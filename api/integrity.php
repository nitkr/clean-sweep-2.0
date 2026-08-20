<?php
/**
 * Clean Sweep - Integrity Baseline API Endpoint
 *
 * JSON API for integrity baseline operations.
 * Handles establishing baselines and checking integrity.
 *
 * @version 1.0
 */

// Include unified bootstrap for CORS headers, OPTIONS handling, and error management
require_once __DIR__ . '/bootstrap.php';

// Load integrity baseline functionality
require_once __DIR__ . '/../includes/system/CleanSweep_Integrity.php';
require_once __DIR__ . '/../includes/system/visit/bootstrap.php';
if (!function_exists('clean_sweep_detect_site_root')) {
    require_once __DIR__ . '/../features/maintenance/core-reinstall.php';
}

// ============================================================================
// ROUTE REQUEST
// ============================================================================

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'get_baseline_info':
    case 'status':
        clean_sweep_handle_get_baseline_info();
        break;

    case 'export_snapshot':
        clean_sweep_handle_export_snapshot();
        break;

    case 'import_snapshot':
        clean_sweep_handle_import_snapshot();
        break;

    case 'skip_snapshot':
        clean_sweep_handle_skip_snapshot();
        break;

    case 'set_include_all_media':
        clean_sweep_handle_set_include_all_media();
        break;

    case 'find_elsewhere':
        clean_sweep_handle_find_elsewhere();
        break;
        
    case 'establish_baseline':
        clean_sweep_handle_establish_baseline();
        break;
        
    case 'check_integrity':
        clean_sweep_handle_check_integrity();
        break;
        
    case 'clear_baseline':
        clean_sweep_handle_clear_baseline();
        break;
        
    case 'export_baseline':
        clean_sweep_handle_export_baseline();
        break;
        
    case 'import_baseline':
        clean_sweep_handle_import_baseline();
        break;
        
    case 'get_progress':
        clean_sweep_handle_get_progress();
        break;

    case 'enable_live_watch':
        clean_sweep_handle_enable_live_watch();
        break;

    case 'disable_live_watch':
        clean_sweep_handle_disable_live_watch();
        break;

    case 'live_watch_tick':
        clean_sweep_handle_live_watch_tick();
        break;
        
    default:
        CleanSweep_ApiResponse::sendError('Unknown action: ' . $action, 'UNKNOWN_ACTION');
}

// ============================================================================
// HANDLER FUNCTIONS
// ============================================================================

/**
 * Handle get baseline info request
 */
