<?php
/**
 * Clean Sweep - Cleanup API Endpoint
 *
 * JSON API for cleanup operations.
 * Returns JSON responses for Alpine.js frontend consumption.
 *
 * @version 1.0
 */

// Include unified bootstrap for CORS headers, OPTIONS handling, and error management
require_once __DIR__ . '/bootstrap.php';

// Load recovery bootstrap for WordPress environment
require_once __DIR__ . '/../includes/system/CleanSweep_FreshEnvironment.php';
require_once __DIR__ . '/../includes/system/CleanSweep_RecoveryBootstrap.php';

// Load cleanup functionality
require_once __DIR__ . '/../includes/system/CleanSweep_Functions.php';
require_once __DIR__ . '/../includes/system/CleanSweep_DB.php';
require_once __DIR__ . '/../includes/system/CleanSweep_Cleanup.php';

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
    case 'cleanup_tool':
    case 'run_cleanup':
        clean_sweep_handle_cleanup_tool();
        break;
        
    case 'get_cleanup_status':
        clean_sweep_handle_get_cleanup_status();
        break;
        
    case 'delete_file':
        clean_sweep_handle_delete_file();
        break;
        
    case 'delete_directory':
        clean_sweep_handle_delete_directory();
        break;
        
    default:
        CleanSweep_ApiResponse::sendError('Unknown action: ' . $action, 'UNKNOWN_ACTION');
}

// ============================================================================
// HANDLER FUNCTIONS
// ============================================================================

/**
 * Handle cleanup tool request - executes full cleanup of Clean Sweep files
 */
function clean_sweep_handle_cleanup_tool() {
    try {
        // Verify required parameters
        $confirm = $_POST['confirm'] ?? '';
        if (empty($confirm) || $confirm !== 'yes') {
            CleanSweep_ApiResponse::sendError('Cleanup confirmation required. Set confirm=yes to proceed.', 'CONFIRMATION_REQUIRED');
        }

        $visit_boot = dirname(__DIR__) . '/includes/system/visit/bootstrap.php';
        if (is_readable($visit_boot)) {
            require_once $visit_boot;
            $state = new CleanSweep_VisitState();
            $vs = $state->load();

            // Allow explicit skip from the remove confirm dialog (one step).
            $skip_snapshot = !empty($_POST['skip_snapshot'])
                && (string) $_POST['skip_snapshot'] !== '0'
                && strtolower((string) $_POST['skip_snapshot']) !== 'false';
            if ($skip_snapshot && empty($vs['snapshot_downloaded']) && empty($vs['snapshot_skipped'])) {
                $state->merge(['snapshot_skipped' => true]);
                $state->event('snapshot:skipped', 'via cleanup_tool');
                $vs = $state->load();
            }

            if (empty($vs['snapshot_downloaded']) && empty($vs['snapshot_skipped'])) {
                CleanSweep_ApiResponse::sendError(
                    'Download a snapshot first, or skip snapshot if you will not compare later.',
                    'SNAPSHOT_REQUIRED'
                );
            }
        }
        
        // Initialize cleanup class
        $cleanup = new CleanSweep_Cleanup();
        
        // Quiet mode: no HTML/flush. Extra output after JSON is still tolerated
        // by the client, but this keeps the response a single JSON object.
        $result = $cleanup->execute_cleanup(true);

        CleanSweep_ApiResponse::sendSuccess([
            'message' => 'Cleanup completed successfully',
            'files_deleted' => (int) ($result['files'] ?? 0),
            'dirs_deleted' => (int) ($result['dirs'] ?? 0),
        ], 'Cleanup executed');
        
    } catch (Throwable $e) {
        CleanSweep_ApiResponse::sendError('Cleanup failed: ' . $e->getMessage(), 'CLEANUP_ERROR');
    }
}

/**
 * Handle get cleanup status - returns information about Clean Sweep files
 */
function clean_sweep_handle_get_cleanup_status() {
    try {
        // Calculate Clean Sweep root directory
        $clean_sweep_dir = function_exists('clean_sweep_toolkit_root')
            ? rtrim(clean_sweep_toolkit_root(), '/')
            : dirname(__DIR__);
        
        // Get file counts and sizes
        $stats = [
            'backups' => clean_sweep_get_dir_stats($clean_sweep_dir . '/backups'),
            'logs' => clean_sweep_get_dir_stats($clean_sweep_dir . '/logs'),
            'assets' => clean_sweep_get_dir_stats($clean_sweep_dir . '/assets'),
            'features' => clean_sweep_get_dir_stats($clean_sweep_dir . '/features'),
            'root_files' => clean_sweep_get_root_file_count($clean_sweep_dir)
        ];
        
        // Determine if cleanup is needed
        $cleanup_needed = ($stats['backups']['total_size'] > 0 || 
                          $stats['logs']['total_size'] > 0 || 
                          $stats['root_files']['count'] > 1);
        
        CleanSweep_ApiResponse::sendSuccess([
            'stats' => $stats,
            'cleanup_needed' => $cleanup_needed,
            'clean_sweep_dir' => $clean_sweep_dir
        ], 'Cleanup status retrieved');
        
    } catch (Exception $e) {
        CleanSweep_ApiResponse::sendError('Failed to get cleanup status: ' . $e->getMessage(), 'STATUS_ERROR');
    }
}

