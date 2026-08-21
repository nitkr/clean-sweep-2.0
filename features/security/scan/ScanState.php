<?php
/**
 * Clean Sweep - Scan State (value object)
 *
 * The single, typed, documented state schema for a scan.
 * One instance per scan_id. All fields are documented and used.
 *
 * Storage is delegated to CleanSweep_ScanCheckpoint (file-based JSON).
 * This class is the in-memory representation.
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_ScanState {

    // --- Identity ---
    /** @var string|null Scan ID (e.g. "bg_1700000000_a1b2c3d4") */
    public ?string $scan_id = null;

    /** @var string|null Profile ID (quick, standard, deep, custom) */
    public ?string $profile_id = null;

    /** @var string One of: initializing, running, paused, completed, failed, cancelled */
    public string $status = 'initializing';

    // --- Phase ---
    /** @var string One of: initializing, files, database, integrity, analysis, complete */
    public string $phase = 'initializing';

    // --- Timestamps ---
    public ?int $started_at = null;
    public ?int $finished_at = null;
    public ?int $last_updated = null;
    public ?int $resumed_at = null;

    // --- Counters (cumulative, monotonic) ---
    public int $files_scanned = 0;
    public int $db_rows_scanned = 0;
    public int $threats_found = 0;
    public int $integrity_violations = 0;

    /** @var int Soft lower bound, updated by discovery as we go. Do NOT cap progress at this. */
    public int $total_files_estimate = 0;

    // --- Cursors (last position scanned, for resume) ---
    public ?string $last_file_path = null;
    public ?int $last_db_id = null;
    public ?string $last_db_table = null;

    // --- Per-table DB cursors (for resumable DB phase) ---
    /** @var array<string,int> table => last_id_scanned */
    public array $db_cursors = [];

    // --- Pause / resume ---
    public ?string $pause_reason = null;
    public ?int $last_drain_activity_at = null;

    /**
     * When the current drain began. Used by the storm guard to distinguish
     * "drain has been running a while" from "drain just finished quickly".
     * Set once at the start of drain(), not updated during the loop.
     *
     * @var int|null
     */
    public ?int $drain_started_at = null;

    /** @var int Number of times the drain loop was kicked (loopback or cron) */
    public int $kick_count = 0;

    // --- Configuration snapshot (immutable for the lifetime of the scan) ---
    /** @var array */
    public array $options = [];

    // --- Integrity baseline ---
    public bool $has_integrity_baseline = false;
    /** @var array|null Integrity baseline metadata */
    public ?array $integrity_baseline = null;

    /**
     * Build from a raw array (e.g. from checkpoint JSON).
     *
     * @param array $raw
     * @return self
     */
    public static function fromArray(array $raw): self {
        $s = new self();

        $s->scan_id = $raw['scan_id'] ?? null;
        $s->profile_id = $raw['profile_id'] ?? null;
        $s->status = $raw['status'] ?? 'initializing';
        $s->phase = $raw['phase'] ?? 'initializing';

        $s->started_at = $raw['started_at'] ?? null;
        $s->finished_at = $raw['finished_at'] ?? null;
        $s->last_updated = $raw['last_updated'] ?? null;
        $s->resumed_at = $raw['resumed_at'] ?? null;

        // All counter and cursor fields are top-level on the schema.
        // `file_state` (the old wrapper) is read for back-compat with
        // any checkpoints written by the old code, then ignored if
        // the flat top-level field is present.
        $file_state = is_array($raw['file_state'] ?? null) ? $raw['file_state'] : [];
        $s->files_scanned = (int)($raw['files_scanned'] ?? $file_state['files_scanned'] ?? 0);
        $s->total_files_estimate = (int)($raw['total_files_estimate'] ?? $file_state['total_files'] ?? 0);
        $s->last_file_path = $raw['last_file_path'] ?? $file_state['last_file_path'] ?? null;

        $s->db_rows_scanned = (int)($raw['db_rows_scanned'] ?? 0);
        $s->last_db_id = $raw['last_db_id'] ?? null;
        $s->last_db_table = $raw['last_db_table'] ?? null;
        $s->db_cursors = is_array($raw['db_cursors'] ?? null) ? $raw['db_cursors'] : [];

        $s->threats_found = (int)($raw['threats_found'] ?? 0);
        $s->integrity_violations = (int)($raw['integrity_violations'] ?? 0);

        $s->pause_reason = $raw['pause_reason'] ?? null;
        $s->last_drain_activity_at = $raw['last_drain_activity_at'] ?? null;
        $s->drain_started_at = $raw['drain_started_at'] ?? null;
        $s->kick_count = (int)($raw['loopback_kick_count'] ?? 0);

        $s->options = is_array($raw['options'] ?? null) ? $raw['options'] : [];
        $s->has_integrity_baseline = !empty($raw['has_integrity_baseline']);
        $s->integrity_baseline = $raw['integrity_baseline'] ?? null;

        return $s;
    }

    /**
     * Serialize to a raw array for storage.
     * Only includes fields that have non-default values, to keep checkpoint JSON small.
     *
     * @return array
     */
    public function toArray(): array {
        $out = [
            'scan_id' => $this->scan_id,
            'profile_id' => $this->profile_id,
            'status' => $this->status,
            'phase' => $this->phase,
        ];
        if ($this->started_at !== null) $out['started_at'] = $this->started_at;
        if ($this->finished_at !== null) $out['finished_at'] = $this->finished_at;
        if ($this->last_updated !== null) $out['last_updated'] = $this->last_updated;
        if ($this->resumed_at !== null) $out['resumed_at'] = $this->resumed_at;

        // Flat schema: file counter/cursor fields are top-level (not
        // nested in a `file_state` wrapper). The orchestrator's merge()
        // + this class's with() only persist top-level properties, so
        // anything nested here would be silently dropped.
        $out['files_scanned'] = $this->files_scanned;
        $out['total_files_estimate'] = $this->total_files_estimate;
        if ($this->last_file_path !== null) $out['last_file_path'] = $this->last_file_path;

        $out['db_rows_scanned'] = $this->db_rows_scanned;
        if ($this->last_db_id !== null) $out['last_db_id'] = $this->last_db_id;
        if ($this->last_db_table !== null) $out['last_db_table'] = $this->last_db_table;
        if (!empty($this->db_cursors)) $out['db_cursors'] = $this->db_cursors;

        $out['threats_found'] = $this->threats_found;
        $out['integrity_violations'] = $this->integrity_violations;

        if ($this->pause_reason !== null) $out['pause_reason'] = $this->pause_reason;
        if ($this->last_drain_activity_at !== null) $out['last_drain_activity_at'] = $this->last_drain_activity_at;
        if ($this->drain_started_at !== null) $out['drain_started_at'] = $this->drain_started_at;
        if ($this->kick_count > 0) $out['loopback_kick_count'] = $this->kick_count;

        if (!empty($this->options)) $out['options'] = $this->options;
        if ($this->has_integrity_baseline) $out['has_integrity_baseline'] = true;
        if ($this->integrity_baseline !== null) $out['integrity_baseline'] = $this->integrity_baseline;

        return $out;
    }

    /**
     * Apply a partial update (used by CleanSweep_WorkerContext). Returns a new instance.
     *
     * @param array $partial
     * @return self
     */
    public function with(array $partial): self {
        $next = clone $this;
        foreach ($partial as $k => $v) {
            if (property_exists($next, $k)) {
                $next->$k = $v;
            }
        }
        $next->last_updated = time();
        return $next;
    }

    /**
     * Is this scan in a state where drain should keep going?
     */
    public function isActive(): bool {
        // 'paused' is resumable — drain() will continue from the queue.
        return in_array($this->status, ['pending', 'running', 'paused', 'initializing'], true);
    }

    /**
     * Is this scan in a terminal state?
     */
    public function isTerminal(): bool {
        return in_array($this->status, ['completed', 'failed', 'cancelled'], true);
    }
}
