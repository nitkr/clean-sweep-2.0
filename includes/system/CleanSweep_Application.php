<?php
/**
 * Clean Sweep - Main Application Class
 *
 * Handles the main application logic and request routing.
 *
 * @version 1.0
 * @author Nithin K R
 */

class CleanSweep_Application {

    private $is_ajax_request;

    public function __construct($is_ajax_request = false) {
        $this->is_ajax_request = $is_ajax_request;
    }

    /**
     * Main execution function for Clean Sweep toolkit
     * Routes requests to appropriate handlers based on action parameter
     */
    public function run() {
        // ACCESS GLOBAL VARIABLES
        global $is_ajax_request;

        // DEBUG LOGGING FOR PAGINATION ISSUES
        clean_sweep_log_message("=== REQUEST ANALYSIS ===", 'info');
        clean_sweep_log_message("Action: " . (isset($_POST['action']) ? $_POST['action'] : 'NOT_SET'), 'info');
        clean_sweep_log_message("Request ID: " . (isset($_POST['request_id']) ? $_POST['request_id'] : 'NOT_SET'), 'info');
        clean_sweep_log_message("Page: " . (isset($_POST['page']) ? $_POST['page'] : 'NOT_SET'), 'info');
        clean_sweep_log_message("Per Page: " . (isset($_POST['per_page']) ? $_POST['per_page'] : 'NOT_SET'), 'info');
        clean_sweep_log_message("Progress File: " . (isset($_POST['progress_file']) ? $_POST['progress_file'] : 'NOT_SET'), 'info');
        clean_sweep_log_message("Is AJAX: " . ($is_ajax_request ? 'YES' : 'NO'), 'info');

        // Determine the requested action from POST data
        $action = isset($_POST['action']) ? $_POST['action'] : '';

        clean_sweep_log_message("Executing action: $action", 'info');

        switch ($action) {
            case 'analyze_plugins':
                $this->clean_sweep_handle_analyze_plugins();
                break;

            case 'reinstall_plugins':
                $this->clean_sweep_handle_reinstall_plugins();
                break;

            case 'reinstall_core':
                $this->clean_sweep_handle_reinstall_core();
                break;

            case 'establish_core_baseline':
                $this->handle_establish_core_baseline();
                break;

            case 'export_baseline':
                $this->clean_sweep_handle_export_baseline();
                break;

            case 'import_baseline':
                $this->clean_sweep_handle_import_baseline();
                break;

            case 'compare_baselines':
                $this->handle_compare_baselines();
                break;

            case 'save_comprehensive_baseline_setting':
                $this->handle_save_comprehensive_baseline_setting();
                break;

            case 'extract_zip':
                $this->clean_sweep_handle_extract_zip();
                break;

            case 'scan_malware':
                $this->handle_scan_malware();
                break;

            case 'run_integrity_check_async':
                $this->handle_run_integrity_check_async();
                break;

            case 'load_more_threats':
                $this->handle_load_more_threats();
                break;



            case 'cleanup':
                $this->handle_cleanup();
                break;

            default:
                $this->handle_default();
                break;
        }
    }

