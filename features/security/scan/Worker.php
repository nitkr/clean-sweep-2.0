<?php
/**
 * Clean Sweep - CleanSweep_Worker (interface) and CleanSweep_WorkerContext
 *
 * The boundary between "what the orchestrator does" and "what actually
 * happens for a work unit".
 *
 * A CleanSweep_Worker takes a WorkUnit + CleanSweep_WorkerContext and returns a CleanSweep_WorkerResult.
 * It does NOT:
 *   - touch the work queue (claim/complete/fail are the orchestrator's job)
 *   - touch the checkpoint file (use ctx->checkpoint()->merge())
 *   - schedule kicks (that's the CleanSweep_Scheduler's job)
 *   - decide whether to pause (return status='more_work_available' and let
 *     the orchestrator decide)
 *
 * This inversion of control is what lets the orchestrator be the only
 * place that knows about time budgets, lease recovery, and kick storms.
 *
 * @since CleanSweep_Scanner v2
 */

/**
 * Result returned by a CleanSweep_Worker after executing one work unit.
 */
final class CleanSweep_WorkerResult {

    /** @var string One of: 'completed' | 'more_work_available' | 'failed' */
    public string $status;

    /** @var array Free-form result payload. Used by Finalize worker, mostly ignored. */
    public array $data;

    /** @var string|null Optional error message when status='failed' */
    public ?string $error;

    public function __construct(string $status, array $data = [], ?string $error = null) {
        $this->status = $status;
        $this->data = $data;
        $this->error = $error;
    }

    public static function completed(array $data = []): self {
        return new self('completed', $data, null);
    }

    public static function moreWork(array $data = []): self {
        return new self('more_work_available', $data, null);
    }

    public static function failed(string $error, array $data = []): self {
        return new self('failed', $data, $error);
    }
}

/**
 * Context passed to a CleanSweep_Worker during run(). Hides the storage plumbing
 * (queue, checkpoint, threat store) behind a narrow interface.
 */
interface CleanSweep_WorkerContext {
    /**
     * The scan's current state (in-memory copy from the start of this run()).
     * Workers should NOT mutate this directly; use merge() to persist changes.
     */
    public function state(): CleanSweep_ScanState;

    /**
     * Merge a partial state update. Will be persisted on drain-loop boundaries.
     * @param array $partial
     */
    public function mergeState(array $partial): void;

    /**
     * Record a threat.
     * @param array $threat
     */
    public function recordThreat(array $threat): void;

    /**
     * Update the global counter (cumulative, monotonic).
     * @param string $key One of: 'files_scanned', 'db_rows_scanned', 'threats_found'
     * @param int $delta How much to add (always positive)
     */
    public function incrementCounter(string $key, int $delta): void;

    /**
     * Cooperative cancel/time-budget check. Returns true if the worker
     * should abort its current batch and return more_work_available.
     */
    public function shouldStop(): bool;

    /**
     * The CPU governor. Workers should call $this->throttle()->file_yield()
     * between files (and $this->throttle()->micro_yield() between signatures
     * in hot loops).
     */
    public function throttle(): CleanSweep_CpuGovernor;

    /**
     * Current profile in use.
     */
    public function profile(): CleanSweep_ScanProfile;

    /**
     * Report progress to the UI.
     */
    public function progress(int $current, int $total, string $message): void;
}

/**
 * The CleanSweep_Worker interface. One implementation per work-unit type.
 */
interface CleanSweep_Worker {
    /**
     * The work-unit type this worker handles. Must match a value in
     * CleanSweep_ScanWorkUnit::TYPE_*.
     */
    public function type(): string;

    /**
     * Execute one work unit.
     *
     * @param array $payload The unit's payload (decoded JSON)
     * @param CleanSweep_WorkerContext $ctx
     * @return CleanSweep_WorkerResult
     */
    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult;
}
