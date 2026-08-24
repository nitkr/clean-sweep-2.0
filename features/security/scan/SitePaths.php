<?php
/**
 * Resolve the real WordPress site root (not Clean Sweep's bundled core).
 */
final class CleanSweep_SitePaths {

    public static function root(): ?string {
        $candidates = [];
        if (defined('ORIGINAL_WP_CONTENT_DIR')) {
            $candidates[] = dirname(ORIGINAL_WP_CONTENT_DIR);
        }
        if (defined('ORIGINAL_ABSPATH')) {
            $candidates[] = ORIGINAL_ABSPATH;
        }
        if (defined('ABSPATH')) {
            $candidates[] = ABSPATH;
        }
        if (function_exists('clean_sweep_detect_site_root')) {
            $candidates[] = clean_sweep_detect_site_root();
        }

        foreach ($candidates as $c) {
            $c = rtrim(str_replace('\\', '/', (string) $c), '/') . '/';
            if ($c !== '/' && is_readable($c . 'wp-includes/version.php')) {
                return $c;
            }
        }
        return null;
    }

    public static function content_dir(): ?string {
        if (defined('ORIGINAL_WP_CONTENT_DIR') && is_dir(ORIGINAL_WP_CONTENT_DIR)) {
            return rtrim(str_replace('\\', '/', ORIGINAL_WP_CONTENT_DIR), '/') . '/';
        }
        $root = self::root();
        if ($root && is_dir($root . 'wp-content')) {
            return $root . 'wp-content/';
        }
        if (defined('WP_CONTENT_DIR') && is_dir(WP_CONTENT_DIR)) {
            return rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/';
        }
        return null;
    }

