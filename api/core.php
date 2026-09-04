<?php
/**
 * Clean Sweep - Core Reinstallation API Endpoint
 *
 * JSON API for WordPress core reinstallation operations.
 * Returns JSON responses for Alpine.js frontend consumption.
 *
 * @version 1.0
 */

// Include unified bootstrap for CORS headers, OPTIONS handling, and error management
require_once __DIR__ . '/bootstrap.php';

// Load recovery bootstrap for WordPress environment
require_once __DIR__ . '/../includes/system/CleanSweep_FreshEnvironment.php';
require_once __DIR__ . '/../includes/system/CleanSweep_RecoveryBootstrap.php';
require_once __DIR__ . '/../includes/system/CleanSweep_Functions.php';

// Load core reinstallation functionality
require_once __DIR__ . '/../features/maintenance/core-reinstall.php';

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
    case 'get_core_info':
        clean_sweep_handle_get_core_info();
        break;
    
    case 'get_version_options':
        clean_sweep_handle_get_version_options();
        break;
        
    case 'reinstall_core':
        if (function_exists('clean_sweep_require_toolkit_ok')) {
            clean_sweep_require_toolkit_ok();
        }
        clean_sweep_handle_reinstall_core();
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
 * Handle get core info request - returns current WordPress version and status
 */
function clean_sweep_handle_get_core_info() {
    try {
        // Get current WordPress version
        $wp_version = get_bloginfo('version');
        
        // Get WordPress content directory
        $wp_content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
        
        // Check if we can determine the latest version
        include_once(ABSPATH . WPINC . '/version.php');
        $latest_version = isset($wp_version) ? $wp_version : 'unknown';
        
        // Determine if update is needed (simple check)
        $update_needed = false;
        if (function_exists('get_core_updates')) {
            $updates = get_core_updates();
            if (!empty($updates) && is_array($updates)) {
                $update_needed = true;
            }
        }
        
        CleanSweep_ApiResponse::sendSuccess([
            'current_version' => $wp_version,
            'latest_version' => $latest_version,
            'update_needed' => $update_needed,
            'wp_content_dir' => $wp_content_dir,
            'abspath' => ABSPATH
        ], 'Core info retrieved');
        
    } catch (Exception $e) {
        CleanSweep_ApiResponse::sendError('Failed to get core info: ' . $e->getMessage(), 'CORE_INFO_ERROR');
    }
}

/**
 * Handle core reinstallation request
 */
function clean_sweep_handle_reinstall_core() {
    $wp_version = isset($_POST['wp_version']) ? $_POST['wp_version'] : 'latest';
    $create_backup = function_exists('clean_sweep_request_flag')
        ? clean_sweep_request_flag('create_backup', true)
        : (isset($_POST['create_backup']) && (string) $_POST['create_backup'] === '1');
    
    // Validate version
    if (!empty($wp_version) && $wp_version !== 'latest' && !preg_match('/^\d+\.\d+(\.\d+)?$/', $wp_version)) {
        CleanSweep_ApiResponse::sendError('Invalid WordPress version format', 'INVALID_VERSION');
    }
    
    $progress_file = isset($_POST['progress_file']) && $_POST['progress_file'] !== ''
        ? basename((string) $_POST['progress_file'])
        : 'core_reinstall_' . time() . '.progress';
    if (substr($progress_file, -9) !== '.progress') {
        $progress_file .= '.progress';
    }
    
    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    @ignore_user_abort(true);
    @set_time_limit(0);

    clean_sweep_write_progress_file($progress_file, [
        'status' => 'running',
        'progress' => 2,
        'message' => 'Starting core reinstallation...',
        'timestamp' => time(),
    ]);

    clean_sweep_log_message("API: Starting WordPress core reinstallation (version: {$wp_version}, backup: " . ($create_backup ? 'yes' : 'no') . ")");
    
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
        // Run core reinstallation
        $results = clean_sweep_execute_core_reinstallation($wp_version);
        $ok = is_array($results) && !empty($results['success']);
        
        $final_progress = [
            'status' => $ok ? 'complete' : 'error',
            'progress' => $ok ? 100 : 0,
            'message' => $ok ? 'Core reinstallation complete' : (string) ($results['message'] ?? 'Core reinstallation failed'),
            'timestamp' => time()
        ];
        @clean_sweep_write_progress_file($progress_file, $final_progress);
        
        $processed = clean_sweep_process_core_results($results);
        
        if (!$ok) {
            CleanSweep_ApiResponse::sendError($final_progress['message'], 'REINSTALL_FAILED', [
                'results' => $processed,
                'progress_file' => $progress_file,
                'failed_files' => $results['failed_files'] ?? [],
            ]);
        }

        CleanSweep_ApiResponse::sendSuccess([
            'results' => $processed,
            'progress_file' => $progress_file
        ], 'Core reinstallation completed');
        
    } catch (Exception $e) {
        // Write error progress
        $error_progress = [
            'status' => 'error',
            'progress' => 0,
            'message' => $e->getMessage(),
            'timestamp' => time()
        ];
        @clean_sweep_write_progress_file($progress_file, $error_progress);
        
        CleanSweep_ApiResponse::sendError('Core reinstallation failed: ' . $e->getMessage(), 'REINSTALL_ERROR', [
            'progress_file' => $progress_file
        ]);
    }
}

