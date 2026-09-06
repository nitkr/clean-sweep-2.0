<?php
/**
 * Clean Sweep - Unified API Bootstrap
 *
 * Framework-independent API bootstrap that provides:
 * - Pure JSON responses (never HTML)
 * - Proper error handling and HTTP status codes
 * - WordPress environment initialization
 * - Security verification
 *
 * This bootstrap is designed to work with ANY frontend framework
 * (Alpine.js, React, Vue, Vanilla JS, etc.)
 *
 * @version 1.1
 */

// ============================================================================
// ERROR HANDLING - Always return JSON
// ============================================================================

// Set JSON headers FIRST - before any output
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CS-Visit-Key');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Error reporting - log errors but don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Global exception handler - always return JSON
set_exception_handler(function($exception) {
    http_response_code(500);
    $file = (string) $exception->getFile();
    $root = defined('CLEAN_SWEEP_ROOT') ? str_replace('\\', '/', CLEAN_SWEEP_ROOT) : '';
    $rel = $file;
    if ($root !== '' && strpos(str_replace('\\', '/', $file), $root) === 0) {
        $rel = substr(str_replace('\\', '/', $file), strlen($root));
    }
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $exception->getMessage(),
        'code' => 'UNHANDLED_EXCEPTION',
        'type' => get_class($exception),
        'file' => $rel,
        'line' => (int) $exception->getLine(),
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// Fatal error handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Fatal server error: ' . $error['message'],
            'code' => 'FATAL_ERROR',
            'file' => $error['file'] ?? '',
            'line' => $error['line'] ?? 0,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

// ============================================================================
// INCLUDES - Minimal required files
// ============================================================================

// Get the base directory (api/ is subdirectory of clean-sweep/)
$base_dir = dirname(__DIR__);

// Load configuration
require_once $base_dir . '/config.php';
// config.php may set display_errors for CLI; JSON responses must never print warnings.
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

// Load utility functions
require_once $base_dir . '/utils.php';

require_once $base_dir . '/includes/system/CleanSweep_Functions.php';

// Visit engine + toolkit self-check (every API request)
require_once $base_dir . '/includes/system/visit/bootstrap.php';
$GLOBALS['clean_sweep_toolkit_integrity'] = clean_sweep_toolkit_integrity();

// Load API Response class
require_once $base_dir . '/includes/ApiResponse.php';

// ============================================================================
// WORDPRESS ENVIRONMENT INITIALIZATION
// ============================================================================

/**
 * Actions that set up core/fresh. Must run without executing any site PHP.
 *
 * @return string[]
 */
function clean_sweep_api_recovery_setup_actions() {
    return ['start_fresh_setup', 'get_setup_progress', 'upload_wordpress_zip', 'clear_all_caches', 'check_canary'];
}

/**
 * Detect the real site root (wp-config.php). Used for path constants only.
 * Never require that directory's wp-load.php — it may be malware-prepended.
 *
 * @return string|false
 */
function clean_sweep_api_detect_wordpress_root() {
    if (defined('SITE_ABSPATH') && SITE_ABSPATH && file_exists(SITE_ABSPATH . 'wp-config.php')) {
        return rtrim(str_replace('\\', '/', SITE_ABSPATH), '/') . '/';
    }
    if (defined('ORIGINAL_ABSPATH') && ORIGINAL_ABSPATH && file_exists(ORIGINAL_ABSPATH . 'wp-config.php')) {
        return rtrim(str_replace('\\', '/', ORIGINAL_ABSPATH), '/') . '/';
    }

    $possible_paths = [
        dirname(__DIR__, 2),
        dirname(__DIR__, 3),
        __DIR__ . '/../../..',
        __DIR__ . '/../..',
    ];

    foreach ($possible_paths as $path) {
        $real_path = realpath($path);
        if ($real_path && file_exists($real_path . '/wp-config.php')) {
            return $real_path . '/';
        }
    }

    return false;
}

/**
 * Point ORIGINAL_* at the real site after fresh WordPress is loaded.
 */
function clean_sweep_api_ensure_site_path_constants() {
    $site = clean_sweep_api_detect_wordpress_root();
    if (!$site) {
        return;
    }
    $site = rtrim(str_replace('\\', '/', $site), '/') . '/';
    if (!defined('ORIGINAL_ABSPATH')) {
        define('ORIGINAL_ABSPATH', $site);
    }
    if (!defined('SITE_ABSPATH')) {
        define('SITE_ABSPATH', $site);
    }
    if (!defined('ORIGINAL_WP_CONTENT_DIR') && is_dir($site . 'wp-content')) {
        define('ORIGINAL_WP_CONTENT_DIR', $site . 'wp-content');
    }
    if (!defined('ORIGINAL_WP_PLUGIN_DIR') && is_dir($site . 'wp-content/plugins')) {
        define('ORIGINAL_WP_PLUGIN_DIR', $site . 'wp-content/plugins/');
    }
}

/**
 * Load WordPress from core/fresh only. Never execute the site's wp-load.php.
 *
 * @return bool True if fresh WordPress is loaded
 */
function clean_sweep_api_initialize_wordpress() {
    if (defined('ABSPATH') && function_exists('get_bloginfo')) {
        clean_sweep_log_message('API: WordPress already loaded from ' . ABSPATH, 'info');
        clean_sweep_api_ensure_site_path_constants();
        return true;
    }

    require_once dirname(__DIR__) . '/includes/system/CleanSweep_FreshEnvironment.php';
    require_once dirname(__DIR__) . '/includes/system/CleanSweep_RecoveryBootstrap.php';
    require_once dirname(__DIR__) . '/includes/system/CleanSweep_Functions.php';

    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $recovery = new CleanSweep_RecoveryBootstrap(true);

    if (in_array($action, clean_sweep_api_recovery_setup_actions(), true)) {
        $recovery->initialize();
        return false;
    }

    $fresh = new CleanSweep_FreshEnvironment();
    if (!$fresh->isValid('api')) {
        clean_sweep_log_message('API: core/fresh is not ready', 'info');
        return false;
    }

    if (!$recovery->initialize()) {
        clean_sweep_log_message('API: RecoveryBootstrap failed to load core/fresh', 'warning');
        return false;
    }

    if (!function_exists('get_bloginfo')) {
        clean_sweep_log_message('API: Fresh WordPress loaded but get_bloginfo() missing', 'warning');
        return false;
    }

    clean_sweep_api_ensure_site_path_constants();
    clean_sweep_log_message('API: WordPress loaded from fresh environment: ' . ABSPATH, 'info');
    return true;
}

if (!clean_sweep_api_initialize_wordpress()) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    if (in_array($action, clean_sweep_api_recovery_setup_actions(), true)) {
        exit;
    }
    CleanSweep_ApiResponse::sendError(
        'Recovery environment is not ready. Complete fresh WordPress setup first.',
        'ENVIRONMENT_NOT_READY',
        ['recovery_required' => true]
    );
}

// ============================================================================
// SCANNER v2 — WP-Cron kick hook
// ============================================================================
// Registered on every API bootstrap so scheduled single events can drain a scan
// when loopback HTTP is unavailable. The event is scheduled by CleanSweep_Scanner::executeKick.
if (function_exists('add_action') && !has_action('clean_sweep_scan_kick')) {
    add_action('clean_sweep_scan_kick', function ($scan_id) {
        $scan_id = is_string($scan_id) ? $scan_id : '';
        if ($scan_id === '') {
            return;
        }
        try {
            if (!class_exists('CleanSweep_Scanner', false)) {
                require_once (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__) . '/')
                    . 'features/security/scan/Scanner.php';
            }
            $scanner = CleanSweep_Scanner::create($scan_id);
            $scanner->drain($scan_id);
        } catch (Throwable $e) {
            if (function_exists('clean_sweep_log_message')) {
                clean_sweep_log_message('CleanSweep_Scanner WP-Cron kick failed: ' . $e->getMessage(), 'error');
            }
        }
    }, 10, 1);
}

