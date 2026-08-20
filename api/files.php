<?php
/**
 * Clean Sweep - Files API Endpoint
 * 
 * File browser for WordPress root directory
 */

// ============================================================================
// CORS & HEADERS
// ============================================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CS-Visit-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================================
// GET ACTION
// ============================================================================

$action = isset($_POST['action']) ? trim($_POST['action']) : (isset($_GET['action']) ? trim($_GET['action']) : '');
$path = isset($_POST['path']) ? trim($_POST['path']) : (isset($_GET['path']) ? trim($_GET['path']) : '');

// ============================================================================
// FIND WORDPRESS ROOT
// ============================================================================

function clean_sweep_find_wp_root() {
    if (defined('ORIGINAL_ABSPATH') && ORIGINAL_ABSPATH && is_dir(ORIGINAL_ABSPATH)) {
        return rtrim(ORIGINAL_ABSPATH, '/');
    }
    $site_paths = dirname(__DIR__) . '/features/security/scan/SitePaths.php';
    if (is_readable($site_paths)) {
        require_once $site_paths;
        if (class_exists('CleanSweep_SitePaths', false)) {
            $root = CleanSweep_SitePaths::root();
            if (is_string($root) && $root !== '') {
                return rtrim($root, '/');
            }
        }
    }
    // First try ABSPATH if WordPress is already loaded (not the bundled fresh core)
    if (defined('ABSPATH') && ABSPATH && is_dir(ABSPATH)
        && strpos(str_replace('\\', '/', ABSPATH), '/core/fresh/') === false) {
        return rtrim(ABSPATH, '/');
    }
    
    // Start from the plugin directory and walk UP the tree looking for wp-load.php
    // This is the most reliable method as it doesn't depend on DOCUMENT_ROOT
    $current_dir = dirname(__DIR__);  // Plugin directory (e.g., /wp-content/plugins/clean-sweep)
    
    // Limit how far up we go (max 10 levels)
    $max_levels = 10;
    for ($i = 0; $i < $max_levels; $i++) {
        $check_path = $current_dir . '/wp-load.php';
        if (file_exists($check_path)) {
            return $current_dir;
        }
        // Go up one level
        $parent = dirname($current_dir);
        if ($parent === $current_dir) {
            // Reached filesystem root
            break;
        }
        $current_dir = $parent;
    }
    
    // Fallback: try DOCUMENT_ROOT
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        return rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    }
    
    // Last resort: return plugin directory
    return dirname(__DIR__);
}

$wp_root = clean_sweep_find_wp_root();

// ============================================================================
// PATH HELPERS
// ============================================================================

/**
 * Normalize a client path to a site-relative path under $wp_root.
 * Threats often store absolute filesystem paths; the browser also may send them.
 * The files API expects paths relative to the WordPress root (e.g. wp-content/...).
 *
 * @param string $path
 * @param string $wp_root
 * @return string Relative path without leading slash
 */
