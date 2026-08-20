<?php
/**
 * Clean Sweep - Theme Management API Endpoint
 *
 * JSON API for theme analysis and reinstallation operations.
 * Returns JSON responses for Svelte frontend consumption.
 *
 * @version 1.0
 */

// Include unified bootstrap for CORS headers, OPTIONS handling, and error management
require_once __DIR__ . '/bootstrap.php';

// ============================================================================
// INCLUDES - Minimal set for theme operations
// ============================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../includes/system/CleanSweep_Functions.php';

// Load recovery bootstrap for WordPress environment
require_once __DIR__ . '/../includes/system/CleanSweep_FreshEnvironment.php';
require_once __DIR__ . '/../includes/system/CleanSweep_RecoveryBootstrap.php';

// Load batch processing classes
require_once __DIR__ . '/../includes/system/batch-processing/CleanSweep_BatchProcessingException.php';
require_once __DIR__ . '/../includes/system/batch-processing/CleanSweep_BatchProcessor.php';
require_once __DIR__ . '/../includes/system/batch-processing/CleanSweep_ProgressManager.php';

// Load theme analysis and reinstall functionality
require_once __DIR__ . '/../features/maintenance/lib/SuspiciousItemAnalyzer.php';
require_once __DIR__ . '/../features/maintenance/plugin-utils.php';
require_once __DIR__ . '/../features/maintenance/lib/ThemeAnalyzer.php';
require_once __DIR__ . '/../features/maintenance/lib/ThemeReinstaller.php';

// Load plugin backup functionality (for theme backup function)
require_once __DIR__ . '/../features/maintenance/plugin-backup.php';

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
    case 'analyze_themes':
        clean_sweep_handle_analyze_themes();
        break;
        
    case 'reinstall_themes':
        if (function_exists('clean_sweep_require_toolkit_ok')) {
            clean_sweep_require_toolkit_ok();
        }
        clean_sweep_handle_reinstall_themes();
        break;
    
    case 'create_theme_backup':
        clean_sweep_handle_create_theme_backup();
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
 * Handle theme analysis request
 */
function clean_sweep_handle_analyze_themes() {
    $force_refresh = isset($_POST['force_refresh']) ? (bool)$_POST['force_refresh'] : false;
    
    clean_sweep_log_message("API: Starting theme analysis (force_refresh: " . ($force_refresh ? 'true' : 'false') . ")");
    
    try {
        // Use the advanced ThemeAnalyzer class
        $analyzer = new CleanSweep_ThemeAnalyzer();
        $result = $analyzer->analyze();
        
        if (!$result['success']) {
            clean_sweep_log_message("API: Theme analysis failed: " . ($result['error'] ?? 'Unknown error'), 'error');
            CleanSweep_ApiResponse::sendError('Analysis failed: ' . ($result['error'] ?? 'Unknown error'), 'ANALYSIS_ERROR');
            return;
        }
        
        // Process results for frontend
        $processed = clean_sweep_process_theme_results($result);
        $processed = clean_sweep_annotate_themes_with_checksums($processed);
        
        CleanSweep_ApiResponse::sendSuccess([
            'results' => $processed
        ], 'Theme analysis completed');
        
    } catch (Exception $e) {
        clean_sweep_log_message("API: Theme analysis failed: " . $e->getMessage(), 'error');
        CleanSweep_ApiResponse::sendError('Analysis failed: ' . $e->getMessage(), 'ANALYSIS_ERROR');
    }
}

/**
 * Handle theme reinstallation request
 */
