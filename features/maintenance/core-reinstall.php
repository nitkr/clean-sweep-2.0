<?php
/**
 * Clean Sweep - Core Reinstallation Feature
 *
 * Contains WordPress core file backup and reinstallation functionality
 */



/**
 * Detect the real WordPress site root directory
 * This is where wp-config.php lives, not necessarily ABSPATH
 */
function clean_sweep_detect_site_root() {
    // Try to find wp-config.php by walking up from current directory
    $current_dir = dirname(__DIR__); // features/ directory
    $max_levels = 5;

    for ($i = 0; $i < $max_levels; $i++) {
        $config_path = $current_dir . '/wp-config.php';
        if (file_exists($config_path)) {
            return rtrim($current_dir, '/') . '/';
        }
        $current_dir = dirname($current_dir);
    }

    // Fallback: assume we're in wp-content/plugins/ structure
    $current_dir = dirname(dirname(dirname(__DIR__))); // Go up 3 levels from features/
    return rtrim($current_dir, '/') . '/';
}

/**
 * Fallback function for deleting core directories
 * Uses PHP functions when WP_Filesystem is not available
 */
function clean_sweep_delete_core_directory($dir_path) {
    if (!is_dir($dir_path)) {
        return true; // Already gone
    }

    // Recursive delete with error suppression
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir_path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getRealPath());
        } else {
            @unlink($item->getRealPath());
        }
    }

    return @rmdir($dir_path);
}

/**
 * Create ZIP backup of core files that need to be preserved
 *
 * @param string $site_root Real WordPress site root directory
 * @param string|null $progress_file Progress file for AJAX updates
 * @return string|false ZIP file path on success, false on failure
 */
