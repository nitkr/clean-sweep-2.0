<?php
/**
 * Clean Sweep - File Scanner
 *
 * Profile-driven file scanning with chunk processing and signature pre-filtering.
 */

require_once __DIR__ . '/ChunkProcessor.php';
require_once dirname(__DIR__) . '/SignaturePreFilter.php';
require_once dirname(__DIR__) . '/SignatureMatcher.php';
require_once dirname(__DIR__) . '/ThreatCollector.php';
require_once dirname(__DIR__) . '/AdaptiveBatcher.php';
require_once dirname(__DIR__) . '/CpuGovernor.php';
require_once dirname(__DIR__) . '/DifferentialScanner.php';
require_once dirname(__DIR__) . '/scan/Worker.php';
require_once dirname(__DIR__) . '/scan/ScanState.php';

/**
 * File Scanner Class
 * Handles file discovery and scanning based on profile configuration.
 */
class CleanSweep_FileScanner {

    /** @var CleanSweep_ChunkProcessor */
    private $chunk_processor;

    /** @var CleanSweep_ScanProfile */
    private $profile;

    /** @var CleanSweep_ThreatCollector */
    private $collector;

    /** @var CleanSweep_SignaturePreFilter */
    private $signature_filter;

    /** @var array Signatures to scan with */
    private $signatures = [];

    /** @var callable|null Progress callback */
    private $progress_callback = null;

    /** @var array Counters */
    private $counters = [
        'files_scanned' => 0,
        'threats_found' => 0,
    ];

    /** @var CleanSweep_AdaptiveBatcher|null Adaptive batcher for throughput monitoring */
    private $batcher = null;

    /** @var CleanSweep_CpuGovernor|null CPU/IO governor (replaces old Throttler) */
    private $throttler = null;

    /** @var CleanSweep_DifferentialScanner|null Differential scanner for incremental scans */
    private $differential = null;

    /** @var array File hashes for differential scanning */
    private $file_hashes = [];

    /** @var CleanSweep_WorkerContext|null CleanSweep_Scanner context (replaces direct checkpoint dependency) */
    private $context = null;

    /** @var int Interval for context state updates (every N files) */
    private $checkpoint_interval = 100;

    /** @var int Resume offset - number of files to skip */
    private $resume_offset = 0;

    /** @var string|null Last file path before interruption (for deduplication) */
    private $resume_last_file = null;

    // Host adaptation options (set via set_profile_options based on profile detection)
    /** @var int Number of items after which scanner should consider auto-pause */
    private $pause_threshold = 200;

    /** @var int Maximum time for this scan phase in seconds */
    private $phase_time_limit = 45;

    /** @var bool Whether to disable heavy analysis features */
    private $disable_heavy_analysis = false;

    /** @var float Phase start time for time limit tracking */
    private $phase_start_time = 0;

    /** @var bool Flag indicating scanner should pause after current batch */
    private $needs_pause = false;

    /** @var string|null Last processed file path (for resume context) */
    private $last_processed_file = null;

    /** @var array Phase 3.5: Discovered subdirectories for eager follow-on enqueueing */
    private $discovered_subdirs = [];

    /** @var int|null Cached max_execution_time for this request */
    private $cached_max_execution_time = null;

    /** @var float|null Cached request start (REQUEST_TIME_FLOAT) */
    private $cached_request_start = null;

    /** @var string[]|null Cached realpath roots for is_allowed_scan_path */
    private $cached_allowed_roots = null;

    /**
     * Constructor.
     *
     * @param CleanSweep_ScanProfile $profile
     * @param CleanSweep_CpuGovernor|null $throttler CPU/IO governor (also accepts legacy Throttler)
     * @param CleanSweep_DifferentialScanner|null $differential Differential scanner instance
     */
    public function __construct($profile, $throttler = null, $differential = null) {
        $this->profile = $profile;
        $this->chunk_processor = new CleanSweep_ChunkProcessor();
        $this->throttler = $throttler;
        $this->differential = $differential;
    }