function clean_sweep_handle_get_baseline_info() {
    $state = new CleanSweep_VisitState();
    $bind = $state->verify_and_bind();
    $payload = $state->status_payload();
    if (class_exists('CleanSweep_VisitWatch', false)) {
        $watch = new CleanSweep_VisitWatch($state);
        $payload = array_merge($payload, $watch->status_slice());
        $payload['live_watch_agent'] = $watch->agent_installed();
    }
    if (!empty($bind['visit_key'])) {
        $payload['visit_key'] = $bind['visit_key'];
    }
    if (!empty($bind['tamper'])) {
        $payload['journal_tamper'] = true;
        $payload['journal_tamper_reason'] = $bind['reason'];
    }
    $payload['toolkit'] = $GLOBALS['clean_sweep_toolkit_integrity'] ?? clean_sweep_toolkit_integrity();
    $payload['has_baseline'] = !empty($payload['core_sealed']) || !empty($payload['snapshot_imported']);
    $payload['mode'] = $payload['core_sealed'] ? 'core' : 'none';
    $payload['file_count'] = $payload['core_file_count'];
    $payload['established_at'] = !empty($payload['core_sealed_at'])
        ? date('Y-m-d H:i:s', (int) $payload['core_sealed_at'])
        : null;
    $payload['not_sealed'] = (new CleanSweep_Snapshot($state))->scope_summary()['not_sealed'];

    $loaded = $state->load();
    if (empty($loaded['samples']['site_owned'])) {
        try {
            (new CleanSweep_Census(new CleanSweep_VisitStore($state)))->run_phase('site_owned');
            $payload = $state->status_payload();
            $payload['toolkit'] = $GLOBALS['clean_sweep_toolkit_integrity'] ?? clean_sweep_toolkit_integrity();
            $payload['has_baseline'] = !empty($payload['core_sealed']) || !empty($payload['snapshot_imported']);
            $payload['not_sealed'] = (new CleanSweep_Snapshot($state))->scope_summary()['not_sealed'];
            if (!empty($bind['visit_key'])) {
                $payload['visit_key'] = $bind['visit_key'];
            }
            if (!empty($bind['tamper'])) {
                $payload['journal_tamper'] = true;
                $payload['journal_tamper_reason'] = $bind['reason'];
            }
        } catch (Throwable $e) {
            // CleanSweep_Census is best-effort on status.
        }
    }

    $root = function_exists('clean_sweep_detect_site_root') ? clean_sweep_detect_site_root() : '';
    $caps = CleanSweep_VisitCapabilities::instance();
    $idf = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__) . '/')
        . 'features/maintenance/lib/PackageIdentity.php';
    if (is_readable($idf)) {
        require_once $idf;
        $payload['likely_fake'] = CleanSweep_PackageIdentity::summary();
    }

    $payload['canary'] = [];
    foreach (['wp-config.php', '.htaccess'] as $rel) {
        $abs = $root . $rel;
        if ($root && is_readable($abs)) {
            $payload['canary'][$rel] = $caps->hash_path($abs);
        }
    }

    CleanSweep_ApiResponse::sendSuccess($payload);
}

function clean_sweep_handle_set_include_all_media() {
    $on = !empty($_POST['include_all_media']);
    $state = new CleanSweep_VisitState();
    $state->merge(['include_all_media' => $on]);
    $state->event('media:' . ($on ? 'all' : 'suspects'), '');
    CleanSweep_ApiResponse::sendSuccess(['include_all_media' => $on]);
}

function clean_sweep_handle_export_snapshot() {
    if (function_exists('clean_sweep_require_toolkit_ok')) {
        clean_sweep_require_toolkit_ok();
    }
    $snap = new CleanSweep_Snapshot();
    $result = $snap->export();
    if (empty($result['success'])) {
        CleanSweep_ApiResponse::sendError($result['error'] ?? 'Export failed', 'EXPORT_ERROR');
    }
    CleanSweep_ApiResponse::sendSuccess($result, 'Snapshot ready to download');
}

function clean_sweep_handle_import_snapshot() {
    $secret = (string) ($_POST['secret'] ?? '');
    $GLOBALS['clean_sweep_confirm_legacy_snapshot'] = !empty($_POST['confirm_legacy']);
    $json = clean_sweep_read_snapshot_payload();
    if ($json === '') {
        $content_len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($content_len > 0 && empty($_POST) && empty($_FILES)) {
            CleanSweep_ApiResponse::sendError(
                'Snapshot is larger than this host allows in a form post. Use the file picker.',
                'IMPORT_ERROR'
            );
        }
        CleanSweep_ApiResponse::sendError('No snapshot data provided', 'IMPORT_ERROR');
    }
    $result = (new CleanSweep_Snapshot())->import($json, $secret);
    if (empty($result['success'])) {
        CleanSweep_ApiResponse::sendError($result['error'] ?? 'Import failed', 'IMPORT_ERROR', [
            'needs_legacy_confirm' => !empty($result['needs_legacy_confirm']),
        ]);
    }
    CleanSweep_ApiResponse::sendSuccess($result, 'Snapshot imported');
}

