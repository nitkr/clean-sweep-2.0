<?php
/**
 * Clean Sweep - File Upload API Endpoint
 *
 * JSON API for file upload and ZIP extraction operations.
 * Returns JSON responses for Alpine.js frontend consumption.
 *
 * @version 1.0
 */

$clean_sweep_upload_helpers_only = defined('CLEAN_SWEEP_UPLOAD_FIXTURE') && CLEAN_SWEEP_UPLOAD_FIXTURE;

$cs_upload_root = dirname(__DIR__);
if (is_readable($cs_upload_root . '/features/utilities/zip-safe.php')) {
    require_once $cs_upload_root . '/features/utilities/zip-safe.php';
}
if (is_readable($cs_upload_root . '/features/utilities/zip-extract.php')) {
    require_once $cs_upload_root . '/features/utilities/zip-extract.php';
}

if (!$clean_sweep_upload_helpers_only) {

// Include unified bootstrap for CORS headers, OPTIONS handling, and error management
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/system/CleanSweep_Functions.php';

// Increase upload limits for this endpoint
@ini_set('upload_max_filesize', '512M');
@ini_set('post_max_size', '512M');
@ini_set('max_execution_time', 300);
@ini_set('max_input_time', 300);

// Load recovery bootstrap for WordPress environment
require_once __DIR__ . '/../includes/system/CleanSweep_FreshEnvironment.php';
require_once __DIR__ . '/../includes/system/CleanSweep_RecoveryBootstrap.php';

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
// ROUTE REQUEST — action first. Only upload_zip (or empty action + FILES) reads $_FILES.
// ============================================================================

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === '' && !empty($_FILES['file'])) {
    $action = 'upload_zip';
}

switch ($action) {
    case 'upload_zip':
        if (function_exists('clean_sweep_require_toolkit_ok')) {
            clean_sweep_require_toolkit_ok();
        }
        clean_sweep_handle_upload();
        break;

    case 'inspect_zip':
        clean_sweep_handle_inspect_zip();
        break;

    case 'extract_zip':
        if (function_exists('clean_sweep_require_toolkit_ok')) {
            clean_sweep_require_toolkit_ok();
        }
        clean_sweep_handle_extract_zip();
        break;

    case 'discard_upload':
        if (function_exists('clean_sweep_require_toolkit_ok')) {
            clean_sweep_require_toolkit_ok();
        }
        clean_sweep_handle_discard_upload();
        break;

    case 'get_upload_status':
        clean_sweep_handle_get_upload_status();
        break;

    case 'get_progress':
    case 'get_upload_progress':
        clean_sweep_handle_get_progress();
        break;

    default:
        CleanSweep_ApiResponse::sendError('Unknown action: ' . $action, 'UNKNOWN_ACTION');
}

} // end HTTP bootstrap + router (skipped for fixtures)

// ============================================================================
// HANDLER FUNCTIONS
// ============================================================================

/**
 * Handle file upload request
 */
function clean_sweep_handle_upload() {
    // Check if file was uploaded
    if (empty($_FILES['file'])) {
        CleanSweep_ApiResponse::sendError('No file uploaded', 'NO_FILE');
    }
    
    $file = $_FILES['file'];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            CleanSweep_ApiResponse::sendError('File exceeds upload size limit', 'FILE_TOO_LARGE', [
                'limit_bytes' => clean_sweep_upload_limit_bytes(),
                'error_code' => $file['error'],
            ]);
        }
        $error_messages = [
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
        ];
        
        $error_message = $error_messages[$file['error']] ?? 'Unknown upload error';
        CleanSweep_ApiResponse::sendError($error_message, 'UPLOAD_ERROR', ['error_code' => $file['error']]);
    }

    $limit_bytes = clean_sweep_upload_limit_bytes();
    if ((int) $file['size'] > $limit_bytes) {
        CleanSweep_ApiResponse::sendError('File exceeds upload size limit', 'FILE_TOO_LARGE', [
            'limit_bytes' => $limit_bytes,
        ]);
    }
    
    // Validate file type
    $allowed_extensions = ['zip'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_extensions)) {
        CleanSweep_ApiResponse::sendError('Invalid file type. Only ZIP files are allowed.', 'INVALID_FILE_TYPE', [
            'allowed' => $allowed_extensions,
            'received' => $file_extension
        ]);
    }
    
    clean_sweep_upload_janitor();

    $safe_name = basename((string) $file['name']);
    $safe_lower = strtolower($safe_name);
    if ($safe_name === '' || $safe_name === '.' || $safe_name === '..'
        || $safe_lower === '.htaccess' || $safe_lower === '.php'
        || substr($safe_lower, -4) === '.php'
    ) {
        CleanSweep_ApiResponse::sendError('Invalid file name', 'INVALID_FILE_TYPE');
    }

    $upload_id = 'upload_' . bin2hex(random_bytes(8));
    $temp_dir = clean_sweep_upload_temp_dir($upload_id);

    if (!defined('CLEAN_SWEEP_TEMP_DIR') || !clean_sweep_ensure_writable_dir(CLEAN_SWEEP_TEMP_DIR)
        || !@mkdir($temp_dir, 0755, true)
    ) {
        CleanSweep_ApiResponse::sendError(
            'Cannot write to backups/temp. The web user needs write access to the Clean Sweep backups folder.',
            'TEMP_DIR_ERROR'
        );
    }

    $uploaded_path = $temp_dir . $safe_name;

    if (!move_uploaded_file($file['tmp_name'], $uploaded_path)) {
        clean_sweep_upload_rmdir($temp_dir);
        CleanSweep_ApiResponse::sendError('Failed to save uploaded file', 'SAVE_ERROR');
    }

    if (!clean_sweep_upload_zip_magic_ok($uploaded_path)) {
        @unlink($uploaded_path);
        clean_sweep_upload_rmdir($temp_dir);
        CleanSweep_ApiResponse::sendError('Not a ZIP archive', 'NOT_A_ZIP');
    }

    $now = time();
    $expires = $now + clean_sweep_upload_ttl_seconds();
    $sha = hash_file('sha256', $uploaded_path) ?: '';
    clean_sweep_upload_write_index($temp_dir, [
        'filename' => $safe_name,
        'bytes' => (int) $file['size'],
        'sha256' => $sha,
        'created' => $now,
        'expires' => $expires,
    ]);

    clean_sweep_log_message('UPLOAD stage filename=' . $safe_name . ' bytes=' . (int) $file['size'] . ' id=' . $upload_id);

    CleanSweep_ApiResponse::sendSuccess([
        'upload_id' => $upload_id,
        'filename' => $safe_name,
        'filesize' => $file['size'],
        'sha256' => $sha,
        'expires_at' => $expires,
        'ready_for_inspection' => true,
    ], 'File uploaded successfully');
}

/**
 * Handle ZIP extraction request
 */
function clean_sweep_handle_inspect_zip() {
    $located = clean_sweep_upload_locate($_POST['upload_id'] ?? $_GET['upload_id'] ?? '');
    if (empty($located['ok'])) {
        CleanSweep_ApiResponse::sendError($located['message'], $located['code']);
    }

    $inspect = clean_sweep_inspect_uploaded_zip($located['zip']);
    if (empty($inspect['ok'])) {
        CleanSweep_ApiResponse::sendError($inspect['message'] ?? 'Unsafe ZIP', $inspect['code'] ?? 'UNSAFE_ZIP', [
            'upload_id' => $located['id'],
            'has_traversal_entries' => !empty($inspect['has_traversal_entries']),
            'warnings' => $inspect['warnings'] ?? [],
        ]);
    }

    $filename = '';
    if (!empty($located['index']['filename'])) {
        $filename = (string) $located['index']['filename'];
    } else {
        $filename = basename($located['zip']);
    }

    clean_sweep_log_message(
        'UPLOAD inspect id=' . $located['id']
        . ' kind=' . ($inspect['kind'] ?? 'unknown')
        . ' slug=' . ($inspect['slug'] ?? '')
        . ' exists=' . (!empty($inspect['existing']['present']) ? '1' : '0')
    );

    CleanSweep_ApiResponse::sendSuccess([
        'upload_id' => $located['id'],
        'filename' => $filename,
        'kind' => $inspect['kind'],
        'confidence' => $inspect['confidence'],
        'suggested_destination' => $inspect['suggested_destination'],
        'slug' => $inspect['slug'],
        'name' => $inspect['name'],
        'version' => $inspect['version'],
        'entry_count' => $inspect['entry_count'],
        'uncompressed_bytes' => $inspect['uncompressed_bytes'],
        'has_traversal_entries' => false,
        'has_symlink_entries' => !empty($inspect['has_symlink_entries']),
        'backup_eligible' => !empty($inspect['backup_eligible']),
        'existing' => $inspect['existing'],
        'warnings' => $inspect['warnings'],
    ]);
}

