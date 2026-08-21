<?php
/**
 * Clean Sweep - Scan Work Unit
 *
 * A bounded, idempotent, retryable piece of scanning work.
 * This is the atomic unit of reliable progress in the Background Execution Abstraction.
 *
 * @since Option C
 */

class CleanSweep_ScanWorkUnit {

    /** Work unit status constants */
    const STATUS_PENDING = 'pending';
    const STATUS_CLAIMED = 'claimed';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_DEAD = 'dead';  // Permanently failed after max attempts

    /** Work unit type constants */
    const TYPE_FILE_BATCH = 'file_batch';
    const TYPE_DB_TABLE_SEGMENT = 'db_table_segment';
    const TYPE_FILE_DISCOVERY = 'file_discovery';
    const TYPE_DB_SITE_DISCOVERY = 'db_site_discovery';
    const TYPE_ROOT_CONFIG = 'root_config';
    const TYPE_CORE_CHECKSUM = 'core_checksum';
    const TYPE_PACKAGE_CHECKSUM = 'package_checksum';
    const TYPE_ANALYSIS = 'analysis';
    const TYPE_INTEGRITY_CHECK = 'integrity_check';
    const TYPE_VISIT_CENSUS = 'visit_census';
    const TYPE_FINALIZATION = 'finalization';

    /** @var string Unique work unit ID */
    private $work_id;

    /** @var string Scan ID this unit belongs to */
    private $scan_id;

    /** @var string Work unit type */
    private $type;

    /** @var int Priority (lower = higher priority) */
    private $priority = 100;

    /** @var array Payload describing what to do */
    private $payload = [];

    /** @var string Current status */
    private $status = self::STATUS_PENDING;

    /** @var int Number of execution attempts */
    private $attempts = 0;

    /** @var int Maximum attempts before marking dead */
    private $max_attempts = 5;

    /** @var string|null Process/hostname that claimed this unit */
    private $claimed_by = null;

    /** @var int Timestamp when claimed */
    private $claimed_at = null;

    /** @var int Timestamp when lease expires */
    private $lease_expires_at = null;

    /** @var int Timestamp of last heartbeat */
    private $last_heartbeat_at = null;

    /** @var array Result summary from execution */
    private $result = [];

    /** @var string|null Error message if failed */
    private $error = null;

    /** @var int Creation timestamp */
    private $created_at;

    /** @var int Last update timestamp */
    private $updated_at;

    /**
     * Create a new work unit.
     *
     * @param string $scan_id Scan ID this unit belongs to
     * @param string $type Work unit type
     * @param array $payload Work payload
     * @param int $priority Priority (lower = higher priority)
     * @return self
     */
    public static function create($scan_id, $type, $payload = [], $priority = 100) {
        $unit = new self();
        $unit->work_id = 'wu_' . time() . '_' . bin2hex(random_bytes(4));
        $unit->scan_id = $scan_id;
        $unit->type = $type;
        $unit->payload = $payload;
        $unit->priority = $priority;
        $unit->status = self::STATUS_PENDING;
        $unit->created_at = time();
        $unit->updated_at = time();
        return $unit;
    }

    /**
     * Create a work unit from array data (e.g., from JSON file).
     *
     * @param array $data Serialized work unit data
     * @return self
     */
    public static function from_array($data) {
        $unit = new self();
        $unit->work_id = $data['work_id'] ?? null;
        $unit->scan_id = $data['scan_id'] ?? null;
        $unit->type = $data['type'] ?? null;
        $unit->priority = $data['priority'] ?? 100;
        $unit->payload = $data['payload'] ?? [];
        $unit->status = $data['status'] ?? self::STATUS_PENDING;
        $unit->attempts = $data['attempts'] ?? 0;
        $unit->max_attempts = $data['max_attempts'] ?? 5;
        $unit->claimed_by = $data['claimed_by'] ?? null;
        $unit->claimed_at = $data['claimed_at'] ?? null;
        $unit->lease_expires_at = $data['lease_expires_at'] ?? null;
        $unit->last_heartbeat_at = $data['last_heartbeat_at'] ?? null;
        $unit->result = $data['result'] ?? [];
        $unit->error = $data['error'] ?? null;
        $unit->created_at = $data['created_at'] ?? time();
        $unit->updated_at = $data['updated_at'] ?? time();
        return $unit;
    }

