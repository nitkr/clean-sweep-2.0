<?php
/**
 * Clean Sweep - Plugin Backup Functions
 *
 * Backup and restore functionality for plugin operations
 */

/**
 * Create ZIP backup of current plugins
 */
function clean_sweep_create_backup() {
    // Get Clean Sweep root directory (2 levels up from features/maintenance/)
    $clean_sweep_root = dirname(__DIR__, 2);
    $backup_dir = $clean_sweep_root . DIRECTORY_SEPARATOR . BACKUP_DIR;

    // Create backup directory if it doesn't exist
    if (!wp_mkdir_p($backup_dir)) {
        if (!mkdir($backup_dir, 0755, true)) {
            clean_sweep_log_message("Failed to create backup directory: $backup_dir", 'error');
            return false;
        }
    }

    // Generate ZIP filename with timestamp
    $zip_filename = 'plugins-backup-' . date('Y-m-d-H-i-s') . '.zip';
    $zip_path = $backup_dir . DIRECTORY_SEPARATOR . $zip_filename;

    clean_sweep_log_message("Creating ZIP backup of plugins to: " . $zip_path);

    // Check if ZipArchive is available
    if (!class_exists('ZipArchive')) {
        clean_sweep_log_message("ZipArchive class not available, cannot create ZIP backup", 'error');
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
        clean_sweep_log_message("Failed to create ZIP file: $zip_path", 'error');
        return false;
    }

    $plugins_dir = WP_PLUGIN_DIR;
    $plugins_dir_length = strlen($plugins_dir) + 1; // +1 for trailing slash

    // Add all plugin files to ZIP with relative paths
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($plugins_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $files_added = 0;
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $file_path = $file->getPathname();
            $relative_path = substr($file_path, $plugins_dir_length); // Remove plugins dir prefix

            if ($zip->addFile($file_path, $relative_path)) {
                $files_added++;
            } else {
                clean_sweep_log_message("Failed to add file to ZIP: $file_path", 'warning');
            }
        }
    }

    $zip->close();

    if ($files_added > 0) {
        $zip_size = filesize($zip_path);
        $zip_size_mb = round($zip_size / (1024 * 1024), 1);
        clean_sweep_log_message("ZIP backup completed: $files_added files, {$zip_size_mb}MB", 'success');

        // Store ZIP path and size for later use
        global $clean_sweep_backup_info;
        $clean_sweep_backup_info = [
            'zip_path' => $zip_path,
            'size_bytes' => $zip_size,
            'files_count' => $files_added
        ];

        return true;
    } else {
        clean_sweep_log_message("No files added to ZIP backup", 'error');
        @unlink($zip_path); // Delete empty ZIP file
        return false;
    }
}
