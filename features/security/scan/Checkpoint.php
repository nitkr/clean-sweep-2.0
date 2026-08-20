<?php
/**
 * Clean Sweep - CleanSweep_Checkpoint (typed facade over CleanSweep_ScanCheckpoint)
 *
 * The single storage interface for CleanSweep_ScanState.
 * Wraps the file-based CleanSweep_ScanCheckpoint with a typed API.
 *
 * All state lives in one file:
 *   logs/checkpoint_{scan_id}.json
 *
 * @since CleanSweep_Scanner v2
 */

if (!class_exists('CleanSweep_ScanCheckpoint', false)) {
    require_once dirname(__DIR__) . '/ScanCheckpoint.php';
}
if (!class_exists('CleanSweep_ScanState', false)) {
    require_once __DIR__ . '/ScanState.php';
}

final class CleanSweep_Checkpoint {

    /** @var CleanSweep_ScanCheckpoint Underlying storage */
    private $impl;

    public function __construct(string $scan_id) {
        $this->impl = new CleanSweep_ScanCheckpoint($scan_id);
    }

    /**
     * Get the scan_id this checkpoint is bound to.
     */
    public function scanId(): string {
        return $this->impl->get_scan_id();
    }

    /**
     * Load the current state, or null if no checkpoint exists.
     */
    public function load(): ?CleanSweep_ScanState {
        $raw = $this->impl->load();
        if ($raw === null) return null;
        $raw['scan_id'] = $raw['scan_id'] ?? $this->impl->get_scan_id();
        return CleanSweep_ScanState::fromArray($raw);
    }

    /**
     * Whether the checkpoint file exists on disk (even if currently unreadable).
     */
    public function exists(): bool {
        return $this->impl->exists();
    }

    /**
     * Save a full state (overwrites).
     */
    public function save(CleanSweep_ScanState $state): void {
        $state->last_updated = time();
        $this->impl->save($state->toArray());
    }

    /**
     * Merge a partial update into the existing state, then save.
     * Atomic read-modify-write. Cheap (one file read + one file write).
     *
     * If the file exists but cannot be loaded (transient read race), skip the
     * merge rather than overwriting with a blank CleanSweep_ScanState.
     *
     * @param array $partial Key-value pairs to merge into the loaded state.
     *                       Only known fields on CleanSweep_ScanState are applied.
     */
    /**
     * @return bool True if the merge was persisted; false if skipped (transient load failure).
     */
    public function merge(array $partial): bool {
        $current = $this->load();
        if ($current === null) {
            if ($this->impl->exists()) {
                // File present but unreadable right now — do not wipe it.
                if (function_exists('clean_sweep_log_message')) {
                    clean_sweep_log_message(
                        'CleanSweep_Checkpoint: merge skipped (file exists but load failed) for ' . $this->scanId(),
                        'warning'
                    );
                }
                return false;
            }
            $current = new CleanSweep_ScanState();
        }
        return $this->mergeInto($current, $partial);
    }

    /**
     * Apply partial onto an already-loaded state and save (single RMW without re-load).
     *
     * @return bool Always true after save attempt (callers treat save as best-effort).
     */
    public function mergeInto(CleanSweep_ScanState $current, array $partial): bool {
        $current->scan_id = $current->scan_id ?? $this->impl->get_scan_id();

        // Ensure total_files_estimate is monotonic (never decreases).
        // This prevents progress oscillation when multiple discovery units
        // run in the same drain iteration with stale state snapshots.
        if (isset($partial['total_files_estimate'])) {
            $partial['total_files_estimate'] = max(
                $current->total_files_estimate ?? 0,
                $partial['total_files_estimate']
            );
        }

        // Never wipe a known last path with null/empty (empty FILE_BATCH units
        // and differential-skip batches used to clear Technical details).
        foreach (['last_file_path', 'last_db_table'] as $path_key) {
            if (!array_key_exists($path_key, $partial)) {
                continue;
            }
            $val = $partial[$path_key];
            if ($val === null || $val === '') {
                unset($partial[$path_key]);
            }
        }

        if ($partial === []) {
            return true;
        }

        $next = $current->with($partial);
        // Skip no-op writes when with() would only bump last_updated.
        $before = $current->toArray();
        $after = $next->toArray();
        unset($before['last_updated'], $after['last_updated']);
        if ($before === $after) {
            return true;
        }

        $this->save($next);
        return true;
    }

    /**
     * Delete the checkpoint file. Used by cancel/cleanup.
     */
    public function delete(): void {
        $this->impl->clear();
    }

    /**
     * Find the most recent resumable checkpoint for a profile.
     * Used for manual resume.
     *
     * @param string $profile_id
     * @param array $options
     * @return CleanSweep_ScanState|null
     */
    public static function findLatestResumable(string $profile_id, array $options = []): ?CleanSweep_ScanState {
        $raw = CleanSweep_ScanCheckpoint::find_latest_resumable_checkpoint($profile_id, $options);
        if ($raw === null) return null;
        return CleanSweep_ScanState::fromArray($raw);
    }

    /**
     * Latest scan suitable for UI reattach after refresh (active or recent complete).
     *
     * @param int $completed_ttl_seconds
     * @return CleanSweep_ScanState|null
     */
    public static function findLatestForUi(int $completed_ttl_seconds = 172800): ?CleanSweep_ScanState {
        $raw = CleanSweep_ScanCheckpoint::find_latest_scan_for_ui($completed_ttl_seconds);
        if ($raw === null) {
            return null;
        }
        return CleanSweep_ScanState::fromArray($raw);
    }
}
