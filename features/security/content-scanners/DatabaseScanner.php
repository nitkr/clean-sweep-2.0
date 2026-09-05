<?php
/**
 * Clean Sweep - Database Scanner
 *
 * Paginated database scanning with cursor-based pagination for large tables.
 * Memory-efficient scanning of WordPress database content.
 */

require_once dirname(__DIR__) . '/ThreatCollector.php';
require_once dirname(__DIR__) . '/SignaturePreFilter.php';
require_once dirname(__DIR__) . '/SignatureMatcher.php';
require_once dirname(__DIR__) . '/CpuGovernor.php';
require_once dirname(__DIR__) . '/scan/Worker.php';
require_once dirname(__DIR__) . '/scan/ScanState.php';
require_once __DIR__ . '/ChunkProcessor.php';

/**
 * Database Scanner Class
 * Handles database table scanning with cursor-based pagination.
 */
class CleanSweep_DatabaseScanner {

/** @var CleanSweep_ScanProfile */
    private $profile;

    /** @var CleanSweep_ThreatCollector */
    private $collector;

    /** @var array Signatures to scan with */
    private $signatures = [];

    /** @var callable|null Progress callback */
    private $progress_callback = null;

    /** @var array Counters */
    private $counters = [
        'db_rows_scanned' => 0,
        'threats_found' => 0,
    ];

    /** @var array High-risk option names */
    private $high_risk_options = [
        'siteurl',
        'home',
        'default_role',
        'users_can_register',
        'active_plugins',
        'cron',
        'recently_activated',
        'rewrite_rules',
        'wpcode_snippets',
        'ihaf_insert_header',
        'ihaf_insert_footer',
        'themes_css',
    ];

    /** @var array High-risk option patterns */
    private $high_risk_option_patterns = [
        'theme_mods_%',
        'widget_%',
        '_transient_%',
        '_site_transient_%',
        '_wco_%',
        'footer_script_%',
        'header_script_%',
    ];

    /** @var array High-risk postmeta keys */
    private $high_risk_postmeta_keys = [
        '_edit_last',
        '_wp_page_template',
        'custom_css',
        'header_scripts',
        'footer_scripts',
        '_wp_attached_file',
        'content_filters',
        'post_filters',
    ];

    /** @var CleanSweep_AdaptiveBatcher|null Adaptive batcher for throughput monitoring */
    private $batcher = null;

    /** @var CleanSweep_CpuGovernor|null CPU/IO governor (replaces legacy Throttler) */
    private $throttler = null;

    /** @var CleanSweep_SignaturePreFilter|null Signature pre-filter for compiled patterns */
    private $signature_filter = null;

    /** @var array Compiled patterns for database scanning */
    private $compiled_patterns = [];

    /** @var CleanSweep_ScanCheckpoint|null CleanSweep_Checkpoint for resumable scans */
    /** @var CleanSweep_WorkerContext|null */
    private $context = null;

    /** @var int Last ping timestamp */
    private $last_ping_time = 0;

    /** @var int Ping interval in seconds */
    private $ping_interval = 30;

    /** @var int CleanSweep_Checkpoint save interval (after every N rows) */
    private $checkpoint_interval = 100;

    /** @var int Resume cursor for posts table */
    private $resume_posts_last_id = 0;

    /** @var int Resume cursor for comments table */
    private $resume_comments_last_id = 0;

    /** @var int Resume cursor for postmeta table */
    private $resume_postmeta_last_id = 0;

    /** @var int Resume cursor for users table */
    private $resume_users_last_id = 0;

    /** @var int Resume cursor for terms table */
    private $resume_terms_last_id = 0;

    // Host adaptation options (set via set_profile_options based on profile detection)
    /** @var int Number of items after which scanner should consider auto-pause */
    private $pause_threshold = 200;

    /** @var int Maximum time for this phase in seconds */
    private $phase_time_limit = 45;

    /** @var bool Whether to disable heavy analysis features */
    private $disable_heavy_analysis = false;

    /** @var float Phase start time for time limit tracking */
    private $phase_start_time = 0;

    /** @var bool Flag indicating scanner should pause after current batch */
    private $needs_pause = false;

    /** @var int Transients accepted this worker invocation (Standard cap) */
    private $transients_scanned = 0;

    /** @var float Request start (for max_execution_time) */
    private $request_start_time = 0;

    /** @var CleanSweep_ChunkProcessor|null */
    private $chunk_processor = null;

    /** @var bool Stop remaining signatures on the current row (time budget) */
    private $row_sig_truncated = false;

    /** @var int[] Poison / too-large ids skipped this invocation */
    private $poison_skipped = [];

    /** @var bool False when the last query_with_recovery hit a DB error */
    private $last_query_ok = true;

