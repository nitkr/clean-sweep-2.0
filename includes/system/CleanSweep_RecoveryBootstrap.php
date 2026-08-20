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
                if (!class_exists('CleanSweep_Functions', false)) {
                    require_once __DIR__ . '/CleanSweep_Functions.php';
                }
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
     * Show HTML setup interface - Uses Svelte app with RecoveryMode component
     * Integrates with the modern Svelte + Bits UI frontend
     */
    private function showHtmlSetupInterface() {
        // Double-check: if environment became valid while loading the page, redirect immediately
        if ($this->fresh_env->isValid()) {
            clean_sweep_log_message("Environment became valid during page load, redirecting to app", 'info');
            echo '<script>window.location.href = window.location.pathname + "?recovery_token=" + Date.now();</script>';
            return;
        }
        
        // Detect recovery issues for Svelte app
        $recovery_issues = array();
        $fresh_dir = dirname(dirname(__DIR__)) . '/core/fresh';
        
        if (!is_dir($fresh_dir)) {
            $recovery_issues[] = 'missing_fresh_directory';
        }
        
        $canary_path = $fresh_dir . '/.clean-sweep-canary.php';
        if (!file_exists($canary_path)) {
            $recovery_issues[] = 'missing_canary';
        }
        
        $required_files = ['wp-load.php', 'wp-settings.php'];
        foreach ($required_files as $file) {
            if (!file_exists($fresh_dir . '/' . $file)) {
                $recovery_issues[] = 'wp_settings_corrupt';
                break;
            }
        }
        
        // Pass recovery data to Svelte app
        $recovery_data = array(
            'isRecoveryMode' => true,
            'issues' => $recovery_issues
        );
        
        // Output Svelte app shell - same as main app but with recovery mode
        echo '<!DOCTYPE html><html lang="en"><head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>Clean Sweep - Recovery Mode</title>';
        
        // Svelte compiled app CSS
        echo '<link rel="stylesheet" href="assets/dist/clean-sweep.css">';
        
        // Custom CSS (Slate theme)
        echo '<link rel="stylesheet" href="assets/css/custom.css">';
        
        // Pass recovery data to Svelte app
        echo '<script>window.cleanSweepRecovery = ' . json_encode($recovery_data) . ';</script>';
        
        // Svelte compiled app (from Vite build)
        echo '<script type="module" src="assets/dist/clean-sweep.js"></script>';
        
        echo '</head>';
        echo '<body class="bg-app text-ink antialiased min-h-screen transition-colors">';
        echo '<div id="app"></div>';
        echo '</body></html>';
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
                break;

            case 'check_canary':
                $this->handleCanaryCheck();
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
        $this->fresh_env->setProgressFile($progress_file);

        $plan = $this->fresh_env->resolveDownloadPlan();
        clean_sweep_write_progress_file($progress_file, [
            'status' => 'running',
            'progress' => 8,
            'message' => $plan['reason'] ?? 'Preparing recovery environment…',
            'step' => 'resolve',
            'wp_version' => $plan['version'] ?? '',
            'plan_source' => $plan['source'] ?? '',
        ]);

        // Reset execution time before long operation
        clean_sweep_reset_execution_time();

        // Start setup process (writes finer progress itself)
        if ($this->fresh_env->setup()) {
            $_SESSION['fresh_env_setup_complete'] = time();
            clean_sweep_log_message('Session flag set: fresh_env_setup_complete = ' . $_SESSION['fresh_env_setup_complete'], 'info');

            $this->sendJsonResponse(true, 'Setup completed successfully', [
                'wp_version' => $plan['version'] ?? '',
                'plan_source' => $plan['source'] ?? '',
            ]);
        } else {
            // setup() already reports error progress when possible
            $this->sendJsonResponse(false, 'Setup failed. You can upload a recovery package instead.');
        }
    }

    /**
     * Handle progress check using existing progress file system
     */
    private function handleGetProgress() {
        $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : 'recovery_setup';

        $progress_path = CLEAN_SWEEP_PROGRESS_DIR . '/' . $progress_file . '.progress';

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
        $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : 'recovery_setup';
        $this->fresh_env->setProgressFile($progress_file);

        if (!isset($_FILES['recovery_zip']) || $_FILES['recovery_zip']['error'] !== UPLOAD_ERR_OK) {
            clean_sweep_write_progress_file($progress_file, [
                'status' => 'error',
                'progress' => 0,
                'message' => 'No recovery package uploaded (or upload error).',
                'step' => 'upload',
            ]);
            $this->sendJsonResponse(false, 'No file uploaded or upload error');
            return;
        }

        $name = (string) ($_FILES['recovery_zip']['name'] ?? '');
        if ($name !== '' && !preg_match('/\.zip$/i', $name)) {
            $this->sendJsonResponse(false, 'Please upload a .zip recovery package.');
            return;
        }

        clean_sweep_write_progress_file($progress_file, [
            'status' => 'running',
            'progress' => 15,
            'message' => 'Uploading recovery package…',
            'step' => 'upload',
        ]);

        clean_sweep_reset_execution_time();

        $temp_file = $_FILES['recovery_zip']['tmp_name'];
        if ($this->fresh_env->setup($temp_file)) {
            $_SESSION['fresh_env_setup_complete'] = time();
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
