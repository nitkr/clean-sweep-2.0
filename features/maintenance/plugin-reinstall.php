<?php
/**
 * Clean Sweep - Plugin Reinstallation Feature
 *
 * Main orchestration for plugin analysis, backup, and reinstallation
 * Functions are now split into focused modules for better organization
 */

// Load utilities first (no dependencies)
require_once __DIR__ . '/lib/WpmuDevUtils.php';

// Load new OOP architecture for advanced features
require_once __DIR__ . '/lib/PluginReinstallationManager.php';
require_once __DIR__ . '/lib/PluginAnalyzer.php';
require_once __DIR__ . '/lib/BackupManager.php';
require_once __DIR__ . '/lib/PluginReinstaller.php';

// Load legacy plugin management modules (for compatibility)
require_once __DIR__ . '/plugin-utils.php';
require_once __DIR__ . '/plugin-backup.php';
require_once __DIR__ . '/plugin-wordpress.php';

/**
 * Analyze and categorize all installed plugins for reinstallation
 * Returns arrays categorized by whether they should be handled by WPMU DEV or WordPress.org
 *
 * LEGACY WRAPPER: Now delegates to the new advanced PluginAnalyzer class
 * FEATURES: Cached analysis results, improved batch processing, backup choice option
 */
function clean_sweep_analyze_plugins($progress_file = null, $force_refresh = false) {
    try {
        clean_sweep_log_message("Running fresh plugin analysis" . ($force_refresh ? " (force refresh requested)" : ""));

        // Use the advanced PluginAnalyzer class
        $analyzer = new CleanSweep_PluginAnalyzer();
        $result = $analyzer->analyze($progress_file);

        if (!$result['success']) {
            clean_sweep_log_message("Plugin analysis failed: " . ($result['error'] ?? 'Unknown error'), 'error');
            return [
                'wp_org_plugins' => [],
                'wpmu_dev_plugins' => [],
                'skipped' => []
            ];
        }

        // Extract data for backward compatibility
        $wp_org_plugins = $result['wp_org_plugins'] ?? [];
        $wpmu_dev_plugins = $result['wpmu_dev_plugins'] ?? [];
        $non_repo_plugins = $result['non_repo_plugins'] ?? [];
        $suspicious_files = $result['suspicious_files'] ?? [];

        // Convert non_repo_plugins to skipped format for backward compatibility
        $skipped = [];
        foreach ($non_repo_plugins as $plugin_file => $plugin_data) {
            $slug = $plugin_data['slug'] ?? $plugin_file;
            $skipped[$slug] = [
                'name' => $plugin_data['name'] ?? $plugin_file,
                'reason' => $plugin_data['reason'] ?? 'Non-repository plugin'
            ];
        }

        // Log analysis summary
        $wp_org_count = count($wp_org_plugins);
        $wpmu_dev_count = count($wpmu_dev_plugins);
        $non_repo_count = count($non_repo_plugins);
        $suspicious_count = count($suspicious_files);

        clean_sweep_log_message("=== Advanced Plugin Analysis Completed ===");
        clean_sweep_log_message("WordPress.org: $wp_org_count, WPMU DEV: $wpmu_dev_count, Non-repository: $non_repo_count, Suspicious files: $suspicious_count");

        // Return complete result set (no caching)
        return array_merge(compact('wp_org_plugins', 'wpmu_dev_plugins', 'skipped'), [
            'non_repo_plugins' => $non_repo_plugins,
            'suspicious_files' => $suspicious_files,
            'copy_lists' => $result['copy_lists'] ?? [],
            'totals' => $result['totals'] ?? [],
            'wpmu_dev_available' => $result['wpmu_dev_available']
        ]);

    } catch (Exception $e) {
        clean_sweep_log_message("Plugin analysis exception: " . $e->getMessage(), 'error');

        // Fallback to basic arrays on error
        return [
            'wp_org_plugins' => [],
            'wpmu_dev_plugins' => [],
            'skipped' => []
        ];
    }
}

