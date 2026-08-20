<?php
/**
 * Clean Sweep - Scan Work Queue Interface
 *
 * Contract for work queue implementations.
 * The queue provides persistent, queryable storage of pending and in-flight Work Units.
 *
 * @since Option C
 */

/**
 * Interface for scan work queue implementations.
 */
interface CleanSweep_ScanWorkQueueInterface {

    /**
     * Enqueue a new work unit.
     *
     * @param CleanSweep_ScanWorkUnit $unit Work unit to enqueue
     * @return string Work unit ID
     */
    public function enqueue(CleanSweep_ScanWorkUnit $unit);

    /**
     * Claim the next available work unit for execution.
     * Uses lease mechanism to prevent concurrent execution.
     *
     * @param string $scan_id Scan ID to claim work for (null for any)
     * @param int $lease_seconds How long to hold the lease
     * @return CleanSweep_ScanWorkUnit|null Claimed unit or null if none available
     */
    public function claim_next($scan_id = null, $lease_seconds = 300);

    /**
     * Extend the lease on a claimed work unit (heartbeat).
     *
     * @param string $work_id Work unit ID
     * @param int $lease_seconds Additional seconds to add
     * @return bool Success
     */
    public function heartbeat($work_id);

    /**
     * Mark a work unit as completed.
     *
     * @param string $work_id Work unit ID
     * @param array $result Result summary
     * @param string|null $scan_id Optional scan ID (avoids filesystem scan to find unit location)
     * @return bool Success
     */
    public function complete($work_id, $result = [], $scan_id = null);

    /**
     * Mark a work unit as failed.
     *
     * @param string $work_id Work unit ID
     * @param string $error Error message
     * @param bool $retryable Whether this can be retried
     * @param string|null $scan_id Optional scan ID (avoids filesystem scan to find unit location)
     * @return bool Success
     */
    public function fail($work_id, $error, $retryable = true, $scan_id = null);

    /**
     * List work units for a scan.
     *
     * @param string $scan_id Scan ID
     * @param array $statuses Filter by statuses (empty = all)
     * @return CleanSweep_ScanWorkUnit[]
     */
    public function list_for_scan($scan_id, $statuses = []);

    /**
     * Get queue statistics for a scan.
     *
     * @param string $scan_id Scan ID
     * @return array Stats including counts by status
     */
    public function get_stats($scan_id);

    /**
     * Recover stale leases (units that didn't heartbeat).
     * Should be called periodically to recover orphaned units.
     *
     * @param string|null $scan_id Optional scan ID to limit recovery
     * @return int Number of units recovered
     */
    public function recover_stale_leases($scan_id = null);

    /**
     * Get a specific work unit by ID.
     *
     * @param string $work_id Work unit ID
     * @return CleanSweep_ScanWorkUnit|null
     */
    public function get($work_id);

    /**
     * Move a failed unit to the dead letter queue.
     *
     * @param string $work_id Work unit ID
     * @return bool Success
     */
    public function move_to_dead_letter($work_id);

    /**
     * Clear all work units for a scan (cleanup).
     *
     * @param string $scan_id Scan ID
     * @return bool Success
     */
    public function clear_scan($scan_id);
}