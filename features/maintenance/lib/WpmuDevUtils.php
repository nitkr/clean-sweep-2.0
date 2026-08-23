<?php
/**
 * Clean Sweep - WPMU DEV Utilities
 *
 * Shared utilities for WPMU DEV plugin handling
 */

/**
 * Sitemeta table name for the live site (recovery WP may not define $wpdb->sitemeta).
 */
function clean_sweep_sitemeta_table() {
    global $wpdb;
    if (!$wpdb) {
        return null;
    }
    if (!empty($wpdb->sitemeta)) {
        return $wpdb->sitemeta;
    }
    $prefix = !empty($wpdb->base_prefix) ? $wpdb->base_prefix : $wpdb->prefix;
    return $prefix . 'sitemeta';
}

/**
 * Normalize blog + network plugin lists from raw option/sitemeta values.
 * Sitewide plugins are stored as [ 'dir/file.php' => timestamp, ... ].
 *
 * @param mixed $blog_plugins
 * @param mixed $sitewide
 * @return array{blog: string[], network: string[]}
 */
function clean_sweep_normalize_plugin_file_lists($blog_plugins, $sitewide): array {
    $blog = [];
    foreach ((array) $blog_plugins as $p) {
        if (is_string($p) && $p !== '') {
            $blog[] = $p;
        }
    }
    $network = [];
    foreach ((array) $sitewide as $k => $v) {
        if (is_string($k) && strpos($k, '.php') !== false) {
            $network[] = $k;
        } elseif (is_string($v) && strpos($v, '.php') !== false) {
            $network[] = $v;
        }
    }
    return ['blog' => array_values(array_unique($blog)), 'network' => array_values(array_unique($network))];
}

function clean_sweep_plugin_is_active_in_lists(string $plugin, array $blog, array $network): bool {
    return in_array($plugin, $blog, true) || in_array($plugin, $network, true);
}

/**
 * Blog-level active_plugins from the live options table (not recovery memory).
 *
 * @return string[]
 */
function clean_sweep_get_blog_active_plugins(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    global $wpdb;
    if ($wpdb) {
        $option_value = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins' LIMIT 1");
        $unserialized = maybe_unserialize($option_value);
        $cache = clean_sweep_normalize_plugin_file_lists($unserialized, [])['blog'];
    }
    return $cache;
}

/**
 * Network-activated plugins from sitemeta.active_sitewide_plugins.
 *
 * @return string[]
 */
function clean_sweep_get_network_active_plugins(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    global $wpdb;
    $table = clean_sweep_sitemeta_table();
    if (!$wpdb || !$table) {
        return $cache;
    }
    $raw = $wpdb->get_var("SELECT meta_value FROM {$table} WHERE meta_key = 'active_sitewide_plugins' LIMIT 1");
    $cache = clean_sweep_normalize_plugin_file_lists([], maybe_unserialize($raw))['network'];
    return $cache;
}

function clean_sweep_is_plugin_network_active($plugin) {
    return in_array($plugin, clean_sweep_get_network_active_plugins(), true);
}

/**
 * True if the plugin is site-active or network-active on the live target.
 * Recovery WP is often single-site while the target is Multisite, so this
 * reads the database instead of is_plugin_active() / is_plugin_active_for_network().
 *
 * @param string $plugin e.g. 'wpmudev-updates/update-notifications.php'
 */
function clean_sweep_is_plugin_active($plugin) {
    return clean_sweep_plugin_is_active_in_lists(
        $plugin,
        clean_sweep_get_blog_active_plugins(),
        clean_sweep_get_network_active_plugins()
    );
}

/**
 * @return array{blog: bool, network: bool}
 */
function clean_sweep_plugin_activation_state($plugin) {
    return [
        'blog' => in_array($plugin, clean_sweep_get_blog_active_plugins(), true),
        'network' => clean_sweep_is_plugin_network_active($plugin),
    ];
}

/**
 * Add or remove a plugin from sitemeta.active_sitewide_plugins when recovery
 * is not a real Multisite runtime (activate_plugin network-wide would no-op).
 */
