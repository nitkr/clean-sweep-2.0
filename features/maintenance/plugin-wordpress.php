<?php
/**
 * Clean Sweep - WordPress.org Plugin Processing
 *
 * Functions for handling WordPress.org repository plugins
 */

/**
 * Download and install plugin from WordPress.org
 */
function clean_sweep_reinstall_plugin($plugin_slug) {
    // Include WordPress admin plugin functions for get_plugins() (after WordPress bootstrap)
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    // CRITICAL: Double-check this isn't a WPMU DEV plugin before proceeding
    $plugin_file = $plugin_slug . '/' . $plugin_slug . '.php';
    // Try alternate common patterns
    if (!file_exists(ORIGINAL_WP_PLUGIN_DIR . '/' . $plugin_file)) {
        // Try to find the actual plugin file
        $plugins = get_plugins();
        foreach ($plugins as $file => $data) {
            if (dirname($file) === $plugin_slug || (dirname($file) === '.' && pathinfo($file, PATHINFO_FILENAME) === $plugin_slug)) {
                $plugin_file = $file;
                break;
            }
        }
    }

    $plugin_path = ORIGINAL_WP_PLUGIN_DIR . '/' . $plugin_file;

    // Check WDP ID header - if present, skip WordPress.org reinstall
    if (file_exists($plugin_path)) {
        $file_data = get_file_data(
            $plugin_path,
            array('id' => 'WDP ID')
        );
        if (!empty($file_data['id']) && is_numeric($file_data['id'])) {
            clean_sweep_log_message("Skipping WordPress.org reinstall for {$plugin_slug} - detected as WPMU DEV plugin (WDP ID: {$file_data['id']})", 'warning');
            return new WP_Error('wpmudev_plugin', 'This is a WPMU DEV plugin, not a WordPress.org plugin');
        }
    }

    clean_sweep_log_message("Re-installing plugin: $plugin_slug");

    // Get plugin info from WordPress.org API
    $api_url = "https://api.wordpress.org/plugins/info/1.0/$plugin_slug.json";
    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        clean_sweep_log_message("Failed to fetch plugin info for $plugin_slug: " . $response->get_error_message(), 'error');
        return false;
    }

    if (wp_remote_retrieve_response_code($response) !== 200) {
        clean_sweep_log_message("Plugin $plugin_slug not found in WordPress.org repository", 'warning');
        return false;
    }

    $plugin_data = json_decode(wp_remote_retrieve_body($response), true);
    if (!$plugin_data || !isset($plugin_data['download_link'])) {
        clean_sweep_log_message("Invalid plugin data received for $plugin_slug", 'error');
        return false;
    }

    $download_url = $plugin_data['download_link'];
    clean_sweep_log_message("Download URL: $download_url");

    // Download the plugin using our secure standalone function
    $temp_file = clean_sweep_download_url($download_url);
    if (is_wp_error($temp_file)) {
        clean_sweep_log_message("Failed to download plugin $plugin_slug: " . $temp_file->get_error_message(), 'error');
        return false;
    }

    // Remove existing plugin directory
    $plugin_dir = ORIGINAL_WP_PLUGIN_DIR . '/' . $plugin_slug;
    if (is_dir($plugin_dir)) {
        clean_sweep_log_message("Removing existing plugin directory: $plugin_dir");

        // Use WP_Filesystem for better compatibility
        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if (!$wp_filesystem->rmdir($plugin_dir, true)) {
            clean_sweep_log_message("Failed to remove existing plugin directory", 'error');
            unlink($temp_file);
            return false;
        }
    }

    // Extract the downloaded zip using our secure standalone function
    $result = clean_sweep_unzip_file($temp_file, ORIGINAL_WP_PLUGIN_DIR);
    if (is_wp_error($result)) {
        clean_sweep_log_message("Failed to extract plugin $plugin_slug: " . $result->get_error_message(), 'error');
        unlink($temp_file);
        return false;
    }

    // Clean up temp file
    unlink($temp_file);

    clean_sweep_log_message("Successfully re-installed plugin: $plugin_slug", 'success');
    return true;
}
