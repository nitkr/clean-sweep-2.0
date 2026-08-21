<?php
/**
 * Seal trusted scopes. Core reinstall → core only. Package reinstall → that slug.
 */
final class CleanSweep_ScopeSealer {

    private CleanSweep_VisitState $state;
    private CleanSweep_VisitCapabilities $caps;

    public function __construct(?CleanSweep_VisitState $state = null, ?CleanSweep_VisitCapabilities $caps = null) {
        $this->state = $state ?: new CleanSweep_VisitState();
        $this->caps = $caps ?: CleanSweep_VisitCapabilities::instance();
    }

    public function seal_core(?string $wp_version = null): bool {
        $this->ensure_core_helpers();
        $root = $this->site_root();
        $files = [];
        foreach (clean_sweep_get_all_core_files($root) as $abs) {
            $rel = $this->relative($root, $abs);
            $entry = $this->sample($abs);
            if ($entry !== null) {
                $files[$rel] = $entry;
            }
        }
        $site_owned = [];
        foreach ($this->site_owned_names() as $name) {
            $abs = $root . $name;
            $entry = $this->sample($abs);
            if ($entry !== null) {
                $site_owned[$name] = $entry;
            }
        }

        $data = $this->state->load();
        $data['scopes']['core'] = [
            'sealed' => true,
            'sealed_at' => time(),
            'wp_version' => $wp_version ?: (function_exists('clean_sweep_get_wordpress_version')
                ? clean_sweep_get_wordpress_version()
                : 'unknown'),
            'file_count' => count($files),
            'files' => $files,
        ];
        $data['scopes']['site_owned'] = [
            'recorded_at' => time(),
            'files' => $site_owned,
        ];
        $this->state->save($data);
        $this->state->event('sealed:core', count($files) . ' files');
        if (class_exists('CleanSweep_VisitStore')) {
            (new CleanSweep_VisitStore($this->state))->record_action('seal_core', count($files) . ' files');
        }
        return count($files) > 0;
    }

