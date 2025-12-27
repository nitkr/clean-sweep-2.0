<?php
/**
 * Clean Sweep - Plugin Reinstaller
 *
 * Handles the actual plugin reinstallation process including WordPress.org
 * and WPMU DEV plugins, plus cleanup of suspicious files.
 */

class CleanSweep_PluginReinstaller {

    /**
     * Start the plugin reinstallation process with separate processing phases
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
        clean_sweep_log_message("PluginReinstaller: Starting reinstallation process with separate processing phases", 'info');

        // With JavaScript-only batching, no transient keys needed for cross-request state

        // Initialize progress manager
        $progressManager = $progress_file ? new CleanSweep_ProgressManager($progress_file) : null;

        try {
            $results = [
                'success' => false,
                'wordpress_org' => ['successful' => [], 'failed' => []],
                'wpmu_dev' => ['successful' => [], 'failed' => []],
                'suspicious_cleanup' => ['deleted' => [], 'failed' => []],
                'backup_created' => false,
                'error' => null
            ];

            // Phase 1: Backup creation (only once)
            if ($create_backup && $batch_start === 0) {
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
                if ($backup_result === false) {
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
            }

            // Phase 2: Process suspicious files (only in first batch)
            if ($batch_start === 0 && !empty($suspicious_files_to_delete)) {
                clean_sweep_log_message("PluginReinstaller: Processing suspicious files", 'info');
                $results['suspicious_cleanup'] = $this->cleanup_suspicious_files_with_progress($suspicious_files_to_delete, $progressManager, 10, 30);
                clean_sweep_log_message("PluginReinstaller: Suspicious files - Deleted: " . count($results['suspicious_cleanup']['deleted']) . ", Failed: " . count($results['suspicious_cleanup']['failed']), 'info');
            }

            // Phase 3: Process WordPress.org plugins in batches
            if (!empty($wp_org_plugins)) {
                clean_sweep_log_message("PluginReinstaller: Processing WordPress.org plugins - Batch start: $batch_start, Size: " . ($batch_size ?? 'all'), 'info');

                // Slice the plugins for this batch
                $plugin_keys = array_keys($wp_org_plugins);
                $batch_plugins = array_slice($wp_org_plugins, $batch_start, $batch_size, true);
                $total_wp_plugins = count($wp_org_plugins);
                $batch_count = count($batch_plugins);

                clean_sweep_log_message("PluginReinstaller: Processing batch " . ($batch_start + 1) . "-" . ($batch_start + $batch_count) . " of $total_wp_plugins WordPress.org plugins", 'info');

                $processed = 0;
                foreach ($batch_plugins as $plugin_key => $plugin_data) {
                    $processed++;
                    $overall_index = $batch_start + $processed;
                    $progress_percent = 30 + round(($overall_index / $total_wp_plugins) * 40); // 30-70% range for WP.org

                    $plugin_name = $plugin_data['name'] ?? $plugin_key;
                    if ($progressManager) {
                        $progressManager->updateProgress([
                            'status' => 'processing',
                            'progress' => $progress_percent,
                            'message' => "Reinstalling WordPress.org plugins...",
                            'details' => "Processing {$plugin_name} ({$overall_index}/{$total_wp_plugins})"
                        ]);
                    }

                    $slug = $plugin_data['slug'] ?? $plugin_key;
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

                // Accumulate WordPress.org results across batches
                $this->accumulate_batch_results($progress_file, $results, 'wordpress_org');

                // Check if there are more WordPress.org batches
                $has_more_wp_batches = ($batch_start + $batch_size) < $total_wp_plugins;
                if ($has_more_wp_batches) {
                    $results['batch_info'] = [
                        'has_more_batches' => true,
                        'next_batch_start' => $batch_start + $batch_size,
                        'processing_type' => 'wordpress_org'
                    ];

                    if ($progressManager) {
                        $progressManager->updateProgress([
                            'status' => 'batch_complete',
                            'progress' => 75,
                            'message' => 'WordPress.org batch completed, continuing...',
                            'details' => "Processed " . count($results['wordpress_org']['successful']) . " successful, " . count($results['wordpress_org']['failed']) . " failed",
                            'batch_info' => $results['batch_info']
                        ]);
                    }
                    clean_sweep_log_message("PluginReinstaller: WordPress.org batch completed, more batches pending", 'info');
                    return $results;
                }
            }

            // Phase 4: Process WPMU DEV plugins in batches (only after all WordPress.org batches complete)
            $wp_org_complete = empty($wp_org_plugins) || ($batch_start + ($batch_size ?? count($wp_org_plugins))) >= count($wp_org_plugins);

            if ($wp_org_complete && !empty($wpmu_dev_plugins)) {
                // CRITICAL: Check WPMU DEV availability BEFORE processing any batches
                if (!clean_sweep_is_wpmudev_available()) {
                    clean_sweep_log_message("PluginReinstaller: WPMU DEV Dashboard not available - skipping ALL WPMU DEV plugin processing", 'warning');

                    // Mark ALL WPMU DEV plugins as skipped due to authentication issues
                    foreach ($wpmu_dev_plugins as $plugin_file => $plugin_info) {
                        $results['wpmu_dev']['failed'][] = [
                            'name' => $plugin_info['name'] ?? $plugin_file,
                            'slug' => $plugin_file,
                            'status' => 'Skipped - WPMU DEV Dashboard not authenticated (check Hub connection)'
                        ];
                    }

                    // Accumulate WPMU DEV results
                    $this->accumulate_batch_results($progress_file, $results, 'wpmu_dev');

                    clean_sweep_log_message("PluginReinstaller: Skipped " . count($wpmu_dev_plugins) . " WPMU DEV plugins due to dashboard unavailability", 'info');

                    // Skip to final processing - no more batches needed
                    goto final_processing;
                }

                clean_sweep_log_message("PluginReinstaller: WordPress.org complete, processing WPMU DEV plugins - Batch start: $batch_start, Size: " . ($batch_size ?? 'all'), 'info');
                clean_sweep_log_message("PluginReinstaller: DEBUG - WPMU DEV plugins array has " . count($wpmu_dev_plugins) . " items", 'info');
                foreach ($wpmu_dev_plugins as $key => $data) {
                    clean_sweep_log_message("PluginReinstaller: DEBUG - WPMU DEV plugin: $key => " . json_encode($data), 'info');
                }

                // With JavaScript-only batching, process all WPMU DEV plugins at once
                // No transient-based batching needed since all data is passed with each request
                clean_sweep_log_message("PluginReinstaller: Processing all WPMU DEV plugins at once", 'info');

                $wmu_results = $this->reinstall_wpmu_dev_batch($wpmu_dev_plugins, $progressManager, 75, 95);

                // Merge WPMU DEV results into main results
                $results['wpmu_dev']['successful'] = array_merge($results['wpmu_dev']['successful'] ?? [], $wmu_results['successful']);
                $results['wpmu_dev']['failed'] = array_merge($results['wpmu_dev']['failed'] ?? [], $wmu_results['failed']);

                clean_sweep_log_message("PluginReinstaller: WPMU DEV processing - Success: " . count($wmu_results['successful']) . ", Failed: " . count($wmu_results['failed']), 'info');

                // All WPMU DEV plugins processed - set completion info
                $results['batch_info'] = [
                    'has_more_batches' => false,
                    'processing_complete' => true,
                    'processing_type' => 'wpmu_dev'
                ];
                clean_sweep_log_message("PluginReinstaller: All WPMU DEV plugins processed in single batch", 'info');
            }

            final_processing:

            // Determine overall success and populate top-level arrays for display compatibility
            $total_successful = count($results['wordpress_org']['successful']) + count($results['wpmu_dev']['successful']);
            $total_failed = count($results['wordpress_org']['failed']) + count($results['wpmu_dev']['failed']);

            // Populate top-level arrays that display functions expect
            $results['successful'] = array_merge(
                $results['wordpress_org']['successful'],
                $results['wpmu_dev']['successful']
            );
            $results['failed'] = array_merge(
                $results['wordpress_org']['failed'],
                $results['wpmu_dev']['failed']
            );

            $results['success'] = ($total_failed === 0);
            $results['summary'] = [
                'wordpress_org_successful' => count($results['wordpress_org']['successful']),
                'wordpress_org_failed' => count($results['wordpress_org']['failed']),
                'wpmu_dev_successful' => count($results['wpmu_dev']['successful']),
                'wpmu_dev_failed' => count($results['wpmu_dev']['failed']),
                'suspicious_deleted' => count($results['suspicious_cleanup']['deleted']),
                'suspicious_failed' => count($results['suspicious_cleanup']['failed'])
            ];

            clean_sweep_log_message("PluginReinstaller: Reinstallation completed - Total successful: $total_successful, Total failed: $total_failed", 'info');

            // Send completion
            if ($progressManager) {
                $progressManager->sendCompletion($results);
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
     * Clean up suspicious files and folders with progress range
     */
    private function cleanup_suspicious_files_with_progress($suspicious_files_to_delete, $progressManager = null, $startProgress = 10, $endProgress = 30) {
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

            $current_progress = $startProgress + round(($processed / $total_files) * $progress_range);
            if ($progressManager) {
                $progressManager->updateProgress([
                    'status' => 'processing',
                    'progress' => $current_progress,
                    'message' => "Cleaning up suspicious files...",
                    'details' => "Processing {$file_name} ({$processed}/{$total_files})"
                ]);
            }

            if ($wp_filesystem->delete($file_path, true)) {
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
                    'status' => 'Deletion failed'
                ];
                clean_sweep_log_message("PluginReinstaller: Failed to delete suspicious file: $file_name", 'warning');
            }
        }

        return $results;
    }

    /**
     * Reinstall WPMU DEV plugins with progress range
     */
    private function reinstall_wpmu_dev_plugins_with_progress($wpmu_dev_plugins, $progressManager = null, $startProgress = 90, $endProgress = 100) {
        if (empty($wpmu_dev_plugins)) {
            return ['successful' => [], 'failed' => []];
        }

        $results = ['successful' => [], 'failed' => []];
        $total_plugins = count($wpmu_dev_plugins);

        if (!clean_sweep_is_wpmudev_available()) {
            clean_sweep_log_message("PluginReinstaller: WPMU DEV Dashboard not available - site may not be connected to WPMU DEV Hub", 'error');

            // Mark all plugins as failed due to dashboard unavailability
            foreach ($wpmu_dev_plugins as $plugin_file => $plugin_info) {
                $results['failed'][] = [
                    'name' => $plugin_info['name'] ?? $plugin_file,
                    'slug' => $plugin_file,
                    'status' => 'Failed - WPMU DEV Dashboard not available (check Hub connection)'
                ];
            }

            clean_sweep_log_message("PluginReinstaller: Marked " . count($wpmu_dev_plugins) . " WPMU DEV plugins as failed due to dashboard unavailability", 'warning');
            return $results;
        }

        set_time_limit(0);
        $admin = clean_sweep_get_wpmudev_admin_user();
        if (!$admin) {
            return ['successful' => [], 'failed' => [], 'error' => 'No admin user'];
        }

        wp_set_current_user($admin->ID);
        $dashboard = WPMUDEV_Dashboard::instance();
        WPMUDEV_Dashboard::$site->refresh_local_projects('local');
        $projects = (array) WPMUDEV_Dashboard::$site->get_cached_projects();

        $processed = 0;
        foreach ($wpmu_dev_plugins as $plugin_file => $plugin_info) {
            $processed++;
            $pid = $plugin_info['wdp_id'] ?? $plugin_info['pid'] ?? null;

            if (!$pid || (int) $pid === 119) {
                continue;
            }

            if (!isset($projects[$pid])) {
                continue;
            }

            $plugin_name = $plugin_info['name'] ?? $plugin_file;
            $current_progress = $startProgress + round(($processed / $total_plugins) * ($endProgress - $startProgress));

            if ($progressManager) {
                $progressManager->updateProgress([
                    'status' => 'processing',
                    'progress' => $current_progress,
                    'message' => "Reinstalling WPMU DEV plugins...",
                    'details' => "Processing {$plugin_name} ({$processed}/{$total_plugins})"
                ]);
            }

            $is_active_blog = is_plugin_active($plugin_file);
            $is_active_network = is_multisite() && is_plugin_active_for_network($plugin_file);
            $should_reactivate = $is_active_blog || $is_active_network;

            if ($is_active_network) {
                deactivate_plugins($plugin_file, true, true);
            } elseif ($is_active_blog) {
                deactivate_plugins($plugin_file, true, false);
            }

            if (!WPMUDEV_Dashboard::$upgrader->delete_plugin($pid, true)) {
                $results['failed'][] = [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Delete failed'
                ];
                continue;
            }

            $download_url = WPMUDEV_Dashboard::$api->rest_url_auth('install/' . $pid);
            $temp_file = download_url($download_url);

            if (is_wp_error($temp_file)) {
                $results['failed'][] = [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Download failed'
                ];
                continue;
            }

            $target_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
            if (!wp_mkdir_p($target_dir)) {
                @unlink($temp_file);
                $results['failed'][] = [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Target directory creation failed'
                ];
                continue;
            }

            $result = unzip_file($temp_file, WP_PLUGIN_DIR);
            @unlink($temp_file);

            if (is_wp_error($result)) {
                $results['failed'][] = [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Extraction failed'
                ];
            } else {
                $entry = [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Re-installed successfully (WPMU DEV)'
                ];

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

                $results['successful'][] = $entry;

                if (isset(WPMUDEV_Dashboard::$site)) {
                    WPMUDEV_Dashboard::$site->clear_local_file_cache();
                    WPMUDEV_Dashboard::$site->refresh_local_projects('local');
                }
            }
        }

        clean_sweep_log_message("PluginReinstaller: WPMU DEV processing completed - Success: " . count($results['successful']) . ", Failed: " . count($results['failed']), 'info');
        return $results;
    }

    /**
     * Accumulate batch results across multiple batches using temporary files
     */
    private function accumulate_batch_results($progress_file, &$results, $batch_type) {
        if (!$progress_file) {
            return;
        }

        $logs_dir = defined('LOGS_DIR') ? LOGS_DIR : 'logs';
        $results_file = $logs_dir . '/' . basename($progress_file, '.progress') . '_accumulated_results.json';

        // Load existing accumulated results
        $accumulated = [];
        if (file_exists($results_file)) {
            $existing = json_decode(file_get_contents($results_file), true);
            if ($existing) {
                $accumulated = $existing;
            }
        }

        // Merge current batch results
        if (!isset($accumulated[$batch_type])) {
            $accumulated[$batch_type] = ['successful' => [], 'failed' => []];
        }

        if (isset($results[$batch_type]['successful'])) {
            $accumulated[$batch_type]['successful'] = array_merge(
                $accumulated[$batch_type]['successful'],
                $results[$batch_type]['successful']
            );
        }

        if (isset($results[$batch_type]['failed'])) {
            $accumulated[$batch_type]['failed'] = array_merge(
                $accumulated[$batch_type]['failed'],
                $results[$batch_type]['failed']
            );
        }

        // Save accumulated results
        file_put_contents($results_file, json_encode($accumulated));

        // Update current results with accumulated data for final display
        $results[$batch_type]['successful'] = $accumulated[$batch_type]['successful'];
        $results[$batch_type]['failed'] = $accumulated[$batch_type]['failed'];

        clean_sweep_log_message("PluginReinstaller: Accumulated $batch_type results - Total success: " . count($results[$batch_type]['successful']) . ", Total failed: " . count($results[$batch_type]['failed']), 'info');
    }

    /**
     * Reinstall a batch of WPMU DEV plugins
     */
    private function reinstall_wpmu_dev_batch($batch_plugins, $progressManager = null, $startProgress = 75, $endProgress = 95) {
        if (empty($batch_plugins)) {
            return ['successful' => [], 'failed' => []];
        }

        $results = ['successful' => [], 'failed' => []];
        $total_plugins = count($batch_plugins);

        if (!clean_sweep_is_wpmudev_available()) {
            clean_sweep_log_message("PluginReinstaller: WPMU DEV Dashboard not available - site may not be connected to WPMU DEV Hub", 'error');

            // Mark all plugins as failed due to dashboard unavailability
            foreach ($batch_plugins as $plugin_file => $plugin_info) {
                $results['failed'][] = [
                    'name' => $plugin_info['name'] ?? $plugin_file,
                    'slug' => $plugin_file,
                    'status' => 'Failed - WPMU DEV Dashboard not available (check Hub connection)'
                ];
            }

            clean_sweep_log_message("PluginReinstaller: Marked " . count($batch_plugins) . " WPMU DEV plugins as failed due to dashboard unavailability", 'warning');
            return $results;
        }

        set_time_limit(0);
        $admin = clean_sweep_get_wpmudev_admin_user();
        if (!$admin) {
            return ['successful' => [], 'failed' => [], 'error' => 'No admin user'];
        }

        wp_set_current_user($admin->ID);
        $dashboard = WPMUDEV_Dashboard::instance();
        WPMUDEV_Dashboard::$site->refresh_local_projects('local');
        $projects = (array) WPMUDEV_Dashboard::$site->get_cached_projects();

        $processed = 0;
        foreach ($batch_plugins as $plugin_file => $plugin_info) {
            $processed++;
            $pid = $plugin_info['wdp_id'] ?? $plugin_info['pid'] ?? null;

            clean_sweep_log_message("🔍 WPMU DEV Reinstall: Starting {$plugin_file} (PID: {$pid})", 'info');

            if (!$pid || (int) $pid === 119) {
                clean_sweep_log_message("⚠️ WPMU DEV Reinstall: Skipping {$plugin_file} - Invalid PID or Dashboard plugin", 'warning');
                continue;
            }

            if (!isset($projects[$pid])) {
                clean_sweep_log_message("❌ WPMU DEV Reinstall: Project {$pid} not found in WPMU DEV projects cache", 'error');
                $results['failed'][] = [
                    'name' => $plugin_info['name'] ?? $plugin_file,
                    'slug' => $plugin_file,
                    'status' => 'Project not found in WPMU DEV'
                ];
                continue;
            }

            $plugin_name = $plugin_info['name'] ?? $plugin_file;
            $current_progress = $startProgress + round(($processed / $total_plugins) * ($endProgress - $startProgress));

            if ($progressManager) {
                $progressManager->updateProgress([
                    'status' => 'processing',
                    'progress' => $current_progress,
                    'message' => "Reinstalling WPMU DEV plugins...",
                    'details' => "Processing {$plugin_name} ({$processed}/{$total_plugins})"
                ]);
            }

            // Check activation status
            $is_active_blog = is_plugin_active($plugin_file);
            $is_active_network = is_multisite() && is_plugin_active_for_network($plugin_file);
            $should_reactivate = $is_active_blog || $is_active_network;

            clean_sweep_log_message("🔍 WPMU DEV Reinstall: {$plugin_name} - Blog active: " . ($is_active_blog ? 'YES' : 'NO') . ", Network active: " . ($is_active_network ? 'YES' : 'NO'), 'info');

            // Deactivate plugin if active
            if ($is_active_network) {
                clean_sweep_log_message("🔄 WPMU DEV Reinstall: Deactivating {$plugin_name} (network)", 'info');
                $deactivate_result = deactivate_plugins($plugin_file, true, true);
                if (is_wp_error($deactivate_result)) {
                    clean_sweep_log_message("⚠️ WPMU DEV Reinstall: Network deactivation failed: " . $deactivate_result->get_error_message(), 'warning');
                }
            } elseif ($is_active_blog) {
                clean_sweep_log_message("🔄 WPMU DEV Reinstall: Deactivating {$plugin_name} (blog)", 'info');
                $deactivate_result = deactivate_plugins($plugin_file, true, false);
                if (is_wp_error($deactivate_result)) {
                    clean_sweep_log_message("⚠️ WPMU DEV Reinstall: Blog deactivation failed: " . $deactivate_result->get_error_message(), 'warning');
                }
            }

            // Delete existing plugin
            clean_sweep_log_message("🗑️ WPMU DEV Reinstall: Deleting existing {$plugin_name} (PID: {$pid})", 'info');
            $delete_result = WPMUDEV_Dashboard::$upgrader->delete_plugin($pid, true);

            if (!$delete_result) {
                clean_sweep_log_message("❌ WPMU DEV Reinstall: Delete failed for {$plugin_name}", 'error');

                // Check if upgrader has error details
                if (method_exists(WPMUDEV_Dashboard::$upgrader, 'get_error')) {
                    $error = WPMUDEV_Dashboard::$upgrader->get_error();
                    if ($error) {
                        clean_sweep_log_message("❌ WPMU DEV Reinstall: Delete error - {$error['code']}: {$error['message']}", 'error');
                    }
                }

                $results['failed'][] = [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Delete failed'
                ];
                continue;
            }
            clean_sweep_log_message("✅ WPMU DEV Reinstall: Successfully deleted {$plugin_name}", 'info');

            // Generate download URL
            clean_sweep_log_message("🔗 WPMU DEV Reinstall: Generating download URL for {$plugin_name} (PID: {$pid})", 'info');
            $download_url = WPMUDEV_Dashboard::$api->rest_url_auth('install/' . $pid);
            clean_sweep_log_message("🔗 WPMU DEV Reinstall: Download URL generated (length: " . strlen($download_url) . ")", 'debug');

            // Download the plugin
            clean_sweep_log_message("📥 WPMU DEV Reinstall: Downloading {$plugin_name}", 'info');
            $temp_file = download_url($download_url);

            if (is_wp_error($temp_file)) {
                clean_sweep_log_message("❌ WPMU DEV Reinstall: Download failed for {$plugin_name}: " . $temp_file->get_error_message(), 'error');
                $results['failed'][] = [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Download failed: ' . $temp_file->get_error_message()
                ];
                continue;
            }
            clean_sweep_log_message("✅ WPMU DEV Reinstall: Download successful for {$plugin_name} (file: " . basename($temp_file) . ")", 'info');

            // Create target directory
            $target_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
            clean_sweep_log_message("📁 WPMU DEV Reinstall: Creating target directory: {$target_dir}", 'info');

            if (!wp_mkdir_p($target_dir)) {
                @unlink($temp_file);
                clean_sweep_log_message("❌ WPMU DEV Reinstall: Failed to create target directory: {$target_dir}", 'error');
                $results['failed'][] = [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Target directory creation failed'
                ];
                continue;
            }

            // Extract the ZIP file
            clean_sweep_log_message("📦 WPMU DEV Reinstall: Extracting {$plugin_name} to plugins directory", 'info');
            $result = unzip_file($temp_file, WP_PLUGIN_DIR);
            @unlink($temp_file); // Clean up temp file

            if (is_wp_error($result)) {
                clean_sweep_log_message("❌ WPMU DEV Reinstall: Extraction failed for {$plugin_name}: " . $result->get_error_message(), 'error');
                $results['failed'][] = [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Extraction failed: ' . $result->get_error_message()
                ];
                continue;
            }
            clean_sweep_log_message("✅ WPMU DEV Reinstall: Extraction successful for {$plugin_name}", 'info');

            // Success - plugin installed
            $entry = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Re-installed successfully (WPMU DEV)'
            ];

            // Reactivate if it was originally active
            if ($should_reactivate) {
                clean_sweep_log_message("🔄 WPMU DEV Reinstall: Reactivating {$plugin_name}", 'info');

                if ($is_active_network) {
                    $reactivation_result = activate_plugin($plugin_file, '', true, true);
                    $reactivate_type = 'network';
                } else {
                    $reactivation_result = activate_plugin($plugin_file, '', false, true);
                    $reactivate_type = 'blog';
                }

                if (is_wp_error($reactivation_result)) {
                    clean_sweep_log_message("⚠️ WPMU DEV Reinstall: {$reactivate_type} reactivation failed: " . $reactivation_result->get_error_message(), 'warning');
                    $entry['status'] .= ' - Reactivation failed';
                } else {
                    clean_sweep_log_message("✅ WPMU DEV Reinstall: {$reactivate_type} reactivation successful", 'info');
                    $entry['status'] .= ' - Reactivated';
                }
            }

            $results['successful'][] = $entry;
            clean_sweep_log_message("🎉 WPMU DEV Reinstall: {$plugin_name} completed successfully", 'success');

            // Clear WPMU DEV cache
            if (isset(WPMUDEV_Dashboard::$site)) {
                WPMUDEV_Dashboard::$site->clear_local_file_cache();
                WPMUDEV_Dashboard::$site->refresh_local_projects('local');
                clean_sweep_log_message("🧹 WPMU DEV Reinstall: Cleared and refreshed WPMU DEV cache", 'debug');
            }
        }

        return $results;
    }
}