/** File upload first; a large snapshot as a text field is often truncated. */
function clean_sweep_read_snapshot_payload(): string {
    $tmp = (string) ($_FILES['snapshot_file']['tmp_name'] ?? '');
    if ($tmp !== '' && is_readable($tmp)) {
        $from_file = (string) file_get_contents($tmp);
        if ($from_file !== '') {
            return $from_file;
        }
    }
    $raw = $_POST['snapshot'] ?? $_POST['baseline'] ?? '';
    if (is_array($raw)) {
        $enc = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($enc) ? $enc : '';
    }
    return (string) $raw;
}

function clean_sweep_handle_find_elsewhere() {
    $basename = basename((string) ($_POST['basename'] ?? $_POST['path'] ?? ''));
    $hash = (string) ($_POST['hash'] ?? '');
    if ($basename === '') {
        CleanSweep_ApiResponse::sendError('basename required', 'FIND_ERROR');
    }
    $hits = (new CleanSweep_Census())->find_elsewhere($basename, $hash !== '' ? $hash : null);
    CleanSweep_ApiResponse::sendSuccess([
        'basename' => $basename,
        'hash' => $hash !== '' ? $hash : null,
        'hits' => $hits,
        'count' => count($hits),
    ]);
}

function clean_sweep_handle_skip_snapshot() {
    $state = new CleanSweep_VisitState();
    $state->merge(['snapshot_skipped' => true]);
    $state->event('snapshot:skipped', '');
    CleanSweep_ApiResponse::sendSuccess(['snapshot_skipped' => true], 'Snapshot skipped for this visit');
}



/**
 * Handle establish baseline request
 */