    /**
     * Constructor.
     *
     * @param CleanSweep_ScanProfile $profile
     * @param CleanSweep_CpuGovernor|null $throttler CPU/IO governor (also accepts legacy Throttler)
     */
    public function __construct($profile, $throttler = null) {
        $this->profile = $profile;
        $this->throttler = $throttler;
        $this->last_ping_time = 0;
        $this->ping_interval = 30;
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
     * Set the worker context. The scanner reads `shouldStop()` (cancel +
     * time-budget exit) and calls `mergeState()` for db_cursors writes.
     * All cumulative counters are owned by the orchestrator.
     *
     * @param CleanSweep_WorkerContext $context
     */
    public function set_context(CleanSweep_WorkerContext $context) {
        $this->context = $context;
    }

    /**
     * Set checkpoint save interval.
     *
     * @param int $interval Number of rows between checkpoint saves
     */
    public function set_checkpoint_interval($interval) {
        $this->checkpoint_interval = max(10, (int)$interval);
    }

    /**
     * Set resume state for continuing from an interrupted scan.
     *
     * @param array $db_state Database cursor positions from checkpoint
     */
    public function set_resume_state($db_state) {
        if (isset($db_state['posts_last_id'])) {
            $this->resume_posts_last_id = $db_state['posts_last_id'];
        }
        if (isset($db_state['comments_last_id'])) {
            $this->resume_comments_last_id = $db_state['comments_last_id'];
        }
        if (isset($db_state['postmeta_last_id'])) {
            $this->resume_postmeta_last_id = $db_state['postmeta_last_id'];
        }
        if (isset($db_state['users_last_id'])) {
            $this->resume_users_last_id = $db_state['users_last_id'];
        }
        if (isset($db_state['terms_last_id'])) {
            $this->resume_terms_last_id = $db_state['terms_last_id'];
        }
        clean_sweep_log_message("DatabaseScanner resume state set: posts={$this->resume_posts_last_id}, comments={$this->resume_comments_last_id}, postmeta={$this->resume_postmeta_last_id}", 'info');
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
        clean_sweep_log_message("DatabaseScanner profile options: pause_threshold={$this->pause_threshold}, time_limit={$this->phase_time_limit}, heavy_analysis=" . ($this->disable_heavy_analysis ? 'disabled' : 'enabled'), 'debug');
    }

    /**
     * Ping database to keep connection alive.
     */
    private function ping_if_needed() {
        $now = time();
        if ($now - $this->last_ping_time >= $this->ping_interval) {
            global $wpdb;
            $wpdb->query('SELECT 1');
            $this->last_ping_time = $now;
        }
    }

    /**
     * Ensure database connection is alive, reconnect if needed.
     */
    private function ensure_connection() {
        global $wpdb;

        if (method_exists($wpdb, 'check_connection') && !$wpdb->check_connection(false)) {
            clean_sweep_log_message("Database connection lost, reconnecting...", 'warning');
            $wpdb->db_connect();
        }

        $this->ping_if_needed();
    }

    /**
     * Execute query with connection recovery.
     * ALWAYS returns an array (empty on error or no rows). Check last_query_ok
     * to distinguish a DB error from a legitimate empty result.
     *
     * @param string $query SQL query
     * @return array Query results (always array, never null)
     */
    private function query_with_recovery($query) {
        global $wpdb;
        $this->last_query_ok = true;

        if (!is_string($query) || $query === '') {
            $this->last_query_ok = false;
            clean_sweep_log_message('DatabaseScanner: empty or invalid SQL', 'error');
            return [];
        }

        $result = $wpdb->get_results($query);

        // Handle errors - wpdb returns null on some errors, false on others
        if ($result === null || $result === false) {
            $error = $wpdb->last_error;
            if (!empty($error)) {
                // Check for recoverable connection errors
                if (strpos($error, 'server has gone away') !== false ||
                    strpos($error, 'Lost connection') !== false ||
                    strpos($error, 'MySQL server has gone away') !== false) {
                    clean_sweep_log_message("Database connection error, attempting reconnect...", 'warning');
                    if (method_exists($wpdb, 'db_connect')) {
                        $wpdb->db_connect();
                        $result = $wpdb->get_results($query);
                        if ($result === null || $result === false) {
                            $this->last_query_ok = false;
                            clean_sweep_log_message("Database reconnection failed, returning empty result", 'error');
                            return [];
                        }
                        // Recovered rows must be returned; do not fall through as empty.
                    } else {
                        $this->last_query_ok = false;
                        return [];
                    }
                } else {
                    $this->last_query_ok = false;
                    clean_sweep_log_message("Database query error: {$error}", 'error');
                    return [];
                }
            } else {
                // No error message but null result — treat as a legitimate empty set.
                return [];
            }
        }

        if (!is_array($result)) {
            $this->last_query_ok = false;
            return [];
        }
        return $result;
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
        // Prefer rules that declare target "db"; fall back to full pack for BC
        if (is_array($targets) && !empty($targets['db'])) {
            $this->compiled_patterns = [];
            $want = array_fill_keys($targets['db'], true);
            foreach ($this->signature_filter->compile_signatures() as $item) {
                if (isset($want[$item['index']])) {
                    $this->compiled_patterns[] = $item;
                }
            }
        } else {
            $this->compiled_patterns = $this->signature_filter->compile_signatures();
        }
    }

    /**
     * Drop PHP-syntax rules on HTML-only DB rows (posts/comments/terms).
     * Options/meta stay `any` because they store PHP snippets.
     *
     * @param array $compiled
     * @param string $table
     * @param string $content
     * @return array
     */
    private function filter_compiled_for_haystack(array $compiled, $table, $content) {
        if ($this->classify_haystack($table, $content) !== 'html') {
            return $compiled;
        }
        if (!function_exists('clean_sweep_get_malware_signatures')) {
            return $compiled;
        }
        $mgr = clean_sweep_get_malware_signatures();
        if (!$mgr || !method_exists($mgr, 'get_syntax')) {
            return $compiled;
        }
        $out = [];
        foreach ($compiled as $item) {
            $index = is_array($item) ? (int) ($item['index'] ?? 0) : 0;
            if ($mgr->get_syntax($index) === 'php') {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param string $table
     * @param string $content
     * @return string html|php|any
     */
    private function classify_haystack($table, $content) {
        $suffix = $this->table_suffix($table);
        if (in_array($suffix, [
            'options', 'postmeta', 'usermeta', 'sitemeta', 'termmeta', 'commentmeta', 'blogmeta',
        ], true)) {
            return 'any';
        }
        if (self::content_looks_like_php($content)) {
            return 'php';
        }
        return 'html';
    }

    /**
     * @param string $content
     * @return bool
     */
    private static function content_looks_like_php($content) {
        return (bool) preg_match(
            '/<\?(?:php|=)|\$wpdb\s*->|\b(?:eval|assert|create_function)\s*\(|\$_(?:GET|POST|REQUEST|COOKIE)\s*\[/i',
            (string) $content
        );
    }

    /**
     * Get compiled patterns for database content.
     *
     * @return array Compiled patterns
     */
    private function get_compiled_patterns() {
        if (empty($this->compiled_patterns)) {
            if ($this->signature_filter) {
                $this->compiled_patterns = $this->signature_filter->compile_signatures();
            } else {
                $this->compiled_patterns = [];
                foreach ($this->signatures as $index => $pattern) {
                    if (@preg_match($pattern, '') !== false) {
                        $this->compiled_patterns[] = [
                            'index' => $index,
                            'pattern' => $pattern,
                        ];
                    }
                }
            }
        }
        return $this->compiled_patterns;
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
     * Save checkpoint during database scanning for resumable scans.
     *
     * @param string $table Table name being scanned
     * @param int $last_id Last processed ID cursor
     * @param int $count Total rows processed so far
     */
    private function save_checkpoint($table, $last_id, $count) {
        if (!$this->context) return;
        $cursors = $this->context->state()->db_cursors;
        $cursors[$table] = (int)$last_id;
        $this->context->mergeState(['db_cursors' => $cursors]);
    }

    /**
     * Scan database based on profile settings.
     *
     * @return array Scan results
     */
    public function scan() {
        // Ensure database connection is alive before starting
        $this->ensure_connection();

        $results = [
            'wp_posts' => [],
            'wp_options' => [],
            'wp_comments' => [],
            'wp_postmeta' => [],
            'wp_users' => [],
            'wp_terms' => [],
            'wp_term_taxonomy' => [],
            'wp_usermeta' => [],
            'total_scanned' => 0,
            'threats_found' => 0,
        ];

        // Phase 2.1: Initialize time tracking for host adaptation
        if ($this->phase_start_time === 0) {
            $this->phase_start_time = microtime(true);
        }
        $total_rows_scanned = 0;
        $paused_at_table = null;

        $this->report_progress(0, 1, "Starting database scan...");

        // Keep connection alive between table scans
        $this->ping_if_needed();

        // Scan posts table (unless pause already triggered)
        if (!$this->should_pause()) {
            $results['wp_posts'] = $this->scan_posts();
            $table_count = count($results['wp_posts']);
            $this->counters['db_rows_scanned'] += $table_count;
            $total_rows_scanned += $table_count;
        } else {
            $results['wp_posts'] = [];
            $paused_at_table = 'posts';
        }

        // Phase 3.2: Check for intra-table pause after each table scan
        // Intra-table pause is detected via needs_continuation() flag
        if ($paused_at_table === null && $this->needs_continuation()) {
            $paused_at_table = 'posts';
        }

        // Scan options table
        if ($paused_at_table === null && !$this->should_pause()) {
            $results['wp_options'] = $this->scan_options();
            $table_count = count($results['wp_options']);
            $this->counters['db_rows_scanned'] += $table_count;
            $total_rows_scanned += $table_count;
        } else {
            $results['wp_options'] = [];
            // Only set paused_at_table if we haven't already identified where we paused
            // This prevents overwriting an intra-table pause location with subsequent table names
            if ($paused_at_table === null) {
                $paused_at_table = 'options';
            }
        }

        // Phase 3.2: Check for intra-table pause
        if ($paused_at_table === null && $this->needs_continuation()) {
            $paused_at_table = 'options';
        }

        // Scan comments table
        if ($paused_at_table === null && !$this->should_pause()) {
            $results['wp_comments'] = $this->scan_comments();
            $table_count = count($results['wp_comments']);
            $this->counters['db_rows_scanned'] += $table_count;
            $total_rows_scanned += $table_count;
        } else {
            $results['wp_comments'] = [];
            if ($paused_at_table === null) {
                $paused_at_table = 'comments';
            }
        }

        // Phase 3.2: Check for intra-table pause
        if ($paused_at_table === null && $this->needs_continuation()) {
            $paused_at_table = 'comments';
        }

        // Scan postmeta table
        if ($paused_at_table === null && !$this->should_pause()) {
            $results['wp_postmeta'] = $this->scan_postmeta();
            $table_count = count($results['wp_postmeta']);
            $this->counters['db_rows_scanned'] += $table_count;
            $total_rows_scanned += $table_count;
        } else {
            $results['wp_postmeta'] = [];
            if ($paused_at_table === null) {
                $paused_at_table = 'postmeta';
            }
        }

        // Phase 3.2: Check for intra-table pause
        if ($paused_at_table === null && $this->needs_continuation()) {
            $paused_at_table = 'postmeta';
        }

        // Scan users table
        if ($paused_at_table === null && !$this->should_pause()) {
            $results['wp_users'] = $this->scan_users();
            $table_count = count($results['wp_users']);
            $this->counters['db_rows_scanned'] += $table_count;
            $total_rows_scanned += $table_count;
        } else {
            $results['wp_users'] = [];
            if ($paused_at_table === null) {
                $paused_at_table = 'users';
            }
        }

        // Phase 3.2: Check for intra-table pause
        if ($paused_at_table === null && $this->needs_continuation()) {
            $paused_at_table = 'users';
        }

        // Scan terms and term_taxonomy if deep profile
        if ($this->profile->get_profile_id() !== 'quick') {
            if ($paused_at_table === null && !$this->should_pause()) {
                $results['wp_terms'] = $this->scan_terms();
                $results['wp_term_taxonomy'] = $this->scan_term_taxonomy();
            } else {
                $results['wp_terms'] = [];
                $results['wp_term_taxonomy'] = [];
                if ($paused_at_table === null) {
                    $paused_at_table = 'terms';
                }
            }

            // Phase 3.2: Check for intra-table pause
            if ($paused_at_table === null && $this->needs_continuation()) {
                $paused_at_table = 'terms';
            }
        }

        // Scan usermeta if not quick profile
        if ($this->profile->get_profile_id() !== 'quick') {
            if ($paused_at_table === null && !$this->should_pause()) {
                $results['wp_usermeta'] = $this->scan_usermeta();
            } else {
                $results['wp_usermeta'] = [];
                if ($paused_at_table === null) {
                    $paused_at_table = 'usermeta';
                }
            }

            // Phase 3.2: Check for intra-table pause
            if ($paused_at_table === null && $this->needs_continuation()) {
                $paused_at_table = 'usermeta';
            }
        }

        // If we paused mid-scan, mark it
        if ($paused_at_table !== null) {
            $results['partial_completion'] = true;
            $results['paused_at_table'] = $paused_at_table;
            clean_sweep_log_message("DatabaseScanner paused at table: {$paused_at_table}", 'info');
        }

        // Calculate totals
        $results['total_scanned'] = $this->counters['db_rows_scanned'];
        $results['threats_found'] = $this->counters['threats_found'];

        $this->report_progress(1, 1, "Database scan complete");
        return $results;
    }

    /**
     * Check if scanner should pause based on thresholds.
     * Called periodically during scanning to enable host adaptation.
     *
     * @return bool True if pause should be triggered
     */
    private function should_pause() {
        if ($this->context && $this->context->shouldStop()) {
            $this->needs_pause = true;
            return true;
        }

        if ($this->pause_threshold > 0) {
            $total_items = $this->counters['db_rows_scanned'];
            if ($total_items >= $this->pause_threshold) {
                clean_sweep_log_message("DatabaseScanner pause_threshold reached ({$total_items} rows), marking for pause", 'info');
                $this->needs_pause = true;
                return true;
            }
        }

        if ($this->phase_start_time <= 0) {
            $this->phase_start_time = microtime(true);
        }

        $elapsed = microtime(true) - $this->phase_start_time;
        if ($this->phase_time_limit > 0 && $elapsed >= ($this->phase_time_limit * 0.8)) {
            clean_sweep_log_message("DatabaseScanner phase_time_limit 80% reached ({$elapsed}s), marking for pause", 'info');
            $this->needs_pause = true;
            return true;
        }

        $max = (int)@ini_get('max_execution_time');
        if ($max > 0) {
            if ($this->request_start_time <= 0) {
                $this->request_start_time = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? $_SERVER['REQUEST_TIME'] ?? $this->phase_start_time);
            }
            $used = microtime(true) - $this->request_start_time;
            if ($used > ($max * 0.70)) {
                clean_sweep_log_message("DatabaseScanner approaching max_execution_time ({$max}s, used ~{$used}s)", 'warning');
                $this->needs_pause = true;
                return true;
            }
        }

        $limit = $this->php_memory_limit_bytes();
        if ($limit > 0 && memory_get_usage(true) >= (int)($limit * 0.80)) {
            clean_sweep_log_message('DatabaseScanner approaching memory_limit, marking for pause', 'warning');
            $this->needs_pause = true;
            return true;
        }

        return false;
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
     * Scan posts table with cursor-based pagination.
     *
     * @return array Threats
     */
    private function scan_posts() {
        global $wpdb;
        $threats = [];
        $last_id = $this->resume_posts_last_id; // Start from resume position
        $batch_size = $this->profile->get_batch_size('db_rows');
        $limit = $this->profile->get_db_post_limit();

        $count = 0;
        do {
            $query = $wpdb->prepare(
                "SELECT ID, post_title, post_content, post_excerpt, post_name
                 FROM {$wpdb->posts}
                 WHERE ID > %d
                 AND post_status IN ('publish', 'draft', 'private', 'pending')
                 AND (LENGTH(post_content) > 0 OR LENGTH(post_title) > 0)
                 ORDER BY ID
                 LIMIT %d",
                $last_id,
                $batch_size
            );

            $rows = $this->query_with_recovery($query);

            foreach ($rows as $row) {
                $content = $this->extract_table_content($wpdb->posts, $row);
                $row_threats = $this->scan_content($content, $wpdb->posts, $row->ID, 'post_content');

                foreach ($row_threats as $threat) {
                    $threat['post_id'] = $row->ID;
                    $threat['table'] = $wpdb->posts;
                    $threat['row_id'] = $row->ID;
                    $threat['column'] = 'post_content';
                    $threats[] = $threat;
                    $this->counters['threats_found']++;

                    // Add to collector for streaming and memory management
                    if ($this->collector) {
                        $this->collector->add($threat);
                    }
                }

                $last_id = $row->ID;
                $count++;

                // Record in collector
                if ($this->collector) {
                    $this->collector->db_rows_scanned(1);
                }
            }

            // Check limit
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            // Throttle between batches for CPU/IO control
            if ($this->throttler) {
                $this->throttler->batch_yield();
            }

            // Keep database connection alive (only pings if 30+ seconds since last ping)
            $this->ping_if_needed();

            // Memory cleanup
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // Save checkpoint after each batch for resumable scans
            if ($count % $this->checkpoint_interval === 0) {
                $this->save_checkpoint('posts', $last_id, $count);
            }

            // Phase 3.2: Intra-table pause check
            // Check pause thresholds after each batch to allow pausing mid-table
            if ($this->should_pause()) {
                clean_sweep_log_message("DatabaseScanner: Pausing posts scan at row {$count}, last_id: {$last_id}", 'info');
                // Save checkpoint at pause point for resumption
                $this->save_checkpoint('posts', $last_id, $count);
                break;
            }

            // Report progress after each batch
            $this->report_progress($count, 0, "Scanning posts... ({$count} scanned)");

        } while (count($rows) === $batch_size);

        $this->report_progress($count, $count, "Scanned {$count} posts");
        return $threats;
    }

    /**
     * Scan options table.
     *
     * @return array Threats
     */
    private function scan_options() {
        global $wpdb;
        $threats = [];

        // Scan high-risk exact options
        foreach ($this->high_risk_options as $option_name) {
            $query = $wpdb->prepare(
                "SELECT option_name, option_value
                 FROM {$wpdb->options}
                 WHERE option_name = %s
                 AND LENGTH(option_value) > 0
                 LIMIT 10",
                $option_name
            );

            $rows = $this->query_with_recovery($query);

            foreach ($rows as $row) {
                if ($this->is_legitimate_option($row->option_name, $row->option_value)) {
                    continue;
                }

                $row_threats = $this->scan_content($row->option_value, $wpdb->options, 0, 'option_value');

                foreach ($row_threats as $threat) {
                    $threat['option_name'] = $row->option_name;
                    $threat['table'] = $wpdb->options;
                    $threat['row_id'] = 0;
                    $threat['column'] = 'option_value';
                    $threats[] = $threat;
                    $this->counters['threats_found']++;

                    // Add to collector for streaming and memory management
                    if ($this->collector) {
                        $this->collector->add($threat);
                    }
                }
            }
        }

// Scan high-risk option patterns
        foreach ($this->high_risk_option_patterns as $pattern) {
            $query = $wpdb->prepare(
                "SELECT option_name, option_value
                 FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                 AND LENGTH(option_value) > 0
                 LIMIT 50",
                $pattern
            );

            $rows = $this->query_with_recovery($query);

            foreach ($rows as $row) {
                if ($this->is_legitimate_option($row->option_name, $row->option_value)) {
                    continue;
                }

                $row_threats = $this->scan_content($row->option_value, $wpdb->options, 0, 'option_value');

                foreach ($row_threats as $threat) {
                    $threat['option_name'] = $row->option_name;
                    $threat['table'] = $wpdb->options;
                    $threat['row_id'] = 0;
                    $threat['column'] = 'option_value';
                    $threats[] = $threat;
                    $this->counters['threats_found']++;
                    if ($this->collector) {
                        $this->collector->add($threat);
                    }
                }
            }
        }

        if ($this->collector) {
            $this->collector->db_rows_scanned(count($threats));
        }

        return $threats;
    }

    /**
     * Check if an option is legitimate to avoid false positives.
     *
     * @param string $option_name
     * @param string $option_value
     * @return bool
     */
    private function is_legitimate_option($option_name, $option_value) {
        // Skip legitimate base64 images
        if (preg_match('/^data:image\/(png|jpg|jpeg|gif|svg);base64,/', $option_value)) {
            return true;
        }

        // Skip known legitimate large options
        if ($option_name === 'cron' && strlen($option_value) > 10000) {
            return true;
        }

        return false;
    }

    /**
     * Scan comments table.
     *
     * @return array Threats
     */
    private function scan_comments() {
        global $wpdb;
        $threats = [];
        $last_id = $this->resume_comments_last_id; // Start from resume position
        $batch_size = $this->profile->get_batch_size('db_rows');
        $limit = $this->profile->get_db_comment_limit();

        $count = 0;
        do {
            $query = $wpdb->prepare(
                "SELECT comment_ID, comment_content, comment_author_url
                 FROM {$wpdb->comments}
                 WHERE comment_ID > %d
                 AND comment_approved = '1'
                 AND (LENGTH(comment_content) > 0 OR LENGTH(comment_author_url) > 0)
                 ORDER BY comment_ID
                 LIMIT %d",
                $last_id,
                $batch_size
            );

            $rows = $this->query_with_recovery($query);

            foreach ($rows as $row) {
                $content = $row->comment_content . ' ' . $row->comment_author_url;
                $row_threats = $this->scan_content($content, $wpdb->comments, $row->comment_ID, 'comment_content');

                foreach ($row_threats as $threat) {
                    $threat['comment_id'] = $row->comment_ID;
                    $threat['table'] = $wpdb->comments;
                    $threat['row_id'] = $row->comment_ID;
                    $threat['column'] = 'comment_content';
                    $threats[] = $threat;
                    $this->counters['threats_found']++;

                    // Add to collector for streaming and memory management
                    if ($this->collector) {
                        $this->collector->add($threat);
                    }
                }

                $last_id = $row->comment_ID;
                $count++;

                if ($this->collector) {
                    $this->collector->db_rows_scanned(1);
                }
            }

            if ($limit > 0 && $count >= $limit) {
                break;
            }

            // Throttle between batches for CPU/IO control
            if ($this->throttler) {
                $this->throttler->batch_yield();
            }

            // Keep database connection alive (only pings if 30+ seconds since last ping)
            $this->ping_if_needed();

            // Save checkpoint after each batch for resumable scans
            if ($count % $this->checkpoint_interval === 0) {
                $this->save_checkpoint('comments', $last_id, $count);
            }

            // Phase 3.2: Intra-table pause check
            if ($this->should_pause()) {
                clean_sweep_log_message("DatabaseScanner: Pausing comments scan at row {$count}, last_id: {$last_id}", 'info');
                $this->save_checkpoint('comments', $last_id, $count);
                break;
            }

            // Report progress after each batch
            $this->report_progress($count, 0, "Scanning comments... ({$count} scanned)");

        } while (count($rows) === $batch_size);

        $this->report_progress($count, $count, "Scanned {$count} comments");

        return $threats;
    }

    /**
     * Scan postmeta table for high-risk keys with cursor-based pagination.
     * For large tables, uses ORDER BY meta_id LIMIT batch_size to iterate.
     *
     * @return array Threats
     */
    private function scan_postmeta() {
        global $wpdb;
        $threats = [];

        if (empty($this->high_risk_postmeta_keys)) {
            return $threats;
        }

        $last_meta_id = $this->resume_postmeta_last_id; // Start from resume position
        $batch_size = $this->profile->get_batch_size('db_rows');
        $high_risk_keys = $this->high_risk_postmeta_keys;
        $count = 0;

        do {
            $placeholders = '(' . implode(',', array_fill(0, count($high_risk_keys), '%s')) . ')';
            $query = $wpdb->prepare(
"SELECT meta_id, post_id, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE meta_id > %d
                 AND meta_key IN $placeholders
                 AND LENGTH(meta_value) > 0
                 ORDER BY meta_id
                 LIMIT %d",
                array_merge([$last_meta_id], $high_risk_keys, [$batch_size])
            );

            $rows = $this->query_with_recovery($query);

            foreach ($rows as $row) {
                $row_threats = $this->scan_content($row->meta_value, $wpdb->postmeta, $row->meta_id, 'meta_value');

                foreach ($row_threats as $threat) {
                    $threat['post_id'] = $row->post_id;
                    $threat['meta_key'] = $row->meta_key;
                    $threat['meta_id'] = $row->meta_id;
                    $threat['table'] = $wpdb->postmeta;
                    $threat['row_id'] = $row->meta_id;
                    $threat['column'] = 'meta_value';
                    $threats[] = $threat;
                    $this->counters['threats_found']++;

                    // Add to collector for streaming and memory management
                    if ($this->collector) {
                        $this->collector->add($threat);
                    }
                }

                $last_meta_id = $row->meta_id;
                $count++;

                // Record in collector
                if ($this->collector) {
                    $this->collector->db_rows_scanned(1);
                }
            }

            // Memory cleanup
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // Throttle between batches for CPU/IO control
            if ($this->throttler) {
                $this->throttler->batch_yield();
            }

            // Keep database connection alive (only pings if 30+ seconds since last ping)
            $this->ping_if_needed();

            // Save checkpoint after each batch for resumable scans
            if ($count % $this->checkpoint_interval === 0) {
                $this->save_checkpoint('postmeta', $last_meta_id, $count);
            }

            // Phase 3.2: Intra-table pause check
            if ($this->should_pause()) {
                clean_sweep_log_message("DatabaseScanner: Pausing postmeta scan at row {$count}, last_meta_id: {$last_meta_id}", 'info');
                $this->save_checkpoint('postmeta', $last_meta_id, $count);
                break;
            }

            // Report progress after each batch
            $this->report_progress($count, 0, "Scanning postmeta... ({$count} scanned)");

        } while (count($rows) === $batch_size);

        $this->report_progress($count, $count, "Scanned {$count} postmeta rows");

        if ($this->collector) {
            $this->collector->db_rows_scanned(count($threats));
        }

        return $threats;
    }

    /**
     * Scan users table.
     *
     * @return array Threats
     */
    private function scan_users() {
        global $wpdb;
        $threats = [];
        $last_id = $this->resume_users_last_id; // Start from resume position
        $batch_size = $this->profile->get_batch_size('db_rows');
        $limit = $this->profile->get_db_user_limit();

        $count = 0;
        do {
            $query = $wpdb->prepare(
                "SELECT ID, user_url, user_email
                 FROM {$wpdb->users}
                 WHERE ID > %d
                 AND (LENGTH(user_url) > 0 OR LENGTH(user_email) > 0)
                 ORDER BY ID
                 LIMIT %d",
                $last_id,
                $batch_size
            );

            $rows = $this->query_with_recovery($query);

            foreach ($rows as $row) {
                $content = $row->user_url . ' ' . $row->user_email;
                $row_threats = $this->scan_content($content, $wpdb->users, $row->ID, 'user_url');

                foreach ($row_threats as $threat) {
                    $threat['user_id'] = $row->ID;
                    $threat['table'] = $wpdb->users;
                    $threat['row_id'] = $row->ID;
                    $threat['column'] = 'user_url';
                    $threats[] = $threat;
                    $this->counters['threats_found']++;

                    // Add to collector for streaming and memory management
                    if ($this->collector) {
                        $this->collector->add($threat);
                    }
                }

                $last_id = $row->ID;
                $count++;

                if ($this->collector) {
                    $this->collector->db_rows_scanned(1);
                }
            }

            if ($limit > 0 && $count >= $limit) {
                break;
            }

            // Throttle between batches for CPU/IO control
            if ($this->throttler) {
                $this->throttler->batch_yield();
            }

            // Keep database connection alive (only pings if 30+ seconds since last ping)
            $this->ping_if_needed();

            // Save checkpoint after each batch for resumable scans
            if ($count % $this->checkpoint_interval === 0) {
                $this->save_checkpoint('users', $last_id, $count);
            }

            // Phase 3.2: Intra-table pause check
            if ($this->should_pause()) {
                clean_sweep_log_message("DatabaseScanner: Pausing users scan at row {$count}, last_id: {$last_id}", 'info');
                $this->save_checkpoint('users', $last_id, $count);
                break;
            }

            // Report progress after each batch
            $this->report_progress($count, 0, "Scanning users... ({$count} scanned)");

        } while (count($rows) === $batch_size);

        $this->report_progress($count, $count, "Scanned {$count} users");

        return $threats;
    }

    /**
     * Scan terms table with cursor-based pagination.
     *
     * @return array Threats
     */
    private function scan_terms() {
        global $wpdb;
        $threats = [];

        $batch_size = $this->profile->get_batch_size('db_rows');
        $last_term_id = $this->resume_terms_last_id; // Start from resume position
        $count = 0;

        do {
            $query = $wpdb->prepare(
                "SELECT term_id, name, slug
                 FROM {$wpdb->terms}
                 WHERE term_id > %d
                 AND (LENGTH(name) > 0 OR LENGTH(slug) > 0)
                 ORDER BY term_id
                 LIMIT %d",
                $last_term_id,
                $batch_size
            );

            $rows = $this->query_with_recovery($query);

            foreach ($rows as $row) {
                $content = $this->extract_table_content($wpdb->terms, $row);
                $row_threats = $this->scan_content($content, $wpdb->terms, $row->term_id, 'name');

                foreach ($row_threats as $threat) {
                    $threat['term_id'] = $row->term_id;
                    $threat['table'] = $wpdb->terms;
                    $threat['row_id'] = $row->term_id;
                    $threats[] = $threat;
                    $this->counters['threats_found']++;

                    // Add to collector for streaming and memory management
                    if ($this->collector) {
                        $this->collector->add($threat);
                    }
                }

                $last_term_id = $row->term_id;
                $count++;

                if ($this->collector) {
                    $this->collector->db_rows_scanned(1);
                }
            }

            // Throttle between batches for CPU/IO control
            if ($this->throttler) {
                $this->throttler->batch_yield();
            }

            // Keep database connection alive (only pings if 30+ seconds since last ping)
            $this->ping_if_needed();

            // Save checkpoint after each batch for resumable scans
            if ($count % $this->checkpoint_interval === 0) {
                $this->save_checkpoint('terms', $last_term_id, $count);
            }

            // Phase 3.2: Intra-table pause check
            if ($this->should_pause()) {
                clean_sweep_log_message("DatabaseScanner: Pausing terms scan at row {$count}, last_term_id: {$last_term_id}", 'info');
                $this->save_checkpoint('terms', $last_term_id, $count);
                break;
            }

            // Report progress after each batch
            $this->report_progress($count, 0, "Scanning terms... ({$count} scanned)");

        } while (count($rows) === $batch_size);

        $this->report_progress($count, $count, "Scanned {$count} terms");

        if ($this->collector) {
            $this->collector->db_rows_scanned($count);
        }

        return $threats;
    }

    /**
     * Scan term_taxonomy table with cursor-based pagination.
     *
     * @return array Threats
     */
    private function scan_term_taxonomy() {
        global $wpdb;
        $threats = [];

        $batch_size = $this->profile->get_batch_size('db_rows');
        $last_ttid = 0;
        $count = 0;

        do {
            $query = $wpdb->prepare(
                "SELECT term_taxonomy_id, description
                 FROM {$wpdb->term_taxonomy}
                 WHERE term_taxonomy_id > %d
                 AND LENGTH(description) > 0
                 ORDER BY term_taxonomy_id
                 LIMIT %d",
                $last_ttid,
                $batch_size
            );

            $rows = $this->query_with_recovery($query);

            foreach ($rows as $row) {
                $row_threats = $this->scan_content($row->description, $wpdb->term_taxonomy, $row->term_taxonomy_id, 'description');

                foreach ($row_threats as $threat) {
                    $threat['term_taxonomy_id'] = $row->term_taxonomy_id;
                    $threat['table'] = $wpdb->term_taxonomy;
                    $threat['row_id'] = $row->term_taxonomy_id;
                    $threats[] = $threat;
                    $this->counters['threats_found']++;

                    // Add to collector for streaming and memory management
                    if ($this->collector) {
                        $this->collector->add($threat);
                    }
                }

                $last_ttid = $row->term_taxonomy_id;
                $count++;

                if ($this->collector) {
                    $this->collector->db_rows_scanned(1);
                }
            }

            // Throttle between batches for CPU/IO control
            if ($this->throttler) {
                $this->throttler->batch_yield();
            }

            // Keep database connection alive (only pings if 30+ seconds since last ping)
            $this->ping_if_needed();

            // Save checkpoint after each batch for resumable scans
            if ($count % $this->checkpoint_interval === 0) {
                $this->save_checkpoint('term_taxonomy', $last_ttid, $count);
            }

            // Phase 3.2: Intra-table pause check
            if ($this->should_pause()) {
                clean_sweep_log_message("DatabaseScanner: Pausing term_taxonomy scan at row {$count}, last_ttid: {$last_ttid}", 'info');
                $this->save_checkpoint('term_taxonomy', $last_ttid, $count);
                break;
            }

            // Report progress after each batch
            $this->report_progress($count, 0, "Scanning term_taxonomy... ({$count} scanned)");

        } while (count($rows) === $batch_size);

        $this->report_progress($count, $count, "Scanned {$count} term_taxonomy rows");

        if ($this->collector) {
            $this->collector->db_rows_scanned($count);
        }

        return $threats;
    }

    /**
     * Scan usermeta table for high-risk keys with cursor-based pagination.
     * For large tables, uses ORDER BY user_id, umeta_id LIMIT batch_size to iterate.
     *
     * @return array Threats
     */
    private function scan_usermeta() {
        global $wpdb;
        $threats = [];

        $high_risk_usermeta = ['nickname', 'description', 'first_name', 'last_name'];

        if (empty($high_risk_usermeta)) {
            return $threats;
        }

        $placeholders = '(' . implode(',', array_fill(0, count($high_risk_usermeta), '%s')) . ')';
        $batch_size = $this->profile->get_batch_size('db_rows');

        // Use cursor-based pagination with (user_id, umeta_id) composite cursor
        // This ensures stable ordering even if user_ids are reused
        $last_user_id = 0;
        $last_umeta_id = 0;
        $count = 0;

        do {
            $query = $wpdb->prepare(
                "SELECT umeta_id, user_id, meta_key, meta_value
                 FROM {$wpdb->usermeta}
                 WHERE (user_id > %d OR (user_id = %d AND umeta_id > %d))
                 AND meta_key IN $placeholders
                 AND LENGTH(meta_value) > 0
                 ORDER BY user_id, umeta_id
                 LIMIT %d",
                $last_user_id,
                $last_user_id,
                $last_umeta_id,
                array_merge([$last_user_id, $last_umeta_id], $high_risk_usermeta, [$batch_size])
            );

            $rows = $this->query_with_recovery($query);

            foreach ($rows as $row) {
                $row_threats = $this->scan_content($row->meta_value, $wpdb->usermeta, $row->user_id, 'meta_value');

                foreach ($row_threats as $threat) {
                    $threat['user_id'] = $row->user_id;
                    $threat['meta_key'] = $row->meta_key;
                    $threat['meta_id'] = $row->umeta_id;
                    $threat['table'] = $wpdb->usermeta;
                    $threat['row_id'] = $row->user_id;
                    $threats[] = $threat;
                    $this->counters['threats_found']++;

                    // Add to collector for streaming and memory management
                    if ($this->collector) {
                        $this->collector->add($threat);
                    }
                }

                $last_user_id = $row->user_id;
                $last_umeta_id = $row->umeta_id;
                $count++;

                // Record in collector
                if ($this->collector) {
                    $this->collector->db_rows_scanned(1);
                }
            }

            // Memory cleanup
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // Throttle between batches for CPU/IO control
            if ($this->throttler) {
                $this->throttler->batch_yield();
            }

            // Keep database connection alive (only pings if 30+ seconds since last ping)
            $this->ping_if_needed();

            // Save checkpoint after each batch for resumable scans
            if ($count % $this->checkpoint_interval === 0) {
                $this->save_checkpoint('usermeta', $last_user_id, $count);
            }

            // Phase 3.2: Intra-table pause check
            if ($this->should_pause()) {
                clean_sweep_log_message("DatabaseScanner: Pausing usermeta scan at row {$count}, last_user_id: {$last_user_id}", 'info');
                $this->save_checkpoint('usermeta', $last_user_id, $count);
                break;
            }

            // Report progress after each batch
            $this->report_progress($count, 0, "Scanning usermeta... ({$count} scanned)");

        } while (count($rows) === $batch_size);

        $this->report_progress($count, $count, "Scanned {$count} usermeta rows");

        if ($this->collector) {
            $this->collector->db_rows_scanned($count);
        }

        return $threats;
    }

    /**
     * Scan content against signatures.
     *
     * @param string $content
     * @param string $table
     * @return array
     */
    private function scan_content($content, $table, $row_id = 0, $column = '', array $opts = []) {
        $threats = [];
        $this->row_sig_truncated = false;

        $compiled = CleanSweep_SignatureMatcher::order_by_severity(
            $this->filter_compiled_for_haystack($this->get_compiled_patterns(), $table, (string) $content)
        );
        $throttler = $this->throttler;
        $bounded = !empty($opts['bounded']);
        $row_started = (float) ($opts['row_started'] ?? microtime(true));
        $time_budget = (float) ($opts['time_budget'] ?? 0);

        $on_tick = function ($n) use ($throttler, $time_budget, $row_started) {
            if ($throttler) {
                $throttler->micro_yield($n);
            }
            if ($this->should_pause()) {
                return true;
            }
            if ($time_budget > 0 && (microtime(true) - $row_started) >= $time_budget) {
                $this->row_sig_truncated = true;
                return true;
            }
            return false;
        };

        $on_preg_error = function ($index, $pattern, $code) use ($table, $row_id) {
            if ((int) $code === PREG_BACKTRACK_LIMIT_ERROR) {
                clean_sweep_log_message(
                    "DatabaseScanner: backtrack_killed {$table} #{$row_id} sig={$index}",
                    'info'
                );
            }
        };

        $run_match = function ($text, $start_offset = 0) use ($compiled, $on_tick, $on_preg_error) {
            return CleanSweep_SignatureMatcher::match_content(
                $text,
                $compiled,
                $on_tick,
                $start_offset,
                $on_preg_error
            );
        };

        $prev_limit = null;
        $haystack_len = strlen((string) $content);
        $tighten_pcre = $bounded || $haystack_len > CleanSweep_ChunkProcessor::CHUNK_SIZE;
        if ($tighten_pcre) {
            $new_limit = 0;
            if (method_exists($this->profile, 'get_db_pcre_backtrack_limit')) {
                $new_limit = (int) $this->profile->get_db_pcre_backtrack_limit();
            }
            if ($new_limit > 0) {
                $prev_limit = ini_get('pcre.backtrack_limit');
                ini_set('pcre.backtrack_limit', (string) $new_limit);
            }
        }

        try {
            $hits = [];
            $len = strlen((string) $content);
            if (($bounded || $tighten_pcre) && $len > CleanSweep_ChunkProcessor::CHUNK_SIZE) {
                if ($this->chunk_processor === null) {
                    $this->chunk_processor = new CleanSweep_ChunkProcessor();
                }
                $this->chunk_processor->iterate_string((string) $content, function ($chunk, $chunk_info) use (&$hits, $run_match, $on_tick, $on_preg_error) {
                    if ($this->row_sig_truncated || $this->should_pause()) {
                        return false;
                    }
                    $part = $run_match($chunk);
                    $overlap_len = strlen($chunk_info['previous_tail'] ?? '');
                    if ($overlap_len > 0 && empty($chunk_info['is_first']) && $part) {
                        $rescan = [];
                        foreach ($part as $hit) {
                            if ((int) ($hit['offset'] ?? 0) < $overlap_len) {
                                $rescan[] = [
                                    'index' => (int) $hit['index'],
                                    'pattern' => (string) $hit['pattern'],
                                ];
                            }
                        }
                        if ($rescan !== []) {
                            foreach (CleanSweep_SignatureMatcher::match_content(
                                $chunk,
                                $rescan,
                                $on_tick,
                                $overlap_len,
                                $on_preg_error
                            ) as $extra) {
                                $part[] = $extra;
                            }
                        }
                    }
                    $hits = array_merge($hits, $part);
                    return null;
                });
            } else {
                $hits = $run_match((string) $content);
            }
        } finally {
            if ($prev_limit !== null) {
                ini_set('pcre.backtrack_limit', (string) $prev_limit);
            }
        }

        foreach ($hits as $hit) {
            $meta = $hit['meta'];
            $sig_id = $meta['id'];
            $match = $hit['match'];
            $index = (int) $hit['index'];

            $threats[] = [
                'id' => md5($table . $row_id . $column . $sig_id . $match),
                'pattern' => $sig_id,
                'signature_id' => $sig_id,
                'signature_index' => $index,
                'match' => substr($match, 0, 100),
                'content_preview' => substr($content, 0, 200),
                'matched_content' => $match,
                'risk_score' => $meta['risk_score'],
                'threat_level' => $meta['threat_level'],
                'category' => $meta['category'],
                'severity' => $meta['severity'],
                'family' => $meta['family'] !== '' ? $meta['family'] : null,
                'source' => 'database',
                'table' => $table,
                'row_id' => $row_id,
                'column' => $column,
                'open_in_editor' => 'DB:' . $table . ':' . $row_id . ':' . $column,
                'detected_at' => date('c'),
            ];
        }

        return $threats;
    }

    /**
     * Get category for a signature pattern.
     *
     * @param string $pattern
     * @return string
     */
    private function get_signature_category($pattern) {
        return CleanSweep_SignaturePreFilter::guess_category_for_pattern((string) $pattern);
    }

    /**
     * Report progress.
     *
     * @param int $current
     * @param int $total
     * @param string $message
     */
    private function report_progress($current, $total, $message) {
        if ($this->progress_callback) {
            $progress = $total > 0 ? round(($current / $total) * 100) : 0;
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
     * Scan a specific table segment by ID range.
     * This enables work queue-based parallel scanning of large tables.
     *
     * @param string $table Table name (e.g., 'wp_posts')
     * @param string $id_column ID column name (e.g., 'ID')
     * @param int $start_id Start ID (inclusive)
     * @param int $end_id End ID (inclusive)
     * @param string|null $where_clause Optional WHERE clause (appended with AND)
     * @param array $opts skip_ids: int[]
     * @return array ['scanned' => int, 'threats_found' => int, 'last_id' => int]
     */
    public function scan_table_segment($table, $id_column = 'ID', $start_id = 0, $end_id = 0, $where_clause = null, array $opts = []) {
        global $wpdb;

        if ($this->phase_start_time <= 0) {
            $this->phase_start_time = microtime(true);
        }
        if ($this->request_start_time <= 0) {
            $this->request_start_time = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? $_SERVER['REQUEST_TIME'] ?? $this->phase_start_time);
        }

        $this->poison_skipped = [];
        $skip_ids = self::normalize_skip_ids($opts['skip_ids'] ?? []);

        $table_q = $this->quote_ident($table);
        $id_q = $this->quote_ident($id_column);
        if ($table_q === null || $id_q === null) {
            clean_sweep_log_message("DatabaseScanner: invalid identifier {$table}.{$id_column}", 'error');
            return [
                'scanned' => 0,
                'threats_found' => 0,
                'last_id' => (int) $start_id,
                'paused' => false,
                'flagged_post_ids' => [],
                'poison_ids' => [],
            ];
        }

        clean_sweep_log_message("DatabaseScanner: Scanning table segment {$table} after ID {$start_id} to {$end_id}", 'info');

        $threats = [];
        $flagged_post_ids = [];
        $scanned = 0;
        $last_id = (int) $start_id;
        $suffix = $this->table_suffix($table);
        $content_col = $this->get_content_column($table);
        $content_q = $this->quote_ident($content_col);
        $key_col = $this->sql_key_column($suffix);
        $key_q = $key_col !== '' ? $this->quote_ident($key_col) : null;

        $full_bytes = method_exists($this->profile, 'get_db_full_scan_bytes')
            ? (int) $this->profile->get_db_full_scan_bytes() : 32768;
        $prefix_bytes = method_exists($this->profile, 'get_db_prefix_bytes')
            ? (int) $this->profile->get_db_prefix_bytes() : 32768;
        $hard_skip = method_exists($this->profile, 'get_db_hard_skip_bytes')
            ? (int) $this->profile->get_db_hard_skip_bytes() : 524288;
        $byte_budget = method_exists($this->profile, 'get_db_fetch_byte_budget')
            ? (int) $this->profile->get_db_fetch_byte_budget() : 524288;
        $probe_limit = method_exists($this->profile, 'get_db_length_probe_limit')
            ? (int) $this->profile->get_db_length_probe_limit() : 500;
        $row_time = method_exists($this->profile, 'get_db_row_time_budget')
            ? (float) $this->profile->get_db_row_time_budget() : 2.0;

        $where_sql = $where_clause ? " AND " . $where_clause : "";

        do {
            if ($this->should_pause()) {
                clean_sweep_log_message("DatabaseScanner: Pause before batch in {$table} at id {$last_id}", 'info');
                break;
            }

            $select = "{$id_q} AS id, LENGTH({$content_q}) AS len";
            if ($key_q) {
                $select .= ", {$key_q} AS row_key";
            }
            $probe_sql = $wpdb->prepare(
                "SELECT {$select} FROM {$table_q} WHERE {$id_q} > %d AND {$id_q} <= %d{$where_sql} ORDER BY {$id_q} ASC LIMIT %d",
                $last_id,
                $end_id,
                $probe_limit
            );
            $probes = $this->query_with_recovery(is_string($probe_sql) ? $probe_sql : '');
            if (!$this->last_query_ok) {
                clean_sweep_log_message(
                    "DatabaseScanner: probe failed for {$table} after id {$last_id}, pausing to retry",
                    'warning'
                );
                $this->needs_pause = true;
                break;
            }
            if (empty($probes)) {
                break;
            }

            $probe_rows = [];
            foreach ($probes as $p) {
                $pid = (int) ($p->id ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $probe_rows[] = [
                    'id' => $pid,
                    'len' => (int) ($p->len ?? 0),
                    'key' => (string) ($p->row_key ?? ''),
                ];
            }

            $packed = self::pack_ids_by_bytes($probe_rows, $byte_budget, $full_bytes, $hard_skip, $skip_ids);
            if ($packed['last_id'] === null) {
                break;
            }

            $full_ids = array_map(static function ($r) { return (int) $r['id']; }, $packed['full']);
            $prefix_ids = array_map(static function ($r) { return (int) $r['id']; }, $packed['prefix']);

            $fetched = [];
            if ($full_ids !== []) {
                foreach ($this->fetch_segment_rows($table, $id_column, $full_ids, 0) as $row) {
                    $rid = (int) $row->$id_column;
                    $fetched[$rid] = ['row' => $row, 'bounded' => false];
                }
            }
            if ($prefix_ids !== []) {
                foreach ($this->fetch_segment_rows($table, $id_column, $prefix_ids, $prefix_bytes) as $row) {
                    $rid = (int) $row->$id_column;
                    $fetched[$rid] = ['row' => $row, 'bounded' => true];
                }
            }

            foreach (self::ordered_batch_items($packed) as $rid => $item) {
                if ($this->should_pause()) {
                    clean_sweep_log_message("DatabaseScanner: Pause mid-segment {$table} at id {$last_id}", 'info');
                    break 2;
                }

                $kind = (string) ($item['kind'] ?? '');
                $row_key = (string) ($item['key'] ?? '');
                $raw_len = (int) ($item['len'] ?? 0);

                if ($kind === 'poison' || $kind === 'skip') {
                    $reason = $kind === 'poison' ? 'poison' : (string) ($item['reason'] ?? 'too_large');
                    if ($kind === 'poison' || $reason === 'too_large') {
                        $this->poison_skipped[] = $rid;
                    }
                    $mode = ($reason === 'empty') ? 'empty' : 'skipped';
                    $this->log_row_outcome($table, $rid, $row_key, $raw_len, $mode, $reason);
                    $last_id = $rid;
                    $scanned++;
                    $this->bump_row_counter();
                    $this->mark_row_done($table, $rid, $row_key, $raw_len, $mode, $kind === 'poison' || $reason === 'too_large');
                    continue;
                }

                if (!isset($fetched[$rid])) {
                    clean_sweep_log_message(
                        "DatabaseScanner: fetch missed {$table} #{$rid}, leaving row unfinished",
                        'warning'
                    );
                    $this->needs_pause = true;
                    break 2;
                }

                $row = $fetched[$rid]['row'];
                $bounded = !empty($fetched[$rid]['bounded']);
                $content = $this->extract_table_content($table, $row, $bounded, $prefix_bytes);
                if ($this->should_skip_transient_row($suffix, $row, $content)) {
                    $last_id = $rid;
                    $scanned++;
                    $this->mark_row_done($table, $rid, $row_key, $raw_len, 'skipped', false);
                    continue;
                }

                $mode = $bounded ? 'truncated' : 'full';
                if ($content !== '') {
                    $this->mark_row_in_progress($table, $rid, $row_key, $raw_len, $mode, $bounded);
                    $content = $this->expand_scan_text($content, $suffix, $row);
                    $use_budget = $bounded || strlen($content) > $full_bytes;
                    $row_started = microtime(true);
                    $row_threats = $this->scan_content(
                        $content,
                        $table,
                        $rid,
                        $content_col,
                        [
                            'bounded' => $bounded,
                            'row_started' => $row_started,
                            'time_budget' => $use_budget ? $row_time : 0,
                        ]
                    );

                    if ($this->needs_continuation() && !$this->row_sig_truncated) {
                        clean_sweep_log_message(
                            "DatabaseScanner: slice pause during {$table} #{$rid}, leaving row unfinished",
                            'info'
                        );
                        break 2;
                    }

                    foreach ($row_threats as $threat) {
                        $threat['table'] = $table;
                        $threat['row_id'] = $rid;
                        $threats[] = $threat;
                        $this->counters['threats_found']++;
                        if ($this->collector) {
                            $this->collector->add($threat);
                        }
                    }

                    if ($suffix === 'posts' && !empty($row_threats)) {
                        $ptype = (string) ($row->post_type ?? '');
                        if ($ptype !== 'revision') {
                            $flagged_post_ids[] = $rid;
                        }
                    }

                    if ($mode === 'truncated' || $this->row_sig_truncated) {
                        $mode = 'truncated';
                        $detail = $this->row_sig_truncated ? 'time_budget' : 'prefix';
                        $this->log_row_outcome($table, $rid, $row_key, $raw_len, 'truncated', $detail);
                    }

                    if ($bounded && $this->collector) {
                        $this->collector->flush();
                    }
                }

                $last_id = $rid;
                $scanned++;
                $this->bump_row_counter();
                $this->mark_row_done($table, $rid, $row_key, $raw_len, $mode, $bounded);
            }

            if ($this->throttler) {
                $this->throttler->batch_yield();
            }
            $this->ping_if_needed();

            if (count($probes) < $probe_limit) {
                break;
            }
        } while ($last_id < $end_id);

        $this->clear_in_progress();

        return [
            'scanned' => $scanned,
            'threats_found' => count($threats),
            'last_id' => $last_id,
            'paused' => $this->needs_continuation(),
            'flagged_post_ids' => array_values(array_unique($flagged_post_ids)),
            'poison_ids' => array_values(array_unique(array_map('intval', $this->poison_skipped))),
        ];
    }

    /**
     * Extract scannable content from a database row based on table type.
     *
     * Prefix mode truncates only the primary content column. Title, excerpt,
     * guid, and post_name stay attached so doorway slugs survive a 32KB body.
     *
     * @param string $table
     * @param object $row
     * @param bool $bounded Whether this row was prefix-fetched
     * @param int $prefix_bytes Max bytes of the primary content column when bounded
     * @return string
     */
    private function extract_table_content($table, $row, $bounded = false, $prefix_bytes = 0) {
        switch ($this->table_suffix($table)) {
            case 'posts':
                $body = (string) ($row->post_content ?? '');
                if ($bounded && $prefix_bytes > 0 && strlen($body) > $prefix_bytes) {
                    $body = substr($body, 0, $prefix_bytes);
                }
                if (!$bounded) {
                    $body .= ' ' . (string) ($row->post_content_filtered ?? '');
                }
                $meta = trim(implode(' ', array_filter([
                    (string) ($row->post_title ?? ''),
                    (string) ($row->post_excerpt ?? ''),
                    (string) ($row->guid ?? ''),
                    CleanSweep_SeoKeywordCatalog::wrap_slug_segment($row->post_name ?? ''),
                ], static function ($part) {
                    return $part !== '';
                })));
                return trim($meta . ' ' . $body);
            case 'options':
                return (string)($row->option_value ?? '');
            case 'comments':
                return ($row->comment_content ?? '') . ' ' . ($row->comment_author_url ?? '')
                    . ' ' . ($row->comment_author ?? '') . ' ' . ($row->comment_author_email ?? '');
            case 'postmeta':
            case 'usermeta':
            case 'sitemeta':
            case 'termmeta':
            case 'commentmeta':
            case 'blogmeta':
                return (string)($row->meta_value ?? '');
            case 'users':
                return ($row->user_url ?? '') . ' ' . ($row->user_email ?? '')
                    . ' ' . ($row->display_name ?? '') . ' ' . ($row->user_nicename ?? '');
            case 'terms':
                return trim((string) ($row->name ?? '') . ' '
                    . CleanSweep_SeoKeywordCatalog::wrap_slug_segment($row->slug ?? ''));
            case 'term_taxonomy':
                return (string)($row->description ?? '');
            case 'blogs':
                return ($row->domain ?? '') . ' ' . ($row->path ?? '');
            case 'signups':
                return ($row->user_login ?? '') . ' ' . ($row->user_email ?? '')
                    . ' ' . ($row->domain ?? '') . ' ' . ($row->path ?? '') . ' ' . ($row->activation_key ?? '');
            default:
                $content = '';
                foreach ((array)$row as $value) {
                    if (is_string($value)) {
                        $content .= ' ' . $value;
                    }
                }
                return $content;
        }
    }

    /**
     * Get the primary content column for a table.
     *
     * @param string $table
     * @return string
     */
    private function get_content_column($table) {
        switch ($this->table_suffix($table)) {
            case 'posts':
                return 'post_content';
            case 'options':
                return 'option_value';
            case 'comments':
                return 'comment_content';
            case 'users':
                return 'user_url';
            case 'terms':
                return 'name';
            case 'term_taxonomy':
                return 'description';
            case 'blogs':
                return 'domain';
            case 'signups':
                return 'user_email';
            default:
                return 'meta_value';
        }
    }

    /**
     * Get the full table name with wp prefix.
     *
     * @param string $table
     * @return string
     */
    private function get_table_name($table) {
        global $wpdb;
        return $wpdb->prefix . $table;
    }

    /**
     * Match a physical table name (including blog prefix) to a known suffix.
     */
    private function table_suffix($table) {
        static $suffixes = [
            'term_taxonomy', 'commentmeta', 'postmeta', 'usermeta', 'sitemeta',
            'termmeta', 'blogmeta', 'comments', 'options', 'signups',
            'users', 'terms', 'posts', 'blogs',
        ];
        $t = strtolower((string)$table);
        foreach ($suffixes as $s) {
            if ($t === $s || substr($t, -strlen($s) - 1) === '_' . $s) {
                return $s;
            }
        }
        return '';
    }

    private function php_memory_limit_bytes() {
        $raw = trim((string)@ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }
        $unit = strtolower(substr($raw, -1));
        $num = (float)$raw;
        switch ($unit) {
            case 'g':
                return (int)($num * 1073741824);
            case 'm':
                return (int)($num * 1048576);
            case 'k':
                return (int)($num * 1024);
            default:
                return (int)$num;
        }
    }

    private function row_key_name($suffix, $row) {
        if ($suffix === 'options') {
            return (string)($row->option_name ?? '');
        }
        if (in_array($suffix, ['sitemeta', 'postmeta', 'usermeta', 'termmeta', 'commentmeta', 'blogmeta'], true)) {
            return (string)($row->meta_key ?? '');
        }
        return '';
    }

    private function is_transient_key($key) {
        return strpos($key, '_transient') === 0 || strpos($key, '_site_transient') === 0;
    }

    /**
     * CleanSweep_Worker-side transient policy (Standard cap; timeouts always skipped).
     */
    private function should_skip_transient_row($suffix, $row, $content) {
        $key = $this->row_key_name($suffix, $row);
        if ($key === '' || !$this->is_transient_key($key)) {
            return false;
        }
        if (strpos($key, '_transient_timeout') === 0 || strpos($key, '_site_transient_timeout') === 0) {
            return true;
        }
        $min = (int)$this->profile->get_transient_min_length();
        if ($min < 0) {
            return true;
        }
        if (strlen((string)$content) <= $min) {
            return true;
        }
        $cap = (int)$this->profile->get_transient_row_cap();
        if ($cap > 0 && $this->transients_scanned >= $cap) {
            return true;
        }
        $this->transients_scanned++;
        return false;
    }

    /**
     * Build extra scan text: unescape (Standard+), Gutenberg JSON (Deep),
     * unserialize + encoding chains (Deep option/meta blobs).
     */
    private function expand_scan_text($content, $suffix, $row) {
        if (!is_string($content) || $content === '') {
            return $content;
        }

        $variants = [$content];
        $mode = method_exists($this->profile, 'get_gutenberg_mode')
            ? $this->profile->get_gutenberg_mode()
            : 'none';

        if ($mode === 'unescape' || $mode === 'decode') {
            $unescaped = $this->cheap_unescape($content);
            if ($unescaped !== $content && $unescaped !== '') {
                $variants[] = $unescaped;
            }
        }

        $deep = $this->profile->get_profile_id() === 'deep';
        $meta_like = $deep
            ? in_array($suffix, [
                'options', 'postmeta', 'usermeta', 'sitemeta', 'termmeta', 'commentmeta', 'blogmeta',
            ], true)
            : in_array($suffix, ['options', 'postmeta', 'sitemeta'], true);

        if ($this->profile->should_unpack_db_values() && $meta_like) {
            $unpacked = $this->unpack_serialized($content);
            if ($unpacked !== '') {
                $variants[] = $unpacked;
                if ($mode === 'decode') {
                    $gutenberg = $this->decode_gutenberg($unpacked);
                    if ($gutenberg !== '') {
                        $variants[] = $gutenberg;
                    }
                }
            }
            if (method_exists($this->profile, 'should_decode_encoding_chains')
                ? $this->profile->should_decode_encoding_chains()
                : $this->profile->get_profile_id() === 'deep') {
                $chained = $this->decode_encoding_chain($content);
                if ($chained !== '') {
                    $variants[] = $chained;
                }
            }
        }

        $looks_like_blocks = ($suffix === 'posts' || strpos($content, '<!-- wp:') !== false);
        $key = $this->row_key_name($suffix, $row);
        if ($key !== '' && (strpos($key, 'widget_block') === 0 || strpos($key, 'widget_custom_html') === 0)) {
            $looks_like_blocks = true;
        }

        if ($mode === 'decode' && $looks_like_blocks) {
            $gutenberg = $this->decode_gutenberg($content);
            if ($gutenberg !== '') {
                $variants[] = $gutenberg;
            }
        }

        $text = implode("\n", array_unique($variants));
        $max = 1500000;
        if (strlen($text) > $max) {
            return substr($text, 0, $max);
        }
        return $text;
    }

    private function cheap_unescape($s) {
        $out = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
        $out = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static function ($m) {
            $code = hexdec($m[1]);
            if (function_exists('mb_convert_encoding')) {
                return mb_convert_encoding(pack('n', $code), 'UTF-8', 'UTF-16BE');
            }
            if ($code < 0x80) {
                return chr($code);
            }
            if ($code < 0x800) {
                return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
            }
            return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
        }, $out);
        return is_string($out) ? $out : $s;
    }

    private function decode_gutenberg($content) {
        if (strpos($content, '<!-- wp:') === false) {
            return '';
        }
        $parts = [];
        $pos = 0;
        $len = strlen($content);
        while ($pos < $len && ($start = strpos($content, '<!-- wp:', $pos)) !== false) {
            if ($this->should_pause()) {
                break;
            }
            $json_start = strpos($content, '{', $start);
            $end_comment = strpos($content, '-->', $start);
            if ($end_comment === false) {
                break;
            }
            if ($json_start !== false && $json_start < $end_comment) {
                $json = $this->extract_json_object($content, $json_start);
                if ($json !== null) {
                    $data = json_decode($json, true);
                    if (is_array($data)) {
                        $walked = $this->walk_strings($data, 0);
                        if ($walked !== '') {
                            $parts[] = $walked;
                        }
                    }
                }
            }
            $pos = $end_comment + 3;
        }
        return implode("\n", $parts);
    }

    private function extract_json_object($s, $start) {
        $len = strlen($s);
        if ($start >= $len || $s[$start] !== '{') {
            return null;
        }
        $depth = 0;
        $in_str = false;
        $escape = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $s[$i];
            if ($in_str) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $in_str = false;
                }
                continue;
            }
            if ($ch === '"') {
                $in_str = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }

    private function unpack_serialized($content) {
        if (!is_string($content) || strlen($content) < 4 || strlen($content) > 2097152) {
            return '';
        }
        $first = $content[0];
        if ($first !== 'a' && $first !== 'O' && $first !== 's' && $first !== 'C') {
            return '';
        }
        $val = @unserialize($content, ['allowed_classes' => false]);
        if ($val === false || $val === $content) {
            return '';
        }
        return $this->walk_strings($val, 0);
    }

    private function walk_strings($value, $depth) {
        if ($depth > 8) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        $parts = [];
        $n = 0;
        foreach ($value as $item) {
            $got = $this->walk_strings($item, $depth + 1);
            if ($got !== '') {
                $parts[] = $got;
            }
            if (++$n > 200) {
                break;
            }
            if (strlen(implode("\n", $parts)) > 1048576) {
                break;
            }
        }
        return implode("\n", $parts);
    }

    private function decode_encoding_chain($content) {
        if (!is_string($content) || strlen($content) < 24 || strlen($content) > 2097152) {
            return '';
        }
        $decoded = $content;
        $layers = 0;
        $max_out = 1048576;
        for ($i = 0; $i < 6; $i++) {
            if ($this->should_pause()) {
                break;
            }
            $prev = $decoded;
            $trim = trim($decoded);
            $next = null;
            if (strlen($trim) > 32 && preg_match('#^[A-Za-z0-9+/]+=*$#', $trim)) {
                $attempt = base64_decode($trim, true);
                if (is_string($attempt) && $attempt !== '' && $attempt !== $prev) {
                    $next = $attempt;
                }
            }
            if ($next === null && strlen($decoded) > 16) {
                $attempt = @gzinflate($decoded);
                if (is_string($attempt) && $attempt !== '') {
                    $next = $attempt;
                }
            }
            if ($next === null && strlen($decoded) > 16) {
                $attempt = @gzuncompress($decoded);
                if (is_string($attempt) && $attempt !== '') {
                    $next = $attempt;
                }
            }
            if ($next === null && function_exists('str_rot13') && strlen($trim) > 16 && preg_match('/^[A-Za-z]+$/', $trim)) {
                $next = str_rot13($trim);
            }
            if ($next === null || $next === $prev) {
                break;
            }
            if (strlen($next) > $max_out) {
                $decoded = substr($next, 0, $max_out);
                $layers++;
                break;
            }
            $decoded = $next;
            $layers++;
        }
        return $layers > 0 ? $decoded : '';
    }

    /**
     * Accept both [id => true] maps and [id, id] lists.
     *
     * @param mixed $raw
     * @return array<int,true>
     */
    public static function normalize_skip_ids($raw) {
        $out = [];
        foreach ((array) $raw as $k => $v) {
            if ($v === true && (int) $k > 0) {
                $out[(int) $k] = true;
            } elseif ($v !== true && (int) $v > 0) {
                $out[(int) $v] = true;
            }
        }
        return $out;
    }

    /**
     * Poison/skip/full/prefix items in meta_id order so last_id never jumps.
     *
     * @param array $packed
     * @return array<int,array>
     */
    public static function ordered_batch_items(array $packed) {
        $work = [];
        foreach (['poison' => 'poison', 'skip' => 'skip', 'full' => 'full', 'prefix' => 'prefix'] as $bucket => $kind) {
            foreach ($packed[$bucket] ?? [] as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $item['kind'] = $kind;
                $work[$id] = $item;
            }
        }
        ksort($work, SORT_NUMERIC);
        return $work;
    }

    private function bump_row_counter() {
        $this->counters['db_rows_scanned']++;
        if ($this->collector) {
            $this->collector->db_rows_scanned(1);
        }
    }

    /**
     * Pack LENGTH() probes into a fetch batch under a byte budget.
     *
     * @param array<int,array{id:int,len:int,key?:string}> $probes
     * @param array<int,bool|int> $skip_ids
     * @return array{full:array,prefix:array,skip:array,poison:array,bytes:int,last_id:?int}
     */
    public static function pack_ids_by_bytes(array $probes, int $byte_budget, int $full_bytes, int $hard_skip_bytes, array $skip_ids = []) {
        $skip_map = self::normalize_skip_ids($skip_ids);

        $full = [];
        $prefix = [];
        $skip = [];
        $poison = [];
        $bytes = 0;
        $last_id = null;
        $have_fetch = false;
        $byte_budget = max(1, $byte_budget);
        $full_bytes = max(1, $full_bytes);
        $hard_skip_bytes = max($full_bytes, $hard_skip_bytes);

        foreach ($probes as $p) {
            $id = (int) ($p['id'] ?? 0);
            $len = (int) ($p['len'] ?? 0);
            $key = (string) ($p['key'] ?? '');
            if ($id <= 0) {
                continue;
            }

            if (isset($skip_map[$id])) {
                $poison[] = ['id' => $id, 'len' => $len, 'key' => $key];
                $last_id = $id;
                continue;
            }

            if ($len <= 0) {
                $skip[] = ['id' => $id, 'len' => $len, 'key' => $key, 'reason' => 'empty'];
                $last_id = $id;
                continue;
            }

            if ($len > $hard_skip_bytes) {
                $skip[] = ['id' => $id, 'len' => $len, 'key' => $key, 'reason' => 'too_large'];
                $last_id = $id;
                continue;
            }

            $charge = $len > $full_bytes ? $full_bytes : $len;
            if ($have_fetch && ($bytes + $charge) > $byte_budget) {
                break;
            }

            $bytes += $charge;
            $have_fetch = true;
            $last_id = $id;
            $item = ['id' => $id, 'len' => $len, 'key' => $key];
            if ($len > $full_bytes) {
                $prefix[] = $item;
            } else {
                $full[] = $item;
            }
        }

        return [
            'full' => $full,
            'prefix' => $prefix,
            'skip' => $skip,
            'poison' => $poison,
            'bytes' => $bytes,
            'last_id' => $last_id,
        ];
    }

    private function quote_ident($name) {
        $name = (string) $name;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return null;
        }
        return '`' . $name . '`';
    }

    private function sql_key_column($suffix) {
        if ($suffix === 'options') {
            return 'option_name';
        }
        if (in_array($suffix, ['postmeta', 'usermeta', 'sitemeta', 'termmeta', 'commentmeta', 'blogmeta'], true)) {
            return 'meta_key';
        }
        return '';
    }

    private function fetch_segment_rows($table, $id_column, array $ids, $prefix_bytes) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $id_q = $this->quote_ident($id_column);
        $select = $this->fetch_select_sql($table, $id_column, (int) $prefix_bytes);
        if ($select === null || $id_q === null) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "{$select} WHERE {$id_q} IN ({$placeholders}) ORDER BY {$id_q} ASC",
            ...$ids
        );
        return $this->query_with_recovery($sql);
    }

    private function fetch_select_sql($table, $id_column, $prefix_bytes) {
        $table_q = $this->quote_ident($table);
        $id_q = $this->quote_ident($id_column);
        $content_q = $this->quote_ident($this->get_content_column($table));
        if ($table_q === null || $id_q === null || $content_q === null) {
            return null;
        }
        if ($prefix_bytes <= 0) {
            return "SELECT * FROM {$table_q}";
        }
        $n = (int) $prefix_bytes;
        $content_expr = 'SUBSTRING(CAST(' . $content_q . ' AS BINARY), 1, ' . $n . ') AS ' . $content_q;
        $suffix = $this->table_suffix($table);
        switch ($suffix) {
            case 'posts':
                $cols = "{$id_q}, `post_title`, `post_excerpt`, `post_content_filtered`, `guid`, `post_type`, `post_name`, {$content_expr}";
                break;
            case 'options':
                $cols = "{$id_q}, `option_name`, {$content_expr}";
                break;
            case 'comments':
                $cols = "{$id_q}, {$content_expr}, `comment_author_url`, `comment_author`, `comment_author_email`";
                break;
            case 'postmeta':
                $cols = "{$id_q}, `post_id`, `meta_key`, {$content_expr}";
                break;
            case 'usermeta':
                $cols = "{$id_q}, `user_id`, `meta_key`, {$content_expr}";
                break;
            case 'sitemeta':
                $cols = "{$id_q}, `site_id`, `meta_key`, {$content_expr}";
                break;
            case 'termmeta':
                $cols = "{$id_q}, `term_id`, `meta_key`, {$content_expr}";
                break;
            case 'commentmeta':
                $cols = "{$id_q}, `comment_id`, `meta_key`, {$content_expr}";
                break;
            case 'blogmeta':
                $cols = "{$id_q}, `blog_id`, `meta_key`, {$content_expr}";
                break;
            case 'users':
                $cols = "{$id_q}, `user_url`, `user_email`, `display_name`, `user_nicename`";
                break;
            case 'terms':
                $cols = "{$id_q}, `name`, `slug`";
                break;
            case 'term_taxonomy':
                $cols = "{$id_q}, {$content_expr}";
                break;
            case 'blogs':
                $cols = "{$id_q}, `domain`, `path`";
                break;
            case 'signups':
                $cols = "{$id_q}, `user_login`, `user_email`, `domain`, `path`, `activation_key`";
                break;
            default:
                return "SELECT * FROM {$table_q}";
        }
        return "SELECT {$cols} FROM {$table_q}";
    }

    private function mark_row_in_progress($table, $id, $key, $bytes, $mode, $force_flush) {
        if (!$this->context) {
            return;
        }
        $this->context->mergeState([
            'phase' => 'database',
            'last_db_table' => (string) $table,
            'last_db_id' => (int) $id,
            'last_db_key' => $key !== '' ? (string) $key : null,
            'last_db_bytes' => (int) $bytes,
            'last_db_mode' => (string) $mode,
            'db_in_progress_id' => (int) $id,
        ]);
        if ($force_flush && method_exists($this->context, 'flushPending')) {
            $this->context->flushPending();
        }
    }

    private function mark_row_done($table, $id, $key, $bytes, $mode, $force_flush) {
        if (!$this->context) {
            return;
        }
        $this->context->mergeState([
            'phase' => 'database',
            'last_db_table' => (string) $table,
            'last_db_id' => (int) $id,
            'last_db_key' => $key !== '' ? (string) $key : null,
            'last_db_bytes' => (int) $bytes,
            'last_db_mode' => (string) $mode,
            'db_in_progress_id' => null,
        ]);
        if ($force_flush && method_exists($this->context, 'flushPending')) {
            $this->context->flushPending();
        }
    }

    private function clear_in_progress() {
        if (!$this->context) {
            return;
        }
        $this->context->mergeState(['db_in_progress_id' => null]);
    }

    private function log_row_outcome($table, $id, $key, $bytes, $mode, $detail = '') {
        $key = $key !== '' ? $key : '-';
        $extra = $detail !== '' ? " {$detail}" : '';
        $level = ($mode === 'skipped' || $mode === 'truncated') ? 'info' : 'debug';
        // empty is debug: Astra/layout flags are numerous and not security-relevant.
        clean_sweep_log_message(
            "DatabaseScanner: {$mode} {$table} #{$id} key={$key} bytes={$bytes}{$extra}",
            $level
        );
    }
}