    public function seal_package(string $type, string $slug, string $dir): bool {
        $type = ($type === 'theme') ? 'theme' : 'plugin';
        $slug = trim($slug, '/');
        if ($slug === '' || !is_dir($dir)) {
            return false;
        }
        $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        $files = [];
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            if (!in_array($ext, ['php', 'phtml', 'js', 'css', 'json'], true)) {
                continue;
            }
            $rel = $this->relative($dir, $abs);
            $entry = $this->sample($abs);
            if ($entry !== null) {
                $files[$rel] = $entry;
            }
        }
        $key = $type . ':' . $slug;
        $data = $this->state->load();
        if (!isset($data['scopes']['packages']) || !is_array($data['scopes']['packages'])) {
            $data['scopes']['packages'] = [];
        }
        $data['scopes']['packages'][$key] = [
            'sealed' => true,
            'sealed_at' => time(),
            'file_count' => count($files),
            'files' => $files,
        ];
        $this->state->save($data);
        $this->state->event('sealed:' . $key, count($files) . ' files');
        return true;
    }

    /**
     * Compare sealed core (+ site-owned) to disk.
     *
     * @return array<int,array>
     */
    public function compare_sealed(): array {
        $data = $this->state->load();
        $root = $this->site_root();
        $violations = [];
        $core = $data['scopes']['core'] ?? null;
        if (is_array($core) && !empty($core['sealed']) && !empty($core['files'])) {
            foreach ($core['files'] as $rel => $sample) {
                $v = $this->diff_one($root . $rel, $rel, $sample, 'core');
                if ($v) {
                    $violations[] = $v;
                }
            }
        }
        $owned = $data['scopes']['site_owned']['files'] ?? [];
        if (is_array($owned)) {
            foreach ($owned as $rel => $sample) {
                $v = $this->diff_one($root . $rel, $rel, $sample, 'site_owned');
                if ($v) {
                    $violations[] = $v;
                }
            }
        }
        $packages = $data['scopes']['packages'] ?? [];
        if (is_array($packages)) {
            foreach ($packages as $key => $pkg) {
                if (empty($pkg['sealed']) || empty($pkg['files'])) {
                    continue;
                }
                foreach ($pkg['files'] as $rel => $sample) {
                    $v = $this->diff_one($this->package_abs($key, $rel), $key . '/' . $rel, $sample, $key);
                    if ($v) {
                        $violations[] = $v;
                    }
                }
            }
        }
        return $violations;
    }

    private function diff_one(string $abs, string $label, array $sample, string $scope): ?array {
        $exists = is_file($abs);
        $was = !empty($sample['exists']);
        if ($was && !$exists) {
            return [
                'file' => $label,
                'type' => 'deleted',
                'scope' => $scope,
                'severity' => 'critical',
                'description' => 'Sealed file is missing',
            ];
        }
        if (!$was || !$exists) {
            return null;
        }
        $hash = $this->caps->hash_path($abs);
        if ($hash !== null && !empty($sample['hash']) && $hash !== $sample['hash']) {
            return [
                'file' => $label,
                'type' => 'modified',
                'scope' => $scope,
                'severity' => 'critical',
                'description' => 'Sealed file content changed',
            ];
        }
        $size = $this->caps->size($abs);
        if ($hash === null && $size !== null && isset($sample['size']) && $size !== $sample['size']) {
            return [
                'file' => $label,
                'type' => 'modified',
                'scope' => $scope,
                'severity' => 'critical',
                'description' => 'Sealed file size changed (hash unavailable)',
            ];
        }
        return null;
    }

    private function sample(string $abs): ?array {
        if (!file_exists($abs) || !is_readable($abs) || is_dir($abs)) {
            return ['exists' => false];
        }
        return [
            'exists' => true,
            'hash' => $this->caps->hash_path($abs),
            'size' => $this->caps->size($abs),
            'mtime' => $this->caps->mtime($abs),
            'ctime' => $this->caps->ctime($abs),
        ];
    }

    private function site_owned_names(): array {
        return [
            'wp-config.php',
            '.htaccess',
            '.user.ini',
            'php.ini',
            'web.config',
            'wp-content/db.php',
            'wp-content/object-cache.php',
            'wp-content/advanced-cache.php',
            'wp-content/sunrise.php',
        ];
    }

    private function site_root(): string {
        $this->ensure_core_helpers();
        if (function_exists('clean_sweep_detect_site_root')) {
            return clean_sweep_detect_site_root();
        }
        return defined('ABSPATH') ? rtrim(str_replace('\\', '/', ABSPATH), '/') . '/' : '/';
    }

    private function relative(string $root, string $abs): string {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $abs = str_replace('\\', '/', $abs);
        if (strpos($abs, $root) === 0) {
            return substr($abs, strlen($root));
        }
        return ltrim($abs, '/');
    }

    private function package_abs(string $key, string $rel): string {
        $root = $this->site_root();
        if (strpos($key, 'theme:') === 0) {
            return $root . 'wp-content/themes/' . substr($key, 6) . '/' . $rel;
        }
        if (strpos($key, 'plugin:') === 0) {
            return $root . 'wp-content/plugins/' . substr($key, 7) . '/' . $rel;
        }
        return $root . $rel;
    }

    private function ensure_core_helpers(): void {
        if (!function_exists('clean_sweep_detect_site_root')) {
            $f = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 2) . '/')
                . 'features/maintenance/core-reinstall.php';
            if (is_readable($f)) {
                require_once $f;
            }
        }
        if (!function_exists('clean_sweep_get_all_core_files')) {
            $f = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 2) . '/')
                . 'includes/system/CleanSweep_Integrity.php';
            if (is_readable($f)) {
                require_once $f;
            }
        }
    }
}