// ============================================================================
// SECURITY VERIFICATION
// ============================================================================

/**
 * Verify API request security
 * Can be extended for additional security (API keys, tokens, etc.)
 *
 * @return bool True if request is secure
 */
function clean_sweep_api_verify_security() {
    // Add your security checks here
    // For example: API key verification, rate limiting, etc.
    
    // For now, we rely on WordPress's built-in nonce verification
    // which can be done by individual endpoints as needed
    
    return true;
}

// Verify basic security
clean_sweep_api_verify_security();

if (!function_exists('clean_sweep_request_flag')) {
    function clean_sweep_request_flag($key, $default = false) {
        if (!isset($_POST[$key]) && !isset($_GET[$key])) {
            return $default;
        }
        $v = $_POST[$key] ?? $_GET[$key];
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }
        return (bool) $v;
    }
}

if (!function_exists('clean_sweep_toolkit_root')) {
    function clean_sweep_toolkit_root() {
        if (defined('CLEAN_SWEEP_ROOT') && CLEAN_SWEEP_ROOT) {
            return rtrim(str_replace('\\', '/', CLEAN_SWEEP_ROOT), '/') . '/';
        }
        return rtrim(str_replace('\\', '/', dirname(__DIR__)), '/') . '/';
    }
}

if (!function_exists('clean_sweep_verify_recovery_nonce')) {
    function clean_sweep_verify_recovery_nonce() {
        if (!defined('CLEAN_SWEEP_RECOVERY_MODE') || !CLEAN_SWEEP_RECOVERY_MODE) {
            return;
        }
        $nonce = $_POST['nonce'] ?? $_GET['nonce'] ?? '';
        if ($nonce === '') {
            return;
        }
        if (function_exists('wp_verify_nonce') && !wp_verify_nonce($nonce, 'clean_sweep_recovery_nonce')) {
            CleanSweep_ApiResponse::sendError('Security verification failed', 'NONCE_ERROR');
        }
    }
}

