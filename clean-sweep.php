<?php
/**
 * Clean Sweep - WordPress Malware Cleanup Toolkit
 *
 * A comprehensive toolkit for WordPress malware cleanup and system restoration.
 * Features: Core file re-installation, plugin re-installation, and file upload/extraction.
 *
 * Usage:
 * 1. Upload the clean-sweep/ folder to your WordPress root directory
 * 2. Run via browser: http://yoursite.com/clean-sweep/clean-sweep.php
 * 3. Or run via command line: php clean-sweep/clean-sweep.php
 *
 * @version 2.0
 * @author Nithin K R
 */

// ============================================================================
// MODULE INCLUDES
// ============================================================================

// Core modular components
require_once __DIR__ . '/config.php';        // Configuration constants
require_once __DIR__ . '/utils.php';         // Utility functions
require_once __DIR__ . '/wordpress-api.php'; // WordPress API wrappers
require_once __DIR__ . '/ui.php';            // User interface components
require_once __DIR__ . '/display.php';       // Display and rendering functions

// Bootstrap Shield - Enhanced WordPress bootstrap protection
require_once __DIR__ . '/includes/system/bootstrap-shield.php';

// Batch Processing System - Reusable long-running operation framework
require_once __DIR__ . '/includes/system/batch-processing/CleanSweep_BatchProcessor.php';
require_once __DIR__ . '/includes/system/batch-processing/CleanSweep_ProgressManager.php';
require_once __DIR__ . '/includes/system/batch-processing/CleanSweep_BatchProcessingException.php';

// Feature-specific modules
require_once __DIR__ . '/features/maintenance/plugin-reinstall.php';  // Plugin reinstallation
require_once __DIR__ . '/features/maintenance/core-reinstall.php';    // Core file reinstallation
require_once __DIR__ . '/features/utilities/zip-extract.php';         // ZIP extraction
require_once __DIR__ . '/features/security/database-scan.php';        // Database scanning
require_once __DIR__ . '/features/security/malware-scan.php';         // Malware scanning

// ============================================================================
// INITIALIZATION
// ============================================================================

// Check if this is an AJAX request (has progress_file parameter) - BULLETPROOF DETECTION
$is_ajax_request = false;
$progress_file_param = isset($_POST['progress_file']) ? trim($_POST['progress_file']) : '';
if (!empty($progress_file_param)) {
    $is_ajax_request = true;
    clean_sweep_log_message("✅ AJAX request confirmed with progress_file: '$progress_file_param'", 'info');
} else {
    $is_ajax_request = false;
    clean_sweep_log_message("ℹ️ Regular request - no progress_file parameter", 'info');
}

// FOR AJAX REQUESTS: Complete error suppression to prevent JSON corruption
if ($is_ajax_request) {
    // Fatal error handler for AJAX (catches undefined constants, etc.)
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            // Output JSON error instead of HTML
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode(['success' => false, 'error' => 'Server error: ' . $error['message']], JSON_UNESCAPED_UNICODE);
            exit;
        }
    });

    // Runtime error suppression
    ini_set('display_errors', 0);
    error_reporting(0);

    // Additional safeguards
    ini_set('html_errors', 0); // Prevent HTML in error messages
    ini_set('log_errors', 1);  // Log errors instead of displaying
}

    // Output HTML header for browser execution (skip for AJAX requests)
    if (!$is_ajax_request) {
        clean_sweep_output_html_header();
    }



/**
 * Execute cleanup of all Clean Sweep files and directories
 * Memory-efficient version for managed hosting with limited memory
 */
