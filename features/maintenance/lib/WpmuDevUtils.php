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

    // Detect if TARGET site is multisite (not recovery environment)
    $target_is_multisite = clean_sweep_detect_target_multisite();
    clean_sweep_log_message("🎯 Target site multisite: " . ($target_is_multisite ? 'YES' : 'NO'), 'info');

    // Check current environment multisite status
    $recovery_is_multisite = is_multisite();
    clean_sweep_log_message("🏗️ Recovery environment multisite: " . ($recovery_is_multisite ? 'YES' : 'NO'), 'info');

    // Handle API key location for multisite backward compatibility
    // When sites are converted from single to multisite, API key may still be in wp_options
    if ($target_is_multisite) {
        // Ensure multisite table names are defined even in single-site recovery environment
        global $wpdb;
        if (empty($wpdb->sitemeta)) {
            $wpdb->sitemeta = $wpdb->prefix . 'sitemeta';
            clean_sweep_log_message("🔧 Defined multisite table names for recovery environment", 'info');
        }

        $site_api_key = get_site_option('wpmudev_apikey');     // wp_sitemeta (normal multisite)
        $option_api_key = get_option('wpmudev_apikey');        // wp_options (converted sites)

        // Debug: Log key status without exposing sensitive data
        $site_key_length = !empty($site_api_key) ? strlen($site_api_key) : 0;
        $option_key_length = !empty($option_api_key) ? strlen($option_api_key) : 0;
        clean_sweep_log_message("🔍 API Key Check - sitemeta: " . ($site_key_length > 0 ? "{$site_key_length} chars" : "empty") . ", options: " . ($option_key_length > 0 ? "{$option_key_length} chars" : "empty"), 'info');

        // Get API key using direct database queries since get_site_option() doesn't work in recovery environment
        $api_key = null;
        try {
            global $wpdb;
            if ($wpdb) {
                // Get API key directly from database - try sitemeta first, then options
                $sitemeta_key = $wpdb->get_var("SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = 'wpmudev_apikey' LIMIT 1");
                if (!empty($sitemeta_key)) {
                    $api_key = $sitemeta_key;
                    clean_sweep_log_message("🔑 Found WPMU DEV API key in wp_sitemeta table", 'info');
                } else {
                    $options_key = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpmudev_apikey' LIMIT 1");
                    if (!empty($options_key)) {
                        $api_key = $options_key;
                        clean_sweep_log_message("🔑 Found WPMU DEV API key in wp_options table (converted site)", 'info');
                    }
                }
            }
        } catch (Exception $e) {
            clean_sweep_log_message("❌ Error getting API key from database: " . $e->getMessage(), 'error');
        }

        // Define the constant with the found key
        if (!empty($api_key) && !defined('WPMUDEV_APIKEY')) {
            define('WPMUDEV_APIKEY', $api_key);
            clean_sweep_log_message("🔑 Defined WPMUDEV_APIKEY constant for multisite compatibility", 'info');
        } elseif (empty($api_key)) {
            clean_sweep_log_message("❌ No WPMU DEV API key found in database", 'error');
        }

        // Update the WordPress function variables for consistency (even though we don't use them)
        if (!empty($api_key)) {
            $site_api_key = $api_key;  // For backward compatibility with existing code
        }
    }

    // Check API key status - Use our own validation since plugin's has_key() is unreliable
    $has_key = false;

    // If we successfully found and defined the API key, consider it authenticated
    if (defined('WPMUDEV_APIKEY') && !empty(WPMUDEV_APIKEY)) {
        $has_key = true;
        clean_sweep_log_message("✅ Authentication successful - API key found and defined", 'info');
    } elseif (isset(WPMUDEV_Dashboard::$api)) {
        // Fallback to plugin's method if our method didn't work
        $has_key = WPMUDEV_Dashboard::$api->has_key();
        clean_sweep_log_message("🔑 WPMUDEV_Dashboard::\$api->has_key(): " . ($has_key ? 'TRUE' : 'FALSE'), 'info');
    } else {
        clean_sweep_log_message("❌ WPMUDEV_Dashboard::\$api not available", 'error');
        return false;
    }

    // If target is multisite but API key check fails, try alternative authentication methods
    if (!$has_key && $target_is_multisite) {
        clean_sweep_log_message("🔄 Target is multisite but has_key() failed - trying alternative auth methods", 'info');

        // Try network-level authentication if available
        if (isset(WPMUDEV_Dashboard::$network) && method_exists(WPMUDEV_Dashboard::$network, 'has_key')) {
            $network_has_key = WPMUDEV_Dashboard::$network->has_key();
            clean_sweep_log_message("🔑 WPMUDEV_Dashboard::\$network->has_key(): " . ($network_has_key ? 'TRUE' : 'FALSE'), 'info');
            if ($network_has_key) {
                $has_key = true;
                clean_sweep_log_message("✅ Using network-level authentication", 'info');
            }
        }

        // Try to initialize WPMU DEV properly for multisite
        if (!$has_key && method_exists('WPMUDEV_Dashboard', 'instance')) {
            try {
                $dashboard_instance = WPMUDEV_Dashboard::instance();
                if ($dashboard_instance && isset(WPMUDEV_Dashboard::$api)) {
                    // Try has_key again after initialization
                    $has_key_retry = WPMUDEV_Dashboard::$api->has_key();
                    clean_sweep_log_message("🔑 WPMUDEV_Dashboard::\$api->has_key() (retry): " . ($has_key_retry ? 'TRUE' : 'FALSE'), 'info');
                    if ($has_key_retry) {
                        $has_key = true;
                        clean_sweep_log_message("✅ Authentication successful on retry", 'info');
                    }
                }
            } catch (Exception $e) {
                clean_sweep_log_message("❌ Error during multisite auth retry: " . $e->getMessage(), 'warning');
            }
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

                // Additional check: if we have projects, authentication is definitely working
                if ($project_count > 0) {
                    clean_sweep_log_message("✅ Confirmed authentication - found {$project_count} WPMU DEV projects", 'info');
                }
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
 * Detect if the target site is multisite by examining its configuration and database
 *
 * @return bool True if target site is multisite, false otherwise
 */
function clean_sweep_detect_target_multisite() {
    static $target_multisite_cache = null;

    if ($target_multisite_cache !== null) {
        return $target_multisite_cache;
    }

    clean_sweep_log_message("🔍 Detecting target site multisite status...", 'debug');

    // Method 1: Check if MULTISITE constant is defined in target config
    if (defined('ORIGINAL_WP_CONTENT_DIR')) {
        $target_config_path = str_replace('/wp-content', '/wp-config.php', ORIGINAL_WP_CONTENT_DIR);
        if (file_exists($target_config_path)) {
            $config_content = file_get_contents($target_config_path);
            if ($config_content !== false && strpos($config_content, "define('MULTISITE', true)") !== false) {
                clean_sweep_log_message("✅ Target site detected as multisite via wp-config.php", 'debug');
                return $target_multisite_cache = true;
            }
        }
    }

    // Method 2: Check database for multisite tables
    try {
        global $wpdb;
        if ($wpdb) {
            $multisite_tables = ['wp_blogs', 'wp_blog_versions', 'wp_registration_log', 'wp_signups', 'wp_site', 'wp_sitemeta'];
            $found_multisite_tables = 0;

            foreach ($multisite_tables as $table) {
                // Use table_exists check or direct query
                $table_name = str_replace('wp_', $wpdb->prefix, $table);
                $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
                if ($table_exists) {
                    $found_multisite_tables++;
                }
            }

            if ($found_multisite_tables >= 3) { // Require at least 3 multisite tables to be confident
                clean_sweep_log_message("✅ Target site detected as multisite via database tables ({$found_multisite_tables} found)", 'debug');
                return $target_multisite_cache = true;
            }
        }
    } catch (Exception $e) {
        clean_sweep_log_message("⚠️ Error checking database for multisite tables: " . $e->getMessage(), 'debug');
    }

    // Method 3: Check sitemeta table (most reliable multisite indicator)
    try {
        global $wpdb;
        if ($wpdb) {
            $sitemeta_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . 'sitemeta'));
            if ($sitemeta_table) {
                $site_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sitemeta WHERE meta_key = 'site_name'");
                if ($site_count > 0) {
                    clean_sweep_log_message("✅ Target site detected as multisite via sitemeta table", 'debug');
                    return $target_multisite_cache = true;
                }
            }
        }
    } catch (Exception $e) {
        clean_sweep_log_message("⚠️ Error checking sitemeta table: " . $e->getMessage(), 'debug');
    }

    // Default to single site if we can't determine
    clean_sweep_log_message("🏠 Target site detected as single site (default)", 'debug');
    return $target_multisite_cache = false;
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
