<?php
/**
 * Clean Sweep - Scan CleanSweep_Checkpoint
 *
 * CleanSweep_Checkpoint/resume state management for long-running scans.
 * Allows scans to resume from interruption point.
 *
 * @since Phase 1
 */

class CleanSweep_ScanCheckpoint {

    /** @var string CleanSweep_Checkpoint file path */
    private $checkpoint_file;

    /** @var string|null Current scan ID */
    private $scan_id;

    /**
     * Initialize checkpoint manager for a scan.
     *
     * @param string $scan_id Unique scan identifier
     */
    public function __construct($scan_id = null) {
        $this->scan_id = $scan_id ?? $this->generate_scan_id();
        $this->checkpoint_file = CLEAN_SWEEP_PROGRESS_DIR . "checkpoint_{$this->scan_id}.json";
    }

    /**
     * Generate a unique scan ID.
     *
     * @return string
     */
    private function generate_scan_id() {
        return 'scan_' . time() . '_' . bin2hex(random_bytes(4));
    }

/**
     * Get the scan ID.
     *
     * @return string
     */
    public function get_scan_id() {
        return $this->scan_id;
    }

    /**
     * Find the most recent resumable checkpoint for a given profile.
     * Used for manual resume when the user wants to continue from a previous scan.
     * This searches across all scan IDs for the profile.
     *
     * Selection logic (in order of preference):
     * 1. If ui_session_id is provided, prefer checkpoints matching that session
     * 2. Fall back to most recent modified resumable checkpoint for profile (legacy behavior)
     *
     * @param string $profile_id Profile to search checkpoints for
     * @param array $options Optional hints for selection:
     *                       - 'ui_session_id' (string): Prefer checkpoints from this session
     * @return array|null CleanSweep_Checkpoint data or null if none found
     */
    public static function find_latest_resumable_checkpoint($profile_id, $options = []) {
        $checkpoint_dir = CLEAN_SWEEP_PROGRESS_DIR;
        if (!is_dir($checkpoint_dir)) {
            return null;
        }

        $ui_session_id = $options['ui_session_id'] ?? null;

        $checkpoint_files = glob($checkpoint_dir . 'checkpoint_*.json');
        if (empty($checkpoint_files)) {
            return null;
        }

        $session_matched_checkpoint = null;
        $session_matched_mtime = 0;
        $latest_checkpoint = null;
        $latest_mtime = 0;

        foreach ($checkpoint_files as $file) {
            $data = @file_get_contents($file);
            if ($data === false) {
                continue;
            }

            $checkpoint = json_decode($data, true);
            if ($checkpoint === null) {
                continue;
            }

            // Consider checkpoints that are:
            // 1. For the same profile (or profile_id is null/unset for older checkpoints)
            // 2. Have status 'running' (incomplete) OR status 'paused'
            // 3. Not yet completed
            $is_resumable = ($checkpoint['status'] === 'running' || $checkpoint['status'] === 'paused') &&
                           ($checkpoint['phase'] ?? 'initializing') !== 'complete' &&
                           $checkpoint['phase'] !== 'cancelled';

            // For profile matching: exact match OR if checkpoint has no profile_id (legacy)
            $profile_matches = empty($checkpoint['profile_id']) ||
                              $checkpoint['profile_id'] === $profile_id;

            if (!$is_resumable || !$profile_matches) {
                continue;
            }

            $mtime = @filemtime($file);
            if ($mtime === false) {
                continue;
            }

            // Check if this checkpoint matches the requested UI session
            if ($ui_session_id !== null &&
                !empty($checkpoint['ui_session_id']) &&
                $checkpoint['ui_session_id'] === $ui_session_id) {
                // Session match - prefer this over mtime-only selection
                if ($mtime > $session_matched_mtime) {
                    $session_matched_mtime = $mtime;
                    $session_matched_checkpoint = $checkpoint;
                    $session_matched_checkpoint['_checkpoint_file'] = $file;
                }
            }

            // Track most recent by mtime as fallback
            if ($mtime > $latest_mtime) {
                $latest_mtime = $mtime;
                $latest_checkpoint = $checkpoint;
                $latest_checkpoint['_checkpoint_file'] = $file;
            }
        }

        // Prefer session match if found, otherwise fall back to most recent
        if ($session_matched_checkpoint !== null) {
            clean_sweep_log_message("ScanCheckpoint: Found session-matched resumable checkpoint (session: {$ui_session_id}, scan_id: {$session_matched_checkpoint['scan_id']})", 'info');
            return $session_matched_checkpoint;
        }

        if ($latest_checkpoint !== null) {
            clean_sweep_log_message("ScanCheckpoint: No session-matched checkpoint found, using most recent (scan_id: {$latest_checkpoint['scan_id']})", 'info');
        }

        return $latest_checkpoint;
    }