function clean_sweep_handle_establish_baseline() {
    $mode = strtolower(trim((string) ($_POST['mode'] ?? $_GET['mode'] ?? '')));
    $comprehensive = (isset($_POST['comprehensive']) && $_POST['comprehensive'])
        || $mode === 'comprehensive';
    $wp_version = isset($_POST['wp_version']) ? $_POST['wp_version'] : null;
    
    // Generate unique progress file for this operation
    $progress_file = 'integrity_baseline_' . time() . '.progress';
    
    clean_sweep_log_message("API: Establishing integrity baseline - Comprehensive: " . ($comprehensive ? 'Yes' : 'No'));
    
    // Set comprehensive mode in session if requested
    if ($comprehensive) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['clean_sweep_comprehensive_baseline'] = true;
    } else {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['clean_sweep_comprehensive_baseline'] = false;
    }
    
    // Progress callback
    $progress_callback = function($current, $total, $message) use ($progress_file) {
        if ($progress_file) {
            $progress_data = [
                'status' => 'processing',
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
        // Write initial progress
        @clean_sweep_write_progress_file($progress_file, [
            'status' => 'processing',
            'progress' => 0,
            'message' => 'Starting baseline establishment...',
            'timestamp' => time()
        ]);
        
        // Establish the baseline
        $mode = 'core';
        $result = clean_sweep_establish_core_baseline($wp_version);
        
        // Write final progress
        @clean_sweep_write_progress_file($progress_file, [
            'status' => $result ? 'complete' : 'error',
            'progress' => $result ? 100 : 0,
            'message' => $result ? 'Baseline established successfully' : 'Failed to establish baseline',
            'timestamp' => time()
        ]);
        
        if ($result) {
            CleanSweep_ApiResponse::sendSuccess([
                'success' => true,
                'mode' => $mode,
                'progress_file' => $progress_file,
                'message' => 'Integrity baseline established successfully'
            ]);
        } else {
            CleanSweep_ApiResponse::sendError('Failed to establish integrity baseline', 'BASELINE_ERROR', [
                'progress_file' => $progress_file
            ]);
        }
        
    } catch (Exception $e) {
        // Write error progress
        @clean_sweep_write_progress_file($progress_file, [
            'status' => 'error',
            'progress' => 0,
            'message' => $e->getMessage(),
            'timestamp' => time()
        ]);
        
        CleanSweep_ApiResponse::sendError('Baseline establishment failed: ' . $e->getMessage(), 'BASELINE_ERROR', [
            'progress_file' => $progress_file
        ]);
    }
}

/**
 * Handle check integrity request
 */
function clean_sweep_handle_check_integrity() {
    // Generate unique progress file for this operation
    $progress_file = 'integrity_check_' . time() . '.progress';
    
    clean_sweep_log_message("API: Starting integrity check");
    
    // Progress callback
    $progress_callback = function($current, $total, $message) use ($progress_file) {
        if ($progress_file) {
            $progress_data = [
                'status' => 'scanning',
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
        // Write initial progress
        @clean_sweep_write_progress_file($progress_file, [
            'status' => 'scanning',
            'progress' => 0,
            'message' => 'Checking integrity...',
            'timestamp' => time()
        ]);
        
        // Run integrity check
        $violations = clean_sweep_check_for_reinfection();
        $compare = null;
        if (class_exists('CleanSweep_SnapshotCompare') && is_array($violations)) {
            $compare = (new CleanSweep_SnapshotCompare())->classify($violations);
            (new CleanSweep_VisitState())->merge(['last_compare' => $compare]);
            $violations = $compare['tamper'] ?? $violations;
        }
        
        // Process violations for frontend
        $processed_violations = [];
        $critical_count = 0;
        $warning_count = 0;
        
        foreach ($violations as $violation) {
            $severity = $violation['severity'] ?? 'warning';
            if ($severity === 'critical') {
                $critical_count++;
            } else {
                $warning_count++;
            }
            
            $processed_violations[] = [
                'file' => $violation['file'] ?? 'unknown',
                'type' => $violation['type'] ?? 'unknown',
                'severity' => $severity,
                'description' => strip_tags($violation['description'] ?? ''),
                'pattern' => $violation['pattern'] ?? ''
            ];
        }
        
        // Write final progress WITH results
        @clean_sweep_write_progress_file($progress_file, [
            'status' => 'complete',
            'progress' => 100,
            'message' => count($violations) > 0 ? 
                'Found ' . count($violations) . ' integrity violations' : 
                'No integrity violations detected',
            'timestamp' => time(),
            'results' => [
                'has_violations' => count($violations) > 0,
                'violations' => $processed_violations,
                'total_violations' => count($violations),
                'critical_count' => $critical_count,
                'warning_count' => $warning_count
            ]
        ]);
        
        CleanSweep_ApiResponse::sendSuccess([
            'success' => true,
            'has_violations' => count($violations) > 0,
            'violations' => $processed_violations,
            'total_violations' => count($violations),
            'critical_count' => $critical_count,
            'warning_count' => $warning_count,
            'compare' => $compare,
            'progress_file' => $progress_file,
            'message' => count($violations) > 0 ? 
                'Found ' . count($violations) . ' integrity violations' : 
                'No integrity violations detected'
        ]);
        
    } catch (Exception $e) {
        // Write error progress
        @clean_sweep_write_progress_file($progress_file, [
            'status' => 'error',
            'progress' => 0,
            'message' => $e->getMessage(),
            'timestamp' => time()
        ]);
        
        CleanSweep_ApiResponse::sendError('Integrity check failed: ' . $e->getMessage(), 'INTEGRITY_ERROR', [
            'progress_file' => $progress_file
        ]);
    }
}

/**
 * Handle clear baseline request
 */
function clean_sweep_handle_clear_baseline() {
    try {
        $result = clean_sweep_clear_core_baseline();
        
        if ($result) {
            CleanSweep_ApiResponse::sendSuccess([
                'success' => true,
                'message' => 'Integrity baseline cleared successfully'
            ]);
        } else {
            CleanSweep_ApiResponse::sendError('Failed to clear baseline (may not exist)', 'CLEAR_ERROR');
        }
    } catch (Exception $e) {
        CleanSweep_ApiResponse::sendError('Clear baseline failed: ' . $e->getMessage(), 'CLEAR_ERROR');
    }
}

/**
 * Handle export baseline request
 */
function clean_sweep_handle_export_baseline() {
    try {
        global $clean_sweep_functions;
        if (!isset($clean_sweep_functions)) {
            $clean_sweep_functions = new CleanSweep_Functions(null);
        }
        
        clean_sweep_handle_export_snapshot();
        return;
    } catch (Exception $e) {
        CleanSweep_ApiResponse::sendError('Export failed: ' . $e->getMessage(), 'EXPORT_ERROR');
    }
}

/**
 * Handle import baseline request
 */
function clean_sweep_handle_import_baseline() {
    clean_sweep_handle_import_snapshot();
}

/**
 * Handle get progress request
 */
function clean_sweep_handle_get_progress() {
    $progress_file = $_POST['progress_file'] ?? $_GET['progress_file'] ?? '';
    
    if (empty($progress_file)) {
        CleanSweep_ApiResponse::sendError('Progress file not specified', 'MISSING_PROGRESS_FILE');
    }
    
    // Sanitize filename to prevent directory traversal
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
        return;
    }
    
    $progress_data = @file_get_contents($progress_path);
    $progress = $progress_data ? json_decode($progress_data, true) : null;
    
    if (!$progress) {
        CleanSweep_ApiResponse::sendSuccess([
            'status' => 'pending',
            'progress' => 0,
            'message' => 'Progress data not available yet'
        ]);
        return;
    }
    
    CleanSweep_ApiResponse::sendSuccess($progress);
}

/**
 * Opt-in always-on live file watch (Phase 3).
 */
function clean_sweep_handle_enable_live_watch() {
    if (!class_exists('CleanSweep_VisitWatch', false)) {
        CleanSweep_ApiResponse::sendError('Live watch not available', 'WATCH_UNAVAILABLE');
    }
    $state = new CleanSweep_VisitState();
    $state->verify_and_bind();
    $result = (new CleanSweep_VisitWatch($state))->enable();
    if (empty($result['ok'])) {
        CleanSweep_ApiResponse::sendError($result['error'] ?? 'Failed to enable live watch', 'WATCH_ENABLE_FAILED');
    }
    $payload = $state->status_payload();
    $payload = array_merge($payload, (new CleanSweep_VisitWatch($state))->status_slice());
    $payload['live_watch_agent'] = (new CleanSweep_VisitWatch($state))->agent_installed();
    CleanSweep_ApiResponse::sendSuccess($payload, 'Live watch enabled');
}

function clean_sweep_handle_disable_live_watch() {
    if (!class_exists('CleanSweep_VisitWatch', false)) {
        CleanSweep_ApiResponse::sendError('Live watch not available', 'WATCH_UNAVAILABLE');
    }
    $state = new CleanSweep_VisitState();
    $state->verify_and_bind();
    (new CleanSweep_VisitWatch($state))->disable();
    $payload = $state->status_payload();
    $payload = array_merge($payload, (new CleanSweep_VisitWatch($state))->status_slice());
    $payload['live_watch_agent'] = (new CleanSweep_VisitWatch($state))->agent_installed();
    CleanSweep_ApiResponse::sendSuccess($payload, 'Live watch disabled');
}

/** Manual / diagnostic tick (also runs automatically via mu-plugin). */
function clean_sweep_handle_live_watch_tick() {
    if (!class_exists('CleanSweep_VisitWatch', false)) {
        CleanSweep_ApiResponse::sendError('Live watch not available', 'WATCH_UNAVAILABLE');
    }
    $state = new CleanSweep_VisitState();
    $state->verify_and_bind();
    $watch = new CleanSweep_VisitWatch($state);
    $tick = $watch->tick();
    $payload = array_merge($state->status_payload(), $watch->status_slice(), ['tick' => $tick]);
    $payload['live_watch_agent'] = $watch->agent_installed();
    CleanSweep_ApiResponse::sendSuccess($payload);
}
