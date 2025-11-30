<?php
/**
 * Clean Sweep - Plugin Reinstallation Feature
 *
 * Main orchestration for plugin analysis, backup, and reinstallation
 * Functions are now split into focused modules for better organization
 */

// Load utilities first (no dependencies)
require_once __DIR__ . '/lib/WpmuDevUtils.php';

// Load new OOP architecture
require_once __DIR__ . '/lib/PluginReinstallationManager.php';
require_once __DIR__ . '/lib/PluginAnalyzer.php';
require_once __DIR__ . '/lib/BackupManager.php';
require_once __DIR__ . '/lib/PluginReinstaller.php';

// Load legacy plugin management modules (for compatibility)
require_once __DIR__ . '/plugin-utils.php';
require_once __DIR__ . '/plugin-backup.php';
require_once __DIR__ . '/plugin-wordpress.php';

/**
 * Analyze and categorize all installed plugins for reinstallation
 * Returns arrays categorized by whether they should be handled by WPMU DEV or WordPress.org
 *
 * LEGACY WRAPPER: Now delegates to the new OOP PluginAnalyzer
 */
function clean_sweep_analyze_plugins($progress_file = null) {
    $manager = new CleanSweep_PluginReinstallationManager();
    $result = $manager->handle_request('analyze_plugins', [
        'progress_file' => $progress_file
    ]);

    // Return full new format data to enable all UI features
    if ($result['success']) {
        return $result; // Include non_repo_plugins, suspicious_files, etc.
    }

    // Return empty arrays on failure
    return [
        'wp_org_plugins' => [],
        'wpmu_dev_plugins' => [],
        'skipped' => []
    ];
}

/**
 * Verify that plugins are actually installed after re-installation
 */
function clean_sweep_verify_installations($expected_plugins) {
    clean_sweep_log_message("Performing final verification of installed plugins...");

    $verification_results = [
        'verified' => [],
        'missing' => [],
        'corrupted' => []
    ];

    // Get current plugins from WordPress
    $current_plugins = get_plugins();

    foreach ($expected_plugins as $plugin_key => $plugin_data) {
        // For WPMU DEV plugins, $plugin_key is the filename like "google-analytics-async/google-analytics-async.php"
        // For WordPress.org plugins, $plugin_key is typically the slug like "wp-file-manager"
        $plugin_slug = $plugin_data['slug'] ?? $plugin_key;
        $plugin_name = $plugin_data['name'] ?? $plugin_key;
        $plugin_found = false;
        $plugin_corrupted = false;

        // Check if plugin exists in current plugins list
        foreach ($current_plugins as $plugin_file => $current_plugin_data) {
            // Use same slug detection logic as in analyze_plugins
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
                $current_slug = pathinfo($plugin_file, PATHINFO_FILENAME);
            } else {
                $current_slug = basename($plugin_dir);
            }

            if ($current_slug === $plugin_slug) {  // FIXED: Use proper slug from data
                $plugin_found = true;

                // Verify plugin files exist and are readable
                if ($plugin_dir === '.' || $plugin_dir === '') {
                    // Plugin is in root directory
                    $main_file = WP_PLUGIN_DIR . '/' . $plugin_file;
                    if (!file_exists($main_file) || !is_readable($main_file)) {
                        $plugin_corrupted = true;
                    }
                } else {
                    // Plugin is in subdirectory
                    $plugin_dir_path = WP_PLUGIN_DIR . '/' . $plugin_slug;  // FIXED: Use proper slug
                    if (is_dir($plugin_dir_path)) {
                        // Check for main plugin file
                        $main_file = $plugin_dir_path . '/' . basename($plugin_file);
                        if (!file_exists($main_file) || !is_readable($main_file)) {
                            $plugin_corrupted = true;
                        }
                    } else {
                        $plugin_corrupted = true;
                    }
                }
                break;
            }
        }

        if ($plugin_found && !$plugin_corrupted) {
            $verification_results['verified'][] = [
                'name' => $plugin_name,
                'slug' => $slug,
                'status' => 'Installed and verified'
            ];
        } elseif ($plugin_corrupted) {
            $verification_results['corrupted'][] = [
                'name' => $plugin_name,
                'slug' => $slug,
                'status' => 'Corrupted or incomplete installation'
            ];
        } else {
            $verification_results['missing'][] = [
                'name' => $plugin_name,
                'slug' => $slug,
                'status' => 'Not found in plugins directory'
            ];
        }
    }

    clean_sweep_log_message("Verification completed. Verified: " . count($verification_results['verified']) .
                ", Missing: " . count($verification_results['missing']) .
                ", Corrupted: " . count($verification_results['corrupted']));

    return $verification_results;
}