function clean_sweep_handle_discard_upload() {
    $raw = (string) ($_POST['upload_id'] ?? $_GET['upload_id'] ?? '');
    if (trim($raw) === '') {
        CleanSweep_ApiResponse::sendError('Upload ID not specified', 'MISSING_UPLOAD_ID');
    }
    $id = clean_sweep_upload_parse_id($raw);
    if ($id === '') {
        CleanSweep_ApiResponse::sendError('Upload not found or expired', 'UPLOAD_NOT_FOUND');
    }
    $temp_dir = clean_sweep_upload_temp_dir($id);
    if (is_dir($temp_dir)) {
        clean_sweep_upload_rmdir($temp_dir);
    }
    CleanSweep_ApiResponse::sendSuccess(['discarded' => true]);
}

function clean_sweep_handle_extract_zip() {
    // activate / network are ignored — never activate_plugin / switch_theme.
    $destination = isset($_POST['destination']) ? (string) $_POST['destination'] : '';
    $custom_rel = isset($_POST['custom_rel']) ? (string) $_POST['custom_rel'] : '';

    $gate = clean_sweep_upload_gate_destination($destination);
    if (empty($gate['ok'])) {
        CleanSweep_ApiResponse::sendError($gate['message'], $gate['code']);
    }

    $resolved = clean_sweep_resolve_upload_destination($gate['id'], $custom_rel);
    if (empty($resolved['ok'])) {
        CleanSweep_ApiResponse::sendError($resolved['message'], $resolved['code']);
    }

    if ($gate['id'] === 'custom' && ($resolved['id'] ?? '') === 'root'
        && !clean_sweep_upload_request_flag('confirm_root')
    ) {
        CleanSweep_ApiResponse::sendError(
            'Confirm that you understand this writes at the WordPress root',
            'OVERWRITE_NOT_CONFIRMED'
        );
    }

    $tk_hit = clean_sweep_upload_dest_toolkit_collision($resolved['abs'], '');
    if (!empty($tk_hit['hit'])) {
        CleanSweep_ApiResponse::sendError($tk_hit['message'], 'DEST_TOOLKIT');
    }

    $located = clean_sweep_upload_locate($_POST['upload_id'] ?? '');
    if (empty($located['ok'])) {
        CleanSweep_ApiResponse::sendError($located['message'], $located['code']);
    }

    $inspect = clean_sweep_inspect_uploaded_zip($located['zip']);
    if (empty($inspect['ok'])) {
        CleanSweep_ApiResponse::sendError($inspect['message'] ?? 'Unsafe ZIP', $inspect['code'] ?? 'UNSAFE_ZIP', [
            'has_traversal_entries' => !empty($inspect['has_traversal_entries']),
            'warnings' => $inspect['warnings'] ?? [],
        ]);
    }

    $tk_pkg = clean_sweep_upload_assert_package_toolkit($located['zip'], $resolved, $inspect);
    if (empty($tk_pkg['ok'])) {
        CleanSweep_ApiResponse::sendError($tk_pkg['message'] ?? 'Destination collides with the Clean Sweep toolkit', $tk_pkg['code'] ?? 'DEST_TOOLKIT');
    }

    $is_package = ($resolved['id'] === 'plugins' || $resolved['id'] === 'themes');
    $needs_overwrite = !$is_package || !empty($inspect['existing']['present']);
    if ($needs_overwrite && !clean_sweep_upload_request_flag('confirm_overwrite')) {
        CleanSweep_ApiResponse::sendError('Overwrite confirmation is required', 'OVERWRITE_NOT_CONFIRMED');
    }

    $progress_file = 'extract_' . $located['id'] . '.progress';
    $zip_name = !empty($located['index']['filename'])
        ? (string) $located['index']['filename']
        : basename($located['zip']);

    $progress_cb = function ($msg) use ($progress_file, $zip_name) {
        if (!function_exists('clean_sweep_write_progress_file')) {
            return;
        }
        @clean_sweep_write_progress_file($progress_file, [
            'status' => 'running',
            'progress' => 50,
            'message' => (string) $msg,
            'phase' => 'install',
            'file' => $zip_name,
            'timestamp' => time(),
        ]);
    };

    clean_sweep_log_message(
        'UPLOAD install id=' . $located['id']
        . ' dest=' . $resolved['id']
        . ' slug=' . ($inspect['slug'] ?? '')
        . ' backup=' . (clean_sweep_upload_request_flag('create_backup') ? '1' : '0')
    );

    try {
        $results = clean_sweep_upload_run_extract($located['zip'], $resolved, $inspect, [
            'upload_id' => $located['id'],
            'filename' => $zip_name,
            'create_backup' => clean_sweep_upload_request_flag('create_backup'),
            'progress_cb' => $progress_cb,
        ]);

        if (empty($results['success'])) {
            $code = $results['code'] ?? 'EXTRACT_ERROR';
            $error_progress = [
                'status' => 'error',
                'progress' => 0,
                'message' => $results['message'] ?? 'Extract failed',
                'phase' => 'error',
                'file' => $zip_name,
                'timestamp' => time()
            ];
            @clean_sweep_write_progress_file($progress_file, $error_progress);
            $err_details = ['progress_file' => $progress_file];
            if (!empty($results['destination_name'])) {
                $err_details['destination_name'] = $results['destination_name'];
            }
            if (!empty($results['backup_rel'])) {
                $err_details['backup_rel'] = $results['backup_rel'];
            }
            CleanSweep_ApiResponse::sendError($results['message'] ?? 'Extract failed', $code, $err_details);
        }

        $processed = clean_sweep_process_extraction_results($results);
        foreach ([
            'mode', 'destination', 'destination_rel', 'slug', 'plugin_file',
            'name', 'version', 'overwritten', 'backup_rel', 'sealed',
            'verification_baseline', 'verification_baseline_files',
            'files_extracted_count', 'message', 'warnings', 'destination_name',
        ] as $key) {
            if (array_key_exists($key, $results)) {
                $processed[$key] = $results[$key];
            }
        }
        $processed['destination'] = $results['destination'] ?? $resolved['id'];
        if (!isset($processed['destination_rel'])) {
            $processed['destination_rel'] = $results['destination_rel'] ?? $resolved['rel'];
        }

        $final_progress = [
            'status' => 'complete',
            'progress' => 100,
            'message' => $processed['message'] ?? 'Done',
            'phase' => 'complete',
            'file' => $zip_name,
            'timestamp' => time()
        ];
        @clean_sweep_write_progress_file($progress_file, $final_progress);

        @unlink($located['zip']);
        @unlink(rtrim($located['temp_dir'], '/') . '/index.json');
        @rmdir($located['temp_dir']);

        clean_sweep_log_message(
            'UPLOAD done id=' . $located['id']
            . ' success=1 dest_rel=' . ($processed['destination_rel'] ?? '')
        );

        CleanSweep_ApiResponse::sendSuccess([
            'results' => $processed,
            'progress_file' => $progress_file
        ], 'ZIP extraction completed');

    } catch (Exception $e) {
        $error_progress = [
            'status' => 'error',
            'progress' => 0,
            'message' => $e->getMessage(),
            'phase' => 'error',
            'file' => $zip_name,
            'timestamp' => time()
        ];
        @clean_sweep_write_progress_file($progress_file, $error_progress);

        CleanSweep_ApiResponse::sendError('Extraction failed: ' . $e->getMessage(), 'EXTRACT_ERROR', [
            'progress_file' => $progress_file
        ]);
    }
}

/**
 * Handle get upload status request
 */