function clean_sweep_handle_reinstall_themes() {
    // Parse theme selections from POST
    $repo_themes = isset($_POST['repo_themes']) ? (json_decode(stripslashes($_POST['repo_themes']), true) ?? []) : [];
    $suspicious_files = isset($_POST['suspicious_files']) ? (json_decode(stripslashes($_POST['suspicious_files']), true) ?? []) : [];
    
    // Extract batch processing parameters
    $progress_file = isset($_POST['progress_file']) ? $_POST['progress_file'] : null;
    $batch_start = isset($_POST['batch_start']) ? (int)$_POST['batch_start'] : 0;
    $batch_size = isset($_POST['batch_size']) ? (int)$_POST['batch_size'] : 5;
    $create_backup = function_exists('clean_sweep_request_flag')
        ? clean_sweep_request_flag('create_backup', true)
        : (isset($_POST['create_backup']) && (string) $_POST['create_backup'] === '1');
    
    clean_sweep_log_message("API: Starting theme reinstallation");
    clean_sweep_log_message("  - Repo themes: " . count($repo_themes));
    clean_sweep_log_message("  - Suspicious files to delete: " . count($suspicious_files));
    clean_sweep_log_message("  - Create backup: " . ($create_backup ? 'yes' : 'no'));
    clean_sweep_log_message("  - Batch start: $batch_start, batch size: $batch_size");
    
    // Create initial progress file for JavaScript polling
    if ($progress_file && $batch_start === 0) {
        $total_themes = count($repo_themes);
        $initial_progress_data = [
            'status' => 'initializing',
            'progress' => 0,
            'message' => 'Initializing theme re-installation...',
            'current' => 0,
            'total' => $total_themes,
            'batch_start' => $batch_start,
            'batch_size' => $batch_size,
            'has_more_batches' => ($batch_size && $batch_start + $batch_size < $total_themes) ? true : false
        ];
        @clean_sweep_write_progress_file($progress_file, $initial_progress_data);
        clean_sweep_log_message("API: Initial progress file created for JavaScript polling");
    }
    
    try {
        // Run theme reinstallation using the new ThemeReinstaller class
        $reinstaller = new CleanSweep_ThemeReinstaller();
        $results = $reinstaller->start_reinstallation(
            $progress_file,
            $create_backup,
            false, // proceed_without_backup
            $repo_themes,
            $suspicious_files,
            $batch_start,
            $batch_size
        );
        
        // Check if this is the final batch
        $inner_results = $results['results'] ?? $results;
        $is_final_batch = empty($inner_results['batch_info']['has_more_batches']);
        
        // Only write 'complete' to progress file on final batch
        if ($is_final_batch && $progress_file) {
            $final_progress = [
                'status' => 'complete',
                'progress' => 100,
                'message' => 'Reinstallation complete',
                'timestamp' => time()
            ];
            @clean_sweep_write_progress_file($progress_file, $final_progress);
        }
        
        // Return success with progress data for frontend
        $combined_total = count($repo_themes);
        CleanSweep_ApiResponse::sendSuccess([
            'results' => $results,
            'progress_file' => $progress_file,
            'batch_info' => $inner_results['batch_info'] ?? null,
            'progress' => [
                'current' => $inner_results['batch_info']['processed'] ?? 0,
                'total' => $inner_results['batch_info']['total'] ?? $combined_total,
                'status' => ($inner_results['batch_info']['has_more_batches'] ?? false) ? 'batch_complete' : 'complete',
                'message' => ($inner_results['batch_info']['has_more_batches'] ?? false) 
                    ? 'Batch complete, processing next batch...' 
                    : 'Reinstallation complete'
            ]
        ], 'Theme reinstallation completed');
        
    } catch (Exception $e) {
        clean_sweep_log_message("API: Theme reinstallation failed: " . $e->getMessage(), 'error');
        
        // Write error progress
        if ($progress_file) {
            $error_progress = [
                'status' => 'error',
                'progress' => 0,
                'message' => $e->getMessage(),
                'timestamp' => time()
            ];
            @clean_sweep_write_progress_file($progress_file, $error_progress);
        }
        
        CleanSweep_ApiResponse::sendError('Reinstallation failed: ' . $e->getMessage(), 'REINSTALL_ERROR', [
            'progress_file' => $progress_file
        ]);
    }
}

/**
 * Handle create theme backup request
 */
