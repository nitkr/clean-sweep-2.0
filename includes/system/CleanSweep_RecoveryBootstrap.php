<?php
/**
 * Clean Sweep - Recovery Bootstrap
 *
 * Simplified bootstrap system for Recovery-Only Mode.
 * Always uses isolated fresh WordPress environment.
 *
 * @version 2.1
 */

class CleanSweep_RecoveryBootstrap {

    private $fresh_env;
    private $is_ajax;

    public function __construct($is_ajax = false) {
        $this->fresh_env = new CleanSweep_FreshEnvironment();
        $this->is_ajax = $is_ajax;

        // Check REAL SITE ROOT core files after recovery completion
        // Use site root detection instead of relative paths
    }

    /**
     * Initialize Clean Sweep - main entry point
     *
     * @return bool True if bootstrap successful
     */
    public function initialize() {
        // Handle recovery setup AJAX requests regardless of environment state
        if ($this->is_ajax && $this->isRecoverySetupAjax()) {
            $this->handleAjaxSetup();
            return false; // AJAX handled, don't continue
        }

        // Check if fresh environment exists
        if ($this->fresh_env->isValid('main_page')) {
            // Load existing fresh environment
            return $this->loadFreshEnvironment();
        } else {
            // Show setup interface
            return $this->showSetupInterface();
        }
    }

    /**
     * Check if this is a recovery setup AJAX request
     *
     * @return bool True if recovery setup AJAX
     */
    private function isRecoverySetupAjax() {
        $action = $_POST['action'] ?? '';
        $recovery_actions = ['start_fresh_setup', 'get_setup_progress', 'upload_wordpress_zip', 'clear_all_caches', 'check_canary'];

        return in_array($action, $recovery_actions);
    }

    /**
     * Load existing fresh environment
     *
     * @return bool True on success
     */
    private function loadFreshEnvironment() {
        clean_sweep_log_message("✅ Fresh environment found - loading...", 'info');

        if ($this->fresh_env->load()) {
            clean_sweep_log_message("🎉 Clean Sweep ready!", 'info');

            // Create global functions object for CleanSweep_Application handlers
            // FreshEnvironment already loaded WordPress and set up database connection
            global $clean_sweep_functions;
            if (!isset($clean_sweep_functions)) {
                // Create functions object - WordPress DB connection already available
                $clean_sweep_functions = new CleanSweep_Functions(null);
            }

            return true;
        } else {
            clean_sweep_log_message("❌ Failed to load fresh environment", 'error');
            return false;
        }
    }

    /**
     * Show setup interface for fresh environment
     *
     * @return bool False (setup in progress)
     */
    private function showSetupInterface() {
        if ($this->is_ajax) {
            // Handle AJAX setup requests
            return $this->handleAjaxSetup();
        } else {
            // Show HTML setup interface
            $this->showHtmlSetupInterface();
            return false; // Don't continue with app
        }
    }

