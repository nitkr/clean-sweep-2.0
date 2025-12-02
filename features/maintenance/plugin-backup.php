<?php
/**
 * Clean Sweep - Plugin Backup Functions
 *
 * Backup and restore functionality for plugin operations
 */

/**
 * Create backup of current plugins with progress updates
 */
function clean_sweep_create_backup($progress_file = null) {
    // Get Clean Sweep root directory (2 levels up from features/maintenance/)
    $clean_sweep_root = dirname(__DIR__, 2);
    $backup_path = $clean_sweep_root . DIRECTORY_SEPARATOR . BACKUP_DIR;
    clean_sweep_log_message("Creating backup of current plugins to: " . $backup_path);

    // Check if backup directory already exists
    if (file_exists($backup_path)) {
        clean_sweep_log_message("Backup directory already exists: $backup_path");
    } else {
        // Try WordPress filesystem method first
        if (!wp_mkdir_p($backup_path)) {
            // Fallback to PHP mkdir with recursive flag
            if (!mkdir($backup_path, 0755, true)) {
                clean_sweep_log_message("Failed to create backup directory: $backup_path (permissions or path issue)", 'error');
                return false;
            }
            clean_sweep_log_message("Created backup directory using PHP mkdir: $backup_path");
        } else {
            clean_sweep_log_message("Created backup directory using WordPress filesystem: $backup_path");
        }
    }

    $plugins_dir = ORIGINAL_WP_PLUGIN_DIR;
    $plugins_dir_length = strlen($plugins_dir);

    // Ensure plugins directory ends with a slash for proper path calculation
    if (substr($plugins_dir, -1) !== '/') {
        $plugins_dir .= '/';
        $plugins_dir_length = strlen($plugins_dir);
    }

    clean_sweep_log_message("Backing up plugins from: $plugins_dir");

    // Count total files for progress tracking
    $file_count = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugins_dir));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $file_count++;
        }
    }

    clean_sweep_log_message("Found $file_count files to backup");

    // Reset iterator for actual backup
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugins_dir));
    $processed_count = 0;

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $full_path = $file->getPathname();

            // Calculate relative path from plugins directory
            if (strpos($full_path, $plugins_dir) === 0) {
                $relative_path = substr($full_path, $plugins_dir_length);
            } else {
                clean_sweep_log_message("Skipping file outside plugins directory: $full_path", 'warning');
                continue;
            }

            // Skip if relative path is empty or contains '..'
            if (empty($relative_path) || strpos($relative_path, '..') !== false) {
                clean_sweep_log_message("Skipping invalid relative path: $relative_path", 'warning');
                continue;
            }

            $backup_file_path = $backup_path . '/' . $relative_path;

            $backup_dir = dirname($backup_file_path);
            if (!wp_mkdir_p($backup_dir)) {
                // Fallback to PHP mkdir with recursive flag
                if (!mkdir($backup_dir, 0755, true)) {
                    clean_sweep_log_message("Failed to create backup subdirectory: $backup_dir", 'error');
                    continue;
                }
                clean_sweep_log_message("Created backup subdirectory using PHP mkdir: $backup_dir");
            }

            if (!copy($full_path, $backup_file_path)) {
                clean_sweep_log_message("Failed to backup file: $full_path", 'error');
            } else {
                clean_sweep_log_message("Backed up: $relative_path");
            }

            $processed_count++;

            // Update progress every 10 files or for the last file
            if ($progress_file && ($processed_count % 10 === 0 || $processed_count === $file_count)) {
                $progress_percentage = round(($processed_count / $file_count) * 100);
                $progress_data = [
                    'status' => 'backing_up',
                    'progress' => $progress_percentage,
                    'message' => "Backing up plugins... ($processed_count/$file_count files)",
                    'current' => $processed_count,
                    'total' => $file_count,
                    'phase' => 'backup'
                ];
                @clean_sweep_write_progress_file($progress_file, $progress_data);
            }
        }
    }

    // Final progress update
    if ($progress_file) {
        $progress_data = [
            'status' => 'backup_complete',
            'progress' => 100,
            'message' => "Backup completed successfully ($file_count files backed up)",
            'current' => $file_count,
            'total' => $file_count,
            'phase' => 'backup'
        ];
        @clean_sweep_write_progress_file($progress_file, $progress_data);
    }

    clean_sweep_log_message("Backup completed successfully - $file_count files backed up", 'success');
    return true;
}
