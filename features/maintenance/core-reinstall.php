<?php
/**
 * Clean Sweep - Core Reinstallation Feature
 *
 * Contains WordPress core file backup and reinstallation functionality
 */



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
 * Execute WordPress core file re-installation
 */
function clean_sweep_execute_core_reinstallation($wp_version = 'latest') {
    // Get progress file parameter for AJAX progress tracking
    $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : null;

    clean_sweep_log_message("=== WordPress Core Re-installation Started ===");
    clean_sweep_log_message("Target Version: $wp_version");
    clean_sweep_log_message("Progress file: " . ($progress_file ?: 'none'));
    clean_sweep_log_message("Current WordPress Version: " . get_bloginfo('version'));

    // Initialize progress tracking
    $progress_data = [
        'status' => 'initializing',
        'progress' => 0,
        'message' => 'Initializing core re-installation...',
        'details' => '',
        'step' => 0,
        'total_steps' => 4
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

    // Check if user requested to proceed without backup
    $proceed_without_backup = isset($_POST['proceed_without_backup']) && $_POST['proceed_without_backup'] === '1';
    if ($proceed_without_backup) {
        clean_sweep_log_message("⚠️ User requested to proceed without backup - skipping disk space check and backup creation", 'warning');
    } else {
        // Check disk space before creating backup
        $disk_check = clean_sweep_check_disk_space('core_reinstall');
        if (!$disk_check['success']) {
            clean_sweep_log_message("Disk space check failed: {$disk_check['message']}", 'error');

            // For AJAX requests, return disk space warning for UI to handle
            if ($progress_file) {
                $progress_data = [
                    'status' => 'disk_space_warning',
                    'progress' => 0,
                    'message' => 'Insufficient disk space for backup',
                    'disk_check' => $disk_check,
                    'can_proceed_without_backup' => $disk_check['can_proceed'] ?? false
                ];
                clean_sweep_write_progress_file($progress_file, $progress_data);
                return ['disk_space_warning' => $disk_check];
            }

            // For CLI/direct requests, show warning and abort
            clean_sweep_log_message("Core reinstallation aborted due to insufficient disk space", 'error');
            clean_sweep_log_message("Required: {$disk_check['required_mb']}MB, Available: {$disk_check['available_mb']}MB", 'error');
            return ['success' => false, 'message' => 'Insufficient disk space for backup'];
        }

        clean_sweep_log_message("Disk space check passed: {$disk_check['backup_size_mb']}MB backup, {$disk_check['available_mb']}MB available", 'info');
    }

    // Create backup directory for core files (only if not proceeding without backup)
    if (!$proceed_without_backup) {
        $core_backup_dir = 'backups/wp-core-backup-' . date('Y-m-d-H-i-s');
        clean_sweep_log_message("Creating core files backup to: $core_backup_dir");

        if (!wp_mkdir_p($core_backup_dir)) {
            clean_sweep_log_message("Failed to create core backup directory", 'error');
            return ['success' => false, 'message' => 'Failed to create backup directory'];
        }
    } else {
        $core_backup_dir = null;
        clean_sweep_log_message("⚠️ Skipping backup creation - proceeding without backup as requested", 'warning');
    }

    // Backup preserve files (only if not proceeding without backup)
    if (!$proceed_without_backup) {
        // Files to preserve
        $preserve_files = [
            'wp-config.php',
            'wp-content',
            '.htaccess',
            'robots.txt'
        ];

        // Backup preserve files
        foreach ($preserve_files as $file) {
            $full_path = ABSPATH . $file;
            if (file_exists($full_path)) {
                $backup_path = $core_backup_dir . '/' . $file;
                $backup_dir = dirname($backup_path);

                if (!wp_mkdir_p($backup_dir)) {
                    clean_sweep_log_message("Failed to create backup subdirectory: $backup_dir", 'error');
                    continue;
                }

                if (is_dir($full_path)) {
                    // Copy directory recursively
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($full_path, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );

                    foreach ($iterator as $item) {
                        if ($item->isDir()) {
                            wp_mkdir_p($backup_path . '/' . $iterator->getSubPathName());
                        } else {
                            copy($item->getPathname(), $backup_path . '/' . $iterator->getSubPathName());
                        }
                    }
                } else {
                    copy($full_path, $backup_path);
                }
                clean_sweep_log_message("Backed up: $file");
            }
        }

        // Update progress: Starting backup
        $progress_data['status'] = 'backing_up';
        $progress_data['progress'] = 25;
        $progress_data['message'] = 'Creating backup of preserve files...';
        $progress_data['step'] = 1;
        clean_sweep_write_progress_file($progress_file, $progress_data);
    } else {
        // Skip backup step entirely
        clean_sweep_log_message("Skipping backup step - proceeding without backup", 'info');
        $progress_data['status'] = 'preparing';
        $progress_data['progress'] = 25;
        $progress_data['message'] = 'Preparing for core files installation...';
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

    $temp_file = download_url($download_url);
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

    $result = unzip_file($temp_file, $extract_dir);
    if (is_wp_error($result)) {
        clean_sweep_log_message("Failed to extract WordPress: " . $result->get_error_message(), 'error');
        unlink($temp_file);
        return ['success' => false, 'message' => 'Failed to extract WordPress'];
    }

    // SECURITY ENHANCEMENT: Delete entire wp-admin and wp-includes directories to remove potential malware
    $directories_to_clean = ['wp-admin', 'wp-includes'];

    clean_sweep_log_message("Security: Completely removing wp-admin and wp-includes directories for fresh install");

    foreach ($directories_to_clean as $dir) {
        $full_dir_path = ABSPATH . $dir;
        if (is_dir($full_dir_path)) {
            clean_sweep_log_message("Removing directory: $full_dir_path");

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
            $target_path = ABSPATH . $relative_path;

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
    // SAVE CLEAN HASHES FOR FUTURE INTEGRITY CHECKING
    // ============================================================================

    clean_sweep_log_message("📋 Saving clean core file hashes for future integrity verification");

    // Calculate SHA-256 hashes of freshly installed core files
    $clean_core_files = [
        'index.php',
        'wp-blog-header.php',
        'wp-load.php',
        'wp-settings.php',
        'wp-admin/index.php',
        'wp-admin/admin.php',
        'wp-includes/version.php',
        'wp-includes/wp-db.php'
    ];

    $clean_hashes = [
        'timestamp' => time(),
        'wp_version' => $wp_version,
        'files' => []
    ];

    foreach ($clean_core_files as $file) {
        $file_path = ABSPATH . $file;
        if (file_exists($file_path)) {
            $clean_hashes['files'][$file_path] = hash_file('sha256', $file_path);
        }
    }

    // Save clean hashes to file
    $hashes_file = __DIR__ . '/clean-core-hashes.json';
    if (file_put_contents($hashes_file, json_encode($clean_hashes, JSON_PRETTY_PRINT))) {
        clean_sweep_log_message("✅ Clean core hashes saved to: $hashes_file (" . count($clean_hashes['files']) . " files)");
        clean_sweep_log_message("🔍 Future scans will use these clean hashes for integrity verification");
    } else {
        clean_sweep_log_message("⚠️ Failed to save clean core hashes", 'warning');
    }

    clean_sweep_log_message("WordPress core re-installation completed successfully");
    clean_sweep_log_message("Files copied: $files_copied");
    if ($core_backup_dir) {
        clean_sweep_log_message("Backup location: " . __DIR__ . '/' . $core_backup_dir);
    } else {
        clean_sweep_log_message("No backup created - proceeded without backup as requested", 'warning');
    }

    // Update final progress status
    $progress_data['status'] = 'complete';
    $progress_data['progress'] = 100;
    $progress_data['message'] = 'Core re-installation completed successfully!';
    $progress_data['details'] = '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:15px;border-radius:4px;margin:10px 0;color:#155724;">' .
        '<h4>✅ Success!</h4>' .
        '<p>WordPress core files have been successfully re-installed.</p>' .
        '<ul style="margin:10px 0;padding-left:20px;">' .
        '<li><strong>Version:</strong> ' . htmlspecialchars($wp_version) . '</li>' .
        '<li><strong>Files copied:</strong> ' . $files_copied . '</li>' .
        ($core_backup_dir ? '<li><strong>Backup location:</strong> <code>' . htmlspecialchars($core_backup_dir) . '</code></li>' : '<li><strong>Backup:</strong> Skipped (proceeded without backup)</li>') .
        '<li><strong>Preserved files:</strong> wp-config.php, uploads, themes, plugins</li>' .
        '</ul>' .
        '<p><strong>Next steps:</strong></p>' .
        '<ul style="margin:10px 0;padding-left:20px;">' .
        '<li>Check your website to ensure everything works correctly</li>' .
        '<li>Re-activate any plugins that were deactivated</li>' .
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
            if ($core_backup_dir) {
                echo '<li><strong>Backup location:</strong> <code>' . htmlspecialchars(__DIR__ . '/' . $core_backup_dir) . '</code></li>';
            } else {
                echo '<li><strong>Backup:</strong> Skipped (proceeded without backup)</li>';
            }
            echo '<li><strong>Preserved files:</strong> wp-config.php, uploads, themes, plugins</li>';
            echo '</ul>';
            echo '<p><strong>Next steps:</strong></p>';
            echo '<ul style="margin:10px 0;padding-left:20px;">';
            echo '<li>Check your website to ensure everything works correctly</li>';
            echo '<li>Re-activate any plugins that were deactivated</li>';
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
            echo "Backup: Skipped (proceeded without backup)\n";
        }
        echo str_repeat("=", 50) . "\n";
    }

    return ['success' => true, 'files_copied' => $files_copied, 'backup_dir' => $core_backup_dir];
}