    /**
     * Serialize to array for storage.
     *
     * @return array
     */
    public function to_array() {
        return [
            'work_id' => $this->work_id,
            'scan_id' => $this->scan_id,
            'type' => $this->type,
            'priority' => $this->priority,
            'payload' => $this->payload,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'claimed_by' => $this->claimed_by,
            'claimed_at' => $this->claimed_at,
            'lease_expires_at' => $this->lease_expires_at,
            'last_heartbeat_at' => $this->last_heartbeat_at,
            'result' => $this->result,
            'error' => $this->error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Get work unit ID.
     *
     * @return string
     */
    public function get_work_id() {
        return $this->work_id;
    }

    /**
     * Get scan ID.
     *
     * @return string
     */
    public function get_scan_id() {
        return $this->scan_id;
    }

    /**
     * Get work unit type.
     *
     * @return string
     */
    public function get_type() {
        return $this->type;
    }

    /**
     * Get priority.
     *
     * @return int
     */
    public function get_priority() {
        return $this->priority;
    }

    /**
     * Get payload.
     *
     * @return array
     */
    public function get_payload() {
        return $this->payload;
    }

    /**
     * Replace payload (used by continue_unit for more_work without a new file).
     *
     * @param array $payload
     */
    public function set_payload(array $payload): void {
        $this->payload = $payload;
        $this->updated_at = time();
    }

    /**
     * Store a non-terminal result summary (continuation progress), without completing.
     *
     * @param array $result
     */
    public function set_result_summary(array $result): void {
        $this->result = $result;
        $this->updated_at = time();
    }

    /**
     * Get status.
     *
     * @return string
     */
    public function get_status() {
        return $this->status;
    }

    /**
     * Get process that claimed this unit.
     *
     * @return string|null
     */
    public function get_claimed_by() {
        return $this->claimed_by;
    }

    /**
     * Get timestamp when claimed.
     *
     * @return int|null
     */
    public function get_claimed_at() {
        return $this->claimed_at;
    }

    /**
     * Get lease expiration timestamp.
     *
     * @return int|null
     */
    public function get_lease_expires_at() {
        return $this->lease_expires_at;
    }

    /**
     * Get last heartbeat timestamp.
     *
     * @return int|null
     */
    public function get_last_heartbeat_at() {
        return $this->last_heartbeat_at;
    }

    /**
     * Get creation timestamp.
     *
     * @return int
     */
    public function get_created_at() {
        return $this->created_at;
    }

    /**
     * Get attempts count.
     *
     * @return int
     */
    public function get_attempts() {
        return $this->attempts;
    }

    /**
     * Get max attempts.
     *
     * @return int
     */
    public function get_max_attempts() {
        return $this->max_attempts;
    }

    /**
     * Check if unit can be retried.
     *
     * @return bool
     */
    public function can_retry() {
        return $this->attempts < $this->max_attempts && $this->status !== self::STATUS_DEAD;
    }

    /**
     * Check if lease has expired.
     *
     * @return bool
     */
    public function is_lease_expired() {
        if ($this->lease_expires_at === null) {
            return false;
        }
        return time() > $this->lease_expires_at;
    }

    /**
     * Check if the heartbeat is stale - the process hasn't sent a heartbeat
     * even though the lease hasn't expired yet.
     *
     * This detects "zombie" claims where the process died or was killed
     * but the lease hasn't expired. A heartbeat should come at least every
     * heartbeat_interval seconds, so if last_heartbeat_at is older than
     * heartbeat_interval * stale_multiplier, the process is likely dead.
     *
     * @param int $stale_threshold Seconds since last heartbeat to consider stale (default: 90s)
     * @return bool True if heartbeat is stale (process likely dead)
     */
    public function is_heartbeat_stale($stale_threshold = 90) {
        if ($this->last_heartbeat_at === null) {
            // Never had a heartbeat - if lease was just claimed, that's normal
            // But if we've been running for a while without heartbeat, that's stale
            if ($this->claimed_at !== null && (time() - $this->claimed_at) > $stale_threshold) {
                return true;
            }
            return false;
        }
        return (time() - $this->last_heartbeat_at) > $stale_threshold;
    }

    /**
     * Claim this unit for execution.
     *
     * @param int $lease_seconds How long to hold the lease
     * @param int $heartbeat_stale_threshold Seconds since last heartbeat to consider stale
     * @return bool Success
     */
    public function claim($lease_seconds = 300, $heartbeat_stale_threshold = 90) {
        // Can claim if: PENDING, or (lease EXPIRED), or (heartbeat is STALE)
        $can_claim = $this->status === self::STATUS_PENDING;
        $can_claim = $can_claim || $this->is_lease_expired();
        $can_claim = $can_claim || $this->is_heartbeat_stale($heartbeat_stale_threshold);

        if (!$can_claim) {
            return false;
        }

        $this->status = self::STATUS_CLAIMED;
        $this->claimed_by = $this->get_process_identifier();
        $this->claimed_at = time();
        $this->lease_expires_at = time() + $lease_seconds;
        $this->updated_at = time();
        return true;
    }

    /**
     * Mark unit as running.
     *
     * @return bool
     */
    public function mark_running() {
        if ($this->status !== self::STATUS_CLAIMED) {
            return false;
        }
        $this->status = self::STATUS_RUNNING;
        $this->updated_at = time();
        return true;
    }

    /**
     * Mark unit as completed successfully.
     *
     * @param array $result Result summary
     * @return bool
     */
    public function mark_completed($result = []) {
        $this->status = self::STATUS_COMPLETED;
        $this->result = $result;
        $this->updated_at = time();
        $this->lease_expires_at = null;
        return true;
    }

    /**
     * Mark unit as failed.
     *
     * @param string $error Error message
     * @param bool $retryable Whether this can be retried
     * @return bool
     */
    public function mark_failed($error, $retryable = true) {
        $this->attempts++;
        $this->error = $error;
        $this->updated_at = time();

        if (!$retryable || !$this->can_retry()) {
            $this->status = self::STATUS_DEAD;
            $this->lease_expires_at = null;
        } else {
            // Reset to pending for retry (but keep attempts count)
            $this->status = self::STATUS_PENDING;
            $this->claimed_by = null;
            $this->claimed_at = null;
            $this->lease_expires_at = null;
        }
        return true;
    }

    /**
     * Extend the lease (heartbeat).
     *
     * @param int $lease_seconds Additional seconds to add
     * @return bool
     */
    public function heartbeat($lease_seconds = 300) {
        if ($this->status !== self::STATUS_RUNNING && $this->status !== self::STATUS_CLAIMED) {
            return false;
        }
        $this->last_heartbeat_at = time();
        $this->lease_expires_at = time() + $lease_seconds;
        $this->updated_at = time();
        return true;
    }

    /**
     * Release claim (return to queue).
     *
     * @return bool
     */
    public function release() {
        $this->status = self::STATUS_PENDING;
        $this->claimed_by = null;
        $this->claimed_at = null;
        $this->lease_expires_at = null;
        $this->updated_at = time();
        return true;
    }

    /**
     * Get a unique identifier for the current process/host.
     *
     * @return string
     */
    private function get_process_identifier() {
        $hostname = defined('HOSTNAME') ? HOSTNAME : (gethostname() ?: 'unknown');
        $pid = function_exists('getmypid') ? getmypid() : 'np';
        return "{$hostname}:pid-{$pid}";
    }

    /**
     * Check if this unit is in a terminal state.
     *
     * @return bool
     */
    public function is_terminal() {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_DEAD]);
    }

    /**
     * Get human-readable description of this work unit.
     *
     * @return string
     */
    public function get_description() {
        $desc = ucfirst(str_replace('_', ' ', $this->type));
        if ($this->status !== self::STATUS_PENDING) {
            $desc .= " [{$this->status}]";
        }
        if ($this->attempts > 0) {
            $desc .= " (attempt {$this->attempts}/{$this->max_attempts})";
        }
        return $desc;
    }

    /**
     * Get a short summary for UI display.
     *
     * @return array
     */
    public function get_summary() {
        return [
            'work_id' => $this->work_id,
            'type' => $this->type,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'error' => $this->error,
            'result' => $this->result,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}