function clean_sweep_create_core_backup_zip($site_root, $progress_file = null) {
    // Get Clean Sweep root directory
    $clean_sweep_root = dirname(__DIR__, 2);
    $backup_dir = $clean_sweep_root . DIRECTORY_SEPARATOR . 'backups';

    // Create unique ZIP filename with timestamp
    $timestamp = date('Y-m-d_H-i-s');
    $zip_filename = 'wp-core-backup_' . $timestamp . '.zip';
    $zip_path = $backup_dir . DIRECTORY_SEPARATOR . $zip_filename;

    clean_sweep_log_message("Creating ZIP backup of core files to: " . $zip_path);

    // Ensure backup directory exists
    if (!file_exists($backup_dir)) {
        if (!wp_mkdir_p($backup_dir)) {
            if (!mkdir($backup_dir, 0755, true)) {
                clean_sweep_log_message("Failed to create backup directory: $backup_dir", 'error');
                return false;
            }
        }
        clean_sweep_log_message("Created backup directory: $backup_dir");
    }

    // Check if ZipArchive is available
    if (!class_exists('ZipArchive')) {
        clean_sweep_log_message("ZipArchive class not available, cannot create ZIP backup", 'error');
        return false;
    }

    $zip = new ZipArchive();

    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        clean_sweep_log_message("Failed to create ZIP file: $zip_path", 'error');
        return false;
    }

    clean_sweep_log_message("Created ZIP file successfully: $zip_filename");

    // Files to preserve (backup from REAL SITE ROOT)
    $preserve_files = [
        'wp-config.php',
        'wp-content',
        '.htaccess',
        'robots.txt'
    ];

    // Count total files for progress tracking
    $file_count = 0;
    foreach ($preserve_files as $file) {
        $full_path = $site_root . $file;
        if (file_exists($full_path)) {
            if (is_dir($full_path)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full_path));
                foreach ($iterator as $item) {
                    if ($item->isFile()) {
                        $file_count++;
                    }
                }
            } else {
                $file_count++;
            }
        }
    }

    clean_sweep_log_message("Found $file_count files to backup to ZIP");

    // Add files to ZIP with proper relative paths
    $processed_count = 0;

    foreach ($preserve_files as $file) {
        $full_path = $site_root . $file;
        if (file_exists($full_path)) {
            if (is_dir($full_path)) {
                // Handle directory recursively
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full_path));
                foreach ($iterator as $item) {
                    if ($item->isFile()) {
                        $relative_path = str_replace($site_root, '', $item->getPathname());

                        // Skip if relative path contains '..'
                        if (strpos($relative_path, '..') !== false) {
                            clean_sweep_log_message("Skipping invalid relative path: $relative_path", 'warning');
                            continue;
                        }

                        // Add file to ZIP
                        if ($zip->addFile($item->getPathname(), $relative_path)) {
                            clean_sweep_log_message("Added to ZIP: $relative_path");
                        } else {
                            clean_sweep_log_message("Failed to add to ZIP: $relative_path", 'error');
                        }

                        $processed_count++;

                        // Update progress every 10 files or for the last file
                        if ($progress_file && ($processed_count % 10 === 0 || $processed_count === $file_count)) {
                            $progress_percentage = round(($processed_count / $file_count) * 100);
                            $progress_data = [
                                'status' => 'backing_up',
                                'progress' => 10 + round($progress_percentage * 0.15), // 10-25% range for backup
                                'message' => "Creating core backup ZIP... ($processed_count/$file_count files)",
                                'current' => $processed_count,
                                'total' => $file_count,
                                'phase' => 'backup'
                            ];
                            @clean_sweep_write_progress_file($progress_file, $progress_data);
                        }
                    }
                }
            } else {
                // Handle single file
                $relative_path = $file;

                // Add file to ZIP
                if ($zip->addFile($full_path, $relative_path)) {
                    clean_sweep_log_message("Added to ZIP: $relative_path");
                } else {
                    clean_sweep_log_message("Failed to add to ZIP: $relative_path", 'error');
                }

                $processed_count++;

                // Update progress
                if ($progress_file) {
                    $progress_percentage = round(($processed_count / $file_count) * 100);
                    $progress_data = [
                        'status' => 'backing_up',
                        'progress' => 10 + round($progress_percentage * 0.15), // 10-25% range for backup
                        'message' => "Creating core backup ZIP... ($processed_count/$file_count files)",
                        'current' => $processed_count,
                        'total' => $file_count,
                        'phase' => 'backup'
                    ];
                    @clean_sweep_write_progress_file($progress_file, $progress_data);
                }
            }
        }
    }

    // Close the ZIP file
    $zip->close();

    // Verify ZIP was created successfully
    if (!file_exists($zip_path) || filesize($zip_path) === 0) {
        clean_sweep_log_message("ZIP backup failed - file not created or empty: $zip_path", 'error');
        return false;
    }

    $zip_size = round(filesize($zip_path) / 1024 / 1024, 2); // Size in MB
    clean_sweep_log_message("Core ZIP backup created successfully: $zip_filename ($zip_size MB, $file_count files)", 'success');

    return $zip_path; // Return the ZIP file path
}

/**
 * Execute WordPress core file re-installation
 */