/**
 * Verify that plugins are actually installed after re-installation
 */
function clean_sweep_verify_installations($expected_plugins) {
    clean_sweep_log_message("Performing final verification of installed plugins...");

    $verification_results = [
        'verified' => [],
        'missing' => [],
        'corrupted' => []
    ];

    // Clear plugin cache to ensure we see newly installed plugins
    wp_cache_flush();
    wp_clean_plugins_cache();
    $current_plugins = get_plugins();

    clean_sweep_log_message("Verification: Found " . count($current_plugins) . " plugins in WordPress");

    foreach ($expected_plugins as $plugin_key => $plugin_data) {
        // For WPMU DEV plugins, $plugin_key is the filename like "google-analytics-async/google-analytics-async.php"
        // For WordPress.org plugins, $plugin_key is typically the slug like "wp-file-manager"
        $plugin_slug = $plugin_data['slug'] ?? $plugin_key;
        $plugin_name = $plugin_data['name'] ?? $plugin_key;
        $plugin_found = false;
        $plugin_corrupted = false;

        // Check if plugin exists in current plugins list
        foreach ($current_plugins as $plugin_file => $current_plugin_data) {
            // Use same slug detection logic as in analyze_plugins
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
                $current_slug = pathinfo($plugin_file, PATHINFO_FILENAME);
            } else {
                $current_slug = basename($plugin_dir);
            }

            if ($current_slug === $plugin_slug) {  // FIXED: Use proper slug from data
                $plugin_found = true;

                // Verify plugin files exist and are readable
                if ($plugin_dir === '.' || $plugin_dir === '') {
                    // Plugin is in root directory
                    $main_file = ORIGINAL_WP_PLUGIN_DIR . '/' . $plugin_file;
                    if (!file_exists($main_file) || !is_readable($main_file)) {
                        $plugin_corrupted = true;
                    }
                } else {
                    // Plugin is in subdirectory
                    $plugin_dir_path = ORIGINAL_WP_PLUGIN_DIR . '/' . $plugin_slug;  // FIXED: Use proper slug
                    if (is_dir($plugin_dir_path)) {
                        // Check for main plugin file
                        $main_file = $plugin_dir_path . '/' . basename($plugin_file);
                        if (!file_exists($main_file) || !is_readable($main_file)) {
                            $plugin_corrupted = true;
                        }
                    } else {
                        $plugin_corrupted = true;
                    }
                }
                break;
            }
        }

        if ($plugin_found && !$plugin_corrupted) {
            $verification_results['verified'][] = [
                'name' => $plugin_name,
                'slug' => $slug,
                'status' => 'Installed and verified'
            ];
        } elseif ($plugin_corrupted) {
            $verification_results['corrupted'][] = [
                'name' => $plugin_name,
                'slug' => $slug,
                'status' => 'Corrupted or incomplete installation'
            ];
        } else {
            $verification_results['missing'][] = [
                'name' => $plugin_name,
                'slug' => $slug,
                'status' => 'Not found in plugins directory'
            ];
        }
    }

    clean_sweep_log_message("Verification completed. Verified: " . count($verification_results['verified']) .
                ", Missing: " . count($verification_results['missing']) .
                ", Corrupted: " . count($verification_results['corrupted']));

    return $verification_results;
}

/**
 * Verify WPMU DEV plugin installations
 * Uses plugin filenames instead of slugs
 */
