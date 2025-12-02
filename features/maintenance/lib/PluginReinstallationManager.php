<?php
/**
 * Clean Sweep - Plugin Reinstallation Manager
 *
 * Main orchestrator for the complete plugin reinstallation workflow:
 * Analysis → Backup Choice → Reinstallation + Cleanup
 *
 * This class coordinates between specialized components to provide
 * a clean, maintainable, and extensible plugin management system.
 */

class CleanSweep_PluginReinstallationManager {

    /**
     * @var CleanSweep_PluginAnalyzer
     */
    private $analyzer;

    /**
     * @var CleanSweep_BackupManager
     */
    private $backup_manager;

    /**
     * @var CleanSweep_PluginReinstaller
     */
    private $reinstaller;

    /**
     * Constructor - Initialize all components
     */
    public function __construct() {
        $this->analyzer = new CleanSweep_PluginAnalyzer();
        $this->backup_manager = new CleanSweep_BackupManager();
        $this->reinstaller = new CleanSweep_PluginReinstaller();
    }

    /**
     * Main request handler for plugin reinstallation workflow
     *
     * @param string $action The action to perform
     * @param array $params Parameters for the action
     * @return array Response data
     */
    public function handle_request($action, $params = []) {
        clean_sweep_log_message("PluginReinstallationManager: Handling action '{$action}'", 'info');

        try {
            switch ($action) {
                case 'analyze_plugins':
                    return $this->handle_analyze_plugins($params);

                case 'get_backup_choice':
                    return $this->handle_get_backup_choice($params);

                case 'start_reinstallation':
                    return $this->handle_start_reinstallation($params);

                default:
                    clean_sweep_log_message("PluginReinstallationManager: Unknown action '{$action}'", 'warning');
                    return [
                        'success' => false,
                        'error' => 'Unknown action: ' . $action
                    ];
            }
        } catch (Exception $e) {
            clean_sweep_log_message("PluginReinstallationManager: Exception in action '{$action}': " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Handle plugin analysis request
     *
     * @param array $params
     * @return array
     */
    private function handle_analyze_plugins($params) {
        clean_sweep_log_message("PluginReinstallationManager: Starting plugin analysis", 'info');

        $progress_file = $params['progress_file'] ?? null;
        $analysis_result = $this->analyzer->analyze($progress_file);

        if ($analysis_result['success']) {
            clean_sweep_log_message("PluginReinstallationManager: Analysis completed successfully", 'info');
        } else {
            clean_sweep_log_message("PluginReinstallationManager: Analysis failed: " . ($analysis_result['error'] ?? 'Unknown error'), 'error');
        }

        return $analysis_result;
    }

    /**
     * Handle backup choice request
     *
     * @param array $params
     * @return array
     */
    private function handle_get_backup_choice($params) {
        clean_sweep_log_message("PluginReinstallationManager: Getting backup choice", 'info');

        $progress_file = $params['progress_file'] ?? null;
        $backup_choice_result = $this->backup_manager->get_backup_choice($progress_file);

        if (isset($backup_choice_result['backup_choice'])) {
            clean_sweep_log_message("PluginReinstallationManager: Backup choice UI returned", 'info');
        } elseif (isset($backup_choice_result['disk_space_warning'])) {
            clean_sweep_log_message("PluginReinstallationManager: Disk space warning returned", 'warning');
        } else {
            clean_sweep_log_message("PluginReinstallationManager: Unexpected backup choice response", 'warning');
        }

        return $backup_choice_result;
    }

    /**
     * Handle reinstallation request
     *
     * @param array $params
     * @return array
     */
    private function handle_start_reinstallation($params) {
        clean_sweep_log_message("PluginReinstallationManager: Starting reinstallation", 'info');

        $progress_file = $params['progress_file'] ?? null;
        $create_backup = $params['create_backup'] ?? false;
        $proceed_without_backup = $params['proceed_without_backup'] ?? false;
        $wp_org_plugins = $params['wp_org_plugins'] ?? [];
        $wpmu_dev_plugins = $params['wpmu_dev_plugins'] ?? [];
        $suspicious_files_to_delete = $params['suspicious_files_to_delete'] ?? [];
        $batch_start = $params['batch_start'] ?? 0;
        $batch_size = $params['batch_size'] ?? null;

        $reinstall_result = $this->reinstaller->start_reinstallation(
            $progress_file,
            $create_backup,
            $proceed_without_backup,
            $wp_org_plugins,
            $wpmu_dev_plugins,
            $suspicious_files_to_delete,
            $batch_start,
            $batch_size
        );

        if ($reinstall_result['success']) {
            clean_sweep_log_message("PluginReinstallationManager: Reinstallation completed successfully", 'info');
        } else {
            clean_sweep_log_message("PluginReinstallationManager: Reinstallation failed: " . ($reinstall_result['error'] ?? 'Unknown error'), 'error');
        }

        return $reinstall_result;
    }

    /**
     * Get analyzer instance for direct access if needed
     *
     * @return CleanSweep_PluginAnalyzer
     */
    public function get_analyzer() {
        return $this->analyzer;
    }

    /**
     * Get backup manager instance for direct access if needed
     *
     * @return CleanSweep_BackupManager
     */
    public function get_backup_manager() {
        return $this->backup_manager;
    }

    /**
     * Get reinstaller instance for direct access if needed
     *
     * @return CleanSweep_PluginReinstaller
     */
    public function get_reinstaller() {
        return $this->reinstaller;
    }
}