/**
 * Handle delete file request - deletes a specific file
 */
function clean_sweep_handle_delete_file() {
    try {
        $file_path = $_POST['file_path'] ?? '';
        
        if (empty($file_path)) {
            CleanSweep_ApiResponse::sendError('File path is required', 'MISSING_FILE_PATH');
        }
        
        // Security: Ensure path is within allowed directories
        $clean_sweep_dir = function_exists('clean_sweep_toolkit_root')
            ? rtrim(clean_sweep_toolkit_root(), '/')
            : dirname(__DIR__);
        $real_target = realpath($file_path);
        $real_base = realpath($clean_sweep_dir);
        
        if ($real_target === false) {
            CleanSweep_ApiResponse::sendError('File does not exist: ' . $file_path, 'FILE_NOT_FOUND');
        }
        
        if (strpos($real_target, $real_base) !== 0) {
            CleanSweep_ApiResponse::sendError('Access denied: Path is outside allowed directory', 'ACCESS_DENIED');
        }
        
        // Delete the file
        if (is_dir($real_target)) {
            CleanSweep_ApiResponse::sendError('Path is a directory, use delete_directory action', 'IS_DIRECTORY');
        }
        
        if (!unlink($real_target)) {
            CleanSweep_ApiResponse::sendError('Failed to delete file: ' . $file_path, 'DELETE_FAILED');
        }
        
        CleanSweep_ApiResponse::sendSuccess([
            'file_path' => $file_path,
            'deleted' => true
        ], 'File deleted successfully');
        
    } catch (Exception $e) {
        CleanSweep_ApiResponse::sendError('Failed to delete file: ' . $e->getMessage(), 'DELETE_ERROR');
    }
}

/**
 * Handle delete directory request - deletes a directory and its contents
 */
function clean_sweep_handle_delete_directory() {
    try {
        $dir_path = $_POST['dir_path'] ?? '';
        
        if (empty($dir_path)) {
            CleanSweep_ApiResponse::sendError('Directory path is required', 'MISSING_DIR_PATH');
        }
        
        // Security: Ensure path is within allowed directories
        $clean_sweep_dir = function_exists('clean_sweep_toolkit_root')
            ? rtrim(clean_sweep_toolkit_root(), '/')
            : dirname(__DIR__);
        $real_target = realpath($dir_path);
        $real_base = realpath($clean_sweep_dir);
        
        if ($real_target === false) {
            CleanSweep_ApiResponse::sendError('Directory does not exist: ' . $dir_path, 'DIR_NOT_FOUND');
        }
        
        if (strpos($real_target, $real_base) !== 0) {
            CleanSweep_ApiResponse::sendError('Access denied: Path is outside allowed directory', 'ACCESS_DENIED');
        }
        
        if (!is_dir($real_target)) {
            CleanSweep_ApiResponse::sendError('Path is not a directory: ' . $dir_path, 'NOT_DIRECTORY');
        }
        
        // Delete the directory recursively
        if (!clean_sweep_delete_directory_recursive($real_target)) {
            CleanSweep_ApiResponse::sendError('Failed to delete directory: ' . $dir_path, 'DELETE_FAILED');
        }
        
        CleanSweep_ApiResponse::sendSuccess([
            'dir_path' => $dir_path,
            'deleted' => true
        ], 'Directory deleted successfully');
        
    } catch (Exception $e) {
        CleanSweep_ApiResponse::sendError('Failed to delete directory: ' . $e->getMessage(), 'DELETE_ERROR');
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Get statistics for a directory
 */
function clean_sweep_get_dir_stats($dir_path) {
    $result = [
        'exists' => false,
        'file_count' => 0,
        'dir_count' => 0,
        'total_size' => 0
    ];
    
    if (!is_dir($dir_path)) {
        return $result;
    }
    
    $result['exists'] = true;
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir_path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isFile()) {
            $result['file_count']++;
            $result['total_size'] += $file->getSize();
        } elseif ($file->isDir()) {
            $result['dir_count']++;
        }
    }
    
    return $result;
}

/**
 * Get count of files in root directory (excluding specific files)
 */
function clean_sweep_get_root_file_count($dir_path) {
    $result = ['count' => 0, 'files' => []];
    
    if (!is_dir($dir_path)) {
        return $result;
    }
    
    $items = glob($dir_path . '/*');
    
    foreach ($items as $item) {
        $basename = basename($item);
        
        // Skip main script and hidden files
        if ($basename === 'clean-sweep.php' || $basename[0] === '.') {
            continue;
        }
        
        if (is_file($item)) {
            $result['count']++;
            $result['files'][] = [
                'name' => $basename,
                'path' => $item,
                'size' => filesize($item)
            ];
        }
    }
    
    return $result;
}

/**
 * Delete a directory and all its contents
 */
function clean_sweep_delete_directory_recursive($dir_path) {
    if (!is_dir($dir_path)) {
        return false;
    }
    
    $items = array_diff(scandir($dir_path), ['.', '..']);
    
    foreach ($items as $item) {
        $path = $dir_path . DIRECTORY_SEPARATOR . $item;
        
        if (is_dir($path)) {
            if (!clean_sweep_delete_directory_recursive($path)) {
                return false;
            }
        } else {
            if (!unlink($path)) {
                return false;
            }
        }
    }
    
    return rmdir($dir_path);
}
