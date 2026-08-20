<?php
/**
 * Clean Sweep - Plugin Management API Endpoint
 *
 * JSON API for plugin analysis and reinstallation operations.
 * Returns JSON responses for Alpine.js frontend consumption.
 *
 * @version 1.0
 */

// Include unified bootstrap for CORS headers, OPTIONS handling, and error management
require_once __DIR__ . '/bootstrap.php';

// ============================================================================
// INCLUDES - Minimal set for plugin operations
// ============================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../includes/system/CleanSweep_Functions.php';

// Load recovery bootstrap for WordPress environment
require_once __DIR__ . '/../includes/system/CleanSweep_FreshEnvironment.php';
require_once __DIR__ . '/../includes/system/CleanSweep_RecoveryBootstrap.php';

// Load plugin functionality
require_once __DIR__ . '/../features/maintenance/plugin-reinstall.php';

// ============================================================================
// BOOTSTRAP WORDPRESS (Minimal)
// ============================================================================

// Check if WordPress is already loaded (from bootstrap.php)
if (defined('ABSPATH') && function_exists('get_bloginfo')) {
    clean_sweep_log_message("API: WordPress already loaded via bootstrap, skipping FreshEnvironment", 'info');
    
    // Ensure global functions object exists
    global $clean_sweep_functions;
    if (!isset($clean_sweep_functions)) {
        $clean_sweep_functions = new CleanSweep_Functions(null);
    }
} else {
    // WordPress not loaded yet - use recovery bootstrap
    $bootstrap = new CleanSweep_RecoveryBootstrap(true);
    if (!$bootstrap->initialize()) {
        CleanSweep_ApiResponse::sendError('Failed to initialize WordPress environment', 'BOOTSTRAP_ERROR');
    }
}

// Verify security nonce if in recovery mode
if (function_exists('clean_sweep_verify_recovery_nonce')) {
    clean_sweep_verify_recovery_nonce();
}

// ============================================================================
// ROUTE REQUEST
// ============================================================================

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'analyze_plugins':
        clean_sweep_handle_analyze_plugins();
        break;
        
    case 'reinstall_plugins':
        if (function_exists('clean_sweep_require_toolkit_ok')) {
            clean_sweep_require_toolkit_ok();
        }
        clean_sweep_handle_reinstall_plugins();
        break;
    
    case 'create_plugin_backup':
        clean_sweep_handle_create_plugin_backup();
        break;
        
    case 'get_progress':
        clean_sweep_handle_get_progress();
        break;
        
    default:
        CleanSweep_ApiResponse::sendError('Unknown action: ' . $action, 'UNKNOWN_ACTION');
}

// ============================================================================
// HANDLER FUNCTIONS
// ============================================================================

/**
 * Handle plugin analysis request
 */
function clean_sweep_handle_analyze_plugins() {
    $force_refresh = isset($_POST['force_refresh']) ? (bool)$_POST['force_refresh'] : false;
    
    // Generate unique progress file
    $progress_file = 'plugin_analyze_' . time() . '.progress';
    
    clean_sweep_log_message("API: Starting plugin analysis (force_refresh: " . ($force_refresh ? 'true' : 'false') . ")");
    
    // Progress callback
    $progress_callback = function($current, $total, $message) use ($progress_file) {
        if ($progress_file) {
            $progress_data = [
                'status' => 'analyzing',
                'progress' => $total > 0 ? round(($current / $total) * 100) : 0,
                'message' => $message,
                'current' => $current,
                'total' => $total,
                'timestamp' => time()
            ];
            @clean_sweep_write_progress_file($progress_file, $progress_data);
        }
    };
    
    try {
        // Run plugin analysis
        $results = clean_sweep_analyze_plugins($progress_file, $force_refresh);
        
        // Write final progress
        $final_progress = [
            'status' => 'complete',
            'progress' => 100,
            'message' => 'Analysis complete',
            'timestamp' => time()
        ];
        @clean_sweep_write_progress_file($progress_file, $final_progress);
        
        // Process results for frontend
        $processed = clean_sweep_process_plugin_results($results);
        $processed = clean_sweep_annotate_plugins_with_checksums($processed);
        
        CleanSweep_ApiResponse::sendSuccess([
            'results' => $processed,
            'progress_file' => $progress_file
        ], 'Plugin analysis completed');
        
    } catch (Exception $e) {
        // Write error progress
        $error_progress = [
            'status' => 'error',
            'progress' => 0,
            'message' => $e->getMessage(),
            'timestamp' => time()
        ];
        @clean_sweep_write_progress_file($progress_file, $error_progress);
        
        CleanSweep_ApiResponse::sendError('Analysis failed: ' . $e->getMessage(), 'ANALYSIS_ERROR', [
            'progress_file' => $progress_file
        ]);
    }
}