    /**
     * Set adaptive batcher for throughput monitoring.
     *
     * @param CleanSweep_AdaptiveBatcher $batcher
     */
    public function set_adaptive_batcher($batcher) {
        $this->batcher = $batcher;
    }

    /**
     * Get file hashes collected during scan (for differential scanning).
     *
     * @return array Path => hash pairs
     */
    public function get_file_hashes() {
        return $this->file_hashes;
    }

    /**
     * Set the worker context (used for cancel check + batch-local pointer updates).
     * The scanner reads `shouldStop()` (for cancel + time-budget) and calls
     * `mergeState()` (for last_file_path / last_file_index writes). All
     * cumulative counters are owned by the orchestrator.
     *
     * @param CleanSweep_WorkerContext $context
     */
    public function set_context(CleanSweep_WorkerContext $context) {
        $this->context = $context;
    }

    /**
     * Set checkpoint save interval.
     *
     * @param int $interval Number of files between checkpoint saves
     */
    public function set_checkpoint_interval($interval) {
        $this->checkpoint_interval = max(10, (int)$interval);
    }

    /**
     * Set profile-based options for adaptive scanning behavior.
     * This allows the scanner to adapt to host restrictions detected by the profile.
     *
     * @param int $pause_threshold Number of items after which to consider pausing
     * @param int $phase_time_limit Maximum time for this phase in seconds
     * @param bool $disable_heavy_analysis Whether to skip heavy analysis features
     */
    public function set_profile_options($pause_threshold, $phase_time_limit, $disable_heavy_analysis = false) {
        $this->pause_threshold = max(50, (int)$pause_threshold);
        $this->phase_time_limit = max(10, (int)$phase_time_limit);
        $this->disable_heavy_analysis = (bool)$disable_heavy_analysis;
        clean_sweep_log_message("FileScanner profile options: pause_threshold={$this->pause_threshold}, time_limit={$this->phase_time_limit}, heavy_analysis=" . ($this->disable_heavy_analysis ? 'disabled' : 'enabled'), 'debug');
    }

    /**
     * Check whether we are approaching a hard time limit (PHP max_execution_time or phase limit)
     * and should trigger a clean pause for continuation.
     * This is the correct architectural defense against fatal "Maximum execution time exceeded" errors.
     */
    private function should_pause_for_time_limit(): bool {
        if ($this->context && $this->context->sliceExpired()) {
            return true;
        }
        // Check soft phase budget first (already set by profile)
        if ($this->phase_time_limit > 0 && $this->phase_start_time > 0) {
            $elapsed = microtime(true) - $this->phase_start_time;
            // Trigger a bit early (80%) so we have margin to save checkpoint and exit cleanly
            if ($elapsed >= ($this->phase_time_limit * 0.8)) {
                return true;
            }
        }

        // Hard safety: PHP's own max_execution_time (common on Apache = 30s/60s/120s).
        // Cache ini_get / REQUEST_TIME* — they do not change during a request.
        if ($this->cached_max_execution_time === null) {
            $this->cached_max_execution_time = (int)@ini_get('max_execution_time');
        }
        if ($this->cached_request_start === null) {
            $this->cached_request_start = (float)($_SERVER['REQUEST_TIME_FLOAT']
                ?? $_SERVER['REQUEST_TIME']
                ?? microtime(true));
        }
        $max = $this->cached_max_execution_time;
        if ($max > 0) {
            $elapsed_request = microtime(true) - $this->cached_request_start;

            // If we've used >70% of the hard limit, pause cleanly.
            // This prevents the preg_match (or any other) loop from ever hitting the fatal.
            if ($elapsed_request > ($max * 0.70)) {
                clean_sweep_log_message("FileScanner: Approaching PHP max_execution_time ({$max}s, used ~{$elapsed_request}s) - forcing clean pause", 'warning');
                return true;
            }
        }

        return false;
    }

    /**
     * Set resume offset for continuing from an interrupted scan.
     *
     * @param int $file_index Number of files to skip
     * @param string|null $last_file_path Last file processed (to avoid duplicates)
     */
    public function set_resume_offset($file_index, $last_file_path = null) {
        $this->resume_offset = max(0, (int)$file_index);
        $this->resume_last_file = $last_file_path;
        clean_sweep_log_message("FileScanner resume set: skip {$this->resume_offset} files, last file: {$this->resume_last_file}", 'info');
    }