    private function clean_sweep_handle_analyze_plugins() {
        $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : null;

        if ($progress_file) {
            // AJAX request - return JSON response
            // 1. Suppress ALL output during operations
            ob_start();
            $analysis_results = clean_sweep_analyze_plugins($progress_file);
            ob_end_clean(); // Discard any warnings/errors

            // Write completion status
            $completion_data = [
                'status' => 'complete',
                'progress' => 100,
                'message' => 'Plugin analysis completed successfully!',
                'results' => $analysis_results
            ];
            clean_sweep_write_progress_file($progress_file, $completion_data);

            // 2. Now capture ONLY the clean HTML - skip plugin content generation
            // Alpine.js will handle displaying results via the API
            $html_content = '';
            // Results will be loaded via AJAX calls to the API endpoints

            // 3. Clean all output buffers to ensure nothing else is sent
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // 4. Return properly encoded JSON
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode([
                'success' => true,
                'results' => $analysis_results,
                'html' => $html_content
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            exit;
        } else {
            // Regular request - show progress and results
            if (!defined('WP_CLI') || !WP_CLI) {
                echo '<h2>🔍 Plugin Analysis Started</h2>';
                echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
                echo '<p><strong>Analysis initiated successfully!</strong> Scanning all installed plugins...</p>';
                echo '</div>';
                ob_flush();
                flush();
            }

            $analysis_results = clean_sweep_analyze_plugins();
            // For regular requests, just show the Alpine.js UI - results will be loaded via AJAX
            // The UI will handle displaying results through the API endpoints
        }
    }

    private function clean_sweep_handle_reinstall_plugins() {
        $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : null;
        $batch_start = isset($_POST['batch_start']) ? intval($_POST['batch_start']) : 0;
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;

        // JavaScript-only batching: Analysis data must be provided with each request
        $analysis = isset($_POST['existing_analysis']) ?
            json_decode(stripslashes($_POST['existing_analysis']), true) : null;

        if (!$analysis || !is_array($analysis)) {
            // This should never happen with proper JavaScript implementation
            clean_sweep_log_message("ERROR: No analysis data provided with batch request", 'error');
            // Fallback for safety (though this indicates a JavaScript issue)
            $analysis = clean_sweep_analyze_plugins($progress_file);
        } else {
            clean_sweep_log_message("Using analysis data from JavaScript batch request", 'debug');
        }

        // Check if selective plugin filtering was requested via AJAX
        $selective_repo_plugins = isset($_POST['repo_plugins']) ?
            json_decode(stripslashes($_POST['repo_plugins']), true) : null;

        if ($selective_repo_plugins !== null && is_array($selective_repo_plugins)) {
            // Use selectively filtered plugins from AJAX request
            clean_sweep_log_message("Using selective plugin filtering: " . count($selective_repo_plugins) . " plugins selected");

            // Separate filtered plugins into WP.org and WPMU DEV
            $repo_plugins = [];
            $wpmu_dev_plugins = [];

            foreach ($selective_repo_plugins as $slug => $plugin_data) {
                // Check if this is a WPMU DEV plugin by looking at analysis data
                $is_wpmu_dev = isset($analysis['wpmu_dev_plugins'][$slug]);

                if ($is_wpmu_dev) {
                    $wpmu_dev_plugins[$slug] = $plugin_data;
                } else {
                    $repo_plugins[$slug] = $plugin_data;
                }
            }

            $suspicious_files = $analysis['suspicious_files'] ?? []; // Always clean suspicious files for security

            // Log selective mode details
            if ($batch_start == 0) {
                clean_sweep_log_message("Selective AJAX Reinstall: " . count($selective_repo_plugins) . " total selected" .
                                      ", WordPress.org: " . count($repo_plugins) .
                                      ", WPMU DEV: " . count($wpmu_dev_plugins));
            }
        } else {
            // Fallback to full analysis (legacy behavior)
            $repo_plugins = $analysis['wp_org_plugins'];  // WordPress.org plugins to reinstall
            $wpmu_dev_plugins = $analysis['wpmu_dev_plugins'];  // WPMU DEV plugins to reinstall
            $suspicious_files = $analysis['suspicious_files'] ?? [];  // Suspicious files to delete

            // Only log analysis details on first batch to avoid spam
            if ($batch_start == 0) {
                $total_categorized = count($repo_plugins) + count($wpmu_dev_plugins);
                clean_sweep_log_message("Full AJAX Reinstall: Total plugins from analysis: $total_categorized" .
                                      ", WordPress.org: " . count($repo_plugins) .
                                      ", WPMU DEV: " . count($wpmu_dev_plugins) .
                                      ", Suspicious files: " . count($suspicious_files));
            }
        }

        if ($progress_file) {
            // AJAX request - return JSON response
            try {
                // Use the new PluginReinstaller for comprehensive plugin and file management
                $reinstaller = new CleanSweep_PluginReinstaller();
                $reinstall_result = $reinstaller->start_reinstallation(
                    $progress_file,
                    isset($_POST['create_backup']) ? filter_var($_POST['create_backup'], FILTER_VALIDATE_BOOLEAN) : false,
                    isset($_POST['proceed_without_backup']) ? filter_var($_POST['proceed_without_backup'], FILTER_VALIDATE_BOOLEAN) : false,
                    $repo_plugins,
                    $wpmu_dev_plugins,
                    $suspicious_files,
                    $batch_start,
                    $batch_size
                );

                // Perform actual verification of installed plugins
                $wp_org_verification = clean_sweep_verify_installations($repo_plugins);
                $wpmu_dev_verification = clean_sweep_verify_wpmudev_installations($wpmu_dev_plugins);

                // Merge verification results
                $verification_results = [
                    'verified' => array_merge($wp_org_verification['verified'], $wpmu_dev_verification['verified']),
                    'missing' => array_merge($wp_org_verification['missing'], $wpmu_dev_verification['missing']),
                    'corrupted' => array_merge($wp_org_verification['corrupted'], $wpmu_dev_verification['corrupted'])
                ];

                // Filter out WPMU DEV Dashboard from verification results (it was skipped, not reinstalled)
                $verification_results['verified'] = array_filter($verification_results['verified'], function($plugin) {
                    // Remove WPMU DEV Dashboard - it appears as verified but was never reinstalled
                    if (isset($plugin['name']) && strpos($plugin['name'], 'WPMU DEV Dashboard') !== false) {
                        return false;
                    }
                    if (isset($plugin['slug']) && strpos($plugin['slug'], 'wpmudev-updates') !== false) {
                        return false;
                    }
                    return true;
                });

                // Build skipped list from both non-repo plugins and explicitly skipped plugins
                $non_repo_plugins = $analysis['non_repo_plugins'] ?? [];
                $skipped_plugins = $analysis['skipped_plugins'] ?? [];

                $reinstall_result['skipped'] = [];

                // Add non-repository plugins to skipped list
                foreach ($non_repo_plugins as $plugin_file => $plugin_data) {
                    $reinstall_result['skipped'][] = [
                        'name' => $plugin_data['name'] ?? $plugin_file,
                        'slug' => $plugin_data['slug'] ?? $plugin_file,
                        'reason' => $plugin_data['reason'] ?? 'Non-repository plugin'
                    ];
                }

                // Add explicitly skipped plugins (like WPMU DEV Dashboard)
                foreach ($skipped_plugins as $plugin_file => $plugin_data) {
                    $reinstall_result['skipped'][] = [
                        'name' => $plugin_data['name'] ?? $plugin_file,
                        'slug' => $plugin_data['slug'] ?? $plugin_file,
                        'reason' => $plugin_data['reason'] ?? 'Plugin cannot be reinstalled'
                    ];
                }

                // Format result for compatibility with existing code
                $execution_data = [
                    'results' => $reinstall_result,
                    'verification_results' => $verification_results
                ];

                // Suppress output during operations
                ob_start();
                // Don't call the old function anymore
                ob_end_clean(); // Discard any warnings/errors

                // Extract results and verification_results from execution
                $reinstall_results = $execution_data['results'] ?? ['successful' => [], 'failed' => []];
                $verification_results = $execution_data['verification_results'] ?? ['verified' => [], 'missing' => [], 'corrupted' => []];

                // Check if this is the final batch
                $batch_info = $reinstall_results['batch_info'] ?? [];
                $is_final_batch = !($batch_info['has_more_batches'] ?? false);

                if ($is_final_batch) {
                    // Final batch - plugin-reinstall.php now returns both results and verification results

                    // Write completion status
                    $completion_data = [
                        'status' => 'complete',
                        'progress' => 100,
                        'message' => 'Plugin re-installation completed successfully!',
                        'results' => $reinstall_results
                    ];
                    clean_sweep_write_progress_file($progress_file, $completion_data);

                    // Generate HTML for final batch using real verification results
                    // Skip HTML content generation - Alpine.js will handle displaying results via API
                    $html_content = '';
                    // Results will be loaded via AJAX calls to the API endpoints
                } else {
                    // More batches to process - return batch info
                    $html_content = '';
                }

                // 3. Clean all output buffers to ensure nothing else is sent
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }

                // 4. Return properly encoded JSON
                header('Content-Type: application/json; charset=utf-8', true);
                echo json_encode([
                    'success' => true,
                    'results' => $reinstall_results,
                    'html' => $html_content,
                    'batch_info' => $batch_info,
                    'is_final_batch' => $is_final_batch
                ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                exit;
            } catch (Exception $e) {
                // Clean any output buffers
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }

                // Return error response for AJAX
                header('Content-Type: application/json; charset=utf-8', true);
                echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                exit;
            }
            exit;
        } else {
            // Regular request - show progress and results
            if (!defined('WP_CLI') || !WP_CLI) {
                echo '<h2>🚀 Plugin Re-installation Started</h2>';
                echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
                echo '<p><strong>Process initiated successfully!</strong> The system is now creating backups and re-installing plugins from WordPress.org.</p>';
                echo '<p>Please wait while we process ' . count($repo_plugins) . ' plugins...</p>';
                echo '</div>';
                ob_flush();
                flush();
            }

            clean_sweep_execute_reinstallation($repo_plugins);
        }
    }

    private function clean_sweep_handle_reinstall_core() {
        $wp_version = isset($_POST['wp_version']) ? $_POST['wp_version'] : 'latest';
        clean_sweep_execute_core_reinstallation($wp_version);
    }

    private function handle_establish_core_baseline() {
        try {
            // Clean output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Establish the core integrity baseline
            $result = clean_sweep_establish_core_baseline();

            // Return JSON response
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Core integrity baseline established successfully' : 'Failed to establish baseline'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            // Clean any output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Return error response
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    private function clean_sweep_handle_export_baseline() {
        try {
            // Clean output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Get integrity manager and export baseline
            global $clean_sweep_functions;
            $integrity_manager = $clean_sweep_functions->get_integrity_manager();
            $result = $integrity_manager->export_baseline();

            if (isset($result['error'])) {
                header('Content-Type: application/json; charset=utf-8', true);
                echo json_encode(['success' => false, 'error' => $result['error']], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Return JSON response with export data
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode([
                'success' => true,
                'data' => $result['data'],
                'filename' => $result['filename'],
                'message' => 'Baseline exported successfully'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            // Clean any output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Return error response
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    private function clean_sweep_handle_import_baseline() {
        try {
            // Clean output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Check if file was uploaded
            if (!isset($_FILES['baseline_file']) || $_FILES['baseline_file']['error'] !== UPLOAD_ERR_OK) {
                header('Content-Type: application/json; charset=utf-8', true);
                echo json_encode(['success' => false, 'error' => 'No file uploaded'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Read uploaded file
            $file_content = file_get_contents($_FILES['baseline_file']['tmp_name']);
            if ($file_content === false) {
                header('Content-Type: application/json; charset=utf-8', true);
                echo json_encode(['success' => false, 'error' => 'Failed to read uploaded file'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Get integrity manager and import baseline
            global $clean_sweep_functions;
            $integrity_manager = $clean_sweep_functions->get_integrity_manager();
            $result = $integrity_manager->import_baseline($file_content);

            if (isset($result['error'])) {
                header('Content-Type: application/json; charset=utf-8', true);
                echo json_encode(['success' => false, 'error' => $result['error']], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Return success response
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode([
                'success' => true,
                'message' => 'Baseline imported and verified successfully',
                'metadata' => $result['metadata']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            // Clean any output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Return error response
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    private function handle_compare_baselines() {
        try {
            // Clean output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Get integrity manager and perform comparison
            global $clean_sweep_functions;
            $integrity_manager = $clean_sweep_functions->get_integrity_manager();

            // For now, just run the standard reinfection check
            // TODO: Extend to compare against imported baseline specifically
            $violations = clean_sweep_check_for_reinfection();

            // Return JSON response
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode([
                'success' => true,
                'violations' => $violations,
                'total_violations' => count($violations),
                'message' => count($violations) > 0 ?
                    "Found " . count($violations) . " integrity violations" :
                    "No integrity violations detected"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            // Clean any output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Return error response
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    private function handle_save_comprehensive_baseline_setting() {
        try {
            // Clean output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Get the enabled parameter
            $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';

            // Save to session
            $_SESSION['clean_sweep_comprehensive_baseline'] = $enabled;

            clean_sweep_log_message("Comprehensive baseline monitoring " . ($enabled ? 'enabled' : 'disabled'), 'info');

            // Return success response
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode([
                'success' => true,
                'enabled' => $enabled,
                'message' => 'Setting saved successfully'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            // Clean any output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Return error response
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    private function clean_sweep_handle_extract_zip() {
        if (function_exists('clean_sweep_log_message')) {
            clean_sweep_log_message('HTML extract_zip is gone; use the JSON upload API', 'info');
        }
        if (!headers_sent()) {
            http_response_code(410);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'error' => 'HTML ZIP extract is no longer available. Use the JSON upload API.',
            'code' => 'GONE',
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function handle_scan_malware() {
        $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : null;

        if ($progress_file) {
            $this->handle_scan_malware_ajax($progress_file);
        } else {
            // Regular request: kick off a background scan and let the UI
            // poll for status. We do NOT run the scan synchronously
            // anymore (that was the root cause of "scan doesn't finish" -
            // a single-request drain would hit the host's max_execution_time).
            if (!defined('WP_CLI') || !WP_CLI) {
                echo '<h2>🔍 Malware Scan Started</h2>';
                echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
                echo '<p><strong>Scan initiated successfully!</strong> Scanning for malware threats...</p>';
                echo '</div>';
                ob_flush();
                flush();
            }

            $profile_id = $_POST['profile_id'] ?? 'standard';
            $scan_id = $this->start_background_scan($profile_id);
            echo '<p>Scan ID: <code>' . htmlspecialchars($scan_id) . '</code></p>';
        }
    }

    private function handle_scan_malware_ajax($progress_file) {
        // AJAX request: start a background scan via CleanSweep_Scanner, write the
        // progress file cache, return JSON with the scan_id.
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'Scan failed (fatal error)'], JSON_UNESCAPED_UNICODE);
                }
                exit;
            }
        });

        try {
            while (ob_get_level() > 0) ob_end_clean();
            ob_start();

            $profile_id = $_POST['profile_id'] ?? 'standard';
            // Match api/malware.php: Svelte JSON-stringifies custom_config into FormData.
            require_once CLEAN_SWEEP_ROOT . 'features/security/scan/Scanner.php';
            $raw_cfg = $_POST['custom_config'] ?? null;
            if (is_array($raw_cfg)) {
                $custom_config = $raw_cfg;
            } elseif (is_string($raw_cfg) && $raw_cfg !== '') {
                $decoded = json_decode(stripslashes($raw_cfg), true);
                if (!is_array($decoded)) {
                    throw new CleanSweep_ScanConfigException(
                        'custom_config must be a valid JSON object',
                        'INVALID_CUSTOM_CONFIG'
                    );
                }
                $custom_config = $decoded;
            } else {
                $custom_config = [];
            }
            $custom_config = CleanSweep_Scanner::normalizeCustomConfigScalars($custom_config);
            $scan_id = $this->start_background_scan($profile_id, $custom_config);

            // Write the initial progress file so the UI can begin polling
            // status immediately without waiting for a tick.
            @clean_sweep_write_progress_file($progress_file, [
                'status' => 'acknowledged',
                'scan_id' => $scan_id,
                'profile_id' => $profile_id,
                'progress' => 0,
                'message' => 'Scan acknowledged, starting...',
                'timestamp' => time(),
            ]);
            ob_end_clean();

            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8', true);
                echo json_encode([
                    'success' => true,
                    'scan_id' => $scan_id,
                    'status' => 'acknowledged',
                    'progress_file' => $progress_file,
                ], JSON_UNESCAPED_UNICODE);
            }
        } catch (CleanSweep_ScanConfigException $e) {
            while (ob_get_level() > 0) ob_end_clean();
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8', true);
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'code' => $e->errorCode,
                ], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            while (ob_get_level() > 0) ob_end_clean();
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8', true);
                echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
        }
        exit;
    }

    /**
     * Start a scan via the CleanSweep_Scanner and return the scan_id.
     * Tries to fire-and-forget via fastcgi_finish_request() so the
     * caller doesn't have to wait for the drain loop.
     */
    private function start_background_scan(string $profile_id, array $custom_config = []): string {
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/Scanner.php';
        // create('') then start() allocates the real scan_id — do not invent one here
        // or the client would poll a different id than the checkpoint/queue.
        $scanner = CleanSweep_Scanner::create('', $profile_id);
        $handle = $scanner->start($profile_id, $custom_config);
        $scan_id = $handle['scan_id'];

        // Always schedule drain after the HTTP response is sent. On FPM,
        // finish the request first; elsewhere shutdown still runs drain.
        register_shutdown_function(function () use ($scanner, $scan_id) {
            if (function_exists('fastcgi_finish_request')) {
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                @fastcgi_finish_request();
            }
            $scanner->drain($scan_id);
        });

        return $scan_id;
    }

    private function handle_run_integrity_check_async() {
        try {
            while (ob_get_level() > 0) ob_end_clean();

            clean_sweep_log_message("🔍 Running asynchronous integrity check after malware scan", 'info');

            // Reuse the same function the CleanSweep_IntegrityWorker uses, so the
            // two paths never diverge. We don't run a full scan here -
            // the worker for this single integrity check is independent
            // of the CleanSweep_Scanner's drain loop.
            if (!function_exists('clean_sweep_check_for_reinfection')) {
                throw new RuntimeException('Integrity function not available');
            }
            $violations = clean_sweep_check_for_reinfection();
            $count = is_array($violations) ? count($violations) : 0;

            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode([
                'success' => true,
                'violations' => $violations,
                'total_violations' => $count,
                'message' => $count > 0
                    ? "Found $count integrity violations"
                    : "No integrity violations detected",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Exception $e) {
            while (ob_get_level() > 0) ob_end_clean();
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8', true);
                echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }
    }

    private function handle_load_more_threats() {
        if (!$this->is_ajax_request) {
            echo json_encode(['success' => false, 'error' => 'Invalid request - no progress file']);
            exit;
        }

        $scan_id = isset($_POST['scan_id']) ? $_POST['scan_id'] : '';
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = isset($_POST['per_page']) ? max(1, min(500, intval($_POST['per_page']))) : 20;

        if (empty($scan_id) || !preg_match('/^bg_\d+_[a-f0-9]+$/', $scan_id)) {
            echo json_encode(['success' => false, 'error' => 'Invalid scan_id']);
            exit;
        }

        // Read directly from the CleanSweep_ThreatStore (JSONL). No temp cache file.
        require_once CLEAN_SWEEP_ROOT . 'features/security/scan/ThreatStore.php';
        $store = new CleanSweep_ThreatStore($scan_id);

        $offset = ($page - 1) * $per_page;
        $paginated = [];
        $idx = 0;
        $total = 0;
        $store->stream(function ($threat) use (&$paginated, &$idx, &$total, $offset, $per_page) {
            if ($idx >= $offset && count($paginated) < $per_page) {
                $paginated[] = $threat;
            }
            $idx++;
            $total++;
        });
        $has_more = ($offset + count($paginated)) < $total;

        header('Content-Type: application/json; charset=utf-8', true);
        echo json_encode([
            'success' => true,
            'scan_id' => $scan_id,
            'threats' => $paginated,
            'page' => $page,
            'loaded_count' => count($paginated),
            'has_more' => $has_more,
            'total_loaded' => min($offset + count($paginated), $total),
            'total_available' => $total,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }



    private function handle_cleanup() {
        if (!defined('WP_CLI') || !WP_CLI) {
            echo '<h2>🗑️ Clean Sweep Cleanup Started</h2>';
            echo '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:20px;border-radius:4px;margin:20px 0;">';
            echo '<p><strong>Cleanup initiated!</strong> Removing all Clean Sweep files and directories...</p>';
            echo '</div>';
            ob_flush();
            flush();
        }

        $cleanup = new CleanSweep_Cleanup();
        $cleanup->execute_cleanup();
    }

    private function handle_default() {
        // Show the Alpine.js UI - all functionality is handled via AJAX calls to API endpoints
        clean_sweep_output_html_header();
        clean_sweep_output_html_footer();
    }
}
