<?php
/**
 * Clean Sweep - Batch Processor
 *
 * Core batch processing system for long-running operations.
 * Handles batching, progress updates, and error recovery.
 *
 * Supports operations like plugin reinstallation, core updates, malware scanning, etc.
 *
 * @author Nithin K R
 */

class CleanSweep_BatchProcessor {

    /**
     * @var CleanSweep_ProgressManager
     */
    private $progressManager;

    /**
     * @var int
     */
    private $batchSize;

    /**
     * @var callable
     */
    private $progressCallback;

    /**
     * Constructor
     *
     * @param string $progressFile Progress file path
     * @param int $batchSize Number of items per batch
     * @param callable $progressCallback Optional progress callback
     */
    public function __construct($progressFile, $batchSize = 5, $progressCallback = null) {
        $this->progressManager = new CleanSweep_ProgressManager($progressFile);
        $this->batchSize = $batchSize;
        $this->progressCallback = $progressCallback;
    }

    /**
     * Process items in batches with progress updates
     *
     * @param array $items Items to process
     * @param callable $processor Function to process each item
     * @param string $operationName Name of the operation for progress messages
     * @return array Results array
     * @throws CleanSweep_BatchProcessingException
     */
    public function processItems($items, $processor, $operationName = 'Processing') {
        if (!is_array($items) || empty($items)) {
            throw new CleanSweep_BatchProcessingException('No items to process');
        }

        if (!is_callable($processor)) {
            throw new CleanSweep_BatchProcessingException('Invalid processor function');
        }

        $totalItems = count($items);
        $results = [
            'success' => [],
            'failed' => [],
            'total_processed' => 0,
            'total_succeeded' => 0,
            'total_failed' => 0
        ];

        // Send initial progress update
        $this->progressManager->updateProgress([
            'status' => 'processing',
            'progress' => 0,
            'message' => "Starting {$operationName}...",
            'details' => "Processing {$totalItems} items in batches of {$this->batchSize}"
        ]);

        // Process items in batches
        $batches = array_chunk($items, $this->batchSize, true);
        $processedCount = 0;

        foreach ($batches as $batchIndex => $batch) {
            $batchNumber = $batchIndex + 1;
            $totalBatches = count($batches);

            // Update batch progress
            $batchProgress = round(($processedCount / $totalItems) * 100);
            $this->progressManager->updateProgress([
                'status' => 'processing',
                'progress' => $batchProgress,
                'message' => "{$operationName}: Processing batch {$batchNumber}/{$totalBatches}",
                'details' => "Batch contains " . count($batch) . " items"
            ]);

            // Process each item in the batch
            foreach ($batch as $itemKey => $item) {
                try {
                    $processedCount++;

                    // Call the processor function
                    $result = call_user_func($processor, $item, $processedCount, $totalItems);

                    if ($result === true || (is_array($result) && isset($result['success']) && $result['success'])) {
                        $results['success'][] = is_array($result) ? $result : ['item' => $itemKey, 'result' => $result];
                        $results['total_succeeded']++;
                    } else {
                        $results['failed'][] = is_array($result) ? $result : ['item' => $itemKey, 'error' => 'Processing failed'];
                        $results['total_failed']++;
                    }

                    // Send individual item progress update
                    $overallProgress = round(($processedCount / $totalItems) * 100);
                    $this->progressManager->updateProgress([
                        'status' => 'processing',
                        'progress' => $overallProgress,
                        'message' => "{$operationName}: " . $this->getItemDescription($item, $processedCount, $totalItems),
                        'details' => "Progress: {$processedCount}/{$totalItems} items processed"
                    ]);

                    // Call progress callback if provided
                    if ($this->progressCallback && is_callable($this->progressCallback)) {
                        call_user_func($this->progressCallback, $processedCount, $totalItems, $item, $result);
                    }

                } catch (Exception $e) {
                    clean_sweep_log_message("BatchProcessor: Error processing item {$itemKey}: " . $e->getMessage(), 'error');
                    $results['failed'][] = ['item' => $itemKey, 'error' => $e->getMessage()];
                    $results['total_failed']++;
                }

                // Small delay between items to prevent overwhelming the system
                usleep(100000); // 0.1 seconds
            }

            // Delay between batches
            if ($batchIndex < count($batches) - 1) {
                sleep(1);
            }
        }

        // Send completion update
        $results['total_processed'] = $processedCount;
        $this->progressManager->sendCompletion($results);

        clean_sweep_log_message("BatchProcessor: Completed {$operationName} - {$results['total_succeeded']} succeeded, {$results['total_failed']} failed", 'info');

        return $results;
    }

    /**
     * Get description for current item being processed
     *
     * @param mixed $item Current item
     * @param int $current Current count
     * @param int $total Total count
     * @return string Description
     */
    private function getItemDescription($item, $current, $total) {
        if (is_array($item) && isset($item['name'])) {
            return "Processing {$item['name']} ({$current}/{$total})";
        } elseif (is_string($item)) {
            return "Processing {$item} ({$current}/{$total})";
        } else {
            return "Processing item {$current} of {$total}";
        }
    }

    /**
     * Set progress callback
     *
     * @param callable $callback
     */
    public function setProgressCallback($callback) {
        if (is_callable($callback)) {
            $this->progressCallback = $callback;
        }
    }

    /**
     * Get progress manager instance
     *
     * @return CleanSweep_ProgressManager
     */
    public function getProgressManager() {
        return $this->progressManager;
    }

    /**
     * Set batch size
     *
     * @param int $batchSize
     */
    public function setBatchSize($batchSize) {
        $this->batchSize = max(1, intval($batchSize));
    }
}