    /**
     * Find the best scan to reattach after a page refresh.
     *
     * Preference:
     *  1. Active scans (running / paused / pending / initializing)
     *  2. Completed scans finished within $completed_ttl_seconds
     *  3. Otherwise null (do not surface cancelled/failed/ancient results)
     *
     * Data lives on disk under CLEAN_SWEEP_PROGRESS_DIR (logs/checkpoint_*.json).
     *
     * @param int $completed_ttl_seconds How long completed results stay "hot" (default 48h)
     * @return array|null CleanSweep_Checkpoint array with scan_id, status, profile_id, timestamps
     */
    public static function find_latest_scan_for_ui($completed_ttl_seconds = 172800) {
        $checkpoint_dir = CLEAN_SWEEP_PROGRESS_DIR;
        if (!is_dir($checkpoint_dir)) {
            return null;
        }

        $checkpoint_files = glob($checkpoint_dir . 'checkpoint_*.json');
        if (empty($checkpoint_files)) {
            return null;
        }

        $now = time();
        $best_active = null;
        $best_active_score = 0;
        $best_completed = null;
        $best_completed_score = 0;

        $active_statuses = ['running', 'paused', 'pending', 'initializing'];

        foreach ($checkpoint_files as $file) {
            $data = @file_get_contents($file);
            if ($data === false) {
                continue;
            }
            $checkpoint = json_decode($data, true);
            if (!is_array($checkpoint)) {
                continue;
            }

            // Derive scan_id from filename if missing
            if (empty($checkpoint['scan_id'])) {
                if (preg_match('/checkpoint_(.+)\.json$/', basename($file), $m)) {
                    $checkpoint['scan_id'] = $m[1];
                } else {
                    continue;
                }
            }

            $status = (string)($checkpoint['status'] ?? '');
            $started = (int)($checkpoint['started_at'] ?? 0);
            $finished = (int)($checkpoint['finished_at'] ?? 0);
            $updated = (int)($checkpoint['last_updated'] ?? 0);
            $mtime = @filemtime($file) ?: 0;
            $score = max($started, $finished, $updated, $mtime);

            if (in_array($status, $active_statuses, true)) {
                if ($score >= $best_active_score) {
                    $best_active_score = $score;
                    $best_active = $checkpoint;
                    $best_active['_checkpoint_file'] = $file;
                }
                continue;
            }

            if ($status === 'completed') {
                $age_base = $finished > 0 ? $finished : $score;
                if (($now - $age_base) > (int)$completed_ttl_seconds) {
                    continue; // too old
                }
                if ($score >= $best_completed_score) {
                    $best_completed_score = $score;
                    $best_completed = $checkpoint;
                    $best_completed['_checkpoint_file'] = $file;
                }
            }
            // cancelled / failed / unknown — skip for UI restore
        }

        return $best_active ?? $best_completed;
    }

