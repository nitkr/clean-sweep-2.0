<?php
/**
 * Clean Sweep - WordPress Malware Cleanup Toolkit
 *
 * A comprehensive toolkit for WordPress malware cleanup and system restoration.
 * Features: Core file re-installation, plugin re-installation, and file upload/extraction.
 *
 * Usage:
 * 1. Upload the clean-sweep/ folder to your WordPress root directory
 * 2. Run via browser: http://yoursite.com/clean-sweep/clean-sweep.php
 * 3. Or run via command line: php clean-sweep/clean-sweep.php
 *
 * @version 2.0
 * @author Nithin K R
 */

// ============================================================================
// MODULE INCLUDES
// ============================================================================

// Core modular components
require_once __DIR__ . '/config.php';        // Configuration constants
require_once __DIR__ . '/utils.php';         // Utility functions
require_once __DIR__ . '/wordpress-api.php'; // WordPress API wrappers
require_once __DIR__ . '/ui.php';            // User interface components
require_once __DIR__ . '/display.php';       // Display and rendering functions

// Batch Processing System - Reusable long-running operation framework
require_once __DIR__ . '/includes/system/batch-processing/CleanSweep_BatchProcessor.php';
require_once __DIR__ . '/includes/system/batch-processing/CleanSweep_ProgressManager.php';
require_once __DIR__ . '/includes/system/batch-processing/CleanSweep_BatchProcessingException.php';

// Recovery-Only Mode Classes
require_once __DIR__ . '/includes/system/CleanSweep_FreshEnvironment.php';
require_once __DIR__ . '/includes/system/CleanSweep_RecoveryBootstrap.php';

// Independent Bootstrap Classes
require_once __DIR__ . '/includes/system/CleanSweep_DB.php';
require_once __DIR__ . '/includes/system/CleanSweep_Functions.php';
require_once __DIR__ . '/includes/system/CleanSweep_Integrity.php';
require_once __DIR__ . '/includes/system/CleanSweep_Filesystem.php';

// Feature-specific modules
require_once __DIR__ . '/features/maintenance/plugin-reinstall.php';  // Plugin reinstallation
require_once __DIR__ . '/features/maintenance/core-reinstall.php';    // Core file reinstallation
require_once __DIR__ . '/features/utilities/zip-extract.php';         // ZIP extraction
require_once __DIR__ . '/features/security/database-scan.php';        // Database scanning
require_once __DIR__ . '/features/security/malware-scan.php';         // Malware scanning

// Application classes
require_once __DIR__ . '/includes/system/CleanSweep_Application.php';
require_once __DIR__ . '/includes/system/CleanSweep_Cleanup.php';

// ============================================================================
// INITIALIZATION
// ============================================================================

// Start session for settings persistence (before any output)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}



// Check if this is an AJAX request (has progress_file OR action parameter) - BULLETPROOF DETECTION
$is_ajax_request = false;
$progress_file_param = isset($_POST['progress_file']) ? trim($_POST['progress_file']) : '';
$action_param = isset($_POST['action']) ? trim($_POST['action']) : '';

if (!empty($progress_file_param) || !empty($action_param)) {
    $is_ajax_request = true;
    if (!empty($progress_file_param)) {
        clean_sweep_log_message("✅ AJAX request confirmed with progress_file: '$progress_file_param'", 'info');
    } else {
        clean_sweep_log_message("✅ AJAX request confirmed with action: '$action_param'", 'info');
    }
} else {
    $is_ajax_request = false;
    clean_sweep_log_message("ℹ️ Regular request - no AJAX parameters detected", 'info');
}

// FOR AJAX REQUESTS: Complete error suppression to prevent JSON corruption
if ($is_ajax_request) {
    // Fatal error handler for AJAX (catches undefined constants, etc.)
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            // Output JSON error instead of HTML
            header('Content-Type: application/json; charset=utf-8', true);
            echo json_encode(['success' => false, 'error' => 'Server error: ' . $error['message']], JSON_UNESCAPED_UNICODE);
            exit;
        }
    });

    // Runtime error suppression
    ini_set('display_errors', 0);
    error_reporting(0);

    // Additional safeguards
    ini_set('html_errors', 0); // Prevent HTML in error messages
    ini_set('log_errors', 1);  // Log errors instead of displaying
}

// AUTO-ADD RECOVERY_TOKEN IF MISSING (Cache-busting enhancement)
if (!isset($_GET['recovery_token']) && !$is_ajax_request && (!defined('WP_CLI') || !WP_CLI)) {
    // Build redirect URL with recovery_token parameter
    $current_uri = $_SERVER['REQUEST_URI'];
    $separator = strpos($current_uri, '?') !== false ? '&' : '?';
    $redirect_url = $current_uri . $separator . 'recovery_token=' . time();

    // Perform redirect to add the cache-busting parameter
    header('Location: ' . $redirect_url, true, 302);
    exit;
}

// ============================================================================
// RECOVERY-ONLY MODE INITIALIZATION
// ============================================================================

// Use new Recovery-Only architecture
$recovery_bootstrap = new CleanSweep_RecoveryBootstrap($is_ajax_request);
$bootstrap_success = $recovery_bootstrap->initialize();

// If setup UI was shown, exit here
if (!$bootstrap_success && !$is_ajax_request) {
    exit;
}

// ============================================================================
// MAIN APPLICATION EXECUTION
// ============================================================================

try {
    $app = new CleanSweep_Application($is_ajax_request);
    $app->run();
} catch (Exception $e) {
    clean_sweep_log_message("Application error: " . $e->getMessage(), 'error');
    if (!$is_ajax_request && (!defined('WP_CLI') || !WP_CLI)) {
        echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:20px;border-radius:4px;margin:20px 0;color:#721c24;">';
        echo '<h3>❌ Application Error</h3>';
        echo '<p>An error occurred while processing your request. Please check the logs for details.</p>';
        echo '</div>';
    }
}

// ============================================================================
// CLEANUP & FINALIZATION
// ============================================================================

// Output HTML footer for browser execution
if (!$is_ajax_request && (!defined('WP_CLI') || !WP_CLI)) {
    clean_sweep_output_html_footer();
}
