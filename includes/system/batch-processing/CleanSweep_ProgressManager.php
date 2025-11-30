<?php
/**
 * Clean Sweep - Progress Manager
 *
 * Manages progress file updates for long-running operations.
 * Provides centralized progress tracking and completion handling.
 *
 * @author Nithin K R
 */

class CleanSweep_ProgressManager {

    /**
     * @var string
     */
    private $progressFile;

    /**
     * Constructor
     *
     * @param string $progressFile Progress file name (without .progress extension)
     */
    public function __construct($progressFile) {
        $this->progressFile = $progressFile;
    }

    /**
     * Update progress with new data
     *
     * @param array $data Progress data array
     * @return bool Success status
     */
    public function updateProgress($data) {
        if (empty($this->progressFile)) {
            return false;
        }

        // Ensure required fields are present
        $progressData = array_merge([
            'timestamp' => time(),
            'status' => 'processing',
            'progress' => 0,
            'message' => '',
            'details' => ''
        ], $data);

        // Sanitize data for JSON encoding
        $progressData = clean_sweep_sanitize_utf8_array($progressData);

        try {
            $result = clean_sweep_write_progress_file($this->progressFile, $progressData);

            if (!$result) {
                clean_sweep_log_message("ProgressManager: Failed to write progress file: {$this->progressFile}", 'error');
                return false;
            }

            // Log progress updates for debugging (only for significant updates)
            if (isset($data['progress']) && $data['progress'] % 25 === 0) {
                clean_sweep_log_message("ProgressManager: {$data['progress']}% - {$data['message']}", 'info');
            }

            return true;

        } catch (Exception $e) {
            clean_sweep_log_message("ProgressManager: Exception updating progress: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Send completion status with results
     *
     * @param array $results Operation results
     * @return bool Success status
     */
    public function sendCompletion($results) {
        $completionData = [
            'status' => 'complete',
            'progress' => 100,
            'message' => 'Operation completed successfully!',
            'results' => $results,
            'completed_at' => time()
        ];

        // Add summary information
        if (is_array($results)) {
            $totalSuccess = 0;
            $totalFailed = 0;

            // Handle nested results structure from PluginReinstaller
            if (isset($results['wordpress_org']) || isset($results['wpmu_dev']) || isset($results['suspicious_cleanup'])) {
                $totalSuccess = count($results['wordpress_org']['successful'] ?? []) +
                               count($results['wpmu_dev']['successful'] ?? []) +
                               count($results['suspicious_cleanup']['deleted'] ?? []);
                $totalFailed = count($results['wordpress_org']['failed'] ?? []) +
                              count($results['wpmu_dev']['failed'] ?? []) +
                              count($results['suspicious_cleanup']['failed'] ?? []);
            } else {
                // Handle flat structure for other operations (backward compatibility)
                $totalSuccess = $results['total_succeeded'] ?? count($results['success'] ?? []);
                $totalFailed = $results['total_failed'] ?? count($results['failed'] ?? []);
            }

            $completionData['summary'] = [
                'total_successful' => $totalSuccess,
                'total_failed' => $totalFailed,
                'total_processed' => $results['total_processed'] ?? ($totalSuccess + $totalFailed)
            ];

            $completionData['message'] = $totalFailed > 0
                ? "Operation completed with {$totalFailed} error(s)"
                : "Operation completed successfully!";
        }

        clean_sweep_log_message("ProgressManager: Sending completion - " . $completionData['message'], 'info');

        return $this->updateProgress($completionData);
    }

    /**
     * Send error status
     *
     * @param string $errorMessage Error message
     * @param array $errorData Additional error data
     * @return bool Success status
     */
    public function sendError($errorMessage, $errorData = []) {
        $errorProgressData = [
            'status' => 'error',
            'progress' => 0,
            'message' => 'Operation failed: ' . $errorMessage,
            'error' => $errorMessage,
            'error_data' => $errorData,
            'failed_at' => time()
        ];

        clean_sweep_log_message("ProgressManager: Sending error - {$errorMessage}", 'error');

        return $this->updateProgress($errorProgressData);
    }

    /**
     * Send warning status
     *
     * @param string $warningMessage Warning message
     * @param array $warningData Additional warning data
     * @return bool Success status
     */
    public function sendWarning($warningMessage, $warningData = []) {
        $warningProgressData = [
            'status' => 'warning',
            'message' => 'Warning: ' . $warningMessage,
            'warning' => $warningMessage,
            'warning_data' => $warningData,
            'warned_at' => time()
        ];

        clean_sweep_log_message("ProgressManager: Sending warning - {$warningMessage}", 'warning');

        return $this->updateProgress($warningProgressData);
    }

    /**
     * Get current progress data
     *
     * @return array|null Current progress data or null if not available
     */
    public function getCurrentProgress() {
        if (empty($this->progressFile)) {
            return null;
        }

        $filePath = PROGRESS_DIR . $this->progressFile;

        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return $data ?: null;
    }

    /**
     * Check if operation is complete
     *
     * @return bool True if operation is complete (success or error)
     */
    public function isComplete() {
        $progress = $this->getCurrentProgress();
        return $progress && in_array($progress['status'], ['complete', 'error']);
    }

    /**
     * Clean up progress file
     *
     * @return bool Success status
     */
    public function cleanup() {
        if (empty($this->progressFile)) {
            return true;
        }

        $filePath = PROGRESS_DIR . $this->progressFile;

        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return true;
    }

    /**
     * Get progress file path
     *
     * @return string Full path to progress file
     */
    public function getProgressFilePath() {
        return PROGRESS_DIR . $this->progressFile;
    }

    /**
     * Set progress file
     *
     * @param string $progressFile
     */
    public function setProgressFile($progressFile) {
        $this->progressFile = $progressFile;
    }

    /**
     * Initialize progress with operation start
     *
     * @param string $operationName Name of the operation
     * @param array $initialData Additional initial data
     * @return bool Success status
     */
    public function initializeProgress($operationName, $initialData = []) {
        $initData = array_merge([
            'status' => 'starting',
            'progress' => 0,
            'message' => "Initializing {$operationName}...",
            'details' => 'Preparing operation',
            'started_at' => time()
        ], $initialData);

        clean_sweep_log_message("ProgressManager: Initializing progress for {$operationName}", 'info');

        return $this->updateProgress($initData);
    }
}