// ============================================================================
// API ENDPOINT REGISTRATION
// ============================================================================

/**
 * Register available API endpoints
 * Each endpoint file should define its actions
 *
 * @return array List of available endpoints
 */
function clean_sweep_api_get_endpoints() {
    return [
        'plugins' => [
            'file' => __DIR__ . '/plugins.php',
            'actions' => ['analyze_plugins', 'reinstall_plugins', 'create_plugin_backup', 'get_progress']
        ],
        'themes' => [
            'file' => __DIR__ . '/themes.php',
            'actions' => ['analyze_themes', 'reinstall_themes', 'create_theme_backup', 'get_progress']
        ],
        'malware' => [
            'file' => __DIR__ . '/malware.php',
            'actions' => [
                // CleanSweep_Scanner v2 (public + internal kick)
                'start',
                'resume',
                'status',
                'cancel',
                'get_threats',
                'get_db_content',
                'get_scan_profiles',
                'latest_scan',
                'internal_kick',  // HMAC-validated, called only by the loopback curl
            ]
        ],
        // Separate from malware — WPVulnerability.com network checks
        'vulnerabilities' => [
            'file' => __DIR__ . '/vulnerabilities.php',
            'actions' => [
                'scan',                   // primary (CleanSweep_Scanner hub UI)
                'scan_vulnerabilities',   // alias / back-compat
                'latest',                 // restore last UI payload after refresh
                'latest_vulnerabilities',
            ]
        ],
        'core' => [
            'file' => __DIR__ . '/core.php',
            'actions' => ['reinstall_core', 'get_core_progress', 'get_progress', 'get_version_options', 'get_core_info']
        ],
        'upload' => [
            'file' => __DIR__ . '/upload.php',
            'actions' => ['upload_zip', 'inspect_zip', 'extract_zip', 'discard_upload', 'get_upload_progress', 'get_upload_status', 'get_upload_limits', 'get_progress']
        ],
        'cleanup' => [
            'file' => __DIR__ . '/cleanup.php',
            'actions' => ['run_cleanup', 'cleanup_tool', 'get_cleanup_status', 'delete_file', 'delete_directory']
        ],
        'integrity' => [
            'file' => __DIR__ . '/integrity.php',
            'actions' => [
                'get_baseline_info',
                'status',
                'establish_baseline',
                'check_integrity',
                'clear_baseline',
                'export_baseline',
                'import_baseline',
                'export_snapshot',
                'import_snapshot',
                'skip_snapshot',
                'find_elsewhere',
                'set_include_all_media',
                'get_progress',
                'enable_live_watch',
                'disable_live_watch',
                'live_watch_tick',
            ]
        ],
        'users' => [
            'file' => __DIR__ . '/users.php',
            'actions' => [
                'audit_users',
                'revoke_app_passwords',
                'destroy_sessions',
                'demote_user',
                'delete_user',
            ]
        ],
        'cron' => [
            'file' => __DIR__ . '/cron.php',
            'actions' => [
                'audit_cron',
                'delete_event',
                'clear_hook',
                'cancel_as_action',
            ]
        ],
        'recovery' => [
            'file' => __DIR__ . '/bootstrap.php',
            'actions' => [
                'start_fresh_setup',
                'get_setup_progress',
                'upload_wordpress_zip',
                'clear_all_caches',
                'check_canary',
            ]
        ],
    ];
}

