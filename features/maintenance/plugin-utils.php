<?php
/**
 * Clean Sweep - Plugin Utility Functions
 *
 * Shared utility functions for plugin operations
 */

if (!defined('ORIGINAL_WP_PLUGIN_DIR')) {
    if (defined('ORIGINAL_WP_CONTENT_DIR') && is_dir(ORIGINAL_WP_CONTENT_DIR . '/plugins')) {
        define('ORIGINAL_WP_PLUGIN_DIR', rtrim(str_replace('\\', '/', ORIGINAL_WP_CONTENT_DIR), '/') . '/plugins/');
    } elseif (defined('WP_PLUGIN_DIR') && is_dir(WP_PLUGIN_DIR)
        && strpos(str_replace('\\', '/', WP_PLUGIN_DIR), '/core/fresh/') === false) {
        define('ORIGINAL_WP_PLUGIN_DIR', rtrim(str_replace('\\', '/', WP_PLUGIN_DIR), '/') . '/');
    } elseif (defined('WP_CONTENT_DIR') && is_dir(WP_CONTENT_DIR . '/plugins')) {
        define('ORIGINAL_WP_PLUGIN_DIR', rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/plugins/');
    }
}

/**
 * Format timestamp as relative time (e.g., "2 days ago", "3 months ago")
 * Properly handles UTC timestamps from WordPress.org API
 */
function clean_sweep_format_relative_time($timestamp) {
    if (!$timestamp) {
        return 'Unknown';
    }

    $now = new DateTime('now', new DateTimeZone('UTC'));
    $plugin_time = new DateTime($timestamp, new DateTimeZone('UTC'));
    $diff = $now->getTimestamp() - $plugin_time->getTimestamp();

    if ($diff < 0) {
        return 'Future';
    }

    $intervals = [
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute'
    ];

    foreach ($intervals as $seconds => $unit) {
        $count = floor($diff / $seconds);
        if ($count > 0) {
            $plural = $count > 1 ? 's' : '';
            return $count . ' ' . $unit . $plural . ' ago';
        }
    }

    return 'Just now';
}

/**
 * Fetch additional plugin information from WordPress.org API
 */
function clean_sweep_fetch_plugin_info($slug) {
    $slug = sanitize_key((string)$slug);
    if ($slug === '') {
        return [];
    }

    // Cache WordPress.org lookups — analyze_plugins used to hit the network
    // once per plugin on every run (slow log: curl_exec in bootstrap → plugins).
    $cache_key = 'cs_wporg_plugin_v2_' . $slug;
    if (function_exists('get_transient')) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    // Suppress all errors and warnings during API call
    $error_reporting = error_reporting(0);

    try {
        $api_url = "https://api.wordpress.org/plugins/info/1.0/{$slug}.json";
        $response = @wp_remote_get($api_url, [
            'timeout' => 3,
            'redirection' => 2,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Negative-cache brief miss so one slow plugin doesn't retry every request
            if (function_exists('set_transient')) {
                set_transient($cache_key, [], 15 * MINUTE_IN_SECONDS);
            }
            return [];
        }

        $data = @json_decode(wp_remote_retrieve_body($response), true);
        if (!$data) {
            return [];
        }

        $out = [
            'last_updated' => $data['last_updated'] ?? null,
            'homepage' => $data['homepage'] ?? null,
            'version' => $data['version'] ?? null,
            'name' => $data['name'] ?? null,
            'author' => isset($data['author']) ? trim(strip_tags((string) $data['author'])) : null,
            'slug' => $data['slug'] ?? $slug,
            'requires' => $data['requires'] ?? null,
            'tested' => $data['tested'] ?? null,
            'rating' => $data['rating'] ?? null,
            'num_ratings' => $data['num_ratings'] ?? null
        ];

        if (function_exists('set_transient')) {
            set_transient($cache_key, $out, 12 * HOUR_IN_SECONDS);
        }

        return $out;
    } catch (Exception $e) {
        return [];
    } finally {
        // Restore error reporting
        error_reporting($error_reporting);
    }
}

/**
 * Fetch theme information from WordPress.org API (cached).
 *
 * @param string $slug
 * @return array
 */
function clean_sweep_fetch_theme_info($slug) {
    $slug = sanitize_key((string) $slug);
    if ($slug === '') {
        return [];
    }

    $cache_key = 'cs_wporg_theme_v2_' . $slug;
    if (function_exists('get_transient')) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $error_reporting = error_reporting(0);

    try {
        $api_url = 'https://api.wordpress.org/themes/info/1.1/?action=theme_information&request[slug]=' . rawurlencode($slug);
        $response = @wp_remote_get($api_url, [
            'timeout' => 3,
            'redirection' => 2,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            if (function_exists('set_transient')) {
                set_transient($cache_key, [], 15 * MINUTE_IN_SECONDS);
            }
            return [];
        }

        $data = @json_decode(wp_remote_retrieve_body($response), true);
        if (!$data || !empty($data['error']) || empty($data['version'])) {
            if (function_exists('set_transient')) {
                set_transient($cache_key, [], 15 * MINUTE_IN_SECONDS);
            }
            return [];
        }

        $out = [
            'last_updated' => $data['last_updated'] ?? null,
            'homepage' => $data['homepage'] ?? null,
            'version' => $data['version'] ?? null,
            'requires' => $data['requires'] ?? null,
            'screenshot_url' => $data['screenshot_url'] ?? null,
            'name' => $data['name'] ?? null,
            'author' => isset($data['author']) ? trim(strip_tags((string) $data['author'])) : null,
            'slug' => $data['slug'] ?? $slug,
        ];

        if (function_exists('set_transient')) {
            set_transient($cache_key, $out, 12 * HOUR_IN_SECONDS);
        }

        return $out;
    } catch (Exception $e) {
        return [];
    } finally {
        error_reporting($error_reporting);
    }
}

/**
 * Check if a plugin is managed by WPMU DEV dashboard
 * Uses the same detection method as the Dashboard: checks for WDP ID header
 */
function clean_sweep_is_wpmudev_plugin($plugin_path) {
    // First, check if the plugin file has the WDP ID header (most reliable method)
    if (file_exists($plugin_path)) {
        $plugin_data = get_file_data(
            $plugin_path,
            array(
                'name'    => 'Plugin Name',
                'id'      => 'WDP ID',
                'version' => 'Version',
            )
        );

        if (!empty($plugin_data['id']) && is_numeric($plugin_data['id'])) {
            clean_sweep_log_message("DEBUG WPMU DEV: Found WDP ID header: {$plugin_data['id']} in {$plugin_path}", 'info');
            return true;
        }
    }

    // Fallback: Check if WPMU DEV Dashboard class exists
    if (!class_exists('WPMUDEV_Dashboard')) {
        return false;
    }

    // Get plugin basename for comparison
    $plugin_basename = plugin_basename($plugin_path);
    $plugin_dir = dirname($plugin_basename);
    if ($plugin_dir === '.') {
        // Single-file plugin
        $plugin_dir = pathinfo(basename($plugin_path), PATHINFO_FILENAME);
    } else {
        $plugin_dir = basename($plugin_dir);
    }

    clean_sweep_log_message("DEBUG WPMU DEV: Checking plugin_path={$plugin_path}, plugin_basename={$plugin_basename}, plugin_dir={$plugin_dir}", 'info');

    // Get WPMU DEV dashboard instance
    $dashboard = WPMUDEV_Dashboard::instance();
    if (!$dashboard) {
        return false;
    }

    $site = WPMUDEV_Dashboard::$site;
    if (!$site) {
        return false;
    }

    try {
        // Always refresh the cache before checking (ensures fresh data after reinstall)
        $site->refresh_local_projects('local');

        // Get all projects
        $projects = $site->get_cached_projects();
        clean_sweep_log_message("DEBUG WPMU DEV: Found " . count($projects) . " WPMU DEV projects after refresh", 'info');

        // Check if the plugin filename matches any project
        foreach ((array) $projects as $project_id => $project) {
            if (empty($project['filename']) || ($project['type'] ?? '') !== 'plugin') {
                continue;
            }

            $project_filename = $project['filename'];
            $project_dir = dirname($project_filename);
            if ($project_dir === '.') {
                $project_dir = pathinfo($project_filename, PATHINFO_FILENAME);
            } else {
                $project_dir = basename($project_dir);
            }

            // Compare both the full filename and the directory name
            if ($project_filename === $plugin_basename || $project_dir === $plugin_dir) {
                clean_sweep_log_message("DEBUG WPMU DEV: MATCH FOUND - Project ID {$project_id}, filename: {$project_filename}", 'info');
                return true;
            }
        }
    } catch (Exception $e) {
        clean_sweep_log_message("DEBUG WPMU DEV: Exception during check: " . $e->getMessage(), 'error');
        return false;
    }

    clean_sweep_log_message("DEBUG WPMU DEV: No match found for {$plugin_basename}", 'info');
    return false;
}
