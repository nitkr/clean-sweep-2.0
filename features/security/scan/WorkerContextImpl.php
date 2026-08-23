<?php
/**
 * Clean Sweep - CleanSweep_Worker Context (concrete implementation)
 *
 * The implementation of CleanSweep_WorkerContext that the new Workers see.
 * Wraps a CleanSweep_Checkpoint + CleanSweep_ThreatStore + CpuGovernor + Profile + the
 * work-queue reference (for incrementing items_processed and
 * enqueueing follow-on units when a worker says "more work available").
 *
 * Workers do NOT call into the orchestrator directly. They return
 * CleanSweep_WorkerResult::moreWork() and the orchestrator's drain loop
 * enqueues any follow-on units.
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_WorkerContextImpl implements CleanSweep_WorkerContext {

    /** @var CleanSweep_ScanState State snapshot at the start of this run() */
    private CleanSweep_ScanState $state;

    /** @var CleanSweep_Checkpoint */
    private CleanSweep_Checkpoint $checkpoint;

    /** @var CleanSweep_ThreatStore */
    private CleanSweep_ThreatStore $threats;

    /** @var CleanSweep_CpuGovernor */
    private CleanSweep_CpuGovernor $throttle;

    /** @var CleanSweep_ScanProfile */
    private CleanSweep_ScanProfile $profile;

    /** @var CleanSweep_FileBasedScanWorkQueue */
    private CleanSweep_FileBasedScanWorkQueue $queue;

    /** @var int Scan start time (used for elapsed_time_sec in pause_point) */
    private int $started_at;

    /** @var int Unix time of last shouldStop() disk read */
    private int $last_stop_check_at = 0;

    /** @var bool Cached shouldStop result */
    private bool $cached_should_stop = false;

    /** @var bool Whether a checkpoint load has succeeded at least once */
    private bool $stop_check_ever_succeeded = false;

    /** @var array Pending checkpoint fields awaiting a coalesced flush */
    private array $pending_merge = [];

    /** @var array<string,int> Pending counter deltas awaiting a coalesced flush */
    private array $pending_counters = [];

    /** @var int Unix time of last coalesced checkpoint flush */
    private int $last_flush_at = 0;

    /** @var CleanSweep_DifferentialScanner|null Drain-scoped differential scanner */
    private $shared_differential = null;

    /** @var array|null Drain-scoped signature list */
    private $shared_signatures = null;

    /** @var CleanSweep_SignaturePreFilter|null Drain-scoped prefilter */
    private $shared_prefilter = null;

    public function __construct(
        CleanSweep_ScanState $state,
        CleanSweep_Checkpoint $checkpoint,
        CleanSweep_ThreatStore $threats,
        CleanSweep_CpuGovernor $throttle,
        CleanSweep_ScanProfile $profile,
        CleanSweep_FileBasedScanWorkQueue $queue,
        int $started_at
    ) {
        $this->state = $state;
        $this->checkpoint = $checkpoint;
        $this->threats = $threats;
        $this->throttle = $throttle;
        $this->profile = $profile;
        $this->queue = $queue;
        $this->started_at = $started_at;
    }

    /**
     * Attach drain-scoped reusable resources (built once per drainLocked).
     */
    public function setDrainResources(
        ?CleanSweep_DifferentialScanner $differential = null,
        ?array $signatures = null,
        $prefilter = null
    ): void {
        $this->shared_differential = $differential;
        $this->shared_signatures = $signatures;
        $this->shared_prefilter = $prefilter;
    }

    public function sharedDifferential(): ?CleanSweep_DifferentialScanner {
        return $this->shared_differential;
    }

    public function sharedSignatures(): ?array {
        return $this->shared_signatures;
    }

    public function sharedPrefilter() {
        return $this->shared_prefilter;
    }

    public function setSharedPrefilter($prefilter): void {
        $this->shared_prefilter = $prefilter;
    }

    public function state(): CleanSweep_ScanState {
        return $this->state;
    }

    public function mergeState(array $partial): void {
        // Update the in-memory snapshot immediately so subsequent calls see fresh values.
        foreach ($partial as $k => $v) {
            if (property_exists($this->state, $k)) {
                $this->state->$k = $v;
            }
        }
        foreach ($partial as $k => $v) {
            $this->pending_merge[$k] = $v;
        }
        // Flush at most every 2s so UI last_file_path stays fresh without per-call RMW.
        $now = time();
        if (($now - $this->last_flush_at) >= 2) {
            $this->flushPending();
        }
    }

    public function recordThreat(array $threat): void {
        $this->threats->append($threat);
        // Increment the in-memory counter (caller is responsible for
        // persisting via mergeState if they need it in the checkpoint).
        $this->state->threats_found++;
    }

    public function incrementCounter(string $key, int $delta): void {
        if ($delta === 0) {
            return;
        }
        if ($key === 'items_processed') {
            $this->queue->increment_items_processed($this->state->scan_id, $delta);
            return;
        }

        // Coalesce counter bumps; flushPending() does one authoritative load+save.
        $this->pending_counters[$key] = ($this->pending_counters[$key] ?? 0) + $delta;
        if (property_exists($this->state, $key)) {
            $this->state->$key += $delta;
        }
    }

    /**
     * Persist coalesced counter/merge updates in a single checkpoint RMW.
     * Call at end of each work unit (and periodically from mergeState).
     */
    public function flushPending(): void {
        if (empty($this->pending_counters) && empty($this->pending_merge)) {
            $this->last_flush_at = time();
            return;
        }

        $current = $this->checkpoint->load();
        if ($current === null) {
            // Transient mid-write or missing file — keep pending for retry.
            // Do NOT clear pending_* here.
            if (!$this->checkpoint->exists()) {
                // CleanSweep_Checkpoint gone (cancelled/cleared): drop pending; nothing to persist.
                $this->pending_counters = [];
                $this->pending_merge = [];
            }
            return;
        }

        $partial = $this->pending_merge;
        foreach ($this->pending_counters as $key => $delta) {
            switch ($key) {
                case 'files_scanned':
                    $partial['files_scanned'] = $current->files_scanned + (int)$delta;
                    $this->state->files_scanned = $partial['files_scanned'];
                    break;
                case 'files_visited':
                    $partial['files_visited'] = $current->files_visited + (int)$delta;
                    $this->state->files_visited = $partial['files_visited'];
                    break;
                case 'files_skipped_unchanged':
                    $partial['files_skipped_unchanged'] = $current->files_skipped_unchanged + (int)$delta;
                    $this->state->files_skipped_unchanged = $partial['files_skipped_unchanged'];
                    break;
                case 'db_rows_scanned':
                    $partial['db_rows_scanned'] = $current->db_rows_scanned + (int)$delta;
                    $this->state->db_rows_scanned = $partial['db_rows_scanned'];
                    break;
                case 'threats_found':
                    $partial['threats_found'] = $current->threats_found + (int)$delta;
                    $this->state->threats_found = $partial['threats_found'];
                    break;
                case 'integrity_violations':
                    $partial['integrity_violations'] = $current->integrity_violations + (int)$delta;
                    $this->state->integrity_violations = $partial['integrity_violations'];
                    break;
                default:
                    break;
            }
        }

        if (empty($partial)) {
            $this->pending_counters = [];
            $this->pending_merge = [];
            $this->last_flush_at = time();
            return;
        }

        // Single RMW via already-loaded state — only clear pending after success.
        if ($this->checkpoint->mergeInto($current, $partial)) {
            $this->pending_counters = [];
            $this->pending_merge = [];
            $this->last_flush_at = time();
        }
        // Always flush coalesced items_processed so end-of-unit counts hit disk.
        $this->queue->flush_items_processed($this->state->scan_id);
    }

    public function shouldStop(): bool {
        $now = time();
        // Mirror CleanSweep_Scanner::drainLocked's 5s cancel throttle — avoids a full
        // checkpoint load+decode on every file in the hot loop.
        if ($this->last_stop_check_at > 0 && ($now - $this->last_stop_check_at) < 5) {
            return $this->cached_should_stop;
        }
        $this->last_stop_check_at = $now;
        $current = $this->checkpoint->load();
        if ($current === null) {
            // Missing file → stop. Unreadable but present → keep prior answer
            // (or stop if we never successfully loaded).
            if (!$this->checkpoint->exists()) {
                $this->cached_should_stop = true;
                return true;
            }
            return $this->stop_check_ever_succeeded ? $this->cached_should_stop : true;
        }
        $this->stop_check_ever_succeeded = true;
        $this->cached_should_stop = $current->isTerminal();
        return $this->cached_should_stop;
    }

    public function throttle(): CleanSweep_CpuGovernor {
        return $this->throttle;
    }

    public function profile(): CleanSweep_ScanProfile {
        return $this->profile;
    }

    public function progress(int $current, int $total, string $message): void {
        if (function_exists('clean_sweep_log_enabled') && !clean_sweep_log_enabled('debug')) {
            return;
        }
        clean_sweep_log_message("CleanSweep_Worker: {$message} ({$current}/{$total})", 'debug');
    }

    public function startedAt(): int {
        return $this->started_at;
    }

    public function queue(): CleanSweep_FileBasedScanWorkQueue {
        return $this->queue;
    }
}
