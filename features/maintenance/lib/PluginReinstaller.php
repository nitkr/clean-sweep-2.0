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

        // FIX: Preserve existing total from progress file to maintain accurate combined count
        $existing_total = null;
        if ($progressManager) {
            $existing_progress = $progressManager->getCurrentProgress();
            if ($existing_progress && isset($existing_progress['total'])) {
                $existing_total = $existing_progress['total'];
                clean_sweep_log_message("PluginReinstaller: Preserving existing total from progress file: $existing_total", 'info');
            }
        }

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
                        'phase' => 'backup',
                        'progress' => 5,
                        'message' => "Creating backup before plugin reinstallation...",
                        'details' => "Backup in progress - please wait...",
                        'plugin' => ''
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
                
                // Calculate total plugins for progress tracking
                $total_plugins = count($wp_org_plugins) + count($wpmu_dev_plugins);
                
                // Reset progress file after backup - backup overwrote it with file counts
                // Now we need to restore plugin count for reinstall progress
                if ($progressManager && $total_plugins > 0) {
                    $reset_progress = [
                        'status' => 'processing',
                        'phase' => 'reinstall',
                        'progress' => 0,
                        'message' => 'Starting plugin reinstallation...',
                        'current' => 0,
                        'total' => $total_plugins,
                        'plugin' => '',
                        'details' => "Processing 0 of {$total_plugins} plugins"
                    ];
                    $progressManager->updateProgress($reset_progress);
                }

                if ($progressManager) {
                    $progressManager->updateProgress([
                        'status' => 'processing',
                        'phase' => 'reinstall',
                        'progress' => 10,
                        'message' => "Backup completed, starting plugin reinstallation...",
                        'details' => "Backup saved successfully - now reinstalling plugins",
                        'plugin' => ''
                    ]);
                }
            }

            // Phase 2: Process suspicious files (only in first batch)
            if ($batch_start === 0 && !empty($suspicious_files_to_delete)) {
                clean_sweep_log_message("PluginReinstaller: Processing suspicious files", 'info');
                $protected_roots = $this->build_protected_plugin_roots($wp_org_plugins, $wpmu_dev_plugins);
                $results['suspicious_cleanup'] = $this->cleanup_suspicious_files_with_progress(
                    $suspicious_files_to_delete,
                    $progressManager,
                    10,
                    30,
                    $protected_roots
                );
                clean_sweep_log_message("PluginReinstaller: Suspicious files - Deleted: " . count($results['suspicious_cleanup']['deleted']) . ", Failed: " . count($results['suspicious_cleanup']['failed']), 'info');
            }

            // FIX: Unified plugin processing - combine WP.org and WPMU DEV plugins into single list
            // This ensures progress bar moves continuously for all plugins regardless of source
            $total_combined = $existing_total ?? (count($wp_org_plugins) + count($wpmu_dev_plugins));
            
            // Build unified plugin list with source indicator
            $unified_plugins = [];
            
            // Add WP.org plugins first
            foreach ($wp_org_plugins as $slug => $plugin_data) {
                $unified_plugins[] = [
                    'slug' => $slug,
                    'name' => $plugin_data['name'] ?? $slug,
                    'version' => $plugin_data['version'] ?? 'unknown',
                    'source' => 'wordpress_org'
                ];
            }
            
            // Add WPMU DEV plugins
            foreach ($wpmu_dev_plugins as $map_key => $plugin_info) {
                $plugin_file = $plugin_info['plugin_file']
                    ?? $plugin_info['file']
                    ?? ((is_string($map_key) && strpos($map_key, '/') !== false) ? $map_key : null);
                $slug = $plugin_info['slug']
                    ?? ((is_string($map_key) && strpos($map_key, '/') === false) ? $map_key : null)
                    ?? ($plugin_file ? $this->extract_slug_from_plugin_file($plugin_file) : (string) $map_key);

                $unified_plugins[] = [
                    'slug' => $slug,
                    'plugin_file' => $plugin_file ?: $slug,
                    'name' => $plugin_info['name'] ?? $slug,
                    'version' => $plugin_info['version'] ?? 'unknown',
                    'wdp_id' => $plugin_info['wdp_id'] ?? $plugin_info['pid'] ?? null,
                    'source' => 'wpmu_dev'
                ];
            }
            
            $total_plugins = count($unified_plugins);
            clean_sweep_log_message("PluginReinstaller: Unified processing - {$total_plugins} total plugins ({$total_combined} combined total)", 'info');
            
            // Slice unified plugins for this batch
            $batch_plugins = array_slice($unified_plugins, $batch_start, $batch_size);
            $batch_count = count($batch_plugins);
            
            clean_sweep_log_message("PluginReinstaller: Processing unified batch " . ($batch_start + 1) . "-" . ($batch_start + $batch_count) . " of {$total_plugins} plugins", 'info');

            if (function_exists('clean_sweep_watch_note_operation') && $batch_plugins !== []) {
                $watch_prefixes = [];
                foreach ($batch_plugins as $plugin) {
                    $slug = (string) ($plugin['slug'] ?? '');
                    if ($slug !== '') {
                        $watch_prefixes[] = 'wp-content/plugins/' . $slug . '/';
                    }
                    $pf = (string) ($plugin['plugin_file'] ?? '');
                    if ($pf !== '') {
                        $watch_prefixes[] = 'wp-content/plugins/' . ltrim(str_replace('\\', '/', $pf), '/');
                    }
                }
                clean_sweep_watch_note_operation('plugin_reinstall', $watch_prefixes, 1200, [
                    'detail' => $batch_count . ' plugin(s)',
                ]);
            }

            // Bootstrap WPMU DEV once per batch when any WPMU items are present
            $batch_has_wpmu = false;
            foreach ($batch_plugins as $bp) {
                if (($bp['source'] ?? '') === 'wpmu_dev') {
                    $batch_has_wpmu = true;
                    break;
                }
            }
            $wpmu_dev_ready = true;
            $wpmu_bootstrap_error = null;
            if ($batch_has_wpmu) {
                $boot = $this->ensure_wpmudev_runtime();
                $wpmu_dev_ready = !empty($boot['ok']);
                $wpmu_bootstrap_error = $boot['error'] ?? 'WPMU DEV runtime not ready';
                if (!$wpmu_dev_ready) {
                    clean_sweep_log_message("PluginReinstaller: WPMU DEV bootstrap failed: {$wpmu_bootstrap_error}", 'warning');
                }
            }
            
            $processed = 0;
            foreach ($batch_plugins as $plugin) {
                $processed++;
                $overall_index = $batch_start + $processed;
                
                $plugin_name = $plugin['name'];
                $plugin_source = $plugin['source'];
                $progress_percent = (int) round(($overall_index / max(1, $total_combined)) * 100);
                
                if ($progressManager) {
                    $progressManager->updateProgress([
                        'status' => 'processing',
                        'phase' => 'reinstall',
                        'progress' => $progress_percent,
                        'current' => $overall_index,
                        'total' => $total_combined,
                        'plugin' => $plugin_name,
                        'message' => "Reinstalling {$plugin_name}...",
                        'details' => "Processing {$plugin_name} ({$overall_index}/{$total_combined}) [{$plugin_source}]"
                    ]);
                }
                
                // Process based on source
                if ($plugin_source === 'wordpress_org') {
                    // WordPress.org plugin
                    $slug = $plugin['slug'];
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
                } elseif ($plugin_source === 'wpmu_dev') {
                    if (!$wpmu_dev_ready) {
                        $results['wpmu_dev']['failed'][] = [
                            'name' => $plugin_name,
                            'slug' => $plugin['plugin_file'] ?? $plugin['slug'],
                            'status' => 'Skipped - ' . ($wpmu_bootstrap_error ?: 'WPMU DEV Dashboard not ready')
                        ];
                        continue;
                    }
                    
                    $wpmu_result = $this->reinstall_single_wpmu_dev_plugin(
                        $plugin['plugin_file'] ?? $plugin['slug'],
                        $plugin['name'],
                        $plugin['wdp_id'] ?? null,
                        $progressManager,
                        $overall_index,
                        $total_combined
                    );
                    
                    if ($wpmu_result['success']) {
                        $results['wpmu_dev']['successful'][] = $wpmu_result['entry'];
                    } else {
                        $results['wpmu_dev']['failed'][] = $wpmu_result['entry'];
                        clean_sweep_log_message(
                            "PluginReinstaller: WPMU DEV reinstall failed for {$plugin_name}: " . ($wpmu_result['entry']['status'] ?? 'unknown'),
                            'error'
                        );
                    }
                }
            }

            // Accumulate results
            $this->accumulate_batch_results($progress_file, $results, 'wordpress_org');
            $this->accumulate_batch_results($progress_file, $results, 'wpmu_dev');

            // Check if there are more batches
            $has_more_batches = ($batch_start + $batch_size) < $total_plugins;
            if ($has_more_batches) {
                $results['batch_info'] = [
                    'has_more_batches' => true,
                    'next_batch_start' => $batch_start + $batch_size,
                    'processing_type' => 'unified',
                    'processed' => $batch_start + $batch_count,
                    'total' => $total_combined
                ];

                if ($progressManager) {
                    $processed_n = $batch_start + $batch_count;
                    $progressManager->updateProgress([
                        'status' => 'batch_complete',
                        'phase' => 'reinstall',
                        'progress' => (int) round(($processed_n / max(1, $total_combined)) * 100),
                        'current' => $processed_n,
                        'total' => $total_combined,
                        'plugin' => '',
                        'message' => 'Batch completed, continuing...',
                        'details' => "Processed {$processed_n} of {$total_combined} plugins ({$processed_n}/{$total_combined})",
                        'batch_info' => $results['batch_info']
                    ]);
                }
                clean_sweep_log_message("PluginReinstaller: Unified batch completed, more batches pending", 'info');
                $results['success'] = true;
                return $results;
            }

            // No more batches - proceed to final processing

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

            // Process finished. Partial WPMU failures must not hide .org successes.
            $results['success'] = true;
            $results['partial'] = ($total_failed > 0);
            $results['summary'] = [
                'wordpress_org_successful' => count($results['wordpress_org']['successful']),
                'wordpress_org_failed' => count($results['wordpress_org']['failed']),
                'wpmu_dev_successful' => count($results['wpmu_dev']['successful']),
                'wpmu_dev_failed' => count($results['wpmu_dev']['failed']),
                'suspicious_deleted' => count($results['suspicious_cleanup']['deleted']),
                'suspicious_failed' => count($results['suspicious_cleanup']['failed'])
            ];

            clean_sweep_log_message("PluginReinstaller: Reinstallation completed - Total successful: $total_successful, Total failed: $total_failed", 'info');

            // Ensure plugins/index.php exists and is not infected
            clean_sweep_ensure_plugins_index();

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
     * Absolute plugin package roots that must not be deleted wholesale during cleanup.
     * Nested files inside these roots remain deletable.
     *
     * @param array $wp_org_plugins
     * @param array $wpmu_dev_plugins
     * @return string[]
     */
    private function build_protected_plugin_roots($wp_org_plugins, $wpmu_dev_plugins) {
        $plugins_dir = defined('ORIGINAL_WP_PLUGIN_DIR') ? ORIGINAL_WP_PLUGIN_DIR : WP_PLUGIN_DIR;
        $roots = [];

        foreach (array_merge((array) $wp_org_plugins, (array) $wpmu_dev_plugins) as $key => $plugin_data) {
            $slug = is_array($plugin_data)
                ? ($plugin_data['slug'] ?? null)
                : null;
            if (!$slug && is_string($key) && strpos($key, '/') === false) {
                $slug = $key;
            }
            $plugin_file = is_array($plugin_data)
                ? ($plugin_data['plugin_file'] ?? $plugin_data['file'] ?? null)
                : null;
            if (!$plugin_file && is_string($key) && strpos($key, '/') !== false) {
                $plugin_file = $key;
            }
            if (!$slug && $plugin_file) {
                $dir = dirname($plugin_file);
                $slug = ($dir === '.' || $dir === '') ? pathinfo($plugin_file, PATHINFO_FILENAME) : basename($dir);
            }
            if ($slug) {
                $roots[] = rtrim($plugins_dir, '/\\') . '/' . $slug;
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * Clean up suspicious files and folders with progress range
     *
     * @param array                              $suspicious_files_to_delete
     * @param CleanSweep_ProgressManager|null    $progressManager
     * @param int                                $startProgress
     * @param int                                $endProgress
     * @param string[]                           $protected_roots Package roots queued for reinstall
     * @return array
     */
    private function cleanup_suspicious_files_with_progress($suspicious_files_to_delete, $progressManager = null, $startProgress = 10, $endProgress = 30, array $protected_roots = []) {
        if (empty($suspicious_files_to_delete)) {
            return ['deleted' => [], 'failed' => []];
        }

        if (!class_exists('CleanSweep_SuspiciousItemAnalyzer', false)) {
            require_once __DIR__ . '/SuspiciousItemAnalyzer.php';
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
            $file_path = is_array($file_info) ? ($file_info['path'] ?? '') : '';
            $file_name = is_array($file_info) ? ($file_info['name'] ?? basename((string) $file_path)) : (string) $file_info;

            $current_progress = $startProgress + round(($processed / $total_files) * $progress_range);
            if ($progressManager) {
                $progressManager->updateProgress([
                    'status' => 'processing',
                    'phase' => 'cleanup',
                    'progress' => $current_progress,
                    'plugin' => '',
                    'message' => "Cleaning up suspicious files...",
                    'details' => "Cleaning {$file_name} ({$processed}/{$total_files} orphans)"
                ]);
            }

            $validation = CleanSweep_SuspiciousItemAnalyzer::validate_cleanup_path($file_path, $protected_roots);
            if (empty($validation['ok'])) {
                $err = $validation['error'] ?? 'Path validation failed';
                $results['failed'][] = [
                    'name' => $file_name,
                    'path' => $file_path,
                    'status' => 'Skipped: ' . $err
                ];
                clean_sweep_log_message("PluginReinstaller: Skipped suspicious file {$file_name}: {$err}", 'warning');
                continue;
            }

            $delete_path = $validation['realpath'] ?? $file_path;
            if ($wp_filesystem->delete($delete_path, true)) {
                $results['deleted'][] = [
                    'name' => $file_name,
                    'path' => $delete_path,
                    'status' => 'Deleted successfully'
                ];
                clean_sweep_log_message("PluginReinstaller: Successfully deleted suspicious file: $file_name", 'info');
            } else {
                $results['failed'][] = [
                    'name' => $file_name,
                    'path' => $delete_path,
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

            // FALLBACK: If wdp_id is not provided, look it up from cached projects by filename
            if (!$pid && !empty($projects)) {
                foreach ($projects as $project_id => $project) {
                    if (isset($project['filename']) && $project['filename'] === $plugin_file) {
                        $pid = $project_id;
                        break;
                    }
                }
            }

            // FIX: Cast PID to integer for proper array key lookup (string keys vs integer keys issue)
            $pid = $pid ? (int) $pid : null;

            if (!$pid || (int) $pid === 119) {
                clean_sweep_log_message("PluginReinstaller: Skipping {$plugin_file} - invalid or WPMU DEV Dashboard plugin (PID: " . ($pid ?? 'null') . ")", 'warning');
                continue;
            }

            if (!isset($projects[$pid])) {
                clean_sweep_log_message("PluginReinstaller: Skipping {$plugin_file} - project ID {$pid} not found in cached projects", 'warning');
                continue;
            }

            $plugin_name = $plugin_info['name'] ?? $plugin_file;
            $progress_total = $total_plugins;
            $current_progress = $startProgress + round(($processed / max(1, $progress_total)) * ($endProgress - $startProgress));

            if ($progressManager) {
                $progressManager->updateProgress([
                    'status' => 'processing',
                    'phase' => 'reinstall',
                    'progress' => $current_progress,
                    'current' => $processed,
                    'total' => $progress_total,
                    'plugin' => $plugin_name,
                    'message' => "Reinstalling {$plugin_name}...",
                    'details' => "Processing {$plugin_name} ({$processed}/{$progress_total})"
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

        $logs_dir = defined('CLEAN_SWEEP_LOGS_DIR') ? CLEAN_SWEEP_LOGS_DIR : 'logs';
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
     * Ensure WPMU DEV Dashboard is authenticated and usable for install/delete.
     * Mirrors the original batch path: admin user context + Dashboard::instance().
     *
     * @return array{ok:bool,error?:string}
     */
    private function ensure_wpmudev_runtime() {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        if (!function_exists('clean_sweep_is_wpmudev_available') || !clean_sweep_is_wpmudev_available()) {
            $cached = ['ok' => false, 'error' => 'WPMU DEV Dashboard not authenticated (check Hub connection)'];
            return $cached;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $admin = function_exists('clean_sweep_get_wpmudev_admin_user')
            ? clean_sweep_get_wpmudev_admin_user()
            : null;
        if (!$admin || empty($admin->ID)) {
            $cached = ['ok' => false, 'error' => 'No admin user available for WPMU DEV operations'];
            return $cached;
        }

        wp_set_current_user((int) $admin->ID);

        if (!class_exists('WPMUDEV_Dashboard')) {
            $cached = ['ok' => false, 'error' => 'WPMUDEV_Dashboard class not loaded'];
            return $cached;
        }

        if (method_exists('WPMUDEV_Dashboard', 'instance')) {
            try {
                WPMUDEV_Dashboard::instance();
            } catch (Throwable $e) {
                $cached = ['ok' => false, 'error' => 'Dashboard init failed: ' . $e->getMessage()];
                return $cached;
            }
        }

        if (!isset(WPMUDEV_Dashboard::$api) || !isset(WPMUDEV_Dashboard::$upgrader)) {
            $cached = ['ok' => false, 'error' => 'WPMU DEV API/upgrader not available'];
            return $cached;
        }

        if (isset(WPMUDEV_Dashboard::$site)) {
            try {
                WPMUDEV_Dashboard::$site->refresh_local_projects('local');
            } catch (Throwable $e) {
                clean_sweep_log_message('PluginReinstaller: refresh_local_projects warning: ' . $e->getMessage(), 'warning');
            }
        }

        clean_sweep_log_message(
            'PluginReinstaller: WPMU DEV runtime ready (user ID ' . (int) $admin->ID . ')',
            'info'
        );
        $cached = ['ok' => true];
        return $cached;
    }

    /**
     * @param string $plugin_file e.g. wp-smush-pro/wp-smush.php
     * @return string
     */
    private function extract_slug_from_plugin_file($plugin_file) {
        $plugin_file = str_replace('\\', '/', (string) $plugin_file);
        $dir = dirname($plugin_file);
        if ($dir !== '.' && $dir !== '') {
            return $dir;
        }
        return pathinfo($plugin_file, PATHINFO_FILENAME);
    }

    /**
     * Resolve main plugin basename (dir/file.php) from slug, map key, or WPMU project cache.
     *
     * @param string $plugin_ref
     * @param int|null $pid
     * @param array $projects
     * @return string
     */
    private function resolve_wpmu_plugin_file($plugin_ref, $pid = null, array $projects = []) {
        $plugin_ref = str_replace('\\', '/', trim((string) $plugin_ref));
        if ($plugin_ref !== '' && (strpos($plugin_ref, '/') !== false || substr($plugin_ref, -4) === '.php')) {
            return ltrim($plugin_ref, '/');
        }

        $pid = $pid ? (int) $pid : null;
        if ($pid && isset($projects[$pid]['filename']) && is_string($projects[$pid]['filename'])) {
            return str_replace('\\', '/', $projects[$pid]['filename']);
        }

        // Match by directory / basename against cached projects
        if ($plugin_ref !== '' && !empty($projects)) {
            foreach ($projects as $project) {
                $filename = isset($project['filename']) ? str_replace('\\', '/', (string) $project['filename']) : '';
                if ($filename === '') {
                    continue;
                }
                $project_dir = dirname($filename);
                $project_base = pathinfo($filename, PATHINFO_FILENAME);
                if ($plugin_ref === $project_dir || $plugin_ref === $project_base || $plugin_ref === $filename) {
                    return $filename;
                }
            }
        }

        // Filesystem fallback: first main PHP under the plugin directory
        if ($plugin_ref !== '' && $plugin_ref !== '.' && defined('WP_PLUGIN_DIR')) {
            $dir = WP_PLUGIN_DIR . '/' . $plugin_ref;
            if (is_dir($dir)) {
                $guess = $plugin_ref . '/' . $plugin_ref . '.php';
                if (is_file(WP_PLUGIN_DIR . '/' . $guess)) {
                    return $guess;
                }
                $files = glob($dir . '/*.php') ?: [];
                foreach ($files as $file) {
                    $headers = @get_file_data($file, ['Name' => 'Plugin Name']);
                    if (!empty($headers['Name'])) {
                        return $plugin_ref . '/' . basename($file);
                    }
                }
                if (!empty($files)) {
                    return $plugin_ref . '/' . basename($files[0]);
                }
            }
        }

        return $plugin_ref;
    }

    /**
     * Reinstall a single WPMU DEV plugin
     *
     * @param string $plugin_file Plugin file path or directory slug
     * @param string $plugin_name Plugin display name
     * @param int|null $wdp_id WPMU DEV project ID
     * @param object|null $progressManager Progress manager instance
     * @param int $overall_index Overall plugin index for progress
     * @param int $total_combined Combined total of all plugins
     * @return array Result with 'success' boolean and 'entry' array
     */
    private function reinstall_single_wpmu_dev_plugin($plugin_file, $plugin_name, $wdp_id = null, $progressManager = null, $overall_index = 0, $total_combined = 0) {
        $boot = $this->ensure_wpmudev_runtime();
        if (empty($boot['ok'])) {
            return [
                'success' => false,
                'entry' => [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Skipped - ' . ($boot['error'] ?? 'WPMU DEV not ready')
                ]
            ];
        }

        $projects = [];
        if (isset(WPMUDEV_Dashboard::$site)) {
            try {
                WPMUDEV_Dashboard::$site->refresh_local_projects('local');
                $projects = (array) WPMUDEV_Dashboard::$site->get_cached_projects();
            } catch (Throwable $e) {
                clean_sweep_log_message('PluginReinstaller: project refresh failed: ' . $e->getMessage(), 'warning');
            }
        }

        $pid = $wdp_id !== null && $wdp_id !== '' ? (int) $wdp_id : null;

        // Fallback: look up project ID from cached projects by filename/slug
        if (!$pid && !empty($projects)) {
            $ref_dir = dirname($plugin_file);
            $ref_base = pathinfo($plugin_file, PATHINFO_FILENAME);
            $ref_slug = ($ref_dir !== '.' && $ref_dir !== '') ? $ref_dir : $ref_base;

            foreach ($projects as $project_id => $project) {
                $project_filename = isset($project['filename']) ? str_replace('\\', '/', (string) $project['filename']) : '';
                if ($project_filename === '') {
                    continue;
                }
                $project_dir = dirname($project_filename);
                $project_basename = pathinfo($project_filename, PATHINFO_FILENAME);

                $match = ($project_filename === $plugin_file)
                    || ($ref_slug !== '' && ($ref_slug === $project_dir || $ref_slug === $project_basename))
                    || ($ref_dir !== '.' && $ref_dir === $project_dir)
                    || ($ref_base !== '' && $ref_base === $project_basename);

                if ($match) {
                    $pid = (int) $project_id;
                    break;
                }
            }
        }

        if (!$pid || $pid === 119) {
            return [
                'success' => false,
                'entry' => [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Skipped - No WDP ID found'
                ]
            ];
        }

        // Prefer cached local project; if missing, allow install when API can resolve the project
        $project_known = isset($projects[$pid]);
        if (!$project_known && isset(WPMUDEV_Dashboard::$site) && method_exists(WPMUDEV_Dashboard::$site, 'get_project_info')) {
            try {
                $info = WPMUDEV_Dashboard::$site->get_project_info($pid);
                if ($info) {
                    $project_known = true;
                    if (!empty($info->filename)) {
                        $projects[$pid] = ['filename' => $info->filename];
                    }
                }
            } catch (Throwable $e) {
                clean_sweep_log_message('PluginReinstaller: get_project_info(' . $pid . ') failed: ' . $e->getMessage(), 'debug');
            }
        }

        if (!$project_known) {
            return [
                'success' => false,
                'entry' => [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Project not found in WPMU DEV (PID ' . $pid . ')'
                ]
            ];
        }

        $plugin_file = $this->resolve_wpmu_plugin_file($plugin_file, $pid, $projects);
        clean_sweep_log_message(
            "PluginReinstaller: WPMU reinstall start name={$plugin_name} file={$plugin_file} pid={$pid}",
            'info'
        );

        $is_active_blog = function_exists('is_plugin_active') ? is_plugin_active($plugin_file) : false;
        $is_active_network = is_multisite() && function_exists('is_plugin_active_for_network')
            && is_plugin_active_for_network($plugin_file);
        // Also treat slug-only active list entries as active when possible
        if (!$is_active_blog && !$is_active_network && function_exists('is_plugin_active')) {
            $slug = $this->extract_slug_from_plugin_file($plugin_file);
            if ($slug && $slug !== $plugin_file) {
                // Scan active plugins for this directory
                $active = (array) get_option('active_plugins', []);
                foreach ($active as $active_file) {
                    if (strpos((string) $active_file, $slug . '/') === 0 || (string) $active_file === $slug) {
                        $plugin_file = (string) $active_file;
                        $is_active_blog = true;
                        break;
                    }
                }
            }
        }
        $should_reactivate = $is_active_blog || $is_active_network;

        if ($is_active_network) {
            deactivate_plugins($plugin_file, true, true);
        } elseif ($is_active_blog) {
            deactivate_plugins($plugin_file, true, false);
        }

        if (!isset(WPMUDEV_Dashboard::$upgrader) || !method_exists(WPMUDEV_Dashboard::$upgrader, 'delete_plugin')) {
            return [
                'success' => false,
                'entry' => [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'WPMU DEV upgrader unavailable'
                ]
            ];
        }

        $delete_result = WPMUDEV_Dashboard::$upgrader->delete_plugin($pid, true);
        if (!$delete_result) {
            return [
                'success' => false,
                'entry' => [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Delete failed'
                ]
            ];
        }

        if (!isset(WPMUDEV_Dashboard::$api) || !method_exists(WPMUDEV_Dashboard::$api, 'rest_url_auth')) {
            return [
                'success' => false,
                'entry' => [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'WPMU DEV API unavailable after delete'
                ]
            ];
        }

        $download_url = WPMUDEV_Dashboard::$api->rest_url_auth('install/' . $pid);
        $temp_file = download_url($download_url);

        if (is_wp_error($temp_file)) {
            return [
                'success' => false,
                'entry' => [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Download failed: ' . $temp_file->get_error_message()
                ]
            ];
        }

        $plugin_dir_name = dirname($plugin_file);
        if ($plugin_dir_name === '.' || $plugin_dir_name === '') {
            $plugin_dir_name = $this->extract_slug_from_plugin_file($plugin_file);
        }
        $target_dir = WP_PLUGIN_DIR . '/' . $plugin_dir_name;

        if ($plugin_dir_name !== '.' && !wp_mkdir_p($target_dir)) {
            @unlink($temp_file);
            return [
                'success' => false,
                'entry' => [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Target directory creation failed'
                ]
            ];
        }

        // WP_Filesystem required by unzip_file on many hosts
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        global $wp_filesystem;
        if (!$wp_filesystem) {
            WP_Filesystem();
        }

        $result = unzip_file($temp_file, WP_PLUGIN_DIR);
        @unlink($temp_file);

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'entry' => [
                    'name' => $plugin_name,
                    'slug' => $plugin_file,
                    'status' => 'Extraction failed: ' . $result->get_error_message()
                ]
            ];
        }

        // Prefer installed path from refreshed cache after extract
        if (isset(WPMUDEV_Dashboard::$site)) {
            try {
                WPMUDEV_Dashboard::$site->clear_local_file_cache();
                WPMUDEV_Dashboard::$site->refresh_local_projects('local');
                $refreshed = (array) WPMUDEV_Dashboard::$site->get_cached_projects();
                if (!empty($refreshed[$pid]['filename'])) {
                    $plugin_file = str_replace('\\', '/', (string) $refreshed[$pid]['filename']);
                }
            } catch (Throwable $e) {
                // non-fatal
            }
        }

        $entry = [
            'name' => $plugin_name,
            'slug' => $plugin_file,
            'status' => 'Re-installed successfully (WPMU DEV)'
        ];

        if ($should_reactivate && function_exists('activate_plugin')) {
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

        clean_sweep_log_message("PluginReinstaller: WPMU reinstall OK {$plugin_name} ({$plugin_file})", 'info');

        return [
            'success' => true,
            'entry' => $entry
        ];
    }

    /**
     * Process a plugin based on its source type
     * 
     * This method provides a unified interface for processing plugins from any source.
     * To add support for new plugin sources (e.g., CodeCanyon, custom premium plugins),
     * add a new case to this switch statement.
     * 
     * @param array $plugin Plugin data with 'slug', 'name', 'source', and source-specific fields
     * @param object|null $progressManager Progress manager instance
     * @param int $overall_index Overall plugin index for progress
     * @param int $total_combined Combined total of all plugins
     * @param array &$results Results array to populate
     */
    private function process_plugin_by_source($plugin, $progressManager = null, $overall_index = 0, $total_combined = 0, &$results = []) {
        $source = $plugin['source'] ?? 'wordpress_org';
        
        switch ($source) {
            case 'wordpress_org':
                $slug = $plugin['slug'];
                $plugin_name = $plugin['name'];
                
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
                break;
                
            case 'wpmu_dev':
                $boot = $this->ensure_wpmudev_runtime();
                if (empty($boot['ok'])) {
                    $results['wpmu_dev']['failed'][] = [
                        'name' => $plugin['name'],
                        'slug' => $plugin['plugin_file'] ?? $plugin['slug'],
                        'status' => 'Skipped - ' . ($boot['error'] ?? 'WPMU DEV Dashboard not authenticated')
                    ];
                    return;
                }
                
                $wpmu_result = $this->reinstall_single_wpmu_dev_plugin(
                    $plugin['plugin_file'] ?? $plugin['slug'],
                    $plugin['name'],
                    $plugin['wdp_id'] ?? null,
                    $progressManager,
                    $overall_index,
                    $total_combined
                );
                
                if ($wpmu_result['success']) {
                    $results['wpmu_dev']['successful'][] = $wpmu_result['entry'];
                } else {
                    $results['wpmu_dev']['failed'][] = $wpmu_result['entry'];
                }
                break;
                
            // TO ADD NEW PLUGIN SOURCES:
            // 1. Add a new case below (e.g., 'codecanyon', 'custom', 'edd')
            // 2. Implement the reinstallation logic for that source
            // 3. Add corresponding entry in $results array
            // Example:
            // case 'custom':
            //     // Custom premium plugin logic
            //     $results['custom']['successful'][] = [...];
            //     break;
            
            default:
                clean_sweep_log_message("PluginReinstaller: Unknown plugin source: $source", 'warning');
                $results['unknown']['failed'][] = [
                    'name' => $plugin['name'],
                    'slug' => $plugin['slug'],
                    'status' => "Unknown source: $source"
                ];
        }
    }
}
