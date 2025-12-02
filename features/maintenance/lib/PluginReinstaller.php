<?php
/**
 * Clean Sweep - Plugin Reinstaller
 *
 * Handles the actual plugin reinstallation process including WordPress.org
 * and WPMU DEV plugins, plus cleanup of suspicious files.
 */

class CleanSweep_PluginReinstaller {

    /**
     * Start the plugin reinstallation process with unified progress tracking and batching support
     *
     * @param string|null $progress_file Progress file for AJAX updates
     * @param bool $create_backup Whether to create backup
     * @param bool $proceed_without_backup Whether to proceed without backup
     * @param array $wp_org_plugins WordPress.org plugins to reinstall
     * @param array $wpmu_dev_plugins WPMU DEV plugins to reinstall
     * @param array $suspicious_files_to_delete Files/folders to delete
     * @param int $batch_start Starting index for batch processing (0-based)
     * @param int|null $batch_size Number of items per batch (null = process all)
     * @return array Reinstallation results
     */
    public function start_reinstallation($progress_file = null, $create_backup = false, $proceed_without_backup = false, $wp_org_plugins = [], $wpmu_dev_plugins = [], $suspicious_files_to_delete = [], $batch_start = 0, $batch_size = null) {
        clean_sweep_log_message("PluginReinstaller: Starting reinstallation process with unified progress tracking", 'info');
        clean_sweep_log_message("PluginReinstaller: Backup requested: " . ($create_backup ? 'YES' : 'NO') . ", Skip backup: " . ($proceed_without_backup ? 'YES' : 'NO'), 'info');
        clean_sweep_log_message("PluginReinstaller: Suspicious files to delete: " . count($suspicious_files_to_delete), 'info');

        // Initialize progress manager for unified tracking
        $progressManager = $progress_file ? new CleanSweep_ProgressManager($progress_file) : null;

        // Handle batching logic
        $is_batch_mode = ($batch_size !== null && $batch_size > 0);
        clean_sweep_log_message("PluginReinstaller: Batch mode: " . ($is_batch_mode ? 'YES' : 'NO') . ", Start: $batch_start, Size: " . ($batch_size ?? 'all'), 'info');

        try {
            // Initialize results
            $results = [
                'success' => false,
                'wordpress_org' => ['successful' => [], 'failed' => []],
                'wpmu_dev' => ['successful' => [], 'failed' => []],
                'suspicious_cleanup' => ['deleted' => [], 'failed' => []],
                'backup_created' => false,
                'error' => null,
                'batch_info' => [
                    'is_batch_mode' => $is_batch_mode,
                    'batch_start' => $batch_start,
                    'batch_size' => $batch_size,
                    'has_more_batches' => false
                ]
            ];

            // Phase 1: Handle backup creation (0-10%)
            if ($create_backup) {
                clean_sweep_log_message("PluginReinstaller: Creating backup before reinstallation", 'info');

                if ($progressManager) {
                    $progressManager->updateProgress([
                        'status' => 'processing',
                        'progress' => 5,
                        'message' => "Creating backup before plugin reinstallation...",
                        'details' => "Backup in progress - please wait..."
                    ]);
                }

                $backup_result = clean_sweep_create_backup($progress_file);
                if (!$backup_result) {
                    clean_sweep_log_message("PluginReinstaller: Backup creation failed, aborting reinstallation", 'error');
                    if ($progressManager) {
                        $progressManager->sendError('Backup creation failed');
                    }
                    return [
                        'success' => false,
                        'error' => 'Backup creation failed',
                        'backup_created' => false
                    ];
                }

                clean_sweep_log_message("PluginReinstaller: Backup created successfully", 'info');
                $results['backup_created'] = true;

                if ($progressManager) {
                    $progressManager->updateProgress([
                        'status' => 'processing',
                        'progress' => 10,
                        'message' => "Backup completed, starting plugin reinstallation...",
                        'details' => "Backup saved successfully - now reinstalling plugins"
                    ]);
                }
            } elseif ($proceed_without_backup) {
                clean_sweep_log_message("PluginReinstaller: Proceeding without backup as requested", 'warning');
                if ($progressManager) {
                    $progressManager->updateProgress([
                        'status' => 'processing',
                        'progress' => 10,
                        'message' => "Starting plugin reinstallation...",
                        'details' => "No backup requested - proceeding with reinstallation"
                    ]);
                }
            } else {
                // No backup phase, start at 0%
                if ($progressManager) {
                    $progressManager->updateProgress([
                        'status' => 'processing',
                        'progress' => 0,
                        'message' => "Starting plugin reinstallation...",
                        'details' => "Preparing to reinstall plugins"
                    ]);
                }
            }

            // Load analysis data from transient if parameters are empty (for batch processing)
            if ($progress_file && (empty($wp_org_plugins) || empty($wpmu_dev_plugins) || empty($suspicious_files_to_delete))) {
                $analysis_key = 'clean_sweep_analysis_' . md5($progress_file);
                $analysis = get_site_transient($analysis_key);
                if ($analysis && is_array($analysis)) {
                    $wp_org_plugins = $analysis['wp_org_plugins'] ?? [];
                    $wpmu_dev_plugins = $analysis['wpmu_dev_plugins'] ?? [];
                    $suspicious_files_to_delete = $analysis['suspicious_files'] ?? [];
                    clean_sweep_log_message("PluginReinstaller: Loaded analysis from transient for batch processing", 'info');
                }
            }

            // Ensure all parameters are arrays (fix for null values from AJAX)
            $wp_org_plugins = is_array($wp_org_plugins) ? $wp_org_plugins : [];
            $wpmu_dev_plugins = is_array($wpmu_dev_plugins) ? $wpmu_dev_plugins : [];
            $suspicious_files_to_delete = is_array($suspicious_files_to_delete) ? $suspicious_files_to_delete : [];

            // Unified batch processing for all plugin types
            if ($is_batch_mode) {
                // Create unified queue: suspicious files + WordPress.org + WPMU DEV
                $unified_queue = [];

                // Add suspicious files
                foreach ($suspicious_files_to_delete as $file_key => $file_data) {
                    $unified_queue[] = [
                        'type' => 'suspicious_file',
                        'key' => $file_key,
                        'data' => $file_data
                    ];
                }

                // Add WordPress.org plugins
                foreach ($wp_org_plugins as $plugin_key => $plugin_data) {
                    $unified_queue[] = [
                        'type' => 'wordpress_org',
                        'key' => $plugin_key,
                        'data' => $plugin_data
                    ];
                }

                // Add WPMU DEV plugins
                foreach ($wpmu_dev_plugins as $plugin_key => $plugin_data) {
                    $unified_queue[] = [
                        'type' => 'wpmu_dev',
                        'key' => $plugin_key,
                        'data' => [$plugin_key => $plugin_data] // Wrap in assoc array with filename as key
                    ];
                }

                $total_items = count($unified_queue);

                // Slice the unified batch
                $batch_items = array_slice($unified_queue, $batch_start, $batch_size, true);
                $results['batch_info']['has_more_batches'] = ($batch_start + $batch_size) < $total_items;

                clean_sweep_log_message("PluginReinstaller: Unified batch processing - Total: {$total_items}, Batch: " . count($batch_items) . " items, More batches: " . ($results['batch_info']['has_more_batches'] ? 'YES' : 'NO'), 'info');

                // Process batch items
                $processed = 0;
                foreach ($batch_items as $item) {
                    $processed++;
                    $progress_percent = 10 + round(($processed / count($batch_items)) * 85); // 10-95% range

                    // Extract item name for progress display
                    $item_name = 'Unknown item';
                    switch ($item['type']) {
                        case 'suspicious_file':
                            $item_name = $item['data']['name'] ?? 'Suspicious file';
                            break;
                        case 'wordpress_org':
                            $item_name = $item['data']['name'] ?? 'WordPress plugin';
                            break;
                        case 'wpmu_dev':
                            $plugin_data = current($item['data']);
                            $item_name = $plugin_data['name'] ?? 'WPMU DEV plugin';
                            break;
                    }

                    if ($progressManager) {
                        $progressManager->updateProgress([
                            'status' => 'processing',
                            'progress' => $progress_percent,
                            'message' => "Installing: {$item_name}",
                            'details' => "Processing {$processed} of " . count($batch_items)
                        ]);
                    }

                    try {
                        switch ($item['type']) {
                            case 'suspicious_file':
                                $this->process_suspicious_file_item($item['data'], $results);
                                break;

                            case 'wordpress_org':
                                $this->process_wordpress_org_item($item['data'], $results);
                                break;

                            case 'wpmu_dev':
                                $this->process_wpmu_dev_item($item['data'], $results);
                                break;
                        }
                    } catch (Exception $e) {
                        clean_sweep_log_message("PluginReinstaller: Exception processing {$item['type']} item: " . $e->getMessage(), 'error');
                    }
                }
            } else {
                // Non-batch mode: process all items
                // Clean up suspicious files (10-30%)
                if (!empty($suspicious_files_to_delete)) {
                    clean_sweep_log_message("PluginReinstaller: Starting suspicious file cleanup", 'info');
                    $cleanup_results = $this->cleanup_suspicious_files_with_progress($suspicious_files_to_delete, $progressManager, 10, 30);
                    $results['suspicious_cleanup'] = $cleanup_results;
                    clean_sweep_log_message("PluginReinstaller: Suspicious files - Deleted: " . count($cleanup_results['deleted']) . ", Failed: " . count($cleanup_results['failed']), 'info');
                }

                // Process WordPress.org plugins (30-70%)
                if (!empty($wp_org_plugins)) {
                    clean_sweep_log_message("PluginReinstaller: Starting WordPress.org plugin reinstallation", 'info');
                    $wp_results = $this->reinstall_wordpress_org_plugins_with_progress($wp_org_plugins, $progressManager, 30, 70);
                    $results['wordpress_org'] = $wp_results;
                    clean_sweep_log_message("PluginReinstaller: WordPress.org plugins - Success: " . count($wp_results['successful']) . ", Failed: " . count($wp_results['failed']), 'info');
                }

                // Process WPMU DEV plugins (70-95%)
                if (!empty($wpmu_dev_plugins)) {
                    clean_sweep_log_message("PluginReinstaller: Starting WPMU DEV plugin reinstallation", 'info');
                    $wpmu_results = $this->reinstall_wpmu_dev_plugins_with_progress($wpmu_dev_plugins, $progressManager, 70, 95);
                    $results['wpmu_dev'] = $wpmu_results;
                    clean_sweep_log_message("PluginReinstaller: WPMU DEV plugins - Success: " . count($wpmu_results['successful']) . ", Failed: " . count($wpmu_results['failed']), 'info');
                }
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

            // Phase 5: Send completion only for final batch (100%)
            if ($progressManager && !$results['batch_info']['has_more_batches']) {
                $progressManager->sendCompletion($results);
            } elseif ($progressManager && $results['batch_info']['has_more_batches']) {
                // For intermediate batches, update progress WITH batch_info so JS can trigger next batch
                $progressManager->updateProgress([
                    'status' => 'batch_complete',
                    'progress' => 100,
                    'message' => 'Batch completed, processing next batch...',
                    'details' => "Completed batch with $total_successful successful, $total_failed failed operations",
                    'batch_info' => $results['batch_info'],
                    'results' => $results
                ]);
                clean_sweep_log_message("PluginReinstaller: Intermediate batch completed, awaiting next batch", 'info');
            }

            return $results;

        } catch (Exception $e) {
            clean_sweep_log_message("PluginReinstaller: Exception during reinstallation: " . $e->getMessage(), 'error');
            if ($progressManager) {
                $progressManager->sendError('Exception: ' . $e->getMessage());
            }
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process a suspicious file item in unified batch
     *
     * @param array $file_data File data
     * @param array &$results Results array (passed by reference)
     */
    private function process_suspicious_file_item($file_data, &$results) {
        $file_path = $file_data['path'];
        $file_name = $file_data['name'];

        clean_sweep_log_message("PluginReinstaller: Processing suspicious file: $file_path", 'info');
        clean_sweep_log_message("PluginReinstaller: File exists check: " . (file_exists($file_path) ? 'YES' : 'NO'), 'info');
        clean_sweep_log_message("PluginReinstaller: File readable check: " . (is_readable($file_path) ? 'YES' : 'NO'), 'info');

        // Try PHP unlink first as it's more reliable
        if (file_exists($file_path) && is_readable($file_path)) {
            if (is_dir($file_path)) {
                // For directories, use recursive deletion
                $deleted = $this->delete_directory_recursive($file_path);
            } else {
                // For files, use unlink
                $deleted = @unlink($file_path);
            }

            if ($deleted) {
                $results['suspicious_cleanup']['deleted'][] = [
                    'name' => $file_name,
                    'path' => $file_path,
                    'status' => 'Deleted successfully'
                ];
                clean_sweep_log_message("PluginReinstaller: Successfully deleted suspicious file: $file_name", 'info');
                return;
            }
        }

        // Fallback to WordPress filesystem
        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        clean_sweep_log_message("PluginReinstaller: Trying WordPress filesystem deletion", 'info');
        if ($wp_filesystem->delete($file_path, true)) { // true for recursive deletion
            $results['suspicious_cleanup']['deleted'][] = [
                'name' => $file_name,
                'path' => $file_path,
                'status' => 'Deleted successfully (WP filesystem)'
            ];
            clean_sweep_log_message("PluginReinstaller: Successfully deleted suspicious file with WP filesystem: $file_name", 'info');
        } else {
            $results['suspicious_cleanup']['failed'][] = [
                'name' => $file_name,
                'path' => $file_path,
                'status' => 'Deletion failed - may not have permission or file in use'
            ];
            clean_sweep_log_message("PluginReinstaller: Failed to delete suspicious file: $file_name (path: $file_path)", 'warning');
        }
    }

    /**
     * Recursively delete a directory
     *
     * @param string $directory Directory path
     * @return bool Success
     */
    private function delete_directory_recursive($directory) {
        if (!is_dir($directory)) {
            return false;
        }

        $files = array_diff(scandir($directory), ['.', '..']);
        foreach ($files as $file) {
            $path = $directory . '/' . $file;
            if (is_dir($path)) {
                $this->delete_directory_recursive($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($directory);
    }

    /**
     * Process a WordPress.org plugin item in unified batch
     *
     * @param array $plugin_data Plugin data
     * @param array &$results Results array (passed by reference)
     */
    private function process_wordpress_org_item($plugin_data, &$results) {
        $slug = $plugin_data['slug'];
        $plugin_name = $plugin_data['name'];

        clean_sweep_log_message("PluginReinstaller: Processing WordPress.org plugin: $plugin_name", 'info');

        if (clean_sweep_reinstall_plugin($slug)) {
            $results['wordpress_org']['successful'][] = [
                'name' => $plugin_name,
                'slug' => $slug,
                'status' => 'Re-installed successfully'
            ];
            clean_sweep_log_message("PluginReinstaller: Successfully reinstalled WordPress.org plugin: $plugin_name", 'info');
        } else {
            $results['wordpress_org']['failed'][] = [
                'name' => $plugin_name,
                'slug' => $slug,
                'status' => 'Re-installation failed'
            ];
            clean_sweep_log_message("PluginReinstaller: Failed to reinstall WordPress.org plugin: $plugin_name", 'error');
        }
    }

    /**
     * Process a WPMU DEV plugin item in unified batch
     *
     * @param array $plugin_data Plugin data
     * @param array &$results Results array (passed by reference)
     */
    private function process_wpmu_dev_item($plugin_data, &$results) {
        $plugin_file = key($plugin_data); // Get the key (filename)
        $plugin_info = current($plugin_data); // Get the data

        $pid = $plugin_info['wdp_id'] ?? $plugin_info['pid'] ?? null;
        $plugin_name = $plugin_info['name'] ?? $plugin_file;

        clean_sweep_log_message("PluginReinstaller: Processing WPMU DEV plugin: $plugin_name (PID: $pid)", 'info');

        // Check if WPMU DEV is available (only once per batch)
        if (!clean_sweep_is_wpmudev_available()) {
            clean_sweep_log_message("PluginReinstaller: WPMU DEV Dashboard not available", 'error');
            $results['wpmu_dev']['failed'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'WPMU DEV not available'
            ];
            return;
        }

        // Set up WPMU DEV environment (only once per batch)
        static $wpmu_setup_done = false;
        if (!$wpmu_setup_done) {
            set_time_limit(0);
            @ini_set('memory_limit', wp_convert_hr_to_bytes('512M'));

            $admin = clean_sweep_get_wpmudev_admin_user();
            if (!$admin) {
                $results['wpmu_dev']['failed'][] = [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'No admin user for WPMU DEV'
                ];
                return;
            }

            wp_set_current_user($admin->ID);
            clean_sweep_log_message("PluginReinstaller: Using admin user for WPMU DEV: " . $admin->user_login);

            $dashboard = WPMUDEV_Dashboard::instance();
            WPMUDEV_Dashboard::$site->refresh_local_projects('local');
            $wpmu_setup_done = true;
        }

        if (!$pid) {
            clean_sweep_log_message("PluginReinstaller: No PID found for plugin: $plugin_name", 'warning');
            $results['wpmu_dev']['failed'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'No PID found'
            ];
            return;
        }

        // Skip specific project
        if ((int) $pid === 119) {
            clean_sweep_log_message("PluginReinstaller: Skipping WPMU DEV project ID 119");
            return;
        }

        // Check project exists
        $projects = (array) WPMUDEV_Dashboard::$site->get_cached_projects();
        if (!isset($projects[$pid])) {
            clean_sweep_log_message("PluginReinstaller: WPMU DEV project ID $pid not found in cache", 'warning');
            $results['wpmu_dev']['failed'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Project not found in cache'
            ];
            return;
        }

        // Check activation status
        $is_active_blog = is_plugin_active($plugin_file);
        $is_active_network = is_multisite() && is_plugin_active_for_network($plugin_file);
        $should_reactivate = $is_active_blog || $is_active_network;

        // Deactivate if active
        if ($is_active_network) {
            deactivate_plugins($plugin_file, true, true);
        } elseif ($is_active_blog) {
            deactivate_plugins($plugin_file, true, false);
        }

        // Delete existing plugin
        if (!WPMUDEV_Dashboard::$upgrader->delete_plugin($pid, true)) {
            $results['wpmu_dev']['failed'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Delete failed'
            ];
            return;
        }

        // Download and install
        $download_url = WPMUDEV_Dashboard::$api->rest_url_auth('install/' . $pid);
        $temp_file = download_url($download_url);

        if (is_wp_error($temp_file)) {
            $results['wpmu_dev']['failed'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Download failed: ' . $temp_file->get_error_message()
            ];
            return;
        }

        $target_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
        if (!wp_mkdir_p($target_dir)) {
            @unlink($temp_file);
            $results['wpmu_dev']['failed'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Failed to create target directory'
            ];
            return;
        }

        $result = unzip_file($temp_file, WP_PLUGIN_DIR);
        @unlink($temp_file);

        if (is_wp_error($result)) {
            $results['wpmu_dev']['failed'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Extraction failed: ' . $result->get_error_message()
            ];
        } else {
            $entry = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Re-installed successfully (WPMU DEV)'
            ];

            // Reactivate if should be active
            if ($should_reactivate) {
                if ($is_active_network) {
                    $reactivation_result = activate_plugin($plugin_file, '', true, true);
                } else {
                    $reactivation_result = activate_plugin($plugin_file, '', false, true);
                }

                if (is_wp_error($reactivation_result)) {
                    $entry['status'] .= ' - Reactivation failed';
                } else {
                    $entry['status'] .= ' - Reactivated';
                }
            }

            $results['wpmu_dev']['successful'][] = $entry;

            // Clear cache after success
            if (isset(WPMUDEV_Dashboard::$site)) {
                WPMUDEV_Dashboard::$site->clear_local_file_cache();
                WPMUDEV_Dashboard::$site->refresh_local_projects('local');
            }
        }
    }

    /**
     * Reinstall WPMU DEV plugins with progress range
     *
     * @param array $wpmu_dev_plugins Plugins to reinstall
     * @param CleanSweep_ProgressManager|null $progressManager Progress manager instance
     * @param int $startProgress Start progress percentage (e.g., 60)
     * @param int $endProgress End progress percentage (e.g., 90)
     * @return array Results
     */
    private function reinstall_wpmu_dev_plugins_with_progress($wpmu_dev_plugins, $progressManager = null, $startProgress = 60, $endProgress = 90) {
        if (empty($wpmu_dev_plugins)) {
            return ['successful' => [], 'failed' => []];
        }

        $results = ['successful' => [], 'failed' => []];
        $total_plugins = count($wpmu_dev_plugins);
        $progress_range = $endProgress - $startProgress;

        // Check if WPMU DEV is available
        if (!clean_sweep_is_wpmudev_available()) {
            clean_sweep_log_message("PluginReinstaller: WPMU DEV Dashboard not available or not authenticated", 'error');
            if ($progressManager) {
                $progressManager->sendError('WPMU DEV not available');
            }
            return [
                'successful' => [],
                'failed' => [],
                'error' => 'WPMU DEV not available'
            ];
        }

        // Set unlimited execution time for potentially large operations
        set_time_limit(0);
        @ini_set('memory_limit', wp_convert_hr_to_bytes('512M'));

        // Admin user selection
        $admin = clean_sweep_get_wpmudev_admin_user();
        if (!$admin) {
            clean_sweep_log_message("PluginReinstaller: No administrator account found for WPMU DEV operations", 'error');
            if ($progressManager) {
                $progressManager->sendError('No admin user for WPMU DEV');
            }
            return [
                'successful' => [],
                'failed' => [],
                'error' => 'No admin user'
            ];
        }

        wp_set_current_user($admin->ID);
        clean_sweep_log_message("PluginReinstaller: Using admin user: " . $admin->user_login);

        // Get WPMU DEV dashboard and objects
        $dashboard = WPMUDEV_Dashboard::instance();
        $site = WPMUDEV_Dashboard::$site;
        $upgrader = WPMUDEV_Dashboard::$upgrader;

        // Refresh local projects cache
        $site->refresh_local_projects('local');
        $projects = (array) $site->get_cached_projects();

        clean_sweep_log_message("PluginReinstaller: Found " . count($projects) . " WPMU DEV projects");

        // Process each WPMU DEV plugin with progress updates
        $processed = 0;
        foreach ($wpmu_dev_plugins as $plugin_file => $plugin_info) {
            try {
                $processed++;
                $pid = $plugin_info['wdp_id'] ?? $plugin_info['pid'] ?? null;

                if (!$pid) {
                    clean_sweep_log_message("PluginReinstaller: No PID found for plugin: " . ($plugin_info['name'] ?? $plugin_file), 'warning');
                    continue;
                }

                // Skip specific project if needed
                if ((int) $pid === 119) {
                    clean_sweep_log_message("PluginReinstaller: Skipping WPMU DEV project ID 119 (excluded)");
                    continue;
                }

                // Verify this project exists in current cache
                if (!isset($projects[$pid])) {
                    clean_sweep_log_message("PluginReinstaller: WPMU DEV project ID {$pid} not found in cache, skipping", 'warning');
                    continue;
                }

                $project = $projects[$pid];
                $plugin_filename = $plugin_file; // Use the exact filename from analysis
                $plugin_name = $plugin_info['name'] ?? $plugin_file;

                // Update progress for current plugin
                $current_progress = $startProgress + round(($processed / $total_plugins) * $progress_range);
                if ($progressManager) {
                    $progressManager->updateProgress([
                        'status' => 'processing',
                        'progress' => $current_progress,
                        'message' => "Reinstalling WPMU DEV plugins...",
                        'details' => "Processing {$plugin_name} ({$processed}/{$total_plugins})"
                    ]);
                }

                // Check activation status
                $is_active_blog = is_plugin_active($plugin_filename);
                $is_active_network = is_multisite() && is_plugin_active_for_network($plugin_filename);
                $should_reactivate = $is_active_blog || $is_active_network;

                // Handle deactivation
                if ($is_active_network) {
                    $deactivation_result = deactivate_plugins($plugin_filename, true, true);
                    if (is_wp_error($deactivation_result)) {
                        $results['failed'][] = [
                            'name' => $plugin_name,
                            'slug' => $plugin_filename,
                            'status' => 'Deactivation failed: ' . $deactivation_result->get_error_message()
                        ];
                        continue;
                    }
                } elseif ($is_active_blog) {
                    $deactivation_result = deactivate_plugins($plugin_filename, true, false);
                    if (is_wp_error($deactivation_result)) {
                        $results['failed'][] = [
                            'name' => $plugin_name,
                            'slug' => $plugin_filename,
                            'status' => 'Deactivation failed: ' . $deactivation_result->get_error_message()
                        ];
                        continue;
                    }
                }

                // Delete existing plugin
                if (!$upgrader->delete_plugin($pid, true)) {
                    $error = $upgrader->get_error();
                    $results['failed'][] = [
                        'name' => $plugin_name,
                        'slug' => $plugin_filename,
                        'status' => 'Delete failed: ' . ($error ? "{$error['code']}: {$error['message']}" : 'Unknown error')
                    ];
                    clean_sweep_log_message("PluginReinstaller: Failed to delete WPMU DEV plugin {$plugin_name}", 'error');
                    continue;
                }

                // Download and install
                $download_url = WPMUDEV_Dashboard::$api->rest_url_auth('install/' . $pid);
                $temp_file = download_url($download_url);

                if (is_wp_error($temp_file)) {
                    $results['failed'][] = [
                        'name' => $plugin_name,
                        'slug' => $plugin_filename,
                        'status' => 'Download failed: ' . $temp_file->get_error_message()
                    ];
                    clean_sweep_log_message("PluginReinstaller: Failed to download WPMU DEV plugin {$plugin_name}: " . $temp_file->get_error_message(), 'error');
                    continue;
                }

                // Install the downloaded ZIP
                $upload_dir = wp_upload_dir();
                $target_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_filename);

                if (!wp_mkdir_p($target_dir)) {
                    @unlink($temp_file);
                    $results['failed'][] = [
                        'name' => $plugin_name,
                        'slug' => $plugin_filename,
                        'status' => 'Failed to create target directory'
                    ];
                    clean_sweep_log_message("PluginReinstaller: Failed to create target directory for WPMU DEV plugin {$plugin_name}", 'error');
                    continue;
                }

                $result = unzip_file($temp_file, WP_PLUGIN_DIR);
                @unlink($temp_file); // Clean up temporary file

                if (is_wp_error($result)) {
                    $results['failed'][] = [
                        'name' => $plugin_name,
                        'slug' => $plugin_filename,
                        'status' => 'Extraction failed: ' . $result->get_error_message()
                    ];
                    clean_sweep_log_message("PluginReinstaller: Failed to extract WPMU DEV plugin {$plugin_name}: " . $result->get_error_message(), 'error');
                } else {
                    // Success - add to results
                    $entry = [
                        'name' => $plugin_name,
                        'slug' => $plugin_filename,
                        'status' => 'Re-installed successfully (WPMU DEV)'
                    ];

                    // Reactivate if it should be active
                    if ($should_reactivate) {
                        if ($is_active_network) {
                            $reactivation_result = activate_plugin($plugin_filename, '', true, true);
                        } else {
                            $reactivation_result = activate_plugin($plugin_filename, '', false, true);
                        }

                        if (is_wp_error($reactivation_result)) {
                            $entry['status'] .= ' - Reactivation failed: ' . $reactivation_result->get_error_message();
                            clean_sweep_log_message("PluginReinstaller: WPMU DEV plugin {$plugin_name} installed but reactivation failed", 'warning');
                        } else {
                            $entry['status'] .= ' - Reactivated';
                        }
                    }

                    $results['successful'][] = $entry;

                    // Clear and refresh WPMU DEV cache
                    if (isset(WPMUDEV_Dashboard::$site)) {
                        WPMUDEV_Dashboard::$site->clear_local_file_cache();
                        WPMUDEV_Dashboard::$site->refresh_local_projects('local');
                        clean_sweep_log_message("PluginReinstaller: Cleared and refreshed WPMU DEV cache after successful install of {$plugin_name}", 'debug');
                    }
                }
            } catch (Exception $e) {
                // Catch any exceptions during individual plugin processing
                clean_sweep_log_message("PluginReinstaller: Exception processing WPMU DEV plugin {$plugin_name}: " . $e->getMessage(), 'error');
                $results['failed'][] = [
                    'name' => $plugin_name ?? $plugin_file ?? 'Unknown',
                    'slug' => $plugin_file ?? 'unknown',
                    'status' => 'Exception during processing: ' . $e->getMessage()
                ];
            }
        }

        clean_sweep_log_message("PluginReinstaller: WPMU DEV processing completed - Success: " . count($results['successful']) . ", Failed: " . count($results['failed']), 'info');

        return $results;
    }

    /**
     * Reinstall WordPress.org plugins using batch processor with progress range
     *
     * @param array $wp_org_plugins Plugins to reinstall
     * @param CleanSweep_ProgressManager|null $progressManager Progress manager instance
     * @param int $startProgress Start progress percentage (e.g., 10)
     * @param int $endProgress End progress percentage (e.g., 60)
     * @return array Results
     */
    private function reinstall_wordpress_org_plugins_with_progress($wp_org_plugins, $progressManager = null, $startProgress = 10, $endProgress = 60) {
        if (empty($wp_org_plugins)) {
            return ['successful' => [], 'failed' => []];
        }

        // Create custom batch processor that respects our progress range
        $batchProcessor = new CleanSweep_BatchProcessor(null, 1); // No progress file, we'll handle progress manually

        // Define the processor function
        $processor = function($plugin_data, $index, $total) use ($progressManager, $startProgress, $endProgress) {
            $slug = $plugin_data['slug'];
            $plugin_name = $plugin_data['name'];

            // Calculate progress within our range
            $progressRange = $endProgress - $startProgress;
            $currentProgress = $startProgress + round(($index / $total) * $progressRange);

            if ($progressManager) {
                $progressManager->updateProgress([
                    'status' => 'processing',
                    'progress' => $currentProgress,
                    'message' => "Reinstalling WordPress.org plugins...",
                    'details' => "Processing {$plugin_name} ({$index}/{$total})"
                ]);
            }

            if (clean_sweep_reinstall_plugin($slug)) {
                clean_sweep_log_message("PluginReinstaller: Successfully reinstalled WordPress.org plugin: $plugin_name", 'info');
                return [
                    'success' => true,
                    'name' => $plugin_name,
                    'slug' => $slug,
                    'status' => 'Re-installed successfully'
                ];
            } else {
                clean_sweep_log_message("PluginReinstaller: Failed to reinstall WordPress.org plugin: $plugin_name", 'error');
                return [
                    'success' => false,
                    'name' => $plugin_name,
                    'slug' => $slug,
                    'error' => 'Re-installation failed'
                ];
            }
        };

        // Process plugins in batches
        $batchResults = $batchProcessor->processItems(
            $wp_org_plugins,
            $processor,
            'Reinstalling WordPress.org plugins'
        );

        // Format results for legacy compatibility
        $results = ['successful' => [], 'failed' => []];

        foreach ($batchResults['success'] as $success) {
            $results['successful'][] = $success;
        }

        foreach ($batchResults['failed'] as $failure) {
            $results['failed'][] = $failure;
        }

        return $results;
    }

    /**
     * Clean up suspicious files and folders with progress range
     *
     * @param array $suspicious_files_to_delete Files to delete
     * @param CleanSweep_ProgressManager|null $progressManager Progress manager instance
     * @param int $startProgress Start progress percentage (e.g., 90)
     * @param int $endProgress End progress percentage (e.g., 95)
     * @return array Cleanup results
     */
    private function cleanup_suspicious_files_with_progress($suspicious_files_to_delete, $progressManager = null, $startProgress = 90, $endProgress = 95) {
        if (empty($suspicious_files_to_delete)) {
            return ['deleted' => [], 'failed' => []];
        }

        $results = ['deleted' => [], 'failed' => []];
        $total_files = count($suspicious_files_to_delete);
        $progress_range = $endProgress - $startProgress;

        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $processed = 0;
        foreach ($suspicious_files_to_delete as $file_info) {
            $processed++;
            $file_path = $file_info['path'];
            $file_name = $file_info['name'];

            // Update progress for current file
            $current_progress = $startProgress + round(($processed / $total_files) * $progress_range);
            if ($progressManager) {
                $progressManager->updateProgress([
                    'status' => 'processing',
                    'progress' => $current_progress,
                    'message' => "Cleaning up suspicious files...",
                    'details' => "Processing {$file_name} ({$processed}/{$total_files})"
                ]);
            }

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
}