    /**
     * Clear ALL checkpoints for a given profile.
     * Used when starting a completely fresh scan.
     *
     * @param string $profile_id Profile to clear checkpoints for
     * @return int Number of checkpoints cleared
     */
    public static function clear_checkpoints_for_profile($profile_id) {
        $checkpoint_dir = CLEAN_SWEEP_PROGRESS_DIR;
        if (!is_dir($checkpoint_dir)) {
            return 0;
        }

        $checkpoint_files = glob($checkpoint_dir . 'checkpoint_*.json');
        $cleared = 0;

        foreach ($checkpoint_files as $file) {
            $data = @file_get_contents($file);
            if ($data === false) {
                continue;
            }

            $checkpoint = json_decode($data, true);
            if ($checkpoint === null) {
                continue;
            }

            // Clear checkpoints that match the profile
            if (empty($checkpoint['profile_id']) || $checkpoint['profile_id'] === $profile_id) {
                @unlink($file);
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * Load checkpoint state.
     *
     * Retries briefly when the file exists but is empty/invalid JSON so a
     * concurrent writer (non-atomic mid-write read) does not look like
     * "scan not found".
     *
     * @return array|null State data or null if no checkpoint exists / unreadable after retries
     */
    public function load() {
        if (!file_exists($this->checkpoint_file)) {
            return null;
        }

        // Mid-write races: empty or partial JSON for a few ms.
        $attempts = 4;
        for ($i = 0; $i < $attempts; $i++) {
            $data = @file_get_contents($this->checkpoint_file);
            if ($data !== false && $data !== '') {
                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            // File gone between exists() and read — treat as missing.
            if (!file_exists($this->checkpoint_file)) {
                return null;
            }
            if ($i + 1 < $attempts) {
                usleep(15000); // 15ms
            }
        }

        return null;
    }

    /**
     * Save checkpoint state atomically (temp file + rename).
     *
     * Direct file_put_contents truncates first, so concurrent status polls
     * could read empty/partial JSON and report not_found.
     *
     * @param array $state State data to save
     * @return bool Success
     */
    public function save($state) {
        $state['last_updated'] = time();
        $json = json_encode($state, JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        $dir = dirname($this->checkpoint_file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // getmypid() is often disabled on shared hosts — do not require it.
        $uniq = bin2hex(random_bytes(8));
        if (function_exists('getmypid')) {
            $pid = @getmypid();
            if ($pid !== false && $pid !== null) {
                $uniq = $pid . '.' . $uniq;
            }
        }
        $tmp = $this->checkpoint_file . '.tmp.' . $uniq;
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        // Atomic replace on the same filesystem.
        if (@rename($tmp, $this->checkpoint_file)) {
            return true;
        }

        // Fallback when rename cannot overwrite (some Windows configs).
        @unlink($this->checkpoint_file);
        $ok = @rename($tmp, $this->checkpoint_file);
        if (!$ok) {
            @unlink($tmp);
        }
        return $ok;
    }

    /**
     * Check if a checkpoint exists.
     *
     * @return bool
     */
    public function exists() {
        return file_exists($this->checkpoint_file);
    }

    /**
     * Clear checkpoint (on completion or cancellation).
     *
     * @return bool
     */
    public function clear() {
        if (!file_exists($this->checkpoint_file)) {
            return true;
        }
        return @unlink($this->checkpoint_file);
    }

    /**
     * Get checkpoint file path.
     *
     * @return string
     */
    public function get_file_path() {
        return $this->checkpoint_file;
    }

    /**
     * Get default checkpoint state structure.
     *
     * @return array
     */
    public static function get_default_state() {
        return [
            'scan_id' => null,
            'profile_id' => 'standard',
            // Phase 3: UI session correlation for improved checkpoint selection
            'ui_session_id' => null,           // Browser session identifier (UUID)
            'last_user_action_at' => null,     // Unix timestamp of last user action (start/resume/loopback)
            'last_user_action_type' => null,   // 'start', 'resume', 'loopback'
            // Status and phase
            'status' => 'initializing', // initializing, scheduled, running, paused, completed, cancelled, error
            'phase' => 'initializing',  // files, database, analysis, complete
            'started_at' => null,
            'last_updated' => null,
            'finished_at' => null,
            'finish_reason' => null,    // completed, interrupted, cancelled, error, time_limit, memory_limit
            // Advanced pause/resume fields (Phase 2.1)
            'pause_reason' => null,     // string: 'time_limit', 'memory_limit', 'user_cancelled', 'error', 'manual'
            'resume_instruction' => null, // string: JSON instruction for how to resume (e.g., 'continue from last_file_path')
            'pause_point' => [          // Rich structure for precise resume
                'phase' => null,         // Phase when paused
                'item_type' => null,     // 'file', 'post', 'comment', 'postmeta', 'user', 'term'
                'item_id' => null,       // Last processed item ID (cursor)
                'item_key' => null,      // Last processed item key/offset
                'item_value' => null,    // Last processed item value (hash of last file for integrity)
                'batch_index' => 0,      // Which batch within the phase
                'batch_offset' => 0,    // Offset within the current batch
                'checkpoint_interval' => 0, // CleanSweep_Checkpoint interval at time of pause
                'memory_usage_mb' => 0, // Memory usage at pause point
                'elapsed_time_sec' => 0, // Time elapsed at pause
            ],
            // File scanning state
            'file_state' => [
                'total_files' => 0,
                'files_scanned' => 0,
                'last_file_path' => null,
                'last_file_index' => 0,
                'last_file_hash' => null,
                'current_directory' => null, // For deep directory resume
                'directory_stack' => [],      // Stack of directories for deep resume
            ],
            // Database scanning state
            'db_state' => [
                'posts_last_id' => 0,
                'posts_offset' => 0,
                'comments_last_id' => 0,
                'comments_offset' => 0,
                'postmeta_last_id' => 0,
                'postmeta_offset' => 0,
                'users_last_id' => 0,
                'users_offset' => 0,
                'terms_last_id' => 0,
                'terms_offset' => 0,
            ],
            // Counters
            'threats_found' => 0,
            'files_with_threats' => 0,
            'db_rows_scanned' => 0,
            // Performance metrics
            'memory_peak_mb' => 0,
            'throughput_files_per_sec' => 0,
            'throughput_db_rows_per_sec' => 0,
            // Error state
            'errors' => [],
            'warnings' => [],
        ];
    }

    /**
     * Update partial state (merge with existing).
     *
     * @param array $partial State fragment to merge
     * @return bool Success
     */
    public function merge($partial) {
        $current = $this->load();
        if ($current === null) {
            $current = self::get_default_state();
        }

        $merged = array_replace_recursive($current, $partial);
        return $this->save($merged);
    }

    /**
     * Record an error during scanning.
     *
     * @param string $context Where error occurred
     * @param string $message Error message
     * @param array $context_data Additional context
     * @return bool Success
     */
    public function record_error($context, $message, $context_data = []) {
        $current = $this->load();
        if ($current === null) {
            $current = self::get_default_state();
        }

        if (!isset($current['errors'])) {
            $current['errors'] = [];
        }

        $current['errors'][] = [
            'context' => $context,
            'message' => $message,
            'context_data' => $context_data,
            'time' => time(),
        ];

        return $this->save($current);
    }

    /**
     * Record a warning during scanning.
     *
     * @param string $context Where warning occurred
     * @param string $message Warning message
     * @return bool Success
     */
    public function record_warning($context, $message) {
        $current = $this->load();
        if ($current === null) {
            $current = self::get_default_state();
        }

        if (!isset($current['warnings'])) {
            $current['warnings'] = [];
        }

        $current['warnings'][] = [
            'context' => $context,
            'message' => $message,
            'time' => time(),
        ];

        return $this->save($current);
    }

    /**
     * Calculate scan progress percentage.
     *
     * @return float Progress 0-100
     */
    public function get_progress_percent() {
        $state = $this->load();
        if ($state === null) {
            return 0;
        }

        $phase = $state['phase'] ?? 'initializing';

        switch ($phase) {
            case 'initializing':
                return 0;
            case 'files':
                $total = $state['file_state']['total_files'] ?? 0;
                $scanned = $state['file_state']['files_scanned'] ?? 0;
                if ($total > 0) {
                    return round(($scanned / $total) * 40); // Files = 40% of total
                }
                // No total known - use estimated progress if available
                return $state['progress_percent'] ?? 0;
            case 'database':
                $file_progress = 40;
                // Estimate database progress (60% of total)
                $db_state = $state['db_state'] ?? [];
                $estimated_db = 60; // Simplified - could track actual scanned vs expected
                return $file_progress + (int)($estimated_db * 0.6);
            case 'analysis':
                return 90;
            case 'complete':
                return 100;
            default:
                return 0;
        }
    }

    /**
     * Check if scan was interrupted (can resume).
     *
     * @return bool
     */
    public function is_interrupted() {
        $state = $this->load();
        if ($state === null) {
            return false;
        }

        return $state['status'] === 'running' &&
               $state['phase'] !== 'complete' &&
               $state['phase'] !== 'cancelled';
    }

    /**
     * Check if scan was cancelled by user.
     *
     * @return bool
     */
    public function is_cancelled() {
        $state = $this->load();
        if ($state === null) {
            return false;
        }

        return $state['status'] === 'cancelled' ||
               ($state['finish_reason'] ?? '') === 'cancelled';
    }

    /**
     * Mark scan as completed.
     *
     * Contract for $final_stats:
     *   - Use this for COMPLETION METADATA only (e.g. 'reconciled', 'error').
     *   - DO NOT pass global cumulative counters here (files_scanned,
     *     db_rows_scanned, threats_found). Those are owned by the
     *     ScanProgressSink and must never be re-written through
     *     this method. Passing them is silently ignored below to
     *     keep the contract enforced; callers that need the final
     *     values should read them from the checkpoint via
     *     ScanProgressSink::cumulative_from_checkpoint() instead.
     *
     * @param string $reason Completion reason
     * @param array $final_stats Final statistics (metadata only)
     * @return bool Success
     */
    public function mark_completed($reason = 'completed', $final_stats = []) {
        $current = $this->load();
        if ($current === null) {
            $current = self::get_default_state();
        }

        $current['status'] = 'completed';
        $current['finish_reason'] = $reason;
        $current['phase'] = 'complete';
        $current['finished_at'] = time();
        $current['last_updated'] = time();

        // Clear pause-related state and loopback token on completion to prevent stale resume data
        unset($current['pause_reason']);
        unset($current['pause_point']);
        unset($current['resume_instruction']);
        unset($current['loopback_token']);

        if (!empty($final_stats)) {
            // Defensive: drop any attempt to overwrite the global
            // cumulative counters here. The sink is the single owner
            // of these and any value passed in $final_stats is either
            // stale, wrong, or a workaround. Logging it makes the
            // foot-gun loud instead of silent.
            $protected_globals = [
                'file_state' => ['files_scanned', 'total_files'],
                'db_rows_scanned',
                'threats_found',
            ];
            foreach ($protected_globals as $key => $val) {
                if (is_array($val)) {
                    // nested path: file_state.files_scanned etc.
                    if (isset($final_stats[$key]) && is_array($final_stats[$key])) {
                        foreach ($val as $nested) {
                            if (array_key_exists($nested, $final_stats[$key])) {
                                clean_sweep_log_message(
                                    "mark_completed: ignoring attempt to overwrite $key.$nested; " .
                                    'that field is owned by ScanProgressSink',
                                    'warning'
                                );
                                unset($final_stats[$key][$nested]);
                            }
                        }
                    }
                } else {
                    if (array_key_exists($val, $final_stats)) {
                        clean_sweep_log_message(
                            "mark_completed: ignoring attempt to overwrite $val; " .
                            'that field is owned by ScanProgressSink',
                            'warning'
                        );
                        unset($final_stats[$val]);
                    }
                }
            }

            if (!empty($final_stats)) {
                $current = array_merge($current, $final_stats);
            }
        }

        // Calculate duration
        if (isset($current['started_at'])) {
            $current['duration_seconds'] = $current['finished_at'] - $current['started_at'];
        }

        return $this->save($current);
    }

    /**
     * Mark scan as started.
     *
     * @param string $profile_id Profile being used
     * @return bool Success
     */
    public function mark_started($profile_id = 'standard') {
        $current = $this->load();
        if ($current === null) {
            $current = self::get_default_state();
        }

        $current['status'] = 'running';
        $current['profile_id'] = $profile_id;
        $current['started_at'] = time();
        $current['last_updated'] = time();

        return $this->save($current);
    }

    /**
     * Mark scan as paused (user cancelled or system stopped).
     *
     * @param string $reason Pause reason
     * @return bool Success
     */
    public function mark_paused($reason = 'user_cancelled') {
        $current = $this->load();
        if ($current === null) {
            return false;
        }

        $current['status'] = 'paused';
        $current['finish_reason'] = $reason;
        $current['last_updated'] = time();

        return $this->save($current);
    }

    /**
     * Mark scan as paused with rich pause point information.
     * This provides detailed resume instructions for the next scan.
     *
     * @param string $pause_reason Reason for pause: 'time_limit', 'memory_limit', 'user_cancelled', 'error', 'manual'
     * @param array $pause_point Detailed pause point data
     * @return bool Success
     */
    public function mark_paused_with_resume($pause_reason, $pause_point = []) {
        $current = $this->load();
        if ($current === null) {
            return false;
        }

        $current['status'] = 'paused';
        $current['pause_reason'] = $pause_reason;
        $current['finish_reason'] = 'paused_' . $pause_reason;
        $current['last_updated'] = time();

        // Merge pause point with defaults
        $default_pause_point = [
            'phase' => $current['phase'] ?? null,
            'item_type' => null,
            'item_id' => null,
            'item_key' => null,
            'item_value' => null,
            'batch_index' => 0,
            'batch_offset' => 0,
            'checkpoint_interval' => 0,
            'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
            'elapsed_time_sec' => time() - ($current['started_at'] ?? time()),
        ];
        $current['pause_point'] = array_merge($default_pause_point, $pause_point);

        // Build resume instruction
        $current['resume_instruction'] = $this->build_resume_instruction($current);

        return $this->save($current);
    }

    /**
     * Build a human-readable and machine-parseable resume instruction.
     *
     * @param array $state Current state
     * @return string JSON instruction
     */
    private function build_resume_instruction($state) {
        $instruction = [
            'action' => 'resume',
            'phase' => $state['pause_point']['phase'] ?? $state['phase'] ?? 'files',
            'from' => [
                'type' => $state['pause_point']['item_type'] ?? 'file',
                'id' => $state['pause_point']['item_id'] ?? $state['file_state']['last_file_path'] ?? null,
                'offset' => $state['pause_point']['batch_offset'] ?? 0,
            ],
            'using_profile' => $state['profile_id'] ?? 'standard',
            'checkpoint_interval' => $state['pause_point']['checkpoint_interval'] ?? 50,
        ];
        return json_encode($instruction);
    }

    /**
     * Get resume instruction for continuing a paused scan.
     *
     * @return array|null Parsed resume instruction or null
     */
    public function get_resume_instruction() {
        $state = $this->load();
        if ($state === null || $state['status'] !== 'paused') {
            return null;
        }

        $instruction = $state['resume_instruction'] ?? null;
        if ($instruction === null) {
            return null;
        }

        return json_decode($instruction, true);
    }

    /**
     * Get the pause point data for resuming.
     *
     * @return array|null Pause point data or null
     */
    public function get_pause_point() {
        $state = $this->load();
        if ($state === null) {
            return null;
        }

        return $state['pause_point'] ?? null;
    }

    /**
     * Clear pause state (pause_point and pause_reason) from checkpoint.
     * Used after successful completion or when clearing stuck scan state.
     *
     * @return bool Success
     */
    public function clear_pause_state() {
        $current = $this->load();
        if ($current === null) {
            return false;
        }

        unset($current['pause_reason']);
        unset($current['pause_point']);
        unset($current['resume_instruction']);

        return $this->save($current);
    }

    /**
     * Save a checkpoint with automatic pause point tracking.
     * Call this periodically during scanning to enable resume.
     *
     * @param array $context Current scanning context
     * @return bool Success
     */
    public function save_with_pause_tracking($context = []) {
        $current = $this->load();
        if ($current === null) {
            return false;
        }

        // Update pause point with current context
        if (!empty($context)) {
            if (!isset($current['pause_point'])) {
                $current['pause_point'] = [];
            }
            $current['pause_point'] = array_merge($current['pause_point'], $context);
        }

        // Update memory usage
        $current['pause_point']['memory_usage_mb'] = round(memory_get_usage(true) / 1048576, 1);
        $current['pause_point']['elapsed_time_sec'] = time() - ($current['started_at'] ?? time());

        $current['last_updated'] = time();
        return $this->save($current);
    }

    /**
     * Update phase progress.
     *
     * @param string $phase New phase
     * @param array $phase_data Phase-specific data
     * @return bool Success
     */
    public function update_phase($phase, $phase_data = []) {
        $current = $this->load();
        if ($current === null) {
            return false;
        }

        $current['phase'] = $phase;
        $current['last_updated'] = time();

        if (!empty($phase_data)) {
            $current = array_merge($current, $phase_data);
        }

        return $this->save($current);
    }
}