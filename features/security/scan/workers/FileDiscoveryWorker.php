<?php
/**
 * Clean Sweep - File Discovery CleanSweep_Worker
 *
 * Lazy BFS through the filesystem. For each directory visited, it
 * enqueues:
 *   - one FILE_BATCH unit to scan the direct files in that directory
 *   - one FILE_DISCOVERY unit for each non-excluded child subdirectory
 *
 * This demand-driven expansion is what lets the system scale to 100GB+
 * without pre-creating millions of work units.
 *
 * Extracted from EpisodeRunner::execute_file_discovery().
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_FileDiscoveryWorker implements CleanSweep_Worker {

    public function type(): string {
        return CleanSweep_ScanWorkUnit::TYPE_FILE_DISCOVERY;
    }

    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        $state = $ctx->state();
        $profile = $ctx->profile();

        $start_path = $payload['start_path'] ?? ($payload['base_dir'] ?? WP_CONTENT_DIR);
        $base_dir   = $payload['base_dir'] ?? $start_path;
        // max_depth on the payload is the *remaining* expansion depth for child
        // discovery units. 1 = list this dir, enqueue children with max_depth=0
        // (children still get a FILE_BATCH for their direct files, but do not
        // expand further). Profile max_depth caps how deep the tree goes.
        $max_depth  = (int)($payload['max_depth'] ?? 1);

        $start_time = time();
        $dirs_enqueued = 0;
        $files_enqueued = 0;
        $batch_size = $profile->get_batch_size('files');
        $seen_reals = [];
        $defer_packages = !empty($payload['defer_package_trees']);
        require_once dirname(__DIR__) . '/SitePaths.php';
        require_once dirname(__DIR__) . '/PackageChecksums.php';

        if (!is_dir($start_path) || $profile->is_excluded($start_path)) {
            return CleanSweep_WorkerResult::completed(['skipped' => 'not_a_dir_or_excluded']);
        }
        $ctx->mergeState(['phase' => 'files']);

        $queue = ($ctx instanceof CleanSweep_WorkerContextImpl) ? $ctx->queue() : null;
        $file_batch_enqueued = !empty($payload['file_batch_enqueued']);
        $resume_after = $this->norm_path((string) ($payload['resume_after'] ?? ''));
        $resume_offset = max(0, (int) ($payload['resume_offset'] ?? 0));
        $start_norm = $this->norm_path($start_path);
        // Drop a stale cursor if that entry is gone or is not in this directory.
        if ($resume_after !== '') {
            $parent = $this->norm_path(dirname($resume_after));
            if ($parent !== $start_norm || (!file_exists($resume_after) && !is_link($resume_after))) {
                $resume_after = '';
                $resume_offset = 0;
            }
        }
        $skipping_until = $resume_after !== '';

        $iterator = new DirectoryIterator($start_path);
        $direct_count = 0;
        $iterations = 0;
        $open_batch = false;
        if ($queue !== null) {
            $queue->begin_batch($state->scan_id);
            $open_batch = true;
        }

        try {
        foreach ($iterator as $item) {
            if ($item->isDot()) {
                continue;
            }
            $path = $this->norm_path($item->getPathname());
            $iterations++;

            $skip_item = false;
            if ($skipping_until) {
                if ($path === $resume_after) {
                    $skipping_until = false;
                }
                $skip_item = true;
            } elseif ($resume_after === '' && $resume_offset > 0 && $iterations <= $resume_offset) {
                $skip_item = true;
            }

            if (!$skip_item) {
                if ($item->isLink() && $item->isDir()) {
                    // Skip linked directories; still honor slice/cancel below.
                } elseif ($item->isDir()) {
                    if (!($defer_packages && $this->is_package_tree_name($path))
                        && !$profile->is_excluded($path)
                        && $max_depth > 0
                        && $queue !== null
                    ) {
                        $budget = (int) $profile->get_tree_max_depth($path);
                        $from_content = (int) $profile->content_relative_depth($path);
                        $remain = $budget - $from_content;
                        if ($remain >= 0) {
                            $disc = CleanSweep_ScanWorkUnit::create(
                                $state->scan_id,
                                CleanSweep_ScanWorkUnit::TYPE_FILE_DISCOVERY,
                                [
                                    'base_dir' => $path,
                                    'start_path' => $path,
                                    'max_depth' => $remain,
                                ],
                                120
                            );
                            $queue->enqueue($disc);
                            $dirs_enqueued++;
                        }
                    }
                } elseif ($item->isFile()) {
                    $base = $item->getFilename();
                    if ($this->might_be_scan_target($base, $profile)
                        && !($item->isLink() && !CleanSweep_SitePaths::accept_scan_target($path, $seen_reals))
                        && ($state->isFreshScan() || !CleanSweep_PackageChecksums::should_skip_signature_scan($path))
                        && !$profile->is_excluded($path)
                        && $profile->should_scan_file($path)
                    ) {
                        $direct_count++;
                        if (!$file_batch_enqueued && $queue !== null) {
                            $batch_count = max(1, $batch_size);
                            $batch = CleanSweep_ScanWorkUnit::create(
                                $state->scan_id,
                                CleanSweep_ScanWorkUnit::TYPE_FILE_BATCH,
                                [
                                    'base_dir' => $start_path,
                                    'start_index' => 0,
                                    'count' => $batch_count,
                                    'is_discovery_seeded' => true,
                                ],
                                82
                            );
                            $queue->enqueue($batch);
                            $file_batch_enqueued = true;
                            $files_enqueued = $direct_count;
                        }
                    }
                }
            }

            if ($ctx->isCancelled()) {
                if ($open_batch) {
                    $queue->end_batch($state->scan_id);
                    $open_batch = false;
                }
                return CleanSweep_WorkerResult::completed(['cancelled' => true, 'iterations' => $iterations]);
            }

            if ($ctx->sliceExpired()) {
                if ($open_batch) {
                    $queue->end_batch($state->scan_id);
                    $open_batch = false;
                }
                if ($direct_count > 0) {
                    $ctx->mergeState([
                        'total_files_estimate' => $state->total_files_estimate + $direct_count,
                    ]);
                }
                $follow = array_merge($payload, [
                    'start_path' => $start_path,
                    'base_dir' => $base_dir,
                    'max_depth' => $max_depth,
                    'defer_package_trees' => $defer_packages,
                    'file_batch_enqueued' => $file_batch_enqueued,
                ]);
                if (!$skip_item) {
                    $follow['resume_after'] = $path;
                    $follow['resume_offset'] = $iterations;
                }
                return CleanSweep_WorkerResult::moreWork([
                    'iterations' => $iterations,
                    'dirs_enqueued' => $dirs_enqueued,
                    'files_enqueued' => $files_enqueued,
                    'duration_seconds' => time() - $start_time,
                    'follow_on_payload' => $follow,
                ]);
            }

            if ($iterations % 500 === 0) {
                $ctx->progress($iterations, 0, "Discovering {$start_path}");
            }
        }

        if ($skipping_until) {
            if ($open_batch) {
                $queue->end_batch($state->scan_id);
                $open_batch = false;
            }
            $retry = $payload;
            $retry['resume_after'] = '';
            $retry['resume_offset'] = 0;
            return $this->run($retry, $ctx);
        }
        } finally {
            if ($open_batch) {
                $queue->end_batch($state->scan_id);
            }
        }

        // Update the running file estimate so computeProgress can use a linear
        // formula instead of the log10 branch (which causes exponential slowdown
        // when total_files_estimate is never populated).
        // Use direct_count (actual files in this directory), not the batch-size
        // inflated count, so the estimate reflects real work.
        $current_estimate = $state->total_files_estimate;
        $ctx->mergeState([
            'total_files_estimate' => $current_estimate + $direct_count,
        ]);

        return CleanSweep_WorkerResult::completed([
            'dirs_enqueued' => $dirs_enqueued,
            'files_enqueued' => $files_enqueued,
            'iterations' => $iterations,
            'duration_seconds' => time() - $start_time,
        ]);
    }

    private function is_package_tree_name(string $path): bool {
        $base = strtolower(basename(str_replace('\\', '/', $path)));
        return $base === 'plugins' || $base === 'themes';
    }

    private function norm_path(string $path): string {
        $path = str_replace('\\', '/', $path);
        if ($path !== '/' && $path !== '') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    /**
     * Fast reject for non-target filenames before should_scan_file().
     * Keeps php-disguise and high-value basenames.
     */
    private function might_be_scan_target(string $basename, CleanSweep_ScanProfile $profile): bool {
        if ($basename === '') {
            return false;
        }
        if ($profile->is_high_value_path($basename)) {
            return true;
        }
        if ($profile->looks_like_php_disguise($basename)) {
            return true;
        }
        // Hidden targets like .backdoor.php still need scanning.
        if ($basename[0] === '.' && preg_match('/\.(?:php\d*|phtml|phar|js|html?|htaccess|ini)$/i', $basename)) {
            return true;
        }
        if ($basename[0] === '.') {
            return false;
        }
        // Quick extension sniff without full pathinfo for common rejects.
        $dot = strrpos($basename, '.');
        if ($dot === false) {
            return false;
        }
        $ext = strtolower(substr($basename, $dot + 1));
        if ($ext === '') {
            return false;
        }
        // Common media/binary skips in uploads trees.
        static $reject = [
            'jpg' => true, 'jpeg' => true, 'png' => true, 'gif' => true, 'webp' => true,
            'svg' => true, 'ico' => true, 'bmp' => true, 'tif' => true, 'tiff' => true,
            'mp4' => true, 'mp3' => true, 'wav' => true, 'avi' => true, 'mov' => true,
            'zip' => true, 'gz' => true, 'tar' => true, 'rar' => true, '7z' => true,
            'pdf' => true, 'doc' => true, 'docx' => true, 'xls' => true, 'xlsx' => true,
            'woff' => true, 'woff2' => true, 'ttf' => true, 'eot' => true,
            'map' => true, 'lock' => true,
        ];
        if (isset($reject[$ext])) {
            return false;
        }
        return true;
    }
}