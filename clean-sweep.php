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
require_once __DIR__ . '/includes/system/visit/bootstrap.php';
$GLOBALS['clean_sweep_toolkit_integrity'] = clean_sweep_toolkit_integrity();
require_once __DIR__ . '/wordpress-api.php'; // WordPress API wrappers
require_once __DIR__ . '/ui.php';            // User interface components

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

// CleanSweep_Scanner v2 - the new unified orchestrator
require_once __DIR__ . '/features/security/scan/Scanner.php';     // Malware scanning

// WP-Cron kick hook for CleanSweep_Scanner v2 (must be registered on every bootstrap that
// can receive cron). Mirrors registration in api/bootstrap.php.
if (function_exists('add_action') && !has_action('clean_sweep_scan_kick')) {
    add_action('clean_sweep_scan_kick', function ($scan_id) {
        $scan_id = is_string($scan_id) ? $scan_id : '';
        if ($scan_id === '') {
            return;
        }
        try {
            $scanner = CleanSweep_Scanner::create($scan_id);
            $scanner->drain($scan_id);
        } catch (Throwable $e) {
            if (function_exists('clean_sweep_log_message')) {
                clean_sweep_log_message('CleanSweep_Scanner WP-Cron kick failed: ' . $e->getMessage(), 'error');
            }
        }
    }, 10, 1);
}

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

// Cache-busting recovery_token: issue on first visit, and again on every full
// page refresh/reload. AJAX must not redirect.
if (!$is_ajax_request && (!defined('WP_CLI') || !WP_CLI) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $incoming_token = isset($_GET['recovery_token']) ? (string) $_GET['recovery_token'] : '';
    $served_token = isset($_SESSION['clean_sweep_recovery_token_served']) ? (string) $_SESSION['clean_sweep_recovery_token_served'] : '';

    // Missing token, or same token as last fully served page → treat as refresh.
    $needs_new_token = ($incoming_token === '' || !preg_match('/^[0-9]{9,16}$/', $incoming_token) || $incoming_token === $served_token);

    if ($needs_new_token) {
        $new_token = (string) time();
        // Avoid colliding with the just-served token within the same second.
        if ($new_token === $served_token || $new_token === $incoming_token) {
            $new_token .= (string) random_int(10, 99);
        }

        $parts = parse_url($_SERVER['REQUEST_URI'] ?? '/');
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['recovery_token'] = $new_token;
        // Do not mark served yet — that happens on the follow-up request below.
        unset($_SESSION['clean_sweep_recovery_token_served']);

        $redirect_url = $path . '?' . http_build_query($query);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $redirect_url, true, 302);
        exit;
    }

    // This request is the post-redirect load with a fresh token — remember it so
    // the next browser refresh will rotate again.
    $_SESSION['clean_sweep_recovery_token_served'] = $incoming_token;
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