function clean_sweep_normalize_site_path($path, $wp_root) {
    $path = str_replace('\\', '/', trim((string) $path));
    if ($path === '') {
        return '';
    }

    $root_norm = rtrim(str_replace('\\', '/', (string) $wp_root), '/');
    $real_root = realpath($wp_root);
    if ($real_root) {
        $real_root = rtrim(str_replace('\\', '/', $real_root), '/');
    }

    $is_absolute = (isset($path[0]) && $path[0] === '/')
        || (bool) preg_match('#^[A-Za-z]:/#', $path);

    if ($is_absolute) {
        // Prefer realpath so we stay inside the site root
        $real = @realpath($path);
        if ($real && $real_root && strpos($real, $real_root) === 0) {
            $rel = substr($real, strlen($real_root));
            return ltrim(str_replace('\\', '/', $rel), '/');
        }

        // realpath may fail for open_basedir; strip configured root prefix
        if ($root_norm !== '' && strpos($path, $root_norm . '/') === 0) {
            return ltrim(substr($path, strlen($root_norm)), '/');
        }
        if ($real_root && strpos($path, $real_root . '/') === 0) {
            return ltrim(substr($path, strlen($real_root)), '/');
        }

        // Last resort: extract from a well-known WP segment
        foreach (['/wp-content/', '/wp-includes/', '/wp-admin/'] as $marker) {
            $i = strpos($path, $marker);
            if ($i !== false) {
                return ltrim(substr($path, $i), '/');
            }
        }

        // Root-level basenames (align with CleanSweep_SitePaths::official_root_php_basenames + overrides)
        $base = strtolower(basename($path));
        $root_basenames = [
            'index.php',
            'wp-activate.php',
            'wp-blog-header.php',
            'wp-comments-post.php',
            'wp-config-sample.php',
            'wp-cron.php',
            'wp-links-opml.php',
            'wp-load.php',
            'wp-login.php',
            'wp-mail.php',
            'wp-settings.php',
            'wp-signup.php',
            'wp-trackback.php',
            'xmlrpc.php',
            'wp-config.php',
            '.htaccess',
            '.user.ini',
            'php.ini',
            'license.txt',
            'readme.html',
        ];
        if ($base !== '' && in_array($base, $root_basenames, true)) {
            // Preserve original casing from the path basename
            return basename($path);
        }
    }

    // Relative (or client-mangled absolute stripped of leading /).
    // Collapse multi-segment leftovers like media/.../wp-config-sample.php
    // when the basename is a known site-root file.
    $rel = ltrim($path, '/');
    if ($rel === '') {
        return '';
    }
    if (strpos($rel, '/') === false) {
        return $rel;
    }
    if (!preg_match('#^(wp-content|wp-includes|wp-admin)(/|$)#i', $rel)) {
        $base = basename($rel);
        $base_l = strtolower($base);
        $root_basenames = [
            'index.php',
            'wp-activate.php',
            'wp-blog-header.php',
            'wp-comments-post.php',
            'wp-config-sample.php',
            'wp-cron.php',
            'wp-links-opml.php',
            'wp-load.php',
            'wp-login.php',
            'wp-mail.php',
            'wp-settings.php',
            'wp-signup.php',
            'wp-trackback.php',
            'xmlrpc.php',
            'wp-config.php',
            '.htaccess',
            '.user.ini',
            'php.ini',
            'license.txt',
            'readme.html',
        ];
        if ($base_l !== '' && in_array($base_l, $root_basenames, true)) {
            return $base;
        }
    }

    return $rel;
}

// ============================================================================
// HANDLERS
// ============================================================================

function clean_sweep_files_path_equals_or_inside($path, $root) {
    $path = rtrim(str_replace('\\', '/', (string) $path), '/');
    $root = rtrim(str_replace('\\', '/', (string) $root), '/');
    if ($path === '' || $root === '') {
        return false;
    }
    return $path === $root || strpos($path, $root . '/') === 0;
}

