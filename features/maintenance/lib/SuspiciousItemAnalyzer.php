<?php
/**
 * Clean Sweep - Shared suspicious item analyzer for plugins/themes.
 *
 * Used by PluginAnalyzer and ThemeAnalyzer for:
 * - Exact orphan matching at plugins/themes roots (not prefix)
 * - Light content / name heuristics + severity for those orphans
 * - Path allowlisting for safe cleanup
 *
 * Intentionally does not scan inside recognized packages — reinstall and
 * the malware scanner cover in-package issues without false positives.
 */

class CleanSweep_SuspiciousItemAnalyzer {

    /** Max bytes read for content heuristics. */
    const CONTENT_READ_LIMIT = 8192;

    /** Soft cap when walking directories for size/count. */
    const MAX_FILES_WALKED = 5000;

    /** Executable-looking extensions for double-ext / content checks. */
    private static $executable_extensions = ['php', 'phtml', 'php5', 'php7', 'php8', 'phar', 'inc'];

    /**
     * Names that are normal in wp-content/plugins|themes roots.
     *
     * @return string[]
     */
    public static function benign_root_names() {
        return ['index.php'];
    }

    /**
     * Exact recognition check (fixes prefix false-negatives).
     *
     * @param string   $item
     * @param string[] $recognized
     * @return bool
     */
    public static function is_recognized($item, array $recognized) {
        return in_array($item, $recognized, true);
    }

    /**
     * Scan a directory root for orphan items not in the recognized set.
     *
     * @param string   $root_dir
     * @param string[] $recognized Exact basenames that are known plugins/themes
     * @param string   $category   orphan (only category used by analyzers)
     * @param string[] $extra_ignore Additional benign basenames
     * @return array[]
     */
    public static function scan_orphans($root_dir, array $recognized, $category = 'orphan', array $extra_ignore = []) {
        $findings = [];
        if (!is_dir($root_dir)) {
            return $findings;
        }

        $ignore = array_unique(array_merge(self::benign_root_names(), $extra_ignore));
        $items = @scandir($root_dir);
        if (!is_array($items)) {
            return $findings;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (in_array($item, $ignore, true)) {
                continue;
            }
            if (self::is_recognized($item, $recognized)) {
                continue;
            }

            $full_path = $root_dir . '/' . $item;
            $analysis = self::analyze_item($full_path, $item, $category);
            if ($analysis) {
                $findings[] = $analysis;
            }
        }

        return $findings;
    }

    /**
     * Analyze a single orphan file or directory at a plugins/themes root.
     *
     * @param string $full_path
     * @param string $display_name Selection key (basename)
     * @param string $category
     * @return array|null
     */
    public static function analyze_item($full_path, $display_name, $category = 'orphan') {
        if (!file_exists($full_path)) {
            return null;
        }

        $is_dir = is_dir($full_path);
        $basename = basename($full_path);
        $size_bytes = 0;
        $file_count = 0;

        if ($is_dir) {
            list($size_bytes, $file_count) = self::directory_stats($full_path);
        } else {
            $size_bytes = (int) @filesize($full_path);
            $file_count = 1;
        }

        $reasons = self::collect_reasons($full_path, $basename, $is_dir, $size_bytes);
        if (empty($reasons)) {
            $reasons = ['Not a recognized plugin/theme'];
        }

        return [
            'name' => $display_name,
            'path' => $full_path,
            'is_directory' => $is_dir,
            'size_bytes' => $size_bytes,
            'size_mb' => round($size_bytes / 1024 / 1024, 2),
            'file_count' => $file_count,
            'last_modified' => @filemtime($full_path) ?: null,
            'readable' => is_readable($full_path),
            'writable' => is_writable($full_path),
            'reasons' => $reasons,
            'severity' => self::calculate_severity($reasons, $is_dir, $category),
            'category' => $category,
            'parent_slug' => null,
        ];
    }

