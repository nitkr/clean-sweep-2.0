<?php
/**
 * Clean Sweep - File-Based Scan Work Queue
 *
 * Persistent file-based implementation of the work queue.
 * Zero external dependencies - works on any WordPress hosting.
 *
 * Directory layout:
 *   logs/scan_work/{scan_id}/
 *     queue/           - pending work units (JSON files)
 *     in_flight/       - currently executing (with lease files)
 *     dead_letter/     - permanently failed units
 *     index.json       - lightweight index for fast queries
 *     meta.json        - queue-level statistics
 *
 * @since Option C
 */

require_once __DIR__ . '/ScanWorkQueueInterface.php';
require_once __DIR__ . '/ScanWorkUnit.php';

class CleanSweep_FileBasedScanWorkQueue implements CleanSweep_ScanWorkQueueInterface {

    /** @var string Base directory for work queue storage */
    private $base_dir;

    /** @var int Default lease duration in seconds */
    private $default_lease_seconds = 180; // 3 minutes - tighter than before for faster stale detection on fatal exits

    /** @var int Heartbeat stale threshold in seconds - if no heartbeat in this time, unit is considered stale.
     *  Lowered for faster recovery after hard failures (fatal errors, OOM, max_execution_time).
     *  EpisodeRunner heartbeats every 15s, so 60s gives ~4 missed heartbeats tolerance.
     */
    private $heartbeat_stale_threshold = 60;

    /**
     * In-request index/meta caches to avoid O(n²) full-file rewrites when
     * bulk-enqueueing thousands of DB segments at scan start.
     * Slow logs showed json_encode() in update_index as the hot path during
     * clean_sweep_handle_start → enqueueDbSegments.
     *
     * @var array<string, array>
     */
    private $index_cache = [];

    /** @var array<string, bool> scan_id => dirty */
    private $index_dirty = [];

    /** @var array<string, array> */
    private $meta_cache = [];

    /** @var array<string, bool> */
    private $meta_dirty = [];

    /** @var array<string, int> pending enqueued counter increments before flush */
    private $meta_enqueued_delta = [];

    /** @var bool When true, index/meta writes are deferred until flush_caches() */
    private $batch_mode = false;

    /** @var int Nested begin_batch depth (flush only when it returns to 0) */
    private $batch_depth = 0;

    /** @var array<string,int> scan_id => last recover_stale_leases unix time */
    private $last_recovery_at = [];

    /** @var array<string,int> Pending items_processed deltas not yet flushed */
    private $items_processed_pending = [];

    /** @var array<string,int> Unix time of last items_processed flush per scan */
    private $items_processed_flushed_at = [];

    /** @var string Queue subdirectory name */
    const QUEUE_DIR = 'queue';

    /** Compact JSON flags for hot-path unit/meta writes */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES;

    /** Flush items_processed at most this often (seconds) */
    private const ITEMS_FLUSH_INTERVAL_SEC = 2;

    /** Or when this many items accumulate since last flush */
    private const ITEMS_FLUSH_EVERY_N = 100;

    /** @var string In-flight subdirectory name */
    const IN_FLIGHT_DIR = 'in_flight';

    /** @var string Completed subdirectory name (finished units moved here) */
    const COMPLETED_DIR = 'completed';

    /** @var string Dead letter subdirectory name */
    const DEAD_LETTER_DIR = 'dead_letter';

    /** @var string Index file name */
    const INDEX_FILE = 'index.json';

    /** @var string Meta file name */
    const META_FILE = 'meta.json';

    /**
     * Create a new file-based work queue.
     *
     * @param string|null $base_dir Base directory (defaults to logs/scan_work)
     */
    public function __construct($base_dir = null) {
        $this->base_dir = $base_dir ?? $this->get_default_base_dir();
        $this->ensure_directories();
    }

    /**
     * Get default base directory.
     *
     * @return string
     */
    private function get_default_base_dir() {
        $logs_dir = defined('CLEAN_SWEEP_PROGRESS_DIR') ? CLEAN_SWEEP_PROGRESS_DIR : __DIR__ . '/../../../logs/';
        return rtrim($logs_dir, '/') . '/scan_work/';
    }