/**
 * Handle plugin reinstallation request
 */
function clean_sweep_handle_reinstall_plugins() {
    // Parse plugin selections from POST
    $repo_plugins = isset($_POST['repo_plugins']) ? (json_decode(stripslashes($_POST['repo_plugins']), true) ?? []) : [];
    $wpmu_dev_plugins = isset($_POST['wpmu_dev_plugins']) ? (json_decode(stripslashes($_POST['wpmu_dev_plugins']), true) ?? []) : [];
    $suspicious_files = isset($_POST['suspicious_files']) ? (json_decode(stripslashes($_POST['suspicious_files']), true) ?? []) : [];
    
    // Extract batch processing parameters
    $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : null;
    $batch_start = isset($_POST['batch_start']) ? (int)$_POST['batch_start'] : 0;
    $batch_size = isset($_POST['batch_size']) ? (int)$_POST['batch_size'] : 5;
    $create_backup = function_exists('clean_sweep_request_flag')
        ? clean_sweep_request_flag('create_backup', true)
        : (isset($_POST['create_backup']) && (string) $_POST['create_backup'] === '1');
    
    clean_sweep_log_message("API: Starting plugin reinstallation");
    clean_sweep_log_message("  - Repo plugins: " . count($repo_plugins));
    clean_sweep_log_message("  - WPMU DEV plugins: " . count($wpmu_dev_plugins));
    clean_sweep_log_message("  - WPMU DEV plugins content: " . json_encode($wpmu_dev_plugins));
    clean_sweep_log_message("  - Suspicious files received: " . count($suspicious_files));
    clean_sweep_log_message("  - Suspicious files content: " . json_encode($suspicious_files));
    clean_sweep_log_message("  - Create backup: " . ($create_backup ? 'yes' : 'no'));
    
    // Progress callback
    $progress_callback = function($current, $total, $message) use ($progress_file) {
        if ($progress_file) {
            $progress_data = [
                'status' => 'reinstalling',
                'progress' => $total > 0 ? round(($current / $total) * 100) : 0,
                'message' => $message,
                'current' => $current,
                'total' => $total,
                'timestamp' => time()
            ];
            @clean_sweep_write_progress_file($progress_file, $progress_data);
        }
    };
    
    try {
        // Run plugin reinstallation - pass repo, wpmu_dev plugins, and selected suspicious files
        $results = clean_sweep_execute_reinstallation(
            $repo_plugins,
            $progress_file,
            $batch_start,
            $batch_size,
            $create_backup,
            $wpmu_dev_plugins,
            $suspicious_files
        );
        
        // Determine if this is the final batch - FIX: access correct nesting level
        $inner_results = $results['results'] ?? $results;
        $is_final_batch = empty($inner_results['batch_info']['has_more_batches']);
        $combined_total = count($repo_plugins) + count($wpmu_dev_plugins);
        $wpmu_failed = count($inner_results['wpmu_dev']['failed'] ?? []);
        $wp_org_failed = count($inner_results['wordpress_org']['failed'] ?? []);
        $total_failed = $wpmu_failed + $wp_org_failed;
        $partial = !empty($inner_results['partial']) || $total_failed > 0;

        // Do not overwrite ProgressManager completion payload with a bare "complete"
        // that drops per-plugin results. Only write a minimal complete marker when
        // the reinstaller did not already mark completion (safety net).
        if ($is_final_batch && $progress_file) {
            $logs_dir = defined('CLEAN_SWEEP_LOGS_DIR') ? CLEAN_SWEEP_LOGS_DIR : (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT . 'logs' : __DIR__ . '/../logs');
            $progress_path = rtrim($logs_dir, '/\\') . '/' . basename($progress_file);
            $existing = is_file($progress_path) ? json_decode((string) @file_get_contents($progress_path), true) : null;
            $already_complete = is_array($existing) && (($existing['status'] ?? '') === 'complete');
            if (!$already_complete) {
                $final_progress = [
                    'status' => 'complete',
                    'progress' => 100,
                    'message' => $partial
                        ? 'Reinstallation finished with failures'
                        : 'Reinstallation complete',
                    'partial' => $partial,
                    'timestamp' => time()
                ];
                @clean_sweep_write_progress_file($progress_file, $final_progress);
            }
        }

        $complete_message = $partial
            ? 'Plugin reinstallation finished with some failures'
            : 'Plugin reinstallation completed';

        CleanSweep_ApiResponse::sendSuccess([
            'results' => $results['results'] ?? $results,
            'verification_results' => $results['verification_results'] ?? null,
            'progress_file' => $progress_file,
            'batch_info' => $inner_results['batch_info'] ?? null,
            'partial' => $partial,
            'progress' => [
                'current' => $inner_results['batch_info']['processed'] ?? 0,
                'total' => $inner_results['batch_info']['total'] ?? $combined_total,
                'status' => ($inner_results['batch_info']['has_more_batches'] ?? false) ? 'batch_complete' : 'complete',
                'message' => ($inner_results['batch_info']['has_more_batches'] ?? false)
                    ? 'Batch complete, processing next batch...'
                    : ($partial ? 'Reinstallation finished with failures' : 'Reinstallation complete'),
                'partial' => $partial,
            ]
        ], $complete_message);
        
    } catch (Exception $e) {
        // Write error progress
        $error_progress = [
            'status' => 'error',
            'progress' => 0,
            'message' => $e->getMessage(),
            'timestamp' => time()
        ];
        @clean_sweep_write_progress_file($progress_file, $error_progress);
        
        CleanSweep_ApiResponse::sendError('Reinstallation failed: ' . $e->getMessage(), 'REINSTALL_ERROR', [
            'progress_file' => $progress_file
        ]);
    }
}