/**
 * Verify WPMU DEV plugin installations
 * Uses plugin filenames instead of slugs
 */
function clean_sweep_verify_wpmudev_installations($wpmudev_plugins) {
    clean_sweep_log_message("Performing WPMU DEV plugin verification...");

    $verification_results = [
        'verified' => [],
        'missing' => [],
        'corrupted' => []
    ];

    // Get current plugins from WordPress
    $current_plugins = get_plugins();

    foreach ($wpmudev_plugins as $plugin_file => $plugin_data) {
        $plugin_name = $plugin_data['name'] ?? $plugin_file;
        $plugin_found = false;
        $plugin_corrupted = false;

        // Check if plugin exists in current plugins list using exact filename
        if (isset($current_plugins[$plugin_file])) {
            $plugin_found = true;

            // Verify the plugin file actually exists and is readable
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if (!file_exists($plugin_path) || !is_readable($plugin_path)) {
                $plugin_corrupted = true;
            }
        }

        if ($plugin_found && !$plugin_corrupted) {
            $verification_results['verified'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Installed and verified (WPMU DEV)'
            ];
            clean_sweep_log_message("WPMU DEV plugin verified: {$plugin_name} ({$plugin_file})");
        } elseif ($plugin_corrupted) {
            $verification_results['corrupted'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Corrupted or incomplete installation'
            ];
            clean_sweep_log_message("WPMU DEV plugin corrupted: {$plugin_name} ({$plugin_file})", 'warning');
        } else {
            $verification_results['missing'][] = [
                'name' => $plugin_name,
                'slug' => $plugin_file,
                'status' => 'Not found in plugins directory'
            ];
            clean_sweep_log_message("WPMU DEV plugin missing: {$plugin_name} ({$plugin_file})", 'warning');
        }
    }

    clean_sweep_log_message("WPMU DEV verification completed. Verified: " . count($verification_results['verified']) .
                ", Missing: " . count($verification_results['missing']) .
                ", Corrupted: " . count($verification_results['corrupted']));

    return $verification_results;
}

/**
 * Execute plugin reinstallation
 *
 * LEGACY WRAPPER: Now delegates to the new OOP PluginReinstallationManager
 */
function clean_sweep_execute_reinstallation($repo_plugins, $progress_file = null, $batch_start = 0, $batch_size = null, $wpmu_dev_plugins = null, $suspicious_files_to_delete = null) {
    // Check backup creation preferences from POST data
    $create_backup = isset($_POST['create_backup']) && $_POST['create_backup'] === '1';
    $proceed_without_backup = isset($_POST['proceed_without_backup']) && $_POST['proceed_without_backup'] === '1';

    // For the new architecture, we need to handle different actions
    $manager = new CleanSweep_PluginReinstallationManager();

    // If this is the first batch and no backup choice has been made, show backup UI
    if ($batch_start === 0 && !$create_backup && !$proceed_without_backup) {
        // Show backup choice UI
        $result = $manager->handle_request('get_backup_choice', [
            'progress_file' => $progress_file
        ]);
        return $result;
    }

    // Otherwise proceed with reinstallation
    $result = $manager->handle_request('start_reinstallation', [
        'progress_file' => $progress_file,
        'create_backup' => $create_backup,
        'proceed_without_backup' => $proceed_without_backup,
        'wp_org_plugins' => $repo_plugins,  // Pass WordPress.org plugins explicitly
        'wpmu_dev_plugins' => $wpmu_dev_plugins,  // Pass WPMU DEV plugins explicitly
        'suspicious_files_to_delete' => $suspicious_files_to_delete,  // Pass suspicious files to delete
        'batch_start' => $batch_start,
        'batch_size' => $batch_size
    ]);

    // Convert new format to legacy format for backward compatibility
    if (isset($result['results'])) {
        return [
            'results' => $result['results'],
            'verification_results' => $result['verification_results'] ?? ['verified' => [], 'missing' => [], 'corrupted' => []]
        ];
    }

    return $result;
}