function clean_sweep_handle_get_upload_status() {
    $raw = (string) ($_POST['upload_id'] ?? $_GET['upload_id'] ?? '');
    if (trim($raw) === '') {
        CleanSweep_ApiResponse::sendError('Upload ID not specified', 'MISSING_UPLOAD_ID');
    }

    $located = clean_sweep_upload_locate($raw);
    if (empty($located['ok'])) {
        if (($located['code'] ?? '') === 'MISSING_UPLOAD_ID') {
            CleanSweep_ApiResponse::sendError($located['message'], $located['code']);
        }
        CleanSweep_ApiResponse::sendSuccess([
            'exists' => false,
            'message' => $located['message'] ?? 'Upload not found or expired',
        ]);
    }

    $index = $located['index'] ?? [];
    CleanSweep_ApiResponse::sendSuccess([
        'exists' => true,
        'ready' => true,
        'filename' => $index['filename'] ?? basename($located['zip']),
        'filesize' => isset($index['bytes']) ? (int) $index['bytes'] : (int) filesize($located['zip']),
        'expires_at' => isset($index['expires']) ? (int) $index['expires'] : null,
    ]);
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
 * Extract accepts uploads / wp-content / root / custom / plugins / themes.
 *
 * @return array{ok:bool,id?:string,code?:string,message?:string}
 */
function clean_sweep_upload_gate_destination(string $id): array {
    $id = trim($id);
    if ($id === '') {
        return [
            'ok' => false,
            'code' => 'MISSING_DESTINATION',
            'message' => 'Destination is required',
        ];
    }
    if (!in_array($id, ['uploads', 'wp-content', 'root', 'custom', 'plugins', 'themes'], true)) {
        return [
            'ok' => false,
            'code' => 'INVALID_DESTINATION',
            'message' => 'Invalid destination',
        ];
    }
    return ['ok' => true, 'id' => $id];
}

/**
 * Resolve a dest id to an absolute directory. Ids are routing keys, not site-relative paths.
 *
 * @return array{ok:bool,id?:string,abs?:string,rel?:string,writable?:bool,exists?:bool,code?:string,message?:string}
 */
function clean_sweep_resolve_upload_destination(string $id, string $custom_rel = ''): array {
    $id = trim($id);
    if ($id === '') {
        return [
            'ok' => false,
            'code' => 'MISSING_DESTINATION',
            'message' => 'Destination is required',
        ];
    }

    $abs = '';
    $unavail = [
        'ok' => false,
        'code' => 'INSTALLER_UNAVAILABLE',
        'message' => 'Destination is not available',
    ];

    if ($id === 'plugins') {
        if (defined('ORIGINAL_WP_PLUGIN_DIR') && is_dir(ORIGINAL_WP_PLUGIN_DIR)) {
            $abs = ORIGINAL_WP_PLUGIN_DIR;
        } elseif (defined('WP_PLUGIN_DIR') && is_dir(WP_PLUGIN_DIR)) {
            $abs = WP_PLUGIN_DIR;
        } else {
            return $unavail;
        }
    } elseif ($id === 'themes') {
        if (defined('ORIGINAL_WP_CONTENT_DIR') && is_dir(ORIGINAL_WP_CONTENT_DIR . '/themes')) {
            $abs = ORIGINAL_WP_CONTENT_DIR . '/themes';
        } elseif (function_exists('get_theme_root')) {
            $abs = (string) get_theme_root();
        } elseif (defined('WP_CONTENT_DIR') && is_dir(WP_CONTENT_DIR . '/themes')) {
            $abs = WP_CONTENT_DIR . '/themes';
        } else {
            return $unavail;
        }
    } elseif ($id === 'uploads') {
        if (function_exists('wp_upload_dir') && function_exists('get_bloginfo')) {
            $uploads = wp_upload_dir();
            if (!empty($uploads['basedir'])) {
                $abs = $uploads['basedir'];
            }
        }
        if ($abs === '') {
            clean_sweep_upload_load_site_paths();
            $content = class_exists('CleanSweep_SitePaths') ? CleanSweep_SitePaths::content_dir() : null;
            if (is_string($content) && $content !== '') {
                $abs = rtrim($content, '/') . '/uploads';
            }
        }
        if ($abs === '') {
            return [
                'ok' => false,
                'code' => 'DEST_NOT_WRITABLE',
                'message' => 'Could not resolve uploads directory',
            ];
        }
    } elseif ($id === 'wp-content') {
        if (defined('ORIGINAL_WP_CONTENT_DIR') && ORIGINAL_WP_CONTENT_DIR) {
            $abs = ORIGINAL_WP_CONTENT_DIR;
        } else {
            clean_sweep_upload_load_site_paths();
            $content = class_exists('CleanSweep_SitePaths') ? CleanSweep_SitePaths::content_dir() : null;
            if (is_string($content) && $content !== '') {
                $abs = $content;
            }
        }
        if ($abs === '') {
            return [
                'ok' => false,
                'code' => 'DEST_NOT_WRITABLE',
                'message' => 'Could not resolve wp-content directory',
            ];
        }
    } elseif ($id === 'root') {
        $abs = clean_sweep_upload_site_root();
        if ($abs === '') {
            return [
                'ok' => false,
                'code' => 'INVALID_DESTINATION',
                'message' => 'Could not resolve site root',
            ];
        }
    } elseif ($id === 'custom') {
        $custom = clean_sweep_upload_resolve_custom_rel($custom_rel);
        if (empty($custom['ok'])) {
            return [
                'ok' => false,
                'code' => $custom['code'] ?? 'DEST_OUTSIDE_SITE',
                'message' => $custom['message'] ?? 'Custom path is not allowed',
            ];
        }
        if (!empty($custom['as_root'])) {
            $id = 'root';
            $abs = $custom['abs'];
        } else {
            $abs = $custom['abs'];
        }
    } else {
        return [
            'ok' => false,
            'code' => 'INVALID_DESTINATION',
            'message' => 'Unknown destination',
        ];
    }

    $abs = rtrim(str_replace('\\', '/', (string) $abs), '/');
    if ($abs === '') {
        return [
            'ok' => false,
            'code' => 'INVALID_DESTINATION',
            'message' => 'Could not resolve destination',
        ];
    }

    $real = is_dir($abs) ? realpath($abs) : false;
    $check = $real ? str_replace('\\', '/', $real) : $abs;
    if (strpos($check, '/core/fresh/') !== false || preg_match('#/core/fresh$#', $check)) {
        return $unavail;
    }

    $exists = is_dir($abs);
    $writable = $exists ? is_writable($abs) : is_writable(dirname($abs));
    $rel = clean_sweep_upload_rel_under_site($abs);

    return [
        'ok' => true,
        'id' => $id,
        'abs' => $abs,
        'rel' => $rel,
        'writable' => (bool) $writable,
        'exists' => $exists,
    ];
}

/**
 * Site root for dest-escape checks. Never DOCUMENT_ROOT. Rejects /core/fresh/.
 */
function clean_sweep_upload_site_root(): string {
    clean_sweep_upload_load_site_paths();
    $root = '';
    if (class_exists('CleanSweep_SitePaths')) {
        $candidate = CleanSweep_SitePaths::root();
        if (is_string($candidate) && $candidate !== '') {
            $root = $candidate;
        }
    }
    if ($root === '') {
        clean_sweep_upload_load_detect_site_root();
        if (function_exists('clean_sweep_detect_site_root')) {
            $root = (string) clean_sweep_detect_site_root();
        }
    }
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if ($root === '' || $root === '/' || strpos($root . '/', '/core/fresh/') !== false) {
        return '';
    }
    return $root . '/';
}

function clean_sweep_upload_load_site_paths(): void {
    if (class_exists('CleanSweep_SitePaths')) {
        return;
    }
    $path = dirname(__DIR__) . '/features/security/scan/SitePaths.php';
    if (is_readable($path)) {
        require_once $path;
    }
}

function clean_sweep_upload_load_detect_site_root(): void {
    if (function_exists('clean_sweep_detect_site_root')) {
        return;
    }
    $path = dirname(__DIR__) . '/features/maintenance/core-reinstall.php';
    if (is_readable($path)) {
        require_once $path;
    }
}

function clean_sweep_upload_rel_under_site(string $abs): string {
    $site = clean_sweep_upload_site_root();
    if ($site === '') {
        return '';
    }
    $site_n = rtrim(str_replace('\\', '/', $site), '/');
    $abs_n = rtrim(str_replace('\\', '/', $abs), '/');
    if ($abs_n === $site_n) {
        return '';
    }
    if (strpos($abs_n, $site_n . '/') === 0) {
        return substr($abs_n, strlen($site_n) + 1);
    }
    return '';
}

function clean_sweep_upload_custom_rel_is_root(string $custom_rel): bool {
    $rel = str_replace('\\', '/', trim($custom_rel));
    $rel = trim($rel, '/');
    if ($rel === '' || $rel === '.') {
        return true;
    }
    return (bool) preg_match('#^(?:\./)+$#', str_replace('\\', '/', trim($custom_rel)));
}

/**
 * Resolve dest=custom against the site root. Walks existing ancestors and realpaths
 * them so outbound symlinks cannot escape. `.` / `./` is dest=root, not custom.
 *
 * @return array{ok:bool,abs?:string,as_root?:bool,code?:string,message?:string}
 */
function clean_sweep_upload_resolve_custom_rel(string $custom_rel): array {
    $custom_rel = str_replace('\\', '/', $custom_rel);
    if (strpos($custom_rel, "\0") !== false || preg_match('/^[A-Za-z]:/', $custom_rel)
        || preg_match('#(?:^|/)\.\.(?:/|$)#', $custom_rel)
    ) {
        return [
            'ok' => false,
            'code' => 'DEST_OUTSIDE_SITE',
            'message' => 'Custom path is not allowed',
        ];
    }
    if (clean_sweep_upload_custom_rel_is_root($custom_rel)) {
        return [
            'ok' => false,
            'code' => 'DEST_OUTSIDE_SITE',
            'message' => 'Use WordPress root instead of a custom path of .',
        ];
    }

    $site = clean_sweep_upload_site_root();
    if ($site === '') {
        return [
            'ok' => false,
            'code' => 'DEST_OUTSIDE_SITE',
            'message' => 'Could not resolve site root',
        ];
    }
    $site_real = realpath($site);
    if ($site_real === false) {
        return [
            'ok' => false,
            'code' => 'DEST_OUTSIDE_SITE',
            'message' => 'Could not resolve site root',
        ];
    }
    $site_real_n = rtrim(str_replace('\\', '/', $site_real), '/');

    $rel = preg_replace('#^(?:\./)+#', '', ltrim($custom_rel, '/'));
    $rel = trim((string) $rel, '/');
    if ($rel === '' || $rel === '.') {
        return [
            'ok' => false,
            'code' => 'DEST_OUTSIDE_SITE',
            'message' => 'Use WordPress root instead of a custom path of .',
        ];
    }

    $abs = $site_real_n . '/' . $rel;
    $walked = clean_sweep_upload_realpath_existing_prefix($abs, $site_real_n);
    if (empty($walked['ok'])) {
        return $walked;
    }
    $abs = $walked['abs'];

    if (file_exists($abs)) {
        $real = realpath($abs);
        if ($real === false) {
            return [
                'ok' => false,
                'code' => 'DEST_OUTSIDE_SITE',
                'message' => 'Custom path could not be resolved',
            ];
        }
        $abs = rtrim(str_replace('\\', '/', $real), '/');
        if (!clean_sweep_upload_path_equals_or_inside($abs, $site_real_n)) {
            return [
                'ok' => false,
                'code' => 'DEST_OUTSIDE_SITE',
                'message' => 'Custom path is outside the site',
            ];
        }
    }

    if (clean_sweep_upload_path_equals($abs, $site_real_n)) {
        return ['ok' => true, 'abs' => $site_real_n, 'as_root' => true];
    }

    return ['ok' => true, 'abs' => $abs, 'as_root' => false];
}

/**
 * Walk up to the first existing ancestor, realpath it, reject unless under $site_real.
 * Fail closed if realpath of an existing prefix is false.
 *
 * @return array{ok:bool,abs?:string,code?:string,message?:string}
 */
function clean_sweep_upload_realpath_existing_prefix(string $abs, string $site_real): array {
    $abs = rtrim(str_replace('\\', '/', $abs), '/');
    $site_real = rtrim(str_replace('\\', '/', $site_real), '/');
    $tail = [];
    $cursor = $abs;

    while ($cursor !== '' && $cursor !== '/') {
        if (file_exists($cursor)) {
            $prefix_real = realpath($cursor);
            if ($prefix_real === false) {
                return [
                    'ok' => false,
                    'code' => 'DEST_OUTSIDE_SITE',
                    'message' => 'Custom path could not be resolved',
                ];
            }
            $prefix_n = rtrim(str_replace('\\', '/', $prefix_real), '/');
            if (!clean_sweep_upload_path_equals_or_inside($prefix_n, $site_real)) {
                return [
                    'ok' => false,
                    'code' => 'DEST_OUTSIDE_SITE',
                    'message' => 'Custom path is outside the site',
                ];
            }
            $joined = $prefix_n;
            foreach (array_reverse($tail) as $seg) {
                if ($seg === '' || $seg === '.') {
                    continue;
                }
                if ($seg === '..') {
                    return [
                        'ok' => false,
                        'code' => 'DEST_OUTSIDE_SITE',
                        'message' => 'Custom path is outside the site',
                    ];
                }
                $joined .= '/' . $seg;
            }
            return ['ok' => true, 'abs' => $joined];
        }
        $tail[] = basename($cursor);
        $parent = str_replace('\\', '/', dirname($cursor));
        if ($parent === $cursor) {
            break;
        }
        $cursor = rtrim($parent, '/');
    }

    return [
        'ok' => false,
        'code' => 'DEST_OUTSIDE_SITE',
        'message' => 'Custom path is outside the site',
    ];
}

/**
 * After mkdir, dest must still realpath under the site. Fail closed.
 *
 * @return array{ok:bool,abs?:string,code?:string,message?:string}
 */
function clean_sweep_upload_assert_under_site(string $destAbs, string $site): array {
    $site = rtrim(str_replace('\\', '/', $site), '/');
    $site_real = realpath($site);
    if ($site_real === false) {
        return [
            'ok' => false,
            'code' => 'DEST_OUTSIDE_SITE',
            'message' => 'Could not resolve site root',
        ];
    }
    $dest_real = realpath($destAbs);
    if ($dest_real === false) {
        return [
            'ok' => false,
            'code' => 'DEST_OUTSIDE_SITE',
            'message' => 'Destination could not be resolved',
        ];
    }
    $site_n = rtrim(str_replace('\\', '/', $site_real), '/');
    $dest_n = rtrim(str_replace('\\', '/', $dest_real), '/');
    if (!clean_sweep_upload_path_equals_or_inside($dest_n, $site_n)) {
        return [
            'ok' => false,
            'code' => 'DEST_OUTSIDE_SITE',
            'message' => 'Destination is outside the site',
        ];
    }
    return ['ok' => true, 'abs' => $dest_n];
}

function clean_sweep_upload_ttl_seconds(): int {
    return 7200;
}

function clean_sweep_upload_request_flag(string $key): bool {
    if (function_exists('clean_sweep_request_flag')) {
        return (bool) clean_sweep_request_flag($key, false);
    }
    if (!isset($_POST[$key]) && !isset($_GET[$key])) {
        return false;
    }
    $v = $_POST[$key] ?? $_GET[$key];
    if (is_bool($v)) {
        return $v;
    }
    return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
}

function clean_sweep_upload_parse_id(string $raw): string {
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^upload_[a-f0-9]{16}$/', $raw)) {
        return '';
    }
    return $raw;
}