    /**
     * Set threat collector.
     *
     * @param CleanSweep_ThreatCollector $collector
     */
    public function set_collector($collector) {
        $this->collector = $collector;
    }

    /**
     * Set signatures to scan with.
     * Keeps BC: callers still pass a flat pattern list. Categories are
     * resolved from the loaded csig pack when counts match.
     *
     * @param array $signatures Array of regex patterns
     */
    public function set_signatures($signatures) {
        $this->signatures = $signatures;
        $categories = clean_sweep_resolve_signature_category_map($signatures);
        $targets = clean_sweep_resolve_signature_target_map($signatures);
        $this->signature_filter = new CleanSweep_SignaturePreFilter($signatures, $categories, $targets);
    }

    /**
     * Reuse a drain-scoped SignaturePreFilter (avoids recompiling per FILE_BATCH).
     *
     * @param array $signatures
     * @param CleanSweep_SignaturePreFilter $filter
     */
    public function set_signature_prefilter($signatures, CleanSweep_SignaturePreFilter $filter) {
        $this->signatures = $signatures;
        $this->signature_filter = $filter;
    }

    /**
     * Set progress callback.
     *
     * @param callable $callback
     */
    public function set_progress_callback($callback) {
        $this->progress_callback = $callback;
    }

    /**
     * Get base path for scanning.
     *
     * @return string
     */
    private function get_base_path() {
        if (defined('ORIGINAL_ABSPATH')) return ORIGINAL_ABSPATH;
        if (defined('ABSPATH')) return ABSPATH;
        // Fallback for standalone / CLI smoke tests where WordPress is not loaded.
        return '/';
    }

    /**
     * Generator that yields files one at a time without buffering.
     * Uses RecursiveIteratorIterator for memory-efficient traversal.
     *
     * @param string $directory Directory to scan
     * @param int $depth Current depth
     * @return Generator File paths
     */
    private function discover_files_streaming($directory, $depth = 0) {
        $max_depth = $this->profile->get_max_depth();

        if ($depth > $max_depth) {
            return;
        }

        if ($this->profile->is_excluded($directory)) {
            return;
        }

        try {
            $iterator = new DirectoryIterator($directory);
            foreach ($iterator as $file) {
                if ($file->isDot()) continue;

                $path = $file->getPathname();

                if ($file->isLink() && $file->isDir()) {
                    continue;
                }
                if ($file->isDir()) {
                    // Phase 3.5 / large-site fix: Track discovered subdirectory for eager follow-on enqueueing.
                    // We do NOT recurse+ yield files here. File processing is per-directory (direct files only)
                    // to support 100GB+ sites without deep recursion in one unit, prevent duplicate work
                    // across ancestor/child units, and allow natural tree expansion via the work queue.
                    // Child directories get their own FILE_BATCH / FILE_DISCOVERY units which will
                    // process their direct files + expand further.
                    if (!$this->profile->is_excluded($path) && ($depth + 1) <= $this->profile->get_max_depth()) {
                        $this->discovered_subdirs[] = $path;
                    }
                    // Do not yield from recurse: children are handled by dedicated units enqueued
                    // by the EpisodeRunner / discovery logic after this unit's direct files are done.
                    // (This enables proper chunking and resume at directory granularity for huge filesystems.)
                } elseif ($file->isFile()) {
                    if ($file->isLink()) {
                        require_once dirname(__DIR__) . '/scan/SitePaths.php';
                        $seen = [];
                        if (!CleanSweep_SitePaths::accept_scan_target($path, $seen)) {
                            continue;
                        }
                    }
                    if (class_exists('CleanSweep_PackageChecksums', false) || is_readable(dirname(__DIR__) . '/scan/PackageChecksums.php')) {
                        require_once dirname(__DIR__) . '/scan/PackageChecksums.php';
                        $fresh = $this->context && $this->context->state() && $this->context->state()->isFreshScan();
                        if (!$fresh && CleanSweep_PackageChecksums::should_skip_signature_scan($path)) {
                            continue;
                        }
                    }
                    if (!$this->profile->is_excluded($path) && $this->profile->should_scan_file($path)) {
                        yield $path;
                    }
                }
            }
        } catch (Exception $e) {
            // Log and continue on permission errors
            clean_sweep_log_message(
                "Cannot read directory {$directory}: " . $e->getMessage(),
                'warning'
            );
        }
    }

