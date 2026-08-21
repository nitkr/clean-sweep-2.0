<?php
/**
 * JSON-safe plugin/theme install via WordPress upgraders.
 * Do not extractTo() into plugins/ or themes/. Never activate.
 */

function clean_sweep_install_log($message, $type = 'info') {
    if (function_exists('clean_sweep_log_message')) {
        clean_sweep_log_message($message, $type);
    }
}

/**
 * Recursive copy of one package directory. Refuses dest-escaping symlinks.
 * Never call the whole-tree plugin backup helper — that snapshots every plugin.
 *
 * @return array{ok:bool,code?:string,message?:string,copied?:int,skipped?:int}
 */
function clean_sweep_copy_package_dir($src, $dest) {
    $src = rtrim(str_replace('\\', '/', (string) $src), '/');
    $dest = rtrim(str_replace('\\', '/', (string) $dest), '/');
    if ($src === '' || $dest === '' || !is_dir($src)) {
        return [
            'ok' => false,
            'code' => 'BACKUP_SLUG_UNKNOWN',
            'message' => 'Package directory is not available',
        ];
    }

    $src_real = realpath($src);
    if ($src_real === false) {
        return [
            'ok' => false,
            'code' => 'BACKUP_SLUG_UNKNOWN',
            'message' => 'Package directory could not be resolved',
        ];
    }
    $src_real = rtrim(str_replace('\\', '/', $src_real), '/');

    if (!is_dir($dest) && !@mkdir($dest, 0755, true)) {
        return [
            'ok' => false,
            'code' => 'EXTRACT_ERROR',
            'message' => 'Could not create backup directory',
        ];
    }
    $dest_real = realpath($dest);
    if ($dest_real === false) {
        return [
            'ok' => false,
            'code' => 'EXTRACT_ERROR',
            'message' => 'Backup directory could not be resolved',
        ];
    }
    $dest_real = rtrim(str_replace('\\', '/', $dest_real), '/');

    $copied = 0;
    $skipped = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src_real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $item_path = str_replace('\\', '/', $item->getPathname());
        if ($item->isLink()) {
            $link_real = realpath($item_path);
            if ($link_real === false) {
                $skipped++;
                continue;
            }
            $link_n = rtrim(str_replace('\\', '/', $link_real), '/');
            if ($link_n !== $src_real && strpos($link_n, $src_real . '/') !== 0) {
                $skipped++;
                continue;
            }
            $skipped++;
            continue;
        }

        $rel = substr($item_path, strlen($src_real) + 1);
        $target = $dest_real . '/' . $rel;

        if ($item->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0755, true)) {
                return [
                    'ok' => false,
                    'code' => 'EXTRACT_ERROR',
                    'message' => 'Could not copy package directory',
                ];
            }
            continue;
        }

        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
            return [
                'ok' => false,
                'code' => 'EXTRACT_ERROR',
                'message' => 'Could not copy package directory',
            ];
        }
        $parent_real = realpath($parent);
        if ($parent_real === false) {
            return [
                'ok' => false,
                'code' => 'EXTRACT_ERROR',
                'message' => 'Backup copy escaped the destination',
            ];
        }
        $parent_n = rtrim(str_replace('\\', '/', $parent_real), '/');
        if ($parent_n !== $dest_real && strpos($parent_n, $dest_real . '/') !== 0) {
            return [
                'ok' => false,
                'code' => 'EXTRACT_ERROR',
                'message' => 'Backup copy escaped the destination',
            ];
        }

        $file_real = realpath($item_path);
        if ($file_real === false) {
            $skipped++;
            continue;
        }
        $file_n = str_replace('\\', '/', $file_real);
        if ($file_n !== $src_real && strpos($file_n, $src_real . '/') !== 0) {
            $skipped++;
            continue;
        }
        if (!@copy($item_path, $target)) {
            return [
                'ok' => false,
                'code' => 'EXTRACT_ERROR',
                'message' => 'Could not copy package file',
            ];
        }
        $copied++;
    }

    return ['ok' => true, 'copied' => $copied, 'skipped' => $skipped];
}

