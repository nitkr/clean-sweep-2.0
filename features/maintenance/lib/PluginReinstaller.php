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

        // Reset WPMU DEV batch counter for fresh reinstallation runs
        delete_transient('clean_sweep_wpmu_batch_number');

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
                clean_sweep_log_message("PluginReinstaller: WordPress.org complete, processing WPMU DEV plugins - Batch start: $batch_start, Size: " . ($batch_size ?? 'all'), 'info');
                clean_sweep_log_message("PluginReinstaller: DEBUG - WPMU DEV plugins array has " . count($wpmu_dev_plugins) . " items", 'info');
                foreach ($wpmu_dev_plugins as $key => $data) {
                    clean_sweep_log_message("PluginReinstaller: DEBUG - WPMU DEV plugin: $key => " . json_encode($data), 'info');
                }

                // Use separate transient storage to track WPMU DEV batch progress across requests
                $wpmu_batch_key = 'clean_sweep_wpmu_batch_number';
                $wpmu_batch_number = get_transient($wpmu_batch_key) ?: 0;

                $wpmu_batch_start = $wpmu_batch_number * $batch_size;
                $wpmu_batch_plugins = array_slice($wpmu_dev_plugins, $wpmu_batch_start, $batch_size, true);
                $total_wpmu_plugins = count($wpmu_dev_plugins);
                $wpmu_batch_count = count($wpmu_batch_plugins);

                clean_sweep_log_message("PluginReinstaller: DEBUG - wpmu_batch_number: $wpmu_batch_number, wpmu_batch_start: $wpmu_batch_start, batch_size: $batch_size", 'info');
                clean_sweep_log_message("PluginReinstaller: DEBUG - wpmu_batch_plugins has $wpmu_batch_count items", 'info');
                foreach ($wpmu_batch_plugins as $key => $data) {
                    clean_sweep_log_message("PluginReinstaller: DEBUG - Batch plugin: $key => " . json_encode($data), 'info');
                }

                if (!empty($wpmu_batch_plugins)) {
                    clean_sweep_log_message("PluginReinstaller: Processing WPMU DEV batch " . ($wpmu_batch_start + 1) . "-" . ($wpmu_batch_start + $wpmu_batch_count) . " of $total_wpmu_plugins plugins", 'info');

                    $wmu_results = $this->reinstall_wpmu_dev_batch($wpmu_batch_plugins, $progressManager, 75, 95);

                    // Merge WPMU DEV results into main results
                    $results['wpmu_dev']['successful'] = array_merge($results['wpmu_dev']['successful'] ?? [], $wmu_results['successful']);
                    $results['wpmu_dev']['failed'] = array_merge($results['wpmu_dev']['failed'] ?? [], $wmu_results['failed']);

                    clean_sweep_log_message("PluginReinstaller: WPMU DEV batch - Success: " . count($wmu_results['successful']) . ", Failed: " . count($wmu_results['failed']), 'info');

                    // Accumulate WPMU DEV results across batches
                    $this->accumulate_batch_results($progress_file, $results, 'wpmu_dev');

                    // Increment and store batch number for next call
                    $wpmu_batch_number++;
                    set_transient($wpmu_batch_key, $wpmu_batch_number, 3600);

                    // Check if there are more WPMU DEV batches
                    // Next batch start would be: current_batch_number * batch_size
                    $next_batch_start = $wpmu_batch_number * $batch_size;
                    $has_more_wpmu_batches = $next_batch_start < $total_wpmu_plugins;

                    clean_sweep_log_message("PluginReinstaller: DEBUG - next_batch_start: $next_batch_start, total_wpmu_plugins: $total_wpmu_plugins, has_more: " . ($has_more_wpmu_batches ? 'YES' : 'NO'), 'info');

                    if ($has_more_wpmu_batches) {
                        $results['batch_info'] = [
                            'has_more_batches' => true,
                            'next_batch_start' => $batch_start + $batch_size, // Keep incrementing for frontend compatibility
                            'processing_type' => 'wpmu_dev'
                        ];

                        if ($progressManager) {
                            $progressManager->updateProgress([
                                'status' => 'batch_complete',
                                'progress' => 95,
                                'message' => 'WPMU DEV batch completed, continuing...',
                                'details' => "Processed " . count($wmu_results['successful']) . " successful, " . count($wmu_results['failed']) . " failed",
                                'batch_info' => $results['batch_info']
                            ]);
                        }
                        clean_sweep_log_message("PluginReinstaller: WPMU DEV batch completed, more batches pending", 'info');
                        return $results;
                    } else {
                        // All WPMU DEV batches completed - set completion info
                        $results['batch_info'] = [
                            'has_more_batches' => false,
                            'processing_complete' => true,
                            'processing_type' => 'wpmu_dev'
                        ];
                        clean_sweep_log_message("PluginReinstaller: DEBUG - All WPMU DEV batches completed", 'info');
                    }
                } else {
                    clean_sweep_log_message("PluginReinstaller: DEBUG - No WPMU DEV plugins in this batch, ending processing", 'info');
                }
            }

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

        return $results;
    }
}