    /**
     * Whether a real path is under a known WordPress root (ABSPATH / wp-content).
     * Used to reject arbitrary filesystem paths while allowing ORIGINAL_* roots
     * in recovery mode (where ABSPATH may point at a clean core copy).
     */
    private function is_allowed_scan_path(string $real_path): bool {
        $path = rtrim(str_replace('\\', '/', $real_path), '/');
        if ($this->cached_allowed_roots === null) {
            $roots = [];
            foreach (['ORIGINAL_ABSPATH', 'ABSPATH', 'ORIGINAL_WP_CONTENT_DIR', 'WP_CONTENT_DIR'] as $const) {
                if (!defined($const)) {
                    continue;
                }
                $raw = constant($const);
                if (!is_string($raw) || $raw === '') {
                    continue;
                }
                $resolved = realpath($raw);
                $roots[] = rtrim(str_replace('\\', '/', $resolved !== false ? $resolved : $raw), '/');
            }
            $this->cached_allowed_roots = array_values(array_unique(array_filter($roots)));
        }
        foreach ($this->cached_allowed_roots as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scan files using streaming generator (memory-efficient).
     * Files are yielded one at a time without buffering all paths.
     * Integrates adaptive batching, throttling, and differential scanning.
     *
     * When $folder_path is set (CleanSweep_Scanner v2 FILE_BATCH units), only the
     * *direct* files in that directory are scanned — child directories are
     * handled by separate FILE_DISCOVERY / FILE_BATCH units.
     *
     * @param string|null $folder_path Optional specific folder to scan
     * @param int $max_files Max files to process this invocation (0 = no limit beyond pause/time)
     * @return array Scan results
     */
    public function scan_streaming($folder_path = null, $max_files = 0) {
        $results = [
            'wp_config' => [],
            'wp_content' => [],
            'total_files_scanned' => 0,
            'files_visited' => 0,
            'files_skipped_unchanged' => 0,
            'scanned_paths' => [],
            'file_threats_found' => 0,
        ];

        $base_path = rtrim(str_replace('\\', '/', $this->get_base_path()), '/') . '/';
        $max_files = max(0, (int)$max_files);

        // Resolve the directory this batch should scan.
        // Discovery-seeded batches pass base_dir; full-site fallback uses wp-content.
        $wp_content = defined('ORIGINAL_WP_CONTENT_DIR')
            ? ORIGINAL_WP_CONTENT_DIR
            : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : $base_path . 'wp-content');

        if ($folder_path) {
            $real_path = realpath($folder_path);
            if ($real_path === false || !is_dir($real_path)) {
                clean_sweep_log_message("FileScanner: folder not found or not a directory: {$folder_path}", 'warning');
                return $results;
            }
            if (!$this->is_allowed_scan_path($real_path)) {
                clean_sweep_log_message("FileScanner: refusing path outside WordPress roots: {$real_path}", 'warning');
                return $results;
            }
            $start_dir = $real_path;
        } else {
            $resolved_content = realpath($wp_content);
            $start_dir = $resolved_content !== false ? $resolved_content : $wp_content;
        }

        $start_dir_norm = rtrim(str_replace('\\', '/', $start_dir), '/');
        $wp_content_norm = rtrim(str_replace('\\', '/', realpath($wp_content) ?: $wp_content), '/');
        $base_norm = rtrim($base_path, '/');

        // Scan wp-config.php once when this batch covers site root or wp-content root.
        // Per-directory FILE_BATCH units must not re-scan wp-config every time.
        $should_scan_wp_config = (
            $folder_path === null
            || $start_dir_norm === $base_norm
            || $start_dir_norm === $wp_content_norm
        );
        if ($should_scan_wp_config && $this->resume_offset === 0 && $this->resume_last_file === null) {
            $wp_config = $base_path . 'wp-config.php';
            if (file_exists($wp_config)) {
                $config_threats = $this->scan_file($wp_config);
                if (!empty($config_threats)) {
                    $results['wp_config'] = array_merge($results['wp_config'], $config_threats);
                    $results['file_threats_found'] += count($config_threats);
                }
                $results['total_files_scanned']++;
                $this->counters['files_scanned']++;
                $results['scanned_paths'][] = $wp_config;
                if ($this->differential && $this->differential->is_enabled()) {
                    $this->file_hashes[$wp_config] = CleanSweep_DifferentialScanner::hash_file($wp_config);
                }
            }
        }

        $this->report_progress($results['total_files_scanned'], 0, "Starting streaming file scan...");

        $file_count = 0;
        $batch_size = $this->profile->get_batch_size('files');
        $batch_threats = [];
        $files_in_current_batch = 0;
        $differential_on = $this->differential && $this->differential->is_enabled();

        // discover_files_streaming yields *direct* files only (no recursion).
        // Tree expansion is the job of CleanSweep_FileDiscoveryWorker / the work queue.
        foreach ($this->discover_files_streaming($start_dir, 0) as $file_path) {
            // Handle resume offset - skip already processed files in this directory
            if ($this->resume_offset > 0) {
                $this->resume_offset--;
                continue;
            }

            // If we have a last file from resume and this is it, skip to avoid duplicate
            if ($this->resume_last_file !== null && $file_path === $this->resume_last_file) {
                $this->resume_last_file = null;
                continue;
            }

            // Hash only when differential is enabled (skip check + manifest).
            // When disabled, hashing every file then reading chunks again is pure waste.
            $skipped_unchanged = false;
            if ($differential_on) {
                $hash = CleanSweep_DifferentialScanner::hash_file($file_path);
                // Never store null hashes — they would clobber a good prior entry.
                if ($hash !== null) {
                    $this->file_hashes[$file_path] = $hash;
                    if (!$this->differential->needs_scanning($file_path, $hash)) {
                        $skipped_unchanged = true;
                    }
                }
            }

            if ($this->phase_start_time === 0) {
                $this->phase_start_time = microtime(true);
            }

            $this->last_processed_file = $file_path;

            if (!$skipped_unchanged) {
                $file_threats = $this->scan_file($file_path);

                if (!empty($file_threats)) {
                    $batch_threats = array_merge($batch_threats, $file_threats);
                    $results['wp_content'] = array_merge($results['wp_content'], $file_threats);
                    $results['file_threats_found'] += count($file_threats);
                }

                $results['total_files_scanned']++;
                $this->counters['files_scanned']++;
                $results['scanned_paths'][] = $file_path;
            } else {
                $results['files_skipped_unchanged']++;
            }

            // Count every visited file (including differential skips) toward
            // unit budgets so warm caches cannot hash forever past max_execution_time.
            $file_count++;
            $files_in_current_batch++;

            if (!$skipped_unchanged && $this->batcher && $file_count % $this->batcher->get_current_batch_size() === 0) {
                $this->batcher->end_batch($this->batcher->get_current_batch_size());
                $this->batcher->start_batch();
                $batch_size = $this->batcher->get_recommended_batch_size();
            }

            if ($file_count % max(1, $batch_size) === 0) {
                $files_scanned = $results['total_files_scanned'];
                $this->report_progress(
                    $files_scanned,
                    0,
                    "Scanning files... ({$files_scanned} scanned)"
                );
            }

            // GC every 200 files — every-50 was expensive on shared hosts (50–500ms).
            if ($file_count % 200 === 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            if ($this->context && $file_count % $this->checkpoint_interval === 0) {
                $this->context->mergeState([
                    'last_file_path' => $file_path,
                    'last_file_index' => $results['total_files_scanned'] ?? 0,
                ]);
            }

            if ($this->throttler) {
                $this->throttler->file_yield();
            }

            // Honor batch max_files (work-unit count) for continuation units.
            if ($max_files > 0 && $file_count >= $max_files) {
                clean_sweep_log_message("FileScanner max_files reached ({$file_count}/{$max_files}), marking pause for continuation", 'info');
                $this->needs_pause = true;
            }

            if ($this->pause_threshold > 0 && $file_count > 0 && $file_count % $this->pause_threshold === 0) {
                clean_sweep_log_message("FileScanner pause_threshold reached ({$file_count} files), marking pause for potential continuation", 'info');
                $this->needs_pause = true;
            }

            if ($this->phase_time_limit > 0 && $this->phase_start_time > 0) {
                $elapsed = microtime(true) - $this->phase_start_time;
                if ($elapsed >= $this->phase_time_limit) {
                    clean_sweep_log_message("FileScanner phase_time_limit reached ({$elapsed}s), marking pause for continuation", 'info');
                    $this->needs_pause = true;
                }
            }

            if ($this->should_pause_for_time_limit()) {
                clean_sweep_log_message("FileScanner: Approaching max_execution_time or soft limit - clean pause for continuation", 'info');
                $this->needs_pause = true;
            }

            if ($this->context && $this->context->shouldStop()) {
                clean_sweep_log_message("File scan cancelled at file {$file_count}", 'info');
                break;
            }

            if ($this->needs_pause) {
                clean_sweep_log_message("FileScanner pausing after {$file_count} files for host adaptation", 'info');
                break;
            }
        }

        if ($this->batcher && $files_in_current_batch > 0) {
            $this->batcher->end_batch($files_in_current_batch % max(1, $batch_size));
        }

        // files_visited includes differential skips — required for resume offsets.
        $results['files_visited'] = $file_count;
        $this->report_progress($results['total_files_scanned'], $results['total_files_scanned'], "File scan complete");
        $this->counters['threats_found'] = $results['file_threats_found'];

        return $results;
    }

    /**
     * Signature-scan an explicit list of files (root .htaccess / ini seeds).
     *
     * @param string[] $paths
     * @return array{threats: array, scanned: int}
     */
    public function scan_explicit_paths(array $paths) {
        $threats = [];
        $scanned = 0;
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || !is_file($path)) {
                continue;
            }
            $real = realpath($path);
            if ($real === false) {
                continue;
            }
            $real_n = str_replace('\\', '/', $real);
            $site_root = class_exists('CleanSweep_SitePaths', false) ? CleanSweep_SitePaths::root() : null;
            $root_n = $site_root ? rtrim(str_replace('\\', '/', $site_root), '/') : '';
            $under_site = $root_n !== '' && ($real_n === $root_n || strpos($real_n, $root_n . '/') === 0);
            if (!$under_site && !$this->is_allowed_scan_path($real)) {
                continue;
            }
            $found = $this->scan_file($real);
            if (!empty($found)) {
                $threats = array_merge($threats, $found);
            }
            $scanned++;
            $this->last_processed_file = $real;
        }
        return ['threats' => $threats, 'scanned' => $scanned];
    }

