<?php
/**
 * Clean Sweep - Plugin Reinstallation Feature
 *
 * Main orchestration for plugin analysis, backup, and reinstallation
 * Functions are now split into focused modules for better organization
 */

// Load plugin management modules
require_once __DIR__ . '/plugin-utils.php';
require_once __DIR__ . '/plugin-backup.php';
require_once __DIR__ . '/plugin-wordpress.php';
require_once __DIR__ . '/plugin-wpmudev.php';

/**
 * Analyze and categorize all installed plugins for reinstallation
 * Returns arrays categorized by whether they should be handled by WPMU DEV or WordPress.org
 */
function clean_sweep_analyze_plugins($progress_file = null) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    clean_sweep_log_message("=== WordPress Plugin Analysis Started ===");
    clean_sweep_log_message("Version: " . CLEAN_SWEEP_VERSION);
    clean_sweep_log_message("WordPress Version: " . get_bloginfo('version'));
    clean_sweep_log_message("Site URL: " . get_site_url());
    clean_sweep_log_message("Progress file: " . ($progress_file ?: 'none'));

    // Initialize categorized plugin arrays
    $wp_org_plugins = [];    // WordPress.org plugins to reinstall
    $wpmu_dev_plugins = [];  // WPMU DEV plugins to reinstall
    $skipped = [];          // Plugins that can't be reinstalled (non-repository)

    // Check if we can write to plugins directory
    if (!wp_is_writable(WP_PLUGIN_DIR)) {
        clean_sweep_log_message("Error: Plugins directory is not writable. Please check file permissions.", 'error');
        return compact('wp_org_plugins', 'wpmu_dev_plugins', 'skipped');
    }

    // Get WPMU DEV cached projects for lookup (refresh cache)
    $wpmudev_projects = [];
    if (clean_sweep_is_wpmudev_available()) {
        WPMUDEV_Dashboard::$site->refresh_local_projects('local');
        $wpmudev_projects = WPMUDEV_Dashboard::$site->get_cached_projects();
    }

    $all_plugins = get_plugins();
    $total_plugins = count($all_plugins);
    clean_sweep_log_message("Found $total_plugins installed plugins");

    $current_count = 0;

    // Direct one-pass categorization through all plugins
    foreach ($all_plugins as $plugin_file => $plugin_data) {
        $current_count++;
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

        // Extract slug for WordPress.org operations
        $plugin_dir = dirname($plugin_file);
        if ($plugin_dir === '.' || $plugin_dir === '') {
            $slug = pathinfo($plugin_file, PATHINFO_FILENAME);
        } else {
            $slug = basename($plugin_dir);
        }

        // Special handling for Hello Dolly - remove it entirely
        if ($slug === 'hello') {
            $plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if (file_exists($plugin_file_path)) {
                clean_sweep_log_message("Removing Hello Dolly plugin (demo plugin): {$plugin_data['Name']}");

                global $wp_filesystem;
                if (!$wp_filesystem) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    WP_Filesystem();
                }

                if ($wp_filesystem->delete($plugin_file_path)) {
                    clean_sweep_log_message("Successfully removed Hello Dolly plugin", 'info');
                } else {
                    clean_sweep_log_message("Failed to remove Hello Dolly plugin", 'warning');
                }
            }
            continue;
        }

        // CRITICAL: Check WDP ID header first (definitive categorization)
        $wdp = get_file_data($plugin_path, ['id' => 'WDP ID'])['id'];

        if ($wdp && is_numeric($wdp)) {
            // Schedule for WPMU DEV reinstallation with full metadata
            $project_info = clean_sweep_is_wpmudev_available() ?
                WPMUDEV_Dashboard::$site->get_project_info($wdp) : null;

            $wpmu_dev_plugins[$plugin_file] = [
                'wdp_id' => $wdp,
                'name' => $project_info->name ?? $plugin_data['Name'] ?? $plugin_file,
                'version' => $project_info->version_installed ?? $plugin_data['Version'] ?? 'Unknown',
                'description' => $project_info->description ?? $plugin_data['Description'] ?? '',
            ];
            clean_sweep_log_message("Scheduled {$wpmu_dev_plugins[$plugin_file]['name']} for WPMU DEV reinstallation (WDP ID: {$wdp}, Version: {$wpmu_dev_plugins[$plugin_file]['version']})", 'info');
            continue;
        }

        // Check if plugin appears in WPMU DEV cached projects (fallback detection)
        $is_wpmu_dev_plugin = false;
        foreach ((array) $wpmudev_projects as $pid => $project) {
            if (isset($project['filename']) && $project['filename'] === $plugin_file) {
                $is_wpmu_dev_plugin = true;
                $wpmu_dev_plugins[$plugin_file] = ['wdp_id' => null]; // May get PID from project later
                clean_sweep_log_message("Scheduled {$plugin_data['Name']} for WPMU DEV reinstallation (cached project)", 'info');
                break;
            }
        }

        if ($is_wpmu_dev_plugin) {
            continue;
        }

        // Check if this is a WordPress.org plugin that should be reinstalled
        $wp_org_info = clean_sweep_fetch_plugin_info($slug);

        if (!empty($wp_org_info) && isset($wp_org_info['version'])) {
            // Schedule for WordPress.org reinstallation
            $wp_org_plugins[$plugin_file] = [
                'name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'slug' => $slug,
                'last_updated' => $wp_org_info['last_updated'] ?? null,
                'plugin_url' => $wp_org_info['homepage'] ?? "https://wordpress.org/plugins/{$slug}/",
            ];
            clean_sweep_log_message("Scheduled {$plugin_data['Name']} for WordPress.org reinstallation", 'info');
        } else {
            // Add to skipped list - plugin not available in WordPress.org repository
            $skipped[$slug] = [
                'name' => $plugin_data['Name'],
                'reason' => 'Not found in WordPress.org repository'
            ];
            clean_sweep_log_message("Skipping non-repository plugin: {$plugin_data['Name']}", 'warning');
        }

        // Update progress for plugin analysis
        if ($progress_file) {
            $progress_data = [
                'status' => 'analyzing',
                'progress' => round(($current_count / $total_plugins) * 100),
                'message' => "Analyzing plugin $current_count of $total_plugins: {$plugin_data['Name']}",
                'current' => $current_count,
                'total' => $total_plugins,
                'step' => 1,
                'total_steps' => 1
            ];
            @clean_sweep_write_progress_file($progress_file, $progress_data);
        } elseif (!defined('WP_CLI') || !WP_CLI) {
            echo '<script>updateProgress(' . $current_count . ', ' . $total_plugins . ', "Analyzing Plugins");</script>';
            ob_flush();
            flush();
        }
    }

    $wp_org_count = count($wp_org_plugins);
    $wpmu_dev_count = count($wpmu_dev_plugins);
    clean_sweep_log_message("Analysis complete: $wp_org_count WordPress.org plugins, $wpmu_dev_count WPMU DEV plugins");

    // Store WPMU DEV plugins in transient for later use during execution phase
    if (!empty($wpmu_dev_plugins)) {
        set_transient('clean_sweep_wpmudev_plugins', $wpmu_dev_plugins, 3600); // 1 hour
        clean_sweep_log_message("Stored " . count($wpmu_dev_plugins) . " WPMU DEV plugins for reinstallation phase");
    }

    // Update final progress (skip for AJAX requests)
    if (!$progress_file && (!defined('WP_CLI') || !WP_CLI)) {
        echo '<script>updateProgress(' . $total_plugins . ', ' . $total_plugins . ', "Analysis Complete");</script>';
        ob_flush();
        flush();
    }

    clean_sweep_log_message("=== WordPress Plugin Analysis Completed ===");

    return compact('wp_org_plugins', 'wpmu_dev_plugins', 'skipped');
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

    // Get current plugins from WordPress
    $current_plugins = get_plugins();

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
                    $main_file = WP_PLUGIN_DIR . '/' . $plugin_file;
                    if (!file_exists($main_file) || !is_readable($main_file)) {
                        $plugin_corrupted = true;
                    }
                } else {
                    // Plugin is in subdirectory
                    $plugin_dir_path = WP_PLUGIN_DIR . '/' . $plugin_slug;  // FIXED: Use proper slug
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

    // Get current plugins from WordPress
    $current_plugins = get_plugins();

    foreach ($wpmudev_plugins as $plugin_file => $plugin_data) {
        $plugin_name = $plugin_data['name'] ?? $plugin_file;
        $plugin_found = false;
        $plugin_corrupted = false;

        // Check if plugin exists in current plugins list using exact filename
        if (isset($current_plugins[$plugin_file])) {
            $plugin_found = true;

            // Verify the plugin file actually exists and is readable
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
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
 * Analyze plugins without re-installing
 */
function clean_sweep_execute_reinstallation($repo_plugins, $progress_file = null, $batch_start = 0, $batch_size = null) {
    clean_sweep_log_message("=== WordPress Plugin Re-installation Started ===");

    // CRITICAL FIX: Filter out any WPMU DEV plugins that might have slipped through
    // This prevents them from showing in the WordPress.org reinstallation progress
    $filtered_repo_plugins = [];
    foreach ($repo_plugins as $slug => $plugin_data) {
        // Double-check this is not a WPMU DEV plugin
        // Check if there's a corresponding plugin file
        $plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
        $skip_plugin = false;

        if (is_dir($plugin_dir)) {
            // Find the main plugin file
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

    // Update repo_plugins to use filtered list
    $repo_plugins = $filtered_repo_plugins;
    $repo_count = count($repo_plugins);

    clean_sweep_log_message("After filtering WPMU DEV plugins: {$repo_count} WordPress.org plugins to reinstall");

    // Handle case where all plugins were WPMU DEV plugins
    if (empty($repo_plugins) && $batch_start === 0) {
        clean_sweep_log_message("No WordPress.org plugins to re-install after filtering - checking for WPMU DEV only", 'info');

        // Still need to process WPMU DEV plugins if this was meant to be the final batch
        $wpmudev_plugins_to_reinstall = get_transient('clean_sweep_wpmudev_plugins');
        if ($wpmudev_plugins_to_reinstall !== false && !empty($wpmudev_plugins_to_reinstall)) {
            clean_sweep_log_message("Processing WPMU DEV plugins only...");
            $wpmudev_results = clean_sweep_reinstall_wpmudev_plugins($progress_file, $wpmudev_plugins_to_reinstall);
            $results = [
                'successful' => [],
                'failed' => []
            ];

            if (!isset($wpmudev_results['error'])) {
                $results['wpmudev'] = $wpmudev_results;

                // Add WPMU DEV results to main results
                if (isset($wpmudev_results['wpmudev_plugins']) && is_array($wpmudev_results['wpmudev_plugins'])) {
                    foreach ($wpmudev_results['wpmudev_plugins'] as $plugin_file => $plugin_data) {
                        if (isset($plugin_data['installed']) && $plugin_data['installed'] &&
                            (!isset($plugin_data['error']) || $plugin_data['error'] === null)) {
                            $results['successful'][] = [
                                'name' => $plugin_data['name'] ?? $plugin_file,
                                'slug' => $plugin_file,
                                'status' => 'Re-installed successfully (WPMU DEV)',
                                'is_wpmudev' => true
                            ];
                        } elseif (isset($plugin_data['error']) && $plugin_data['error']) {
                            $results['failed'][] = [
                                'name' => $plugin_data['name'] ?? $plugin_file,
                                'slug' => $plugin_file,
                                'status' => 'WPMU DEV re-installation failed: ' . $plugin_data['error'],
                                'is_wpmudev' => true
                            ];
                        }
                    }
                }
            }

            return $results;
        }
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

    // Check disk space before creating backup (only for the first batch)
    if ($batch_start === 0) {
        clean_sweep_log_message("🔍 Starting disk space check for plugin reinstallation (batch_start: $batch_start)", 'info');

        try {
            $disk_check = clean_sweep_check_disk_space('plugin_reinstall');
            clean_sweep_log_message("📊 Disk check result: " . ($disk_check['success'] ? 'PASSED' : 'FAILED'), 'info');

            if (!$disk_check['success']) {
                clean_sweep_log_message("Disk space check failed: {$disk_check['message']}", 'error');

                // For AJAX requests, return the disk space warning
                if ($progress_file) {
                    clean_sweep_log_message("📤 Returning disk space warning for AJAX UI", 'info');
                    $progress_data = [
                        'status' => 'disk_space_warning',
                        'progress' => 0,
                        'message' => 'Insufficient disk space for backup',
                        'disk_check' => $disk_check,
                        'can_proceed_without_backup' => $disk_check['can_proceed'] ?? false
                    ];
                    clean_sweep_write_progress_file($progress_file, $progress_data);
                    return ['disk_space_warning' => $disk_check];
                }

                // For CLI/direct requests, show warning and abort
                clean_sweep_log_message("Plugin reinstallation aborted due to insufficient disk space", 'error');
                clean_sweep_log_message("Required: {$disk_check['required_mb']}MB, Available: {$disk_check['available_mb']}MB", 'error');
                return $results;
            }

            clean_sweep_log_message("Disk space check passed: {$disk_check['backup_size_mb']}MB backup, {$disk_check['available_mb']}MB available", 'info');

        } catch (Exception $e) {
            clean_sweep_log_message("❌ Exception in disk space check: " . $e->getMessage(), 'error');
            // Continue without disk check if there's an exception
            clean_sweep_log_message("⚠️ Continuing without disk space check due to exception", 'warning');
        }

        // Create backup only if disk space check passed
        if (!clean_sweep_create_backup()) {
            clean_sweep_log_message("Backup failed. Aborting re-installation.", 'error');
            return $results;
        }
    } else {
        clean_sweep_log_message("⏭️ Skipping disk space check (batch_start: $batch_start)", 'info');
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

        // Handle WPMU DEV plugins only after ALL WordPress.org batches are complete
        // Retrieve stored WPMU DEV plugins list from analysis phase
        clean_sweep_log_message("Checking for WPMU DEV premium plugins to reinstall...");
        $wpmudev_plugins_to_reinstall = get_transient('clean_sweep_wpmudev_plugins');

        if ($wpmudev_plugins_to_reinstall !== false) {
            clean_sweep_log_message("Found " . count($wpmudev_plugins_to_reinstall) . " WPMU DEV plugins to reinstall from stored analysis");
            $wpmudev_results = clean_sweep_reinstall_wpmudev_plugins($progress_file, $wpmudev_plugins_to_reinstall);
        } else {
            clean_sweep_log_message("No WPMU DEV plugins list found from analysis phase", 'warning');
            $wpmudev_results = ['error' => 'No WPMU DEV plugins list available'];
        }

        // Merge results
        if (!isset($wpmudev_results['error'])) {
            $results['wpmudev'] = $wpmudev_results;
            clean_sweep_log_message("WPMU DEV plugin processing completed: {$wpmudev_results['successful']} success, {$wpmudev_results['failed']} failed");

            // Add successful WPMU DEV plugins to main successful array for proper final display
            if (isset($wpmudev_results['wpmudev_plugins']) && is_array($wpmudev_results['wpmudev_plugins'])) {
                foreach ($wpmudev_results['wpmudev_plugins'] as $pid_index => $plugin_data) {
                    if (isset($plugin_data['installed']) && $plugin_data['installed'] && empty($plugin_data['error'])) {
                        // Add to main successful results for proper final display
                        $results['successful'][] = [
                            'name' => $plugin_data['name'] ?? $plugin_data['filename'] ?? $pid_index,
                            'slug' => $plugin_data['filename'] ?? $plugin_data['name'] ?? $pid_index,
                            'status' => 'Re-installed successfully (WPMU DEV)',
                            'is_wpmudev' => true
                        ];
                    } elseif (isset($plugin_data['error']) && $plugin_data['error']) {
                        // Add failed WPMU DEV plugins to main failed results
                        $results['failed'][] = [
                            'name' => $plugin_data['name'] ?? $plugin_data['filename'] ?? $pid_index,
                            'slug' => $plugin_data['filename'] ?? $plugin_data['name'] ?? $pid_index,
                            'status' => 'WPMU DEV re-installation failed: ' . $plugin_data['error'],
                            'is_wpmudev' => true
                        ];
                    }
                }
            }

            // Filter out excluded plugins from verification (same as install)
            // Exclude WPMU DEV Dashboard (ID 119)
            $filtered_wpmudev_plugins_for_verification = [];
            foreach ($wpmudev_plugins_to_reinstall as $plugin_file => $plugin_data) {
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
        $wpmudev_success = isset($results['wpmudev']['successful']) ? $results['wpmudev']['successful'] : 0;
        $wpmudev_failed = isset($results['wpmudev']['failed']) ? $results['wpmudev']['failed'] : 0;

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