function clean_sweep_upload_temp_dir(string $upload_id): string {
    $base = defined('CLEAN_SWEEP_TEMP_DIR') ? CLEAN_SWEEP_TEMP_DIR : '';
    return rtrim(str_replace('\\', '/', (string) $base), '/') . '/' . $upload_id . '/';
}

function clean_sweep_upload_write_index(string $temp_dir, array $index): bool {
    $path = rtrim($temp_dir, '/') . '/index.json';
    $json = json_encode($index, JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($path, $json) !== false;
}

function clean_sweep_upload_load_index(string $temp_dir): ?array {
    $path = rtrim($temp_dir, '/') . '/index.json';
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function clean_sweep_upload_is_expired(?array $index, string $temp_dir = ''): bool {
    $ttl = clean_sweep_upload_ttl_seconds();
    if (is_array($index) && isset($index['expires'])) {
        return time() > (int) $index['expires'];
    }
    if ($temp_dir !== '' && is_dir($temp_dir)) {
        $mtime = @filemtime($temp_dir);
        if ($mtime) {
            return (time() - $mtime) > $ttl;
        }
    }
    return false;
}

function clean_sweep_upload_find_zip(string $temp_dir): string {
    $zips = glob(rtrim($temp_dir, '/') . '/*.zip') ?: [];
    return !empty($zips) ? (string) $zips[0] : '';
}

function clean_sweep_upload_rmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        @rmdir($dir);
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            clean_sweep_upload_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function clean_sweep_upload_janitor(): void {
    if (!defined('CLEAN_SWEEP_TEMP_DIR') || !is_dir(CLEAN_SWEEP_TEMP_DIR)) {
        return;
    }
    $dirs = glob(rtrim(CLEAN_SWEEP_TEMP_DIR, '/') . '/upload_*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs as $dir) {
        $index = clean_sweep_upload_load_index($dir);
        if (clean_sweep_upload_is_expired($index, $dir)) {
            clean_sweep_upload_rmdir($dir);
        }
    }
}

/**
 * @return array{ok:bool,id?:string,temp_dir?:string,zip?:string,index?:?array,code?:string,message?:string}
 */
function clean_sweep_upload_locate(string $raw_id): array {
    $raw_id = trim($raw_id);
    if ($raw_id === '') {
        return [
            'ok' => false,
            'code' => 'MISSING_UPLOAD_ID',
            'message' => 'Upload ID not specified',
        ];
    }
    $id = clean_sweep_upload_parse_id($raw_id);
    if ($id === '') {
        return [
            'ok' => false,
            'code' => 'UPLOAD_NOT_FOUND',
            'message' => 'Upload not found or expired',
        ];
    }
    $temp_dir = clean_sweep_upload_temp_dir($id);
    if (!is_dir($temp_dir)) {
        return [
            'ok' => false,
            'code' => 'UPLOAD_NOT_FOUND',
            'message' => 'Upload not found or expired',
        ];
    }
    $index = clean_sweep_upload_load_index($temp_dir);
    if (clean_sweep_upload_is_expired($index, $temp_dir)) {
        return [
            'ok' => false,
            'code' => 'UPLOAD_EXPIRED',
            'message' => 'Upload has expired',
        ];
    }
    $zip = clean_sweep_upload_find_zip($temp_dir);
    if ($zip === '') {
        return [
            'ok' => false,
            'code' => 'ZIP_NOT_FOUND',
            'message' => 'ZIP file not found in upload',
        ];
    }
    return [
        'ok' => true,
        'id' => $id,
        'temp_dir' => $temp_dir,
        'zip' => $zip,
        'index' => $index,
    ];
}

/**
 * Parse plugin/theme file headers from a string (first 8 KiB). Do not use get_file_data().
 *
 * @param array<string,string> $headers field => header name
 * @return array<string,string>
 */
function clean_sweep_parse_headers(string $contents, array $headers): array {
    $chunk = substr($contents, 0, 8 * 1024);
    $chunk = str_replace("\r", "\n", $chunk);
    $out = [];
    foreach ($headers as $field => $name) {
        if (is_int($field)) {
            $field = $name;
        }
        $name = (string) $name;
        if ($name !== '' && preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi', $chunk, $m) && isset($m[1]) && $m[1] !== '') {
            $out[$field] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]));
        } else {
            $out[$field] = '';
        }
    }
    return $out;
}