    private function scan_file($file_path) {
        $threats = [];

        if (!file_exists($file_path) || !is_readable($file_path)) {
            return $threats;
        }

        // Check file size - skip very large files unless deep profile
        if ($this->chunk_processor->should_skip_file($file_path, ['skip_large_files' => true])
            && $this->profile->get_profile_id() !== 'deep') {
            return $threats;
        }

        // Get signatures for file type (use compiled patterns when available)
        $base = basename($file_path);
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        // pathinfo(".htaccess") can yield empty or "htaccess" depending on PHP/OS
        if ($base === '.htaccess') {
            $extension = 'htaccess';
        } elseif ($base === '.user.ini' || $base === 'php.ini') {
            $extension = 'ini';
        }
        $file_signatures = $this->signature_filter
            ? $this->signature_filter->get_compiled_for_filetype($extension)
            : $this->signatures;

        // High-value drop-ins: fall back to PHP rules if extension mapping is empty
        if (empty($file_signatures) && $this->signature_filter
            && method_exists($this->profile, 'is_high_value_path')
            && $this->profile->is_high_value_path($file_path)) {
            $file_signatures = $this->signature_filter->get_compiled_for_filetype('php');
        }

        if (empty($file_signatures)) {
            return $threats;
        }

        // Scan file in chunks
        $this->chunk_processor->read_chunks($file_path, function($chunk, $chunk_info) use ($file_path, $file_signatures, &$threats) {
            $file_threats = $this->match_signatures($chunk, $file_signatures, $file_path, $chunk_info);
            $threats = array_merge($threats, $file_threats);
        });

        // Record file processed
        if ($this->collector) {
            $this->collector->files_processed(1);
        }

        return $threats;
    }