function clean_sweep_handle_list_directory($path, $wp_root) {
    $real_root = realpath($wp_root);
    if (!$real_root) {
        echo json_encode(['success' => false, 'error' => 'Invalid root', 'code' => 'INVALID_ROOT']);
        exit;
    }
    $real_root = rtrim(str_replace('\\', '/', $real_root), '/');

    $path = clean_sweep_normalize_site_path((string) $path, $wp_root);
    if (strpos($path, "\0") !== false || preg_match('#(?:^|/)\.\.(?:/|$)#', $path)
        || preg_match('/^[A-Za-z]:/', $path)
    ) {
        echo json_encode(['success' => false, 'error' => 'Path is outside the site', 'code' => 'DEST_OUTSIDE_SITE']);
        exit;
    }

    $full_path = $path !== '' ? $real_root . '/' . $path : $real_root;
    $real_path = realpath($full_path);
    $real_path_n = $real_path ? rtrim(str_replace('\\', '/', $real_path), '/') : '';

    if ($real_path === false || !clean_sweep_files_path_equals_or_inside($real_path_n, $real_root)) {
        echo json_encode(['success' => false, 'error' => 'Path is outside the site', 'code' => 'DEST_OUTSIDE_SITE']);
        exit;
    }

    if (!is_dir($real_path)) {
        echo json_encode(['success' => false, 'error' => 'Not a directory', 'code' => 'NOT_DIRECTORY']);
        exit;
    }
    
    $files = [];
    $items = scandir($real_path);
    $listed_rel = ($real_path_n === $real_root)
        ? ''
        : ltrim(substr($real_path_n, strlen($real_root)), '/');

    // Filter out hidden files, common directories to skip, and the plugin folder itself
    $skip_items = ['.', '..', '.git', 'node_modules', '.svn', '.hg', 'vendor', 'cache'];
    $skip_folders = ['clean-sweep', 'clean-sweep-2.0-master'];
    
    foreach ($items as $item) {
        if (in_array($item, $skip_items)) continue;
        if (is_dir($real_path . '/' . $item) && in_array($item, $skip_folders)) continue;
        
        $item_path = $real_path . '/' . $item;
        $relative_path = $listed_rel !== '' ? $listed_rel . '/' . $item : $item;
        
        $file_info = [
            'name' => $item,
            'path' => $relative_path,
            'type' => is_dir($item_path) ? 'folder' : 'file'
        ];
        
        if ($file_info['type'] === 'file') {
            $file_info['size'] = filesize($item_path);
            $file_info['modified'] = date('c', filemtime($item_path));
        }
        
        $files[] = $file_info;
    }
    
    // Sort: folders first
    usort($files, function($a, $b) {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'folder' ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });
    
    echo json_encode(['success' => true, 'data' => ['path' => $listed_rel, 'files' => $files]]);
    exit;
}

function clean_sweep_handle_get_file_content($path, $wp_root) {
    // path is already parsed at the top level
    if (empty($path)) {
        echo json_encode(['success' => false, 'error' => 'Path is required', 'code' => 'MISSING_PATH']);
        exit;
    }

    $path = clean_sweep_normalize_site_path($path, $wp_root);
    if ($path === '') {
        echo json_encode(['success' => false, 'error' => 'Path is required', 'code' => 'MISSING_PATH']);
        exit;
    }
    
    // Only allow safe file types
    $allowed = ['php', 'js', 'css', 'txt', 'html', 'htm', 'json', 'xml', 'md', 'yml', 'yaml', 'htaccess', 'log', 'inc', 'info', 'ini', 'phtml', 'phar', 'conf', 'user.ini'];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $base = strtolower(basename($path));
    if ($base === '.user.ini' || $base === '.htaccess' || $base === 'php.ini') {
        $ext = $base === '.htaccess' ? 'htaccess' : 'ini';
    }
    
    if (!in_array($ext, $allowed, true) && $base !== '.user.ini' && $base !== 'php.ini') {
        echo json_encode(['success' => false, 'error' => 'File type not allowed: ' . $ext, 'code' => 'FILE_TYPE_NOT_ALLOWED']);
        exit;
    }
    
    $real_root = realpath($wp_root);
    if (!$real_root) {
        echo json_encode(['success' => false, 'error' => 'Invalid root', 'code' => 'INVALID_ROOT']);
        exit;
    }
    
    $full_path = $real_root . '/' . $path;
    $real_path = realpath($full_path);
    
    if ($real_path === false || strpos($real_path, $real_root) !== 0) {
        echo json_encode(['success' => false, 'error' => 'File not found', 'code' => 'FILE_NOT_FOUND', 'details' => ['path' => $path]]);
        exit;
    }
    
    if (!is_file($real_path)) {
        echo json_encode(['success' => false, 'error' => 'Not a file', 'code' => 'NOT_FILE']);
        exit;
    }
    
    // Allow larger files for malware review (still cap to keep responses sane)
    $size = filesize($real_path);
    if ($size > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'File too large (max 2MB)', 'code' => 'FILE_TOO_LARGE']);
        exit;
    }
    
    $content = @file_get_contents($real_path);
    if ($content === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to read file', 'code' => 'READ_ERROR']);
        exit;
    }
    
    // Soft truncate for very large sources (malware samples are often dense single lines)
    $max_bytes = 400000;
    $truncated = false;
    if (strlen($content) > $max_bytes) {
        $content = substr($content, 0, $max_bytes) . "\n\n... [truncated for editor]";
        $truncated = true;
    }
    // Convert to UTF-8 and replace invalid sequences
    $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
    // Remove BOM if present
    $content = str_replace("\xEF\xBB\xBF", '', $content);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'path' => $path,
            'content' => $content,
            'modified' => false,
            'truncated' => $truncated,
            'lineCount' => substr_count($content, "\n") + 1
        ]
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// GET ORIGINAL CONTENT FROM WORDPRESS.ORG
// ============================================================================