function clean_sweep_execute_cleanup() {
    // Note: Cleanup operations are not logged to avoid creating log files during cleanup

    $clean_sweep_dir = __DIR__;
    $files_deleted = 0;
    $dirs_deleted = 0;

    if (!defined('WP_CLI') || !WP_CLI) {
        echo '<div style="background:#f8f9fa;border:1px solid #dee2e6;padding:20px;border-radius:4px;margin:20px 0;">';
        echo '<h3>🗑️ Deleting Clean Sweep Files...</h3>';
        echo '<pre style="background:#f5f5f5;padding:10px;border:1px solid #ddd;max-height:300px;overflow-y:auto;">';
    }

    // Memory-efficient cleanup: process directories one by one
    $subdirs = ['backups', 'logs', 'assets', 'features'];

    // First, delete subdirectories with large contents (backups and logs)
    foreach ($subdirs as $subdir) {
        $subdir_path = $clean_sweep_dir . '/' . $subdir;
        if (is_dir($subdir_path)) {
            if (!defined('WP_CLI') || !WP_CLI) {
                echo "🗂️  Processing directory: $subdir\n";
                ob_flush();
                flush();
            }

            // Use memory-efficient deletion for large directories
            $result = clean_sweep_delete_directory_efficiently($subdir_path);
            if ($result['success']) {
                $files_deleted += $result['files'];
                $dirs_deleted += $result['dirs'];
                if (!defined('WP_CLI') || !WP_CLI) {
                    echo "✅ Deleted directory: $subdir ({$result['files']} files, {$result['dirs']} dirs)\n";
                }
            } else {
                if (!defined('WP_CLI') || !WP_CLI) {
                    echo "❌ Failed to delete directory: $subdir\n";
                }
            }

            // Clear memory between operations
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
    }

    // Now delete remaining files in the root directory
    $remaining_files = glob($clean_sweep_dir . '/*');
    foreach ($remaining_files as $file) {
        $basename = basename($file);

        // Skip the main script for now
        if ($basename === 'clean-sweep.php') {
            continue;
        }

        if (is_file($file)) {
            if (unlink($file)) {
                $files_deleted++;
                if (!defined('WP_CLI') || !WP_CLI) {
                    echo "✅ Deleted file: $basename\n";
                }
            } else {
                if (!defined('WP_CLI') || !WP_CLI) {
                    echo "❌ Failed to delete file: $basename\n";
                }
            }
        } elseif (is_dir($file)) {
            // Delete any remaining directories
            if (clean_sweep_recursive_delete($file)) {
                $dirs_deleted++;
                if (!defined('WP_CLI') || !WP_CLI) {
                    echo "✅ Deleted directory: $basename\n";
                }
            } else {
                if (!defined('WP_CLI') || !WP_CLI) {
                    echo "❌ Failed to delete directory: $basename\n";
                }
            }
        }

        // Flush output for real-time feedback
        if (!defined('WP_CLI') || !WP_CLI) {
            ob_flush();
            flush();
        }

        // Clear memory between operations
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    // Try to delete the main directory (may fail if script is running from it)
    if (rmdir($clean_sweep_dir)) {
        $dirs_deleted++;
        if (!defined('WP_CLI') || !WP_CLI) {
            echo "✅ Deleted directory: clean-sweep\n";
        }
    } else {
        if (!defined('WP_CLI') || !WP_CLI) {
            echo "ℹ️  Main directory will be empty (script running from it)\n";
        }
    }

    // Finally, delete the main script
    $main_script = $clean_sweep_dir . '/clean-sweep.php';
    if (file_exists($main_script) && unlink($main_script)) {
        $files_deleted++;
        if (!defined('WP_CLI') || !WP_CLI) {
            echo "✅ Deleted file: clean-sweep.php\n";
        }
    }

    if (!defined('WP_CLI') || !WP_CLI) {
        echo '</pre>';
        echo '</div>';
    }

    if (!defined('WP_CLI') || !WP_CLI) {
        echo '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:20px;border-radius:4px;margin:20px 0;color:#155724;">';
        echo '<h3>🎉 Clean Sweep Cleanup Complete!</h3>';
        echo '<p><strong>Summary:</strong></p>';
        echo '<ul>';
        echo '<li>Files deleted: ' . $files_deleted . '</li>';
        echo '<li>Directories deleted: ' . $dirs_deleted . '</li>';
        echo '</ul>';
        echo '<p><strong>✅ All Clean Sweep files and directories have been successfully removed from your server.</strong></p>';
        echo '<p><em>This toolkit is no longer available. If you need it again in the future, you can re-upload it.</em></p>';
        echo '</div>';
    } else {
        echo "\n🗑️ CLEANUP COMPLETE\n";
        echo str_repeat("=", 30) . "\n";
        echo "Files deleted: $files_deleted\n";
        echo "Directories deleted: $dirs_deleted\n";
        echo "\n✅ Clean Sweep has been completely removed from your server.\n";
    }
}

/**
 * Memory-efficient directory deletion for large directories
 * Processes all items systematically to ensure complete removal
 */
function clean_sweep_delete_directory_efficiently($dir_path) {
    $files_deleted = 0;
    $dirs_deleted = 0;

    if (!is_dir($dir_path)) {
        return ['success' => false, 'files' => 0, 'dirs' => 0];
    }

    // Use scandir for more reliable directory reading
    $items = @scandir($dir_path);
    if ($items === false) {
        return ['success' => false, 'files' => 0, 'dirs' => 0];
    }

    // Remove . and .. entries
    $items = array_diff($items, ['.', '..']);

    // First pass: recursively delete all subdirectories
    foreach ($items as $item) {
        $full_path = $dir_path . '/' . $item;
        if (is_dir($full_path) && !is_link($full_path)) {
            $result = clean_sweep_delete_directory_efficiently($full_path);
            $files_deleted += $result['files'];
            $dirs_deleted += $result['dirs'];

            // Clear memory
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
    }

    // Second pass: delete all remaining files and symlinks
    foreach ($items as $item) {
        $full_path = $dir_path . '/' . $item;
        if (is_file($full_path) || is_link($full_path)) {
            if (@unlink($full_path)) {
                $files_deleted++;
            }
        }
    }

    // Clear memory before final deletion
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    // Finally, delete the directory itself
    if (@rmdir($dir_path)) {
        $dirs_deleted++;
        return ['success' => true, 'files' => $files_deleted, 'dirs' => $dirs_deleted];
    }

    return ['success' => false, 'files' => $files_deleted, 'dirs' => $dirs_deleted];
}

/**
 * Main execution function for Clean Sweep toolkit
 * Routes requests to appropriate handlers based on action parameter
 */
function clean_sweep_run_clean_sweep() {
    // ACCESS GLOBAL VARIABLES
    global $is_ajax_request;

    // Determine the requested action from POST data
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    clean_sweep_log_message("Executing action: $action", 'info');

    switch ($action) {
        case 'analyze_plugins':
            // Plugin analysis request - scan and categorize installed plugins
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

                $json_response = [
                    'success' => true,
                    ...$analysis_results,
                    'html' => $html_content
                ];

                // 4. Return properly encoded JSON
                header('Content-Type: application/json; charset=utf-8', true);
                echo json_encode($json_response, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
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
            break;

        case 'reinstall_plugins':
            // Plugin re-installation request - get categorized plugins from our analysis
            $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : null;
            $batch_start = isset($_POST['batch_start']) ? intval($_POST['batch_start']) : 0;
            $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;

            // OPTIMIZATION: Analyze plugins once, reuse for all batches
            $analysis_key = 'clean_sweep_analysis_' . md5($progress_file);
            if ($batch_start == 0) {
                // FIRST BATCH: Perform analysis and store for reuse
                $analysis = clean_sweep_analyze_plugins($progress_file);
                set_transient($analysis_key, $analysis, 3600); // 1 hour expiry
                clean_sweep_log_message("Plugin analysis stored for optimized batch processing");

                // Initialize cumulative results for first batch
                $cumulative_results_key = 'clean_sweep_results_' . md5($progress_file);
                set_transient($cumulative_results_key, [
                    'wordpress_org' => ['successful' => [], 'failed' => []],
                    'wpmu_dev' => ['successful' => [], 'failed' => []],
                    'verification_results' => ['verified' => [], 'missing' => [], 'corrupted' => []]
                ], 3600);
                clean_sweep_log_message("Initialized cumulative results with verification arrays", 'info');
                clean_sweep_log_message("Initialized cumulative results for batch processing", 'info');
            } else {
                // SUBSEQUENT BATCHES: Use stored analysis for instant processing
                $analysis = get_transient($analysis_key);
                if (!$analysis) {
                    // Fallback if transient expired (rare - storage/save issue)
                    clean_sweep_log_message("Warning: Stored analysis expired, re-analyzing", 'warning');
                    $analysis = clean_sweep_analyze_plugins($progress_file);
                    set_transient($analysis_key, $analysis, 3600);
                }
            }

            $repo_plugins = $analysis['wp_org_plugins'];  // WordPress.org plugins to reinstall
            $wpmu_dev_plugins = $analysis['wpmu_dev_plugins'];  // WPMU DEV plugins to reinstall
            $suspicious_files_to_delete = $analysis['suspicious_files'] ?? [];  // Suspicious files to delete

            // Only log analysis details on first batch to avoid spam
            if ($batch_start == 0) {
                $total_categorized = count($repo_plugins) + count($wpmu_dev_plugins);
                clean_sweep_log_message("AJAX Reinstall: Total plugins from analysis: $total_categorized" .
                                      ", WordPress.org: " . count($repo_plugins) .
                                      ", WPMU DEV: " . count($wpmu_dev_plugins) .
                                      ", Suspicious files: " . count($suspicious_files_to_delete));
            }

            if ($progress_file) {
                // AJAX request - return JSON response
                try {
                    // 1. Suppress ALL output during operations
                    ob_start();
                    $result = clean_sweep_execute_reinstallation($repo_plugins, $progress_file, $batch_start, $batch_size, $wpmu_dev_plugins, $suspicious_files_to_delete);
                    ob_end_clean(); // Discard any warnings/errors

                    // CRITICAL FIX: Detect backup choice responses and return them directly
                    if (isset($result['disk_check']) || isset($result['backup_choice'])) {
                        // This is backup choice data - return it directly without processing
                        clean_sweep_log_message("Action handler: Detected backup choice response, returning directly", 'info');
                        while (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                        header('Content-Type: application/json; charset=utf-8', true);
                        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                        exit;
                    }

                    // ACCUMULATE RESULTS ACROSS BATCHES
                    $cumulative_results_key = 'clean_sweep_results_' . md5($progress_file);
                    $cumulative_results = get_transient($cumulative_results_key);

                    if (!$cumulative_results) {
                        // Initialize if not found (shouldn't happen but safety check)
                        $cumulative_results = [
                            'wordpress_org' => ['successful' => [], 'failed' => []],
                            'wpmu_dev' => ['successful' => [], 'failed' => []],
                            'verification_results' => ['verified' => [], 'missing' => [], 'corrupted' => []]
                        ];
                        clean_sweep_log_message("Warning: Cumulative results not found, initializing", 'warning');
                    }

                    // Add current batch results to cumulative results
                    if (isset($result['wordpress_org'])) {
                        $cumulative_results['wordpress_org']['successful'] = array_merge(
                            $cumulative_results['wordpress_org']['successful'],
                            $result['wordpress_org']['successful'] ?? []
                        );
                        $cumulative_results['wordpress_org']['failed'] = array_merge(
                            $cumulative_results['wordpress_org']['failed'],
                            $result['wordpress_org']['failed'] ?? []
                        );
                    }

                    if (isset($result['wpmu_dev'])) {
                        $cumulative_results['wpmu_dev']['successful'] = array_merge(
                            $cumulative_results['wpmu_dev']['successful'],
                            $result['wpmu_dev']['successful'] ?? []
                        );
                        $cumulative_results['wpmu_dev']['failed'] = array_merge(
                            $cumulative_results['wpmu_dev']['failed'],
                            $result['wpmu_dev']['failed'] ?? []
                        );
                    }

                    // Accumulate verification results as well
                    if (isset($result['verification_results'])) {
                        $cumulative_results['verification_results']['verified'] = array_merge(
                            $cumulative_results['verification_results']['verified'],
                            $result['verification_results']['verified'] ?? []
                        );
                        $cumulative_results['verification_results']['missing'] = array_merge(
                            $cumulative_results['verification_results']['missing'],
                            $result['verification_results']['missing'] ?? []
                        );
                        $cumulative_results['verification_results']['corrupted'] = array_merge(
                            $cumulative_results['verification_results']['corrupted'],
                            $result['verification_results']['corrupted'] ?? []
                        );
                    }

                    // Save updated cumulative results
                    set_transient($cumulative_results_key, $cumulative_results, 3600);
                    clean_sweep_log_message("Updated cumulative results: WP.org successful=" .
                                           count($cumulative_results['wordpress_org']['successful']) .
                                           ", WPMU DEV successful=" . count($cumulative_results['wpmu_dev']['successful']), 'info');

                    // Prepare results for this batch response (current batch only)
                    $reinstall_results = [
                        'successful' => array_merge(
                            $result['wordpress_org']['successful'] ?? [],
                            $result['wpmu_dev']['successful'] ?? []
                        ),
                        'failed' => array_merge(
                            $result['wordpress_org']['failed'] ?? [],
                            $result['wpmu_dev']['failed'] ?? []
                        )
                    ];
                    $verification_results = $result['verification_results'] ?? ['verified' => [], 'missing' => [], 'corrupted' => []];

                    // FIX: Get batch_info from $result (top level), not $reinstall_results
                    $batch_info = $result['batch_info'] ?? [];

                    $is_final_batch = !($batch_info['has_more_batches'] ?? false);

                    if ($is_final_batch) {
                        clean_sweep_log_message("Action handler: FINAL batch - loading cumulative results");

                        // Load the complete cumulative results from all batches
                        $cumulative_results_key = 'clean_sweep_results_' . md5($progress_file);
                        $final_cumulative_results = get_transient($cumulative_results_key);

                        if (!$final_cumulative_results) {
                            clean_sweep_log_message("Warning: Final cumulative results not found, using current batch", 'warning');
                            $final_cumulative_results = [
                                'wordpress_org' => ['successful' => $result['wordpress_org']['successful'] ?? [], 'failed' => $result['wordpress_org']['failed'] ?? []],
                                'wpmu_dev' => ['successful' => $result['wpmu_dev']['successful'] ?? [], 'failed' => $result['wpmu_dev']['failed'] ?? []],
                                'verification_results' => $verification_results
                            ];
                        }

                        // Combine all successful and failed plugins from cumulative results
                        // Add source information to each plugin
                        $wordpress_org_successful = array_map(function($plugin) {
                            $plugin['source'] = 'wordpress_org';
                            return $plugin;
                        }, $final_cumulative_results['wordpress_org']['successful'] ?? []);

                        $wordpress_org_failed = array_map(function($plugin) {
                            $plugin['source'] = 'wordpress_org';
                            return $plugin;
                        }, $final_cumulative_results['wordpress_org']['failed'] ?? []);

                        $wpmu_dev_successful = array_map(function($plugin) {
                            $plugin['source'] = 'wpmu_dev';
                            return $plugin;
                        }, $final_cumulative_results['wpmu_dev']['successful'] ?? []);

                        $wpmu_dev_failed = array_map(function($plugin) {
                            $plugin['source'] = 'wpmu_dev';
                            return $plugin;
                        }, $final_cumulative_results['wpmu_dev']['failed'] ?? []);

                        $final_reinstall_results = [
                            'successful' => array_merge($wordpress_org_successful, $wpmu_dev_successful),
                            'failed' => array_merge($wordpress_org_failed, $wpmu_dev_failed)
                        ];

                        clean_sweep_log_message("Action handler: FINAL batch - generating HTML with cumulative results: successful=" .
                                               count($final_reinstall_results['successful']) . ", failed=" . count($final_reinstall_results['failed']), 'info');

                        // Generate HTML for final results using CUMULATIVE verification results
                        ob_start();
                        clean_sweep_display_final_results($final_reinstall_results, $final_cumulative_results['verification_results'] ?? $verification_results);
                        $html_content = ob_get_clean();

                        // Final batch - write completion status WITH HTML
                        $completion_data = [
                            'status' => 'complete',
                            'progress' => 100,
                            'message' => 'Plugin re-installation completed successfully!',
                            'results' => $final_reinstall_results,  // Use cumulative results
                            'html' => $html_content,  // Include HTML in progress file for JS to read
                            'batch_info' => $batch_info  // Include batch info
                        ];

                        clean_sweep_write_progress_file($progress_file, $completion_data);
                    } else {
                        clean_sweep_log_message("Action handler: INTERMEDIATE batch - updating progress with batch_info");
                        // Calculate total items for progress calculation
                        $total_items = count($repo_plugins) + count($wpmu_dev_plugins);
                        $batch_info['total_items'] = $total_items;  // Add total_items to batch_info

                        // INTERMEDIATE batch - update progress file to indicate more batches needed
                        $intermediate_data = [
                            'status' => 'processing',  // NOT complete for intermediate batches
                            'progress' => min(95, round(($batch_info['batch_start'] + $batch_info['batch_size']) / max($batch_info['total_items'], 1) * 100)),
                            'message' => 'Processing batch ' . (($batch_info['batch_start'] / $batch_info['batch_size']) + 1) . ' of ' . ceil($batch_info['total_items'] / $batch_info['batch_size']) . '...',
                            'batch_info' => $batch_info,  // Include batch info so JS knows to continue
                            'results' => $reinstall_results
                        ];
                        clean_sweep_write_progress_file($progress_file, $intermediate_data);
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
            break;

        case 'reinstall_core':
            // Core file re-installation request - replace WordPress core files
            $wp_version = isset($_POST['wp_version']) ? $_POST['wp_version'] : 'latest';
            clean_sweep_execute_core_reinstallation($wp_version);
            break;

        case 'extract_zip':
            // ZIP extraction request - upload and extract ZIP files
            if (!defined('WP_CLI') || !WP_CLI) {
                echo '<h2>📁 ZIP Extraction Started</h2>';
                echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
                echo '<p><strong>Process initiated successfully!</strong> Extracting uploaded ZIP file...</p>';
                echo '</div>';
                ob_flush();
                flush();
            }

            clean_sweep_execute_zip_extraction();
            break;

        case 'scan_malware':
            // Malware scanning request - enhanced Smart Tiered scanning (Tier 1 + optional Tier 2)
            $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : null;

            if ($progress_file) {
                // ==================== AJAX REQUEST: BULLETPROOF JSON RESPONSE ====================

                // 🛡️ CATCH FATAL ERRORS BEFORE THEY OUTPUT HTML
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
                    // 🛡️ EMERGENCY BUFFER CLEANUP - Clear ANY pending output buffers
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    // 🚫 SILENCE ALL OUTPUT for the ENTIRE malware scan operation
                    ob_start();
                    $analysis_results = clean_sweep_execute_malware_scan();
                    // Discard ANY potential HTML output from scan functions
                    ob_end_clean();

                    // Validate that we got a proper response array
                    if (!is_array($analysis_results)) {
                        throw new Exception("Scan function returned invalid response type: " . gettype($analysis_results));
                    }

                    // 🛡️ SECOND EMERGENCY BUFFER CLEANUP
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    // 2. PREVENT JSON BLOAT: Truncate large results for AJAX performance
                    $total_threats = isset($analysis_results['summary']['total_threats'])
                        ? $analysis_results['summary']['total_threats']
                        : 0;

                    $is_ajax_request = true; // Confirmed via progress_file presence
                    if ($is_ajax_request && $total_threats > 50) {
                        // LARGE SCAN DETECTED: Cache full results then truncate for JSON response
                        clean_sweep_log_message("Large scan detected: {$total_threats} threats - caching full results for pagination", 'info');

                        // Generate unique request ID for pagination
                        $request_id = 'scan_' . time() . '_' . mt_rand(1000, 9999);
                        $cache_file = PROGRESS_DIR . '/' . 'threat_cache_' . $request_id . '.json';

                    // Cache complete results (before truncation) - sanitize UTF-8 first
                    if (!is_dir(TEMP_DIR)) {
                        mkdir(TEMP_DIR, 0755, true);
                        clean_sweep_log_message("Created temp directory: " . TEMP_DIR, 'info');
                    }

                    $full_results_for_cache = clean_sweep_sanitize_utf8_array($analysis_results);
                    file_put_contents($cache_file, json_encode($full_results_for_cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
                    if (file_exists($cache_file)) {
                        chmod($cache_file, 0644);
                        clean_sweep_log_message("Cached {$total_threats} threat results to: {$cache_file}", 'info');
                    } else {
                        clean_sweep_log_message("Failed to create cache file: {$cache_file}", 'error');
                    }

                        // TRUNCATE for JSON response
                        $analysis_results['truncated'] = true;
                        $analysis_results['request_id'] = $request_id; // Add for pagination
                        $analysis_results['full_count'] = $total_threats;
                        $analysis_results['log_file_reference'] = LOGS_DIR . LOG_FILE;
                        $analysis_results['truncation_message'] =
                            "Results truncated for browser performance. Found {$total_threats} total threats. " .
                            "Use the buttons below to load additional results.";

                        // Keep summary stats, truncate detailed arrays to prevent JSON bloat
                        // Keep first 10 entries from each section for preview
                        foreach (['database', 'files'] as $section) {
                            if (isset($analysis_results[$section]) && is_array($analysis_results[$section])) {
                                foreach ($analysis_results[$section] as $key => &$value) {
                                    if (is_array($value) && count($value) > 10) {
                                        // Keep first 10 detailed entries, discard rest
                                        $value = array_slice($value, 0, 10, true);
                                    }
                                }
                            }
                        }
                    }

                    // 🛡️ Capture HTML with extra buffer protection
                    ob_start();
                    clean_sweep_display_malware_scan_results($analysis_results);
                    $html_content = ob_get_clean();

                    // 🛡️ SILENCE progress file writing (prevents HTML output during AJAX)
                    ob_start();
                    $completion_data = [
                        'status' => 'complete',
                        'progress' => 100,
                        'message' => $analysis_results['truncation_message'] ??
                                   'Malware scan completed successfully!',
                        'results' => $analysis_results
                    ];
                    clean_sweep_write_progress_file($progress_file, $completion_data);
                    ob_end_clean(); // Discard any HTML output from write_progress_file

                    // 🛡️ FINAL EMERGENCY BUFFER CLEANUP before JSON
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    // 🚀 Send 100% clean JSON response (guaranteed HTML-free and UTF-8 safe)
                    header('Content-Type: application/json; charset=utf-8', true);

                    // Sanitize UTF-8 data before JSON encoding to prevent encoding failures
                    $safe_analysis_results = clean_sweep_sanitize_utf8_array($analysis_results);

                    $json_response = json_encode([
                        'success' => true,
                        'results' => $safe_analysis_results,
                        'html' => $html_content
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

                    // Final validation: Ensure valid JSON
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception('JSON encoding failed: ' . json_last_error_msg());
                    }

                    // 🚀 Send the response and exit immediately
                    if (!headers_sent()) {
                        echo $json_response;
                    } else {
                        throw new Exception('Headers already sent - cannot send JSON');
                    }
                } catch (Exception $e) {
                    // 🛡️ GUARANTEED CLEANUP: Prevent any buffered output
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    // 🚨 Send error response (also guaranteed clean)
                    if (!headers_sent()) {
                        header('Content-Type: application/json; charset=utf-8', true);
                        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    } else {
                        // Fallback: Even if headers sent, still output JSON
                        echo json_encode(['success' => false, 'error' => 'Headers already sent: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    }
                }
                exit; // GUARANTEED exit - no further processing
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
            break;

        case 'load_more_threats':
            // Load additional threat results for truncated scans
            if (!$is_ajax_request) {
                echo json_encode(['success' => false, 'error' => 'Invalid request - no progress file']);
                exit;
            }

            // Add detailed logging for debugging
            $request_id = isset($_POST['request_id']) ? $_POST['request_id'] : '';
            $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
            $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;

            clean_sweep_log_message("Load more threats request: request_id=$request_id, page=$page, per_page=$per_page", 'info');

            // Validate request ID format
            if (empty($request_id) || !preg_match('/^scan_\d+_\d+$/', $request_id)) {
                clean_sweep_log_message("ERROR: Invalid request_id format: $request_id", 'error');
                echo json_encode(['success' => false, 'error' => 'Invalid request ID format']);
                exit;
            }

            // Load cached full results
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

            // Debug: Log cache structure
            clean_sweep_log_message("Cache loaded successfully. Database keys: " . implode(', ', array_keys($full_results['database'] ?? [])), 'info');
            clean_sweep_log_message("Cache structure - Files keys: " . implode(', ', array_keys($full_results['files'] ?? [])), 'info');
            clean_sweep_log_message("Cache summary - Total threats: " . ($full_results['summary']['total_threats'] ?? 0), 'info');

            // Flatten all threats into a single array for pagination with intelligent structure recognition
            $all_threats = [];

            // Process DATABASE section
            $database_section = $full_results['database'] ?? [];
            if (is_array($database_section)) {
                foreach ($database_section as $key => $value) {
                    // Only process if this is a threat array (not a scalar value)
                    if (is_array($value) && !empty($value) && isset($value[0]['pattern'])) {
                        // This is a table of threats (e.g., 'wp_posts' => [threats])
                        foreach ($value as $threat) {
                            if (is_array($threat)) {
                                $threat['_table'] = $key;
                                $threat['_section'] = 'database';
                                $all_threats[] = $threat;
                            }
                        }
                    }
                    // Skip scalar values like 'total_scanned', 'threats_found', etc.
                }
            }

            // Process FILES section
            $files_section = $full_results['files'] ?? [];
            if (is_array($files_section)) {
                foreach ($files_section as $key => $value) {
                    // Only process actual threat arrays (skip scalars like 'total_files_scanned')
                    if (is_array($value) && !empty($value) && isset($value[0]['pattern'])) {
                        // This is a threat category array (e.g., 'wp_content' => [threats])
                        foreach ($value as $threat) {
                            if (is_array($threat)) {
                                $threat['_file_category'] = $key;
                                $threat['_section'] = 'files';
                                $all_threats[] = $threat;
                            }
                        }
                    }
                    // Skip scalar values like 'total_files_scanned', 'file_threats_found', etc.
                }
            }

            // Log final threat count
            clean_sweep_log_message("Total flattened threats: " . count($all_threats), 'info');

            // Calculate pagination
            $total_threats = count($all_threats);
            $start = ($page - 1) * $per_page;
            $paginated_threats = array_slice($all_threats, $start, $per_page);
            $has_more = ($start + $per_page) < $total_threats;

            // Generate HTML for the paginated threats
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

                    // Add context for specific threat types
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

            // Return the results
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

        case 'cleanup':
            // Cleanup request - remove all Clean Sweep files
            if (!defined('WP_CLI') || !WP_CLI) {
                echo '<h2>🗑️ Clean Sweep Cleanup Started</h2>';
                echo '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:20px;border-radius:4px;margin:20px 0;">';
                echo '<p><strong>Cleanup initiated!</strong> Removing all Clean Sweep files and directories...</p>';
                echo '</div>';
                ob_flush();
                flush();
            }

            clean_sweep_execute_cleanup();
            break;

        default:
            // Default action: Show the main toolkit interface
            clean_sweep_display_toolkit_interface();
            break;
    }
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

// Bootstrap WordPress environment and verify all requirements
if (!clean_sweep_bootstrap_wordpress()) {
    exit(1);
}

// Initialize global variables for browser interface
if (!defined('WP_CLI') || !WP_CLI) {
    $all_plugins = get_plugins();
    $total_plugins = count($all_plugins);
    // Note: Progress tracking is handled by individual feature functions
}

// Execute the main application logic
try {
    clean_sweep_run_clean_sweep();
} catch (Exception $e) {
    clean_sweep_log_message("Fatal error: " . $e->getMessage(), 'error');
}

// ============================================================================
// CLEANUP & FINALIZATION
// ============================================================================

// Output HTML footer for browser execution
if (!defined('WP_CLI') || !WP_CLI) {
    // Show completion summary and back button for completed actions (excluding analyze_plugins which just shows results)
    if (isset($_POST['action']) && !empty($_POST['action']) && $_POST['action'] !== 'analyze_plugins') {
        echo '<hr>';
        echo '<div style="background:#f8f9fa;border:1px solid #dee2e6;padding:20px;border-radius:4px;margin:20px 0;text-align:center;">';

        // Action-specific completion messages
        switch ($_POST['action']) {
            case 'reinstall_plugins':
                echo '<h3>🚀 Plugin Re-installation Completed</h3>';
                echo '<p><strong>Process completed successfully!</strong></p>';
                echo '<p>Check the log file for details: <code>' . LOGS_DIR . LOG_FILE . '</code></p>';
                echo '<p>Backup location: <code>' . __DIR__ . '/' . BACKUP_DIR . '</code></p>';
                echo '<p><em>Remember to re-activate your plugins through the WordPress admin panel.</em></p>';
                break;

            case 'reinstall_core':
                // Completion message is already shown in progress details
                break;

            case 'extract_zip':
                echo '<h3>📁 ZIP Extraction Completed</h3>';
                echo '<p><strong>ZIP file has been successfully extracted!</strong></p>';
                echo '<p>The files are now available in the selected location.</p>';
                break;

            case 'cleanup':
                // Cleanup completion is already handled in execute_cleanup()
                break;

            default:
                echo '<h3>✅ Process Completed</h3>';
                echo '<p><strong>The requested operation has been completed.</strong></p>';
                break;
        }

        echo '</div>';
    }

    // Show floating back button for all completed actions except cleanup
    if (isset($_POST['action']) && !empty($_POST['action']) && $_POST['action'] !== 'cleanup') {
        echo '<form method="post" style="display:inline;">';
        echo '<button type="submit" class="back-to-menu-btn visible">';
        echo '🏠 Back to Main Menu';
        echo '</button>';
        echo '</form>';
    }

    echo '</body></html>';
}