// ============================================================================
// API REQUEST ROUTING
// ============================================================================

/**
 * Parse a PHP ini size (512M, 128K, 1G) to bytes. 0 / -1 / empty → 0 (unlimited).
 */
function clean_sweep_api_ini_bytes($value) {
    $value = trim((string) $value);
    if ($value === '' || $value === '-1' || $value === '0') {
        return 0;
    }
    if (preg_match('/^(\d+)([KMG])$/i', $value, $m)) {
        $v = (int) $m[1];
        switch (strtoupper($m[2])) {
            case 'K':
                return $v * 1024;
            case 'M':
                return $v * 1024 * 1024;
            case 'G':
                return $v * 1024 * 1024 * 1024;
        }
    }
    return (int) $value;
}

/**
 * True when this request's target script is api/bootstrap.php (not an
 * endpoint that included bootstrap for JSON headers).
 */
function clean_sweep_api_is_bootstrap_script() {
    $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script === '') {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    }
    return basename(str_replace('\\', '/', $script)) === 'bootstrap.php';
}

/**
 * PHP discards $_POST/$_FILES when the body is larger than post_max_size.
 * CONTENT_LENGTH is still set.
 */
function clean_sweep_api_post_exceeds_limit() {
    $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($content_length <= 0) {
        return false;
    }
    $post_max = clean_sweep_api_ini_bytes((string) ini_get('post_max_size'));
    return $post_max > 0 && $content_length > $post_max;
}

function clean_sweep_api_available_actions() {
    $actions = [];
    foreach (clean_sweep_api_get_endpoints() as $endpoint) {
        if (empty($endpoint['actions']) || !is_array($endpoint['actions'])) {
            continue;
        }
        foreach ($endpoint['actions'] as $name) {
            $actions[] = $name;
        }
    }
    if (function_exists('clean_sweep_api_recovery_setup_actions')) {
        foreach (clean_sweep_api_recovery_setup_actions() as $name) {
            $actions[] = $name;
        }
    }
    return array_values(array_unique($actions));
}

function clean_sweep_api_send_post_too_large() {
    $post_max = (string) ini_get('post_max_size');
    $upload_max = (string) ini_get('upload_max_filesize');
    $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $limit_bytes = 512 * 1024 * 1024;
    foreach ([$post_max, $upload_max] as $ini) {
        $bytes = clean_sweep_api_ini_bytes($ini);
        if ($bytes > 0 && $bytes < $limit_bytes) {
            $limit_bytes = $bytes;
        }
    }
    if (function_exists('clean_sweep_upload_limit_bytes')) {
        $limit_bytes = clean_sweep_upload_limit_bytes();
    }
    CleanSweep_ApiResponse::sendError(
        'Upload exceeds this host\'s PHP post size limit (post_max_size=' . $post_max . ', upload_max_filesize=' . $upload_max . '). Raise those limits, then retry.',
        'FILE_TOO_LARGE',
        [
            'content_length' => $content_length,
            'post_max_size' => $post_max,
            'upload_max_filesize' => $upload_max,
            'limit_bytes' => $limit_bytes,
        ]
    );
}

/**
 * Route API request to appropriate handler.
 * Endpoint files include this bootstrap for JSON headers; they run their own
 * switch. Only route when the request target is bootstrap.php itself.
 */
function clean_sweep_api_route_request() {
    if (!clean_sweep_api_is_bootstrap_script()) {
        return;
    }

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if (function_exists('clean_sweep_api_recovery_setup_actions') && in_array($action, clean_sweep_api_recovery_setup_actions(), true)) {
        return;
    }

    if ($action === '' && !empty($_FILES['file'])) {
        $action = 'upload_zip';
    }

    if ($action === '') {
        if (clean_sweep_api_post_exceeds_limit()) {
            clean_sweep_api_send_post_too_large();
        }
        CleanSweep_ApiResponse::sendError(
            'No action specified.',
            'NO_ACTION',
            ['available_actions' => clean_sweep_api_available_actions()]
        );
    }

    $endpoints = clean_sweep_api_get_endpoints();

    foreach ($endpoints as $endpoint) {
        if (in_array($action, $endpoint['actions'], true)) {
            require_once $endpoint['file'];
            return;
        }
    }

    CleanSweep_ApiResponse::sendError(
        "Unknown action: $action",
        'UNKNOWN_ACTION',
        ['available_actions' => clean_sweep_api_available_actions()]
    );
}

// Route only when this file is the request target.
clean_sweep_api_route_request();