    /**
     * Match signatures against chunk content.
     *
     * @param string $content Chunk content
     * @param array $signatures Signatures to match
     * @param string $file_path File path
     * @param array $chunk_info Chunk metadata
     * @return array Threats
     */
    private function match_signatures($content, $signatures, $file_path, $chunk_info) {
        $threats = [];

        $signatures = CleanSweep_SignatureMatcher::order_by_severity($signatures);
        $throttler = $this->throttler;

        $hits = CleanSweep_SignatureMatcher::match_content(
            $content,
            $signatures,
            function ($n) use ($throttler) {
                if ($throttler) {
                    $throttler->micro_yield($n);
                }
                // should_pause_for_time_limit() sets needs_pause for the outer loop
                return ($n % 25 === 0 && $this->should_pause_for_time_limit());
            }
        );

        foreach ($hits as $hit) {
            $pattern = $hit['pattern'];
            $match = $hit['match'];
            $meta = $hit['meta'];
            $sig_index = (int) $hit['index'];
            $sig_id = $meta['id'];

            $match_position = strpos($content, $match);
            if ($match_position === false) {
                $match_position = 0;
            }
            $line_number = $this->chunk_processor->calculate_line_number($content, $match_position, $chunk_info);

            $risk_score = $this->maybe_demote_package_eval_fp($file_path, $pattern, $match, (int) $meta['risk_score']);
            $threat_level = CleanSweep_ThreatCollector::risk_score_to_level($risk_score);

            $threat = [
                'id' => md5($file_path . $sig_id . $match . $line_number),
                'pattern' => $sig_id,
                'signature_id' => $sig_id,
                'signature_index' => $sig_index,
                'category' => $meta['category'],
                'match' => substr($match, 0, 100),
                'file' => $file_path,
                'line_number' => $line_number,
                'byte_offset' => $this->chunk_processor->calculate_byte_offset($match_position, $chunk_info),
                'chunk_index' => $chunk_info['chunk_index'],
                'open_in_editor' => $file_path . ':' . $line_number,
                'content_preview' => $this->chunk_processor->extract_preview($content, $match_position),
                'matched_content' => $match,
                'source' => 'file',
                'table' => null,
                'row_id' => null,
                'column' => null,
                'threat_level' => $threat_level,
                'risk_score' => $risk_score,
                'severity' => $meta['severity'],
                'family' => $meta['family'] !== '' ? $meta['family'] : null,
                'detected_at' => date('c'),
            ];

            $threats[] = $threat;
            if ($this->collector) {
                $this->collector->add($threat);
            }
        }

        return $threats;
    }

