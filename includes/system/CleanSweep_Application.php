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
                $this->handle_analyze_plugins();
                break;

            case 'reinstall_plugins':
                $this->handle_reinstall_plugins();
                break;

            case 'reinstall_core':
                $this->handle_reinstall_core();
                break;

            case 'extract_zip':
                $this->handle_extract_zip();
                break;

            case 'scan_malware':
                $this->handle_scan_malware();
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

    private function handle_analyze_plugins() {
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

            // 2. Now capture ONLY the clean HTML
            ob_start();
            clean_sweep_display_plugins_tab_content($analysis_results);
            $html_content = ob_get_clean();

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
            clean_sweep_display_toolkit_interface($analysis_results);
        }
    }

    private function handle_reinstall_plugins() {
        $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : null;
        $batch_start = isset($_POST['batch_start']) ? intval($_POST['batch_start']) : 0;
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;

        // OPTIMIZATION: Analyze plugins once, reuse for all batches
        if ($batch_start == 0) {
            // FIRST BATCH: Perform analysis and store for reuse
            $analysis = clean_sweep_analyze_plugins($progress_file);
            set_transient('clean_sweep_batch_analysis', $analysis, 3600); // 1 hour expiry
            clean_sweep_log_message("Plugin analysis stored for optimized batch processing");
        } else {
            // SUBSEQUENT BATCHES: Use stored analysis for instant processing
            $analysis = get_transient('clean_sweep_batch_analysis');
            if (!$analysis) {
                // Fallback if transient expired (rare - storage/save issue)
                clean_sweep_log_message("Warning: Stored analysis expired, re-analyzing", 'warning');
                $analysis = clean_sweep_analyze_plugins($progress_file);
                set_transient('clean_sweep_batch_analysis', $analysis, 3600);
            }
        }

        $repo_plugins = $analysis['wp_org_plugins'];  // WordPress.org plugins to reinstall
        $wpmu_dev_plugins = $analysis['wpmu_dev_plugins'];  // WPMU DEV plugins to reinstall
        $suspicious_files = $analysis['suspicious_files'] ?? [];  // Suspicious files to delete

        // Only log analysis details on first batch to avoid spam
        if ($batch_start == 0) {
            $total_categorized = count($repo_plugins) + count($wpmu_dev_plugins);
            clean_sweep_log_message("AJAX Reinstall: Total plugins from analysis: $total_categorized" .
                                  ", WordPress.org: " . count($repo_plugins) .
                                  ", WPMU DEV: " . count($wpmu_dev_plugins) .
                                  ", Suspicious files: " . count($suspicious_files));
        }

        if ($progress_file) {
            // AJAX request - return JSON response
            try {
                // Use the new PluginReinstaller for comprehensive plugin and file management
                $reinstaller = new CleanSweep_PluginReinstaller();
                $reinstall_result = $reinstaller->start_reinstallation(
                    $progress_file,
                    false, // create_backup - handled separately
                    true,  // proceed_without_backup
                    $repo_plugins,
                    $wpmu_dev_plugins,
                    $suspicious_files,
                    $batch_start,
                    $batch_size
                );

                // Format result for compatibility with existing code
                $execution_data = [
                    'results' => $reinstall_result,
                    'verification_results' => ['verified' => [], 'missing' => [], 'corrupted' => []]
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
                    ob_start();
                    clean_sweep_display_final_results($reinstall_results, $verification_results);
                    $html_content = ob_get_clean();
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

    private function handle_reinstall_core() {
        $wp_version = isset($_POST['wp_version']) ? $_POST['wp_version'] : 'latest';
        clean_sweep_execute_core_reinstallation($wp_version);
    }

    private function handle_extract_zip() {
        if (!defined('WP_CLI') || !WP_CLI) {
            echo '<h2>📁 ZIP Extraction Started</h2>';
            echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
            echo '<p><strong>Process initiated successfully!</strong> Extracting uploaded ZIP file...</p>';
            echo '</div>';
            ob_flush();
            flush();
        }

        clean_sweep_execute_zip_extraction();
    }

    private function handle_scan_malware() {
        $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : null;

        if ($progress_file) {
            $this->handle_scan_malware_ajax($progress_file);
        } else {
            // Regular request - show progress and results
            if (!defined('WP_CLI') || !WP_CLI) {
                echo '<h2>🔍 Malware Scan Started</h2>';
                echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
                echo '<p><strong>Scan initiated successfully!</strong> Scanning for malware threats...</p>';
                echo '</div>';
                ob_flush();
                flush();
            }

            $scan_results = clean_sweep_execute_malware_scan();
            clean_sweep_display_toolkit_interface(null, $scan_results);
        }
    }

    private function handle_scan_malware_ajax($progress_file) {
        // AJAX request - return JSON response
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
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            ob_start();
            $analysis_results = clean_sweep_execute_malware_scan();
            ob_end_clean();

            if (!is_array($analysis_results)) {
                throw new Exception("Scan function returned invalid response type: " . gettype($analysis_results));
            }

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $total_threats = isset($analysis_results['summary']['total_threats']) ? $analysis_results['summary']['total_threats'] : 0;

            if ($total_threats > 50) {
                $request_id = 'scan_' . time() . '_' . mt_rand(1000, 9999);
                $cache_file = PROGRESS_DIR . '/' . 'threat_cache_' . $request_id . '.json';

                if (!is_dir(TEMP_DIR)) {
                    mkdir(TEMP_DIR, 0755, true);
                    clean_sweep_log_message("Created temp directory: " . TEMP_DIR, 'info');
                }

                $full_results_for_cache = clean_sweep_sanitize_utf8_array($analysis_results);
                file_put_contents($cache_file, json_encode($full_results_for_cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
                if (file_exists($cache_file)) {
                    chmod($cache_file, 0644);
                    clean_sweep_log_message("Cached {$total_threats} threat results to: {$cache_file}", 'info');
                }

                $analysis_results['truncated'] = true;
                $analysis_results['request_id'] = $request_id;
                $analysis_results['full_count'] = $total_threats;
                $analysis_results['log_file_reference'] = LOGS_DIR . LOG_FILE;
                $analysis_results['truncation_message'] = "Results truncated for browser performance. Found {$total_threats} total threats. Use the buttons below to load additional results.";

                foreach (['database', 'files'] as $section) {
                    if (isset($analysis_results[$section]) && is_array($analysis_results[$section])) {
                        foreach ($analysis_results[$section] as $key => &$value) {
                            if (is_array($value) && count($value) > 10) {
                                $value = array_slice($value, 0, 10, true);
                            }
                        }
                    }
                }
            }

            ob_start();
            clean_sweep_display_malware_scan_results($analysis_results);
            $html_content = ob_get_clean();

            ob_start();
            $completion_data = [
                'status' => 'complete',
                'progress' => 100,
                'message' => $analysis_results['truncation_message'] ?? 'Malware scan completed successfully!',
                'results' => $analysis_results
            ];
            clean_sweep_write_progress_file($progress_file, $completion_data);
            ob_end_clean();

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: application/json; charset=utf-8', true);
            $safe_analysis_results = clean_sweep_sanitize_utf8_array($analysis_results);
            $json_response = json_encode([
                'success' => true,
                'results' => $safe_analysis_results,
                'html' => $html_content
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON encoding failed: ' . json_last_error_msg());
            }

            if (!headers_sent()) {
                echo $json_response;
            } else {
                throw new Exception('Headers already sent - cannot send JSON');
            }
        } catch (Exception $e) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8', true);
                echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
        }
        exit;
    }

    private function handle_load_more_threats() {
        if (!$this->is_ajax_request) {
            echo json_encode(['success' => false, 'error' => 'Invalid request - no progress file']);
            exit;
        }

        $request_id = isset($_POST['request_id']) ? $_POST['request_id'] : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;

        clean_sweep_log_message("Load more threats request: request_id=$request_id, page=$page, per_page=$per_page", 'info');

        if (empty($request_id) || !preg_match('/^scan_\d+_\d+$/', $request_id)) {
            clean_sweep_log_message("ERROR: Invalid request_id format: $request_id", 'error');
            echo json_encode(['success' => false, 'error' => 'Invalid request ID format']);
            exit;
        }

        $temp_file = PROGRESS_DIR . '/' . 'threat_cache_' . $request_id . '.json';

        if (!file_exists($temp_file)) {
            echo json_encode(['success' => false, 'error' => 'Results expired or not found']);
            exit;
        }

        $full_results = json_decode(file_get_contents($temp_file), true);

        if (!$full_results) {
            echo json_encode(['success' => false, 'error' => 'Invalid cached results']);
            exit;
        }

        clean_sweep_log_message("Cache loaded successfully. Database keys: " . implode(', ', array_keys($full_results['database'] ?? [])), 'info');
        clean_sweep_log_message("Cache structure - Files keys: " . implode(', ', array_keys($full_results['files'] ?? [])), 'info');
        clean_sweep_log_message("Cache summary - Total threats: " . ($full_results['summary']['total_threats'] ?? 0), 'info');

        $all_threats = [];

        $database_section = $full_results['database'] ?? [];
        if (is_array($database_section)) {
            foreach ($database_section as $key => $value) {
                if (is_array($value) && !empty($value) && isset($value[0]['pattern'])) {
                    foreach ($value as $threat) {
                        if (is_array($threat)) {
                            $threat['_table'] = $key;
                            $threat['_section'] = 'database';
                            $all_threats[] = $threat;
                        }
                    }
                }
            }
        }

        $files_section = $full_results['files'] ?? [];
        if (is_array($files_section)) {
            foreach ($files_section as $key => $value) {
                if (is_array($value) && !empty($value) && isset($value[0]['pattern'])) {
                    foreach ($value as $threat) {
                        if (is_array($threat)) {
                            $threat['_file_category'] = $key;
                            $threat['_section'] = 'files';
                            $all_threats[] = $threat;
                        }
                    }
                }
            }
        }

        clean_sweep_log_message("Total flattened threats: " . count($all_threats), 'info');

        $total_threats = count($all_threats);
        $start = ($page - 1) * $per_page;
        $paginated_threats = array_slice($all_threats, $start, $per_page);
        $has_more = ($start + $per_page) < $total_threats;

        ob_start();
        foreach ($paginated_threats as $threat) {
            $section = $threat['_section'];
            unset($threat['_section'], $threat['_table'], $threat['_file_category']);

            if ($section === 'database') {
                echo '<li style="background:#f8f9fa;padding:10px;border-radius:4px;margin:10px 0;border:1px solid #dee2e6;">';
                $table = $threat['_table'] ?? 'unknown';
                echo '<div style="background:#007bff;color:white;padding:3px 8px;border-radius:3px;display:inline-block;font-size:11px;font-weight:bold;margin-bottom:5px;">📊 Database Threat</div><br>';
                echo '<strong>Pattern:</strong> <code style="font-size:11px;">' . htmlspecialchars($threat['pattern'] ?? '') . '</code><br>';
                echo '<strong>Match:</strong> <em style="font-size:12px;">' . htmlspecialchars($threat['match'] ?? '') . '</em><br>';

                if (isset($threat['option_name'])) {
                    echo '<strong>Option Name:</strong> <code>' . htmlspecialchars($threat['option_name']) . '</code><br>';
                }
                if (isset($threat['post_id'])) {
                    echo '<strong>Post ID:</strong> ' . $threat['post_id'] . ' (<a href="post.php?post=' . $threat['post_id'] . '&action=edit" style="color:#007bff;text-decoration:none;font-size:11px;">Edit Post →</a>)<br>';
                }
                if (isset($threat['meta_key'])) {
                    echo '<strong>Meta Key:</strong> <code>' . htmlspecialchars($threat['meta_key']) . '</code><br>';
                }
                if (isset($threat['comment_id'])) {
                    echo '<strong>Comment ID:</strong> ' . $threat['comment_id'] . '<br>';
                }
                if (isset($threat['user_id'])) {
                    echo '<strong>User ID:</strong> ' . $threat['user_id'] . '<br>';
                }
                echo '</li>';
            } elseif ($section === 'files') {
                echo '<li style="border:1px solid #f8d7da;padding:10px;border-radius:4px;margin:10px 0;background:#f8d7da;">';
                echo '<strong>File:</strong> ' . htmlspecialchars($threat['file'] ?? '') . '<br>';
                echo '<strong>Pattern:</strong> <code>' . htmlspecialchars($threat['pattern'] ?? '') . '</code><br>';
                echo '<strong>Line ' . ($threat['line_number'] ?? '?') . ':</strong> <em>' . htmlspecialchars($threat['match'] ?? '') . '</em>';
                echo '</li>';
            }
        }
        $html = ob_get_clean();

        header('Content-Type: application/json; charset=utf-8', true);
        echo json_encode([
            'success' => true,
            'html' => $html,
            'page' => $page,
            'loaded_count' => count($paginated_threats),
            'has_more' => $has_more,
            'total_loaded' => min($start + $per_page, $total_threats),
            'total_available' => $total_threats
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
        clean_sweep_output_html_header();
        clean_sweep_display_toolkit_interface();
        clean_sweep_output_html_footer();
    }
}