/**
 * Assert WP_PLUGIN_DIR / theme root matches the dest-resolver abs.
 *
 * @return array{ok:bool,abs?:string,resolved?:array,code?:string,message?:string}
 */
function clean_sweep_install_assert_package_root(string $kind): array {
    $id = $kind === 'theme' ? 'themes' : 'plugins';
    if (!function_exists('clean_sweep_resolve_upload_destination')) {
        return [
            'ok' => false,
            'code' => 'INSTALLER_UNAVAILABLE',
            'message' => 'Destination resolver is not available',
        ];
    }
    $resolved = clean_sweep_resolve_upload_destination($id);
    if (empty($resolved['ok'])) {
        return [
            'ok' => false,
            'code' => $resolved['code'] ?? 'INSTALLER_UNAVAILABLE',
            'message' => $resolved['message'] ?? 'Could not resolve package destination',
        ];
    }

    $dest_abs = rtrim(str_replace('\\', '/', (string) $resolved['abs']), '/');
    $dest_real = is_dir($dest_abs) ? realpath($dest_abs) : false;
    if ($dest_real === false) {
        return [
            'ok' => false,
            'code' => 'INSTALLER_UNAVAILABLE',
            'message' => 'Package destination does not exist',
        ];
    }
    $dest_real = rtrim(str_replace('\\', '/', $dest_real), '/');

    if ($kind === 'theme') {
        if (function_exists('get_theme_root')) {
            $wp_dir = (string) get_theme_root();
        } elseif (defined('WP_CONTENT_DIR') && WP_CONTENT_DIR) {
            $wp_dir = rtrim((string) WP_CONTENT_DIR, '/') . '/themes';
        } else {
            $wp_dir = '';
        }
    } else {
        $wp_dir = defined('WP_PLUGIN_DIR') ? (string) WP_PLUGIN_DIR : '';
    }

    $wp_real = ($wp_dir !== '' && is_dir($wp_dir)) ? realpath($wp_dir) : false;
    if ($wp_real === false) {
        return [
            'ok' => false,
            'code' => 'INSTALLER_UNAVAILABLE',
            'message' => 'WordPress package directory is not available',
        ];
    }
    $wp_real = rtrim(str_replace('\\', '/', $wp_real), '/');

    if ($wp_real !== $dest_real) {
        return [
            'ok' => false,
            'code' => 'INSTALLER_UNAVAILABLE',
            'message' => 'WordPress package directory does not match the destination',
        ];
    }

    if (strpos($wp_real, '/core/fresh/') !== false || (bool) preg_match('#/core/fresh$#', $wp_real)) {
        return [
            'ok' => false,
            'code' => 'INSTALLER_UNAVAILABLE',
            'message' => 'Package destination is a fresh-core path',
        ];
    }

    $tk = '';
    if (defined('CLEAN_SWEEP_ROOT') && CLEAN_SWEEP_ROOT) {
        $tk_real = realpath(CLEAN_SWEEP_ROOT);
        $tk = $tk_real ? rtrim(str_replace('\\', '/', $tk_real), '/') : '';
    }
    if ($tk !== '' && ($wp_real === $tk || strpos($wp_real, $tk . '/') === 0)) {
        return [
            'ok' => false,
            'code' => 'INSTALLER_UNAVAILABLE',
            'message' => 'Package destination is inside the toolkit',
        ];
    }

    return ['ok' => true, 'abs' => $dest_real, 'resolved' => $resolved];
}

/**
 * WP_Filesystem() — direct method only. No FTP credential prompt.
 *
 * @return array{ok:bool,code?:string,message?:string}
 */
