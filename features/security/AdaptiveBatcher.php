<?php
/**
 * Clean Sweep - Adaptive Batcher
 *
 * Throughput monitoring and adaptive batch sizing for scanner operations.
 * Dynamically adjusts batch sizes based on observed performance.
 *
 * @since Phase 1
 */
class CleanSweep_AdaptiveBatcher {

    /** @var int Number of batches to track for rolling average */
    private $window_size;

    /** @var array Batch times in seconds */
    private $batch_times = [];

    /** @var int Current batch size */
    private $current_batch_size;

    /** @var int Minimum batch size */
    private $min_batch_size;

    /** @var int Maximum batch size */
    private $max_batch_size;

    /** @var float Target time per batch in seconds */
    private $target_time_per_batch;

    /** @var int Items processed in current window */
    private $items_in_window;

    /** @var float Start time of current batch */
    private $batch_start_time;

    /** @var string Type of operation (files or db_rows) */
    private $operation_type;

    /**
     * Initialize adaptive batcher.
     *
     * @param int $initial_batch_size Starting batch size
     * @param array $options Configuration options
     */
    public function __construct($initial_batch_size = 20, $options = []) {
        $this->window_size = $options['window_size'] ?? 10;
        $this->min_batch_size = $options['min_batch_size'] ?? 5;
        $this->max_batch_size = $options['max_batch_size'] ?? 100;
        $this->target_time_per_batch = $options['target_time'] ?? 2.0;
        $this->operation_type = $options['type'] ?? 'files';
        $this->current_batch_size = $initial_batch_size;
        $this->items_in_window = 0;
        $this->batch_start_time = null;
    }

    /**
     * Start timing a new batch.
     */
    public function start_batch() {
        $this->batch_start_time = microtime(true);
    }

    /**
     * End timing current batch and record results.
     *
     * @param int $items_processed Number of items processed in this batch
     * @return int Recommended batch size for next batch
     */
    public function end_batch($items_processed = 0) {
        if ($this->batch_start_time === null) {
            return $this->current_batch_size;
        }

        $end_time = microtime(true);
        $batch_time = $end_time - $this->batch_start_time;
        $this->batch_start_time = null;

        // Record this batch's time
        $this->batch_times[] = $batch_time;
        $this->items_in_window += $items_processed;

        // Keep window size bounded
        if (count($this->batch_times) > $this->window_size) {
            array_shift($this->batch_times);
            // Approximate: subtract oldest items proportionally
            $this->items_in_window = (int)($this->items_in_window * 0.9);
        }

        // Adjust batch size based on timing
        $this->adjust_batch_size();

        return $this->current_batch_size;
    }

    /**
     * Adjust batch size based on observed timing.
     */
    private function adjust_batch_size() {
        if (count($this->batch_times) < 3) {
            // Not enough data yet - stay at initial size
            return;
        }

        $avg_time = $this->get_average_batch_time();

        // If running faster than target, increase batch size
        if ($avg_time < $this->target_time_per_batch * 0.8) {
            $increase = ($this->target_time_per_batch * 0.8 - $avg_time) * 10;
            $this->current_batch_size = (int)min(
                $this->current_batch_size + max(1, $increase),
                $this->max_batch_size
            );
        }
        // If running slower than target, decrease batch size
        elseif ($avg_time > $this->target_time_per_batch * 1.2) {
            $decrease = ($avg_time - $this->target_time_per_batch * 1.2) * 10;
            $this->current_batch_size = (int)max(
                $this->current_batch_size - max(1, $decrease),
                $this->min_batch_size
            );
        }
    }

    /**
     * Get rolling average batch time.
     *
     * @return float Seconds
     */
    public function get_average_batch_time() {
        if (empty($this->batch_times)) {
            return 0;
        }

        return array_sum($this->batch_times) / count($this->batch_times);
    }

    /**
     * Get recommended batch size for next iteration.
     *
     * @return int
     */
    public function get_recommended_batch_size() {
        return $this->current_batch_size;
    }

    /**
     * Get current batch size.
     *
     * @return int
     */
    public function get_current_batch_size() {
        return $this->current_batch_size;
    }