function clean_sweep_set_network_plugin_active($plugin, $active) {
    global $wpdb;
    $table = clean_sweep_sitemeta_table();
    if (!$wpdb || !$table || !is_string($plugin) || $plugin === '') {
        return false;
    }
    $raw = @$wpdb->get_var("SELECT meta_value FROM {$table} WHERE meta_key = 'active_sitewide_plugins' LIMIT 1");
    $list = maybe_unserialize($raw);
    if (!is_array($list)) {
        $list = [];
    }
    if ($active) {
        $list[$plugin] = time();
    } else {
        unset($list[$plugin]);
    }
    $stored = maybe_serialize($list);
    if ($raw === null) {
        return $wpdb->insert($table, [
            'site_id' => 1,
            'meta_key' => 'active_sitewide_plugins',
            'meta_value' => $stored,
        ], ['%d', '%s', '%s']) !== false;
    }
    return $wpdb->update(
        $table,
        ['meta_value' => $stored],
        ['meta_key' => 'active_sitewide_plugins'],
        ['%s'],
        ['%s']
    ) !== false;
}

/**
 * Check if WPMU DEV Dashboard is available and authenticated
 *
 * @return bool True if WPMU DEV Dashboard is available and authenticated, false otherwise
 */
function clean_sweep_is_wpmudev_available() {
    clean_sweep_log_message("=== WPMU DEV Authentication Check ===", 'info');

    // PHASE 1: Detect if TARGET site is multisite (BEFORE loading Dashboard)
    $target_is_multisite = clean_sweep_detect_target_multisite();
    clean_sweep_log_message("🎯 Target site multisite: " . ($target_is_multisite ? 'YES' : 'NO'), 'info');

    // Check current environment multisite status
    $recovery_is_multisite = is_multisite();
    clean_sweep_log_message("🏗️ Recovery environment multisite: " . ($recovery_is_multisite ? 'YES' : 'NO'), 'info');

    // PHASE 2: Retrieve API key from database BEFORE loading Dashboard plugin
    // This is critical: Dashboard reads API key during initialization, so constant must exist first
    $api_key = null;
    
    try {
        global $wpdb;
        if ($wpdb) {
            // Ensure multisite table names are defined even in single-site recovery environment
            $sitemeta_table = clean_sweep_sitemeta_table();
            if (empty($wpdb->sitemeta) && $sitemeta_table) {
                $wpdb->sitemeta = $sitemeta_table;
                clean_sweep_log_message("🔧 Defined multisite table names for recovery environment", 'info');
            }

            if ($target_is_multisite) {
                // Multisite: Try sitemeta first (normal), then options (converted sites)
                $sitemeta_key = $sitemeta_table
                    ? $wpdb->get_var("SELECT meta_value FROM {$sitemeta_table} WHERE meta_key = 'wpmudev_apikey' LIMIT 1")
                    : null;
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
            } else {
                // Single site: Check wp_options table
                $options_key = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpmudev_apikey' LIMIT 1");
                if (!empty($options_key)) {
                    $api_key = $options_key;
                    clean_sweep_log_message("🔑 Found WPMU DEV API key in wp_options table (single site)", 'info');
                }
            }
        }
    } catch (Exception $e) {
        clean_sweep_log_message("❌ Error getting API key from database: " . $e->getMessage(), 'error');
    }

    // PHASE 3: Define WPMUDEV_APIKEY constant BEFORE loading Dashboard
    // Dashboard plugin checks for this constant during initialization
    if (!empty($api_key) && !defined('WPMUDEV_APIKEY')) {
        define('WPMUDEV_APIKEY', $api_key);
        $key_length = strlen($api_key);
        clean_sweep_log_message("🔑 Defined WPMUDEV_APIKEY constant BEFORE Dashboard load ({$key_length} chars)", 'info');
    } elseif (empty($api_key)) {
        clean_sweep_log_message("❌ No WPMU DEV API key found in database", 'error');
    } elseif (defined('WPMUDEV_APIKEY')) {
        clean_sweep_log_message("🔑 WPMUDEV_APIKEY constant already defined", 'info');
    }

    // PHASE 4: Now load the WPMU DEV Dashboard plugin (API key constant is already set)
    // First check if the plugin is actually activated in WordPress
    $dashboard_plugin = 'wpmudev-updates/update-notifications.php';
    $dashboard_path = WP_PLUGIN_DIR . '/' . $dashboard_plugin;
    
    if (!clean_sweep_is_plugin_active($dashboard_plugin)) {
        clean_sweep_log_message("❌ WPMU DEV Dashboard plugin is not activated (site or network)", 'warning');
        return false;
    }
    if (clean_sweep_is_plugin_network_active($dashboard_plugin)) {
        clean_sweep_log_message("✅ WPMU DEV Dashboard is network-activated", 'info');
    }
    
    if (!class_exists('WPMUDEV_Dashboard')) {
        if (file_exists($dashboard_path)) {
            clean_sweep_log_message("🔄 Auto-loading WPMU DEV Dashboard plugin (with API key pre-defined)...", 'info');
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

    // PHASE 5: Verify authentication status
    if (!isset(WPMUDEV_Dashboard::$api)) {
        clean_sweep_log_message("❌ WPMUDEV_Dashboard::\$api not available", 'error');
        return false;
    }

    $has_key = WPMUDEV_Dashboard::$api->has_key();
    clean_sweep_log_message("🔑 WPMUDEV_Dashboard::\$api->has_key(): " . ($has_key ? 'TRUE' : 'FALSE'), 'info');

    // If authentication still fails, try fallback methods
    if (!$has_key) {
        // Try network-level authentication if available (multisite)
        if ($target_is_multisite && isset(WPMUDEV_Dashboard::$network) && method_exists(WPMUDEV_Dashboard::$network, 'has_key')) {
            $network_has_key = WPMUDEV_Dashboard::$network->has_key();
            if ($network_has_key) {
                $has_key = true;
                clean_sweep_log_message("✅ Using network-level authentication", 'info');
            }
        }

        // Try to re-initialize Dashboard instance
        if (!$has_key && method_exists('WPMUDEV_Dashboard', 'instance')) {
            try {
                $dashboard_instance = WPMUDEV_Dashboard::instance();
                if ($dashboard_instance && isset(WPMUDEV_Dashboard::$api)) {
                    $has_key_retry = WPMUDEV_Dashboard::$api->has_key();
                    if ($has_key_retry) {
                        $has_key = true;
                        clean_sweep_log_message("✅ Authentication successful on retry", 'info');
                    }
                }
            } catch (Exception $e) {
                clean_sweep_log_message("❌ Error during auth retry: " . $e->getMessage(), 'warning');
            }
        }
    }

    // Verify authentication by checking projects availability
    if ($has_key) {
        try {
            if (isset(WPMUDEV_Dashboard::$site)) {
                WPMUDEV_Dashboard::$site->refresh_local_projects('local');
                $projects = WPMUDEV_Dashboard::$site->get_cached_projects();
                $project_count = is_array($projects) ? count($projects) : 0;
                clean_sweep_log_message("📦 Available WPMU DEV projects: {$project_count}", 'info');
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

    // Method 1: Check if MULTISITE constant is defined in target config
    if (defined('ORIGINAL_WP_CONTENT_DIR')) {
        $target_config_path = str_replace('/wp-content', '/wp-config.php', ORIGINAL_WP_CONTENT_DIR);
        if (file_exists($target_config_path)) {
            $config_content = file_get_contents($target_config_path);
            if ($config_content !== false && strpos($config_content, "define('MULTISITE', true)") !== false) {
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
                    return $target_multisite_cache = true;
                }
            }
        }
    } catch (Exception $e) {
        // Silently handle errors
    }

    // Default to single site if we can't determine
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
    // Check if target site is multisite (recovery environment might be single-site)
    $target_is_multisite = clean_sweep_detect_target_multisite();
    
    // For multisite targets, we need a super admin even if recovery environment is single-site
    if ($target_is_multisite || is_multisite()) {
        // Try get_super_admins() first (works in actual multisite environments)
        if (function_exists('get_super_admins')) {
            $super_admins = get_super_admins();
            if (!empty($super_admins)) {
                $user = get_user_by('login', $super_admins[0]);
                if ($user) {
                    return $user;
                }
            }
        }
        
        // Fallback: Check database for super admins (for recovery environment with multisite target)
        try {
            global $wpdb;
            if ($wpdb) {
                // In multisite, super admins are stored in wp_sitemeta with meta_key 'site_admins'
                $site_admins = $wpdb->get_var("SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = 'site_admins' LIMIT 1");
                if ($site_admins) {
                    $site_admins = maybe_unserialize($site_admins);
                    if (is_array($site_admins) && !empty($site_admins)) {
                        $user = get_user_by('login', $site_admins[0]);
                        if ($user) {
                            return $user;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Silently handle errors
        }
    }
    
    // Fallback to regular administrator (for single site or if super admin not found)
    
    $admins = get_users([
        'role'    => 'administrator',
        'orderby' => 'ID',
        'order'   => 'ASC',
        'number'  => 1,
        'fields'  => ['ID'],
    ]);

    if (!empty($admins)) {
        $user = get_user_by('id', $admins[0]->ID);
        if ($user) {
            return $user;
        }
    }

    return null;
}