function clean_sweep_verify_wpmudev_installations($wpmudev_plugins) {
    clean_sweep_log_message("Performing WPMU DEV plugin verification...");

    $verification_results = [
        'verified' => [],
        'missing' => [],
        'corrupted' => []
    ];

    // Clear plugin cache to ensure we see newly installed plugins
    wp_cache_flush();
    wp_clean_plugins_cache();
    $current_plugins = get_plugins();

    clean_sweep_log_message("WPMU DEV verification: Found " . count($current_plugins) . " plugins in WordPress");

    foreach ($wpmudev_plugins as $plugin_file => $plugin_data) {
        $plugin_name = $plugin_data['name'] ?? $plugin_file;
        $plugin_found = false;
        $plugin_corrupted = false;

        // Check if plugin exists in current plugins list using exact filename
        if (isset($current_plugins[$plugin_file])) {
            $plugin_found = true;

            // Verify the plugin file actually exists and is readable
            $plugin_path = ORIGINAL_WP_PLUGIN_DIR . '/' . $plugin_file;
            if (!file_exists($plugin_path) || !is_readable($plugin_path)) {
                $plugin_corrupted = true;
            }
        }

        if ($plugin_found && !$plugin_corrupted) {
            $verification_results['verified'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Installed and verified (WPMU DEV)'
            ];
            clean_sweep_log_message("WPMU DEV plugin verified: {$plugin_name} ({$plugin_file})");
        } elseif ($plugin_corrupted) {
            $verification_results['corrupted'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Corrupted or incomplete installation'
            ];
            clean_sweep_log_message("WPMU DEV plugin corrupted: {$plugin_name} ({$plugin_file})", 'warning');
        } else {
            $verification_results['missing'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Not found in plugins directory'
            ];
            clean_sweep_log_message("WPMU DEV plugin missing: {$plugin_name} ({$plugin_file})", 'warning');
        }
    }

    clean_sweep_log_message("WPMU DEV verification completed. Verified: " . count($verification_results['verified']) .
                ", Missing: " . count($verification_results['missing']) .
                ", Corrupted: " . count($verification_results['corrupted']));

    return $verification_results;
}

/**
 * Execute plugin reinstallation with advanced features
 * Supports backup choice, cached analysis, and improved batch processing
 */