/**
 * Handle get progress request
 */
function clean_sweep_handle_get_progress() {
    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    $progress_file = $_POST['progress_file'] ?? $_GET['progress_file'] ?? '';
    
    if (empty($progress_file)) {
        CleanSweep_ApiResponse::sendError('Progress file not specified', 'MISSING_PROGRESS_FILE');
    }
    
    // Sanitize filename
    $progress_file = basename($progress_file);
    if (substr($progress_file, -9) !== '.progress') {
        $progress_file .= '.progress';
    }
    $progress_path = CLEAN_SWEEP_PROGRESS_DIR . $progress_file;
    
    if (!file_exists($progress_path)) {
        CleanSweep_ApiResponse::sendSuccess([
            'status' => 'pending',
            'progress' => 0,
            'message' => 'Operation starting, progress file not yet created'
        ]);
    }
    
    $progress_data = @file_get_contents($progress_path);
    $progress = $progress_data ? json_decode($progress_data, true) : null;
    
    if (!$progress) {
        CleanSweep_ApiResponse::sendSuccess([
            'status' => 'pending',
            'progress' => 0,
            'message' => 'Progress data not available yet'
        ]);
    }
    
    CleanSweep_ApiResponse::sendSuccess($progress);
}

/**
 * Flatten plugin map rows for the frontend while preserving plugin_file.
 *
 * Analyzer keys plugins by main file (e.g. wp-smush-pro/wp-smush.php).
 * array_values alone drops that key; reinstall needs it for deactivate/reactivate.
 *
 * @param array $plugins
 * @return array<int, array>
 */
function clean_sweep_normalize_plugin_rows($plugins) {
    if (!is_array($plugins) || empty($plugins)) {
        return [];
    }

    $rows = [];
    foreach ($plugins as $key => $plugin_data) {
        if (!is_array($plugin_data)) {
            continue;
        }
        $row = $plugin_data;
        $key_str = is_string($key) ? str_replace('\\', '/', $key) : '';

        if (empty($row['plugin_file']) && $key_str !== '' && (strpos($key_str, '/') !== false || substr($key_str, -4) === '.php')) {
            $row['plugin_file'] = $key_str;
        } elseif (empty($row['plugin_file']) && !empty($row['file'])) {
            $row['plugin_file'] = $row['file'];
        }

        if (empty($row['slug'])) {
            if ($key_str !== '' && strpos($key_str, '/') !== false) {
                $row['slug'] = dirname($key_str);
            } elseif ($key_str !== '') {
                $row['slug'] = pathinfo($key_str, PATHINFO_FILENAME);
            } elseif (!empty($row['plugin_file'])) {
                $pf = str_replace('\\', '/', (string) $row['plugin_file']);
                $row['slug'] = (strpos($pf, '/') !== false) ? dirname($pf) : pathinfo($pf, PATHINFO_FILENAME);
            }
        }

        $rows[] = $row;
    }

    return $rows;
}

/**
 * Process plugin analysis results for frontend consumption
 */
