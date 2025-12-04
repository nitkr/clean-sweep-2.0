<?php
/**
 * Clean Sweep - WPMU DEV Premium Plugin Processing
 *
 * Functions for handling WPMU DEV premium plugins
 */

/**
 * Check if WPMU DEV Dashboard is available and authenticated
 */
function clean_sweep_is_wpmudev_available() {
    // Check if WPMU DEV Dashboard class exists and is authenticated
    if (!class_exists('WPMUDEV_Dashboard')) {
        return false;
    }

    if (!WPMUDEV_Dashboard::$api->has_key()) {
        clean_sweep_log_message("WPMU DEV Dashboard found but not authenticated", 'warning');
        return false;
    }

    clean_sweep_log_message("WPMU DEV Dashboard detected and authenticated");
    return true;
}


/**
 * Reinstall WPMU DEV premium plugins
 *
 * @param string|null $progress_file Progress file path for tracking
 * @param array $wpmudev_plugins_list List of WPMU DEV plugins to reinstall from analysis phase
 */
function clean_sweep_reinstall_wpmudev_plugins($progress_file = null, $wpmudev_plugins_list = null) {
    clean_sweep_log_message("=== WPMU DEV Plugin Re-installation Started ===");

    // Check if we have a specific list of plugins to reinstall
    if (empty($wpmudev_plugins_list)) {
        clean_sweep_log_message("No WPMU DEV plugins list provided, skipping reinstallation", 'warning');
        return [
            'wpmudev_plugins' => [],
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'error' => 'No WPMU DEV plugins to reinstall'
        ];
    }

    clean_sweep_log_message("Processing " . count($wpmudev_plugins_list) . " WPMU DEV plugins from analysis phase");

    // Verify WPMU DEV availability
    if (!clean_sweep_is_wpmudev_available()) {
        clean_sweep_log_message("WPMU DEV Dashboard not available or not authenticated", 'error');
        return ['error' => 'WPMU DEV not available'];
    }

    // Set unlimited execution time for potentially large operations
    set_time_limit(0);
    @ini_set('memory_limit', wp_convert_hr_to_bytes('512M'));

    // Admin user selection (similar to provided code)
    $admin = clean_sweep_get_wpmudev_admin_user();
    if (!$admin) {
        clean_sweep_log_message("No administrator account found for WPMU DEV operations", 'error');
        return ['error' => 'No admin user'];
    }

    wp_set_current_user($admin->ID);
    clean_sweep_log_message("Using admin user: " . $admin->user_login);

    // Get WPMU DEV dashboard and objects
    $dashboard = WPMUDEV_Dashboard::instance();
    $site = WPMUDEV_Dashboard::$site;
    $upgrader = WPMUDEV_Dashboard::$upgrader;

    // Refresh local projects cache
    $site->refresh_local_projects('local');
    $projects = (array) $site->get_cached_projects();

    clean_sweep_log_message("Found " . count($projects) . " WPMU DEV projects");

    $results = [
        'wpmudev_plugins' => [],
        'processed' => 0,
        'successful' => 0,
        'failed' => 0
    ];

    // Process only the plugins from the analysis phase stored list
    foreach ($wpmudev_plugins_list as $plugin_file => $plugin_info) {
        $pid = $plugin_info['wdp_id'] ?? $plugin_info['pid'] ?? null;  // Also check 'wdp_id' for backward compatibility

        if (!$pid) {
            clean_sweep_log_message("No PID found for plugin: " . ($plugin_info['name'] ?? $plugin_file), 'warning');
            continue;
        }

        // Skip specific project if needed (from original code)
        if ((int) $pid === 119) {
            clean_sweep_log_message("Skipping WPMU DEV project ID 119 (excluded)");
            continue;
        }

        // Verify this project exists in current cache
        if (!isset($projects[$pid])) {
            clean_sweep_log_message("WPMU DEV project ID {$pid} not found in cache, skipping", 'warning');
            continue;
        }

        $project = $projects[$pid];
        $plugin_filename = $plugin_file; // Use the exact filename from analysis

        $current_count = $results['processed'] + 1;
        clean_sweep_log_message("Processing WPMU DEV plugin: {$plugin_info['name']} (ID: $pid)");

        // Check activation status
        $is_active_blog = is_plugin_active($plugin_filename);
        $is_active_network = is_multisite() && is_plugin_active_for_network($plugin_filename);
        $should_reactivate = $is_active_blog || $is_active_network;

        // Initialize result entry
        $entry = [
            'pid' => (int) $pid,
            'name' => $plugin_info['name'] ?? $plugin_file,
            'filename' => $plugin_filename,
            'deleted' => false,
            'installed' => false,
            'reactivated' => false,
            'error' => null,
            'deactivated' => false,
            'deactivate_error' => null,
        ];

        // Update progress if specified
        if ($progress_file) {
            $progress_data = [
                'status' => 'wpmudev_reinstalling',
                'progress' => 100, // Static since this is typically fast
                'message' => "Processing WPMU DEV plugin: {$entry['name']}",
                'current' => $current_count,
                'total' => count($wpmudev_plugins_list),
            ];
            @clean_sweep_write_progress_file($progress_file, $progress_data);
        }

        // Handle deactivation (similar to original logic)
        if ($is_active_network) {
            $deactivation_result = deactivate_plugins($plugin_filename, true, true);
            if (is_wp_error($deactivation_result)) {
                $entry['deactivate_error'] = $deactivation_result->get_error_message();
                $results['wpmudev_plugins'][] = $entry;
                $results['failed']++;
                $results['processed']++;
                continue;
            }
            $entry['deactivated'] = true;
        } elseif ($is_active_blog) {
            $deactivation_result = deactivate_plugins($plugin_filename, true, false);
            if (is_wp_error($deactivation_result)) {
                $entry['deactivate_error'] = $deactivation_result->get_error_message();
                $results['wpmudev_plugins'][] = $entry;
                $results['failed']++;
                $results['processed']++;
                continue;
            }
            $entry['deactivated'] = true;
        }

        // Delete existing plugin using WPMU DEV's method
        if (!$upgrader->delete_plugin($pid, true)) {
            $error = $upgrader->get_error();
            $entry['error'] = $error ? "{$error['code']}: {$error['message']}" : 'delete_failed';
            $results['wpmudev_plugins'][] = $entry;
            $results['failed']++;
            $results['processed']++;
            clean_sweep_log_message("Failed to delete WPMU DEV plugin {$entry['name']}", 'error');
            continue;
        }
        $entry['deleted'] = true;

        // Use WPMU DEV's authenticated endpoint to bypass WordPress.org completely
        // This ensures Pro versions are downloaded even for plugins that exist on WP.org
        $download_url = WPMUDEV_Dashboard::$api->rest_url_auth('install/' . $pid);
        $temp_file = download_url($download_url);

        if (is_wp_error($temp_file)) {
            $entry['error'] = 'Download failed: ' . $temp_file->get_error_message();
            $results['failed']++;
            clean_sweep_log_message("Failed to download WPMU DEV plugin {$entry['name']}: " . $temp_file->get_error_message(), 'error');
            $results['wpmudev_plugins'][] = $entry;
            $results['processed']++;
            continue;
        }

        // Install the downloaded ZIP using WordPress filesystem
        $upload_dir = wp_upload_dir();
        $target_dir = ORIGINAL_WP_PLUGIN_DIR . '/' . dirname($plugin_filename);

        if (!wp_mkdir_p($target_dir)) {
            @unlink($temp_file);
            $entry['error'] = 'Failed to create target directory';
            $results['failed']++;
            clean_sweep_log_message("Failed to create target directory for WPMU DEV plugin {$entry['name']}", 'error');
            $results['wpmudev_plugins'][] = $entry;
            $results['processed']++;
            continue;
        }

        $result = unzip_file($temp_file, ORIGINAL_WP_PLUGIN_DIR);
        @unlink($temp_file); // Clean up temporary file

        if (is_wp_error($result)) {
            $entry['error'] = 'Extraction failed: ' . $result->get_error_message();
            $results['failed']++;
            clean_sweep_log_message("Failed to extract WPMU DEV plugin {$entry['name']}: " . $result->get_error_message(), 'error');
        } else {
            $entry['installed'] = true;
            $results['successful']++;

            // Clear and immediately refresh Dashboard cache so plugin is detectable immediately
            if (isset(WPMUDEV_Dashboard::$site)) {
                WPMUDEV_Dashboard::$site->clear_local_file_cache();
                WPMUDEV_Dashboard::$site->refresh_local_projects('local');
                clean_sweep_log_message("Cleared and refreshed WPMU DEV cache after successful install of {$entry['name']}", 'debug');
            }

            // Reactivate if it should be active (this is optional - install success takes precedence)
            if ($should_reactivate) {
                if ($is_active_network) {
                    $reactivation_result = activate_plugin($plugin_filename, '', true, true);
                } else {
                    $reactivation_result = activate_plugin($plugin_filename, '', false, true);
                }

                if (is_wp_error($reactivation_result)) {
                    $entry['reactivate_error'] = $reactivation_result->get_error_message();
                    clean_sweep_log_message("WPMU DEV plugin {$entry['name']} installed but reactivation failed: " . $reactivation_result->get_error_message(), 'warning');
                } else {
                    $entry['reactivated'] = true;
                }
            }
        }

        $results['wpmudev_plugins'][] = $entry;
        $results['processed']++;
    }

    $results['summary'] = "WPMU DEV: {$results['successful']} successful, {$results['failed']} failed, {$results['processed']} total";
    clean_sweep_log_message($results['summary']);

    if (!$progress_file && (!defined('WP_CLI') || !WP_CLI)) {
        echo '<script>updateProgress(100, 100, "WPMU DEV plugins processed: ' . $results['successful'] . ' success, ' . $results['failed'] . ' failed");</script>';
        ob_flush();
        flush();
    }

    clean_sweep_log_message("=== WPMU DEV Plugin Re-installation Completed ===");

    return $results;
}

/**
 * Get appropriate admin user for WPMU DEV operations
 * Similar to the wpmudev_pick_admin_user function from the original code
 */
function clean_sweep_get_wpmudev_admin_user() {
    if (is_multisite()) {
        $super_admins = get_super_admins();
        if (!empty($super_admins)) {
            $user = get_user_by('login', $super_admins[0]);
            if ($user) {
                return $user;
            }
        }
    }

    $admins = get_users(
        [
            'role'    => 'administrator',
            'orderby' => 'ID',
            'order'   => 'ASC',
            'number'  => 1,
            'fields'  => ['ID'],
        ]
    );

    if (!empty($admins)) {
        return get_user_by('id', $admins[0]->ID);
    }

    return null;
}