function clean_sweep_execute_core_reinstallation($wp_version = 'latest') {
    // Get progress file parameter for AJAX progress tracking
    $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : null;

    // Check if user has made a backup choice
    $create_backup = isset($_POST['create_backup']) && $_POST['create_backup'] === '1';
    $proceed_without_backup = isset($_POST['proceed_without_backup']) && $_POST['proceed_without_backup'] === '1';

    clean_sweep_log_message("=== WordPress Core Re-installation Started ===");
    clean_sweep_log_message("Target Version: $wp_version");
    clean_sweep_log_message("Progress file: " . ($progress_file ?: 'none'));
    clean_sweep_log_message("Create backup: " . ($create_backup ? 'YES' : 'NO'));
    clean_sweep_log_message("Proceed without backup: " . ($proceed_without_backup ? 'YES' : 'NO'));
    clean_sweep_log_message("Current WordPress Version: " . get_bloginfo('version'));

    // DETECT REAL SITE ROOT (where wp-config.php lives, not ABSPATH)
    $site_root = clean_sweep_detect_site_root();
    clean_sweep_log_message("Detected site root: $site_root");

    // CHECK DISK SPACE BEFORE PROCEEDING
    $disk_check = clean_sweep_check_disk_space('core_reinstall');
    clean_sweep_log_message("Disk space check result: " . json_encode($disk_check), 'info');

    // Handle user's backup choice
    if ($create_backup) {
        // User chose to create backup - immediately update progress file to prevent duplicate UI display
        if ($progress_file) {
            $progress_data = [
                'status' => 'initializing',
                'progress' => 5,
                'message' => 'Starting core reinstallation with backup...',
                'details' => ''
            ];
            clean_sweep_write_progress_file($progress_file, $progress_data);
        }
        
        // Check if we have sufficient space
        if (!$disk_check['success'] || $disk_check['space_status'] === 'insufficient') {
            $error_msg = 'Cannot create backup - insufficient disk space';
            clean_sweep_log_message($error_msg, 'error');
            if ($progress_file) {
                $progress_data = [
                    'status' => 'error',
                    'progress' => 0,
                    'message' => $error_msg,
                    'details' => '<div style="color:#dc3545;">Error: ' . ($disk_check['warning'] ?? $disk_check['message']) . '</div>'
                ];
                clean_sweep_write_progress_file($progress_file, $progress_data);
                // Return JSON response for AJAX
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $error_msg, 'disk_check' => $disk_check]);
                exit;
            }
            return ['success' => false, 'message' => $error_msg, 'disk_check' => $disk_check];
        }
        clean_sweep_log_message("User requested backup creation - proceeding", 'info');
    } elseif ($proceed_without_backup) {
        // User chose to skip backup - immediately update progress file to prevent duplicate UI display
        if ($progress_file) {
            $progress_data = [
                'status' => 'initializing',
                'progress' => 5,
                'message' => 'Starting core reinstallation without backup...',
                'details' => ''
            ];
            clean_sweep_write_progress_file($progress_file, $progress_data);
        }
        
        clean_sweep_log_message("User chose to proceed without backup", 'warning');
    } elseif ($progress_file) {
    // Phase 1: Return JSON response for backup choice UI (hybrid approach)
    if (!$disk_check['success']) {
        // Disk space check failed
        // Create progress file FIRST to prevent 404 errors when polling starts
        $progress_data = [
            'status' => 'disk_space_error',
            'progress' => 0,
            'message' => 'Disk space check failed',
            'disk_check' => $disk_check,
            'details' => '<div style="color:#dc3545;">Error: ' . $disk_check['message'] . '</div>'
        ];
        clean_sweep_write_progress_file($progress_file, $progress_data);
        
        // Then return JSON response
        header('Content-Type: application/json');
        echo json_encode($progress_data);
        exit;
    } elseif ($disk_check['space_status'] === 'insufficient') {
        // Insufficient disk space - show warning
        // Create progress file FIRST to prevent 404 errors when polling starts
        $progress_data = [
            'status' => 'disk_space_warning',
            'progress' => 0,
            'message' => 'Insufficient disk space for backup',
            'disk_check' => $disk_check,
            'can_proceed_without_backup' => true
        ];
        clean_sweep_write_progress_file($progress_file, $progress_data);
        
        // Then return JSON response
        header('Content-Type: application/json');
        echo json_encode($progress_data);
        exit;
    } else {
        // Sufficient disk space - return backup choice JSON for UI
        // Create progress file FIRST to prevent 404 errors when polling starts
        $progress_data = [
            'status' => 'backup_choice',
            'progress' => 0,
            'message' => 'Choose backup option for core reinstallation',
            'disk_check' => $disk_check
        ];
        clean_sweep_write_progress_file($progress_file, $progress_data);
        
        // Then return JSON response
        header('Content-Type: application/json');
        echo json_encode($progress_data);
        exit; // Stop here - let JavaScript show UI and start polling after user choice
    }
    }

    // For non-AJAX requests, proceed with backup creation if sufficient space (unless user explicitly chose no backup)
    if (!$progress_file && !$proceed_without_backup && (!$disk_check['success'] || $disk_check['space_status'] === 'insufficient')) {
        return ['success' => false, 'message' => 'Insufficient disk space for backup', 'disk_check' => $disk_check];
    }

    // Initialize progress tracking
    $total_steps = $create_backup ? 4 : 3; // Fewer steps if no backup
    $progress_data = [
        'status' => 'initializing',
        'progress' => 0,
        'message' => 'Initializing core re-installation...',
        'details' => '',
        'step' => 0,
        'total_steps' => $total_steps
    ];
    clean_sweep_write_progress_file($progress_file, $progress_data);

    // CRITICAL: Set up custom error handler to suppress chmod warnings throughout the entire process
    $original_error_handler = set_error_handler(function($errno, $errstr, $errfile, $errline) {
        // Suppress chmod warnings specifically
        if (strpos($errstr, 'chmod()') !== false && strpos($errstr, 'No such file or directory') !== false) {
            return true; // Suppress the warning
        }
        // For all other errors, use default handling
        return false;
    }, E_WARNING);

    // Determine download URL based on version
    if ($wp_version === 'latest') {
        $download_url = 'https://wordpress.org/latest.zip';
        clean_sweep_log_message("Using latest WordPress version");
    } else {
        $download_url = "https://wordpress.org/wordpress-$wp_version.zip";
        clean_sweep_log_message("Using WordPress version: $wp_version");
    }

    // Create backup ZIP for core files (only if user requested backup)
    $core_backup_zip = null;
    if ($create_backup) {
        // Create ZIP backup of core files
        $core_backup_zip = clean_sweep_create_core_backup_zip($site_root, $progress_file);

        if (!$core_backup_zip) {
            clean_sweep_log_message("Failed to create core backup ZIP", 'error');
            return ['success' => false, 'message' => 'Failed to create backup ZIP'];
        }

        clean_sweep_log_message("Core backup ZIP created: $core_backup_zip");

        // Update progress: Backup complete
        $progress_data['status'] = 'backing_up';
        $progress_data['progress'] = 25;
        $progress_data['message'] = 'Core backup ZIP created successfully...';
        $progress_data['step'] = 1;
        clean_sweep_write_progress_file($progress_file, $progress_data);
    } else {
        // Skip backup - update progress accordingly
        clean_sweep_log_message("Skipping backup as requested by user", 'info');
        $progress_data['status'] = 'preparing';
        $progress_data['progress'] = 25;
        $progress_data['message'] = 'Preparing core reinstallation (backup skipped)...';
        $progress_data['step'] = 1;
        clean_sweep_write_progress_file($progress_file, $progress_data);
    }

    // Download WordPress
    clean_sweep_log_message("Downloading WordPress from: $download_url");

    $progress_data['status'] = 'downloading';
    $progress_data['progress'] = 50;
    $progress_data['message'] = 'Downloading WordPress core files...';
    $progress_data['step'] = 2;
    clean_sweep_write_progress_file($progress_file, $progress_data);

    $temp_file = clean_sweep_download_url($download_url);
    if (is_wp_error($temp_file)) {
        $progress_data['status'] = 'error';
        $progress_data['message'] = 'Failed to download WordPress';
        $progress_data['details'] = '<div style="color:#dc3545;">Error: ' . $temp_file->get_error_message() . '</div>';
        clean_sweep_write_progress_file($progress_file, $progress_data);

        clean_sweep_log_message("Failed to download WordPress: " . $temp_file->get_error_message(), 'error');
        return ['success' => false, 'message' => 'Failed to download WordPress'];
    }

    // Extract WordPress
    clean_sweep_log_message("Extracting WordPress files");

    $progress_data['status'] = 'extracting';
    $progress_data['progress'] = 75;
    $progress_data['message'] = 'Extracting WordPress files...';
    $progress_data['step'] = 3;
    clean_sweep_write_progress_file($progress_file, $progress_data);

    $temp_dir = defined('WP_TEMP_DIR') && WP_TEMP_DIR ? WP_TEMP_DIR : sys_get_temp_dir();
    $extract_dir = $temp_dir . '/wordpress_core_' . time();

    if (!wp_mkdir_p($extract_dir)) {
        clean_sweep_log_message("Failed to create extraction directory", 'error');
        unlink($temp_file);
        return ['success' => false, 'message' => 'Failed to create extraction directory'];
    }

    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';  // Already included earlier, but safe to ensure
        WP_Filesystem();
    }
    if (empty($wp_filesystem)) {
        clean_sweep_log_message("Failed to initialize WP_Filesystem", 'error');
        return ['success' => false, 'message' => 'Failed to initialize filesystem'];
    }

    $result = clean_sweep_unzip_file($temp_file, $extract_dir);
    if (is_wp_error($result)) {
        clean_sweep_log_message("Failed to extract WordPress: " . $result->get_error_message(), 'error');
        unlink($temp_file);
        return ['success' => false, 'message' => 'Failed to extract WordPress'];
    }

    // SECURITY ENHANCEMENT: Delete entire wp-admin and wp-includes directories from REAL SITE ROOT
    $directories_to_clean = ['wp-admin', 'wp-includes'];

    clean_sweep_log_message("Security: Completely removing wp-admin and wp-includes directories from real site for fresh install");

    foreach ($directories_to_clean as $dir) {
        $full_dir_path = $site_root . $dir;
        if (is_dir($full_dir_path)) {
            clean_sweep_log_message("Removing directory from real site: $full_dir_path");

            // Use WP_Filesystem for better compatibility
            if (!empty($wp_filesystem) && $wp_filesystem->rmdir($full_dir_path, true)) {
                clean_sweep_log_message("Successfully removed: $full_dir_path");
            } else {
                // Fallback to PHP functions with error suppression
                $success = @clean_sweep_delete_core_directory($full_dir_path);
                if ($success) {
                    clean_sweep_log_message("Successfully removed (fallback method): $full_dir_path");
                } else {
                    clean_sweep_log_message("Failed to remove directory: $full_dir_path", 'warning');
                }
            }
        }
    }

    // Install WordPress core files with complete error suppression
    clean_sweep_log_message("Installing WordPress core files");

    $progress_data['status'] = 'installing';
    $progress_data['progress'] = 90;
    $progress_data['message'] = 'Installing WordPress core files...';
    $progress_data['step'] = 4;
    clean_sweep_write_progress_file($progress_file, $progress_data);

    $wordpress_dir = $extract_dir . '/wordpress';

    // Also suppress all errors for this section using @ operator
    $old_error_reporting = error_reporting(0); // Suppress all errors

    try {
        $files_copied = 0;

        // Use simple recursive copy with error suppression
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wordpress_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative_path = str_replace($wordpress_dir . '/', '', $item->getPathname());
            $target_path = $site_root . $relative_path;  // INSTALL TO REAL SITE ROOT

            // Skip preserve files - for wp-admin/wp-includes, no longer needed since directories are fresh
            $skip = false;
            foreach ($preserve_files as $preserve) {
                if (strpos($relative_path, $preserve) === 0) {
                    $skip = true;
                    break;
                }
            }

            if (!$skip) {
                if ($item->isDir()) {
                    // Create directory with error suppression
                    if (!is_dir($target_path)) {
                        @mkdir($target_path, 0755, true);
                    }
                } else {
                    // Copy file with error suppression
                    if (@copy($item->getPathname(), $target_path)) {
                        $files_copied++;
                    }
                }
            }
        }

        clean_sweep_log_message("Core files installation completed with $files_copied files copied");
        clean_sweep_log_message("Security: wp-admin and wp-includes directories reinstalled fresh (malware removed)");

    } finally {
        // CRITICAL: Restore error handling
        error_reporting($old_error_reporting);
        if ($original_error_handler) {
            set_error_handler($original_error_handler);
        } else {
            restore_error_handler();
        }
    }

    // Clean up temporary files
    clean_sweep_recursive_delete($extract_dir);
    if (file_exists($temp_file)) {
        unlink($temp_file);
    }

    if (!defined('WP_CLI') || !WP_CLI) {
        echo '<script>updateProgress(4, 4, "Core Re-installation Complete");</script>';
        ob_flush();
        flush();
    }

    // ============================================================================
    // ESTABLISH CORE INTEGRITY BASELINE FOR REINFECTION DETECTION
    // ============================================================================

    clean_sweep_log_message("🔐 Establishing core integrity baseline for reinfection detection");

    // Establish baseline for freshly installed core files
    if (function_exists('clean_sweep_establish_core_baseline')) {
        $baseline_result = clean_sweep_establish_core_baseline($wp_version);
        if ($baseline_result) {
            clean_sweep_log_message("✅ Core integrity baseline established successfully");
            clean_sweep_log_message("🛡️ Future malware scans will detect reinfection by comparing against this baseline");
        } else {
            clean_sweep_log_message("⚠️ Failed to establish core integrity baseline", 'warning');
        }
    } else {
        clean_sweep_log_message("⚠️ Core baseline function not available", 'warning');
    }

    // Update final progress status
    $progress_data['status'] = 'complete';
    $progress_data['progress'] = 100;
    $progress_data['message'] = 'Core re-installation completed successfully!';

    $backup_info = $core_backup_zip ?
        '<li><strong>Backup ZIP:</strong> <code>' . htmlspecialchars(basename($core_backup_zip)) . '</code></li>' :
        '<li><strong>Backup:</strong> Skipped as requested</li>';

    $progress_data['details'] = '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:15px;border-radius:4px;margin:10px 0;color:#155724;">' .
        '<h4>✅ Success!</h4>' .
        '<p>WordPress core files have been successfully re-installed.</p>' .
        '<ul style="margin:10px 0;padding-left:20px;">' .
        '<li><strong>Version:</strong> ' . htmlspecialchars($wp_version) . '</li>' .
        '<li><strong>Files copied:</strong> ' . $files_copied . '</li>' .
        $backup_info .
        '<li><strong>Preserved files:</strong> wp-config.php, /wp-content</li>' .
        '</ul>' .
        '<p><strong>Next steps:</strong></p>' .
        '<ul style="margin:10px 0;padding-left:20px;">' .
        '<li>Check your website to ensure everything works correctly</li>' .
        '<li>Clear any caching plugins</li>' .
        '<li>Test all website functionality</li>' .
        '</ul>' .
        '</div>';
    clean_sweep_write_progress_file($progress_file, $progress_data);

    // Progress file will be cleaned up by browser cache or manually by user
    // Do not use shutdown functions as they can interfere with AJAX completion

    // Display results for non-AJAX requests
    if (!defined('WP_CLI') || !WP_CLI) {
        if (!$progress_file) {
            // Only show HTML output for non-AJAX requests
            echo '<h2>🛡️ WordPress Core Re-installation Complete</h2>';
            echo '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:20px;border-radius:4px;margin:20px 0;color:#155724;">';
            echo '<h3>✅ Success!</h3>';
            echo '<p>WordPress core files have been successfully re-installed.</p>';
            echo '<ul style="margin:10px 0;padding-left:20px;">';
            echo '<li><strong>Version:</strong> ' . htmlspecialchars($wp_version) . '</li>';
            echo '<li><strong>Files copied:</strong> ' . $files_copied . '</li>';
            if ($core_backup_zip) {
                echo '<li><strong>Backup ZIP:</strong> <code>' . htmlspecialchars(basename($core_backup_zip)) . '</code></li>';
            } else {
                echo '<li><strong>Backup:</strong> Skipped as requested</li>';
            }
            echo '<li><strong>Preserved files:</strong> wp-config.php, /wp-content</li>';
            echo '</ul>';
            echo '<p><strong>Next steps:</strong></p>';
            echo '<ul style="margin:10px 0;padding-left:20px;">';
            echo '<li>Check your website to ensure everything works correctly</li>';
            echo '<li>Clear any caching plugins</li>';
            echo '<li>Test all website functionality</li>';
            echo '</ul>';
            echo '</div>';
        }
    } else {
        echo "\n🛡️ WORDPRESS CORE RE-INSTALLATION COMPLETE\n";
        echo str_repeat("=", 50) . "\n";
        echo "✅ Success!\n";
        echo "Version: $wp_version\n";
        echo "Files copied: $files_copied\n";
        if ($core_backup_dir) {
            echo "Backup location: " . __DIR__ . '/' . $core_backup_dir . "\n";
        } else {
            echo "Backup: Skipped as requested\n";
        }
        echo str_repeat("=", 50) . "\n";
    }

    return ['success' => true, 'files_copied' => $files_copied, 'backup_dir' => $core_backup_dir];
}
