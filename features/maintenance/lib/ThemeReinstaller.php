<?php
/**
 * Clean Sweep - Theme Reinstaller
 *
 * Handles the actual theme reinstallation process including WordPress.org themes,
 * plus cleanup of suspicious files and themes/index.php reinstall.
 *
 * @version 1.0
 */

class CleanSweep_ThemeReinstaller {

    /**
     * Start the theme reinstallation process with separate processing phases
     *
     * @param string|null $progress_file Progress file for AJAX updates
     * @param bool $create_backup Whether to create backup
     * @param bool $proceed_without_backup Whether to proceed without backup
     * @param array $wp_org_themes WordPress.org themes to reinstall
     * @param array $suspicious_files_to_delete Files/folders to delete
     * @param int $batch_start Starting index for batch processing (0-based)
     * @param int|null $batch_size Number of items per batch (null = process all)
     * @return array Reinstallation results
     */
    public function start_reinstallation($progress_file = null, $create_backup = false, $proceed_without_backup = false, $wp_org_themes = [], $suspicious_files_to_delete = [], $batch_start = 0, $batch_size = null) {
        clean_sweep_log_message("ThemeReinstaller: Starting reinstallation process", 'info');

        // Initialize progress manager
        $progressManager = $progress_file ? new CleanSweep_ProgressManager($progress_file) : null;

        // FIX: Preserve existing total from progress file to maintain accurate combined count
        $existing_total = null;
        if ($progressManager) {
            $existing_progress = $progressManager->getCurrentProgress();
            if ($existing_progress && isset($existing_progress['total'])) {
                $existing_total = $existing_progress['total'];
                clean_sweep_log_message("ThemeReinstaller: Preserving existing total from progress file: $existing_total", 'info');
            }
        }

        try {
            $results = [
                'success' => false,
                'wordpress_org' => ['successful' => [], 'failed' => []],
                'suspicious_cleanup' => ['deleted' => [], 'failed' => []],
                'backup_created' => false,
                'error' => null
            ];

            // Phase 1: Backup creation (only once)
            if ($create_backup && $batch_start === 0) {
                clean_sweep_log_message("ThemeReinstaller: Creating backup before reinstallation", 'info');

                if ($progressManager) {
                    $progressManager->updateProgress([
                        'status' => 'processing',
                        'phase' => 'backup',
                        'progress' => 5,
                        'message' => "Creating backup before theme reinstallation...",
                        'details' => "Backup in progress - please wait...",
                        'plugin' => ''
                    ]);
                }

                $backup_result = clean_sweep_create_theme_backup();
                if ($backup_result === false) {
                    clean_sweep_log_message("ThemeReinstaller: Backup creation failed, aborting reinstallation", 'error');
                    if ($progressManager) {
                        $progressManager->sendError('Backup creation failed');
                    }
                    return [
                        'success' => false,
                        'error' => 'Backup creation failed',
                        'backup_created' => false
                    ];
                }

                clean_sweep_log_message("ThemeReinstaller: Backup created successfully", 'info');
                $results['backup_created'] = true;
                
                // Calculate total themes for progress tracking
                $total_themes = count($wp_org_themes);
                
                // Reset progress file after backup - backup overwrote it with file counts
                // Now we need to restore theme count for reinstall progress
                if ($progressManager && $total_themes > 0) {
                    $reset_progress = [
                        'status' => 'processing',
                        'phase' => 'reinstall',
                        'progress' => 0,
                        'message' => 'Starting theme reinstallation...',
                        'current' => 0,
                        'total' => $total_themes,
                        'plugin' => '',
                        'details' => "Processing 0 of {$total_themes} themes"
                    ];
                    $progressManager->updateProgress($reset_progress);
                }

                if ($progressManager) {
                    $progressManager->updateProgress([
                        'status' => 'processing',
                        'phase' => 'reinstall',
                        'progress' => 10,
                        'message' => "Backup completed, starting theme reinstallation...",
                        'details' => "Backup saved successfully - now reinstalling themes",
                        'plugin' => ''
                    ]);
                }
            }

            // Phase 2: Process suspicious files (only in first batch)
            if ($batch_start === 0 && !empty($suspicious_files_to_delete)) {
                clean_sweep_log_message("ThemeReinstaller: Processing suspicious files", 'info');
                $protected_roots = $this->build_protected_theme_roots($wp_org_themes);
                $results['suspicious_cleanup'] = $this->cleanup_suspicious_files_with_progress(
                    $suspicious_files_to_delete,
                    $progressManager,
                    10,
                    25,
                    $protected_roots
                );
                clean_sweep_log_message("ThemeReinstaller: Suspicious files - Deleted: " . count($results['suspicious_cleanup']['deleted']) . ", Failed: " . count($results['suspicious_cleanup']['failed']), 'info');
            }

            // Phase 3: Reinstall WordPress.org themes
            $total_themes = $existing_total ?? count($wp_org_themes);
            
            clean_sweep_log_message("ThemeReinstaller: Processing {$total_themes} total themes", 'info');
            
            // Slice themes for this batch
            $batch_themes = array_slice($wp_org_themes, $batch_start, $batch_size);
            $batch_count = count($batch_themes);
            
            clean_sweep_log_message("ThemeReinstaller: Processing batch " . ($batch_start + 1) . "-" . ($batch_start + $batch_count) . " of {$total_themes} themes", 'info');

            if (function_exists('clean_sweep_watch_note_operation') && $batch_themes !== []) {
                $watch_prefixes = [];
                foreach ($batch_themes as $theme_slug => $_theme_data) {
                    $slug = is_string($theme_slug) ? $theme_slug : (string) $theme_slug;
                    if ($slug !== '') {
                        $watch_prefixes[] = 'wp-content/themes/' . $slug . '/';
                    }
                }
                clean_sweep_watch_note_operation('theme_reinstall', $watch_prefixes, 1200, [
                    'detail' => $batch_count . ' theme(s)',
                ]);
            }
            
            $processed = 0;
            foreach ($batch_themes as $theme_slug => $theme_data) {
                $processed++;
                $overall_index = $batch_start + $processed;
                
                $theme_name = $theme_data['name'] ?? $theme_slug;
                $progress_percent = (int) round(($overall_index / max(1, $total_themes)) * 100);
                
                if ($progressManager) {
                    $progressManager->updateProgress([
                        'status' => 'processing',
                        'phase' => 'reinstall',
                        'progress' => $progress_percent,
                        'current' => $overall_index,
                        'total' => $total_themes,
                        'plugin' => $theme_name,
                        'message' => "Reinstalling {$theme_name}...",
                        'details' => "Processing {$theme_name} ({$overall_index}/{$total_themes})"
                    ]);
                }
                
                // Reinstall theme from WordPress.org
                $reinstall_result = $this->reinstall_single_theme($theme_slug, $theme_data);
                
                if ($reinstall_result['success']) {
                    $results['wordpress_org']['successful'][] = $reinstall_result['entry'];
                    clean_sweep_log_message("ThemeReinstaller: Successfully reinstalled theme: $theme_name", 'info');
                } else {
                    $results['wordpress_org']['failed'][] = $reinstall_result['entry'];
                    clean_sweep_log_message("ThemeReinstaller: Failed to reinstall theme: $theme_name", 'error');
                }
            }

            // Accumulate batch results
            $this->accumulate_batch_results($progress_file, $results, 'wordpress_org');

            // Check if there are more batches
            $has_more_batches = ($batch_start + $batch_size) < $total_themes;
            if ($has_more_batches) {
                $results['batch_info'] = [
                    'has_more_batches' => true,
                    'next_batch_start' => $batch_start + $batch_size,
                    'processed' => $batch_start + $batch_count,
                    'total' => $total_themes
                ];

                if ($progressManager) {
                    $processed_n = $batch_start + $batch_count;
                    $progressManager->updateProgress([
                        'status' => 'batch_complete',
                        'phase' => 'reinstall',
                        'progress' => (int) round(($processed_n / max(1, $total_themes)) * 100),
                        'current' => $processed_n,
                        'total' => $total_themes,
                        'plugin' => '',
                        'message' => 'Batch completed, continuing...',
                        'details' => "Processed {$processed_n} of {$total_themes} themes ({$processed_n}/{$total_themes})",
                        'batch_info' => $results['batch_info']
                    ]);
                }
                clean_sweep_log_message("ThemeReinstaller: Batch completed, more batches pending", 'info');
                $results['success'] = true;
                return $results;
            }

            // No more batches - proceed to final processing

            // Phase 4: Ensure themes/index.php exists and is not infected
            clean_sweep_log_message("ThemeReinstaller: Ensuring themes/index.php exists", 'info');
            $index_result = clean_sweep_ensure_themes_index();
            if ($index_result) {
                clean_sweep_log_message("ThemeReinstaller: themes/index.php verified/reinstalled", 'info');
            }

            // Determine overall success
            $total_successful = count($results['wordpress_org']['successful']);
            $total_failed = count($results['wordpress_org']['failed']);

            // Populate top-level arrays for display compatibility
            $results['successful'] = $results['wordpress_org']['successful'];
            $results['failed'] = $results['wordpress_org']['failed'];

            $results['success'] = ($total_failed === 0);
            $results['summary'] = [
                'wordpress_org_successful' => count($results['wordpress_org']['successful']),
                'wordpress_org_failed' => count($results['wordpress_org']['failed']),
                'suspicious_deleted' => count($results['suspicious_cleanup']['deleted']),
                'suspicious_failed' => count($results['suspicious_cleanup']['failed']),
                'index_php_reinstalled' => $index_result
            ];

            clean_sweep_log_message("ThemeReinstaller: Reinstallation completed - Total successful: $total_successful, Total failed: $total_failed", 'info');

            $visit_boot = dirname(__DIR__, 3) . '/includes/system/visit/bootstrap.php';
            if (is_readable($visit_boot)) {
                require_once $visit_boot;
            }
            $theme_root = defined('WP_CONTENT_DIR')
                ? rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/themes/'
                : '';
            if (function_exists('clean_sweep_seal_theme_dir') && $theme_root !== '') {
                foreach ($results['successful'] as $row) {
                    $slug = (string) ($row['slug'] ?? '');
                    if ($slug !== '') {
                        clean_sweep_seal_theme_dir($slug, $theme_root . $slug);
                    }
                }
            }

            // Send completion
            if ($progressManager) {
                $progressManager->sendCompletion($results);
            }

            return $results;

        } catch (Exception $e) {
            clean_sweep_log_message("ThemeReinstaller: Exception during reinstallation: " . $e->getMessage(), 'error');
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
     * Reinstall a single theme from WordPress.org
     *
     * @param string $theme_slug Theme slug
     * @param array $theme_data Theme data
     * @return array Result with success flag and entry
     */
    private function reinstall_single_theme($theme_slug, $theme_data) {
        try {
            // Include required WordPress files for theme operations
            include_once(ABSPATH . 'wp-admin/includes/file.php');
            include_once(ABSPATH . 'wp-admin/includes/misc.php');
            include_once(ABSPATH . 'wp-admin/includes/theme.php');
            
            // Get theme API
            $api = themes_api('theme_information', array(
                'slug' => $theme_slug,
                'fields' => array('sections' => false, 'tags' => false)
            ));
            
            if (is_wp_error($api)) {
                return [
                    'success' => false,
                    'entry' => [
                        'name' => $theme_data['name'] ?? $theme_slug,
                        'slug' => $theme_slug,
                        'status' => 'API Error: ' . $api->get_error_message()
                    ]
                ];
            }
            
            // Check if theme is already installed
            $theme = wp_get_theme($theme_slug);
            $was_active = false;
            
            if ($theme->exists()) {
                // Check if theme is active
                $current_theme = wp_get_theme();
                if ($current_theme->get('Name') === $theme->get('Name')) {
                    $was_active = true;
                }
                
                // Switch to a default theme if the current theme is being reinstalled
                if ($was_active) {
                    switch_theme('twentytwentyfive');
                }
                
                // Delete the theme
                delete_theme($theme_slug);
            }
            
            // Install the theme - using manual download/unzip approach like plugin reinstall
            // This avoids needing WordPress Upgrader classes
            $download_url = $api->download_link;
            
            // Download the theme
            $temp_file = clean_sweep_download_url($download_url);
            
            if (is_wp_error($temp_file)) {
                return [
                    'success' => false,
                    'entry' => [
                        'name' => $theme_data['name'] ?? $theme_slug,
                        'slug' => $theme_slug,
                        'status' => 'Download failed: ' . $temp_file->get_error_message()
                    ]
                ];
            }
            
            // Get themes directory
            $themes_dir = get_theme_root();
            
            // Remove existing theme directory if it exists
            $theme_dir = $themes_dir . '/' . $theme_slug;
            if (is_dir($theme_dir)) {
                global $wp_filesystem;
                if (!$wp_filesystem) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    WP_Filesystem();
                }
                if (!$wp_filesystem->rmdir($theme_dir, true)) {
                    @unlink($temp_file);
                    return [
                        'success' => false,
                        'entry' => [
                            'name' => $theme_data['name'] ?? $theme_slug,
                            'slug' => $theme_slug,
                            'status' => 'Failed to remove existing theme directory'
                        ]
                    ];
                }
            }
            
            // Extract the downloaded zip
            $result = clean_sweep_unzip_file($temp_file, $themes_dir);
            @unlink($temp_file);
            
            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'entry' => [
                        'name' => $theme_data['name'] ?? $theme_slug,
                        'slug' => $theme_slug,
                        'status' => 'Extract failed: ' . $result->get_error_message()
                    ]
                ];
            }
            
            // Reactivate if it was active
            if ($was_active) {
                switch_theme($theme_slug);
            }
            
            return [
                'success' => true,
                'entry' => [
                    'name' => $theme_data['name'] ?? $theme_slug,
                    'slug' => $theme_slug,
                    'version' => $api->version ?? $theme_data['version'] ?? 'unknown',
                    'status' => 'Re-installed successfully'
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'entry' => [
                    'name' => $theme_data['name'] ?? $theme_slug,
                    'slug' => $theme_slug,
                    'status' => 'Exception: ' . $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Absolute theme package roots that must not be deleted wholesale during cleanup.
     *
     * @param array $wp_org_themes
     * @return string[]
     */
    private function build_protected_theme_roots($wp_org_themes) {
        $themes_dir = defined('ORIGINAL_WP_CONTENT_DIR')
            ? rtrim(ORIGINAL_WP_CONTENT_DIR, '/\\') . '/themes'
            : (defined('WP_CONTENT_DIR') ? rtrim(WP_CONTENT_DIR, '/\\') . '/themes' : get_theme_root());
        $roots = [];
        foreach ((array) $wp_org_themes as $key => $theme_data) {
            $slug = is_array($theme_data) ? ($theme_data['slug'] ?? null) : null;
            if (!$slug && is_string($key)) {
                $slug = $key;
            }
            if ($slug) {
                $roots[] = rtrim($themes_dir, '/\\') . '/' . $slug;
            }
        }
        return array_values(array_unique($roots));
    }

    /**
     * Clean up suspicious files and folders with progress range
     *
     * @param array                           $suspicious_files_to_delete
     * @param CleanSweep_ProgressManager|null $progressManager
     * @param int                             $startProgress
     * @param int                             $endProgress
     * @param string[]                        $protected_roots
     * @return array
     */
    private function cleanup_suspicious_files_with_progress($suspicious_files_to_delete, $progressManager = null, $startProgress = 10, $endProgress = 25, array $protected_roots = []) {
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
                clean_sweep_log_message("ThemeReinstaller: Skipped suspicious file {$file_name}: {$err}", 'warning');
                continue;
            }

            $delete_path = $validation['realpath'] ?? $file_path;
            if ($wp_filesystem->delete($delete_path, true)) {
                $results['deleted'][] = [
                    'name' => $file_name,
                    'path' => $delete_path,
                    'status' => 'Deleted successfully'
                ];
                clean_sweep_log_message("ThemeReinstaller: Successfully deleted suspicious file: $file_name", 'info');
            } else {
                $results['failed'][] = [
                    'name' => $file_name,
                    'path' => $delete_path,
                    'status' => 'Deletion failed'
                ];
                clean_sweep_log_message("ThemeReinstaller: Failed to delete suspicious file: $file_name", 'warning');
            }
        }

        return $results;
    }

    /**
     * Accumulate batch results for cross-request state
     * Note: With JavaScript-only batching, results are accumulated client-side
     */
    private function accumulate_batch_results($progress_file, &$results, $type) {
        // With client-side batching, we don't need server-side accumulation
        // Results are returned and combined in the frontend
    }
}

/**
 * Ensure the themes directory index.php exists
 * This is a standard WordPress security file that prevents directory browsing
 * It is ALWAYS reinstalled during theme reinstallation to ensure a clean copy
 */
function clean_sweep_ensure_themes_index() {
    $themes_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/themes' : get_theme_root();
    if (defined('ORIGINAL_WP_CONTENT_DIR')) {
        $themes_dir = ORIGINAL_WP_CONTENT_DIR . '/themes';
    }
    
    $index_file = $themes_dir . '/index.php';

    // Standard WordPress themes/index.php content (silence is golden)
    $standard_content = "<?php
// Silence is golden.\n";

    // Always reinstall the index.php to ensure we have a clean copy
    // This ensures any modified or potentially infected files are replaced
    clean_sweep_log_message("Reinstalling themes/index.php to ensure clean copy", 'info');
    
    // Ensure directory exists
    if (!is_dir($themes_dir)) {
        clean_sweep_log_message("Themes directory does not exist: " . $themes_dir, 'error');
        return false;
    }
    
    if (file_put_contents($index_file, $standard_content) === false) {
        clean_sweep_log_message("Failed to reinstall themes/index.php", 'error');
        return false;
    }
    
    clean_sweep_log_message("Successfully reinstalled themes/index.php", 'info');
    return true;
}