    /**
     * @param string $full_path
     * @param string $basename
     * @param bool   $is_dir
     * @param int    $size_bytes
     * @return string[]
     */
    private static function collect_reasons($full_path, $basename, $is_dir, $size_bytes) {
        $reasons = [];

        // Hidden file/dir (but not ".." traversal — basename never is)
        if (isset($basename[0]) && $basename[0] === '.') {
            $reasons[] = 'Hidden file/directory';
        }

        // Double extension with executable final suffix: image.jpg.php
        if (!$is_dir && self::has_executable_double_extension($basename)) {
            $reasons[] = 'Double extension detected';
        }

        $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        if (!$is_dir && in_array($ext, self::$executable_extensions, true)) {
            if ($size_bytes > 0 && $size_bytes < 100000) {
                $content = @file_get_contents($full_path, false, null, 0, self::CONTENT_READ_LIMIT);
                if (is_string($content) && $content !== '') {
                    if (self::content_has_malware_patterns($content)) {
                        $reasons[] = 'Contains suspicious code patterns';
                    }
                    if (strlen($content) < 500 && preg_match('/<\?php\s+\/\*/i', $content)) {
                        $reasons[] = 'Suspiciously small PHP file';
                    }
                    if (preg_match('/(?:[A-Za-z0-9+\/]{80,}={0,2})/', $content) && preg_match('/base64_decode\s*\(/i', $content)) {
                        $reasons[] = 'Obfuscated base64 payload';
                    }
                }
            }

            // Short / hex dropper-style names at the package root (e.g. qa.php)
            if (preg_match('/^[a-f0-9]{8,16}\.php$/i', $basename) || preg_match('/^[a-z0-9]{1,3}\.php$/i', $basename)) {
                $reasons[] = 'Suspicious PHP filename pattern';
            }
        }

        if ($is_dir) {
            $lower = strtolower($basename);
            // Tight set — avoid flagging normal theme/plugin names containing these as substrings
            // unless the dirname is essentially that keyword or keyword-with-suffix.
            $junk_exact = ['backup', 'old', 'test', 'tmp', 'temp', 'dev', 'hidden', 'private', 'cache', 'bak'];
            if (in_array($lower, $junk_exact, true) || preg_match('/^(backup|old|tmp|temp|bak)([-_.].*)?$/i', $basename)) {
                $reasons[] = 'Suspicious directory name';
            }
        }

        return $reasons;
    }

    /**
     * @param string $content
     * @return bool
     */
    private static function content_has_malware_patterns($content) {
        // Orphan-root heuristics: prefer webshell/obfuscation tokens.
        // Deliberately omit curl_exec — too common in legitimate code if an
        // orphan happens to be a misplaced helper; unrecognized PHP is already high.
        return (bool) preg_match(
            '/\b(eval\s*\(|assert\s*\(|base64_decode\s*\(|gzinflate\s*\(|gzuncompress\s*\(|str_rot13\s*\(|shell_exec\s*\(|passthru\s*\(|system\s*\(|exec\s*\(|proc_open\s*\(|popen\s*\(|create_function\s*\(|preg_replace\s*\([^)]*\/e|`[^`]+`)/i',
            $content
        );
    }