function clean_sweep_upload_zip_magic_ok(string $zip_path): bool {
    $fh = @fopen($zip_path, 'rb');
    if (!$fh) {
        return false;
    }
    $magic = fread($fh, 4);
    fclose($fh);
    return $magic === "PK\x03\x04" || $magic === "PK\x05\x06" || $magic === "PK\x07\x08";
}

/**
 * Parse a PHP ini size (512M, 128K, 1G) to bytes. 0 / -1 / empty → 0 (unlimited).
 */
function clean_sweep_upload_ini_bytes($value): int {
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
 * Effective upload cap: min(512M, upload_max_filesize, post_max_size).
 * ini_set cannot raise a host hard limit — this is the real cap.
 */
function clean_sweep_upload_limit_bytes(): int {
    $limit = 512 * 1024 * 1024;
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $bytes = clean_sweep_upload_ini_bytes((string) ini_get($key));
        if ($bytes > 0 && $bytes < $limit) {
            $limit = $bytes;
        }
    }
    return $limit;
}

function clean_sweep_upload_silence_index(): string {
    return "<?php\n// Silence is golden.\n";
}

/** Real php_flag engine off directive — not a comment or substring. */
function clean_sweep_upload_htaccess_has_engine_off(string $body): bool {
    foreach (preg_split('/\R/', $body) as $line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#' || $trim[0] === ';') {
            continue;
        }
        if (preg_match('/^php_flag\s+engine\s+off\b/i', $trim)) {
            return true;
        }
    }
    return false;
}

function clean_sweep_upload_htaccess_last_is_engine_off(string $body): bool {
    $last = '';
    foreach (preg_split('/\R/', $body) as $line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#' || $trim[0] === ';') {
            continue;
        }
        $last = $trim;
    }
    return (bool) preg_match('/^php_flag\s+engine\s+off\b/i', $last);
}

function clean_sweep_upload_ensure_htaccess_engine_off(string $path): void {
    $body = is_file($path) ? (string) @file_get_contents($path) : '';
    if (clean_sweep_upload_htaccess_last_is_engine_off($body)) {
        return;
    }
    if ($body !== '' && substr($body, -1) !== "\n") {
        $body .= "\n";
    }
    @file_put_contents($path, $body . "php_flag engine off\n");
}

function clean_sweep_upload_dest_is_uploads_basedir(string $dest_abs): bool {
    $dest_abs = rtrim(str_replace('\\', '/', $dest_abs), '/');
    if ($dest_abs === '') {
        return false;
    }
    $resolved = clean_sweep_resolve_upload_destination('uploads');
    if (empty($resolved['ok']) || empty($resolved['abs'])) {
        return false;
    }
    $uploads = rtrim(str_replace('\\', '/', (string) $resolved['abs']), '/');
    $dest_cmp = is_dir($dest_abs) ? realpath($dest_abs) : $dest_abs;
    $up_cmp = is_dir($uploads) ? realpath($uploads) : $uploads;
    if ($dest_cmp === false || $up_cmp === false) {
        return false;
    }
    return clean_sweep_upload_path_equals((string) $dest_cmp, (string) $up_cmp);
}

/**
 * Snapshot uploads basedir guards *before* extract so zip-planted files
 * are not treated as an already-hardened operator file.
 *
 * @return array{is_uploads:bool,dir?:string,htaccess?:bool,htaccess_flag?:bool,index?:bool,user_ini?:bool}
 */
function clean_sweep_upload_uploads_harden_snapshot(string $dest_abs): array {
    $dest_abs = rtrim(str_replace('\\', '/', $dest_abs), '/');
    if ($dest_abs === '' || !clean_sweep_upload_dest_is_uploads_basedir($dest_abs)) {
        return ['is_uploads' => false];
    }
    $dir = is_dir($dest_abs) ? realpath($dest_abs) : $dest_abs;
    if ($dir === false) {
        return ['is_uploads' => false];
    }
    $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
    $ht = $dir . '/.htaccess';
    $ht_body = is_file($ht) ? (string) @file_get_contents($ht) : '';
    return [
        'is_uploads' => true,
        'dir' => $dir,
        'htaccess' => is_file($ht),
        'htaccess_flag' => $ht_body !== '' && clean_sweep_upload_htaccess_has_engine_off($ht_body),
        'index' => is_file($dir . '/index.php'),
        'user_ini' => is_file($dir . '/.user.ini'),
    ];
}

/**
 * After a successful extract whose dest *is* the uploads basedir, ensure
 * php_flag engine off is the final directive and a silence index.php.
 * Do not overwrite a pre-extract hardened .htaccess.
 */
function clean_sweep_upload_harden_uploads_if_basedir(string $dest_abs, array $pre = [], array $extracted = []): void {
    if (empty($pre['is_uploads'])) {
        $pre = clean_sweep_upload_uploads_harden_snapshot($dest_abs);
    }
    if (empty($pre['is_uploads'])) {
        return;
    }
    $dir = isset($pre['dir']) ? (string) $pre['dir'] : rtrim(str_replace('\\', '/', $dest_abs), '/');
    if ($dir === '') {
        return;
    }

    $wrote = [];
    foreach ($extracted as $name) {
        $norm = ltrim(str_replace('\\', '/', (string) $name), '/');
        if ($norm !== '') {
            $wrote[$norm] = true;
        }
    }
    $wrote_ht = !empty($wrote['.htaccess']);
    $wrote_index = !empty($wrote['index.php']);
    $wrote_user = !empty($wrote['.user.ini']);

    $ht = $dir . '/.htaccess';
    // Skip only if this zip did not write basedir .htaccess and pre-extract was already hardened.
    if ($wrote_ht || empty($pre['htaccess']) || empty($pre['htaccess_flag'])) {
        clean_sweep_upload_ensure_htaccess_engine_off($ht);
    }

    $index = $dir . '/index.php';
    $silence = clean_sweep_upload_silence_index();
    $current = is_file($index) ? (string) @file_get_contents($index) : '';
    if ($wrote_index || empty($pre['index'])) {
        if ($current !== $silence) {
            @file_put_contents($index, $silence);
        }
    }

    $user_ini = $dir . '/.user.ini';
    if (($wrote_user || empty($pre['user_ini'])) && is_file($user_ini)) {
        @file_put_contents($user_ini, "engine = Off\n");
    }

    foreach ($extracted as $name) {
        $norm = ltrim(str_replace('\\', '/', (string) $name), '/');
        $base = basename($norm);
        if ($norm === '.htaccess' || $norm === 'index.php' || $norm === '.user.ini') {
            continue;
        }
        $path = $dir . '/' . $norm;
        if ($base === '.htaccess' && is_file($path)) {
            clean_sweep_upload_ensure_htaccess_engine_off($path);
        }
        if ($base === '.user.ini' && is_file($path)) {
            @file_put_contents($path, "engine = Off\n");
        }
    }
}

