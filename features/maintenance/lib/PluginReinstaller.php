<?php
/**
 * Clean Sweep - Plugin Reinstaller
 *
 * Handles the actual plugin reinstallation process including WordPress.org
 * and WPMU DEV plugins, plus cleanup of suspicious files.
 */

class CleanSweep_PluginReinstaller {

    /**
     * Start the plugin reinstallation process
     *
     * @param string|null $progress_file Progress file for AJAX updates
     * @param bool $create_backup Whether to create backup
     * @param bool $proceed_without_backup Whether to proceed without backup
     * @param array $suspicious_files_to_delete Files/folders to delete
     * @return array Reinstallation results
     */
    public function start_reinstallation($progress_file = null, $create_backup = false, $proceed_without_backup = false, $suspicious_files_to_delete = []) {
        clean_sweep_log_message("PluginReinstaller: Starting reinstallation process", 'info');
        clean_sweep_log_message("PluginReinstaller: Backup requested: " . ($create_backup ? 'YES' : 'NO') . ", Skip backup: " . ($proceed_without_backup ? 'YES' : 'NO'), 'info');
        clean_sweep_log_message("PluginReinstaller: Suspicious files to delete: " . count($suspicious_files_to_delete), 'info');

        try {
            // Initialize results
            $results = [
                'success' => false,
                'wordpress_org' => ['successful' => [], 'failed' => []],
                'wpmu_dev' => ['successful' => [], 'failed' => []],
                'suspicious_cleanup' => ['deleted' => [], 'failed' => []],
                'backup_created' => false,
                'error' => null
            ];

            // Handle backup creation if requested
            if ($create_backup) {
                clean_sweep_log_message("PluginReinstaller: Creating backup before reinstallation", 'info');

                $backup_result = clean_sweep_create_backup();
                if (!$backup_result) {
                    clean_sweep_log_message("PluginReinstaller: Backup creation failed, aborting reinstallation", 'error');
                    return [
                        'success' => false,
                        'error' => 'Backup creation failed',
                        'backup_created' => false
                    ];
                }

                clean_sweep_log_message("PluginReinstaller: Backup created successfully", 'info');
                $results['backup_created'] = true;
            } elseif ($proceed_without_backup) {
                clean_sweep_log_message("PluginReinstaller: Proceeding without backup as requested", 'warning');
            }

            // Get plugins from transient (set during analysis)
            $wpmu_dev_plugins = get_transient('clean_sweep_wpmudev_plugins');
            if ($wpmu_dev_plugins === false) {
                $wpmu_dev_plugins = [];
                clean_sweep_log_message("PluginReinstaller: No WPMU DEV plugins found in transient", 'warning');
            }

            // Get WordPress.org plugins from analysis results (we'll need to pass this in future)
            // For now, get from current installation
            $all_plugins = get_plugins();
            $wp_org_plugins = $this->filter_wordpress_org_plugins($all_plugins, $wpmu_dev_plugins);

            clean_sweep_log_message("PluginReinstaller: Processing " . count($wp_org_plugins) . " WordPress.org plugins and " . count($wpmu_dev_plugins) . " WPMU DEV plugins", 'info');

            // Process WordPress.org plugins
            if (!empty($wp_org_plugins)) {
                clean_sweep_log_message("PluginReinstaller: Starting WordPress.org plugin reinstallation", 'info');
                $wp_results = $this->reinstall_wordpress_org_plugins($wp_org_plugins, $progress_file);
                $results['wordpress_org'] = $wp_results;
                clean_sweep_log_message("PluginReinstaller: WordPress.org plugins - Success: " . count($wp_results['successful']) . ", Failed: " . count($wp_results['failed']), 'info');
            }

            // Process WPMU DEV plugins
            if (!empty($wpmu_dev_plugins)) {
                clean_sweep_log_message("PluginReinstaller: Starting WPMU DEV plugin reinstallation", 'info');
                $wpmu_results = $this->reinstall_wpmu_dev_plugins($wpmu_dev_plugins, $progress_file);
                $results['wpmu_dev'] = $wpmu_results;
                clean_sweep_log_message("PluginReinstaller: WPMU DEV plugins - Success: " . count($wpmu_results['successful']) . ", Failed: " . count($wpmu_results['failed']), 'info');
            }

            // Clean up suspicious files
            if (!empty($suspicious_files_to_delete)) {
                clean_sweep_log_message("PluginReinstaller: Starting suspicious file cleanup", 'info');
                $cleanup_results = $this->cleanup_suspicious_files($suspicious_files_to_delete);
                $results['suspicious_cleanup'] = $cleanup_results;
                clean_sweep_log_message("PluginReinstaller: Suspicious files - Deleted: " . count($cleanup_results['deleted']) . ", Failed: " . count($cleanup_results['failed']), 'info');
            }

            // Determine overall success
            $total_successful = count($results['wordpress_org']['successful']) + count($results['wpmu_dev']['successful']);
            $total_failed = count($results['wordpress_org']['failed']) + count($results['wpmu_dev']['failed']);

            $results['success'] = ($total_failed === 0); // Success if no failures
            $results['summary'] = [
                'wordpress_org_successful' => count($results['wordpress_org']['successful']),
                'wordpress_org_failed' => count($results['wordpress_org']['failed']),
                'wpmu_dev_successful' => count($results['wpmu_dev']['successful']),
                'wpmu_dev_failed' => count($results['wpmu_dev']['failed']),
                'suspicious_deleted' => count($results['suspicious_cleanup']['deleted']),
                'suspicious_failed' => count($results['suspicious_cleanup']['failed'])
            ];

            clean_sweep_log_message("PluginReinstaller: Reinstallation completed - Total successful: $total_successful, Total failed: $total_failed", 'info');

            return $results;

        } catch (Exception $e) {
            clean_sweep_log_message("PluginReinstaller: Exception during reinstallation: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Filter WordPress.org plugins from all plugins
     *
     * @param array $all_plugins All installed plugins
     * @param array $wpmu_dev_plugins Known WPMU DEV plugins
     * @return array WordPress.org plugins
     */
    private function filter_wordpress_org_plugins($all_plugins, $wpmu_dev_plugins) {
        $wp_org_plugins = [];

        // Get WPMU DEV plugin files for exclusion
        $wpmu_dev_files = array_keys($wpmu_dev_plugins);

        foreach ($all_plugins as $plugin_file => $plugin_data) {
            // Skip if it's a WPMU DEV plugin
            if (in_array($plugin_file, $wpmu_dev_files)) {
                continue;
            }

            // Check if it's a WordPress.org plugin
            $slug = $this->extract_plugin_slug($plugin_file);
            $wp_org_info = clean_sweep_fetch_plugin_info($slug);

            if (!empty($wp_org_info) && isset($wp_org_info['version'])) {
                $wp_org_plugins[$plugin_file] = [
                    'name' => $plugin_data['Name'],
                    'version' => $plugin_data['Version'],
                    'slug' => $slug,
                    'last_updated' => $wp_org_info['last_updated'] ?? null,
                    'plugin_url' => $wp_org_info['homepage'] ?? "https://wordpress.org/plugins/{$slug}/",
                ];
            }
        }

        return $wp_org_plugins;
    }

    /**
     * Extract plugin slug from plugin file path
     *
     * @param string $plugin_file
     * @return string
     */
    private function extract_plugin_slug($plugin_file) {
        $plugin_dir = dirname($plugin_file);
        if ($plugin_dir === '.' || $plugin_dir === '') {
            return pathinfo($plugin_file, PATHINFO_FILENAME);
        } else {
            return basename($plugin_dir);
        }
    }

    /**
     * Reinstall WordPress.org plugins
     *
     * @param array $wp_org_plugins Plugins to reinstall
     * @param string|null $progress_file Progress file
     * @return array Results
     */
    private function reinstall_wordpress_org_plugins($wp_org_plugins, $progress_file = null) {
        $results = ['successful' => [], 'failed' => []];

        $total_plugins = count($wp_org_plugins);
        $current_count = 0;

        foreach ($wp_org_plugins as $plugin_file => $plugin_data) {
            $current_count++;
            $slug = $plugin_data['slug'];
            $plugin_name = $plugin_data['name'];

            // Update progress
            if ($progress_file) {
                $progress_data = [
                    'status' => 'reinstalling',
                    'progress' => round(($current_count / $total_plugins) * 100),
                    'message' => "Re-installing WordPress.org plugin $current_count of $total_plugins: $plugin_name",
                    'current' => $current_count,
                    'total' => $total_plugins
                ];
                @clean_sweep_write_progress_file($progress_file, $progress_data);
            }

            if (clean_sweep_reinstall_plugin($slug)) {
                $results['successful'][] = [
                    'name' => $plugin_name,
                    'slug' => $slug,
                    'status' => 'Re-installed successfully'
                ];
                clean_sweep_log_message("PluginReinstaller: Successfully reinstalled WordPress.org plugin: $plugin_name", 'info');
            } else {
                $results['failed'][] = [
                    'name' => $plugin_name,
                    'slug' => $slug,
                    'status' => 'Re-installation failed'
                ];
                clean_sweep_log_message("PluginReinstaller: Failed to reinstall WordPress.org plugin: $plugin_name", 'error');
            }

            // Small delay to be respectful to the API
            sleep(1);
        }

        return $results;
    }

    /**
     * Reinstall WPMU DEV plugins
     *
     * @param array $wpmu_dev_plugins Plugins to reinstall
     * @param string|null $progress_file Progress file
     * @return array Results
     */
    private function reinstall_wpmu_dev_plugins($wpmu_dev_plugins, $progress_file = null) {
        $results = ['successful' => [], 'failed' => []];

        // Use existing WPMU DEV reinstallation logic
        $wpmu_results = clean_sweep_reinstall_wpmudev_plugins($progress_file, $wpmu_dev_plugins);

        if (!isset($wpmu_results['error'])) {
            // Process successful WPMU DEV installations
            if (isset($wpmu_results['wpmudev_plugins']) && is_array($wpmu_results['wpmudev_plugins'])) {
                foreach ($wpmu_results['wpmudev_plugins'] as $pid_index => $plugin_data) {
                    if (isset($plugin_data['installed']) && $plugin_data['installed'] && empty($plugin_data['error'])) {
                        $results['successful'][] = [
                            'name' => $plugin_data['name'] ?? $plugin_data['filename'] ?? $pid_index,
                            'slug' => $plugin_data['filename'] ?? $plugin_data['name'] ?? $pid_index,
                            'status' => 'Re-installed successfully (WPMU DEV)'
                        ];
                    } elseif (isset($plugin_data['error']) && $plugin_data['error']) {
                        $results['failed'][] = [
                            'name' => $plugin_data['name'] ?? $plugin_data['filename'] ?? $pid_index,
                            'slug' => $plugin_data['filename'] ?? $plugin_data['name'] ?? $pid_index,
                            'status' => 'WPMU DEV re-installation failed: ' . $plugin_data['error']
                        ];
                    }
                }
            }
        } else {
            clean_sweep_log_message("PluginReinstaller: WPMU DEV reinstallation failed: " . $wpmu_results['error'], 'error');
            $results['failed'][] = [
                'name' => 'WPMU DEV Plugins',
                'slug' => 'multiple',
                'status' => 'WPMU DEV reinstallation failed: ' . $wpmu_results['error']
            ];
        }

        return $results;
    }

    /**
     * Clean up suspicious files and folders
     *
     * @param array $suspicious_files_to_delete Files to delete
     * @return array Cleanup results
     */
    private function cleanup_suspicious_files($suspicious_files_to_delete) {
        $results = ['deleted' => [], 'failed' => []];

        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        foreach ($suspicious_files_to_delete as $file_info) {
            $file_path = $file_info['path'];
            $file_name = $file_info['name'];

            clean_sweep_log_message("PluginReinstaller: Attempting to delete suspicious file: $file_path", 'info');

            if ($wp_filesystem->delete($file_path, true)) { // true for recursive deletion
                $results['deleted'][] = [
                    'name' => $file_name,
                    'path' => $file_path,
                    'status' => 'Deleted successfully'
                ];
                clean_sweep_log_message("PluginReinstaller: Successfully deleted suspicious file: $file_name", 'info');
            } else {
                $results['failed'][] = [
                    'name' => $file_name,
                    'path' => $file_path,
                    'status' => 'Deletion failed - may not have permission or file in use'
                ];
                clean_sweep_log_message("PluginReinstaller: Failed to delete suspicious file: $file_name", 'warning');
            }
        }

        return $results;
    }

    /**
     * Verify reinstallation results
     *
     * @param array $results Reinstallation results
     * @return array Verification results
     */
    public function verify_reinstallation($results) {
        clean_sweep_log_message("PluginReinstaller: Verifying reinstallation results", 'info');

        $verification = [
            'wordpress_org_verified' => [],
            'wpmu_dev_verified' => [],
            'wordpress_org_missing' => [],
            'wpmu_dev_missing' => []
        ];

        // Verify WordPress.org plugins
        foreach ($results['wordpress_org']['successful'] as $plugin) {
            if ($this->verify_plugin_installation($plugin['slug'])) {
                $verification['wordpress_org_verified'][] = $plugin;
            } else {
                $verification['wordpress_org_missing'][] = $plugin;
            }
        }

        // Verify WPMU DEV plugins
        foreach ($results['wpmu_dev']['successful'] as $plugin) {
            if ($this->verify_plugin_installation($plugin['slug'])) {
                $verification['wpmu_dev_verified'][] = $plugin;
            } else {
                $verification['wpmu_dev_missing'][] = $plugin;
            }
        }

        clean_sweep_log_message("PluginReinstaller: Verification completed - WordPress.org verified: " . count($verification['wordpress_org_verified']) . ", WPMU DEV verified: " . count($verification['wpmu_dev_verified']), 'info');

        return $verification;
    }

    /**
     * Verify if a plugin is properly installed
     *
     * @param string $plugin_slug Plugin slug or filename
     * @return bool Whether plugin is verified
     */
    private function verify_plugin_installation($plugin_slug) {
        $all_plugins = get_plugins();

        // Check if plugin exists
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $current_slug = $this->extract_plugin_slug($plugin_file);
            if ($current_slug === $plugin_slug || $plugin_file === $plugin_slug) {
                // Check if main file exists
                $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
                return file_exists($plugin_path) && is_readable($plugin_path);
            }
        }

        return false;
    }
}