    /**
     * Get rolling average throughput (items/second).
     *
     * @return float Items per second
     */
    public function get_throughput() {
        if (count($this->batch_times) < 2) {
            return 0;
        }

        $avg_time = $this->get_average_batch_time();
        if ($avg_time <= 0) {
            return 0;
        }

        return $this->current_batch_size / $avg_time;
    }

    /**
     * Get estimated items per second (alternative calculation using window totals).
     *
     * @return float
     */
    public function get_items_per_second() {
        if (empty($this->batch_times) || $this->items_in_window <= 0) {
            return 0;
        }

        $total_time = array_sum($this->batch_times);
        if ($total_time <= 0) {
            return 0;
        }

        return $this->items_in_window / $total_time;
    }

    /**
     * Calculate ETA based on remaining items and current throughput.
     *
     * @param int $remaining Number of items remaining
     * @return string|null Human-readable ETA or null if cannot estimate
     */
    public function calculate_eta($remaining) {
        $throughput = $this->get_throughput();

        if ($throughput <= 0) {
            return null;
        }

        $seconds_remaining = $remaining / $throughput;

        return $this->format_eta($seconds_remaining);
    }

    /**
     * Format seconds into human-readable ETA.
     *
     * @param float $seconds
     * @return string
     */
    public function format_eta($seconds) {
        if ($seconds < 0) {
            return 'Unknown';
        }

        if ($seconds < 60) {
            return 'Less than a minute';
        }

        if ($seconds < 3600) {
            $minutes = (int)round($seconds / 60);
            return "~$minutes minute" . ($minutes > 1 ? 's' : '');
        }

        $hours = (int)floor($seconds / 3600);
        $minutes = (int)round(($seconds % 3600) / 60);

        if ($minutes > 0) {
            return "~$hours hour" . ($hours > 1 ? 's' : '') . " $minutes min";
        }

        return "~$hours hour" . ($hours > 1 ? 's' : '');
    }

    /**
     * Get batch statistics summary.
     *
     * @return array
     */
    public function get_stats() {
        return [
            'type' => $this->operation_type,
            'current_batch_size' => $this->current_batch_size,
            'avg_batch_time' => round($this->get_average_batch_time(), 3),
            'throughput' => round($this->get_throughput(), 2),
            'items_in_window' => $this->items_in_window,
            'batches_tracked' => count($this->batch_times),
            'min_batch_size' => $this->min_batch_size,
            'max_batch_size' => $this->max_batch_size,
            'target_time' => $this->target_time_per_batch,
        ];
    }

    /**
     * Reset statistics (for new scan).
     */
    public function reset() {
        $this->batch_times = [];
        $this->items_in_window = 0;
        $this->batch_start_time = null;
    }

    /**
     * Set target time per batch.
     *
     * @param float $seconds
     */
    public function set_target_time($seconds) {
        $this->target_time_per_batch = max(0.5, (float)$seconds);
    }

    /**
     * Set batch size limits.
     *
     * @param int $min
     * @param int $max
     */
    public function set_limits($min, $max) {
        $this->min_batch_size = max(1, (int)$min);
        $this->max_batch_size = max($this->min_batch_size, (int)$max);
        $this->current_batch_size = min($this->current_batch_size, $this->max_batch_size);
    }

    /**
     * Force a specific batch size (overrides adaptive behavior).
     *
     * @param int $size
     */
    public function force_batch_size($size) {
        $this->current_batch_size = max($this->min_batch_size, min($this->max_batch_size, (int)$size));
    }

    /**
     * Get window of recent batch times.
     *
     * @return array
     */
    public function get_recent_batch_times() {
        return $this->batch_times;
    }

    /**
     * Record batch time externally (e.g., from parallel workers).
     * This allows the main process to record batch times reported by workers.
     *
     * @param float $batch_time Time taken for batch in seconds
     * @param int $items_processed Number of items processed in this batch
     */
    public function record_batch_time($batch_time, $items_processed = 0) {
        // Record this batch's time
        $this->batch_times[] = (float)$batch_time;
        $this->items_in_window += $items_processed;

        // Keep window size bounded
        if (count($this->batch_times) > $this->window_size) {
            array_shift($this->batch_times);
            // Approximate: subtract oldest items proportionally
            if ($this->items_in_window > 0) {
                $this->items_in_window = (int)($this->items_in_window * 0.9);
            }
        }

        // Adjust batch size based on timing
        $this->adjust_batch_size();
    }
}