function clean_sweep_official_site_root($fallback) {
    if (defined('ORIGINAL_ABSPATH') && is_string(ORIGINAL_ABSPATH) && is_dir(ORIGINAL_ABSPATH)) {
        return rtrim(str_replace('\\', '/', ORIGINAL_ABSPATH), '/') . '/';
    }
    $site_paths = dirname(__DIR__) . '/features/security/scan/SitePaths.php';
    if (is_readable($site_paths)) {
        require_once $site_paths;
        if (class_exists('CleanSweep_SitePaths', false)) {
            $root = CleanSweep_SitePaths::root();
            if (is_string($root) && $root !== '') {
                return rtrim(str_replace('\\', '/', $root), '/') . '/';
            }
        }
    }
    return rtrim(str_replace('\\', '/', (string) $fallback), '/') . '/';
}

function clean_sweep_official_classify_path($rel) {
    $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        return null;
    }
    if (preg_match('#^wp-content/plugins/([^/]+)/(.+)$#', $rel, $m)) {
        return ['kind' => 'plugin', 'slug' => $m[1], 'file' => $m[2]];
    }
    if (preg_match('#^wp-content/themes/([^/]+)/(.+)$#', $rel, $m)) {
        return ['kind' => 'theme', 'slug' => $m[1], 'file' => $m[2]];
    }
    $core = str_starts_with($rel, 'wp-admin/')
        || str_starts_with($rel, 'wp-includes/')
        || (bool) preg_match('/^(index|xmlrpc|wp-[a-z0-9-]+)\.php$/', $rel);
    if ($core) {
        return ['kind' => 'core', 'slug' => 'wordpress', 'file' => $rel];
    }
    return null;
}

function clean_sweep_official_http_get($url) {
    if (function_exists('wp_remote_get')) {
        $res = wp_remote_get($url, [
            'timeout' => 12,
            'redirection' => 3,
            'sslverify' => true,
            'user-agent' => 'CleanSweep/2.0',
        ]);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        if ($code === 200) {
            $body = wp_remote_retrieve_body($res);
            return is_string($body) && $body !== '' ? $body : null;
        }
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'CleanSweep/2.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200 && $body !== '') ? (string) $body : null;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 12, 'follow_location' => 1, 'user_agent' => 'CleanSweep/2.0'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) && $body !== '' ? $body : null;
}

function clean_sweep_official_read_version($root, $kind, $slug) {
    if ($kind === 'core') {
        $file = $root . 'wp-includes/version.php';
        if (is_readable($file) && preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($file), $m)) {
            return $m[1];
        }
        return '';
    }
    if ($kind === 'theme') {
        $css = $root . 'wp-content/themes/' . $slug . '/style.css';
        if (is_readable($css) && preg_match('/^Version:\s*(.+)$/mi', (string) file_get_contents($css, false, null, 0, 8192), $m)) {
            return trim($m[1]);
        }
        return '';
    }
    $dir = $root . 'wp-content/plugins/' . $slug;
    if (!is_dir($dir)) {
        return '';
    }
    foreach (glob($dir . '/*.php') ?: [] as $php) {
        $head = (string) @file_get_contents($php, false, null, 0, 8192);
        if ($head !== '' && stripos($head, 'Plugin Name:') !== false && preg_match('/^Version:\s*(.+)$/mi', $head, $m)) {
            return trim($m[1]);
        }
    }
    return '';
}

