<?php
/**
 * Path helpers for the AI scan. No shell calls.
 */
final class AiPaths {

    public const NESTED_REASON = 'Nested WordPress site. Left out of this scan.';

    public static function norm(string $path): string {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        return $path;
    }

    public static function rel(string $path, string $root): string {
        $path = self::norm($path);
        $root = rtrim(self::norm($root), '/') . '/';
        if ($root !== '/' && strncasecmp($path, $root, strlen($root)) === 0) {
            return ltrim(substr($path, strlen($root)), '/');
        }
        $realRoot = realpath(rtrim($root, '/'));
        $realPath = @realpath($path);
        if (is_string($realRoot) && is_string($realPath)) {
            $realRoot = rtrim(self::norm($realRoot), '/') . '/';
            $realPath = self::norm($realPath);
            if (strncasecmp($realPath, $realRoot, strlen($realRoot)) === 0) {
                return ltrim(substr($realPath, strlen($realRoot)), '/');
            }
        }
        return ltrim($path, '/');
    }

    /**
     * WordPress root that holds the site's wp-config.php.
     * Recovery mode sets ABSPATH to clean-sweep/core/fresh. That copy is never the site.
     */
    public static function siteRoot(): string {
        $toolkit = defined('CLEAN_SWEEP_ROOT') ? (string) CLEAN_SWEEP_ROOT : '';
        $candidates = [];
        if (defined('ORIGINAL_WP_CONTENT_DIR') && ORIGINAL_WP_CONTENT_DIR) {
            $candidates[] = dirname(self::norm((string) ORIGINAL_WP_CONTENT_DIR));
        }
        foreach (['ORIGINAL_ABSPATH', 'SITE_ABSPATH'] as $name) {
            if (defined($name) && constant($name)) {
                $candidates[] = (string) constant($name);
            }
        }
        if (function_exists('clean_sweep_api_detect_wordpress_root')) {
            $detected = clean_sweep_api_detect_wordpress_root();
            if (is_string($detected) && $detected !== '') {
                $candidates[] = $detected;
            }
        }
        if (function_exists('clean_sweep_detect_site_root')) {
            $detected = clean_sweep_detect_site_root();
            if (is_string($detected) && $detected !== '') {
                $candidates[] = $detected;
            }
        }
        if ($toolkit !== '') {
            $dir = dirname(rtrim(self::norm($toolkit), '/'));
            for ($i = 0; $i < 5; $i++) {
                $candidates[] = $dir;
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }
        if (defined('ABSPATH') && ABSPATH) {
            $candidates[] = (string) ABSPATH;
        }
        $picked = self::pickSiteRoot($candidates, $toolkit);
        if ($picked !== '') {
            return $picked;
        }
        $cwd = getcwd();
        return rtrim(self::norm(is_string($cwd) && $cwd !== '' ? $cwd : '/'), '/') . '/';
    }

    /**
     * First candidate that is a real site root, outside the toolkit.
     *
     * @param array<int,string> $candidates
     */
    public static function pickSiteRoot(array $candidates, string $toolkit): string {
        $toolkit = $toolkit === '' ? '' : rtrim(self::norm($toolkit), '/') . '/';
        foreach ($candidates as $candidate) {
            $root = rtrim(self::norm((string) $candidate), '/') . '/';
            if (self::isRealSiteRoot($root, $toolkit)) {
                return $root;
            }
        }
        return '';
    }

    public static function isRealSiteRoot(string $root, string $toolkit): bool {
        $root = rtrim(self::norm($root), '/') . '/';
        if ($root === '/') {
            return false;
        }
        $toolkit = $toolkit === '' ? '' : rtrim(self::norm($toolkit), '/') . '/';
        if ($toolkit !== '' && stripos($root, $toolkit) === 0) {
            return false;
        }
        if (is_file($root . '.clean-sweep-canary.php')) {
            return false;
        }
        return is_file($root . 'wp-config.php') && is_dir($root . 'wp-content');
    }

    /**
     * A directory that is its own WordPress tree.
     * wp-config.php beside the usual core markers, or a core tree whose config sits one level up.
     */
    public static function isWordPressTree(string $absDir): bool {
        $absDir = rtrim(self::norm($absDir), '/') . '/';
        if ($absDir === '/' || !is_dir(rtrim($absDir, '/'))) {
            return false;
        }
        $hasConfig = is_file($absDir . 'wp-config.php');
        $hasContent = is_dir($absDir . 'wp-content');
        $hasIncludes = is_dir($absDir . 'wp-includes');
        if ($hasConfig && ($hasContent || $hasIncludes || is_file($absDir . 'wp-settings.php'))) {
            return true;
        }
        return $hasContent && is_file($absDir . 'wp-includes/version.php');
    }

    /**
     * Outermost nested WordPress directory that contains $rel.
     * The scan root itself is never returned.
     */
    public static function nestedSiteRel(string $rel, string $root): string {
        $rel = ltrim(self::norm($rel), '/');
        $root = rtrim(self::norm($root), '/') . '/';
        if ($rel === '' || $rel === '.') {
            return '';
        }
        $acc = '';
        foreach (explode('/', $rel) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $acc = $acc === '' ? $part : $acc . '/' . $part;
            $abs = $root . $acc;
            if (!is_dir($abs)) {
                return '';
            }
            if (self::isWordPressTree($abs)) {
                return $acc;
            }
        }
        return '';
    }

    public static function abs(string $rel, string $root): string {
        $rel = ltrim(self::norm($rel), '/');
        return rtrim(self::norm($root), '/') . '/' . $rel;
    }

    /**
     * True when $rel stays inside $root after resolution.
     */
    public static function insideRoot(string $rel, string $root): bool {
        $rel = ltrim(self::norm($rel), '/');
        if ($rel === '' || strpos($rel, "\0") !== false) {
            return false;
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $rel)) {
            return false;
        }
        $root = rtrim(self::norm($root), '/');
        $full = $root . '/' . $rel;
        $parent = $root;
        $realParent = realpath($parent);
        if ($realParent === false) {
            return true;
        }
        $dir = is_dir($full) ? $full : dirname($full);
        $realDir = realpath($dir);
        if ($realDir === false) {
            $prefix = rtrim(str_replace('\\', '/', $realParent), '/') . '/';
            $candidate = $prefix . $rel;
            return strncasecmp($candidate, $prefix, strlen($prefix)) === 0;
        }
        $realDir = rtrim(str_replace('\\', '/', $realDir), '/');
        $realParent = rtrim(str_replace('\\', '/', $realParent), '/');
        return $realDir === $realParent || strncasecmp($realDir, $realParent . '/', strlen($realParent) + 1) === 0;
    }
}