function clean_sweep_upload_wp_loaded(): bool {
    return defined('ABSPATH') && function_exists('get_bloginfo');
}

function clean_sweep_upload_installed_version(string $kind, string $slug, string $packageRoot): string {
    $slug = trim(str_replace('\\', '/', $slug), '/');
    if ($slug === '') {
        return '';
    }
    if ($kind === 'plugin' && clean_sweep_upload_wp_loaded() && function_exists('get_plugins')) {
        $plugins = get_plugins();
        if (is_array($plugins)) {
            foreach ($plugins as $file => $data) {
                $dir = dirname((string) $file);
                $base = pathinfo((string) $file, PATHINFO_FILENAME);
                if ($dir === $slug || $base === $slug) {
                    return (string) ($data['Version'] ?? '');
                }
            }
        }
    }
    if ($kind === 'theme' && function_exists('wp_get_theme')) {
        $theme = wp_get_theme($slug);
        if (is_object($theme) && method_exists($theme, 'get') && (!method_exists($theme, 'errors') || !$theme->errors())) {
            $ver = (string) $theme->get('Version');
            if ($ver !== '') {
                return $ver;
            }
        }
    }
    $root = rtrim(str_replace('\\', '/', $packageRoot), '/') . '/' . $slug;
    return clean_sweep_upload_scan_installed_version($root, $kind);
}

function clean_sweep_upload_scan_installed_version(string $path, string $kind): string {
    $path = rtrim(str_replace('\\', '/', $path), '/');
    if (is_file($path)) {
        $bytes = @file_get_contents($path);
        return is_string($bytes) ? (clean_sweep_parse_headers($bytes, ['Version' => 'Version'])['Version'] ?? '') : '';
    }
    if (!is_dir($path)) {
        $single = $path . '.php';
        if (is_file($single)) {
            $bytes = @file_get_contents($single);
            return is_string($bytes) ? (clean_sweep_parse_headers($bytes, ['Version' => 'Version'])['Version'] ?? '') : '';
        }
        return '';
    }
    $scan = function (string $dir, int $remain) use (&$scan, $kind): string {
        $items = @scandir($dir);
        if (!is_array($items)) {
            return '';
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . '/' . $item;
            if (is_file($full)) {
                $base = strtolower($item);
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if ($kind === 'theme' && $base === 'style.css') {
                    $bytes = @file_get_contents($full);
                    if (is_string($bytes)) {
                        $ver = clean_sweep_parse_headers($bytes, ['Version' => 'Version'])['Version'] ?? '';
                        if ($ver !== '') {
                            return $ver;
                        }
                    }
                }
                if ($kind !== 'theme' && $ext === 'php') {
                    $bytes = @file_get_contents($full);
                    if (is_string($bytes)) {
                        $h = clean_sweep_parse_headers($bytes, [
                            'Name' => 'Plugin Name',
                            'Version' => 'Version',
                        ]);
                        if ($h['Name'] !== '' && $h['Version'] !== '') {
                            return $h['Version'];
                        }
                    }
                }
            } elseif (is_dir($full) && $remain > 1) {
                $found = $scan($full, $remain - 1);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    };
    return $scan($path, 2);
}

/**
 * Open a staged zip without extracting. Traversal / zip-bomb → UNSAFE_ZIP.
 *
 * @return array<string,mixed>
 */
function clean_sweep_inspect_uploaded_zip(string $zip_path): array {
    if ($zip_path === '' || !is_file($zip_path)) {
        return ['ok' => false, 'code' => 'ZIP_NOT_FOUND', 'message' => 'ZIP file not found'];
    }
    if (!clean_sweep_upload_zip_magic_ok($zip_path)) {
        return ['ok' => false, 'code' => 'NOT_A_ZIP', 'message' => 'Not a ZIP archive'];
    }
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'code' => 'EXTRACT_ERROR', 'message' => 'ZipArchive is not available'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return ['ok' => false, 'code' => 'EXTRACT_ERROR', 'message' => 'Could not open ZIP'];
    }

    $num = $zip->numFiles;
    if ($num > 20000) {
        $zip->close();
        return [
            'ok' => false,
            'code' => 'UNSAFE_ZIP',
            'message' => 'ZIP has too many entries',
            'warnings' => ['zip_bomb_ratio'],
        ];
    }

    $uncomp = 0;
    $comp = 0;
    $has_symlink = false;
    $symlink_names = [];
    $tops = [];
    $top_is_dir = [];
    $plugin_header = null;
    $theme_header = null;
    $plugin_header_file = '';
    $theme_header_file = '';
    $has_php = false;
    $has_style = false;

    for ($i = 0; $i < $num; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            $zip->close();
            return ['ok' => false, 'code' => 'UNSAFE_ZIP', 'message' => 'ZIP contains an unreadable entry'];
        }
        $norm = str_replace('\\', '/', $name);
        if (clean_sweep_upload_zip_entry_is_unsafe($name)) {
            $zip->close();
            return [
                'ok' => false,
                'code' => 'UNSAFE_ZIP',
                'message' => 'ZIP contains an unsafe entry',
                'has_traversal_entries' => true,
            ];
        }

        if (clean_sweep_upload_entry_is_symlink($zip, $i)) {
            $has_symlink = true;
            $symlink_names[] = $norm;
        }

        $stat = $zip->statIndex($i);
        if (is_array($stat)) {
            $uncomp += (int) ($stat['size'] ?? 0);
            $comp += (int) ($stat['comp_size'] ?? 0);
        }

        $trimmed = trim($norm, '/');
        if ($trimmed === '') {
            continue;
        }
        $parts = explode('/', $trimmed);
        $top = $parts[0];
        $tops[$top] = true;
        if (count($parts) > 1 || substr($norm, -1) === '/') {
            $top_is_dir[$top] = true;
        } elseif (!isset($top_is_dir[$top])) {
            $top_is_dir[$top] = false;
        }
        $depth = count($parts);
        $base = $parts[$depth - 1];
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));

        if ($ext === 'php') {
            $has_php = true;
            if ($depth <= 2 && $plugin_header === null) {
                $bytes = $zip->getFromIndex($i, 8192);
                if (is_string($bytes)) {
                    $h = clean_sweep_parse_headers($bytes, [
                        'Name' => 'Plugin Name',
                        'Version' => 'Version',
                        'TextDomain' => 'Text Domain',
                    ]);
                    if ($h['Name'] !== '') {
                        $plugin_header = $h;
                        $plugin_header_file = $trimmed;
                    }
                }
            }
        }
        if (strtolower($base) === 'style.css') {
            $has_style = true;
            if ($depth <= 2 && $theme_header === null) {
                $bytes = $zip->getFromIndex($i, 8192);
                if (is_string($bytes)) {
                    $h = clean_sweep_parse_headers($bytes, [
                        'Name' => 'Theme Name',
                        'Version' => 'Version',
                    ]);
                    if ($h['Name'] !== '') {
                        $theme_header = $h;
                        $theme_header_file = $trimmed;
                    }
                }
            }
        }
    }
    $zip->close();

    if ($uncomp > 512 * 1024 * 1024 || ($uncomp / max($comp, 1)) > 50) {
        return [
            'ok' => false,
            'code' => 'UNSAFE_ZIP',
            'message' => 'ZIP exceeds safety limits',
            'warnings' => ['zip_bomb_ratio'],
        ];
    }

    $warnings = [];
    $kind = 'unknown';
    $confidence = 'none';
    $name = '';
    $version = '';

    if ($plugin_header && $theme_header) {
        $kind = 'plugin';
        $confidence = 'header';
        $name = $plugin_header['Name'];
        $version = $plugin_header['Version'];
        $warnings[] = 'mixed_plugin_and_theme';
    } elseif ($plugin_header) {
        $kind = 'plugin';
        $confidence = 'header';
        $name = $plugin_header['Name'];
        $version = $plugin_header['Version'];
    } elseif ($theme_header) {
        $kind = 'theme';
        $confidence = 'header';
        $name = $theme_header['Name'];
        $version = $theme_header['Version'];
    } elseif (count($tops) === 1 && $has_php) {
        $kind = 'plugin';
        $confidence = 'structure';
    } elseif (count($tops) === 1 && $has_style) {
        $kind = 'theme';
        $confidence = 'structure';
    }

    $unique_top = count($tops) === 1;
    $unique_top_dir = false;
    if ($unique_top) {
        $only = (string) array_key_first($tops);
        $unique_top_dir = !empty($top_is_dir[$only]);
    }
    if (!$unique_top_dir) {
        $warnings[] = 'no_top_level_directory';
    }

    $filename = basename($zip_path);
    if (strtolower(substr($filename, -4)) === '.zip') {
        $filename = substr($filename, 0, -4);
    }
    if ($unique_top) {
        $slug = (string) array_key_first($tops);
    } elseif ($plugin_header_file !== '') {
        $dir = dirname($plugin_header_file);
        $slug = ($dir !== '.' && $dir !== '') ? explode('/', str_replace('\\', '/', $dir))[0] : pathinfo($plugin_header_file, PATHINFO_FILENAME);
    } elseif ($theme_header_file !== '') {
        $dir = dirname($theme_header_file);
        $slug = ($dir !== '.' && $dir !== '') ? explode('/', str_replace('\\', '/', $dir))[0] : $filename;
    } else {
        $slug = $filename;
    }

    $suggested = '';
    if ($kind === 'plugin') {
        $suggested = 'plugins';
    } elseif ($kind === 'theme') {
        $suggested = 'themes';
    }

    $existing = [
        'present' => false,
        'installed_version' => '',
        'path_rel' => '',
    ];
    if ($slug !== '' && ($kind === 'plugin' || $kind === 'theme')) {
        $resolved = clean_sweep_resolve_upload_destination($kind === 'plugin' ? 'plugins' : 'themes');
        if (!empty($resolved['ok']) && !empty($resolved['abs'])) {
            $package_root = rtrim(str_replace('\\', '/', $resolved['abs']), '/');
            $slug_abs = $package_root . '/' . $slug;
            $present_path = '';
            if (is_dir($slug_abs)) {
                $present_path = $slug_abs;
            } elseif (is_file($slug_abs)) {
                $present_path = $slug_abs;
            } elseif (strtolower(substr($slug, -4)) !== '.php' && is_file($slug_abs . '.php')) {
                $present_path = $slug_abs . '.php';
            }
            $existing['present'] = $present_path !== '';
            if ($present_path !== '') {
                $existing['path_rel'] = clean_sweep_upload_rel_under_site($present_path);
                $existing['installed_version'] = clean_sweep_upload_installed_version($kind, $slug, $package_root);
            }
        }
    }

    return [
        'ok' => true,
        'kind' => $kind,
        'confidence' => $confidence,
        'suggested_destination' => $suggested,
        'slug' => $slug,
        'name' => $name,
        'version' => $version,
        'entry_count' => $num,
        'uncompressed_bytes' => $uncomp,
        'has_traversal_entries' => false,
        'has_symlink_entries' => $has_symlink,
        'symlink_names' => $symlink_names,
        'backup_eligible' => $unique_top_dir,
        'existing' => $existing,
        'warnings' => $warnings,
    ];
}