    /**
     * @param string $basename
     * @return bool
     */
    private static function has_executable_double_extension($basename) {
        if (substr_count($basename, '.') < 2) {
            return false;
        }
        $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::$executable_extensions, true)) {
            return false;
        }
        return (bool) preg_match('/\.(jpe?g|png|gif|ico|txt|html?|js|css|svg|xml|zip|pdf)\.(php|phtml|php\d|phar|inc)$/i', $basename)
            || (bool) preg_match('/\.(php|phtml|phar)\.(php|phtml|phar)$/i', $basename);
    }

    /**
     * @param string[] $reasons
     * @param bool     $is_dir
     * @param string   $category
     * @return string
     */
    public static function calculate_severity(array $reasons, $is_dir, $category = 'orphan') {
        $high = [
            'Contains suspicious code patterns',
            'Double extension detected',
            'Obfuscated base64 payload',
            'Suspicious PHP filename pattern',
        ];
        $medium = [
            'Hidden file/directory',
            'Suspiciously small PHP file',
            'Suspicious directory name',
        ];

        foreach ($reasons as $reason) {
            if (in_array($reason, $high, true)) {
                return 'high';
            }
        }
        foreach ($reasons as $reason) {
            if (in_array($reason, $medium, true)) {
                return 'medium';
            }
        }

        // Any unrecognized PHP/file at plugins/themes root is high; leftover dirs are low.
        if (!$is_dir) {
            return 'high';
        }

        return 'low';
    }

    /**
     * @param string $directory
     * @return array{0:int,1:int} [size_bytes, file_count]
     */
    public static function directory_stats($directory) {
        $size = 0;
        $count = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $file) {
                if ($count >= self::MAX_FILES_WALKED) {
                    break;
                }
                if ($file->isFile()) {
                    $count++;
                    $size += (int) $file->getSize();
                }
            }
        } catch (Exception $e) {
            // Permission / link issues — return what we have.
        }
        return [$size, $count];
    }

    /**
     * Build allowed cleanup roots (plugins, themes, mu-plugins, ORIGINAL_*).
     *
     * @return string[] Absolute normalized paths without trailing slash
     */
    public static function allowed_cleanup_roots() {
        $roots = [];

        $candidates = [];
        if (defined('ORIGINAL_WP_PLUGIN_DIR')) {
            $candidates[] = ORIGINAL_WP_PLUGIN_DIR;
        }
        if (defined('WP_PLUGIN_DIR')) {
            $candidates[] = WP_PLUGIN_DIR;
        }
        if (defined('ORIGINAL_WP_CONTENT_DIR')) {
            $candidates[] = ORIGINAL_WP_CONTENT_DIR . '/themes';
            $candidates[] = ORIGINAL_WP_CONTENT_DIR . '/mu-plugins';
        }
        if (defined('WP_CONTENT_DIR')) {
            $candidates[] = WP_CONTENT_DIR . '/themes';
            $candidates[] = WP_CONTENT_DIR . '/mu-plugins';
        }
        if (defined('WPMU_PLUGIN_DIR')) {
            $candidates[] = WPMU_PLUGIN_DIR;
        }
        if (function_exists('get_theme_root')) {
            $candidates[] = get_theme_root();
        }

        foreach ($candidates as $c) {
            if (!$c) {
                continue;
            }
            $real = realpath($c);
            if ($real) {
                $roots[] = rtrim(str_replace('\\', '/', $real), '/');
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * Whether a path is safe to delete as a suspicious item.
     *
     * @param string   $path
     * @param string[] $protected_roots Absolute package roots that must not be deleted wholesale
     * @return array{ok:bool,error?:string,realpath?:string}
     */
    public static function validate_cleanup_path($path, array $protected_roots = []) {
        if (!is_string($path) || $path === '') {
            return ['ok' => false, 'error' => 'Missing path'];
        }

        // Resolve; if missing, still normalize for prefix checks.
        $real = realpath($path);
        $normalized = rtrim(str_replace('\\', '/', $real ?: $path), '/');

        $allowed = self::allowed_cleanup_roots();
        if (empty($allowed)) {
            return ['ok' => false, 'error' => 'No allowed cleanup roots available'];
        }

        $under_allowed = false;
        foreach ($allowed as $root) {
            if ($normalized === $root || strpos($normalized . '/', $root . '/') === 0) {
                $under_allowed = true;
                break;
            }
        }
        if (!$under_allowed) {
            return ['ok' => false, 'error' => 'Path is outside plugins/themes/mu-plugins'];
        }

        // Never delete an entire allowed root (plugins dir itself, etc.)
        foreach ($allowed as $root) {
            if ($normalized === $root) {
                return ['ok' => false, 'error' => 'Refusing to delete an entire content root'];
            }
        }

        foreach ($protected_roots as $prot) {
            $prot_real = realpath($prot);
            $prot_norm = rtrim(str_replace('\\', '/', $prot_real ?: $prot), '/');
            if ($prot_norm !== '' && $normalized === $prot_norm) {
                return ['ok' => false, 'error' => 'Refusing to delete a package selected for reinstall'];
            }
        }

        return ['ok' => true, 'realpath' => $normalized];
    }
}