function clean_sweep_official_cache_path($kind, $slug, $version, $file) {
    $dir = defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT . 'backups/' : dirname(__DIR__) . '/backups/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $safe = preg_replace('/[^a-z0-9._-]/i', '_', $kind . '_' . $slug . '_' . $version . '_' . $file);
    if (strlen($safe) > 140) {
        $safe = substr($safe, 0, 100) . '_' . md5($kind . $slug . $version . $file);
    }
    return $dir . 'official_' . $safe . '.txt';
}

function clean_sweep_handle_get_original_content($path) {
    $path = isset($_POST['path']) ? trim($_POST['path']) : (isset($_GET['path']) ? trim($_GET['path']) : '');
    if ($path === '') {
        echo json_encode(['success' => false, 'error' => 'Path is required', 'code' => 'MISSING_PATH']);
        exit;
    }

    global $wp_root;
    $path = clean_sweep_normalize_site_path($path, $wp_root ?: clean_sweep_find_wp_root());
    $classified = clean_sweep_official_classify_path($path);
    $kind = strtolower(trim((string) ($_POST['package_type'] ?? $_GET['package_type'] ?? ($classified['kind'] ?? ''))));
    $slug = trim((string) ($_POST['package_slug'] ?? $_GET['package_slug'] ?? ($classified['slug'] ?? '')));
    $file = $classified['file'] ?? $path;
    $version = trim((string) ($_POST['version'] ?? $_GET['version'] ?? ''));

    if ($kind === '' && $classified) {
        $kind = $classified['kind'];
    }
    if (!in_array($kind, ['core', 'plugin', 'theme'], true) || strpos($file, '..') !== false) {
        echo json_encode(['success' => false, 'error' => 'Not an official wordpress.org path', 'code' => 'NOT_OFFICIAL_PATH']);
        exit;
    }
    if ($kind !== 'core' && !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        echo json_encode(['success' => false, 'error' => 'Invalid package slug', 'code' => 'INVALID_SLUG']);
        exit;
    }

    $root = clean_sweep_official_site_root($wp_root ?: clean_sweep_find_wp_root());
    if ($version === '') {
        $version = clean_sweep_official_read_version($root, $kind, $slug);
    }
    if ($version === '') {
        echo json_encode(['success' => false, 'error' => 'Could not determine package version', 'code' => 'NO_VERSION']);
        exit;
    }

    $cache = clean_sweep_official_cache_path($kind, $slug, $version, $file);
    if (is_readable($cache) && (time() - (int) @filemtime($cache)) < 604800) {
        $cached = (string) file_get_contents($cache);
        if ($cached !== '') {
            echo json_encode([
                'success' => true,
                'data' => [
                    'path' => $path,
                    'content' => $cached,
                    'version' => $version,
                    'source' => 'cache',
                    'kind' => $kind,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $urls = [];
    if ($kind === 'plugin') {
        $urls[] = 'https://plugins.svn.wordpress.org/' . rawurlencode($slug) . '/tags/' . rawurlencode($version) . '/' . str_replace('%2F', '/', rawurlencode($file));
    } elseif ($kind === 'theme') {
        $urls[] = 'https://themes.svn.wordpress.org/' . rawurlencode($slug) . '/' . rawurlencode($version) . '/' . str_replace('%2F', '/', rawurlencode($file));
    } else {
        $enc = str_replace('%2F', '/', rawurlencode($file));
        $urls[] = "https://raw.githubusercontent.com/WordPress/WordPress/{$version}/{$enc}";
        $urls[] = "https://raw.githubusercontent.com/WordPress/WordPress/refs/tags/{$version}/{$enc}";
        $urls[] = "https://core.svn.wordpress.org/tags/{$version}/{$enc}";
    }

    $content = null;
    $source = 'wordpress.org';
    foreach ($urls as $url) {
        $content = clean_sweep_official_http_get($url);
        if (is_string($content) && $content !== '') {
            break;
        }
    }
    if ((!is_string($content) || $content === '') && $kind === 'core') {
        $fresh = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__) . '/') . 'core/fresh/' . $file;
        if (is_readable($fresh)) {
            $local = (string) file_get_contents($fresh);
            if ($local !== '') {
                $content = $local;
                $source = 'bundled-core';
            }
        }
    }

    if (!is_string($content) || $content === '') {
        echo json_encode([
            'success' => false,
            'error' => 'Official file is not on wordpress.org for this version (premium/custom builds cannot be compared).',
            'code' => 'FETCH_FAILED',
            'data' => ['kind' => $kind, 'slug' => $slug, 'version' => $version],
        ]);
        exit;
    }
    if (strlen($content) > 2000000 || strpos($content, "\0") !== false) {
        echo json_encode(['success' => false, 'error' => 'Official file is binary or too large to diff', 'code' => 'NOT_TEXT']);
        exit;
    }

    @file_put_contents($cache, $content);

    echo json_encode([
        'success' => true,
        'data' => [
            'path' => $path,
            'content' => $content,
            'version' => $version,
            'source' => $source,
            'kind' => $kind,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// SAVE FILE CONTENT (RESTORE)
// ============================================================================

function clean_sweep_handle_save_file_content($path, $wp_root) {
    $path = isset($_POST['path']) ? trim($_POST['path']) : (isset($_GET['path']) ? trim($_GET['path']) : '');
    $content = isset($_POST['content']) ? $_POST['content'] : '';
    $path = clean_sweep_normalize_site_path($path, $wp_root);
    
    if (empty($path)) {
        echo json_encode(['success' => false, 'error' => 'Path is required', 'code' => 'MISSING_PATH']);
        exit;
    }
    
    if ($content === null) {
        echo json_encode(['success' => false, 'error' => 'Content is required', 'code' => 'MISSING_CONTENT']);
        exit;
    }
    
    // Security: stay inside the site. Allow core, wp-content, and root config files.
    $allowed_root = ['wp-config.php', 'wp-settings.php', 'wp-load.php', 'index.php', '.htaccess', '.user.ini', 'php.ini'];
    $basename = basename($path);
    $in_tree = (bool) preg_match('#^(wp-admin/|wp-includes/|wp-content/)#', $path);
    if (!$in_tree && !in_array($basename, $allowed_root, true) && !in_array($path, $allowed_root, true)) {
        echo json_encode(['success' => false, 'error' => 'Cannot save this file for security reasons', 'code' => 'FORBIDDEN']);
        exit;
    }
    
    $real_root = realpath($wp_root);
    if (!$real_root) {
        echo json_encode(['success' => false, 'error' => 'Invalid root', 'code' => 'INVALID_ROOT']);
        exit;
    }
    
    $full_path = $real_root . '/' . $path;
    $real_path = realpath($full_path);
    
    if ($real_path === false || strpos($real_path, $real_root) !== 0) {
        echo json_encode(['success' => false, 'error' => 'File not found', 'code' => 'FILE_NOT_FOUND']);
        exit;
    }
    
    // Create backup before saving
    $backup_path = $full_path . '.backup.' . date('YmdHis');
    if (!copy($full_path, $backup_path)) {
        echo json_encode(['success' => false, 'error' => 'Failed to create backup', 'code' => 'BACKUP_FAILED']);
        exit;
    }
    
    // Write the new content
    $result = file_put_contents($full_path, $content);
    
    if ($result === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to write file', 'code' => 'WRITE_FAILED']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'path' => $path,
            'bytes_written' => $result,
            'backup_path' => basename($backup_path)
        ]
    ]);
    exit;
}

// ============================================================================
// ROUTE
// ============================================================================

switch ($action) {
    case 'list_directory':
        clean_sweep_handle_list_directory($path ?? '', $wp_root);
        break;
        
    case 'get_file_content':
        clean_sweep_handle_get_file_content($path ?? '', $wp_root);
        break;
        
    case 'get_original_content':
        clean_sweep_handle_get_original_content($path ?? '');
        break;
        
    case 'save_file_content':
        clean_sweep_handle_save_file_content($path ?? '', $wp_root);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action, 'code' => 'UNKNOWN_ACTION']);
}