    /**
     * Lower score for bare eval($var) inside plugin/theme packages.
     * Keeps the finding for review but avoids HIGH on intentional runners (e.g. WPCode).
     * Does not demote uploads or eval chains with decode/superglobals.
     *
     * @param string $file_path
     * @param string $pattern
     * @param string $match
     * @param int $risk_score
     * @return int
     */
    private function maybe_demote_package_eval_fp($file_path, $pattern, $match, $risk_score) {
        $path = str_replace('\\', '/', (string) $file_path);
        if (!preg_match('#/(?:plugins|themes)/[^/]+/#i', $path)) {
            return $risk_score;
        }
        // Match text must look like bare eval($…)
        if (!preg_match('/eval\s*\(\s*\$/i', (string) $match)) {
            return $risk_score;
        }
        if (preg_match('/base64_decode|gzinflate|gzuncompress|str_rot13|\$_(?:GET|POST|REQUEST|COOKIE)|assert\s*\(/i', (string) $match)) {
            return $risk_score;
        }
        // Pattern should be eval-related but not an explicit decode chain rule
        $pat = (string) $pattern;
        if (stripos($pat, 'eval') === false) {
            return $risk_score;
        }
        if (preg_match('/base64_decode|gzinflate|\$_(?:GET|POST|REQUEST)/i', $pat)) {
            return $risk_score;
        }
        return min((int) $risk_score, 35);
    }

