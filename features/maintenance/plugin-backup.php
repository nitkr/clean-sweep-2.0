<?php
/**
 * Clean Sweep - Plugin Backup Functions
 *
 * Backup and restore functionality for plugin operations
 */

/**
 * Create ZIP backup of current plugins with real-time progress updates
 *
 * @param string $progress_file Optional progress file for AJAX updates
 * @return bool Success status
 */
function clean_sweep_create_backup($progress_file = null) {
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

    // PHASE 1: Scan all files first to get total count (10% progress)
    $all_files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($plugins_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $all_files[] = $file->getPathname();
        }
    }

    $total_files = count($all_files);
    clean_sweep_log_message("Backup preparation complete: Found $total_files files to backup", 'info');

    // Send progress update: Scanning complete, starting ZIP creation
    if ($progress_file) {
        $progress_data = [
            'status' => 'processing',
            'progress' => 10,
            'message' => "Backup preparation complete - found {$total_files} files",
            'details' => "Starting ZIP archive creation..."
        ];
        clean_sweep_write_progress_file($progress_file, $progress_data);
    }

    // PHASE 2: Add files to ZIP with incremental progress updates (20-80%)
    $files_added = 0;
    $files_failed = 0;
    $progress_increment = max(1, floor($total_files / 60)); // Update progress every 1/60th of total files

    foreach ($all_files as $index => $file_path) {
        $relative_path = substr($file_path, $plugins_dir_length); // Remove plugins dir prefix

        if ($zip->addFile($file_path, $relative_path)) {
            $files_added++;
        } else {
            $files_failed++;
            clean_sweep_log_message("Failed to add file to ZIP: $file_path", 'warning');
        }

        // Send incremental progress updates every N files
        if (($index + 1) % $progress_increment === 0 || $index === $total_files - 1) {
            $progress_percent = 20 + round((($index + 1) / $total_files) * 60); // 20-80% range
            $current_mb = round($zip->status == ZIPARCHIVE::ER_OK ? filesize($zip_path) / (1024 * 1024) : 0, 1);

            if ($progress_file) {
                $progress_data = [
                    'status' => 'processing',
                    'progress' => min(80, $progress_percent),
                    'message' => "Creating backup: {$files_added} files processed",
                    'details' => "ZIP size: {$current_mb}MB | Progress: " . ($index + 1) . "/{$total_files} files"
                ];
                clean_sweep_write_progress_file($progress_file, $progress_data);
            }
        }
    }

    // PHASE 3: Finalize ZIP and cleanup (90% progress)
    $zip->close();

    if ($progress_file) {
        $progress_data = [
            'status' => 'processing',
            'progress' => 90,
            'message' => "Finalizing backup archive...",
            'details' => "Closing ZIP file and performing cleanup"
        ];
        clean_sweep_write_progress_file($progress_file, $progress_data);
    }

    // Check final result
    if ($files_added > 0) {
        $zip_size = filesize($zip_path);
        $zip_size_mb = round($zip_size / (1024 * 1024), 1);
        clean_sweep_log_message("ZIP backup completed: $files_added files, {$zip_size_mb}MB", 'success');

        if ($files_failed > 0) {
            clean_sweep_log_message("Warning: $files_failed files failed to add to backup", 'warning');
        }

        // Store ZIP path and size for later use
        global $clean_sweep_backup_info;
        $clean_sweep_backup_info = [
            'zip_path' => $zip_path,
            'size_bytes' => $zip_size,
            'files_count' => $files_added,
            'failed_count' => $files_failed
        ];

        return true;
    } else {
        clean_sweep_log_message("No files added to ZIP backup", 'error');
        @unlink($zip_path); // Delete empty ZIP file
        return false;
    }
}