/**
 * dest=plugins + kind=theme (or reverse) → DEST_MISMATCH.
 * dest=plugins|themes + kind=unknown → NOT_A_PACKAGE.
 *
 * @return array{ok:bool,code?:string,message?:string}
 */
/**
 * Reject dest=plugins/themes before backup/install when dest+slug or any zip
 * entry would land inside the toolkit. Post-install dest_name check stays as backup.
 *
 * @return array{ok:bool,code?:string,message?:string}
 */
function clean_sweep_upload_assert_package_toolkit(string $zip_path, array $resolved, array $inspect, $tk = null): array {
    $dest_id = (string) ($resolved['id'] ?? '');
    if ($dest_id !== 'plugins' && $dest_id !== 'themes') {
        return ['ok' => true];
    }
    $dest_abs = (string) ($resolved['abs'] ?? '');
    if ($dest_abs === '') {
        return ['ok' => true];
    }
    $slug = trim(str_replace('\\', '/', (string) ($inspect['slug'] ?? '')), '/');
    if ($slug !== '') {
        $hit = clean_sweep_upload_dest_toolkit_collision($dest_abs, $slug, $tk);
        if (!empty($hit['hit'])) {
            return [
                'ok' => false,
                'code' => 'DEST_TOOLKIT',
                'message' => $hit['message'] ?? 'Extract would write into the Clean Sweep toolkit',
            ];
        }
    }
    $walk = clean_sweep_upload_walk_zip_entries($zip_path, $dest_abs, $tk);
    if (empty($walk['ok'])) {
        return [
            'ok' => false,
            'code' => $walk['code'] ?? 'DEST_TOOLKIT',
            'message' => $walk['message'] ?? 'ZIP entry would write into the Clean Sweep toolkit',
        ];
    }
    return ['ok' => true];
}

function clean_sweep_upload_assert_dest_kind(string $dest_id, string $kind): array {
    if ($dest_id !== 'plugins' && $dest_id !== 'themes') {
        return ['ok' => true];
    }
    if ($kind === 'unknown' || $kind === '') {
        return [
            'ok' => false,
            'code' => 'NOT_A_PACKAGE',
            'message' => 'This ZIP is not a plugin or theme package',
        ];
    }
    $want = $dest_id === 'plugins' ? 'plugin' : 'theme';
    if ($kind !== $want) {
        return [
            'ok' => false,
            'code' => 'DEST_MISMATCH',
            'message' => 'Destination does not match the package type',
        ];
    }
    return ['ok' => true];
}

/**
 * create_backup=1 is only valid when inspect.backup_eligible (unique top-level directory).
 *
 * @return array{ok:bool,eligible?:bool,slug?:string,code?:string,message?:string}
 */
function clean_sweep_upload_assert_backup_request(array $inspect, bool $create_backup): array {
    if (!$create_backup) {
        return ['ok' => true, 'eligible' => !empty($inspect['backup_eligible'])];
    }
    if (empty($inspect['backup_eligible'])) {
        return [
            'ok' => false,
            'code' => 'BACKUP_SLUG_UNKNOWN',
            'message' => 'Archive has no single top-level folder to back up',
        ];
    }
    $slug = trim(str_replace('\\', '/', (string) ($inspect['slug'] ?? '')), '/');
    if ($slug === '' || strpos($slug, '/') !== false) {
        return [
            'ok' => false,
            'code' => 'BACKUP_SLUG_UNKNOWN',
            'message' => 'Archive has no single top-level folder to back up',
        ];
    }
    return ['ok' => true, 'eligible' => true, 'slug' => $slug];
}

function clean_sweep_upload_package_backup_abs(string $upload_id, string $slug): string {
    $root = defined('CLEAN_SWEEP_ROOT') ? rtrim(str_replace('\\', '/', (string) CLEAN_SWEEP_ROOT), '/') : '';
    $id = preg_replace('/[^a-z0-9_]/', '', strtolower($upload_id));
    $slug = trim(str_replace('\\', '/', $slug), '/');
    if ($root === '' || $id === '' || $slug === '') {
        return '';
    }
    return $root . '/backups/uploads/' . $id . '/' . $slug;
}

/**
 * Reinstall via WP upgrader (plugins/themes) or zip-slip-safe unzip (raw dests).
 *
 * @return array<string,mixed>
 */
