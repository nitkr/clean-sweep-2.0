<?php
/**
 * Clean Sweep - Backup Manager
 *
 * Handles backup choice UI and disk space checking for plugin reinstallation.
 * Shows users backup size information and lets them choose backup options.
 */

class CleanSweep_BackupManager {

    /**
     * Get backup choice UI for plugin reinstallation
     *
     * @param string|null $progress_file Progress file for AJAX responses
     * @return array Backup choice response
     */
    public function get_backup_choice($progress_file = null) {
        clean_sweep_log_message("BackupManager: Getting backup choice for plugin reinstallation", 'info');

        try {
            // Calculate backup size for plugins
            $disk_check = clean_sweep_check_disk_space('plugin_reinstall');

            if (!$disk_check['success']) {
                clean_sweep_log_message("BackupManager: Disk space check failed: {$disk_check['message']}", 'error');

                // For AJAX requests, return disk space warning
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

                // For direct requests, return error
                return [
                    'success' => false,
                    'error' => 'Insufficient disk space for backup',
                    'disk_check' => $disk_check
                ];
            }

            clean_sweep_log_message("BackupManager: Disk space check passed: {$disk_check['backup_size_mb']}MB backup, {$disk_check['available_mb']}MB available", 'info');

            // Return backup choice UI
            if ($progress_file) {
                $progress_data = [
                    'status' => 'backup_choice',
                    'progress' => 0,
                    'message' => 'Choose backup option',
                    'disk_check' => $disk_check
                ];
                clean_sweep_write_progress_file($progress_file, $progress_data);

                clean_sweep_log_message("BackupManager: Backup choice UI sent to client", 'info');
                    return ['disk_check' => $disk_check];
            }

            // For direct requests, return the data
            return [
                'success' => true,
                'backup_choice' => $disk_check
            ];

        } catch (Exception $e) {
            clean_sweep_log_message("BackupManager: Exception in get_backup_choice: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process backup choice made by user
     *
     * @param bool $create_backup Whether to create backup
     * @param bool $proceed_without_backup Whether to proceed without backup
     * @return array Result of backup processing
     */
    public function process_backup_choice($create_backup, $proceed_without_backup) {
        clean_sweep_log_message("BackupManager: Processing backup choice - create: " . ($create_backup ? 'YES' : 'NO') . ", skip: " . ($proceed_without_backup ? 'YES' : 'NO'), 'info');

        try {
            if ($create_backup) {
                clean_sweep_log_message("BackupManager: User requested backup creation", 'info');

                // Check disk space before creating backup
                $disk_check = clean_sweep_check_disk_space('plugin_reinstall');
                if (!$disk_check['success']) {
                    clean_sweep_log_message("BackupManager: Disk space check failed: {$disk_check['message']}", 'error');
                    return [
                        'success' => false,
                        'error' => 'Insufficient disk space for backup',
                        'disk_check' => $disk_check
                    ];
                }

                clean_sweep_log_message("BackupManager: Creating backup...", 'info');
                $backup_result = clean_sweep_create_backup();

                if (!$backup_result) {
                    clean_sweep_log_message("BackupManager: Backup creation failed", 'error');
                    return [
                        'success' => false,
                        'error' => 'Backup creation failed'
                    ];
                }

                clean_sweep_log_message("BackupManager: Backup created successfully", 'info');
                return [
                    'success' => true,
                    'backup_created' => true
                ];

            } elseif ($proceed_without_backup) {
                clean_sweep_log_message("BackupManager: User chose to proceed without backup", 'warning');
                return [
                    'success' => true,
                    'backup_created' => false,
                    'proceeded_without_backup' => true
                ];

            } else {
                clean_sweep_log_message("BackupManager: Invalid backup choice", 'error');
                return [
                    'success' => false,
                    'error' => 'Invalid backup choice'
                ];
            }

        } catch (Exception $e) {
            clean_sweep_log_message("BackupManager: Exception in process_backup_choice: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get backup size information without creating UI
     *
     * @return array Backup size information
     */
    public function get_backup_size_info() {
        clean_sweep_log_message("BackupManager: Getting backup size information", 'info');

        $disk_check = clean_sweep_check_disk_space('plugin_reinstall');

        return [
            'success' => $disk_check['success'],
            'backup_size_mb' => $disk_check['backup_size_mb'] ?? 0,
            'required_mb' => $disk_check['required_mb'] ?? 0,
            'available_mb' => $disk_check['available_mb'] ?? 0,
            'shortfall_mb' => $disk_check['shortfall_mb'] ?? 0,
            'message' => $disk_check['message'] ?? '',
            'warning' => $disk_check['warning'] ?? '',
            'space_status' => $disk_check['space_status'] ?? 'unknown'
        ];
    }

    /**
     * Check if backup is recommended based on current plugins
     *
     * @return bool Whether backup is recommended
     */
    public function is_backup_recommended() {
        $size_info = $this->get_backup_size_info();

        // Recommend backup if:
        // 1. We have plugins to reinstall (> 0 plugins)
        // 2. Sufficient space is available
        // 3. Backup size is reasonable (< 500MB)

        return $size_info['success'] &&
               $size_info['backup_size_mb'] > 0 &&
               $size_info['backup_size_mb'] < 500;
    }
}
