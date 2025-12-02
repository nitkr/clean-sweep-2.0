<?php
/**
 * Clean Sweep - Plugin Backup Functions
 *
 * Backup and restore functionality for plugin operations
 */

/**
 * Create backup of current plugins
 */
function clean_sweep_create_backup() {
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
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugins_dir));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relative_path = str_replace($plugins_dir . '/', '', $file->getPathname());
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

            if (!copy($file->getPathname(), $backup_file_path)) {
                clean_sweep_log_message("Failed to backup file: " . $file->getPathname(), 'error');
            }
        }
    }

    clean_sweep_log_message("Backup completed", 'success');
    return true;
}
