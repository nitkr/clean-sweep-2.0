<?php
/**
 * Clean Sweep - File Batch CleanSweep_Worker
 *
 * Scans one batch of files in a single base_dir.
 * Extracted from EpisodeRunner::execute_file_batch() with the scanner
 * pause-decoupling: this worker always returns 'completed' or 'more_work'
 * and lets the orchestrator decide. The actual scanner can still call
 * $ctx->shouldStop() to honor a user-cancel.
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_FileBatchWorker implements CleanSweep_Worker {

    public function type(): string {
        return CleanSweep_ScanWorkUnit::TYPE_FILE_BATCH;
    }

    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        $state = $ctx->state();
        $profile = $ctx->profile();

        $start_index = (int)($payload['start_index'] ?? 0);
        $count       = (int)($payload['count'] ?? $profile->get_batch_size('files'));
        $last_file   = $payload['last_file_path'] ?? null;
        $base_dir    = $payload['base_dir'] ?? WP_CONTENT_DIR;

        // Reuse existing FileScanner implementation. It does the heavy work
        // (chunk reading, signature matching, threat collection, differential
        // hashing). We do not modify FileScanner in this migration.
        require_once dirname(__DIR__, 2) . '/content-scanners/FileScanner.php';
        require_once dirname(__DIR__, 2) . '/AdaptiveBatcher.php';
        require_once dirname(__DIR__, 2) . '/CpuGovernor.php';
        require_once dirname(__DIR__, 2) . '/DifferentialScanner.php';
        require_once dirname(__DIR__, 2) . '/ThreatCollector.php';
        require_once dirname(__DIR__, 2) . '/SignaturePreFilter.php';
        if (!function_exists('clean_sweep_get_malware_signatures')) {
            require_once dirname(__DIR__, 2) . '/signatures.php';
        }

        $throttler = $ctx->throttle();

        // Prefer drain-scoped differential scanner (loaded once per drain).
        $differential = ($ctx instanceof CleanSweep_WorkerContextImpl) ? $ctx->sharedDifferential() : null;
        if ($differential === null) {
            $differential = new CleanSweep_DifferentialScanner(null, false);
            $differential->set_enabled($profile->get_enable_differential_scan());
            if ($ctx instanceof CleanSweep_WorkerContextImpl) {
                $ctx->setDrainResources($differential, $ctx->sharedSignatures(), $ctx->sharedPrefilter());
            }
        } else {
            $differential->set_enabled($profile->get_enable_differential_scan());
        }
        // Load profile hash manifest lazily on first FILE_BATCH that needs it.
        if ($differential->is_enabled()) {
            $differential->set_profile_id($profile->get_profile_id());
        }

        $file_scanner = new CleanSweep_FileScanner($profile, $throttler, $differential);

        // Reuse drain-scoped signatures + prefilter when available.
        $signatures = ($ctx instanceof CleanSweep_WorkerContextImpl) ? $ctx->sharedSignatures() : null;
        $prefilter = ($ctx instanceof CleanSweep_WorkerContextImpl) ? $ctx->sharedPrefilter() : null;
        if ($signatures === null) {
            $signatures = clean_sweep_get_malware_signatures()->get_signatures();
        }
        if ($prefilter instanceof CleanSweep_SignaturePreFilter) {
            $file_scanner->set_signature_prefilter($signatures, $prefilter);
        } else {
            $categories = clean_sweep_resolve_signature_category_map($signatures);
            $targets = clean_sweep_resolve_signature_target_map($signatures);
            $prefilter = new CleanSweep_SignaturePreFilter($signatures, $categories, $targets);
            $file_scanner->set_signature_prefilter($signatures, $prefilter);
            if ($ctx instanceof CleanSweep_WorkerContextImpl) {
                $ctx->setDrainResources($differential, $signatures, $prefilter);
            }
        }

        $file_scanner->set_checkpoint_interval($profile->get_checkpoint_interval());
        $file_scanner->set_profile_options(
            $profile->get_pause_threshold(),
            $profile->get_phase_time_limit('files'),
            $profile->should_disable_heavy_analysis()
        );
        if ($start_index > 0 || $last_file !== null) {
            $file_scanner->set_resume_offset($start_index, $last_file);
        }

        // Wire the threat collector. The collector buffers threats in
        // memory and flushes them to the CleanSweep_ThreatStore when the flush
        // threshold is reached. We also flush at the end of the batch
        // (see below) to ensure no threats are lost.
        $collector = new CleanSweep_ThreatCollector($profile->get_batch_size('files') * 2);
        $file_scanner->set_collector($collector);
        $file_scanner->set_context($ctx);

        // Give the collector a direct reference to the CleanSweep_ThreatStore so
        // its flush() actually writes somewhere. Without this, threats
        // would be buffered in memory and never persisted.
        $collector->set_threat_store(new CleanSweep_ThreatStore($state->scan_id));

        // Progress callback: update ctx counter + call ctx->progress().
        // Also refresh last_file_path periodically so Technical details are not stuck
        // on "—" for minutes while batches run (status polls only see checkpoint).
        $start_index_local = $start_index;
        $last_index = 0;
        $last_path_flush_at = 0;
        $progress_callback = function ($current, $total, $message, $progress = null) use (
            $ctx,
            &$last_index,
            $start_index_local,
            &$file_scanner,
            &$last_path_flush_at
        ) {
            $global = $start_index_local + max(0, (int)$current);
            $delta = $current - $last_index;
            if ($delta > 0) {
                $ctx->incrementCounter('items_processed', $delta);
            }
            $last_index = $current;
            $ctx->progress($global, $total, $message);

            $now = time();
            if ($file_scanner && ($now - $last_path_flush_at) >= 2) {
                $path = $file_scanner->get_last_processed_file();
                if (is_string($path) && $path !== '') {
                    $ctx->mergeState([
                        'phase' => 'files',
                        'last_file_path' => $path,
                    ]);
                    $last_path_flush_at = $now;
                }
            }
        };
        $file_scanner->set_progress_callback($progress_callback);

        $batcher = new CleanSweep_AdaptiveBatcher(
            $profile->get_batch_size('files'),
            ['type' => 'files', 'target_time' => 2.0]
        );

        $start_time = time();
        $batcher->start_batch();
        // Scope the stream to this work unit's directory. Without base_dir,
        // every batch re-walked the whole WP_CONTENT tree (CleanSweep_Scanner v2 bug).
        // max_files = count so large directories can continue via moreWork.
        $result = $file_scanner->scan_streaming($base_dir, $count > 0 ? $count : 0);
        $file_scanner->clear_discovered_subdirs(); // expansion driven by FILE_DISCOVERY units
        $batcher->end_batch($result['total_files_scanned']);

        // Flush any threats still buffered in the collector to the CleanSweep_ThreatStore
        $collector->flush();

        if ($differential->is_enabled()) {
            // save_hashes merges into the full manifest (DifferentialScanner).
            $differential->save_hashes($file_scanner->get_file_hashes());
        }

        // Persist cumulative counters (sole owner).
        $ctx->incrementCounter('files_scanned', (int)$result['total_files_scanned']);
        if (!empty($result['file_threats_found'])) {
            $ctx->incrementCounter('threats_found', (int)$result['file_threats_found']);
        }
        $merge = ['phase' => 'files'];
        $last_path = $file_scanner->get_last_processed_file();
        if (is_string($last_path) && $last_path !== '') {
            $merge['last_file_path'] = $last_path;
        }
        $ctx->mergeState($merge);
        if ($ctx instanceof CleanSweep_WorkerContextImpl) {
            $ctx->flushPending();
        }

        $items = (int)$result['total_files_scanned'];
        $visited = (int)($result['files_visited'] ?? $items);
        $threats = (int)($result['file_threats_found'] ?? 0);

        // If the scanner wants more time/batches, return moreWork and let
        // the orchestrator enqueue a follow-on unit. We do NOT touch the
        // queue or schedule kicks from inside the worker.
        if ($file_scanner->needs_continuation()) {
            $file_scanner->reset_pause_flag();
            // Resume offset must advance by visited entries (including
            // differential skips), not only signature-scanned files.
            $visited_so_far = $start_index + $visited;
            return CleanSweep_WorkerResult::moreWork([
                'items_processed' => $items,
                'threats' => $threats,
                'duration_seconds' => time() - $start_time,
                'last_file_path' => $file_scanner->get_last_processed_file(),
                'files_scanned_so_far' => $visited_so_far,
                'base_dir' => $base_dir,
                'count' => $count,
                'follow_on_payload' => [
                    'base_dir' => $base_dir,
                    'start_index' => $visited_so_far,
                    'count' => $count,
                    'last_file_path' => $file_scanner->get_last_processed_file(),
                    'is_discovery_seeded' => !empty($payload['is_discovery_seeded']),
                ],
            ]);
        }

        return CleanSweep_WorkerResult::completed([
            'items_processed' => $items,
            'threats' => $threats,
            'duration_seconds' => time() - $start_time,
        ]);
    }
}
