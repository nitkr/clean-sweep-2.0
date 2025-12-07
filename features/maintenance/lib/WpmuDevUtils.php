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
    if (!class_exists('WPMUDEV_Dashboard')) {
        return false;
    }

    // First check: Basic has_key() method
    $has_key = WPMUDEV_Dashboard::$api->has_key();

    // Second check: Try to get the API key directly (more reliable)
    $api_key = WPMUDEV_Dashboard::$api->get_key();

    // Third check: Try a simple API call to verify authentication
    $is_authenticated = false;
    if ($has_key && !empty($api_key)) {
        // Try to get user data as a test of authentication
        try {
            $user_data = WPMUDEV_Dashboard::$api->get_user_data();
            $is_authenticated = !empty($user_data) && !isset($user_data['error']);
        } catch (Exception $e) {
            $is_authenticated = false;
        }
    }

    // Log debug information
    clean_sweep_log_message("WPMU DEV Auth Check - has_key: " . ($has_key ? 'TRUE' : 'FALSE') .
                           ", api_key: " . (!empty($api_key) ? 'PRESENT' : 'EMPTY') .
                           ", authenticated: " . ($is_authenticated ? 'TRUE' : 'FALSE'), 'debug');

    if (!$is_authenticated) {
        clean_sweep_log_message("WPMU DEV Dashboard found but not authenticated", 'warning');
        return false;
    }

    clean_sweep_log_message("WPMU DEV Dashboard detected and authenticated");
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
