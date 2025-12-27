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

    if (!class_exists('WPMUDEV_Dashboard')) {
        clean_sweep_log_message("❌ WPMUDEV_Dashboard class does not exist", 'error');
        return false;
    }

    clean_sweep_log_message("✅ WPMUDEV_Dashboard class exists", 'info');

    // Check multisite status
    $is_multisite = is_multisite();
    clean_sweep_log_message("📊 Multisite: " . ($is_multisite ? 'YES' : 'NO'), 'info');

    // Check API key status
    $has_key = WPMUDEV_Dashboard::$api->has_key();
    clean_sweep_log_message("🔑 WPMUDEV_Dashboard::\$api->has_key(): " . ($has_key ? 'TRUE' : 'FALSE'), 'info');

    // Additional multisite checks
    if ($is_multisite) {
        // Check network properties
        if (isset(WPMUDEV_Dashboard::$network)) {
            clean_sweep_log_message("🌐 WPMUDEV_Dashboard::\$network exists", 'info');
            if (method_exists(WPMUDEV_Dashboard::$network, 'has_key')) {
                $network_has_key = WPMUDEV_Dashboard::$network->has_key();
                clean_sweep_log_message("🔑 WPMUDEV_Dashboard::\$network->has_key(): " . ($network_has_key ? 'TRUE' : 'FALSE'), 'info');
            } else {
                clean_sweep_log_message("❌ WPMUDEV_Dashboard::\$network->has_key() method not available", 'warning');
            }
        } else {
            clean_sweep_log_message("❌ WPMUDEV_Dashboard::\$network not set", 'warning');
        }

        // Check current site vs main site
        $current_site_id = get_current_blog_id();
        $main_site_id = get_main_site_id();
        clean_sweep_log_message("🏠 Current site ID: {$current_site_id}, Main site ID: {$main_site_id}", 'info');
    }

    // Check if we can get projects (additional auth test)
    if ($has_key) {
        try {
            WPMUDEV_Dashboard::$site->refresh_local_projects('local');
            $projects = WPMUDEV_Dashboard::$site->get_cached_projects();
            $project_count = is_array($projects) ? count($projects) : 0;
            clean_sweep_log_message("📦 Available WPMU DEV projects: {$project_count}", 'info');
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