    /**
     * Ensure base and subdirectories exist.
     */
    private function ensure_directories() {
        $dirs = [
            $this->base_dir,
            $this->base_dir . self::QUEUE_DIR,
            $this->base_dir . self::IN_FLIGHT_DIR,
            $this->base_dir . self::COMPLETED_DIR,
            $this->base_dir . self::DEAD_LETTER_DIR,
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Get the directory for a specific scan's work queue.
     *
     * @param string $scan_id
     * @return string
     */
    public function get_scan_dir($scan_id) {
        return $this->base_dir . $scan_id . '/';
    }

    /**
     * Get all scan directories (immediate subdirs of base_dir).
     * Filters out the per-state directories (queue, in_flight, etc.) so
     * callers don't accidentally try to operate on them.
     *
     * @return string[] Absolute paths, or empty array if none
     */
    private function get_scan_dirs(): array {
        $all = glob($this->base_dir . '*', GLOB_ONLYDIR);
        if (!is_array($all)) return [];
        return array_values(array_filter($all, function($d) {
            return !in_array(basename($d), [
                self::QUEUE_DIR, self::IN_FLIGHT_DIR,
                self::COMPLETED_DIR, self::DEAD_LETTER_DIR,
            ], true);
        }));
    }

    /**
     * Ensure scan-specific directory exists.
     *
     * @param string $scan_id
     */
    private function ensure_scan_dir($scan_id) {
        $dirs = [
            $this->get_scan_dir($scan_id),
            $this->get_scan_dir($scan_id) . self::QUEUE_DIR,
            $this->get_scan_dir($scan_id) . self::IN_FLIGHT_DIR,
            $this->get_scan_dir($scan_id) . self::COMPLETED_DIR,
            $this->get_scan_dir($scan_id) . self::DEAD_LETTER_DIR,
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Begin deferred index/meta writes for bulk enqueue (scan start / DB plan).
     * Must be paired with end_batch().
     */
    public function begin_batch(string $scan_id): void {
        $this->batch_depth++;
        $this->batch_mode = true;
        // Prime caches once
        if (!isset($this->index_cache[$scan_id])) {
            $this->index_cache[$scan_id] = $this->load_index($scan_id);
            $this->index_dirty[$scan_id] = false;
        }
        if (!isset($this->meta_cache[$scan_id])) {
            $this->meta_cache[$scan_id] = $this->load_meta_raw($scan_id);
            $this->meta_dirty[$scan_id] = false;
            $this->meta_enqueued_delta[$scan_id] = 0;
        }
    }

    /**
     * Flush deferred index/meta when the outermost batch ends.
     */
    public function end_batch(string $scan_id): void {
        if ($this->batch_depth > 0) {
            $this->batch_depth--;
        }
        if ($this->batch_depth > 0) {
            // Outer batch still open — keep deferring.
            return;
        }
        $this->flush_index($scan_id);
        $this->flush_meta($scan_id);
        $this->batch_mode = false;
    }

    /**
     * Enqueue many units efficiently (one index/meta rewrite at the end).
     *
     * @param CleanSweep_ScanWorkUnit[] $units
     * @return int Number enqueued
     */
    public function enqueue_many(array $units): int {
        if (empty($units)) {
            return 0;
        }
        $scan_id = $units[0]->get_scan_id();
        $this->begin_batch($scan_id);
        $n = 0;
        try {
            foreach ($units as $unit) {
                $this->enqueue($unit);
                $n++;
            }
        } finally {
            $this->end_batch($scan_id);
        }
        return $n;
    }

    /**
     * Enqueue a new work unit.
     *
     * @param CleanSweep_ScanWorkUnit $unit
     * @return string Work unit ID
     */
    public function enqueue(CleanSweep_ScanWorkUnit $unit) {
        $scan_id = $unit->get_scan_id();
        $this->ensure_scan_dir($scan_id);

        $work_id = $unit->get_work_id();
        $file_path = $this->get_queue_file_path($scan_id, $work_id);

        // Compact JSON — PRETTY_PRINT made bulk enqueue of DB segments very slow
        $data = json_encode($unit->to_array(), self::JSON_FLAGS);
        $result = @file_put_contents($file_path, $data, LOCK_EX);

        if ($result === false) {
            throw new Exception("Failed to enqueue work unit: {$work_id}");
        }

        // Update index
        $this->update_index($scan_id, $work_id, 'add');

        // Update meta
        $this->update_meta($scan_id, 'enqueued');

        // Debug logging per unit is expensive during bulk plan build
        if (!$this->batch_mode) {
            clean_sweep_log_message("WorkQueue: Enqueued {$work_id} for scan {$scan_id}", 'debug');
        }

        return $work_id;
    }

    /**
     * Claim the next available work unit.
     *
     * @param string|null $scan_id Scan ID (null for any)
     * @param int $lease_seconds Lease duration
     * @return CleanSweep_ScanWorkUnit|null
     */
    public function claim_next($scan_id = null, $lease_seconds = 300) {
        // NOTE: Stale lease recovery is the caller's responsibility.
        // CleanSweep_Scanner::drain() calls recover_stale_leases() once before its claim
        // loop. Other callers must call recover_stale_leases() before their
        // first claim_next() in a cycle to avoid leaving orphaned units.

        // If scan_id provided, look only in that scan's queue
        if ($scan_id !== null) {
            return $this->claim_from_scan($scan_id, $lease_seconds);
        }

        // Otherwise, scan all scan directories for pending work.
        // Match all immediate subdirs (any prefix: scan_*, bg_*, etc.)
        $all_dirs = glob($this->base_dir . '*', GLOB_ONLYDIR);
        $scan_dirs = is_array($all_dirs) ? array_filter($all_dirs, function($d) {
            return !in_array(basename($d), [self::QUEUE_DIR, self::IN_FLIGHT_DIR, self::COMPLETED_DIR, self::DEAD_LETTER_DIR], true);
        }) : [];
        if (empty($scan_dirs)) {
            return null;
        }

        // Sort by modification time (oldest first for fairness)
        usort($scan_dirs, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        foreach ($scan_dirs as $scan_dir) {
            $sid = basename($scan_dir);
            $unit = $this->claim_from_scan($sid, $lease_seconds);
            if ($unit !== null) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * Claim from a specific scan's queue.
     *
     * @param string $scan_id
     * @param int $lease_seconds
     * @return CleanSweep_ScanWorkUnit|null
     */
    private function claim_from_scan($scan_id, $lease_seconds) {
        $queue_dir = $this->get_scan_dir($scan_id) . self::QUEUE_DIR;

        if (!is_dir($queue_dir)) {
            return null;
        }

        // Find pending work units.
        // Optimization: glob() returns sorted alphabetically (wu_{time}_... ≈ create order).
        // Reading every JSON to sort by priority is O(N) disk I/O on huge queues.
        // Sample a bounded set: when small, read all; when large, earliest + latest
        // so late-enqueued high-priority file batches are not starved by bulk DB
        // units that share the first second of the scan.
        $files = glob($queue_dir . '/wu_*.json');
        if (empty($files)) {
            return null;
        }

        $n = count($files);
        if ($n <= 300) {
            $files_to_check = $files;
        } else {
            $head = array_slice($files, 0, 150);
            $tail = array_slice($files, -100);
            // Sparse sample from the middle for long-running discovery growth.
            $mid = [];
            $step = max(1, (int) floor(($n - 250) / 50));
            for ($i = 150; $i < $n - 100; $i += $step) {
                $mid[] = $files[$i];
                if (count($mid) >= 50) {
                    break;
                }
            }
            $files_to_check = array_values(array_unique(array_merge($head, $mid, $tail)));
        }

        // Read units once and sort in memory (avoids race condition from double-read)
        $units_data = [];
        foreach ($files_to_check as $file) {
            $data = @file_get_contents($file);
            if ($data === false) {
                continue;
            }
            $decoded = json_decode($data, true);
            if ($decoded === null) {
                continue;
            }
            $units_data[$file] = $decoded;
        }

        // Sort by priority then creation time
        uasort($units_data, function($a, $b) {
            $priority_a = $a['priority'] ?? 100;
            $priority_b = $b['priority'] ?? 100;
            if ($priority_a !== $priority_b) {
                return $priority_a - $priority_b;
            }
            return ($a['created_at'] ?? 0) - ($b['created_at'] ?? 0);
        });

        // Try to claim each unit in priority order
        foreach ($units_data as $file => $data) {
            $unit = CleanSweep_ScanWorkUnit::from_array($data);

            // Try to claim (atomic move)
            if ($this->atomic_claim($scan_id, $unit, $lease_seconds)) {
                return $unit;
            }
            // Claim failed (already claimed by another process) - continue to next
        }

        return null;
    }

    /**
     * Atomically claim a work unit (move from queue to in_flight).
     *
     * @param string $scan_id
     * @param CleanSweep_ScanWorkUnit $unit
     * @param int $lease_seconds
     * @return bool
     */
    private function atomic_claim($scan_id, $unit, $lease_seconds) {
        $queue_file = $this->get_queue_file_path($scan_id, $unit->get_work_id());
        $in_flight_file = $this->get_in_flight_file_path($scan_id, $unit->get_work_id());
        $lease_file = $this->get_lease_file_path($scan_id, $unit->get_work_id());

        // Check if queue file still exists (wasn't claimed by another process)
        if (!file_exists($queue_file)) {
            return false;
        }

        // Claim the unit — allow claiming if PENDING, lease expired, OR heartbeat stale.
        // This handles zombie units that recover_stale_leases() released back to queue.
        // MUST check return value: if false, the unit was not claimable and must NOT
        // be moved to in_flight/ (would create a ghost unit counted as 'running').
        if (!$unit->claim($lease_seconds, $this->heartbeat_stale_threshold)) {
            return false;
        }

        // Write to in_flight (lease fields already on the unit JSON)
        $data = json_encode($unit->to_array(), self::JSON_FLAGS);
        if (@file_put_contents($in_flight_file, $data, LOCK_EX) === false) {
            return false;
        }

        // Sidecar lease kept for cleanup globs; compact and best-effort.
        $lease_data = json_encode([
            'work_id' => $unit->get_work_id(),
            'claimed_by' => $unit->get_claimed_by(),
            'claimed_at' => $unit->get_claimed_at(),
            'lease_expires_at' => $unit->get_lease_expires_at(),
        ], self::JSON_FLAGS);
        @file_put_contents($lease_file, $lease_data, LOCK_EX);

        // Remove from queue (ignore failure if already removed)
        @unlink($queue_file);

        // Update index
        $this->update_index($scan_id, $unit->get_work_id(), 'claim');

        // Update meta
        $this->update_meta($scan_id, 'claimed');

        clean_sweep_log_message("WorkQueue: Claimed {$unit->get_work_id()} for scan {$scan_id}", 'debug');

        return true;
    }

    /**
     * Heartbeat - extend lease on a work unit.
     *
     * @param string $work_id
     * @return bool
     */
    public function heartbeat($work_id) {
        $info = $this->find_work_unit($work_id);
        if ($info === null) {
            return false;
        }

        $scan_id = $info['scan_id'];
        $in_flight_file = $this->get_in_flight_file_path($scan_id, $work_id);
        $lease_file = $this->get_lease_file_path($scan_id, $work_id);

        if (!file_exists($in_flight_file)) {
            return false;
        }

        $data = @file_get_contents($in_flight_file);
        if ($data === false) {
            return false;
        }

        $unit = CleanSweep_ScanWorkUnit::from_array(json_decode($data, true));
        $unit->heartbeat();

        // Write updated unit
        @file_put_contents($in_flight_file, json_encode($unit->to_array(), self::JSON_FLAGS), LOCK_EX);

        // Update lease file
        $lease_data = json_encode([
            'work_id' => $unit->get_work_id(),
            'claimed_by' => $unit->get_claimed_by(),
            'claimed_at' => $unit->get_claimed_at(),
            'lease_expires_at' => $unit->get_lease_expires_at(),
            'last_heartbeat_at' => $unit->get_last_heartbeat_at(),
        ]);
        @file_put_contents($lease_file, $lease_data, LOCK_EX);

        clean_sweep_log_message("WorkQueue: Heartbeat for {$work_id}", 'debug');

        return true;
    }

    /**
     * Mark a work unit as completed.
     *
     * @param string $work_id
     * @param array $result
     * @return bool
     */
    public function complete($work_id, $result = [], $scan_id = null) {
        // If scan_id is provided, skip the O(N) filesystem scan.
        // The drain loop already knows the scan_id, so passing it avoids
        // find_work_unit() scanning all scan directories for every complete() call.
        if ($scan_id === null) {
            $info = $this->find_work_unit($work_id);
            if ($info === null) {
                return false;
            }
            $scan_id = $info['scan_id'];
        }

        $in_flight_file = $this->get_in_flight_file_path($scan_id, $work_id);
        $lease_file = $this->get_lease_file_path($scan_id, $work_id);
        $in_flight_file = $this->get_in_flight_file_path($scan_id, $work_id);
        $lease_file = $this->get_lease_file_path($scan_id, $work_id);

        if (!file_exists($in_flight_file)) {
            return false;
        }

        $data = @file_get_contents($in_flight_file);
        if ($data === false) {
            return false;
        }

        $unit = CleanSweep_ScanWorkUnit::from_array(json_decode($data, true));

        // IDEMPOTENCY CHECK: If already completed, just clean up lease and return success
        // This handles the case where complete() was called but the response was lost
        if ($unit->get_status() === CleanSweep_ScanWorkUnit::STATUS_COMPLETED) {
            clean_sweep_log_message("WorkQueue: {$work_id} already completed (idempotent), cleaning up stale lease", 'debug');
            @unlink($lease_file);
            return true;
        }

        $unit->mark_completed($result);

        // Write completed unit to in_flight directory (overwrites running state)
        $write_result = @file_put_contents($in_flight_file, json_encode($unit->to_array(), self::JSON_FLAGS), LOCK_EX);
        if ($write_result === false) {
            // PERSISTENCE FAILURE: Work completed but couldn't write to disk
            // CRITICAL: Must remove in_flight file so get_stats() doesn't count it as 'running'
            // The index/meta already track completion, and the unit's work is done
            clean_sweep_log_message("WorkQueue: CRITICAL - {$work_id} completed but failed to persist in_flight file. " .
                "Removing in_flight file to prevent incorrect stats. Index/meta still updated.", 'error');

            @unlink($lease_file);
            @unlink($in_flight_file); // Remove stale in_flight file so it doesn't count as 'running'
            $this->update_index($scan_id, $work_id, 'complete');
            $this->update_meta($scan_id, 'completed');

            return false;
        }

        // Success - remove lease and update index. Meta completed++ only after
        // the unit is out of in_flight/ (avoids getStats double-count: meta
        // completed + in_flight glob-as-running).
        @unlink($lease_file);
        $this->update_index($scan_id, $work_id, 'complete');

        clean_sweep_log_message("WorkQueue: Completed {$work_id} for scan {$scan_id}", 'debug');

        // Move completed unit out of in_flight to completed/ directory.
        // Only unlink from in_flight/ if the completed/ write succeeded.
        // If the write fails, leave in in_flight (counted as running until
        // recover_stale_leases moves it) and do NOT bump completed yet.
        $completed_file = $this->get_completed_file_path($scan_id, $work_id);
        if ($completed_file !== null) {
            $write_ok = @file_put_contents($completed_file, json_encode($unit->to_array(), self::JSON_FLAGS), LOCK_EX);
            if ($write_ok !== false) {
                @unlink($in_flight_file);
                $this->update_meta($scan_id, 'completed');
            } else {
                clean_sweep_log_message("WorkQueue: {$work_id} completed but failed to move to completed/ — leaving in in_flight (status=completed) until recovery.", 'warning');
            }
        } else {
            // No completed path — still leave in_flight and skip meta bump.
            clean_sweep_log_message("WorkQueue: {$work_id} completed but completed/ path unavailable", 'warning');
        }

        return true;
    }

    /**
     * Get completed file path.
     *
     * @param string $scan_id
     * @param string $work_id
     * @return string|null
     */
    private function get_completed_file_path($scan_id, $work_id) {
        return $this->get_scan_dir($scan_id) . self::COMPLETED_DIR . '/' . $work_id . '.json';
    }

    /**
     * Mark a work unit as failed.
     *
     * @param string $work_id
     * @param string $error
     * @param bool $retryable
     * @return bool
     */
    public function fail($work_id, $error, $retryable = true, $scan_id = null) {
        // If scan_id is provided, skip the O(N) filesystem scan.
        // The drain loop already knows the scan_id, so passing it avoids
        // find_work_unit() scanning all scan directories for every fail() call.
        if ($scan_id === null) {
            $info = $this->find_work_unit($work_id);
            if ($info === null) {
                return false;
            }
            $scan_id = $info['scan_id'];
        }

        $in_flight_file = $this->get_in_flight_file_path($scan_id, $work_id);
        $lease_file = $this->get_lease_file_path($scan_id, $work_id);

        if (!file_exists($in_flight_file)) {
            return false;
        }

        $data = @file_get_contents($in_flight_file);
        if ($data === false) {
            return false;
        }

        $unit = CleanSweep_ScanWorkUnit::from_array(json_decode($data, true));
        $unit->mark_failed($error, $retryable);

        if ($unit->get_status() === CleanSweep_ScanWorkUnit::STATUS_DEAD) {
            // Move to dead letter queue
            $this->move_to_dead_letter($work_id);
            $this->update_meta($scan_id, 'dead');
        } else {
            // Write back to in_flight for retry
            @file_put_contents($in_flight_file, json_encode($unit->to_array(), self::JSON_FLAGS), LOCK_EX);
            // Move back to queue for retry
            $queue_file = $this->get_queue_file_path($scan_id, $work_id);
            @file_put_contents($queue_file, json_encode($unit->to_array(), self::JSON_FLAGS), LOCK_EX);
            @unlink($in_flight_file);
            $this->update_meta($scan_id, 'retry');
        }

        // Remove lease file
        @unlink($lease_file);

        // Update index
        $this->update_index($scan_id, $work_id, 'fail');

        clean_sweep_log_message("WorkQueue: Failed {$work_id} - {$error} (retryable=" . ($retryable ? 'yes' : 'no') . ")", 'debug');

        return true;
    }

    /**
     * List work units for a scan.
     *
     * @param string $scan_id
     * @param array $statuses Filter by statuses (empty = all)
     * @return CleanSweep_ScanWorkUnit[]
     */
    public function list_for_scan($scan_id, $statuses = []) {
        $units = [];
        $scan_dir = $this->get_scan_dir($scan_id);

        if (!is_dir($scan_dir)) {
            return $units;
        }

        // Scan queue, in_flight, completed, and dead_letter directories
        $dirs = [
            self::QUEUE_DIR,
            self::IN_FLIGHT_DIR,
            self::COMPLETED_DIR,
            self::DEAD_LETTER_DIR,
        ];

        foreach ($dirs as $dir) {
            $path = $scan_dir . $dir;
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . '/wu_*.json');
            foreach ($files as $file) {
                $data = @file_get_contents($file);
                if ($data === false) {
                    continue;
                }

                $unit = CleanSweep_ScanWorkUnit::from_array(json_decode($data, true));

                if (empty($statuses) || in_array($unit->get_status(), $statuses)) {
                    $units[] = $unit;
                }
            }
        }

        // Sort by creation time
        usort($units, function($a, $b) {
            return ($a->get_created_at() ?? 0) - ($b->get_created_at() ?? 0);
        });

        return $units;
    }

    /**
     * Get queue statistics for a scan.
     *
     * Back-compat shim. The authoritative source is now `getStats()`
     * which counts everything from a single directory listing (no dual
     * blending with META_FILE counters).
     *
     * @param string $scan_id
     * @return array
     */
    public function get_stats($scan_id) {
        return $this->getStats($scan_id);
    }

    /**
     * Get queue statistics for a scan (clean, single source of truth).
     *
     * Pending/running come from globbing queue/ and in_flight/ (bounded).
     * completed/dead and items_processed come from meta.json counters so
     * status polls do not readdir tens of thousands of completed unit files.
     * Legacy metas missing completed/dead keys are seeded once from a glob.
     *
     * @param string $scan_id
     * @return array
     */
    public function getStats(string $scan_id): array {
        $scan_dir = $this->get_scan_dir($scan_id);

        $stats = [
            'total_units' => 0,
            'pending' => 0,
            'claimed' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'dead' => 0,
            'items_processed' => 0,
            'has_pending' => false,
        ];

        // Hot path: only glob queue/ and in_flight/ (bounded). completed/ and
        // dead_letter/ grow monotonically — read counts from meta.json instead
        // of globbing tens of thousands of files on every UI poll.
        $queue_path = $scan_dir . self::QUEUE_DIR;
        if (is_dir($queue_path)) {
            $files = glob($queue_path . '/wu_*.json');
            $stats['pending'] = is_array($files) ? count($files) : 0;
        }

        $in_flight_path = $scan_dir . self::IN_FLIGHT_DIR;
        $in_flight_files = [];
        if (is_dir($in_flight_path)) {
            $in_flight_files = glob($in_flight_path . '/wu_*.json') ?: [];
            $stats['running'] = count($in_flight_files);
        }
        $stats['current_unit'] = $this->peek_current_unit($in_flight_files);

        $meta = $this->load_meta_raw($scan_id);
        $counters = is_array($meta['counters'] ?? null) ? $meta['counters'] : [];
        $stats['items_processed'] = (int)($meta['items_processed'] ?? 0);

        // Prefer monotonic meta counters. Seed once from glob for legacy metas
        // that never recorded completed/dead (avoids O(N) completed/ glob every poll).
        $needs_seed = !array_key_exists('completed', $counters) || !array_key_exists('dead', $counters);
        if ($needs_seed) {
            $completed_path = $scan_dir . self::COMPLETED_DIR;
            $dead_path = $scan_dir . self::DEAD_LETTER_DIR;
            $completed = array_key_exists('completed', $counters)
                ? (int)$counters['completed']
                : count(is_dir($completed_path) ? (glob($completed_path . '/wu_*.json') ?: []) : []);
            $dead = array_key_exists('dead', $counters)
                ? (int)$counters['dead']
                : count(is_dir($dead_path) ? (glob($dead_path . '/wu_*.json') ?: []) : []);
            $seed = array_merge(
                ['enqueued' => 0, 'claimed' => 0, 'completed' => 0, 'failed' => 0, 'dead' => 0, 'retry' => 0],
                $counters,
                ['completed' => $completed, 'dead' => $dead]
            );
            if (!isset($this->meta_cache[$scan_id])) {
                $this->meta_cache[$scan_id] = is_array($meta) ? $meta : [];
            }
            $this->meta_cache[$scan_id]['counters'] = $seed;
            $this->meta_dirty[$scan_id] = true;
            $this->flush_meta($scan_id);
            $counters = $seed;
        }

        $stats['completed'] = (int)($counters['completed'] ?? 0);
        $stats['dead'] = (int)($counters['dead'] ?? 0);
        $stats['total_units'] = $stats['pending'] + $stats['running'] + $stats['completed'] + $stats['dead'];

        // Pending, claimed, or in-flight means the scan still has work (drain safety net).
        $stats['has_pending'] = ($stats['pending'] + $stats['claimed'] + $stats['running']) > 0;

        // in_progress: sum of running (actively processing) and claimed (leased but not yet started).
        // In-flight units are counted as running only (no per-file status parse for poll DOS).
        // claimed stays 0 by design; UI should use in_progress / running.
        $stats['in_progress'] = $stats['running'] + $stats['claimed'];

        return $stats;
    }

    /**
     * Cheap UI peek: type + a few payload fields from one in-flight unit.
     * One JSON read per status poll; glob already listed in_flight/.
     *
     * @param array $in_flight_files Absolute paths from glob()
     * @return array{type:string,table:?string,base_dir:?string,start:?int,phase:?string}|null
     */
    private function peek_current_unit(array $in_flight_files): ?array {
        if ($in_flight_files === []) {
            return null;
        }
        $raw = @file_get_contents($in_flight_files[0]);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $type = (string) ($decoded['type'] ?? '');
        if ($type === '') {
            return null;
        }
        $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];
        $base = $payload['base_dir'] ?? $payload['start_path'] ?? null;
        if (is_string($base) && strlen($base) > 240) {
            $base = substr($base, -240);
        }
        $unit_phase = isset($payload['phase']) ? (string) $payload['phase'] : '';
        return [
            'type' => $type,
            'table' => isset($payload['table']) ? (string) $payload['table'] : null,
            'base_dir' => is_string($base) && $base !== '' ? $base : null,
            'start' => isset($payload['start']) ? (int) $payload['start'] : null,
            'phase' => $unit_phase !== '' ? $unit_phase : null,
        ];
    }

    /**
     * Recover stale leases (units that didn't heartbeat or whose process died).
     *
     * A unit is considered stale if EITHER:
     * 1. The lease has expired (time-based)
     * 2. The heartbeat is stale (process died without heartbeat)
     *
     * This dual-check approach handles both:
     * - Slow units that legitimately need more time (lease extended via heartbeat)
     * - Dead/crashed processes that never heartbeat again (heartbeat-stale detection)
     *
     * @param string|null $scan_id Optional scan ID to limit recovery
     * @param int|null $heartbeat_stale_threshold Seconds since last heartbeat to consider stale (null = use default)
     * @return int Number of units recovered
     */
    public function recover_stale_leases($scan_id = null, $heartbeat_stale_threshold = null) {
        $recovered = 0;
        $stale_threshold = $heartbeat_stale_threshold ?? $this->heartbeat_stale_threshold;

        $base_path = $scan_id !== null
            ? [$this->get_scan_dir($scan_id)]
            : $this->get_scan_dirs();

        if (empty($base_path)) {
            return $recovered;
        }

        foreach ($base_path as $scan_dir) {
            $sid = is_string($scan_dir) ? basename($scan_dir) : null;
            $in_flight_dir = is_string($scan_dir)
                ? $scan_dir . '/' . self::IN_FLIGHT_DIR
                : $scan_dir . '/' . self::IN_FLIGHT_DIR;

            if (!is_dir($in_flight_dir)) {
                continue;
            }

            // Skip when nothing is in flight (cheap).
            $files = glob($in_flight_dir . '/wu_*.json');
            if (!is_array($files) || empty($files)) {
                continue;
            }
            // Only throttle repeated full parses when the last pass recovered
            // nothing — never skip recovery when claim paths are stuck.
            $throttle_key = $sid ?? $in_flight_dir;
            $now = time();
            $last = $this->last_recovery_at[$throttle_key] ?? null;
            if (is_array($last)
                && ($now - (int)$last['at']) < 30
                && (int)$last['recovered'] === 0) {
                continue;
            }

            $recovered_here = 0;
            foreach ($files as $file) {
                $data = @file_get_contents($file);
                if ($data === false) {
                    continue;
                }

                $unit = CleanSweep_ScanWorkUnit::from_array(json_decode($data, true));

                // Check both lease expiry AND heartbeat staleness
                $is_lease_expired = $unit->is_lease_expired();
                $is_heartbeat_stale = $unit->is_heartbeat_stale($stale_threshold);

                if ($is_lease_expired || $is_heartbeat_stale) {
                    // If the unit is already completed, move it to completed/ directory
                    // instead of re-enqueueing. This prevents re-running already-done work.
                    if ($unit->get_status() === CleanSweep_ScanWorkUnit::STATUS_COMPLETED) {
                        $completed_file = $this->get_completed_file_path($sid, $unit->get_work_id());
                        $moved = false;
                        $already_archived = $completed_file !== null && file_exists($completed_file);
                        if ($completed_file !== null) {
                            $moved = @file_put_contents($completed_file, json_encode($unit->to_array(), self::JSON_FLAGS), LOCK_EX) !== false;
                        }
                        if ($moved || $already_archived) {
                            @unlink($file);
                            // Bump meta only when we newly archive — avoid double-count
                            // if complete() already incremented before a failed unlink.
                            if ($moved && !$already_archived && $sid !== null) {
                                $this->update_meta($sid, 'completed');
                            }
                            $recovered_here++;
                        }
                        // Remove lease file
                        $lease_file = is_string($scan_dir)
                            ? $this->get_lease_file_path($sid, $unit->get_work_id())
                            : null;
                        if ($lease_file) {
                            @unlink($lease_file);
                        }
                        $stale_reason = $is_lease_expired ? 'lease_expired' : 'heartbeat_stale';
                        clean_sweep_log_message("WorkQueue: Stale completed unit {$unit->get_work_id()} moved to completed/ (reason: {$stale_reason})", 'info');
                        continue;
                    }

                    // Release back to queue
                    $unit->release();

                    $queue_file = is_string($scan_dir)
                        ? $this->get_queue_file_path($sid, $unit->get_work_id())
                        : null;

                    if ($queue_file) {
                        @file_put_contents($queue_file, json_encode($unit->to_array(), self::JSON_FLAGS), LOCK_EX);
                    }

                    @unlink($file);

                    // Remove lease file
                    $lease_file = is_string($scan_dir)
                        ? $this->get_lease_file_path($sid, $unit->get_work_id())
                        : null;
                    if ($lease_file) {
                        @unlink($lease_file);
                    }

                    $recovered_here++;
                    $stale_reason = $is_lease_expired ? 'lease_expired' : 'heartbeat_stale';
                    clean_sweep_log_message("WorkQueue: Recovered stale lease for {$unit->get_work_id()} (reason: {$stale_reason}, last_heartbeat: " . ($unit->get_last_heartbeat_at() ? date('Y-m-d H:i:s', $unit->get_last_heartbeat_at()) : 'never') . ")", 'info');
                }
            }
            $this->last_recovery_at[$throttle_key] = [
                'at' => time(),
                'recovered' => $recovered_here,
            ];
            $recovered += $recovered_here;
        }

        return $recovered;
    }

    /**
     * Get heartbeat stale threshold.
     *
     * @return int Threshold in seconds
     */
    public function get_heartbeat_stale_threshold() {
        return $this->heartbeat_stale_threshold;
    }

    /**
     * Set heartbeat stale threshold.
     *
     * @param int $threshold Seconds since last heartbeat to consider stale
     * @return self
     */
    public function set_heartbeat_stale_threshold($threshold) {
        $this->heartbeat_stale_threshold = max(30, (int)$threshold);
        return $this;
    }

    /**
     * Get a specific work unit by ID.
     *
     * @param string $work_id
     * @return CleanSweep_ScanWorkUnit|null
     */
    public function get($work_id) {
        $info = $this->find_work_unit($work_id);
        if ($info === null) {
            return null;
        }

        $scan_id = $info['scan_id'];
        $file = $info['path'];

        if (!file_exists($file)) {
            return null;
        }

        $data = @file_get_contents($file);
        if ($data === false) {
            return null;
        }

        return CleanSweep_ScanWorkUnit::from_array(json_decode($data, true));
    }

    /**
     * Move a failed unit to the dead letter queue.
     *
     * @param string $work_id
     * @return bool
     */
    public function move_to_dead_letter($work_id) {
        $info = $this->find_work_unit($work_id);
        if ($info === null) {
            return false;
        }

        $scan_id = $info['scan_id'];
        $source = $info['path'];
        $dest_dir = $this->get_scan_dir($scan_id) . self::DEAD_LETTER_DIR;

        if (!is_dir($dest_dir)) {
            @mkdir($dest_dir, 0755, true);
        }

        $dest = $dest_dir . '/' . basename($source);
        @rename($source, $dest);

        // Remove lease file if exists
        $lease_file = $this->get_lease_file_path($scan_id, $work_id);
        @unlink($lease_file);

        // Update index
        $this->update_index($scan_id, $work_id, 'dead_letter');

        clean_sweep_log_message("WorkQueue: Moved {$work_id} to dead letter queue", 'info');

        return true;
    }

    /**
     * Clear all work units for a scan.
     *
     * @param string $scan_id
     * @return bool
     */
    public function clear_scan($scan_id) {
        $scan_dir = $this->get_scan_dir($scan_id);

        if (!is_dir($scan_dir)) {
            return true;
        }

        // Remove all subdirectories and files
        $dirs = [self::QUEUE_DIR, self::IN_FLIGHT_DIR, self::COMPLETED_DIR, self::DEAD_LETTER_DIR];

        foreach ($dirs as $dir) {
            $path = $scan_dir . $dir;
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . '/wu_*.json');
            foreach ($files as $file) {
                @unlink($file);
            }

            $leases = glob($path . '/wu_*.lease');
            foreach ($leases as $file) {
                @unlink($file);
            }
        }

        // Remove index and meta
        @unlink($scan_dir . self::INDEX_FILE);
        @unlink($scan_dir . self::META_FILE);

        // Remove scan directory if empty
        @rmdir($scan_dir);

        clean_sweep_log_message("WorkQueue: Cleared all work units for scan {$scan_id}", 'info');

        return true;
    }

    /**
     * Find a work unit's location by scanning directories directly.
     * This is more reliable than index lookup which can be stale.
     *
     * @param string $work_id
     * @return array|null ['scan_id' => ..., 'path' => ...] or null
     */
    private function find_work_unit($work_id) {
        // Direct filesystem scan - always reliable, no index needed.
        // Note: scan_id prefix can be any string (e.g. "scan_", "bg_", "integ_")
        // - match ALL immediate subdirectories of base_dir, not just "scan_*".
        $scan_dirs = glob($this->base_dir . '*', GLOB_ONLYDIR);
        if (!is_array($scan_dirs)) {
            return null;
        }
        foreach ($scan_dirs as $scan_dir) {
            // Skip the per-state directories themselves (queue, in_flight, etc.)
            $basename = basename($scan_dir);
            if (in_array($basename, [self::QUEUE_DIR, self::IN_FLIGHT_DIR, self::COMPLETED_DIR, self::DEAD_LETTER_DIR], true)) {
                continue;
            }
            $sid = $basename;

            foreach ([self::QUEUE_DIR, self::IN_FLIGHT_DIR, self::COMPLETED_DIR, self::DEAD_LETTER_DIR] as $dir) {
                $path = $scan_dir . '/' . $dir . '/' . $work_id . '.json';
                if (file_exists($path)) {
                    return ['scan_id' => $sid, 'path' => $path];
                }
            }
        }

        return null;
    }

    /**
     * Get queue file path.
     *
     * @param string $scan_id
     * @param string $work_id
     * @return string
     */
    private function get_queue_file_path($scan_id, $work_id) {
        return $this->get_scan_dir($scan_id) . self::QUEUE_DIR . '/' . $work_id . '.json';
    }

    /**
     * Get in-flight file path.
     *
     * @param string $scan_id
     * @param string $work_id
     * @return string
     */
    private function get_in_flight_file_path($scan_id, $work_id) {
        return $this->get_scan_dir($scan_id) . self::IN_FLIGHT_DIR . '/' . $work_id . '.json';
    }

    /**
     * Get lease file path.
     *
     * @param string $scan_id
     * @param string $work_id
     * @return string
     */
    private function get_lease_file_path($scan_id, $work_id) {
        return $this->get_scan_dir($scan_id) . self::IN_FLIGHT_DIR . '/' . $work_id . '.lease';
    }

    /**
     * Update the index file.
     *
     * @param string $scan_id
     * @param string $work_id
     * @param string $action 'add', 'claim', 'complete', 'fail', 'dead_letter'
     */
    private function update_index($scan_id, $work_id, $action) {
        if (!isset($this->index_cache[$scan_id])) {
            $this->index_cache[$scan_id] = $this->load_index($scan_id);
        }
        $index =& $this->index_cache[$scan_id];

        if ($action === 'add') {
            $index[$work_id] = [
                'scan_id' => $scan_id,
                'dir' => self::QUEUE_DIR,
                'added_at' => time(),
            ];
        } elseif ($action === 'claim') {
            if (isset($index[$work_id])) {
                $index[$work_id]['dir'] = self::IN_FLIGHT_DIR;
            }
        } elseif ($action === 'complete' || $action === 'fail') {
            if (isset($index[$work_id])) {
                $index[$work_id]['dir'] = self::IN_FLIGHT_DIR; // Keep in in_flight until cleaned
            }
        } elseif ($action === 'dead_letter') {
            if (isset($index[$work_id])) {
                $index[$work_id]['dir'] = self::DEAD_LETTER_DIR;
            }
        }

        $this->index_dirty[$scan_id] = true;

        if (!$this->batch_mode) {
            $this->flush_index($scan_id);
        }
    }

    private function flush_index(string $scan_id): void {
        if (empty($this->index_dirty[$scan_id]) || !isset($this->index_cache[$scan_id])) {
            return;
        }
        $index_file = $this->get_scan_dir($scan_id) . self::INDEX_FILE;
        // Compact JSON — no PRETTY_PRINT on hot path
        @file_put_contents(
            $index_file,
            json_encode($this->index_cache[$scan_id], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        $this->index_dirty[$scan_id] = false;
    }

    /**
     * Load index file for a specific scan.
     * Note: Each scan has its own index file, keyed by work_id within that scan.
     * The scan_id must be passed since load_index is called after we know which scan we're operating on.
     *
     * @param string|null $scan_id Scan ID to load index for (required)
     * @return array Index data keyed by work_id
     */
    private function load_index($scan_id = null) {
        if ($scan_id === null) {
            // Fallback: try to find the scan_id from the base directory structure
            // This is inefficient but needed for update_index calls where scan_id isn't passed
            $scan_dirs = $this->get_scan_dirs();
            $combined_index = [];
            foreach ($scan_dirs as $scan_dir) {
                $index_file = $scan_dir . '/' . self::INDEX_FILE;
                if (file_exists($index_file)) {
                    $data = @file_get_contents($index_file);
                    if ($data !== false) {
                        $partial = json_decode($data, true);
                        if (is_array($partial)) {
                            $combined_index = array_merge($combined_index, $partial);
                        }
                    }
                }
            }
            return $combined_index;
        }

        $index_file = $this->get_scan_dir($scan_id) . '/' . self::INDEX_FILE;

        if (!file_exists($index_file)) {
            return [];
        }

        $data = @file_get_contents($index_file);
        if ($data === false) {
            return [];
        }

        $index = json_decode($data, true);
        return is_array($index) ? $index : [];
    }

    /**
     * Update meta file with queue statistics.
     *
     * @param string $scan_id
     * @param string $event Event type
     * @param array $extra Optional extra data to merge (e.g., items_processed)
     */
    private function load_meta_raw(string $scan_id): array {
        $meta_file = $this->get_scan_dir($scan_id) . self::META_FILE;
        if (!file_exists($meta_file)) {
            return [];
        }
        $data = @file_get_contents($meta_file);
        if ($data === false) {
            return [];
        }
        $meta = json_decode($data, true);
        return is_array($meta) ? $meta : [];
    }

    private function update_meta($scan_id, $event, $extra = []) {
        if (!isset($this->meta_cache[$scan_id])) {
            $this->meta_cache[$scan_id] = $this->load_meta_raw($scan_id);
        }
        $meta =& $this->meta_cache[$scan_id];

        // Update counters
        if (!isset($meta['counters'])) {
            $meta['counters'] = ['enqueued' => 0, 'claimed' => 0, 'completed' => 0, 'failed' => 0, 'dead' => 0, 'retry' => 0];
        }

        if ($this->batch_mode && $event === 'enqueued') {
            // Defer counter bump to flush — avoid rewriting meta per unit
            $this->meta_enqueued_delta[$scan_id] = ($this->meta_enqueued_delta[$scan_id] ?? 0) + 1;
            $this->meta_dirty[$scan_id] = true;
            return;
        }

        if (isset($meta['counters'][$event])) {
            $meta['counters'][$event]++;
        }

        // Merge extra data, but NEVER allow items_processed to regress.
        // This protects against stale snapshots, partial merges, and any other
        // caller that passes an $extra with a smaller items_processed value
        // than what's already on disk. We take the max, not the overwrite.
        if (!empty($extra)) {
            $existing_items = (int)($meta['items_processed'] ?? 0);
            $extra_items    = (int)($extra['items_processed'] ?? -1);
            $extra_last_progress = (int)($extra['last_progress_at'] ?? 0);

            // Always preserve the highest known items_processed and most recent
            // last_progress_at we've ever seen, across all writers.
            $effective_items = max($existing_items, $extra_items >= 0 ? $extra_items : $existing_items);
            $effective_last_progress = max(
                (int)($meta['last_progress_at'] ?? 0),
                $extra_last_progress
            );

            $meta = array_merge($meta, $extra);
            $meta['items_processed'] = $effective_items;
            if ($effective_last_progress > 0) {
                $meta['last_progress_at'] = $effective_last_progress;
            }
        }

        $meta['last_event'] = $event;
        $meta['last_event_at'] = time();
        $this->meta_dirty[$scan_id] = true;

        if (!$this->batch_mode) {
            $this->flush_meta($scan_id);
        }
    }

    private function flush_meta(string $scan_id): void {
        if (empty($this->meta_dirty[$scan_id]) && empty($this->meta_enqueued_delta[$scan_id])) {
            return;
        }
        if (!isset($this->meta_cache[$scan_id])) {
            $this->meta_cache[$scan_id] = $this->load_meta_raw($scan_id);
        }
        $meta =& $this->meta_cache[$scan_id];
        if (!isset($meta['counters'])) {
            $meta['counters'] = ['enqueued' => 0, 'claimed' => 0, 'completed' => 0, 'failed' => 0, 'dead' => 0, 'retry' => 0];
        }
        $delta = (int)($this->meta_enqueued_delta[$scan_id] ?? 0);
        if ($delta > 0) {
            $meta['counters']['enqueued'] = (int)($meta['counters']['enqueued'] ?? 0) + $delta;
            $this->meta_enqueued_delta[$scan_id] = 0;
        }
        $meta['last_event'] = $meta['last_event'] ?? 'enqueued';
        $meta['last_event_at'] = time();

        $meta_file = $this->get_scan_dir($scan_id) . self::META_FILE;
        if (!@file_put_contents($meta_file, json_encode($meta, JSON_UNESCAPED_SLASHES), LOCK_EX)) {
            clean_sweep_log_message("WorkQueue: Failed to write meta file {$meta_file}", 'error');
        }
        $this->meta_dirty[$scan_id] = false;
    }

    /**
     * Increment items_processed counter for progress visibility.
     * This allows partial progress to be visible even before unit completion.
     * Called during unit execution to track incremental progress.
     *
     * @param string $scan_id Scan ID
     * @param int $count Number of items processed in this batch
     * @return bool Success
     */
    public function increment_items_processed($scan_id, $count = 1) {
        if ($count <= 0) return true;

        // Accumulate in memory; flush at most every 2s or every N items.
        // Mid-unit UI latency of 1–2s is invisible; disk I/O is not.
        $this->items_processed_pending[$scan_id] =
            ($this->items_processed_pending[$scan_id] ?? 0) + $count;

        if (!isset($this->meta_cache[$scan_id])) {
            $this->meta_cache[$scan_id] = $this->load_meta_raw($scan_id);
        }
        // Keep cache optimistic so same-request readers see progress.
        $this->meta_cache[$scan_id]['items_processed'] =
            (int)($this->meta_cache[$scan_id]['items_processed'] ?? 0) + $count;
        $this->meta_cache[$scan_id]['last_progress_at'] = time();

        $pending = (int)$this->items_processed_pending[$scan_id];
        $last = (int)($this->items_processed_flushed_at[$scan_id] ?? 0);
        $now = time();
        if ($pending >= self::ITEMS_FLUSH_EVERY_N || ($now - $last) >= self::ITEMS_FLUSH_INTERVAL_SEC) {
            return $this->flush_items_processed($scan_id);
        }
        return true;
    }

    /**
     * Force-flush coalesced items_processed to meta.json.
     * Call at end of each work unit so the final count is never stranded in memory.
     */
    public function flush_items_processed(string $scan_id): bool {
        $pending = (int)($this->items_processed_pending[$scan_id] ?? 0);
        if ($pending <= 0) {
            $this->items_processed_flushed_at[$scan_id] = time();
            return true;
        }

        // Re-read disk so we never regress under concurrent writers.
        $disk = $this->load_meta_raw($scan_id);
        $disk_items = (int)($disk['items_processed'] ?? 0);
        $prop = $disk_items + $pending;

        $meta = $disk;
        if (isset($this->meta_cache[$scan_id]) && is_array($this->meta_cache[$scan_id])) {
            // Preserve other in-request meta fields, but items_processed is disk+pending.
            $meta = array_merge($disk, $this->meta_cache[$scan_id]);
        }
        $meta['items_processed'] = $prop;
        $meta['last_progress_at'] = time();
        $this->meta_cache[$scan_id] = $meta;

        $meta_file = $this->get_scan_dir($scan_id) . self::META_FILE;
        if (!@file_put_contents($meta_file, json_encode($meta, self::JSON_FLAGS), LOCK_EX)) {
            clean_sweep_log_message("WorkQueue: Failed to write meta file for items_processed update", 'warning');
            return false;
        }

        $this->items_processed_pending[$scan_id] = 0;
        $this->items_processed_flushed_at[$scan_id] = time();
        $this->meta_dirty[$scan_id] = false;
        return true;
    }

    /**
     * Continue a claimed unit with a new payload instead of complete+enqueue.
     * Rewrites the same work_id back to queue/ (one file move, no new unit id).
     * Does not bump the completed counter.
     *
     * @param string $work_id
     * @param array $new_payload Replacement payload for the next claim
     * @param string $scan_id
     * @param array $partial_result Optional summary retained on the unit for debugging
     * @return bool
     */
    public function continue_unit(string $work_id, array $new_payload, string $scan_id, array $partial_result = []): bool {
        $in_flight_file = $this->get_in_flight_file_path($scan_id, $work_id);
        $queue_file = $this->get_queue_file_path($scan_id, $work_id);
        $lease_file = $this->get_lease_file_path($scan_id, $work_id);

        if (!file_exists($in_flight_file)) {
            return false;
        }
        $data = @file_get_contents($in_flight_file);
        if ($data === false) {
            return false;
        }
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return false;
        }

        $unit = CleanSweep_ScanWorkUnit::from_array($decoded);
        $unit->set_payload($new_payload);
        if (!empty($partial_result)) {
            $unit->set_result_summary($partial_result);
        }
        // Back to pending so claim_next can pick it up again (same work_id).
        $unit->release();

        if (@file_put_contents($queue_file, json_encode($unit->to_array(), self::JSON_FLAGS), LOCK_EX) === false) {
            clean_sweep_log_message("WorkQueue: continue_unit failed to write queue file for {$work_id}", 'error');
            return false;
        }
        @unlink($in_flight_file);
        @unlink($lease_file);
        $this->update_index($scan_id, $work_id, 'retry');
        // Flush any coalesced progress before the unit is reclaimed.
        $this->flush_items_processed($scan_id);

        clean_sweep_log_message("WorkQueue: Continued {$work_id} with new payload (no new unit file)", 'debug');
        return true;
    }

    /**
     * Cleanup old completed and dead work units.
     * Call periodically to prevent filesystem bloat.
     *
     * @param string|null $scan_id Optional scan ID to limit cleanup
     * @param int $max_age_seconds Units older than this will be deleted (default: 7 days)
     * @return array ['deleted' => int, 'freed_bytes' => int]
     */
    public function cleanup($scan_id = null, $max_age_seconds = 604800) {
        $deleted = 0;
        $freed_bytes = 0;
        $cutoff_time = time() - $max_age_seconds;

        $base_path = $scan_id !== null
            ? [$this->get_scan_dir($scan_id)]
            : $this->get_scan_dirs();

        if (empty($base_path)) {
            return ['deleted' => 0, 'freed_bytes' => 0];
        }

        foreach ($base_path as $scan_dir) {
            $sid = is_string($scan_dir) ? basename($scan_dir) : null;

            // Clean completed units (in in_flight dir after completion)
            $this->cleanup_directory(
                $scan_dir . '/' . self::IN_FLIGHT_DIR,
                $cutoff_time,
                $deleted,
                $freed_bytes
            );

            // Clean dead letter queue
            $this->cleanup_directory(
                $scan_dir . '/' . self::DEAD_LETTER_DIR,
                $cutoff_time,
                $deleted,
                $freed_bytes
            );
        }

        clean_sweep_log_message("WorkQueue: Cleanup completed - deleted {$deleted} units, freed {$freed_bytes} bytes", 'info');

        return ['deleted' => $deleted, 'freed_bytes' => $freed_bytes];
    }

    /**
     * Cleanup files in a directory older than cutoff time.
     *
     * @param string $dir Directory path
     * @param int $cutoff_time Unix timestamp
     * @param int &$deleted Reference to deleted counter
     * @param int &$freed_bytes Reference to freed bytes counter
     */
    private function cleanup_directory($dir, $cutoff_time, &$deleted, &$freed_bytes) {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/wu_*.json');
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff_time) {
                $size = @filesize($file);
                if (@unlink($file)) {
                    $deleted++;
                    $freed_bytes += $size;
                }
            }
        }

        // Also cleanup lease files
        $lease_files = glob($dir . '/wu_*.lease');
        foreach ($lease_files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff_time) {
                @unlink($file);
            }
        }
    }
}