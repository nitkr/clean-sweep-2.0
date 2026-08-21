<?php
/**
 * Clean Sweep - Plugin Reinstallation Feature
 *
 * Main orchestration for plugin analysis, backup, and reinstallation
 * Functions are now split into focused modules for better organization
 */

// Load utilities first (no dependencies)
require_once __DIR__ . '/lib/WpmuDevUtils.php';

// Load batch processing classes (from includes/system/batch-processing/)
require_once __DIR__ . '/../../includes/system/batch-processing/CleanSweep_BatchProcessingException.php';
require_once __DIR__ . '/../../includes/system/batch-processing/CleanSweep_BatchProcessor.php';
require_once __DIR__ . '/../../includes/system/batch-processing/CleanSweep_ProgressManager.php';

// Load new OOP architecture for advanced features
require_once __DIR__ . '/lib/PluginReinstallationManager.php';
require_once __DIR__ . '/lib/SuspiciousItemAnalyzer.php';
require_once __DIR__ . '/lib/PackageIdentity.php';
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
        $likely_fake_plugins = $result['likely_fake_plugins'] ?? [];
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
        $likely_fake_count = count($likely_fake_plugins);
        $suspicious_count = count($suspicious_files);

        clean_sweep_log_message("=== Advanced Plugin Analysis Completed ===");
        clean_sweep_log_message("WordPress.org: $wp_org_count, WPMU DEV: $wpmu_dev_count, Non-repository: $non_repo_count, Likely fake: $likely_fake_count, Suspicious files: $suspicious_count");

        // Return complete result set (no caching)
        return array_merge(compact('wp_org_plugins', 'wpmu_dev_plugins', 'skipped'), [
            'non_repo_plugins' => $non_repo_plugins,
            'likely_fake_plugins' => $likely_fake_plugins,
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

        // Use $plugin_slug instead of undefined $slug
        if ($plugin_found && !$plugin_corrupted) {
            $verification_results['verified'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_slug,
                'status' => 'Installed and verified'
            ];
        } elseif ($plugin_corrupted) {
            $verification_results['corrupted'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_slug,
                'status' => 'Corrupted or incomplete installation'
            ];
        } else {
            $verification_results['missing'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_slug,
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
        $found_plugin_file = null;

        // WPMU DEV plugins are passed with directory name (e.g., "ultimate-branding")
        // But WordPress stores them with full path (e.g., "ultimate-branding/ultimate-branding.php")
        // So we need to search for the plugin by directory name
        $plugin_dir = ORIGINAL_WP_PLUGIN_DIR . '/' . $plugin_file;
        
        // First try exact match
        if (isset($current_plugins[$plugin_file])) {
            $plugin_found = true;
            $found_plugin_file = $plugin_file;
        } else {
            // Search for plugin by directory name
            foreach ($current_plugins as $plugin_path => $plugin_info) {
                // Check if the path starts with the plugin directory name
                $path_parts = explode('/', $plugin_path);
                if (!empty($path_parts) && $path_parts[0] === $plugin_file) {
                    $plugin_found = true;
                    $found_plugin_file = $plugin_path;
                    break;
                }
            }
        }

        if ($plugin_found && $found_plugin_file) {
            // Verify the plugin file actually exists and is readable
            $plugin_path = ORIGINAL_WP_PLUGIN_DIR . '/' . $found_plugin_file;
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
 * 
 * @param array $repo_plugins WordPress.org plugins to reinstall
 * @param string|null $progress_file Progress file for AJAX updates
 * @param int $batch_start Starting index for batch processing
 * @param int|null $batch_size Number of items per batch
 * @param bool $create_backup Whether to create backup first
 * @param array $wpmu_dev_plugins WPMU DEV plugins to reinstall (optional, passed from API)
 */
function clean_sweep_execute_reinstallation($repo_plugins, $progress_file = null, $batch_start = 0, $batch_size = null, $create_backup = true, $wpmu_dev_plugins = array(), $suspicious_files = array()) {
    clean_sweep_log_message("=== WordPress Plugin Re-installation Started ===");

    // Detect if this is an AJAX request - skip inline script output for JSON API responses
    $is_ajax_request = !empty($progress_file) || 
        (isset($_POST['action']) && strpos($_POST['action'], 'reinstall') !== false) ||
        (defined('DOING_AJAX') && DOING_AJAX);

    // Initialize $wpmudev_results to prevent undefined variable warning
    $wpmudev_results = null;

    // DEBUG: Log what was passed to the function
    clean_sweep_log_message("DEBUG: clean_sweep_execute_reinstallation called with:", 'debug');
    clean_sweep_log_message("  repo_plugins count: " . count($repo_plugins), 'debug');
    clean_sweep_log_message("  wpmu_dev_plugins count: " . count($wpmu_dev_plugins), 'debug');
    clean_sweep_log_message("  wpmu_dev_plugins content: " . json_encode($wpmu_dev_plugins), 'debug');

    // Handle selective plugin reinstallation: separate WP.org and WPMU DEV plugins
    // Use explicitly passed wpmu_dev_plugins from API if available, otherwise detect from filesystem
    $selective_mode = false;
    $wp_org_plugins = [];
    $wpmu_dev_plugins_from_api = $wpmu_dev_plugins; // Already passed from API
    
    // If wpmu_dev_plugins was passed from API, use it directly
    if (!empty($wpmu_dev_plugins_from_api)) {
        clean_sweep_log_message("Using WPMU DEV plugins directly passed from API: " . count($wpmu_dev_plugins_from_api) . " plugins", 'info');
        $selective_mode = true;
    }
    
    // Process repo_plugins (which may contain WP.org plugins or a mix)
    foreach ($repo_plugins as $slug => $plugin_data) {
        // Check if this is a WPMU DEV plugin
        $plugin_dir = ORIGINAL_WP_PLUGIN_DIR . '/' . $slug;
        $is_wpmu_dev = false;

        // Skip detection if already identified as WPMU DEV from API
        if (isset($wpmu_dev_plugins_from_api[$slug])) {
            $is_wpmu_dev = true;
        } elseif (is_dir($plugin_dir)) {
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
            // Skip if already in API-passed array
            if (!isset($wpmu_dev_plugins_from_api[$slug])) {
                $wpmu_dev_plugins_from_api[$slug] = $plugin_data;
            }
            $selective_mode = true;
            clean_sweep_log_message("Detected WPMU DEV plugin in selective mode: " . ($plugin_data['name'] ?? $slug) . " (slug: $slug)", 'info');
        } else {
            $wp_org_plugins[$slug] = $plugin_data;
            $selective_mode = true;
            clean_sweep_log_message("Detected WordPress.org plugin in selective mode: " . ($plugin_data['name'] ?? $slug) . " (slug: $slug)", 'info');
        }
    }

    // In selective mode, use the separated arrays; otherwise fall back to legacy behavior
    if ($selective_mode) {
        $repo_plugins = $wp_org_plugins;
        $repo_count = count($repo_plugins);
        // Use the API-passed WPMU DEV plugins if available
        $wpmu_dev_plugins = $wpmu_dev_plugins_from_api;
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
    $wpmu_dev_count = count($wpmu_dev_plugins);
    $total_plugins_count = $repo_count + $wpmu_dev_count; // FIX: Combined total for accurate progress tracking

    if (empty($repo_plugins) && empty($wpmu_dev_plugins)) {
        clean_sweep_log_message("No WordPress.org or WPMU DEV plugins to re-install", 'warning');
        return $results;
    }

    // Create initial progress file for JavaScript polling (FIX: prevent 404 errors)
    if ($progress_file && $batch_start === 0) {
        clean_sweep_log_message("Creating initial progress file: $progress_file");
        $initial_progress_data = [
            'status' => 'initializing',
            'phase' => 'reinstall',
            'progress' => 0,
            'message' => 'Initializing plugin re-installation...',
            'current' => 0,
            'total' => $total_plugins_count, // FIX: Use combined total
            'plugin' => '',
            'batch_start' => $batch_start,
            'batch_size' => $batch_size,
            'has_more_batches' => ($batch_size && $batch_start + $batch_size < $total_plugins_count) ? true : false
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
        
        // Reset progress file after backup completes - backup overwrote it with file counts
        // Now we need to restore plugin count for reinstall progress
        if ($progress_file) {
            $reset_progress = [
                'status' => 'reinstalling',
                'progress' => 0,
                'message' => 'Starting plugin reinstallation...',
                'current' => 0,
                'total' => $total_plugins_count,
                'plugin' => '',
                'phase' => 'reinstall'
            ];
            @clean_sweep_write_progress_file($progress_file, $reset_progress);
            clean_sweep_log_message("Progress file reset for reinstallation after backup: {$total_plugins_count} plugins");
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

    // FIX: In selective mode with unified processing, skip legacy WP.org batch processing
    // and let the unified PluginReinstaller handle everything in a single call.
    // This ensures progress bar works for both WP.org and WPMU DEV plugins.
    $use_unified_processing = $selective_mode && !empty($wpmu_dev_plugins);
    
    if ($use_unified_processing) {
        clean_sweep_log_message("Using unified PluginReinstaller for both WP.org and WPMU DEV plugins", 'info');
        
        // Call unified manager directly - this handles both WP.org and WPMU DEV
        $manager = new CleanSweep_PluginReinstallationManager();
        $reinstall_result = $manager->handle_request('start_reinstallation', [
            'progress_file' => $progress_file,
            'create_backup' => false, // Already handled above
            'proceed_without_backup' => true,
            'wp_org_plugins' => $repo_plugins,
            'wpmu_dev_plugins' => $wpmu_dev_plugins,
            'suspicious_files_to_delete' => $suspicious_files,
            'batch_start' => $batch_start,
            'batch_size' => $batch_size
        ]);
        
        // Always pass through per-plugin lists. Mixed WPMU failures used to
        // set success=false and drop the WordPress.org successes from the UI.
        $has_plugin_lists = isset($reinstall_result['wordpress_org']) || isset($reinstall_result['wpmu_dev']);
        if ($has_plugin_lists) {
            $results = [
                'successful' => array_merge(
                    array_map(function($p) { return ['name' => $p['name'], 'slug' => $p['slug'], 'status' => $p['status']]; }, $reinstall_result['wordpress_org']['successful'] ?? []),
                    array_map(function($p) { return ['name' => $p['name'], 'slug' => $p['slug'], 'status' => $p['status'], 'is_wpmudev' => true]; }, $reinstall_result['wpmu_dev']['successful'] ?? [])
                ),
                'failed' => array_merge(
                    array_map(function($p) { return ['name' => $p['name'], 'slug' => $p['slug'], 'status' => $p['status']]; }, $reinstall_result['wordpress_org']['failed'] ?? []),
                    array_map(function($p) { return ['name' => $p['name'], 'slug' => $p['slug'], 'status' => $p['status'], 'is_wpmudev' => true]; }, $reinstall_result['wpmu_dev']['failed'] ?? [])
                ),
                'wordpress_org' => $reinstall_result['wordpress_org'] ?? ['successful' => [], 'failed' => []],
                'wpmu_dev' => $reinstall_result['wpmu_dev'] ?? ['successful' => [], 'failed' => []],
                'batch_info' => $reinstall_result['batch_info'] ?? null,
                'partial' => !empty($reinstall_result['partial'])
            ];
            $wpmudev_results = $reinstall_result['wpmu_dev'] ?? ['successful' => [], 'failed' => []];
            clean_sweep_log_message("Unified processing completed - WP.org: " . count($reinstall_result['wordpress_org']['successful'] ?? []) . ", WPMU DEV: " . count($wpmudev_results['successful'] ?? []));
        } else {
            $wpmudev_results = ['error' => $reinstall_result['error'] ?? 'Unified processing failed'];
            clean_sweep_log_message("Unified processing failed: " . ($reinstall_result['error'] ?? 'Unknown error'), 'error');
        }
        
        // FIX: Add verification before returning - this was being skipped due to goto
        // Initialize verification results
        $verification_results = [
            'verified' => [],
            'missing' => [],
            'corrupted' => []
        ];
        
        // Verify WPMU DEV plugins (excluding Dashboard ID 119)
        $filtered_wpmudev_for_verification = [];
        foreach ($wpmu_dev_plugins as $plugin_file => $plugin_data) {
            if (($plugin_data['wdp_id'] ?? $plugin_data['pid'] ?? null) !== 119) {
                $filtered_wpmudev_for_verification[$plugin_file] = $plugin_data;
            }
        }
        
        if (!empty($filtered_wpmudev_for_verification)) {
            $wpmudev_verification = clean_sweep_verify_wpmudev_installations($filtered_wpmudev_for_verification);
            $verification_results['verified'] = array_merge($verification_results['verified'], $wpmudev_verification['verified']);
            $verification_results['missing'] = array_merge($verification_results['missing'], $wpmudev_verification['missing']);
            $verification_results['corrupted'] = array_merge($verification_results['corrupted'], $wpmudev_verification['corrupted']);
        }
        
        // Verify WordPress.org plugins
        if (!empty($repo_plugins)) {
            $wp_org_verification = clean_sweep_verify_installations($repo_plugins);
            $verification_results['verified'] = array_merge($verification_results['verified'], $wp_org_verification['verified']);
            $verification_results['missing'] = array_merge($verification_results['missing'], $wp_org_verification['missing']);
            $verification_results['corrupted'] = array_merge($verification_results['corrupted'], $wp_org_verification['corrupted']);
        }
        
        // Log final summary with verification results
        $wp_success = count($results['successful']);
        $wp_failed = count($results['failed']);
        $wpmudev_success = count($wpmudev_results['successful'] ?? []);
        $wpmudev_failed = count($wpmudev_results['failed'] ?? []);
        clean_sweep_log_message("Final Summary: WordPress.org ({$wp_success}/{$wp_failed} success/failed) + WPMU DEV ({$wpmudev_success}/{$wpmudev_failed} success/failed)");
        
        // Return with verification results
        goto return_after_unified;
    }

    // Legacy batch processing for non-selective mode
    // Slice the plugins array for this batch
    $plugin_keys = array_keys($repo_plugins);
    $batch_plugins = array_slice($plugin_keys, $batch_start, $batch_size, true);
    $batch_count = count($batch_plugins);

    // Calculate overall progress
    $overall_processed = $batch_start;
    $overall_total = $total_plugins_count; // FIX: Use combined total for accurate progress

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
                'phase' => 'reinstall',
                'progress' => round(($overall_processed / $overall_total) * 100),
                'message' => "Reinstalling {$plugin_name}...",
                'details' => "Processing {$plugin_name} ({$overall_processed}/{$overall_total})",
                'current' => $overall_processed,
                'total' => $overall_total,
                'plugin' => $plugin_name,
                'batch_start' => $batch_start,
                'batch_size' => $batch_size,
                'has_more_batches' => ($batch_start + $batch_size) < $overall_total
            ];
            @clean_sweep_write_progress_file($progress_file, $progress_data); // Suppress file write errors
        } elseif (!$is_ajax_request && (!defined('WP_CLI') || !WP_CLI)) {
            // Fallback to inline progress for non-AJAX/non-CLI requests only
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

    // Mark progress as complete - skip for AJAX to avoid corrupting JSON response
    if (!$is_ajax_request && (!defined('WP_CLI') || !WP_CLI)) {
        echo '<script>updateProgress(' . $repo_count . ', ' . $repo_count . ', "Completed");</script>';
        ob_flush();
        flush();
    }

    clean_sweep_log_message("Re-installation completed for batch. Success: $success_count, Failed: $fail_count");

    // Label for unified processing to skip legacy final batch handling
    skip_legacy_final_batch:

    // DEBUG: Log final batch check
    clean_sweep_log_message("DEBUG: Final batch check - batch_start: $batch_start, batch_size: " . ($batch_size ?? 'null') . ", repo_count: $repo_count", 'debug');
    clean_sweep_log_message("DEBUG: wpmu_dev_plugins at final batch check: " . count($wpmu_dev_plugins) . " plugins", 'debug');

    // Check if this is the final batch in the processing
    // Final batch means: all WP.org plugins in this batch are done (batch_start + batch_size >= repo_count)
    // OR we processed fewer plugins than batch size (meaning we're done with WP.org)
    $wp_org_batches_complete = ($batch_start + $batch_size >= $repo_count) || (count(array_keys($repo_plugins)) <= $batch_size);
    
    // FIX: Only consider final batch if WP.org is complete - then process WPMU DEV
    $is_final_batch = $wp_org_batches_complete;

    // Only perform final verification and WPMU DEV processing for final batch
    if ($is_final_batch) {
        clean_sweep_log_message("Final batch detected - performing verification and WPMU DEV processing...");

        // FIX: In selective mode, the unified PluginReinstaller processes both WP.org and WPMU DEV
        // in a single call. We should NOT skip the manager call - instead we need to
        // ensure the unified processing happens on the FIRST batch (not final).
        // 
        // The legacy code in plugin-reinstall.php does its own WP.org processing (lines 440-477),
        // which conflicts with the unified PluginReinstaller. We need to use the unified code
        // INSTEAD OF the legacy code.
        
        // Skip only if we've already processed everything (not first batch)
        $already_processed_all = $selective_mode && ($batch_start > 0);
        
        if ($already_processed_all) {
            clean_sweep_log_message("Skipping separate WPMU DEV processing - all plugins already processed in unified batches", 'info');
            $wpmudev_results = $results['wpmu_dev'] ?? ['successful' => [], 'failed' => []];
        } elseif ($selective_mode && !empty($wpmu_dev_plugins)) {
            // In selective mode, process WPMU DEV plugins using the unified manager
            // This handles both WP.org and WPMU DEV together
            clean_sweep_log_message("Processing selected WPMU DEV plugins with unified handler: " . count($wpmu_dev_plugins) . " plugins");

            $manager = new CleanSweep_PluginReinstallationManager();
            $reinstall_result = $manager->handle_request('start_reinstallation', [
                'progress_file' => $progress_file,
                'create_backup' => false, // Already handled above
                'proceed_without_backup' => true, // Already handled above
                'wp_org_plugins' => [], // No WordPress.org plugins in this phase
                'wpmu_dev_plugins' => $wpmu_dev_plugins, // Pass selected WPMU DEV plugins
                'suspicious_files_to_delete' => $suspicious_files, // Pass selected suspicious files to delete
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
            // Legacy fallback: try to get WPMU DEV plugins from transient/analysis if available
            // This is for backward compatibility with older clients that don't pass wpmu_dev_plugins
            $wpmudev_plugins_to_reinstall = get_transient('clean_sweep_wpmu_dev_plugins');
            if (!$wpmudev_plugins_to_reinstall) {
                // Try to get from stored analysis
                $stored_analysis = get_transient('clean_sweep_plugin_analysis');
                if ($stored_analysis && isset($stored_analysis['wpmu_dev_plugins'])) {
                    $wpmudev_plugins_to_reinstall = $stored_analysis['wpmu_dev_plugins'];
                }
            }
            if (empty($wpmudev_plugins_to_reinstall)) {
                $wpmudev_plugins_to_reinstall = [];
            }
            clean_sweep_log_message("Legacy mode: attempting to get WPMU DEV plugins from storage: " . count($wpmudev_plugins_to_reinstall) . " plugins", 'debug');
            $wpmudev_results = ['successful' => [], 'failed' => []];
        }

        // Merge results and perform verification
        if (!isset($wpmudev_results['error'])) {
            $results['wpmudev'] = $wpmudev_results;

            // Determine which WPMU DEV plugins to verify based on mode
            $wpmudev_plugins_to_reinstall = $selective_mode ? [] : ($wpmudev_plugins_to_reinstall ?? []);
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
        // Initialize verification_results if not set (could be unset if WPMU DEV processing had error)
        if (!isset($verification_results) || !is_array($verification_results)) {
            $verification_results = [
                'verified' => [],
                'missing' => [],
                'corrupted' => []
            ];
        }
        
        $wp_org_verification = clean_sweep_verify_installations($repo_plugins);
        $verification_results['verified'] = array_merge($verification_results['verified'], $wp_org_verification['verified']);
        $verification_results['missing'] = array_merge($verification_results['missing'], $wp_org_verification['missing']);
        $verification_results['corrupted'] = array_merge($verification_results['corrupted'], $wp_org_verification['corrupted']);

        // Only display results for non-AJAX requests on final batch
        // Note: Results are now handled by Alpine.js UI via API calls - skipping old display function
        if (!$progress_file && (!defined('WP_CLI') || !WP_CLI)) {
            // Alpine.js will handle displaying results via AJAX calls to API endpoints
            // Skip: clean_sweep_display_final_results($results, $verification_results);
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
        // Use session_status() to safely check session state without triggering warnings
        if (session_status() === PHP_SESSION_NONE) {
            @session_start(); // Suppress warning if headers already sent
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            $comprehensive_mode = isset($_SESSION['clean_sweep_comprehensive_baseline']) && $_SESSION['clean_sweep_comprehensive_baseline'];
        }

        $visit_boot = dirname(__DIR__, 2) . '/includes/system/visit/bootstrap.php';
        if (is_readable($visit_boot)) {
            require_once $visit_boot;
        }
        // Seal each successfully reinstalled plugin only (never the whole site).
        $plugin_root = defined('ORIGINAL_WP_PLUGIN_DIR')
            ? rtrim(ORIGINAL_WP_PLUGIN_DIR, '/') . '/'
            : (defined('WP_PLUGIN_DIR') ? rtrim(WP_PLUGIN_DIR, '/') . '/' : '');
        if (function_exists('clean_sweep_seal_plugin_dir')) {
            foreach ($results['successful'] as $row) {
                $slug = (string) ($row['slug'] ?? '');
                if ($slug === '' || $plugin_root === '') {
                    continue;
                }
                $dir = $plugin_root . $slug;
                clean_sweep_seal_plugin_dir($slug, $dir);
            }
        }
        // Phase 6: verification baselines from CS reinstall (source=reinstall).
        $pvb = dirname(__DIR__, 2) . '/features/security/scan/PackageVerificationBaseline.php';
        if ($plugin_root !== '' && is_readable($pvb)) {
            require_once $pvb;
            if (class_exists('CleanSweep_PackageVerificationBaseline', false)) {
                foreach ($results['successful'] as $row) {
                    $slug = (string) ($row['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    CleanSweep_PackageVerificationBaseline::create_from_dir([
                        'type' => 'plugin',
                        'slug' => $slug,
                        'dir' => $plugin_root . $slug,
                        'version' => (string) ($row['version'] ?? ''),
                        'name' => (string) ($row['name'] ?? $slug),
                        'source' => 'reinstall',
                    ]);
                }
            }
        }
        clean_sweep_log_message("ℹ️ Sealed successfully reinstalled plugins (not a whole-site baseline)");

        clean_sweep_log_message("Final Summary: WordPress.org ({$wp_success}/{$wp_failed} success/failed) + WPMU DEV ({$wpmudev_success}/{$wpmudev_failed} success/failed)");
        clean_sweep_log_message("=== Complete Plugin Ecosystem Re-installation Completed ===");
    } else {
        // For non-final batches, just log the batch completion
        clean_sweep_log_message("Intermediate batch completed. Awaiting final batch for verification and WPMU DEV processing...");
    }

    // Activation is in the database, so previously active plugins stay active.
    // Restore error handling
    if ($original_error_handler) {
        set_error_handler($original_error_handler);
    } else {
        restore_error_handler();
    }

    // Return both results and verification_results for AJAX responses
    return_after_unified:
    
    return [
        'results' => $results,
        'verification_results' => isset($verification_results) ? $verification_results : ['verified' => [], 'missing' => [], 'corrupted' => []]
    ];
}