/**
 * Handle get version options request - returns available WordPress versions
 */
function clean_sweep_handle_get_version_options() {
    try {
        // Include WordPress API functions
        require_once __DIR__ . '/../wordpress-api.php';
        
        // Include integrity functions for version detection
        require_once __DIR__ . '/../includes/system/CleanSweep_Integrity.php';
        
        // Get version options from WordPress.org API
        $version_options = clean_sweep_get_wordpress_version_options();
        
        // Get current WordPress version - use robust function instead of get_bloginfo
        $current_version = clean_sweep_get_wordpress_version();
        
        // Get latest version from WordPress.org
        $latest_version = clean_sweep_get_latest_wordpress_version();
        
        CleanSweep_ApiResponse::sendSuccess([
            'versions' => $version_options,
            'current_version' => $current_version,
            'latest_version' => $latest_version
        ], 'Version options retrieved');
        
    } catch (Exception $e) {
        CleanSweep_ApiResponse::sendError('Failed to get version options: ' . $e->getMessage(), 'VERSION_OPTIONS_ERROR');
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
 * Process core reinstallation results for frontend consumption
 */
function clean_sweep_process_core_results($results) {
    if (!$results) {
        return null;
    }
    
    $processed = [
        'success' => false,
        'message' => '',
        'files_updated' => [],
        'files_deleted' => [],
        'errors' => [],
        'backup_created' => false
    ];
    
    // Determine success based on results
    if (isset($results['success'])) {
        $processed['success'] = $results['success'];
    } elseif (isset($results['result']) && $results['result'] === true) {
        $processed['success'] = true;
    }
    
    // Extract message
    if (isset($results['message'])) {
        $processed['message'] = $results['message'];
    } elseif (isset($results['status_message'])) {
        $processed['message'] = $results['status_message'];
    }
    
    // Process files
    if (!empty($results['files_updated'])) {
        $processed['files_updated'] = is_array($results['files_updated']) 
            ? $results['files_updated'] 
            : explode(',', $results['files_updated']);
    }
    
    if (!empty($results['files_deleted'])) {
        $processed['files_deleted'] = is_array($results['files_deleted']) 
            ? $results['files_deleted'] 
            : explode(',', $results['files_deleted']);
    }
    
    // Process errors
    if (!empty($results['errors'])) {
        $processed['errors'] = is_array($results['errors']) 
            ? $results['errors'] 
            : [$results['errors']];
    }
    
    // Check for backup
    if (!empty($results['backup_created']) || !empty($results['backup_file'])) {
        $processed['backup_created'] = true;
    }
    
    // Summary
    $processed['summary'] = [
        'files_updated_count' => count($processed['files_updated']),
        'files_deleted_count' => count($processed['files_deleted']),
        'errors_count' => count($processed['errors']),
        'success' => $processed['success']
    ];
    
    return $processed;
}