function clean_sweep_install_init_filesystem(): array {
    if (!defined('ABSPATH') || ABSPATH === '') {
        return [
            'ok' => false,
            'code' => 'FS_UNAVAILABLE',
            'message' => 'WordPress is not loaded',
        ];
    }

    $file_php = rtrim((string) ABSPATH, '/') . '/wp-admin/includes/file.php';
    if (!function_exists('WP_Filesystem')) {
        if (!is_readable($file_php)) {
            return [
                'ok' => false,
                'code' => 'FS_UNAVAILABLE',
                'message' => 'WordPress filesystem API is not available',
            ];
        }
        require_once $file_php;
    }
    if (!function_exists('WP_Filesystem')) {
        return [
            'ok' => false,
            'code' => 'FS_UNAVAILABLE',
            'message' => 'WordPress filesystem API is not available',
        ];
    }

    if (function_exists('get_filesystem_method') && get_filesystem_method() !== 'direct') {
        return [
            'ok' => false,
            'code' => 'FS_UNAVAILABLE',
            'message' => 'Direct filesystem access is required',
        ];
    }

    $ok = WP_Filesystem();
    global $wp_filesystem;
    if (!$ok || !is_object($wp_filesystem)) {
        return [
            'ok' => false,
            'code' => 'FS_UNAVAILABLE',
            'message' => 'Could not initialize the WordPress filesystem',
        ];
    }
    $method = isset($wp_filesystem->method) ? (string) $wp_filesystem->method : '';
    if ($method !== 'direct') {
        return [
            'ok' => false,
            'code' => 'FS_UNAVAILABLE',
            'message' => 'Direct filesystem access is required',
        ];
    }

    return ['ok' => true];
}

/**
 * @return array{ok:bool,code?:string,message?:string}
 */
function clean_sweep_install_load_upgrader_classes(): array {
    if (!defined('ABSPATH') || ABSPATH === '') {
        return [
            'ok' => false,
            'code' => 'INSTALLER_UNAVAILABLE',
            'message' => 'WordPress is not loaded',
        ];
    }
    $admin = rtrim((string) ABSPATH, '/') . '/wp-admin/includes/';
    foreach (['class-wp-upgrader.php', 'class-plugin-upgrader.php', 'class-theme-upgrader.php'] as $file) {
        $path = $admin . $file;
        if (is_readable($path)) {
            require_once $path;
        }
    }
    if (
        !class_exists('WP_Upgrader')
        || !class_exists('Plugin_Upgrader')
        || !class_exists('Theme_Upgrader')
        || !class_exists('WP_Upgrader_Skin')
    ) {
        return [
            'ok' => false,
            'code' => 'INSTALLER_UNAVAILABLE',
            'message' => 'WordPress upgrader is not available',
        ];
    }
    return ['ok' => true];
}

function clean_sweep_install_define_json_skin(): void {
    if (class_exists('CleanSweep_Json_Upgrader_Skin', false)) {
        return;
    }
    if (!class_exists('WP_Upgrader_Skin')) {
        return;
    }

    class CleanSweep_Json_Upgrader_Skin extends WP_Upgrader_Skin {
        private $filename;
        private $on_feedback;

        public function __construct($filename = '', $on_feedback = null) {
            parent::__construct();
            $this->filename = $filename;
            $this->on_feedback = $on_feedback;
        }

        public function header() {
        }

        public function footer() {
        }

        public function before() {
        }

        public function after() {
        }

        public function error($errors) {
            $msg = '';
            if (is_string($errors)) {
                $msg = $errors;
            } elseif (is_object($errors) && method_exists($errors, 'get_error_message')) {
                $msg = (string) $errors->get_error_message();
            }
            if ($msg !== '') {
                clean_sweep_install_log('WordPress installer error: ' . $msg, 'error');
                if (is_callable($this->on_feedback)) {
                    call_user_func($this->on_feedback, $msg);
                }
            }
        }

        public function feedback($string, ...$args) {
            $string = (string) $string;
            if (isset($this->upgrader->strings[$string])) {
                $string = (string) $this->upgrader->strings[$string];
            }
            if ($string !== '' && strpos($string, '%') !== false && !empty($args)) {
                $string = vsprintf($string, $args);
            }
            if ($string === '') {
                return;
            }
            clean_sweep_install_log('WordPress installer: ' . $string, 'info');
            if (is_callable($this->on_feedback)) {
                call_user_func($this->on_feedback, $string);
            }
        }
    }
}