function clean_sweep_execute_reinstallation($repo_plugins, $progress_file = null, $batch_start = 0, $batch_size = null, $create_backup = true) {
    clean_sweep_log_message("=== WordPress Plugin Re-installation Started ===");

    // Handle selective plugin reinstallation: separate WP.org and WPMU DEV plugins
    $selective_mode = false;
    $wp_org_plugins = [];
    $wpmu_dev_plugins = [];

    foreach ($repo_plugins as $slug => $plugin_data) {
        // Check if this is a WPMU DEV plugin
        $plugin_dir = ORIGINAL_WP_PLUGIN_DIR . '/' . $slug;
        $is_wpmu_dev = false;

        if (is_dir($plugin_dir)) {
            // Find the main plugin file
            $files = glob($plugin_dir . '/*.php');
            foreach ($files as $file) {
                $file_data = get_file_data($file, array('id' => 'WDP ID'));
                if (!empty($file_data['id']) && is_numeric($file_data['id'])) {
                    $is_wpmu_dev = true;
                    break;
                }
            }
        }

        if ($is_wpmu_dev) {
            $wpmu_dev_plugins[$slug] = $plugin_data;
            $selective_mode = true;
            clean_sweep_log_message("Detected WPMU DEV plugin in selective mode: {$plugin_data['name']} (slug: {$slug})", 'info');
        } else {
            $wp_org_plugins[$slug] = $plugin_data;
            $selective_mode = true;
            clean_sweep_log_message("Detected WordPress.org plugin in selective mode: {$plugin_data['name']} (slug: {$slug})", 'info');
        }
    }

    // In selective mode, use the separated arrays; otherwise fall back to legacy behavior
    if ($selective_mode) {
        $repo_plugins = $wp_org_plugins;
        $repo_count = count($repo_plugins);
        clean_sweep_log_message("Selective mode: {$repo_count} WordPress.org, " . count($wpmu_dev_plugins) . " WPMU DEV plugins selected");
    } else {
        // Legacy behavior: filter out WPMU DEV plugins for backward compatibility
        $filtered_repo_plugins = [];
        foreach ($repo_plugins as $slug => $plugin_data) {
            $plugin_dir = ORIGINAL_WP_PLUGIN_DIR . '/' . $slug;
            $skip_plugin = false;

            if (is_dir($plugin_dir)) {
                $files = glob($plugin_dir . '/*.php');
                foreach ($files as $file) {
                    $file_data = get_file_data($file, array('id' => 'WDP ID'));
                    if (!empty($file_data['id']) && is_numeric($file_data['id'])) {
                        clean_sweep_log_message("Filtering out WPMU DEV plugin from WordPress.org batch: {$plugin_data['name']} (slug: {$slug})", 'info');
                        $skip_plugin = true;
                        break;
                    }
                }
            }

            if (!$skip_plugin) {
                $filtered_repo_plugins[$slug] = $plugin_data;
            }
        }

        $repo_plugins = $filtered_repo_plugins;
        $repo_count = count($repo_plugins);
    }

    clean_sweep_log_message("After filtering WPMU DEV plugins: {$repo_count} WordPress.org plugins to reinstall");

    // Handle case where all plugins were WPMU DEV plugins
    if (empty($repo_plugins) && $batch_start === 0) {
        clean_sweep_log_message("No WordPress.org plugins to re-install after filtering - checking for WPMU DEV only", 'info');

        // With JavaScript-only batching, WPMU DEV plugins should be handled via selective mode
        // No fallback to transient storage needed
    }

    // Suppress chmod warnings during plugin operations
    $original_error_handler = set_error_handler(function($errno, $errstr, $errfile, $errline) {
        // Suppress chmod warnings specifically
        if (strpos($errstr, 'chmod()') !== false && strpos($errstr, 'No such file or directory') !== false) {
            return true; // Suppress the warning
        }
        // For all other errors, use default handling
        return false;
    }, E_WARNING);

    // Initialize result arrays
    $results = [
        'successful' => [],
        'failed' => []
    ];

    $repo_count = count($repo_plugins);

    if (empty($repo_plugins)) {
        clean_sweep_log_message("No WordPress.org plugins to re-install", 'warning');
        return $results;
    }

    // Create initial progress file for JavaScript polling (FIX: prevent 404 errors)
    if ($progress_file && $batch_start === 0) {
        clean_sweep_log_message("Creating initial progress file: $progress_file");
        $initial_progress_data = [
            'status' => 'initializing',
            'progress' => 0,
            'message' => 'Initializing plugin re-installation...',
            'current' => 0,
            'total' => $repo_count,
            'batch_start' => $batch_start,
            'batch_size' => $batch_size,
            'has_more_batches' => ($batch_size && $batch_start + $batch_size < $repo_count) ? true : false
        ];
        @clean_sweep_write_progress_file($progress_file, $initial_progress_data); // Suppress file write errors
        clean_sweep_log_message("Initial progress file created for JavaScript polling");
    }

    // Create backup only for the first batch to avoid multiple backups (if requested)
    if ($batch_start === 0 && $create_backup) {
        clean_sweep_log_message("User requested backup creation - proceeding...");
        if (!clean_sweep_create_backup($progress_file)) {
            clean_sweep_log_message("Backup failed. Aborting re-installation.", 'error');
            return $results;
        }
    } elseif ($batch_start === 0 && !$create_backup) {
        clean_sweep_log_message("User opted out of backup creation - proceeding without backup");
    }

    // Get active plugins before re-installation
    $active_plugins_before = clean_sweep_get_active_plugins_list();
    clean_sweep_log_message("Active plugins before re-installation: " . implode(', ', array_map(function($p) { return basename(dirname($p)); }, $active_plugins_before)));

    // Batch processing for managed hosting (prevent timeouts)
    if ($batch_size === null) {
        $batch_size = 5; // Process 5 plugins per batch by default
    }

    // Slice the plugins array for this batch
    $plugin_keys = array_keys($repo_plugins);
    $batch_plugins = array_slice($plugin_keys, $batch_start, $batch_size, true);
    $batch_count = count($batch_plugins);

    // Calculate overall progress
    $overall_processed = $batch_start;
    $overall_total = $repo_count;

    clean_sweep_log_message("Processing batch: " . ($batch_start + 1) . "-" . ($batch_start + $batch_count) . " of $overall_total plugins");

    // Re-install plugins in this batch
    $success_count = 0;
    $fail_count = 0;
    $current_in_batch = 0;

    foreach ($batch_plugins as $plugin_key) {
        $plugin_data = $repo_plugins[$plugin_key];
        $slug = $plugin_data['slug'] ?? $plugin_key;
        $plugin_name = $plugin_data['name'] ?? $plugin_key;
        $current_in_batch++;
        $overall_processed++;

        // Update progress for re-installation (show overall progress)
        if ($progress_file) {
            // Write progress to file for AJAX polling
            $progress_data = [
                'status' => 'reinstalling',
                'progress' => round(($overall_processed / $overall_total) * 100),
                'message' => "Re-installing plugin $overall_processed of $overall_total: $plugin_name",
                'current' => $overall_processed,
                'total' => $overall_total,
                'batch_start' => $batch_start,
                'batch_size' => $batch_size,
                'has_more_batches' => ($batch_start + $batch_size) < $overall_total
            ];
            @clean_sweep_write_progress_file($progress_file, $progress_data); // Suppress file write errors
        } elseif (!defined('WP_CLI') || !WP_CLI) {
            // Fallback to inline progress for non-AJAX requests
            echo '<script>updateProgress(' . $overall_processed . ', ' . $overall_total . ', "Re-installing: ' . addslashes($plugin_name) . '");</script>';
            ob_flush();
            flush();
        }

        if (clean_sweep_reinstall_plugin($slug)) {
            $success_count++;
            $results['successful'][] = [
                'name' => $plugin_name,
                'slug' => $slug,
                'status' => 'Re-installed successfully',
                'is_wpmudev' => false // Mark as WordPress.org plugin
            ];
        } else {
            $fail_count++;
            $results['failed'][] = [
                'name' => $plugin_name,
                'slug' => $slug,
                'status' => 'Re-installation failed',
                'is_wpmudev' => false
            ];
        }

        // Small delay to be respectful to the API
        sleep(1);
    }

    // Check if there are more batches to process
    $has_more_batches = ($batch_start + $batch_size) < $overall_total;
    $results['batch_info'] = [
        'processed' => $overall_processed,
        'total' => $overall_total,
        'has_more_batches' => $has_more_batches,
        'next_batch_start' => $has_more_batches ? ($batch_start + $batch_size) : null
    ];

    // Store batch results for accumulation across batches
    if ($progress_file) {
        $batch_results_file = dirname($progress_file) . '/' . basename($progress_file, '.progress') . '_results.tmp';
        $all_results = [
            'successful' => [],
            'failed' => []
        ];

        // Load existing results if this is not the first batch
        if ($batch_start > 0 && file_exists($batch_results_file)) {
            $existing = @json_decode(@file_get_contents($batch_results_file), true);
            if ($existing && is_array($existing)) {
                $all_results = $existing;
            }
        }

        // Add current batch results
        $all_results['successful'] = array_merge($all_results['successful'], $results['successful']);
        $all_results['failed'] = array_merge($all_results['failed'], $results['failed']);

        // Save accumulated results
        @file_put_contents($batch_results_file, json_encode($all_results, JSON_UNESCAPED_UNICODE));

        // Return accumulated results for final batch
        if (!$has_more_batches) {
            $results['successful'] = $all_results['successful'];
            $results['failed'] = $all_results['failed'];
            // Clean up temporary results file
            @unlink($batch_results_file);
        }
    }

    // Mark progress as complete
    if (!defined('WP_CLI') || !WP_CLI) {
        echo '<script>updateProgress(' . $repo_count . ', ' . $repo_count . ', "Completed");</script>';
        ob_flush();
        flush();
    }

    clean_sweep_log_message("Re-installation completed for batch. Success: $success_count, Failed: $fail_count");

    // Check if this is the final batch in the processing
    $is_final_batch = ($batch_start + $batch_size >= $repo_count);

    // Only perform final verification and WPMU DEV processing for final batch
    if ($is_final_batch) {
        clean_sweep_log_message("Final batch detected - performing verification and WPMU DEV processing...");

        // Handle WPMU DEV plugins processing
        if ($selective_mode && !empty($wpmu_dev_plugins)) {
            // In selective mode, process the selected WPMU DEV plugins directly
            clean_sweep_log_message("Processing selected WPMU DEV plugins: " . count($wpmu_dev_plugins) . " plugins");

            $manager = new CleanSweep_PluginReinstallationManager();
            $reinstall_result = $manager->handle_request('start_reinstallation', [
                'progress_file' => $progress_file,
                'create_backup' => false, // Already handled above
                'proceed_without_backup' => true, // Already handled above
                'wp_org_plugins' => [], // No WordPress.org plugins in this phase
                'wpmu_dev_plugins' => $wpmu_dev_plugins, // Pass selected WPMU DEV plugins
                'suspicious_files_to_delete' => [], // No suspicious files in this phase
                'batch_start' => 0, // Not batching for WPMU DEV
                'batch_size' => null // Process all at once
            ]);

            if ($reinstall_result['success']) {
                // Extract WPMU DEV results from OOP structure
                $wpmudev_results = $reinstall_result['wpmu_dev'] ?? ['successful' => [], 'failed' => []];
                clean_sweep_log_message("Selected WPMU DEV plugin processing completed: " . count($wpmudev_results['successful']) . " success, " . count($wpmudev_results['failed']) . " failed");

                // Add successful WPMU DEV plugins to main successful array for proper final display
                foreach ($wpmudev_results['successful'] as $plugin_data) {
                    $results['successful'][] = [
                        'name' => $plugin_data['name'] ?? $plugin_data['slug'] ?? 'Unknown',
                        'slug' => $plugin_data['slug'] ?? $plugin_data['name'] ?? 'unknown',
                        'status' => 'Re-installed successfully (WPMU DEV)',
                        'is_wpmudev' => true
                    ];
                }

                // Add failed WPMU DEV plugins to main failed results
                foreach ($wpmudev_results['failed'] as $plugin_data) {
                    $results['failed'][] = [
                        'name' => $plugin_data['name'] ?? $plugin_data['slug'] ?? 'Unknown',
                        'slug' => $plugin_data['slug'] ?? $plugin_data['name'] ?? 'unknown',
                        'status' => 'WPMU DEV re-installation failed: ' . ($plugin_data['status'] ?? 'Unknown error'),
                        'is_wpmudev' => true
                    ];
                }
            } else {
                $wpmudev_results = ['error' => $reinstall_result['error'] ?? 'WPMU DEV reinstallation failed'];
                clean_sweep_log_message("Selected WPMU DEV processing failed: {$wpmudev_results['error']}", 'error');
            }
        } elseif (!$selective_mode) {
            // With JavaScript-only batching, WPMU DEV plugins should be handled via selective mode
            // No fallback to transient storage needed
            clean_sweep_log_message("No WPMU DEV plugins to process in legacy mode", 'debug');
            $wpmudev_results = ['successful' => [], 'failed' => []];
        }

        // Merge results and perform verification
        if (!isset($wpmudev_results['error'])) {
            $results['wpmudev'] = $wpmudev_results;

            // Determine which WPMU DEV plugins to verify based on mode
            $wpmu_dev_plugins_to_verify = $selective_mode ? $wpmu_dev_plugins : $wpmudev_plugins_to_reinstall;

            // Filter out excluded plugins from verification (same as install)
            // Exclude WPMU DEV Dashboard (ID 119)
            $filtered_wpmudev_plugins_for_verification = [];
            foreach ($wpmu_dev_plugins_to_verify as $plugin_file => $plugin_data) {
                if (($plugin_data['wdp_id'] ?? $plugin_data['pid'] ?? null) !== 119) {
                    $filtered_wpmudev_plugins_for_verification[$plugin_file] = $plugin_data;
                }
            }

            // Perform verification for WPMU DEV plugins (excluding Dashboard)
            $wpmudev_verification = clean_sweep_verify_wpmudev_installations($filtered_wpmudev_plugins_for_verification);

            // Initialize verification_results if not already set (from WordPress.org verification)
            if (!isset($verification_results) || !is_array($verification_results)) {
                $verification_results = [
                    'verified' => [],
                    'missing' => [],
                    'corrupted' => []
                ];
            }

            // Merge WPMU DEV verification results into main verification results for display
            $verification_results['verified'] = array_merge($verification_results['verified'], $wpmudev_verification['verified']);
            $verification_results['missing'] = array_merge($verification_results['missing'], $wpmudev_verification['missing']);
            $verification_results['corrupted'] = array_merge($verification_results['corrupted'], $wpmudev_verification['corrupted']);
        } else {
            clean_sweep_log_message("WPMU DEV processing skipped: {$wpmudev_results['error']}", 'warning');
            $results['wpmudev'] = $wpmudev_results;
        }

        // Perform final verification of all WordPress.org plugins and merge into existing results
        $wp_org_verification = clean_sweep_verify_installations($repo_plugins);
        $verification_results['verified'] = array_merge($verification_results['verified'], $wp_org_verification['verified']);
        $verification_results['missing'] = array_merge($verification_results['missing'], $wp_org_verification['missing']);
        $verification_results['corrupted'] = array_merge($verification_results['corrupted'], $wp_org_verification['corrupted']);

        // Only display results for non-AJAX requests on final batch
        if (!$progress_file && (!defined('WP_CLI') || !WP_CLI)) {
            clean_sweep_display_final_results($results, $verification_results);
        }

        // Updated summary now includes WPMU DEV results
        $wp_success = count($results['successful']);
        $wp_failed = count($results['failed']);
        $wpmudev_success = count($wpmudev_results['successful'] ?? []);
        $wpmudev_failed = count($wpmudev_results['failed'] ?? []);

        // ============================================================================
        // ESTABLISH INTEGRITY BASELINE FOR REINFECTION DETECTION (comprehensive mode only)
        // ============================================================================

        // Check if comprehensive baseline mode is enabled
        $comprehensive_mode = false;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $comprehensive_mode = isset($_SESSION['clean_sweep_comprehensive_baseline']) && $_SESSION['clean_sweep_comprehensive_baseline'];

        if ($comprehensive_mode) {
            clean_sweep_log_message("🔐 Establishing comprehensive baseline after plugin reinstallation");

            // Establish baseline for freshly reinstalled plugins (comprehensive mode only)
            if (function_exists('clean_sweep_establish_core_baseline')) {
                $baseline_result = clean_sweep_establish_core_baseline();
                if ($baseline_result) {
                    clean_sweep_log_message("✅ Comprehensive integrity baseline established successfully after plugin reinstallation");
                    clean_sweep_log_message("🛡️ Future malware scans will detect reinfection by comparing against this baseline");
                } else {
                    clean_sweep_log_message("⚠️ Failed to establish comprehensive integrity baseline after plugin reinstallation", 'warning');
                }
            }
        } else {
            clean_sweep_log_message("ℹ️ Skipping baseline establishment after plugin reinstallation (comprehensive mode disabled)");
        }

        clean_sweep_log_message("Final Summary: WordPress.org ({$wp_success}/{$wp_failed} success/failed) + WPMU DEV ({$wpmudev_success}/{$wpmudev_failed} success/failed)");
        clean_sweep_log_message("=== Complete Plugin Ecosystem Re-installation Completed ===");
    } else {
        // For non-final batches, just log the batch completion
        clean_sweep_log_message("Intermediate batch completed. Awaiting final batch for verification and WPMU DEV processing...");
    }

    // Note: Plugins will need to be re-activated manually or via additional script
    // Restore error handling
    if ($original_error_handler) {
        set_error_handler($original_error_handler);
    } else {
        restore_error_handler();
    }

    // Return both results and verification_results for AJAX responses
    return [
        'results' => $results,
        'verification_results' => isset($verification_results) ? $verification_results : ['verified' => [], 'missing' => [], 'corrupted' => []]
    ];
}