    /**
     * Pre-boot override files: .htaccess, .user.ini, php.ini at site root,
     * wp-content, and wp-admin.
     *
     * @return string[] Existing readable paths
     */
    public static function root_override_files(): array {
        $root = self::root();
        if ($root === null) {
            return [];
        }
        $dirs = [$root];
        $content = self::content_dir();
        if ($content) {
            $dirs[] = $content;
        }
        if (is_dir($root . 'wp-admin')) {
            $dirs[] = $root . 'wp-admin/';
        }

        $names = ['.htaccess', '.user.ini', 'php.ini'];
        $out = [];
        foreach ($dirs as $dir) {
            $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
            foreach ($names as $name) {
                $path = $dir . $name;
                if (is_file($path) && is_readable($path)) {
                    $out[] = $path;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Packaged WordPress root PHP files. Covered by WordPress.org checksums —
     * do not signature-scan these. wp-config.php is user-owned and is not listed.
     *
     * @return string[] lowercase basenames
     */
    public static function official_root_php_basenames(): array {
        return [
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
        ];
    }

    public static function is_official_root_php(string $path): bool {
        $base = strtolower(basename(str_replace('\\', '/', $path)));
        return in_array($base, self::official_root_php_basenames(), true);
    }

    /**
     * Convert an absolute filesystem path to a site-relative path under the WP root.
     * Used so threat.path is always openable by the files API / editor.
     *
     * @param string $abs Absolute (or already relative) path
     * @return string Site-relative path without leading slash, or '' if empty
     */
    public static function to_site_relative(string $abs): string {
        $abs = str_replace('\\', '/', trim($abs));
        if ($abs === '') {
            return '';
        }

        // Strip open_in_editor ":line" suffix when present
        if (preg_match('#:\d+$#', $abs) && !preg_match('#^[A-Za-z]:/#', $abs)) {
            $abs = preg_replace('#:\d+$#', '', $abs) ?? $abs;
        }

        $root = self::root();
        if (is_string($root) && $root !== '') {
            $root_n = rtrim(str_replace('\\', '/', $root), '/');
            if (strpos($abs, $root_n . '/') === 0) {
                return ltrim(substr($abs, strlen($root_n)), '/');
            }
            if ($abs === $root_n) {
                return '';
            }
            $real = @realpath($abs);
            $real_root = @realpath($root_n);
            if (is_string($real) && is_string($real_root)) {
                $real = rtrim(str_replace('\\', '/', $real), '/');
                $real_root = rtrim(str_replace('\\', '/', $real_root), '/');
                if (strpos($real, $real_root . '/') === 0) {
                    return ltrim(substr($real, strlen($real_root)), '/');
                }
                if ($real === $real_root) {
                    return '';
                }
            }
        }

        foreach (['/wp-content/', '/wp-includes/', '/wp-admin/'] as $marker) {
            $i = strpos($abs, $marker);
            if ($i !== false) {
                return ltrim(substr($abs, $i), '/');
            }
        }

        // Known root basenames
        $base = basename($abs);
        $base_l = strtolower($base);
        $roots = self::official_root_php_basenames();
        $roots[] = 'wp-config.php';
        $roots[] = '.htaccess';
        $roots[] = '.user.ini';
        $roots[] = 'php.ini';
        $roots[] = 'license.txt';
        $roots[] = 'readme.html';
        if ($base_l !== '' && in_array($base_l, $roots, true)) {
            return $base;
        }

        // Already site-relative-looking
        if (preg_match('#^(wp-content|wp-includes|wp-admin)(/|$)#i', ltrim($abs, '/'))) {
            return ltrim($abs, '/');
        }

        return ltrim($abs, '/');
    }

    /**
     * Extra PHP in the site root (no recursion). Official packaged names
     * are omitted — checksums cover those.
     *
     * @return string[]
     */
    public static function root_php_files(): array {
        $root = self::root();
        if ($root === null) {
            return [];
        }
        $out = [];
        foreach (['*.php', '*.phtml'] as $glob) {
            $found = glob($root . $glob) ?: [];
            foreach ($found as $path) {
                if (self::is_official_root_php($path)) {
                    continue;
                }
                if (self::accept_scan_target($path)) {
                    $out[] = $path;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Directory symlinks: never. File symlinks: only a regular file inside
     * the site root, not already seen (optional $seen keyed by realpath).
     */
    public static function accept_scan_target(string $path, ?array &$seen = null): bool {
        if ($path === '' || !file_exists($path)) {
            return false;
        }
        if (is_link($path)) {
            if (@is_dir($path)) {
                return false;
            }
            $real = @realpath($path);
            if ($real === false || !is_file($real) || !is_readable($real)) {
                return false;
            }
            $ft = @filetype($real);
            if ($ft !== false && $ft !== 'file') {
                return false;
            }
            $root = self::root();
            if ($root) {
                $rn = rtrim(str_replace('\\', '/', $root), '/');
                $tn = str_replace('\\', '/', $real);
                if ($tn !== $rn && strpos($tn, $rn . '/') !== 0) {
                    return false;
                }
            }
            if (is_array($seen)) {
                if (isset($seen[$real])) {
                    return false;
                }
                $seen[$real] = true;
            }
            return true;
        }
        if (is_dir($path)) {
            return true;
        }
        return is_file($path) && is_readable($path);
    }

    public static function wordpress_version(): string {
        $root = self::root();
        if ($root) {
            $file = $root . 'wp-includes/version.php';
            if (is_readable($file)) {
                $content = (string) @file_get_contents($file);
                if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $m)) {
                    return $m[1];
                }
            }
        }
        if (function_exists('clean_sweep_get_wordpress_version')) {
            $v = clean_sweep_get_wordpress_version();
            if (is_string($v) && $v !== '' && $v !== 'unknown') {
                return $v;
            }
        }
        if (defined('WP_VERSION')) {
            return (string) WP_VERSION;
        }
        return '';
    }

    public static function locale(): string {
        if (function_exists('get_locale')) {
            $loc = (string) get_locale();
            if ($loc !== '') {
                return $loc;
            }
        }
        return 'en_US';
    }

    /**
     * Allowed realpath roots for user-supplied scan seeds (site + content).
     *
     * @return string[] Absolute normalized roots without trailing slash
     */
    public static function allowed_scan_roots(): array {
        $roots = [];
        foreach (['ORIGINAL_ABSPATH', 'ABSPATH', 'ORIGINAL_WP_CONTENT_DIR', 'WP_CONTENT_DIR'] as $const) {
            if (!defined($const)) {
                continue;
            }
            $raw = constant($const);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $resolved = realpath($raw);
            $roots[] = rtrim(str_replace('\\', '/', $resolved !== false ? $resolved : $raw), '/');
        }
        $site = self::root();
        if ($site) {
            $roots[] = rtrim(str_replace('\\', '/', $site), '/');
        }
        $content = self::content_dir();
        if ($content) {
            $roots[] = rtrim(str_replace('\\', '/', $content), '/');
        }
        return array_values(array_unique(array_filter($roots)));
    }

    /**
     * Whether an absolute real path is under a known WordPress root.
     */
    public static function is_under_allowed_root(string $real_path): bool {
        $path = rtrim(str_replace('\\', '/', $real_path), '/');
        foreach (self::allowed_scan_roots() as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a user-supplied scan path to an absolute file or directory under the live site.
     * Accepts absolute paths or paths relative to site root / content dir.
     * Rejects empty input, NUL, `..` segments, and paths that escape WP roots.
     *
     * @return string|null Absolute realpath (no trailing slash), or null if invalid
     */
    public static function resolve_scan_seed_path(string $raw): ?string {
        $raw = trim(str_replace('\\', '/', $raw));
        if ($raw === '' || strpos($raw, "\0") !== false) {
            return null;
        }
        // Reject .. segment traversal before join (also after normalize).
        $parts = array_filter(explode('/', $raw), static function ($p) {
            return $p !== '' && $p !== '.';
        });
        foreach ($parts as $p) {
            if ($p === '..') {
                return null;
            }
        }

        $candidates = [];
        $is_absolute = ($raw[0] === '/' || preg_match('#^[A-Za-z]:/#', $raw) === 1);
        if ($is_absolute) {
            $candidates[] = $raw;
        } else {
            $rel = ltrim($raw, '/');
            // Strip leading wp-content/ when joining to content_dir to avoid doubling.
            $root = self::root();
            $content = self::content_dir();
            if ($root) {
                $candidates[] = rtrim($root, '/') . '/' . $rel;
            }
            if ($content) {
                $candidates[] = rtrim($content, '/') . '/' . $rel;
                if (str_starts_with($rel, 'wp-content/')) {
                    $candidates[] = rtrim($content, '/') . '/' . substr($rel, strlen('wp-content/'));
                }
            }
            if (defined('ABSPATH') && is_string(ABSPATH) && ABSPATH !== '') {
                $candidates[] = rtrim(str_replace('\\', '/', ABSPATH), '/') . '/' . $rel;
            }
        }

        foreach ($candidates as $cand) {
            if (!is_dir($cand) && !is_file($cand)) {
                continue;
            }
            $real = @realpath($cand);
            if ($real === false || (!is_dir($real) && !is_file($real))) {
                continue;
            }
            $real_n = rtrim(str_replace('\\', '/', $real), '/');
            if (self::is_under_allowed_root($real_n)) {
                return $real_n;
            }
        }
        return null;
    }
}
