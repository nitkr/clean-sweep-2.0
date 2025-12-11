<?php
/**
 * Clean Sweep - Canary File Manager
 *
 * Manages a simple canary file to verify fresh environment setup completion.
 * Bypasses FastCGI filesystem caching by using browser-based HTTP verification.
 *
 * @version 1.0
 * @author Nithin K R
 */

class CleanSweep_CanaryFile {

    private $fresh_dir;
    private $canary_file;

    public function __construct() {
        $this->fresh_dir = dirname(dirname(__DIR__)) . '/core/fresh';
        $this->canary_file = $this->fresh_dir . '/.clean-sweep-ready';
    }

    /**
     * Write canary file to signal successful setup completion
     * Called at the very end of setup() method
     *
     * @return bool True on success, false on failure
     */
    public function writeCanary() {
        clean_sweep_log_message("🕊️ Writing canary file to signal setup completion: " . $this->canary_file, 'info');

        // Create canary file with timestamp
        $canary_data = json_encode([
            'timestamp' => time(),
            'setup_complete' => true,
            'method' => 'canary_verification'
        ]);

        if (file_put_contents($this->canary_file, $canary_data) === false) {
            clean_sweep_log_message("❌ Failed to write canary file: " . $this->canary_file, 'error');
            return false;
        }

        clean_sweep_log_message("✅ Canary file written successfully at: " . $this->canary_file, 'info');
        clean_sweep_log_message("🕊️ Browser will verify this file via HTTP request to /core/fresh/.clean-sweep-ready", 'info');
        
        return true;
    }

    /**
     * Get the URL path for the canary file (for browser-based verification)
     *
     * @return string URL path to canary file
     */
    public function getCanaryPath() {
        // Return relative path from WordPress root to canary file
        return '/core/fresh/.clean-sweep-ready';
    }

    /**
     * Check if canary file exists (server-side check, for reference)
     * Note: This is NOT used in the normal flow as it would be affected by FastCGI caching
     * Instead, the browser fetches the file directly via HTTP
     *
     * @return bool True if canary file exists
     */
    public function canaryExists() {
        return file_exists($this->canary_file);
    }

    /**
     * Delete canary file (for cleanup/reset)
     *
     * @return bool True on success
     */
    public function deleteCanary() {
        if (file_exists($this->canary_file)) {
            if (unlink($this->canary_file)) {
                clean_sweep_log_message("🗑️  Canary file deleted successfully", 'debug');
                return true;
            } else {
                clean_sweep_log_message("❌ Failed to delete canary file", 'error');
                return false;
            }
        }
        return true;
    }
}
