<?php
/**
 * Clean Sweep - WPMU DEV Utilities
 *
 * Shared utilities for WPMU DEV plugin handling
 */

/**
 * Check if WPMU DEV Dashboard is available and authenticated
 *
 * @return bool True if WPMU DEV Dashboard is available and authenticated, false otherwise
 */
function clean_sweep_is_wpmudev_available() {
    clean_sweep_log_message("=== WPMU DEV Authentication Check ===", 'info');

    // If class doesn't exist, try to auto-load the WPMU DEV Dashboard plugin
    if (!class_exists('WPMUDEV_Dashboard')) {
        $dashboard_path = WP_PLUGIN_DIR . '/wpmudev-updates/update-notifications.php';
        if (file_exists($dashboard_path)) {
            clean_sweep_log_message("🔄 Auto-loading WPMU DEV Dashboard plugin...", 'info');
            include_once $dashboard_path;

            if (class_exists('WPMUDEV_Dashboard')) {
                clean_sweep_log_message("✅ WPMU DEV Dashboard plugin loaded successfully", 'info');
            } else {
                clean_sweep_log_message("❌ Failed to load WPMU DEV Dashboard plugin", 'error');
                return false;
            }
        } else {
            clean_sweep_log_message("❌ WPMU DEV Dashboard plugin file not found at: {$dashboard_path}", 'error');
            return false;
        }
    } else {
        clean_sweep_log_message("✅ WPMUDEV_Dashboard class already exists", 'info');
    }

    // Check multisite status
    $is_multisite = is_multisite();
    clean_sweep_log_message("📊 Multisite: " . ($is_multisite ? 'YES' : 'NO'), 'info');

    // In multisite, also verify the plugin is activated (network or site level)
    if ($is_multisite) {
        $network_active = is_plugin_active_for_network('wpmudev-updates/update-notifications.php');
        $site_active = is_plugin_active('wpmudev-updates/update-notifications.php');

        if (!$network_active && !$site_active) {
            clean_sweep_log_message("❌ WPMU DEV Dashboard not activated in multisite (neither network nor site level)", 'error');
            return false;
        }

        clean_sweep_log_message("✅ WPMU DEV Dashboard activated - Network: " . ($network_active ? 'YES' : 'NO') . ", Site: " . ($site_active ? 'YES' : 'NO'), 'info');

        // Check current site vs main site
        $current_site_id = get_current_blog_id();
        $main_site_id = get_main_site_id();
        clean_sweep_log_message("🏠 Current site ID: {$current_site_id}, Main site ID: {$main_site_id}", 'info');
    }

    // Check API key status
    if (!isset(WPMUDEV_Dashboard::$api)) {
        clean_sweep_log_message("❌ WPMUDEV_Dashboard::\$api not available", 'error');
        return false;
    }

    $has_key = WPMUDEV_Dashboard::$api->has_key();
    clean_sweep_log_message("🔑 WPMUDEV_Dashboard::\$api->has_key(): " . ($has_key ? 'TRUE' : 'FALSE'), 'info');

    // Additional multisite network checks
    if ($is_multisite && isset(WPMUDEV_Dashboard::$network)) {
        clean_sweep_log_message("🌐 WPMUDEV_Dashboard::\$network exists", 'info');
        if (method_exists(WPMUDEV_Dashboard::$network, 'has_key')) {
            $network_has_key = WPMUDEV_Dashboard::$network->has_key();
            clean_sweep_log_message("🔑 WPMUDEV_Dashboard::\$network->has_key(): " . ($network_has_key ? 'TRUE' : 'FALSE'), 'info');
        } else {
            clean_sweep_log_message("❌ WPMUDEV_Dashboard::\$network->has_key() method not available", 'warning');
        }
    }

    // Check if we can get projects (additional auth test)
    if ($has_key) {
        try {
            if (isset(WPMUDEV_Dashboard::$site)) {
                WPMUDEV_Dashboard::$site->refresh_local_projects('local');
                $projects = WPMUDEV_Dashboard::$site->get_cached_projects();
                $project_count = is_array($projects) ? count($projects) : 0;
                clean_sweep_log_message("📦 Available WPMU DEV projects: {$project_count}", 'info');
            } else {
                clean_sweep_log_message("❌ WPMUDEV_Dashboard::\$site not available for project check", 'warning');
            }
        } catch (Exception $e) {
            clean_sweep_log_message("❌ Error checking WPMU DEV projects: " . $e->getMessage(), 'error');
        }
    }

    if (!$has_key) {
        clean_sweep_log_message("❌ WPMU DEV Dashboard found but not authenticated (has_key returned false)", 'warning');
        return false;
    }

    clean_sweep_log_message("✅ WPMU DEV Dashboard detected and authenticated", 'info');
    return true;
}

/**
 * Get appropriate admin user for WPMU DEV operations
 *
 * Gets super admin for multisite, or first regular admin for single site.
 * Used for setting current user context during WPMU DEV operations.
 *
 * @return WP_User|null User object or null if no admin found
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

    $admins = get_users([
        'role'    => 'administrator',
        'orderby' => 'ID',
        'order'   => 'ASC',
        'number'  => 1,
        'fields'  => ['ID'],
    ]);

    if (!empty($admins)) {
        return get_user_by('id', $admins[0]->ID);
    }

    return null;
}