function clean_sweep_upload_run_extract(string $zip_path, array $resolved, array $inspect, array $opts = []): array {
    $dest_id = (string) ($resolved['id'] ?? '');
    $kind = (string) ($inspect['kind'] ?? 'unknown');
    $kind_ok = clean_sweep_upload_assert_dest_kind($dest_id, $kind);
    if (empty($kind_ok['ok'])) {
        return [
            'success' => false,
            'code' => $kind_ok['code'] ?? 'DEST_MISMATCH',
            'message' => $kind_ok['message'] ?? 'Destination does not match the package type',
        ];
    }

    $tk_pkg = clean_sweep_upload_assert_package_toolkit($zip_path, $resolved, $inspect, $opts['toolkit'] ?? null);
    if (empty($tk_pkg['ok'])) {
        return [
            'success' => false,
            'code' => $tk_pkg['code'] ?? 'DEST_TOOLKIT',
            'message' => $tk_pkg['message'] ?? 'Extract would write into the Clean Sweep toolkit',
        ];
    }

    $is_package = ($dest_id === 'plugins' || $dest_id === 'themes');
    $backup_rel = '';
    $create_backup = !empty($opts['create_backup']);

    if ($is_package && $create_backup) {
        $bak = clean_sweep_upload_assert_backup_request($inspect, true);
        if (empty($bak['ok'])) {
            return [
                'success' => false,
                'code' => $bak['code'] ?? 'BACKUP_SLUG_UNKNOWN',
                'message' => $bak['message'] ?? 'Archive has no single top-level folder to back up',
            ];
        }
        $slug = (string) ($bak['slug'] ?? '');
        $src = rtrim(str_replace('\\', '/', (string) $resolved['abs']), '/') . '/' . $slug;
        if (is_dir($src)) {
            $upload_id = (string) ($opts['upload_id'] ?? '');
            $dest = clean_sweep_upload_package_backup_abs($upload_id, $slug);
            if ($dest === '' || !function_exists('clean_sweep_copy_package_dir')) {
                return [
                    'success' => false,
                    'code' => 'EXTRACT_ERROR',
                    'message' => 'Could not create package backup',
                ];
            }
            $copied = clean_sweep_copy_package_dir($src, $dest);
            if (empty($copied['ok'])) {
                return [
                    'success' => false,
                    'code' => $copied['code'] ?? 'EXTRACT_ERROR',
                    'message' => $copied['message'] ?? 'Could not create package backup',
                ];
            }
            $backup_rel = 'backups/uploads/' . $upload_id . '/' . $slug;
        }
    }

    if ($is_package) {
        if (!function_exists('clean_sweep_install_uploaded_package')) {
            return [
                'success' => false,
                'code' => 'INSTALLER_UNAVAILABLE',
                'message' => 'WordPress upgrader is not available',
            ];
        }
        $installed = clean_sweep_install_uploaded_package($zip_path, $kind, [
            'filename' => (string) ($opts['filename'] ?? basename($zip_path)),
            'preview_slug' => (string) ($inspect['slug'] ?? ''),
            'progress_cb' => $opts['progress_cb'] ?? null,
        ]);
        if (empty($installed['success'])) {
            $fail_name = (string) ($installed['destination_name'] ?? '');
            $preview = (string) ($inspect['slug'] ?? '');
            if ($backup_rel !== '' && $fail_name !== '' && $fail_name === $preview) {
                $installed['backup_rel'] = $backup_rel;
            }
            return $installed;
        }

        $dest_name = (string) ($installed['destination_name'] ?? $installed['slug'] ?? '');
        $dest_abs = (string) ($installed['destination'] ?? '');
        if ($dest_abs !== '') {
            $hit = clean_sweep_upload_dest_toolkit_collision($dest_abs, '');
            if (!empty($hit['hit'])) {
                return [
                    'success' => false,
                    'code' => 'DEST_TOOLKIT',
                    'message' => $hit['message'] ?? 'Install landed inside the Clean Sweep toolkit',
                    'destination_name' => $dest_name,
                    'backup_rel' => $backup_rel,
                ];
            }
        }
        if ($dest_name !== '') {
            $hit = clean_sweep_upload_dest_toolkit_collision((string) $resolved['abs'], $dest_name);
            if (!empty($hit['hit'])) {
                return [
                    'success' => false,
                    'code' => 'DEST_TOOLKIT',
                    'message' => $hit['message'] ?? 'Install landed inside the Clean Sweep toolkit',
                    'destination_name' => $dest_name,
                    'backup_rel' => $backup_rel,
                ];
            }
        }

        $preview = (string) ($inspect['slug'] ?? '');
        $show_backup = ($backup_rel !== '' && $dest_name !== '' && $dest_name === $preview);

        return [
            'success' => true,
            'mode' => $installed['mode'] ?? ($kind === 'theme' ? 'theme_upgrader' : 'plugin_upgrader'),
            'destination' => $dest_id,
            'destination_rel' => $installed['destination_rel'] ?? '',
            'destination_name' => $dest_name,
            'slug' => $dest_name,
            'plugin_file' => $installed['plugin_file'] ?? null,
            'name' => $installed['name'] ?? ($inspect['name'] ?? ''),
            'version' => $installed['version'] ?? ($inspect['version'] ?? ''),
            'overwritten' => !empty($inspect['existing']['present']),
            'backup_rel' => $show_backup ? $backup_rel : '',
            'sealed' => !empty($installed['sealed']),
            'verification_baseline' => !empty($installed['verification_baseline']),
            'verification_baseline_files' => (int) ($installed['verification_baseline_files'] ?? 0),
            'files_extracted_count' => 0,
            'message' => $installed['message'] ?? 'Reinstalled. If it was already active, it stays active.',
            'warnings' => $installed['warnings'] ?? [],
            'extracted_files' => [],
        ];
    }

    if (!function_exists('clean_sweep_safe_unzip')) {
        return [
            'success' => false,
            'code' => 'EXTRACT_ERROR',
            'message' => 'Safe unzip is not available',
        ];
    }

    $site_constrain = null;
    if ($dest_id === 'custom' || $dest_id === 'root') {
        $site_constrain = clean_sweep_upload_site_root();
    }
    $pre_harden = clean_sweep_upload_uploads_harden_snapshot((string) $resolved['abs']);
    $unzip = clean_sweep_safe_unzip(
        $zip_path,
        (string) $resolved['abs'],
        $opts['toolkit'] ?? null,
        $site_constrain
    );
    if (empty($unzip['success'])) {
        return [
            'success' => false,
            'code' => $unzip['code'] ?? 'EXTRACT_ERROR',
            'message' => $unzip['message'] ?? 'Extract failed',
            'errors' => $unzip['errors'] ?? [],
        ];
    }

    clean_sweep_upload_harden_uploads_if_basedir(
        (string) $resolved['abs'],
        $pre_harden,
        $unzip['extracted'] ?? ($unzip['extracted_files'] ?? [])
    );

    $dest_label = $resolved['rel'] !== '' ? $resolved['rel'] : ($dest_id === 'root' ? 'WordPress root' : $dest_id);
    return [
        'success' => true,
        'mode' => 'unzip',
        'destination' => $dest_id,
        'destination_rel' => $resolved['rel'] ?? '',
        'slug' => $inspect['slug'] ?? null,
        'sealed' => false,
        'files_extracted_count' => (int) ($unzip['count'] ?? count($unzip['extracted'] ?? [])),
        'extracted_files' => $unzip['extracted'] ?? ($unzip['extracted_files'] ?? []),
        'message' => 'Extracted to ' . $dest_label . '. New packages stay inactive. Existing ones keep their current active state.',
    ];
}

/**
 * Process ZIP extraction results for frontend consumption
 */
function clean_sweep_process_extraction_results($results) {
    if (!$results) {
        return null;
    }
    
    $processed = [
        'success' => false,
        'message' => '',
        'files_extracted' => [],
        'directories_created' => [],
        'errors' => []
    ];
    
    // Determine success
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
    
    // Process extracted files
    if (!empty($results['extracted_files'])) {
        $processed['files_extracted'] = is_array($results['extracted_files']) 
            ? $results['extracted_files'] 
            : explode("\n", $results['extracted_files']);
    }
    
    if (!empty($results['files_extracted'])) {
        $processed['files_extracted'] = is_array($results['files_extracted']) 
            ? $results['files_extracted'] 
            : explode("\n", $results['files_extracted']);
    }
    
    // Process directories
    if (!empty($results['directories_created'])) {
        $processed['directories_created'] = is_array($results['directories_created']) 
            ? $results['directories_created'] 
            : explode("\n", $results['directories_created']);
    }
    
    // Process errors
    if (!empty($results['errors'])) {
        $processed['errors'] = is_array($results['errors']) 
            ? $results['errors'] 
            : [$results['errors']];
    }
    
    // Summary
    $processed['summary'] = [
        'files_extracted_count' => count($processed['files_extracted']),
        'directories_created_count' => count($processed['directories_created']),
        'errors_count' => count($processed['errors']),
        'success' => $processed['success']
    ];
    
    return $processed;
}