/**
 * Install one staged plugin/theme ZIP via Plugin_Upgrader / Theme_Upgrader.
 * JSON-safe: no echo. Never activate_plugin / switch_theme. No raw unzip fallback.
 *
 * @param string $zip_path
 * @param string $kind plugin|theme
 * @param array  $opts filename, progress_cb, preview_slug
 * @return array<string,mixed>
 */
function clean_sweep_install_uploaded_package($zip_path, $kind, $opts = []) {
    $kind = $kind === 'theme' ? 'theme' : ($kind === 'plugin' ? 'plugin' : '');
    if ($kind === '') {
        return [
            'success' => false,
            'code' => 'NOT_A_PACKAGE',
            'message' => 'Not a plugin or theme package',
        ];
    }
    if ($zip_path === '' || !is_file($zip_path)) {
        return [
            'success' => false,
            'code' => 'ZIP_NOT_FOUND',
            'message' => 'ZIP file not found',
        ];
    }

    $filename = isset($opts['filename']) ? (string) $opts['filename'] : basename((string) $zip_path);
    $preview_slug = isset($opts['preview_slug']) ? (string) $opts['preview_slug'] : '';
    $progress_cb = $opts['progress_cb'] ?? null;
    $notify = function ($msg) use ($progress_cb) {
        clean_sweep_install_log($msg);
        if (is_callable($progress_cb)) {
            call_user_func($progress_cb, $msg);
        }
    };

    $root = clean_sweep_install_assert_package_root($kind);
    if (empty($root['ok'])) {
        return [
            'success' => false,
            'code' => $root['code'] ?? 'INSTALLER_UNAVAILABLE',
            'message' => $root['message'] ?? 'Installer is not available',
        ];
    }

    $fs = clean_sweep_install_init_filesystem();
    if (empty($fs['ok'])) {
        return [
            'success' => false,
            'code' => $fs['code'] ?? 'FS_UNAVAILABLE',
            'message' => $fs['message'] ?? 'Direct filesystem access is required',
        ];
    }

    $loaded = clean_sweep_install_load_upgrader_classes();
    if (empty($loaded['ok'])) {
        return [
            'success' => false,
            'code' => $loaded['code'] ?? 'INSTALLER_UNAVAILABLE',
            'message' => $loaded['message'] ?? 'WordPress upgrader is not available',
        ];
    }

    clean_sweep_install_define_json_skin();
    if (!class_exists('CleanSweep_Json_Upgrader_Skin')) {
        return [
            'success' => false,
            'code' => 'INSTALLER_UNAVAILABLE',
            'message' => 'WordPress upgrader is not available',
        ];
    }

    $filter = static function ($options) {
        if (!is_array($options)) {
            $options = [];
        }
        $options['clear_destination'] = true;
        $options['abort_if_destination_exists'] = false;
        return $options;
    };
    if (function_exists('add_filter')) {
        add_filter('upgrader_package_options', $filter);
    }

    $notify('Installing ' . $filename . '…');
    $skin = new CleanSweep_Json_Upgrader_Skin($filename, $progress_cb);
    if ($kind === 'theme') {
        $upgrader = new Theme_Upgrader($skin);
    } else {
        $upgrader = new Plugin_Upgrader($skin);
    }

    $result = $upgrader->install($zip_path);

    if (function_exists('remove_filter')) {
        remove_filter('upgrader_package_options', $filter);
    }

    if (is_object($result) && method_exists($result, 'get_error_message')) {
        $error_msg = (string) $result->get_error_message();
        clean_sweep_install_log('Package install failed for ' . $filename . ': ' . $error_msg, 'error');
        return [
            'success' => false,
            'code' => 'INSTALL_ERROR',
            'message' => $error_msg !== '' ? $error_msg : 'Installation failed',
            'details' => [$error_msg],
        ];
    }
    if ($result !== true) {
        clean_sweep_install_log('Package install result unclear for ' . $filename, 'warning');
        return [
            'success' => false,
            'code' => 'INSTALL_ERROR',
            'message' => 'Installation result unclear',
        ];
    }

    $up_result = is_array($upgrader->result) ? $upgrader->result : [];
    $dest_name = isset($up_result['destination_name']) ? (string) $up_result['destination_name'] : '';
    $dest_abs = isset($up_result['destination']) ? rtrim(str_replace('\\', '/', (string) $up_result['destination']), '/') : '';
    $package_root = rtrim(str_replace('\\', '/', (string) $root['abs']), '/');
    if ($dest_abs === '' && $dest_name !== '') {
        $dest_abs = $package_root . '/' . $dest_name;
    }

    if ($dest_abs !== '' && function_exists('clean_sweep_upload_dest_toolkit_collision')) {
        $hit = clean_sweep_upload_dest_toolkit_collision($dest_abs, '');
        if (!empty($hit['hit'])) {
            return [
                'success' => false,
                'code' => 'DEST_TOOLKIT',
                'message' => $hit['message'] ?? 'Install landed inside the Clean Sweep toolkit',
                'destination_name' => $dest_name,
                'destination' => $dest_abs,
            ];
        }
        $hit_name = clean_sweep_upload_dest_toolkit_collision($root['abs'], $dest_name);
        if (!empty($hit_name['hit'])) {
            return [
                'success' => false,
                'code' => 'DEST_TOOLKIT',
                'message' => $hit_name['message'] ?? 'Install landed inside the Clean Sweep toolkit',
                'destination_name' => $dest_name,
                'destination' => $dest_abs,
            ];
        }
    }

    $plugin_file = null;
    $name = '';
    $version = '';
    if ($kind === 'plugin' && method_exists($upgrader, 'plugin_info')) {
        $info = $upgrader->plugin_info();
        if (is_string($info) && $info !== '') {
            $plugin_file = $info;
        }
    }
    if ($kind === 'theme' && method_exists($upgrader, 'theme_info')) {
        $theme = $upgrader->theme_info();
        if (is_object($theme) && method_exists($theme, 'get')) {
            $name = (string) $theme->get('Name');
            $version = (string) $theme->get('Version');
        }
    }

    if ($dest_abs !== '' && is_dir($dest_abs)) {
        $headers = clean_sweep_install_read_live_headers($dest_abs, $kind);
        if ($name === '' && $headers['name'] !== '') {
            $name = $headers['name'];
        }
        if ($version === '' && $headers['version'] !== '') {
            $version = $headers['version'];
        }
    }

    $sealed = false;
    $seal_abs = '';
    if ($dest_name !== '' && $dest_abs !== '' && is_dir($dest_abs)) {
        $dest_n = rtrim(str_replace('\\', '/', $dest_abs), '/');
        $dest_real = realpath($dest_abs);
        if ($dest_real) {
            $dest_n = rtrim(str_replace('\\', '/', $dest_real), '/');
        }
        $root_n = $package_root;
        $root_real = is_dir($package_root) ? realpath($package_root) : false;
        if ($root_real) {
            $root_n = rtrim(str_replace('\\', '/', $root_real), '/');
        }
        if ($dest_n !== $root_n && strpos($dest_n, $root_n . '/') === 0) {
            $seal_abs = $dest_n;
        }
    }
    $visit_boot = dirname(__DIR__, 2) . '/includes/system/visit/bootstrap.php';
    if (is_readable($visit_boot)) {
        require_once $visit_boot;
    }
    if ($seal_abs !== '' && $dest_name !== '') {
        if ($kind === 'plugin' && function_exists('clean_sweep_seal_plugin_dir')) {
            clean_sweep_seal_plugin_dir($dest_name, $seal_abs);
            $sealed = true;
        } elseif ($kind === 'theme' && function_exists('clean_sweep_seal_theme_dir')) {
            clean_sweep_seal_theme_dir($dest_name, $seal_abs);
            $sealed = true;
        }
    }

    // Phase 6: package verification baseline (scan integrity for Pro/non-.org).
    // Created only after CS Upload/install success — not a claim of malware-free zip.
    $verification_baseline = false;
    $verification_baseline_files = 0;
    if ($seal_abs !== '' && $dest_name !== '') {
        $pvb = dirname(__DIR__, 2) . '/features/security/scan/PackageVerificationBaseline.php';
        if (is_readable($pvb)) {
            require_once $pvb;
            if (class_exists('CleanSweep_PackageVerificationBaseline', false)) {
                $created = CleanSweep_PackageVerificationBaseline::create_from_dir([
                    'type' => $kind === 'theme' ? 'theme' : 'plugin',
                    'slug' => $dest_name,
                    'dir' => $seal_abs,
                    'version' => $version,
                    'name' => $name !== '' ? $name : $dest_name,
                    'source' => 'upload',
                ]);
                if (!empty($created['success'])) {
                    $verification_baseline = true;
                    $verification_baseline_files = (int) ($created['file_count'] ?? 0);
                }
            }
        }
    }

    $warnings = [];
    if ($preview_slug !== '' && $dest_name !== '' && $dest_name !== $preview_slug) {
        $warnings[] = 'DEST_NAME_MISMATCH';
    }

    $rel = '';
    if ($dest_abs !== '' && function_exists('clean_sweep_upload_rel_under_site')) {
        $rel = clean_sweep_upload_rel_under_site($dest_abs);
    }

    $label = $kind === 'theme' ? 'theme' : 'plugin';
    $msg = 'Reinstalled ' . $label . ' ' . ($dest_name !== '' ? $dest_name : $filename);
    if ($version !== '') {
        $msg .= ' ' . $version;
    }
    $msg .= ' (stays active if it already was)';
    if ($verification_baseline) {
        $msg .= '; verification baseline saved (' . $verification_baseline_files . ' files)';
    }

    $notify($msg);

    return [
        'success' => true,
        'code' => '',
        'message' => $msg,
        'mode' => $kind === 'theme' ? 'theme_upgrader' : 'plugin_upgrader',
        'destination_name' => $dest_name,
        'destination' => $dest_abs,
        'destination_rel' => $rel,
        'slug' => $dest_name,
        'plugin_file' => $plugin_file,
        'name' => $name,
        'version' => $version,
        'sealed' => $sealed,
        'verification_baseline' => $verification_baseline,
        'verification_baseline_files' => $verification_baseline_files,
        'warnings' => $warnings,
        'files_extracted_count' => 0,
    ];
}