    /**
     * Estimate threat level from pattern.
     *
     * @param string $pattern
     * @return string
     */
    private function estimate_threat_level($pattern) {
        // High-risk patterns
        $high_risk = ['eval', 'base64_decode', 'system(', 'exec(', 'shell_exec', 'passthru'];
        $medium_risk = ['curl', 'file_get_contents', 'fopen', 'preg_replace'];

        foreach ($high_risk as $risk) {
            if (strpos($pattern, $risk) !== false) {
                return 'high';
            }
        }

        foreach ($medium_risk as $risk) {
            if (strpos($pattern, $risk) !== false) {
                return 'medium';
            }
        }

        return 'low';
    }

    /**
     * Write a batch-local pointer (last_file_path / last_file_index) to the
     * context. The orchestrator owns cumulative counters; the scanner only
     * writes its own cursor.
     *
     * Replaces the old save_checkpoint() which did a load-mutate-save round-trip
     * of the whole state to write two pointer fields. That was a workaround
     * (the sink pattern, the WorkUnit payload, and the context should be the
     * only writers), and it was the source of the "UI counts oscillate" bug.
     *
     * @param array $results Current scan results (unused, kept for back-compat)
     * @param string|null $last_file Last processed file path
     */
    private function save_checkpoint($results, $last_file = null) {
        if (!$this->context || $last_file === null) {
            return;
        }
        $this->context->mergeState([
            'last_file_path'  => $last_file,
            'last_file_index' => (int)($results['total_files_scanned'] ?? 0),
        ]);
    }

    /**
     * Check if scanner triggered a pause and needs continuation.
     *
     * @return bool True if pause was triggered
     */
    public function needs_continuation() {
        return $this->needs_pause;
    }

    /**
     * Reset the pause flag (call after continuation is scheduled).
     */
    public function reset_pause_flag() {
        $this->needs_pause = false;
    }

    /**
     * Report progress.
     *
     * @param int $current Current count of items processed
     * @param int $total Total count (may be 0 for streaming scans with unknown total)
     * @param string $message Human-readable progress message
     * @param int|null $progress_override Optional explicit progress percentage (0-100)
     */
    private function report_progress($current, $total, $message, $progress_override = null) {
        if ($this->progress_callback) {
            // Use explicit progress if provided, otherwise calculate from current/total
            // For streaming scans where total is unknown, we can still show incremental progress
            // by using the passed progress_override when available
            $progress = $progress_override;
            if ($progress === null) {
                $progress = $total > 0 ? round(($current / $total) * 100) : 0;
            }
            ($this->progress_callback)($current, $total, $message, $progress);
        }
    }

    /**
     * Get counters.
     *
     * @return array
     */
    public function get_counters() {
        return $this->counters;
    }

    /**
     * Get the last processed file path (for resume context).
     *
     * @return string|null
     */
    public function get_last_processed_file() {
        return $this->last_processed_file;
    }

    /**
     * Get the total files scanned in current run.
     *
     * @return int
     */
    public function get_files_scanned() {
        return $this->counters['files_scanned'];
    }

    /**
     * Get discovered subdirectories for eager follow-on enqueueing (Phase 3.5).
     * Called by EpisodeRunner after a file batch completes to enqueue subdir scans.
     *
     * @return array Array of subdirectory paths
     */
    public function get_discovered_subdirs() {
        return $this->discovered_subdirs;
    }

    /**
     * Clear discovered subdirectories (called after they've been enqueued).
     *
     * @return void
     */
    public function clear_discovered_subdirs() {
        $this->discovered_subdirs = [];
    }
}