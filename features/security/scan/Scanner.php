<?php
/**
 * Clean Sweep - CleanSweep_Scanner (the single orchestrator)
 *
 * The ONLY entry point for starting, resuming, querying, and cancelling
 * malware scans. Replaces the 5-layer call chain that used to exist
 * (api/malware.php -> Clean_Sweep_Malware_Scanner -> HighLevelScan ->
 * ScanExecutionManager -> EpisodeRunner -> scanners).
 *
 * Public surface:
 *   - start()   - create a new scan
 *   - resume()  - continue a paused scan (or drain an existing one)
 *   - status()  - read-only, returns a stable ScanStatus
 *   - cancel()  - mark cancelled, drain will exit at next safe point
 *   - drain()   - do the actual work (claim units, run workers, schedule kicks)
 *
 * All state-mutating operations go through one place. No more
 * `is_loopback_kick` / `force_resume` / `queue_driven_continuation`
 * flags - the same drain() method runs for every trigger (initial
 * click, scheduled kick, manual resume, cron).
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_Scanner {

    /** @var CleanSweep_FileBasedScanWorkQueue */
    private $queue;

    /** @var CleanSweep_WorkerRegistry */
    private $workers;

    /** @var CleanSweep_Scheduler */
    private $scheduler;

    /** @var CleanSweep_Checkpoint */
    private $checkpoint;

    /** @var CleanSweep_ThreatStore */
    private $threats;

    /** @var CleanSweep_HostDetector */
    private $host;

    /** @var CleanSweep_ScanProfile */
    private $profile;

    public function __construct(
        CleanSweep_FileBasedScanWorkQueue $queue,
        CleanSweep_WorkerRegistry $workers,
        CleanSweep_Scheduler $scheduler,
        CleanSweep_Checkpoint $checkpoint,
        CleanSweep_ThreatStore $threats,
        CleanSweep_HostDetector $host,
        CleanSweep_ScanProfile $profile
    ) {
        $this->queue = $queue;
        $this->workers = $workers;
        $this->scheduler = $scheduler;
        $this->checkpoint = $checkpoint;
        $this->threats = $threats;
        $this->host = $host;
        $this->profile = $profile;
    }

    /**
     * Convenience constructor: builds all collaborators from defaults.
     * Used by api/malware.php and the CLI driver.
     */
    public static function create(string $scan_id, string $profile_id = 'standard'): self {
        require_once CLEAN_SWEEP_ROOT . 'config.php';
        require_once CLEAN_SWEEP_ROOT . 'utils.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/profiles/ScanProfile.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/ScanCheckpoint.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/CpuGovernor.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/FileBasedScanWorkQueue.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/ScanWorkUnit.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/ScanWorkQueueInterface.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/ScanWorkUnit.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/signatures.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/ScanState.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/Checkpoint.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/HostDetector.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/Scheduler.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/ThreatStore.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/Worker.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/WorkerRegistry.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/WorkerContextImpl.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/DefaultWorkerRegistry.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/SitePaths.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/workers/RootConfigWorker.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/workers/CoreChecksumWorker.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/workers/PackageChecksumWorker.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/workers/FileDiscoveryWorker.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/workers/FileBatchWorker.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/DbScanPlanner.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/workers/DbSegmentWorker.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/workers/DbSiteDiscoveryWorker.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/workers/IntegrityWorker.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/workers/CensusWorker.php';
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/workers/FinalizeWorker.php';

        $host = new CleanSweep_HostDetector();
        if ($scan_id !== '') {
            $prior = (new CleanSweep_Checkpoint($scan_id))->load();
            if ($prior && !empty($prior->profile_id)) {
                $profile_id = $prior->profile_id;
            }
        }
        $profile = CleanSweep_ScanProfile::create($profile_id);
        // Needed for kick/resume paths that call create($scan_id) then drain()
        // without start() — start() rebuilds the profile and adapts again.
        $profile->apply_host_adaptation($host);
        $queue = new CleanSweep_FileBasedScanWorkQueue();
        $workers = CleanSweep_DefaultWorkerRegistry::build();
        $scheduler = new CleanSweep_Scheduler($host);
        $checkpoint = new CleanSweep_Checkpoint($scan_id);
        $threats = new CleanSweep_ThreatStore($scan_id);

        return new self($queue, $workers, $scheduler, $checkpoint, $threats, $host, $profile);
    }

    // ============================================================
    // PUBLIC API - one method, one job
    // ============================================================

    /**
     * Start a new scan. Returns immediately with a scan_id.
     * The actual work happens in drain(), which the caller invokes
     * either in the same request (when fastcgi_finish_request is
     * available or for CLI), or via a kick.
     *
     * @param string $profile_id
     * @param array $config
     * @return array{scan_id: string, status: string}
     */
    public function start(string $profile_id, array $config = []): array {
        $scan_id = $this->newScanId();
        $this->profile = CleanSweep_ScanProfile::create($profile_id, $config);

        // Drop leftover scan-kick cron from prior runs (single-event safety net only).
        self::clearAllScanKickCrons();

        // Phase 2: adapt profile for restricted hosts (smaller scope, conservative throttle).
        $this->profile->apply_host_adaptation($this->host);

        // Re-bind collaborators that are bound to a scan_id at construction
        // time (CleanSweep_Checkpoint, CleanSweep_ThreatStore). The factory built them with the
        // empty scan_id passed to CleanSweep_Scanner::create(''); now that we have the
        // real id, they must point at it or the status poll will look up
        // the wrong file and report 'not_found'.
        $this->checkpoint = new CleanSweep_Checkpoint($scan_id);
        $this->threats = new CleanSweep_ThreatStore($scan_id);

        // Deep scope normalization (full / files / database / paths). Throws CleanSweep_ScanConfigException.
        $config = $this->prepareScopeConfig($config);

        // Phase 2 §5: integrity baseline is only meaningful after Core Reinstall.
        $baseline_meta = $this->resolveIntegrityBaselineMeta();

        // Initialize the work plan (integrity unit only if baseline exists)
        $this->buildInitialWorkPlan($scan_id, $config, $baseline_meta['available']);

        $want_files = !empty($config['want_files']);
        $want_db = !empty($config['want_db']);
        $initial_phase = (!$want_files && $want_db) ? 'database' : 'files';

        // Initialize checkpoint
        $state = new CleanSweep_ScanState();
        $state->scan_id = $scan_id;
        $state->profile_id = $profile_id;
        $state->status = 'running';
        $state->phase = $initial_phase;
        $state->started_at = time();
        $state->has_integrity_baseline = $baseline_meta['available'];
        $state->integrity_baseline = $baseline_meta;
        $state->options = array_merge($config, [
            'restricted_host' => $this->host->isSharedHosting(),
            'environment_advisory' => $this->profile->get_environment_advisory(),
            'differential' => $this->profile->get_enable_differential_scan(),
            'cpu_preset' => $this->profile->get_cpu_governor_preset(),
        ]);
        $fresh_scan = !empty($config['fresh_scan']);
        if ($fresh_scan && $want_files) {
            require_once dirname(__DIR__) . '/DifferentialScanner.php';
            $diff = new CleanSweep_DifferentialScanner(null, false);
            $diff->set_profile_id($this->profile->get_profile_id());
            $cleared = $diff->clear_hashes();
            clean_sweep_log_message(
                "CleanSweep_Scanner: fresh scan cleared file-hash cache for {$profile_id}" .
                ($cleared ? '' : ' (already empty)'),
                'info'
            );
        }

        $this->checkpoint->save($state);

        // Seed hash-skipped file hits from prior completed/cancelled/failed
        // scans so live preview is not empty while this run walks new files.
        if ($want_files && $this->profile->get_enable_differential_scan() && !$fresh_scan) {
            require_once __DIR__ . '/FileThreatCarry.php';
            $seed = CleanSweep_FileThreatCarry::apply($state, false);
            if (!empty($seed['carried'])) {
                $state->threats_found = (int) $state->threats_found + (int) $seed['carried'];
                $state->options['file_carry'] = [
                    'carried' => (int) $seed['carried'],
                    'from_scan_id' => $seed['from_scan_id'] ?? null,
                    'from_profile' => $seed['from_profile'] ?? null,
                    'seeded_at_start' => true,
                ];
                $this->checkpoint->save($state);
                clean_sweep_log_message(
                    "CleanSweep_Scanner: seeded {$seed['carried']} prior file hit(s) into {$scan_id}" .
                    (!empty($seed['from_scan_id']) ? " (from {$seed['from_scan_id']})" : ''),
                    'info'
                );
            }
        }

        $scope = (string)($config['scan_scope'] ?? 'full');
        $coerced = !empty($config['scope_coerced_from_folder']);
        clean_sweep_log_message(
            "CleanSweep_Scanner: started scan {$scan_id} (profile={$profile_id}, scope={$scope}" .
            ($coerced ? ', coerced_from_folder=1' : '') .
            ($fresh_scan ? ', fresh_scan=1' : '') .
            ', seeds=' . count($config['resolved_seeds'] ?? []) .
            ', want_db=' . ($want_db ? 'yes' : 'no') .
            ', restricted=' . ($this->host->isSharedHosting() ? 'yes' : 'no') .
            ', baseline=' . ($baseline_meta['available'] ? 'yes' : 'no') . ')',
            'info'
        );

        if (function_exists('clean_sweep_watch_note_operation')) {
            // Scan does not write site files; empty prefixes keep this as journal
            // context only so Live Watch does not treat scan as an expected write.
            clean_sweep_watch_note_operation('scan', [], 1800, [
                'detail' => $profile_id,
            ]);
        }

        return [
            'scan_id' => $scan_id,
            'status' => 'running',
            'profile_id' => $profile_id,
            'has_integrity_baseline' => $baseline_meta['available'],
            'integrity_note' => $baseline_meta['note'],
            'environment_advisory' => $this->profile->get_environment_advisory(),
            'restricted_host' => $this->host->isSharedHosting(),
            'scan_scope' => $scope,
            'folder_path' => $config['folder_path'] ?? null,
            'folder_paths' => $config['folder_paths'] ?? null,
            'folder_path_display' => $config['folder_path_display'] ?? null,
            'include_db' => !empty($config['include_db']),
            'want_db' => $want_db,
            'want_files' => $want_files,
        ];
    }

    /**
     * Resume (or continue) a scan. Identical to drain() but exists
     * for API symmetry.
     */
    public function resume(string $scan_id): array {
        return $this->drain($scan_id);
    }

    /**
     * Status for the UI poller. Reads checkpoint + queue stats.
     * likely_source is this scan's finalize result only — never copied from
     * VisitStore (that is leftover from another visit / snapshot compare).
     */
    public function status(string $scan_id): array {
        $ckpt = new CleanSweep_Checkpoint($scan_id);
        $state = $ckpt->load();
        $queue = new CleanSweep_FileBasedScanWorkQueue();

        if ($state === null) {
            // File exists but could not be decoded (mid-write race after retries):
            // do NOT report not_found — that stops the UI poller and hides progress.
            if ($ckpt->exists()) {
                return [
                    'scan_id' => $scan_id,
                    'status' => 'running',
                    'phase' => 'checkpoint_retry',
                    'progress' => -1, // client sentinel: keep last HWM / counters
                    'reason' => 'checkpoint_read_busy',
                    'pause_reason' => null,
                    'counters' => [],
                    'queue' => [],
                ];
            }
            return ['scan_id' => $scan_id, 'status' => 'not_found'];
        }

        $qstats = $queue->getStats($scan_id);
        $threats = new CleanSweep_ThreatStore($scan_id);

        $files = $state->files_scanned;
        $db_rows = (int)($state->db_rows_scanned ?? 0);
        $items_processed = (int)($qstats['items_processed'] ?? 0);
        $cumulative = max($files, $items_processed, $db_rows);

        // Prefer queue-based progress once there is enough work. Do not
        // max() it with the files-phase 0–75 heuristic: that hits 75% as soon
        // as the first small file estimate is "done" while discovery is still
        // enqueueing hundreds of units.
        $queue_progress = $this->computeQueueProgress($qstats, $state->phase);
        if ($queue_progress !== null) {
            $progress = $queue_progress;
        } else {
            $progress = $this->computeProgressRaw(
                $state->phase,
                $cumulative,
                $state->total_files_estimate,
                $db_rows
            );
        }

        $baseline = is_array($state->integrity_baseline) ? $state->integrity_baseline : null;
        $has_baseline = (bool)$state->has_integrity_baseline;
        $integrity_note = is_array($baseline) && !empty($baseline['note'])
            ? (string)$baseline['note']
            : ($has_baseline
                ? null
                : 'No trusted baseline is available (run Core Reinstall first for reinfection detection).');

        $options = is_array($state->options) ? $state->options : [];
        $likely = $options['likely_source'] ?? null;
        if (!is_array($likely) || (empty($likely['reinfection']) && empty($likely['core_changed']))) {
            $likely = null;
        }

        return [
            'scan_id' => $scan_id,
            'status' => $state->status,
            'phase' => $state->phase,
            'progress' => $progress,
            'progress_source' => $queue_progress !== null ? 'queue' : 'phase',
            'profile_id' => $state->profile_id,
            'pause_reason' => $state->pause_reason,
            'counters' => [
                'files_scanned' => $state->files_scanned,
                'files_visited' => $state->files_visited,
                'files_skipped_unchanged' => $state->files_skipped_unchanged,
                'db_rows_scanned' => $state->db_rows_scanned,
                'threats_found' => $state->threats_found,
                'integrity_violations' => $state->integrity_violations,
                // malware ≈ total threats minus integrity (integrity may be double-counted if both counters used)
                'malware_threats' => max(0, (int)$state->threats_found - (int)$state->integrity_violations),
            ],
            'file_carry' => is_array($options['file_carry'] ?? null) ? $options['file_carry'] : null,
            // Phase 2: separate integrity metadata from malware counters
            'has_integrity_baseline' => $has_baseline,
            'integrity_note' => $integrity_note,
            'integrity_violations' => $state->integrity_violations,
            'checksum_note' => $options['checksum_note'] ?? null,
            'checksum_checked' => (int)($options['checksum_checked'] ?? 0),
            'checksum_findings' => (int)($options['checksum_findings'] ?? 0),
            'checksum_version' => $options['checksum_version'] ?? null,
            'package_checksum_note' => $options['package_checksum_note'] ?? null,
            'last_file_path' => $state->last_file_path,
            'last_db_table' => $state->last_db_table,
            'last_db_id' => $state->last_db_id,
            'environment_advisory' => $options['environment_advisory'] ?? null,
            'restricted_host' => !empty($options['restricted_host']),
            'scan_scope' => $options['scan_scope'] ?? 'full',
            'fresh_scan' => !empty($options['fresh_scan']),
            'folder_path' => $options['folder_path'] ?? null,
            'folder_paths' => $options['folder_paths'] ?? null,
            'folder_path_display' => $options['folder_path_display'] ?? null,
            'include_db' => !empty($options['include_db']),
            'want_db' => array_key_exists('want_db', $options)
                ? !empty($options['want_db'])
                : in_array($options['scan_scope'] ?? 'full', ['full', 'database'], true)
                    || (($options['scan_scope'] ?? '') === 'paths' && !empty($options['include_db'])),
            'want_files' => array_key_exists('want_files', $options)
                ? !empty($options['want_files'])
                : in_array($options['scan_scope'] ?? 'full', ['full', 'files', 'paths'], true),
            'likely_source' => is_array($likely) ? $likely : null,
            'queue' => $qstats,
            'started_at' => $state->started_at,
            'finished_at' => $state->finished_at,
            'last_updated' => $state->last_updated,
            'last_drain_activity_at' => $state->last_drain_activity_at,
        ];
    }

    /**
     * Mark a scan as cancelled. The next drain() will see the cancelled
     * status via CleanSweep_WorkerContext::shouldStop() and exit cleanly.
     */
    public function cancel(string $scan_id): bool {
        $ckpt = new CleanSweep_Checkpoint($scan_id);
        $ckpt->merge(['status' => 'cancelled', 'finished_at' => time()]);
        self::clearScanKickCron($scan_id);
        clean_sweep_log_message("CleanSweep_Scanner: cancelled scan {$scan_id}", 'info');
        return true;
    }

    /**
     * THE drain loop. The only place that does work. All entry points
     * (start, resume, kick, cron) eventually call this.
     *
     * @param string $scan_id
     * @return array DrainResult envelope
     */
    public function drain(string $scan_id): array {
        $ckpt = new CleanSweep_Checkpoint($scan_id);
        $state = $ckpt->load();
        if ($state === null) {
            self::clearScanKickCron($scan_id);
            return ['status' => 'not_found', 'reason' => 'no_checkpoint'];
        }
        if ($state->isTerminal()) {
            // Scan already finished; drop any leftover single-event kick for this id.
            self::clearScanKickCron($scan_id);
            return ['status' => $state->status, 'reason' => 'scan_terminal'];
        }

        // Only one drain per scan_id at a time. Overlapping resume + internal_kick
        // + status-nudge drains pin every FPM worker; status then 504s and the
        // UI thinks the scan is dead.
        $lock = $this->acquireDrainLock($scan_id);
        if ($lock === null) {
            clean_sweep_log_message("CleanSweep_Scanner: drain busy for {$scan_id} — another worker holds the lock", 'info');
            return [
                'status' => 'busy',
                'reason' => 'drain_in_progress',
                'processed' => 0,
                'completed' => 0,
                'failed' => 0,
                'elapsed_seconds' => 0,
            ];
        }

        try {
            return $this->drainLocked($scan_id, $ckpt, $state);
        } finally {
            $this->releaseDrainLock($lock);
        }
    }

    /**
     * @param resource|array $lock
     */
    private function releaseDrainLock($lock): void {
        if (is_array($lock) && !empty($lock['fp'])) {
            @flock($lock['fp'], LOCK_UN);
            @fclose($lock['fp']);
        }
    }

    /**
     * Non-blocking exclusive lock for a scan drain.
     * @return array{fp: resource, path: string}|null
     */
    private function acquireDrainLock(string $scan_id): ?array {
        $dir = defined('CLEAN_SWEEP_LOGS_DIR') ? CLEAN_SWEEP_LOGS_DIR : (CLEAN_SWEEP_ROOT . 'logs/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = rtrim($dir, '/') . '/.drain_lock_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $scan_id);
        $fp = @fopen($path, 'c+');
        if (!$fp) {
            return null;
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            return null;
        }
        return ['fp' => $fp, 'path' => $path];
    }

    private function drainLocked(string $scan_id, CleanSweep_Checkpoint $ckpt, CleanSweep_ScanState $state): array {
        $started_at = $state->started_at ?? time();
        $time_budget = $this->host->recommendedTimeBudget();
        $max_units = $this->host->recommendedMaxUnitsPerDrain();

        // Do NOT set_time_limit(0) — that lets usleep-heavy drains run past the
        // gateway timeout while holding an FPM worker. Cap near our slice budget.
        @set_time_limit(max(20, $time_budget + 15));
        @ignore_user_abort(true);

        $drain_start = time();
        $processed = 0;
        $completed = 0;
        $failed = 0;
        $reason = 'queue_empty';

        // Record when this drain began. The storm guard uses this (not
        // last_drain_activity_at, which is updated every iteration) to
        // decide whether the drain ran long enough to warrant a follow-on kick.
        $ckpt->merge(['drain_started_at' => $drain_start, 'status' => 'running', 'pause_reason' => null]);

        // Recover any stale leases once at the start of this drain window.
        // This is O(k) where k = in-flight units from crashed/abandoned requests.
        // Running this per-claim (inside the loop) made the scan O(n*k) on
        // filesystems with slow glob()/readdir(), which was the dominant cost
        // for large queues on shared hosts. Also gate to at most once per 30s
        // and skip entirely when in_flight/ is empty.
        $this->queue->recover_stale_leases($scan_id);

        // Build the CPU governor once and reuse it across all units in this
        // drain window. The governor tracks system load across the entire
        // drain; creating a new instance per unit reset its load history
        // and fired a new shell_exec() for CPU detection on every unit.
        $throttle = $this->buildThrottle();

        // Drain-scoped reusable resources (differential hashes + signatures).
        // Built lazily on first FILE_BATCH via CleanSweep_WorkerContextImpl, then reused.
        require_once dirname(__DIR__) . '/DifferentialScanner.php';
        require_once dirname(__DIR__) . '/SignaturePreFilter.php';
        if (!function_exists('clean_sweep_get_malware_signatures')) {
            require_once dirname(__DIR__) . '/signatures.php';
        }
        // Defer set_profile_id (hash manifest load) until the first FILE_BATCH.
        $shared_differential = new CleanSweep_DifferentialScanner(null, false);
        $shared_differential->set_enabled($this->profile->get_enable_differential_scan());
        // Signatures/prefilter are built lazily on the first FILE_BATCH unit.
        $shared_signatures = null;
        $shared_prefilter = null;

        $last_heartbeat_at = 0;        // throttle checkpoint heartbeat writes
        $last_cancel_check_at = 0;     // throttle checkpoint reads for cancel detection

        while (true) {
            $elapsed = time() - $drain_start;
            if ($elapsed >= $time_budget) {
                $reason = 'time_budget_exceeded';
                break;
            }
            if ($processed >= $max_units) {
                $reason = 'time_budget_exceeded'; // treat as soft budget so kick continues
                break;
            }

            // Re-read state periodically (cancel can flip it).
            // Reading the checkpoint file on EVERY iteration is expensive I/O;
            // check every 5 seconds instead. Cancel detection latency goes from
            // ~0ms to ~5s, which is acceptable for a background scanner.
            $now = time();
            if (($now - $last_cancel_check_at) >= 5) {
                $state = $ckpt->load();
                if ($state === null || $state->isTerminal()) {
                    $reason = $state === null ? 'checkpoint_lost' : 'cancelled';
                    break;
                }
                $last_cancel_check_at = $now;
            }

            // Heartbeat: track when the queue was last touched (for stale lease recovery).
            // Throttled to every 10 seconds — writing the checkpoint on every iteration
            // was burning I/O on shared hosts for no benefit.
            if (($now - $last_heartbeat_at) >= 10) {
                $ckpt->merge(['last_drain_activity_at' => $now]);
                $last_heartbeat_at = $now;
            }

            // Try to claim a unit
            $lease_seconds = max(30, $time_budget - $elapsed - 5);
            $unit = $this->queue->claim_next($scan_id, $lease_seconds);

            if ($unit === null) {
                // Quick check: pending queue/ and in_flight/ (do not treat in-flight
                // leases as "empty" or we mark complete while another drain holds work).
                $scan_dir = $this->queue->get_scan_dir($scan_id);
                $remaining = is_dir($scan_dir . 'queue/')
                    ? (glob($scan_dir . 'queue/wu_*.json') ?: [])
                    : [];
                $in_flight = is_dir($scan_dir . 'in_flight/')
                    ? (glob($scan_dir . 'in_flight/wu_*.json') ?: [])
                    : [];
                if (empty($remaining) && empty($in_flight)) {
                    $reason = 'queue_empty';
                } elseif (empty($remaining) && !empty($in_flight)) {
                    $reason = 'in_flight_busy';
                } else {
                    $reason = 'nothing_claimable';
                }
                break;
            }

            $processed++;

            // Look up the worker
            $type = $unit->get_type();
            $worker = $this->workers->get($type);
            if ($worker === null) {
                $this->queue->fail($unit->get_work_id(), "unknown_unit_type:{$type}", false);
                $failed++;
                continue;
            }

            // Build context — throttle (CpuGovernor) is shared across the drain
            $ctx = new CleanSweep_WorkerContextImpl(
                $state,
                $ckpt,
                $this->threats,
                $throttle,
                $this->profile,
                $this->queue,
                $started_at
            );
            $ctx->setDrainResources($shared_differential, $shared_signatures, $shared_prefilter);
            if (method_exists($ctx, 'setSliceDeadline')) {
                $ctx->setSliceDeadline($drain_start, $time_budget);
            }

            // Run the worker
            $result = $this->runWorker($worker, $unit, $ctx);
            $ctx->flushPending();
            // Persist lazily-built resources for subsequent units in this drain.
            $shared_signatures = $ctx->sharedSignatures() ?? $shared_signatures;
            $shared_prefilter = $ctx->sharedPrefilter() ?? $shared_prefilter;
            $shared_differential = $ctx->sharedDifferential() ?? $shared_differential;

            if ($result->status === 'completed') {
                $this->queue->complete($unit->get_work_id(), $result->data, $scan_id);
                $completed++;
            } elseif ($result->status === 'more_work_available') {
                // Prefer continue_unit: rewrite same work_id back to queue/ (no new file).
                $follow = $this->followOnPayload($type, $result->data);
                if ($follow !== null
                    && $this->queue->continue_unit($unit->get_work_id(), $follow, $scan_id, $result->data)
                ) {
                    // Same unit re-queued; do not bump completed counter.
                } else {
                    // Fallback: legacy complete + new unit file.
                    $this->queue->complete($unit->get_work_id(), $result->data, $scan_id);
                    $completed++;
                    $this->enqueueFollowOn($scan_id, $type, $result->data);
                }
            } else {
                $this->queue->fail($unit->get_work_id(), $result->error ?? 'unknown', true, $scan_id);
                $failed++;
            }
            // No inter-unit sleep here: CpuGovernor::file_yield() (called per file)
            // and CpuGovernor::batch_yield() (called per work unit) already provide
            // adaptive throttling. Adding a fixed 50ms sleep per unit added 80+ seconds
            // of mandatory sleep for a 1,600-unit scan before any actual work.
        }

        $elapsed = time() - $drain_start;

        // Before scheduling, verify loopback actually works on this host.
        // The CleanSweep_HostDetector constructor only checks theoretical capability;
        // this probe confirms the server can actually reach itself via HTTP.
        $this->host->ensureLoopbackTested();

        // Decide what to do next
        $state = $ckpt->load();

        // If the queue is empty but finalization never ran (or status was
        // never flipped), leave terminal detection to CleanSweep_FinalizeWorker. When
        // work remains and we are not already terminal, mark paused so the
        // UI / findLatestResumable can offer Resume instead of hanging on
        // "running" forever if kicks fail.
        if ($state !== null && !$state->isTerminal()) {
            if ($reason === 'queue_empty') {
                // Safety net: if finalize unit was missing, complete the scan.
                $qstats = $this->queue->getStats($scan_id);
                $still_pending = !empty($qstats['has_pending'])
                    || ((int)($qstats['pending'] ?? 0) > 0)
                    || ((int)($qstats['running'] ?? 0) > 0)
                    || ((int)($qstats['claimed'] ?? 0) > 0);
                if (!$still_pending && $state->status !== 'completed') {
                    // Check if finalize already set complete phase
                    if (($state->phase ?? '') === 'complete') {
                        $ckpt->merge(['status' => 'completed', 'finished_at' => time()]);
                    } else {
                        // Prefer re-queue finalize once so threat recount / likely-source run.
                        $opts = is_array($state->options ?? null) ? $state->options : [];
                        if (empty($opts['finalize_requeued'])) {
                            $opts['finalize_requeued'] = true;
                            $final = CleanSweep_ScanWorkUnit::create(
                                $scan_id,
                                CleanSweep_ScanWorkUnit::TYPE_FINALIZATION,
                                [],
                                300
                            );
                            $this->queue->enqueue($final);
                            $ckpt->merge([
                                'status' => 'paused',
                                'pause_reason' => 'finalize_requeued',
                                'options' => $opts,
                                'last_drain_activity_at' => time(),
                            ]);
                            $reason = 'nothing_claimable';
                            clean_sweep_log_message(
                                "CleanSweep_Scanner: re-queued missing finalize for {$scan_id}",
                                'warning'
                            );
                        } else {
                            // Already tried — unblock UI rather than looping forever.
                            $ckpt->merge([
                                'status' => 'completed',
                                'phase' => 'complete',
                                'finished_at' => time(),
                            ]);
                        }
                    }
                }
            } elseif (in_array($reason, ['time_budget_exceeded', 'nothing_claimable', 'in_flight_busy', 'scan_paused', 'deliberate_pause'], true)) {
                $now = time();
                $ckpt->merge([
                    'status' => 'paused',
                    'pause_reason' => $reason,
                    'last_drain_activity_at' => $now,
                ]);
            }
            $state = $ckpt->load();
        }

        $kick = $this->scheduler->scheduleNext($state ?? new CleanSweep_ScanState(), ['reason' => $reason]);
        $this->executeKick($kick, $scan_id);

        $final_status = 'paused';
        if (($reason === 'queue_empty' && $state && $state->status === 'completed')
            || ($state && $state->status === 'completed')) {
            $final_status = 'completed';
        } elseif ($state && $state->isTerminal()) {
            $final_status = $state->status;
        }

        // Do not leave clean_sweep_scan_kick in wp_options after the scan ends.
        // Only clear when the scan is terminal or the scheduler says no more work
        // (do not clear on storm_guard / none kicks that still leave pending work).
        $terminal = in_array($final_status, ['completed', 'cancelled', 'failed'], true)
            || ($state && $state->isTerminal());
        $no_more_work = $kick->channel === CleanSweep_ScheduledKick::CHANNEL_NONE
            && in_array($kick->reason, ['scan_terminal', 'queue_empty'], true);
        if ($terminal || $no_more_work) {
            self::clearScanKickCron($scan_id);
        }

        return [
            'status' => $final_status,
            'reason' => $reason,
            'processed' => $processed,
            'completed' => $completed,
            'failed' => $failed,
            'elapsed_seconds' => $elapsed,
            'kick' => [
                'channel' => $kick->channel,
                'at' => $kick->at_unix,
                'reason' => $kick->reason,
            ],
        ];
    }

    /**
     * WP-Cron hook name for scan continuation (single events only).
     */
    public static function scanKickHook(): string {
        return 'clean_sweep_scan_kick';
    }

    /**
     * Remove scheduled clean_sweep_scan_kick event(s) for one scan id.
     * Matches the args used in scheduleWpCronKick ([$scan_id]).
     */
    public static function clearScanKickCron(string $scan_id): void {
        if ($scan_id === '' || !function_exists('wp_clear_scheduled_hook')) {
            return;
        }
        wp_clear_scheduled_hook(self::scanKickHook(), [$scan_id]);
        if (function_exists('clean_sweep_log_message')) {
            clean_sweep_log_message("CleanSweep_Scanner: cleared WP-Cron kick for {$scan_id}", 'debug');
        }
    }

    /**
     * Remove all clean_sweep_scan_kick events (any scan id).
     * Used on new scan start and full toolkit delete.
     */
    public static function clearAllScanKickCrons(): void {
        $hook = self::scanKickHook();
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook($hook);
            if (function_exists('clean_sweep_log_message')) {
                clean_sweep_log_message('CleanSweep_Scanner: cleared all clean_sweep_scan_kick WP-Cron events', 'debug');
            }
            return;
        }
        // WP < 4.9 fallback: walk cron array
        if (!function_exists('_get_cron_array') || !function_exists('wp_unschedule_event')) {
            return;
        }
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            return;
        }
        foreach ($crons as $timestamp => $hooks) {
            if (!isset($hooks[$hook]) || !is_array($hooks[$hook])) {
                continue;
            }
            foreach ($hooks[$hook] as $sig => $event) {
                $args = $event['args'] ?? [];
                wp_unschedule_event((int) $timestamp, $hook, $args);
            }
        }
        if (function_exists('clean_sweep_log_message')) {
            clean_sweep_log_message('CleanSweep_Scanner: cleared all clean_sweep_scan_kick WP-Cron events (fallback)', 'debug');
        }
    }

    // ============================================================
    // INTERNAL HELPERS
    // ============================================================

    private function runWorker(CleanSweep_Worker $worker, CleanSweep_ScanWorkUnit $unit, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        try {
            $payload = $unit->get_payload() ?? [];
            return $worker->run($payload, $ctx);
        } catch (Exception $e) {
            clean_sweep_log_message("CleanSweep_Scanner: worker " . $worker->type() . " failed: " . $e->getMessage(), 'error');
            return CleanSweep_WorkerResult::failed($e->getMessage());
        }
    }

    /**
     * Build the follow-on payload for a more_work result, or null if none.
     */
    private function followOnPayload(string $type, array $data): ?array {
        if (!isset($data['follow_on_payload']) && !isset($data['last_file_path']) && !isset($data['last_id'])) {
            return null;
        }
        $payload = $data['follow_on_payload'] ?? [];
        if (!empty($payload) && is_array($payload)) {
            return $payload;
        }
        if ($type === CleanSweep_ScanWorkUnit::TYPE_FILE_BATCH) {
            return [
                'base_dir' => $data['base_dir'] ?? WP_CONTENT_DIR,
                'start_index' => (int)($data['files_scanned_so_far'] ?? 0),
                'count' => (int)($data['count'] ?? 200),
                'last_file_path' => $data['last_file_path'] ?? null,
            ];
        }
        if ($type === CleanSweep_ScanWorkUnit::TYPE_DB_TABLE_SEGMENT) {
            return [
                'table' => $data['table'] ?? null,
                'id_column' => 'ID',
                'start_id' => (int)($data['last_id'] ?? 0),
                'end_id' => (int)($data['last_id'] ?? 0) + 1000,
                'last_processed_id' => (int)($data['last_id'] ?? 0),
            ];
        }
        if ($type === CleanSweep_ScanWorkUnit::TYPE_PACKAGE_CHECKSUM
            && isset($data['follow_on_payload'])
            && is_array($data['follow_on_payload'])
        ) {
            return $data['follow_on_payload'];
        }
        return null;
    }

    private function enqueueFollowOn(string $scan_id, string $type, array $data): void {
        $payload = $this->followOnPayload($type, $data);
        if ($payload === null) {
            return;
        }
        $priority = 100;
        if ($type === CleanSweep_ScanWorkUnit::TYPE_VISIT_CENSUS) {
            $priority = 200;
        }
        $new = CleanSweep_ScanWorkUnit::create($scan_id, $type, $payload, $priority);
        // Soft-batch a single follow-on so index/meta rewrite once (unit file still written).
        $this->queue->begin_batch($scan_id);
        try {
            $this->queue->enqueue($new);
        } finally {
            $this->queue->end_batch($scan_id);
        }
    }

    private function buildThrottle(): CleanSweep_CpuGovernor {
        // Prefer profile preset (Phase 2 Quick = conservative) when more
        // restrictive than the host default; CleanSweep_HostDetector still informs context.
        $profile_preset = method_exists($this->profile, 'get_cpu_governor_preset')
            ? $this->profile->get_cpu_governor_preset()
            : 'balanced';
        $host_preset = $this->host->cpuGovernorPreset();
        $rank = ['aggressive' => 0, 'low' => 1, 'balanced' => 2, 'high' => 3];
        $preset = (($rank[$profile_preset] ?? 2) <= ($rank[$host_preset] ?? 2))
            ? $profile_preset
            : $host_preset;

        return new CleanSweep_CpuGovernor([
            'preset' => $preset,
            'context' => $this->host->context,
        ]);
    }

    /**
     * Phase 2 §5: resolve whether a trusted post-reinstall baseline exists.
     *
     * @return array{available: bool, note: string, path: string|null}
     */
    private function resolveIntegrityBaselineMeta(): array {
        $boot = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 2) . '/')
            . 'includes/system/visit/bootstrap.php';
        if (is_readable($boot)) {
            require_once $boot;
            $st = (new CleanSweep_VisitState())->load();
            if (!empty($st['scopes']['core']['sealed'])) {
                return [
                    'available' => true,
                    'path' => 'visit:core',
                    'note' => 'Core is sealed for this visit. Integrity compare will run.',
                ];
            }
        }

        $baseline_file = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'backups/core_integrity_baseline.json'
            : __DIR__ . '/../../../backups/core_integrity_baseline.json';

        if (file_exists($baseline_file)) {
            return [
                'available' => true,
                'path' => $baseline_file,
                'note' => 'Trusted post-reinstall baseline found. Integrity check will run as part of this scan.',
            ];
        }

        return [
            'available' => false,
            'path' => null,
            'note' => 'No trusted baseline is available (run Core Reinstall first for reinfection detection).',
        ];
    }

    /**
     * Normalize Deep scan_scope / paths and resolve seeds.
     * Non-Deep profiles always get full (ignore + log if scope keys present).
     *
     * @param array $config Raw custom_config
     * @return array Config with scan_scope, want_files, want_db, resolved_seeds, …
     * @throws CleanSweep_ScanConfigException
     */
    public function prepareScopeConfig(array $config): array {
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/SitePaths.php';

        $config = self::normalizeCustomConfigScalars($config);
        $scope = $this->normalizeDeepScope($config);

        $want_files = in_array($scope, ['full', 'files', 'paths'], true);
        $want_db = ($scope === 'full' || $scope === 'database'
            || ($scope === 'paths' && !empty($config['include_db'])));
        $path_scoped = ($scope === 'paths');

        $seeds = [];
        $display = null;
        if ($want_files) {
            if ($path_scoped) {
                $resolved = $this->resolvePathSeeds($config);
                if (empty($resolved['seeds'])) {
                    throw new CleanSweep_ScanConfigException(
                        'No resolvable folder path for Deep path-scoped scan. Use a path under the WordPress site (e.g. wp-content/plugins/).',
                        'INVALID_FOLDER_PATH'
                    );
                }
                $seeds = $resolved['seeds'];
                $display = $resolved['display'];
                // Persist absolute seeds for resume/logs; keep original UI string for labels.
                $config['folder_path'] = $seeds[0];
                $config['folder_paths'] = $seeds;
                $config['folder_path_display'] = $display;
            } else {
                // Stray folder_path on full/files must not leak into options/status.
                unset($config['folder_path'], $config['folder_paths'], $config['folder_path_display'], $config['scope_coerced_from_folder']);
                $wp_content = CleanSweep_SitePaths::content_dir();
                if (!$wp_content && defined('ORIGINAL_WP_CONTENT_DIR') && is_dir(ORIGINAL_WP_CONTENT_DIR)) {
                    $wp_content = ORIGINAL_WP_CONTENT_DIR;
                }
                if (!$wp_content && defined('WP_CONTENT_DIR') && is_dir(WP_CONTENT_DIR)) {
                    $wp_content = WP_CONTENT_DIR;
                }
                if ($wp_content && is_dir($wp_content)) {
                    $seeds[] = rtrim(str_replace('\\', '/', $wp_content), '/');
                }
                if (empty($seeds)) {
                    throw new CleanSweep_ScanConfigException(
                        'Could not resolve wp-content for file scan. Check site paths / ORIGINAL_WP_CONTENT_DIR.',
                        'INVALID_SITE_PATHS'
                    );
                }
            }
        } else {
            // database-only (or no files): drop path keys from persisted options
            unset($config['folder_path'], $config['folder_paths'], $config['folder_path_display'], $config['scope_coerced_from_folder']);
        }

        $config['scan_scope'] = $scope;
        $config['want_files'] = $want_files;
        $config['want_db'] = $want_db;
        $config['path_scoped'] = $path_scoped;
        $config['resolved_seeds'] = $seeds;
        $config['include_db'] = !empty($config['include_db']);

        return $config;
    }

    /**
     * @param array $config
     * @return string One of full|files|database|paths
     * @throws CleanSweep_ScanConfigException
     */
    private function normalizeDeepScope(array &$config): string {
        $is_deep = $this->profile->get_profile_id() === CleanSweep_ScanProfile::DEEP;
        $has_scope_keys = isset($config['scan_scope']) || isset($config['scan_phase'])
            || $this->configHasPathKeys($config);

        if (!$is_deep) {
            if ($has_scope_keys) {
                clean_sweep_log_message(
                    'CleanSweep_Scanner: ignoring scan_scope/path keys on non-Deep profile (' .
                    $this->profile->get_profile_id() . ')',
                    'info'
                );
            }
            return 'full';
        }

        $raw_scope = isset($config['scan_scope']) ? trim((string)$config['scan_scope']) : '';
        if ($raw_scope !== '') {
            if (in_array($raw_scope, ['full', 'files', 'database', 'paths'], true)) {
                return $raw_scope;
            }
            throw new CleanSweep_ScanConfigException(
                'Invalid scan_scope: ' . $raw_scope,
                'INVALID_SCAN_SCOPE'
            );
        }

        // Absent scan_scope + path keys → paths (DB off unless include_db).
        if ($this->configHasPathKeys($config)) {
            $config['scope_coerced_from_folder'] = true;
            return 'paths';
        }

        // Legacy alias (reachable only when scope/paths absent).
        $phase = isset($config['scan_phase']) ? trim((string)$config['scan_phase']) : '';
        if ($phase === 'files' || $phase === 'database') {
            return $phase;
        }

        return 'full';
    }

    private function configHasPathKeys(array $config): bool {
        if (!empty($config['folder_path']) && is_string($config['folder_path']) && trim($config['folder_path']) !== '') {
            return true;
        }
        if (!empty($config['folder_paths']) && is_array($config['folder_paths'])) {
            foreach ($config['folder_paths'] as $p) {
                if (is_string($p) && trim($p) !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array{seeds: string[], display: string|null}
     * @throws CleanSweep_ScanConfigException
     */
    private function resolvePathSeeds(array $config): array {
        $raw_list = [];
        if (!empty($config['folder_paths']) && is_array($config['folder_paths'])) {
            foreach ($config['folder_paths'] as $p) {
                if (is_string($p) && trim($p) !== '') {
                    $raw_list[] = trim($p);
                }
            }
        }
        if (!empty($config['folder_path']) && is_string($config['folder_path']) && trim($config['folder_path']) !== '') {
            array_unshift($raw_list, trim($config['folder_path']));
        }
        $raw_list = array_values(array_unique($raw_list));
        if (count($raw_list) > 20) {
            throw new CleanSweep_ScanConfigException(
                'Too many folder_paths (max 20)',
                'INVALID_FOLDER_PATH'
            );
        }

        $seeds = [];
        $display_parts = [];
        foreach ($raw_list as $raw) {
            $resolved = CleanSweep_SitePaths::resolve_scan_seed_path($raw);
            if ($resolved === null) {
                continue;
            }
            if (!in_array($resolved, $seeds, true)) {
                $seeds[] = $resolved;
                $display_parts[] = $raw;
            }
        }

        return [
            'seeds' => $seeds,
            'display' => !empty($display_parts) ? implode(', ', $display_parts) : null,
        ];
    }

    /**
     * Normalize scalars after wire parse (include_db, folder_paths string, trims).
     */
    public static function normalizeCustomConfigScalars(array $config): array {
        if (array_key_exists('include_db', $config)) {
            $config['include_db'] = self::toBool($config['include_db']);
        } else {
            $config['include_db'] = false;
        }
        if (array_key_exists('fresh_scan', $config)) {
            $config['fresh_scan'] = self::toBool($config['fresh_scan']);
        } else {
            $config['fresh_scan'] = false;
        }

        if (isset($config['folder_paths']) && is_string($config['folder_paths'])) {
            $decoded = json_decode($config['folder_paths'], true);
            $config['folder_paths'] = is_array($decoded) ? $decoded : [];
        }
        if (isset($config['folder_paths']) && is_array($config['folder_paths'])) {
            $config['folder_paths'] = array_values(array_filter(
                array_map(static function ($p) {
                    return is_string($p) ? trim($p) : '';
                }, $config['folder_paths']),
                static function ($p) {
                    return $p !== '';
                }
            ));
        }

        foreach (['scan_scope', 'folder_path', 'scan_phase', 'folder_path_display'] as $key) {
            if (isset($config[$key]) && !is_string($config[$key])) {
                $config[$key] = is_scalar($config[$key]) ? trim((string)$config[$key]) : '';
            } elseif (isset($config[$key])) {
                $config[$key] = trim((string)$config[$key]);
            }
        }

        return $config;
    }

    /** @param mixed $v */
    public static function toBool($v): bool {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return ((int)$v) === 1;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));
            return in_array($s, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    private function buildInitialWorkPlan(string $scan_id, array $config, bool $has_baseline = false): void {
        // Config must already be prepareScopeConfig()'d (scan_scope, want_*, resolved_seeds).
        $want_files = !empty($config['want_files']);
        $want_db = !empty($config['want_db']);
        $path_scoped = !empty($config['path_scoped']);
        $seeds = is_array($config['resolved_seeds'] ?? null) ? $config['resolved_seeds'] : [];

        // Site-wide file extras: NEVER gate on empty(folder_path) string — that caused
        // the relative-path footgun (skip checksums while still scanning full wp-content).
        $skip_sitewide_file_extras = $path_scoped || !$want_files;

        // Priority (lower runs first): seed discovery/FILE_BATCH (70–82), then
        // checksums (110–120) and child discovery (120). Visit census is 200 so
        // a uploads dump cannot block malware start. Finalize is 300.
        if ($want_files && !$skip_sitewide_file_extras) {
            $this->queue->enqueue(CleanSweep_ScanWorkUnit::create(
                $scan_id,
                CleanSweep_ScanWorkUnit::TYPE_VISIT_CENSUS,
                ['phase' => 'site_owned', 'offset' => 0],
                200
            ));
            $this->queue->enqueue(CleanSweep_ScanWorkUnit::create(
                $scan_id,
                CleanSweep_ScanWorkUnit::TYPE_ROOT_CONFIG,
                [],
                101
            ));
            $this->queue->enqueue(CleanSweep_ScanWorkUnit::create(
                $scan_id,
                CleanSweep_ScanWorkUnit::TYPE_CORE_CHECKSUM,
                [],
                110
            ));
            $this->queue->enqueue(CleanSweep_ScanWorkUnit::create(
                $scan_id,
                CleanSweep_ScanWorkUnit::TYPE_PACKAGE_CHECKSUM,
                [
                    'start' => 0,
                    'force' => !empty($config['fresh_scan']),
                ],
                120
            ));
        }

        // BFS depth budget from profile (quick=2, standard=3, deep=5).
        // Seed only expands immediate children; each tree sets its own budget.
        $seed_depth = 1;
        foreach ($seeds as $root) {
            if (is_file($root)) {
                $this->queue->enqueue(CleanSweep_ScanWorkUnit::create(
                    $scan_id,
                    CleanSweep_ScanWorkUnit::TYPE_FILE_BATCH,
                    [
                        'base_dir' => dirname($root),
                        'explicit_files' => [$root],
                    ],
                    70
                ));
                continue;
            }
            if (!is_dir($root)) {
                continue;
            }
            $disc = CleanSweep_ScanWorkUnit::create(
                $scan_id,
                CleanSweep_ScanWorkUnit::TYPE_FILE_DISCOVERY,
                [
                    'base_dir' => $root,
                    'start_path' => $root,
                    'max_depth' => $seed_depth,
                    'defer_package_trees' => !$path_scoped,
                ],
                70
            );
            $this->queue->enqueue($disc);
        }

        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/DbScanPlanner.php';

        $this->queue->begin_batch($scan_id);
        try {
            if ($want_db) {
                $this->enqueueDbSegments($scan_id);
            }

            // Integrity: full + files only (not paths, not database-only).
            if ($has_baseline && $want_files && !$path_scoped) {
                $unit = CleanSweep_ScanWorkUnit::create(
                    $scan_id,
                    CleanSweep_ScanWorkUnit::TYPE_INTEGRITY_CHECK,
                    [],
                    200
                );
                $this->queue->enqueue($unit);
            }

            $final = CleanSweep_ScanWorkUnit::create(
                $scan_id,
                CleanSweep_ScanWorkUnit::TYPE_FINALIZATION,
                [],
                300
            );
            $this->queue->enqueue($final);
        } finally {
            $this->queue->end_batch($scan_id);
        }
    }

    private function enqueueDbSegments(string $scan_id): void {
        global $wpdb;
        if (!isset($wpdb) || $wpdb === null) {
            // WordPress not loaded (e.g. CLI smoke test). Skip DB segments;
            // the scan will still scan files and the user can rerun once
            // WordPress is available.
            clean_sweep_log_message("CleanSweep_Scanner: \$wpdb not available, skipping DB segments", 'warning');
            return;
        }

        $globals = CleanSweep_DbScanPlanner::enqueue_global_tables(
            $this->queue,
            $scan_id,
            $this->profile,
            $this->host
        );

        $current_prefix = (string)$wpdb->prefix;
        $current_blog = CleanSweep_DbScanPlanner::current_blog_id();
        $skip = [$current_blog];

        $blog_segments = CleanSweep_DbScanPlanner::enqueue_blog_tables(
            $this->queue,
            $scan_id,
            $current_prefix,
            $this->profile,
            $this->host
        );

        // Quick (and first Standard/Deep tick): also cover the main site
        // when the scan was started from a sub-site.
        $base_prefix = CleanSweep_DbScanPlanner::base_prefix();
        if ($current_prefix !== $base_prefix) {
            $blog_segments += CleanSweep_DbScanPlanner::enqueue_blog_tables(
                $this->queue,
                $scan_id,
                $base_prefix,
                $this->profile,
                $this->host
            );
            $skip[] = 1;
        }

        $is_ms = CleanSweep_DbScanPlanner::is_multisite_target();
        $wants_discovery = $is_ms && $this->profile->get_profile_id() !== CleanSweep_ScanProfile::QUICK;
        if ($wants_discovery) {
            $disc = CleanSweep_ScanWorkUnit::create(
                $scan_id,
                CleanSweep_ScanWorkUnit::TYPE_DB_SITE_DISCOVERY,
                [
                    'last_blog_id' => 0,
                    'sites_done' => count(array_unique($skip)),
                    'skip_blog_ids' => array_values(array_unique($skip)),
                ],
                145
            );
            $this->queue->enqueue($disc);
        }

        clean_sweep_log_message(
            "CleanSweep_Scanner: queued DB globals={$globals} blog_segments={$blog_segments} multisite=" .
            ($is_ms ? 'yes' : 'no') . " discovery=" . ($wants_discovery ? 'yes' : 'no'),
            'info'
        );
    }

    /**
     * Continue the scan without the browser: prefer HTTP loopback; WP-Cron only
     * when loopback is unavailable or the loopback fire hard-fails.
     *
     * Avoids writing clean_sweep_scan_kick into wp_options on healthy hosts.
     */
    private function executeKick(CleanSweep_ScheduledKick $kick, string $scan_id): void {
        if ($kick->channel === CleanSweep_ScheduledKick::CHANNEL_NONE) {
            return;
        }

        // CleanSweep_Scheduler already decided loopback is not usable on this host.
        if ($kick->channel === CleanSweep_ScheduledKick::CHANNEL_CRON) {
            $this->scheduleWpCronKick($scan_id, max(time(), (int) $kick->at_unix), 'no_loopback');
            return;
        }

        if ($kick->channel !== CleanSweep_ScheduledKick::CHANNEL_LOOPBACK || empty($kick->url)) {
            return;
        }

        $loopback_ok = $this->fireLoopbackKick($kick->url, $scan_id);
        if ($loopback_ok) {
            // Drop any prior safety-net cron for this scan; loopback is handling it.
            self::clearScanKickCron($scan_id);
            return;
        }

        // Hard loopback failure: temporary WP-Cron single event only.
        if ($this->host->can_wp_cron && function_exists('wp_schedule_single_event')) {
            $at = max(time() + 5, (int) $kick->at_unix);
            $this->scheduleWpCronKick($scan_id, $at, 'loopback_failed');
            return;
        }

        clean_sweep_log_message(
            "CleanSweep_Scanner: loopback kick failed and WP-Cron unavailable for {$scan_id}; UI poll/resume must continue the scan",
            'warning'
        );
    }

    /**
     * Schedule a WP-Cron single event that drains the given scan.
     * Replaces any prior kick for this scan_id (no stacking).
     *
     * @param string $why  Log reason: no_loopback | loopback_failed
     */
    private function scheduleWpCronKick(string $scan_id, int $at_unix, string $why = 'fallback'): void {
        if (!function_exists('wp_schedule_single_event')) {
            clean_sweep_log_message("CleanSweep_Scanner: WP-Cron unavailable for kick of {$scan_id}", 'warning');
            return;
        }

        $hook = self::scanKickHook();
        // One pending kick per scan — clear then schedule.
        self::clearScanKickCron($scan_id);
        $ok = wp_schedule_single_event($at_unix, $hook, [$scan_id]);
        if ($ok === false) {
            clean_sweep_log_message("CleanSweep_Scanner: wp_schedule_single_event failed for {$scan_id}", 'warning');
            return;
        }
        clean_sweep_log_message(
            "CleanSweep_Scanner: scheduled WP-Cron kick for {$scan_id} at " . date('c', $at_unix) . " ({$why})",
            'info'
        );
        if (function_exists('spawn_cron')) {
            @spawn_cron();
        }
    }

    /**
     * Fire-and-forget HTTP loopback to continue draining a scan.
     *
     * @return bool True if the request was likely accepted (do not schedule cron).
     *              False on hard failure (connection refused, no curl, etc.).
     */
    private function fireLoopbackKick(string $url, string $scan_id): bool {
        if (!function_exists('curl_init')) {
            clean_sweep_log_message("CleanSweep_Scanner: curl unavailable for loopback kick of {$scan_id}", 'warning');
            return false;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Fire-and-forget: we only need the request accepted; drain runs in another worker.
        // 250ms worst-case bound (was 1s) — timeouts still count as accepted.
        if (defined('CURLOPT_TIMEOUT_MS')) {
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 250);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 150);
        } else {
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        }
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: CleanSweep-Loopback/1.0',
            'Connection: close',
        ]);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
        @curl_exec($ch);
        $errno = (int) curl_errno($ch);
        $err = (string) curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Fire-and-forget: HTTP code, clean curl, or a short timeout all mean
        // the request was attempted. Treating CURLE_OPERATION_TIMEDOUT (often
        // HTTP 0 after 250ms) as a hard fail stacked WP-Cron on top of the
        // tab poller and produced a drain-busy storm.
        $timeout = defined('CURLE_OPERATION_TIMEDOUT') ? (int) CURLE_OPERATION_TIMEDOUT : 28;
        if ($code > 0 || $errno === 0 || $errno === $timeout) {
            clean_sweep_log_message(
                "CleanSweep_Scanner: loopback kick fired for {$scan_id} (HTTP {$code}, errno={$errno})",
                'debug'
            );
            return true;
        }

        clean_sweep_log_message(
            "CleanSweep_Scanner: loopback kick hard-failed for {$scan_id}: errno={$errno} {$err}",
            'warning'
        );
        return false;
    }

    /**
     * Queue-driven progress: completed / (completed + remaining).
     * Matches what the UI shows as "steps left" so the bar feels honest.
     * Returns null when the queue is too small to trust (early discovery).
     * No high-water mark: discovery is allowed to grow pending and the %
     * should drop rather than freeze at a premature files-phase cap.
     */
    private function computeQueueProgress(array $qstats, string $phase): ?int {
        $pending   = (int)($qstats['pending'] ?? 0);
        $completed = (int)($qstats['completed'] ?? 0);
        $running   = (int)($qstats['in_progress'] ?? 0) + (int)($qstats['running'] ?? 0) + (int)($qstats['claimed'] ?? 0);
        // Avoid double-counting if in_progress already includes running+claimed
        if ((int)($qstats['in_progress'] ?? 0) > 0) {
            $running = (int)$qstats['in_progress'];
        }
        $failed    = (int)($qstats['failed'] ?? 0) + (int)($qstats['dead'] ?? 0);
        $total     = $pending + $completed + $running + $failed;

        // Need a meaningful queue before we trust this ratio (discovery floods pending)
        if ($total < 8 && $completed < 3) {
            return null;
        }

        $done = $completed;
        // Count half of in-progress units as partial progress
        $done_weighted = $done + ($running * 0.35);
        $pct = (int)floor(($done_weighted / max(1, $total)) * 100);

        if ($phase === 'complete' || $phase === 'finalization') {
            return 100;
        }

        // Never claim 100% until the backend marks complete
        return max(1, min(99, $pct));
    }

    /**
     * Early-discovery fallback only (queue not yet trustworthy).
     * Files must stay in a low band so a tiny first estimate cannot look
     * like 75% done while hundreds of units are still pending.
     */
    private function computeProgressRaw(
        string $phase,
        int $cumulative,
        int $total_estimate,
        int $db_rows = 0
    ): int {
        switch ($phase) {
            case 'initializing':
                return 1;
            case 'files':
            case 'file':
                if ($cumulative <= 0) {
                    return 0;
                }
                // Discovery is still growing the real workload. Stay in a low
                // band so the bar cannot look almost finished.
                if ($total_estimate > 0) {
                    $raw = (int) floor(($cumulative / max(1, $total_estimate)) * 20);
                    return min(12, max(1, $raw));
                }
                return min(10, (int) floor(1 + log10(max(1, $cumulative)) * 4));
            case 'database':
            case 'db':
                $db_signal = max($db_rows, $cumulative);
                if ($db_signal <= 0) {
                    return 8;
                }
                $bump = (int) floor(log10(max(1, $db_signal)) * 4);
                $bump += (int) floor($db_signal / 4000);
                return min(40, 8 + $bump);
            case 'analysis':
            case 'anomaly_detection':
                return 96;
            case 'complete':
            case 'finalization':
            case 'finalize':
                return 100;
            default:
                return 0;
        }
    }

    public static function newScanId(): string {
        return 'bg_' . time() . '_' . bin2hex(random_bytes(4));
    }
}

/**
 * Invalid Deep scope / folder_path configuration for CleanSweep_Scanner::start().
 */
class CleanSweep_ScanConfigException extends Exception {
    /** @var string */
    public $errorCode;

    public function __construct(string $message, string $errorCode = 'INVALID_SCAN_CONFIG') {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}