    /**
     * Show HTML setup interface
     */
    private function showHtmlSetupInterface() {
        // Double-check: if environment became valid while loading the page, redirect immediately
        if ($this->fresh_env->isValid()) {
            clean_sweep_log_message("Environment became valid during page load, redirecting to app", 'info');
            echo '<script>window.location.href = window.location.pathname + "?recovery_token=" + Date.now();</script>';
            return;
        }

        clean_sweep_output_html_header();

        echo '<div style="max-width: 800px; margin: 50px auto; padding: 30px; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
        echo '<h1 style="color: #d73a49; text-align: center; margin-bottom: 30px;">🛡️ Secure Recovery Environment Setup</h1>';

        echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; border-radius: 6px; margin-bottom: 30px;">';
        echo '<h3 style="margin: 0 0 15px 0; color: #856404;">🔄 Setting up Secure Recovery Environment</h3>';
        echo '<p style="margin: 0; color: #856404;">Clean Sweep runs in a protected recovery environment that safely isolates and removes malware.</p>';
        echo '</div>';

        echo '<div id="setup-progress" style="margin-bottom: 30px;">';
        echo '<div style="background: #f8f9fa; border: 1px solid #dee2e6; padding: 20px; border-radius: 6px;">';
        echo '<div style="display: flex; align-items: center; margin-bottom: 15px;">';
        echo '<div id="progress-spinner" style="width: 20px; height: 20px; border: 2px solid #007cba; border-top: 2px solid transparent; border-radius: 50%; animation: spin 1s linear infinite; margin-right: 15px;"></div>';
        echo '<h4 style="margin: 0; color: #007cba;">Setting up Recovery Environment...</h4>';
        echo '</div>';
        echo '<div style="background: #e9ecef; height: 8px; border-radius: 4px; overflow: hidden;">';
        echo '<div id="progress-bar" style="width: 0%; height: 100%; background: #007cba; transition: width 0.3s ease;"></div>';
        echo '</div>';
        echo '<p id="progress-text" style="margin: 10px 0 0 0; color: #6c757d; font-size: 14px;">Initializing download...</p>';
        echo '</div>';
        echo '</div>';

        echo '<div id="manual-upload" style="display: none;">';
        echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; border-radius: 6px;">';
        echo '<h4 style="margin: 0 0 15px 0; color: #721c24;">⚠️ Auto-Setup Failed</h4>';
        echo '<p style="margin: 0 0 15px 0; color: #721c24;">Unable to set up the recovery environment automatically. This can happen in restricted network environments.</p>';
        echo '<p style="margin: 0 0 20px 0; color: #721c24;"><strong>Solution:</strong> Download the required files manually and upload the ZIP file.</p>';

        echo '<div style="background: #fff; border: 1px solid #dee2e6; padding: 20px; border-radius: 6px; margin-bottom: 20px;">';
        echo '<h5 style="margin: 0 0 10px 0;">📥 Manual Upload Steps:</h5>';
        echo '<ol style="margin: 0; padding-left: 20px;">';
        echo '<li>Download the latest WordPress ZIP from <a href="https://wordpress.org/download/" target="_blank" style="color: #007cba;">wordpress.org/download</a></li>';
        echo '<li>Locate the wordpress-*.zip file on your computer</li>';
        echo '<li>Upload the ZIP file below</li>';
        echo '</ol>';
        echo '</div>';

        echo '<form id="upload-form" enctype="multipart/form-data" style="margin-bottom: 20px;">';
        echo '<input type="file" name="recovery_zip" accept=".zip" required style="display: block; margin-bottom: 10px; padding: 8px; border: 1px solid #dee2e6; border-radius: 4px; width: 100%;">';
        echo '<button type="submit" style="background: #007cba; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">📤 Upload & Install</button>';
        echo '</form>';
        echo '<div id="upload-progress" style="display: none;">Processing upload...</div>';
        echo '</div>';
        echo '</div>';

        echo '<div style="text-align: center; color: #6c757d; font-size: 14px;">';
        echo '<p>This setup runs only once. Future visits will be instant.</p>';
        echo '</div>';

        echo '</div>';

        // JavaScript for setup process with enhanced cache-busting
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const progressBar = document.getElementById("progress-bar");
            const progressText = document.getElementById("progress-text");
            const setupProgress = document.getElementById("setup-progress");
            const manualUpload = document.getElementById("manual-upload");

            // Start auto-download
            startAutoDownload();

            function startAutoDownload() {
                progressText.textContent = "Initializing secure environment...";

                fetch("", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                        "Cache-Control": "no-cache, no-store",
                        "Pragma": "no-cache"
                    },
                    body: "action=start_fresh_setup"
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateProgress(25, "Setting up recovery environment...");
                        pollProgress();
                    } else {
                        showManualUpload(data.error || "Auto-setup failed");
                    }
                })
                .catch(error => {
                    showManualUpload("Connection error - manual setup required");
                });
            }

            function pollProgress() {
                fetch("", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                        "Cache-Control": "no-cache, no-store",
                        "Pragma": "no-cache"
                    },
                    body: "action=get_setup_progress"
                })
                .then(response => response.json())
                .then(data => {
                    if (data.progress !== undefined) {
                        updateProgress(data.progress, data.message || "Setting up...");
                        if (data.progress < 100) {
                            setTimeout(pollProgress, 1000);
                        } else if (data.success) {
                            updateProgress(100, "Environment configured! Starting Clean Sweep...");
                            // PRE-RELOAD CACHE CLEAR + INCREASED DELAY
                            setTimeout(() => completeSetup(), 1000);
                        }
                    }
                })
                .catch(error => {
                    showManualUpload("Progress check failed");
                });
            }

            function completeSetup() {
                // Step 1: Clear server caches before reload
                updateProgress(100, "Clearing server caches...");
                fetch("", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                        "Cache-Control": "no-cache, no-store",
                        "Pragma": "no-cache"
                    },
                    body: "action=clear_all_caches"
                })
                .then(() => {
                    // Step 2: Verify canary file exists (proves setup completed)
                    updateProgress(100, "Verifying environment setup completion...");
                    pollCanary(0);
                })
                .catch(() => {
                    // Fallback: still try to reload
                    setTimeout(() => window.location.href = window.location.pathname + "?recovery_token=" + Date.now(), 2000);
                });
            }

            function pollCanary(attempts) {
                const maxAttempts = 10;  // Try for 5 seconds (500ms * 10)
                
                if (attempts >= maxAttempts) {
                    // Canary check timed out, reload anyway (cache should have cleared)
                    updateProgress(100, "Environment ready! Starting Clean Sweep...");
                    setTimeout(() => window.location.href = window.location.pathname + "?recovery_token=" + Date.now(), 1000);
                    return;
                }

                fetch("", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                        "Cache-Control": "no-cache, no-store",
                        "Pragma": "no-cache"
                    },
                    body: "action=check_canary"
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Canary file found! Setup is definitely complete
                        updateProgress(100, "✅ Setup verified! Starting Clean Sweep...");
                        setTimeout(() => window.location.href = window.location.pathname + "?recovery_token=" + Date.now(), 1000);
                    } else {
                        // Canary not found yet, keep polling
                        setTimeout(() => pollCanary(attempts + 1), 500);
                    }
                })
                .catch(() => {
                    // Network error, keep polling
                    setTimeout(() => pollCanary(attempts + 1), 500);
                });
            }

            function updateProgress(percent, message) {
                progressBar.style.width = percent + "%";
                progressText.textContent = message;
            }

            function showManualUpload(reason) {
                setupProgress.style.display = "none";
                manualUpload.style.display = "block";

                // Handle manual upload
                const uploadForm = document.getElementById("upload-form");
                uploadForm.addEventListener("submit", function(e) {
                    e.preventDefault();
                    const formData = new FormData(uploadForm);
                    formData.append("action", "upload_wordpress_zip");

                    document.getElementById("upload-progress").style.display = "block";

                    fetch("", {
                        method: "POST",
                        headers: {
                            "Cache-Control": "no-cache, no-store",
                            "Pragma": "no-cache"
                        },
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            updateProgress(100, "Environment configured! Starting Clean Sweep...");
                            setTimeout(() => completeSetup(), 1000);
                        } else {
                            alert("Upload failed: " + (data.error || "Unknown error"));
                        }
                    })
                    .catch(error => {
                        alert("Upload error: " + error.message);
                    });
                });
            }
        });
        </script>';

        echo '<style>
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        </style>';

        clean_sweep_output_html_footer();
    }

    /**
     * Handle AJAX setup requests
     *
     * @return bool False (setup in progress)
     */
    private function handleAjaxSetup() {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'start_fresh_setup':
                $this->handleStartSetup();
                break;

            case 'get_setup_progress':
                $this->handleGetProgress();
                break;

            case 'upload_wordpress_zip':
                $this->handleUploadZip();
                break;

            case 'clear_all_caches':
                $this->handleClearCaches();
            case 'check_canary':
                $this->handleCanaryCheck();
                break;
                break;

            default:
                $this->sendJsonResponse(false, 'Invalid action');
        }

        return false;
    }

    /**
     * Handle start setup request using existing progress system
     */
    private function handleStartSetup() {
        $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : 'recovery_setup';

        // Initialize progress file
        $progress_data = [
            'status' => 'downloading',
            'progress' => 10,
            'message' => 'Initializing recovery environment setup...',
            'step' => 1,
            'total_steps' => 3
        ];
        clean_sweep_write_progress_file($progress_file, $progress_data);

        // Reset execution time before long operation
        clean_sweep_reset_execution_time();

        // Start setup process
        if ($this->fresh_env->setup()) {
            // SET SESSION FLAG WITH TIMESTAMP FOR FAST VALIDATION BYPASS
            // This prevents FastCGI caching issues on subsequent page loads
            // Sessions are in-process memory, not affected by filesystem caching
            $_SESSION['fresh_env_setup_complete'] = time();
            clean_sweep_log_message("✅ Session flag set: fresh_env_setup_complete = " . $_SESSION['fresh_env_setup_complete'], 'info');

            // PHP will auto-save session data when request ends
            // No need to manually call session_write_close()

            // Update progress to complete
            $progress_data = [
                'status' => 'complete',
                'progress' => 100,
                'message' => 'Recovery environment setup complete!',
                'step' => 3,
                'total_steps' => 3
            ];
            clean_sweep_write_progress_file($progress_file, $progress_data);

            $this->sendJsonResponse(true, 'Setup completed successfully');
        } else {
            // Update progress to error
            $progress_data = [
                'status' => 'error',
                'progress' => 0,
                'message' => 'Failed to setup recovery environment',
                'details' => 'Could not download or configure WordPress files'
            ];
            clean_sweep_write_progress_file($progress_file, $progress_data);

            $this->sendJsonResponse(false, 'Setup failed');
        }
    }

    /**
     * Handle progress check using existing progress file system
     */
    private function handleGetProgress() {
        $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : 'recovery_setup';

        $progress_path = PROGRESS_DIR . '/' . $progress_file . '.progress';

        if (file_exists($progress_path)) {
            $progress_data = json_decode(file_get_contents($progress_path), true);
            if ($progress_data) {
                $this->sendJsonResponse(true, 'Progress retrieved', $progress_data);
                return;
            }
        }

        // If no progress file exists, check if setup is already complete
        if ($this->fresh_env->isValid()) {
            $this->sendJsonResponse(true, 'Setup complete', ['progress' => 100, 'status' => 'complete']);
        } else {
            $this->sendJsonResponse(false, 'No progress data available');
        }
    }

    /**
     * Handle ZIP upload
     */
    private function handleUploadZip() {
        if (!isset($_FILES['recovery_zip']) || $_FILES['recovery_zip']['error'] !== UPLOAD_ERR_OK) {
            $this->sendJsonResponse(false, 'No file uploaded or upload error');
            return;
        }

        $temp_file = $_FILES['recovery_zip']['tmp_name'];
        if ($this->fresh_env->setup($temp_file)) {
            $this->sendJsonResponse(true, 'Upload and setup complete');
        } else {
            $this->sendJsonResponse(false, 'Upload processing failed');
        }
    }

    /**
     * Handle cache clearing request
     * Clears all possible caches to ensure filesystem checks work correctly
     */
    private function handleClearCaches() {
        clean_sweep_log_message("🧹 Clearing all caches for fresh filesystem validation", 'info');

        // Clear PHP filesystem cache
        clearstatcache();

        // Clear OPcache if available
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // Clear APCu cache if available (modern PHP 7+)
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }

        // Clear any custom caches
        if (isset($GLOBALS['wp_object_cache']) && is_object($GLOBALS['wp_object_cache'])) {
            $GLOBALS['wp_object_cache']->flush();
        }

        // Clear server-specific caches
        $this->handleServerSpecificCaches();

        clean_sweep_log_message("🎉 All caches cleared successfully", 'info');
        $this->sendJsonResponse(true, 'All caches cleared successfully');
    }

    /**
     * Handle canary file check request
     * Verifies if the fresh environment setup is complete
     */
    private function handleCanaryCheck() {
        $canary_path = dirname(dirname(__DIR__)) . '/core/fresh/.clean-sweep-canary.php';

        if (file_exists($canary_path)) {
            $this->sendJsonResponse(true, 'Environment is ready');
        } else {
            $this->sendJsonResponse(false, 'Environment not ready');
        }
    }

    /**
     * Clear server-specific caches based on hosting environment
     * Add server-specific cache clearing functions here as needed
     */
    private function handleServerSpecificCaches() {
        // WPMUDEV Hosting detection and cache clearing
        if (isset($_SERVER['WPMUDEV_HOSTED'])) {
            if (function_exists('curl_init')) {
                try {
                    // Build domain and resolver
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                    $domain   = $protocol . rtrim($_SERVER['HTTP_HOST'], '/');
                    $resolver = str_replace(array('http://', 'https://'), '', $domain) . ':443:127.0.0.1';

                    // Purge site root instead of current script to clear homepage and main pages
                    $path = '/'; // Site root - this will clear homepage and main cached pages
                    $url  = $domain . $path;

                    $ch = curl_init();
                    curl_setopt_array($ch, array(
                        CURLOPT_URL                  => $url,
                        CURLOPT_RETURNTRANSFER       => true,
                        CURLOPT_CUSTOMREQUEST        => 'PURGE',
                        CURLOPT_DNS_USE_GLOBAL_CACHE => false,
                        CURLOPT_RESOLVE              => array($resolver),
                        CURLOPT_TIMEOUT              => 10,
                    ));

                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curl_error = curl_error($ch);
                    curl_close($ch);

                    if (empty($curl_error) && (strpos(strtoupper($response), 'OK') !== false || $http_code === 200 || $http_code === 204)) {
                        clean_sweep_log_message("✅ WPMUDEV cache purge successful", 'info');
                        // Test cache effectiveness by making a follow-up request
                        $this->testCacheEffectiveness($url);
                    }

                } catch (Exception $e) {
                    // Silently fail - cache purging is not critical
                }
            }
        }
    }

    /**
     * Test cache effectiveness by making a follow-up request
     * This helps verify if the PURGE request actually cleared the cache
     *
     * @param string $url The URL that was purged
     */
    private function testCacheEffectiveness($url) {
        try {
            // Add a cache-busting parameter to ensure we get a fresh response
            $test_url = $url . (strpos($url, '?') !== false ? '&' : '?') . 'cache_test=' . time() . rand(1000, 9999);

            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL            => $test_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_NOBODY         => false, // We want the body to check for dynamic content
            ));

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200 && strpos($response, 'cache_test=') !== false) {
                // Cache appears to be working correctly
                return;
            }
        } catch (Exception $e) {
            // Silently fail - cache testing is not critical
        }
    }

    /**
     * Detect the real WordPress site root directory
     *
     * @return string Site root path with trailing slash
     */
    private function detectSiteRoot() {
        // Try to find wp-config.php by walking up from current directory
        $current_dir = dirname(__DIR__); // system/ directory
        $max_levels = 5;

        for ($i = 0; $i < $max_levels; $i++) {
            $config_path = $current_dir . '/wp-config.php';
            if (file_exists($config_path)) {
                return rtrim($current_dir, '/') . '/';
            }
            $current_dir = dirname($current_dir);
        }

        // Fallback: assume we're in wp-content/plugins/ structure
        $current_dir = dirname(dirname(dirname(__DIR__))); // Go up 3 levels from system/
        return rtrim($current_dir, '/') . '/';
    }

    /**
     * Send JSON response
     *
     * @param bool $success Success status
     * @param string $message Response message
     * @param array $data Additional data
     */
    private function sendJsonResponse($success, $message = '', $data = []) {
        header('Content-Type: application/json');
        echo json_encode(array_merge([
            'success' => $success,
            'message' => $message
        ], $data));
        exit;
    }
}