function clean_sweep_handle_create_theme_backup() {
    clean_sweep_log_message("API: Creating theme backup");
    
    try {
        $result = clean_sweep_create_theme_backup();
        
        if ($result) {
            clean_sweep_log_message("API: Theme backup created successfully: $result");
            CleanSweep_ApiResponse::sendSuccess([
                'backup_path' => $result,
                'backup_filename' => basename($result)
            ], 'Theme backup created successfully');
        } else {
            clean_sweep_log_message("API: Theme backup failed", 'error');
            CleanSweep_ApiResponse::sendError('Failed to create theme backup', 'BACKUP_ERROR');
        }
    } catch (Exception $e) {
        clean_sweep_log_message("API: Theme backup exception: " . $e->getMessage(), 'error');
        CleanSweep_ApiResponse::sendError('Backup error: ' . $e->getMessage(), 'BACKUP_EXCEPTION');
    }
}

/**
 * Handle get progress request
 */
function clean_sweep_handle_get_progress() {
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
 * Process theme analysis results for frontend consumption
 */
function clean_sweep_process_theme_results($results) {
    if (!$results) {
        return null;
    }
    
    $processed = [
        'summary' => [],
        'wp_org_themes' => [],
        'custom_themes' => [],
        'likely_fake_themes' => [],
        'suspicious_files' => [],
        'total_themes' => 0,
        'needs_reinstall' => false,
        'copy_lists' => []
    ];
    
    // Process WordPress.org themes
    if (!empty($results['wp_org_themes'])) {
        $processed['wp_org_themes'] = array_values($results['wp_org_themes']);
        $processed['needs_reinstall'] = true;
    }
    
    // Process custom themes
    if (!empty($results['custom_themes'])) {
        $processed['custom_themes'] = array_values($results['custom_themes']);
    }

    if (!empty($results['likely_fake_themes'])) {
        $processed['likely_fake_themes'] = array_values($results['likely_fake_themes']);
    }
    
    // Process suspicious files
    if (!empty($results['suspicious_files'])) {
        $processed['suspicious_files'] = array_values($results['suspicious_files']);
    }
    
    // Calculate totals
    $processed['total_themes'] = count($processed['wp_org_themes']) + count($processed['custom_themes']) + count($processed['likely_fake_themes']);
    
    // Summary
    $processed['summary'] = [
        'wp_org_count' => count($processed['wp_org_themes']),
        'custom_count' => count($processed['custom_themes']),
        'likely_fake_count' => count($processed['likely_fake_themes']),
        'suspicious_count' => count($processed['suspicious_files']),
        'total_themes' => $processed['total_themes'],
        'needs_reinstall' => $processed['needs_reinstall']
    ];
    
    $processed['copy_lists'] = $results['copy_lists'] ?? [];

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

function clean_sweep_annotate_themes_with_checksums($processed) {
    if (!is_array($processed)) {
        return $processed;
    }
    $file = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__) . '/') . 'features/security/scan/PackageChecksums.php';
    if (is_readable($file)) {
        require_once $file;
    }
    $latest = class_exists('CleanSweep_PackageChecksums', false) ? CleanSweep_PackageChecksums::load_latest() : [];
    foreach (['wp_org_themes', 'custom_themes', 'likely_fake_themes'] as $key) {
        if (empty($processed[$key]) || !is_array($processed[$key])) {
            continue;
        }
        foreach ($processed[$key] as &$theme) {
            $slug = (string) ($theme['slug'] ?? '');
            $row = $latest['theme:' . $slug] ?? null;
            $theme['checksum_status'] = $row['status'] ?? null;
            $theme['checksum_outcome'] = $row['outcome'] ?? null;
            $theme['checksum_note'] = $row['note'] ?? null;
            $theme['checksum_findings'] = $row['finding_count'] ?? 0;
            if (($row['outcome'] ?? '') === 'divergent' && empty($theme['checksum_note'])) {
                $theme['checksum_note'] = 'bulk divergence from wordpress.org free package';
            }
        }
        unset($theme);
    }
    return $processed;
}