function clean_sweep_process_plugin_results($results) {
    if (!$results) {
        return null;
    }
    
    $processed = [
        'summary' => [],
        'wp_org_plugins' => [],
        'wpmu_dev_plugins' => [],
        'non_repo_plugins' => [],
        'likely_fake_plugins' => [],
        'suspicious_files' => [],
        'total_plugins' => 0,
        'needs_reinstall' => false,
        'wpmu_dev_available' => true
    ];
    
    // Process WordPress.org plugins (preserve plugin_file from map keys)
    if (!empty($results['wp_org_plugins'])) {
        $processed['wp_org_plugins'] = clean_sweep_normalize_plugin_rows($results['wp_org_plugins']);
        $processed['needs_reinstall'] = true;
    }
    
    // Process WPMU DEV plugins (plugin_file required for deactivate/reactivate)
    if (!empty($results['wpmu_dev_plugins'])) {
        $processed['wpmu_dev_plugins'] = clean_sweep_normalize_plugin_rows($results['wpmu_dev_plugins']);
        $processed['needs_reinstall'] = true;
    }
    
    // Process non-repo plugins
    if (!empty($results['non_repo_plugins'])) {
        $processed['non_repo_plugins'] = clean_sweep_normalize_plugin_rows($results['non_repo_plugins']);
    }

    if (!empty($results['likely_fake_plugins'])) {
        $processed['likely_fake_plugins'] = clean_sweep_normalize_plugin_rows($results['likely_fake_plugins']);
    }
    
    // Process suspicious files
    if (!empty($results['suspicious_files'])) {
        $processed['suspicious_files'] = array_values($results['suspicious_files']);
    }
    
    // Calculate totals
    $processed['total_plugins'] = count($processed['wp_org_plugins']) + 
                                  count($processed['wpmu_dev_plugins']) + 
                                  count($processed['non_repo_plugins']) +
                                  count($processed['likely_fake_plugins']);
    
    // Summary
    $processed['summary'] = [
        'wp_org_count' => count($processed['wp_org_plugins']),
        'wpmu_dev_count' => count($processed['wpmu_dev_plugins']),
        'non_repo_count' => count($processed['non_repo_plugins']),
        'likely_fake_count' => count($processed['likely_fake_plugins']),
        'suspicious_count' => count($processed['suspicious_files']),
        'total' => $processed['total_plugins'],
        'needs_reinstall' => $processed['needs_reinstall']
    ];
    
    // Pass through WPMU DEV availability status
    $processed['wpmu_dev_available'] = isset($results['wpmu_dev_available']) ? (bool)$results['wpmu_dev_available'] : true;

    $idf = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__) . '/')
        . 'features/maintenance/lib/PackageIdentity.php';
    if (!class_exists('CleanSweep_PackageIdentity', false) && is_readable($idf)) {
        require_once $idf;
    }
    if (class_exists('CleanSweep_PackageIdentity', false)) {
        $processed['identity_summary'] = CleanSweep_PackageIdentity::summary();
    }
    
    return $processed;
}

/**
 * Attach last scan's wordpress.org checksum status to each plugin row.
 */
function clean_sweep_annotate_plugins_with_checksums(array $processed): array {
    $file = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__) . '/') . 'features/security/scan/PackageChecksums.php';
    if (is_readable($file)) {
        require_once $file;
    }
    $latest = class_exists('CleanSweep_PackageChecksums', false) ? CleanSweep_PackageChecksums::load_latest() : [];
    foreach (['wp_org_plugins', 'wpmu_dev_plugins', 'non_repo_plugins', 'likely_fake_plugins'] as $key) {
        if (empty($processed[$key]) || !is_array($processed[$key])) {
            continue;
        }
        foreach ($processed[$key] as &$plugin) {
            $slug = (string) ($plugin['slug'] ?? '');
            $row = $latest['plugin:' . $slug] ?? null;
            $plugin['checksum_status'] = $row['status'] ?? null;
            $plugin['checksum_outcome'] = $row['outcome'] ?? null;
            $plugin['checksum_note'] = $row['note'] ?? null;
            $plugin['checksum_findings'] = $row['finding_count'] ?? 0;
            $plugin['checksum_annotations'] = $row['annotations'] ?? null;
            $plugin['checksum_baseline_used'] = !empty($row['baseline_used']);
            if (($row['outcome'] ?? '') === 'divergent' && empty($plugin['checksum_note'])) {
                $plugin['checksum_note'] = 'bulk divergence from wordpress.org free package';
            }
            if (strpos((string) ($row['outcome'] ?? ''), 'baseline') === 0 && empty($plugin['checksum_note'])) {
                $plugin['checksum_note'] = (string) ($row['note'] ?? 'verification baseline');
            }
        }
        unset($plugin);
    }
    return $processed;
}

/**
 * Handle create plugin backup request
 */
function clean_sweep_handle_create_plugin_backup() {
    clean_sweep_log_message("API: Creating plugin backup");
    
    try {
        $result = clean_sweep_create_backup();
        
        if ($result) {
            clean_sweep_log_message("API: Plugin backup created successfully: $result");
            CleanSweep_ApiResponse::sendSuccess([
                'backup_path' => $result,
                'backup_filename' => basename($result)
            ], 'Plugin backup created successfully');
        } else {
            clean_sweep_log_message("API: Plugin backup failed", 'error');
            CleanSweep_ApiResponse::sendError('Failed to create plugin backup', 'BACKUP_ERROR');
        }
    } catch (Exception $e) {
        clean_sweep_log_message("API: Plugin backup exception: " . $e->getMessage(), 'error');
        CleanSweep_ApiResponse::sendError('Backup error: ' . $e->getMessage(), 'BACKUP_EXCEPTION');
    }
}