/**
 * @return array{name:string,version:string}
 */
function clean_sweep_install_read_live_headers(string $dir, string $kind): array {
    $name = '';
    $version = '';
    if (function_exists('clean_sweep_parse_headers') && function_exists('clean_sweep_upload_scan_installed_version')) {
        $version = clean_sweep_upload_scan_installed_version($dir, $kind);
    }
    if (!function_exists('clean_sweep_parse_headers') || !is_dir($dir)) {
        return ['name' => $name, 'version' => $version];
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return ['name' => $name, 'version' => $version];
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $dir . '/' . $item;
        if (!is_file($full)) {
            continue;
        }
        $base = strtolower($item);
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        $bytes = @file_get_contents($full);
        if (!is_string($bytes)) {
            continue;
        }
        if ($kind === 'theme' && $base === 'style.css') {
            $h = clean_sweep_parse_headers($bytes, [
                'Name' => 'Theme Name',
                'Version' => 'Version',
            ]);
            if ($h['Name'] !== '') {
                $name = $h['Name'];
            }
            if ($version === '' && $h['Version'] !== '') {
                $version = $h['Version'];
            }
            break;
        }
        if ($kind !== 'theme' && $ext === 'php') {
            $h = clean_sweep_parse_headers($bytes, [
                'Name' => 'Plugin Name',
                'Version' => 'Version',
            ]);
            if ($h['Name'] !== '') {
                $name = $h['Name'];
                if ($version === '' && $h['Version'] !== '') {
                    $version = $h['Version'];
                }
                break;
            }
        }
    }
    return ['name' => $name, 'version' => $version];
}

