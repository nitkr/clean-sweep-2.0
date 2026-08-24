<?php
/**
 * Seal trusted scopes after reinstall/upload, or pin current hashes on snapshot download.
 */
final class CleanSweep_ScopeSealer {

    private const PHP_EXTS = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar'];
    private const PIN_PHP_PER_SLUG = 8000;

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
            'pinned' => false,
            'origin' => 'reinstall',
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
            'pinned' => false,
            'origin' => 'reinstall',
            'sealed_at' => time(),
            'file_count' => count($files),
            'files' => $files,
        ];
        $this->state->save($data);
        $this->state->event('sealed:' . $key, count($files) . ' files');
        return true;
    }

    /**
     * Hash the live tree into scopes so a snapshot download is a compare baseline
     * even when Clean Sweep never reinstalled anything.
     *
     * Reinstall-sealed flags are kept. File hashes are always the disk at pin time.
     *
     * @return array{file_count:int,warnings:array<int,string>,warning_groups:array,origins:array}
     */
    public function pin_for_snapshot(): array {
        $this->ensure_core_helpers();
        $root = $this->site_root();
        $data = $this->state->load();
        $now = time();

        $core_files = [];
        foreach (clean_sweep_get_all_core_files($root) as $abs) {
            $rel = $this->relative($root, $abs);
            $entry = $this->sample($abs);
            if ($entry !== null) {
                $core_files[$rel] = $entry;
            }
        }
        $prev_core = is_array($data['scopes']['core'] ?? null) ? $data['scopes']['core'] : [];
        $core_sealed = !empty($prev_core['sealed']);
        $data['scopes']['core'] = [
            'sealed' => $core_sealed,
            'pinned' => true,
            'origin' => $core_sealed ? 'reinstall' : 'snapshot',
            'sealed_at' => $prev_core['sealed_at'] ?? null,
            'pinned_at' => $now,
            'wp_version' => function_exists('clean_sweep_get_wordpress_version')
                ? clean_sweep_get_wordpress_version()
                : (string) ($prev_core['wp_version'] ?? 'unknown'),
            'file_count' => count($core_files),
            'files' => $core_files,
        ];

        $groups = [
            'site_owned' => [],
            'mu_plugins' => [],
            'root_php' => [],
            'wp_content' => [],
            'uploads_exec' => [],
            'bootstrap' => [],
        ];

        $site_owned = [];
        foreach ($this->site_owned_names() as $name) {
            $entry = $this->sample($root . $name);
            if ($entry !== null) {
                $site_owned[$name] = $entry;
                if (!empty($entry['exists']) && in_array($name, ['.user.ini', 'wp-content/db.php'], true)) {
                    $groups['site_owned'][] = $name;
                }
            }
        }
        foreach ($this->list_root_extra_php($root) as $abs) {
            $rel = $this->relative($root, $abs);
            if ($rel === '' || $this->is_toolkit_root_php($rel)) {
                continue;
            }
            $entry = $this->sample($abs);
            if ($entry === null) {
                continue;
            }
            $site_owned[$rel] = $entry;
            if (!empty($entry['exists'])) {
                $groups['root_php'][] = $rel;
            }
        }
        foreach ($this->list_wp_content_root_exec($root) as $abs) {
            $rel = $this->relative($root, $abs);
            if ($rel === '' || isset($site_owned[$rel])) {
                continue;
            }
            $entry = $this->sample($abs);
            if ($entry === null) {
                continue;
            }
            $site_owned[$rel] = $entry;
            $base = strtolower(basename(str_replace('\\', '/', $rel)));
            if (!empty($entry['exists']) && $base !== 'index.php') {
                $groups['wp_content'][] = $rel;
            }
        }
        $data['scopes']['site_owned'] = [
            'recorded_at' => $now,
            'pinned' => true,
            'files' => $site_owned,
        ];

        $mu_files = [];
        $mu_dir = $root . 'wp-content/mu-plugins';
        if (is_dir($mu_dir)) {
            foreach ($this->list_pin_files($mu_dir, 200) as $abs) {
                $rel = $this->relative($root, $abs);
                $entry = $this->sample($abs);
                if ($entry !== null) {
                    $mu_files[$rel] = $entry;
                    if (!empty($entry['exists']) && !$this->is_watch_agent($rel)) {
                        $groups['mu_plugins'][] = $rel;
                    }
                }
            }
        }
        $data['scopes']['mu_plugins'] = [
            'pinned' => true,
            'pinned_at' => $now,
            'file_count' => count($mu_files),
            'files' => $mu_files,
        ];

        if (!isset($data['scopes']['packages']) || !is_array($data['scopes']['packages'])) {
            $data['scopes']['packages'] = [];
        }
        $live = $this->installed_package_dirs($root);
        $pkg_count = 0;
        foreach ($live as $key => $path) {
            $single = is_file($path) && !is_dir($path);
            $files = $single
                ? [basename($path) => $this->sample($path)]
                : $this->hash_tree_pin($path, self::PIN_PHP_PER_SLUG);
            $files = array_filter($files, static function ($entry) {
                return is_array($entry);
            });
            $prev = is_array($data['scopes']['packages'][$key] ?? null) ? $data['scopes']['packages'][$key] : [];
            $was_sealed = !empty($prev['sealed']);
            $data['scopes']['packages'][$key] = [
                'sealed' => $was_sealed,
                'pinned' => true,
                'origin' => $was_sealed ? 'reinstall' : 'snapshot',
                'sealed_at' => $prev['sealed_at'] ?? null,
                'pinned_at' => $now,
                'single_file' => $single,
                'file_count' => count($files),
                'files' => $files,
            ];
            $pkg_count++;
        }
        foreach (array_keys($data['scopes']['packages']) as $key) {
            if (!isset($live[$key])) {
                unset($data['scopes']['packages'][$key]);
            }
        }

        foreach ($data['scopes']['packages'] as $key => $pkg) {
            foreach ($this->bootstrap_pin_warnings((string) $key, is_array($pkg['files'] ?? null) ? $pkg['files'] : []) as $w) {
                $groups['bootstrap'][] = $w;
            }
        }

        $uploads_files = [];
        foreach ($this->list_uploads_exec($root) as $abs) {
            $rel = $this->relative($root, $abs);
            if ($rel === '') {
                continue;
            }
            $entry = $this->sample($abs);
            if ($entry === null) {
                continue;
            }
            if ($this->php_in_image($abs)) {
                $entry['php_in_image'] = true;
            }
            $uploads_files[$rel] = $entry;
            $base = strtolower(basename(str_replace('\\', '/', $rel)));
            if (!empty($entry['exists']) && $base !== '.htaccess' && $base !== 'index.php') {
                $groups['uploads_exec'][] = $rel;
            }
        }
        $data['scopes']['uploads_exec'] = [
            'pinned' => true,
            'pinned_at' => $now,
            'file_count' => count($uploads_files),
            'files' => $uploads_files,
        ];

        $warnings = [];
        foreach ($groups as $items) {
            foreach ($items as $w) {
                $w = (string) $w;
                if ($w !== '') {
                    $warnings[] = $w;
                }
            }
        }
        $warnings = array_values(array_unique($warnings));
        $data['pin_warning_groups'] = $groups;

        $this->state->save($data);
        $n = count($core_files) + count($site_owned) + count($mu_files) + count($uploads_files);
        foreach ($data['scopes']['packages'] as $pkg) {
            $n += (int) ($pkg['file_count'] ?? 0);
        }
        $this->state->event('snapshot:pinned', $n . ' files · ' . $pkg_count . ' packages');
        return [
            'file_count' => $n,
            'warnings' => $warnings,
            'warning_groups' => $groups,
            'origins' => [
                'core' => $data['scopes']['core']['origin'] ?? 'snapshot',
                'packages_pinned' => $pkg_count,
            ],
        ];
    }

    /**
     * Compare sealed or snapshot-pinned trees to disk.
     *
     * Missing or unpinned scopes are not a baseline. An empty files map
     * must not mean "snapshot of nothing" (first scan false reinfection).
     *
     * @return array<int,array>
     */
    public function compare_sealed(): array {
        $data = $this->state->load();
        $root = $this->site_root();
        $violations = [];
        $core = is_array($data['scopes']['core'] ?? null) ? $data['scopes']['core'] : null;
        $owned_scope = is_array($data['scopes']['site_owned'] ?? null) ? $data['scopes']['site_owned'] : null;
        $owned = $this->scope_files($owned_scope);
        $mu_scope = is_array($data['scopes']['mu_plugins'] ?? null) ? $data['scopes']['mu_plugins'] : null;
        $mu = $this->scope_files($mu_scope);
        $core_known = [];
        if (is_array($core) && $this->in_baseline($core) && !empty($core['files']) && is_array($core['files'])) {
            foreach ($core['files'] as $rel => $sample) {
                if (!is_array($sample)) {
                    continue;
                }
                $core_known[(string) $rel] = $sample;
                $v = $this->diff_one($root . $rel, $rel, $sample, 'core', false);
                if ($v) {
                    $violations[] = $v;
                }
            }
        }
        foreach ($owned as $rel => $sample) {
            if (!isset($core_known[(string) $rel])) {
                $core_known[(string) $rel] = is_array($sample) ? $sample : [];
            }
        }
        if (is_array($core) && $this->in_baseline($core)) {
            foreach ($this->new_core_php_violations($root, $core_known) as $v) {
                $violations[] = $v;
            }
        }
        foreach ($owned as $rel => $sample) {
            if (!is_array($sample)) {
                continue;
            }
            $v = $this->diff_one($root . $rel, $rel, $sample, 'site_owned', true);
            if ($v) {
                $violations[] = $v;
            }
        }
        foreach ($mu as $rel => $sample) {
            if (!is_array($sample)) {
                continue;
            }
            $v = $this->diff_one($root . $rel, $rel, $sample, 'mu_plugins', true);
            if ($v) {
                $violations[] = $v;
            }
        }
        $mu_dir = $root . 'wp-content/mu-plugins';
        if (is_dir($mu_dir) && $this->in_baseline($mu_scope ?? [])) {
            foreach ($this->list_pin_files($mu_dir, 200) as $abs) {
                $rel = $this->relative($root, $abs);
                if (isset($mu[$rel]) || $this->is_watch_agent($rel)) {
                    continue;
                }
                $violations[] = [
                    'file' => $rel,
                    'type' => 'created',
                    'scope' => 'mu_plugins',
                    'severity' => 'critical',
                    'description' => 'New must-use plugin since snapshot',
                ];
            }
        }
        $packages = $data['scopes']['packages'] ?? [];
        if (is_array($packages)) {
            foreach ($packages as $key => $pkg) {
                if (!is_array($pkg) || !$this->in_baseline($pkg)) {
                    continue;
                }
                foreach ($pkg['files'] ?? [] as $rel => $sample) {
                    if (!is_array($sample)) {
                        continue;
                    }
                    $label = $this->package_site_rel((string) $key, (string) $rel);
                    $v = $this->diff_one($this->package_abs($key, $rel), $label, $sample, (string) $key, false);
                    if ($v) {
                        $violations[] = $v;
                    }
                }
                foreach ($this->new_package_php_violations((string) $key, $pkg) as $v) {
                    $violations[] = $v;
                }
            }
        }
        $live = $this->installed_package_dirs($root);
        $pinned_pkgs = is_array($packages) ? $packages : [];
        $has_pkg_pin = false;
        foreach ($pinned_pkgs as $pkg) {
            if (is_array($pkg) && $this->in_baseline($pkg)) {
                $has_pkg_pin = true;
                break;
            }
        }
        foreach ($has_pkg_pin ? $live : [] as $key => $path) {
            if (isset($pinned_pkgs[$key]) && $this->in_baseline($pinned_pkgs[$key])) {
                continue;
            }
            $violations[] = [
                'file' => rtrim($this->package_site_rel((string) $key, ''), '/'),
                'type' => 'created',
                'scope' => (string) $key,
                'severity' => 'critical',
                'description' => 'New plugin/theme since snapshot',
                'new_package' => true,
            ];
            foreach ($this->new_package_root_files((string) $key, $path) as $v) {
                $violations[] = $v;
            }
        }
        if ($this->in_baseline($owned_scope ?? [])) {
            foreach ($this->list_wp_content_root_exec($root) as $abs) {
                $rel = $this->relative($root, $abs);
                if ($rel === '' || isset($owned[$rel])) {
                    continue;
                }
                $violations[] = [
                    'file' => $rel,
                    'type' => 'created',
                    'scope' => 'site_owned',
                    'severity' => 'critical',
                    'description' => 'New PHP or pre-boot file under wp-content since snapshot',
                ];
            }
        }
        $up = is_array($data['scopes']['uploads_exec'] ?? null) ? $data['scopes']['uploads_exec'] : null;
        $up_files = $this->scope_files($up);
        if (is_array($up) && $this->in_baseline($up) && $up_files !== []) {
            foreach ($up_files as $rel => $sample) {
                if (!is_array($sample)) {
                    continue;
                }
                $v = $this->diff_one($root . $rel, (string) $rel, $sample, 'uploads_exec', true);
                if ($v) {
                    $violations[] = $v;
                }
            }
        }
        if (is_array($up) && $this->in_baseline($up)) {
            foreach ($this->list_uploads_exec($root) as $abs) {
                $rel = $this->relative($root, $abs);
                if ($rel === '' || isset($up_files[$rel])) {
                    continue;
                }
                $violations[] = [
                    'file' => $rel,
                    'type' => 'created',
                    'scope' => 'uploads_exec',
                    'severity' => 'critical',
                    'description' => $this->php_in_image($abs)
                        ? 'New PHP inside an uploads image since snapshot'
                        : 'New PHP or pre-boot file in uploads since snapshot',
                ];
            }
        }
        return $violations;
    }

    public function package_site_rel(string $key, string $rel): string {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (strpos($key, 'theme:') === 0) {
            $slug = substr($key, 6);
            return 'wp-content/themes/' . $slug . ($rel !== '' ? '/' . $rel : '');
        }
        if (strpos($key, 'plugin:') === 0) {
            $slug = substr($key, 7);
            if ($this->is_single_plugin_key($key)) {
                return 'wp-content/plugins/' . $slug;
            }
            return 'wp-content/plugins/' . $slug . ($rel !== '' ? '/' . $rel : '');
        }
        return $rel;
    }

    private function in_baseline(array $scope): bool {
        return !empty($scope['sealed']) || !empty($scope['pinned']);
    }

    /**
     * @param array|null $scope
     * @return array<string,array>
     */
    private function scope_files(?array $scope): array {
        if (!is_array($scope)) {
            return [];
        }
        $files = $scope['files'] ?? null;
        return is_array($files) ? $files : [];
    }

    /**
     * @param array{exists?:bool,hash?:string,size?:int} $sample
     */
    private function diff_one(string $abs, string $label, array $sample, string $scope, bool $allow_created): ?array {
        $exists = is_file($abs);
        $was = !empty($sample['exists']);
        if ($was && !$exists) {
            return [
                'file' => $label,
                'type' => 'deleted',
                'scope' => $scope,
                'severity' => 'critical',
                'description' => 'Baseline file is missing',
            ];
        }
        if (!$was && $exists && $allow_created) {
            return [
                'file' => $label,
                'type' => 'created',
                'scope' => $scope,
                'severity' => 'critical',
                'description' => 'New always-on / site-owned file since snapshot',
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
                'description' => 'Baseline file content changed',
            ];
        }
        $size = $this->caps->size($abs);
        if ($hash === null && $size !== null && isset($sample['size']) && $size !== $sample['size']) {
            return [
                'file' => $label,
                'type' => 'modified',
                'scope' => $scope,
                'severity' => 'critical',
                'description' => 'Baseline file size changed (hash unavailable)',
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

    /**
     * PHP added under wp-admin / wp-includes / WP root after the snapshot.
     *
     * @param array<string,array> $known
     * @return array<int,array>
     */
    private function new_core_php_violations(string $root, array $known): array {
        $out = [];
        foreach (['wp-admin', 'wp-includes'] as $dir) {
            $abs_dir = $root . $dir;
            if (!is_dir($abs_dir)) {
                continue;
            }
            foreach ($this->list_php_sorted($abs_dir, 8000) as $abs) {
                $rel = $this->relative($root, $abs);
                if ($rel === '' || isset($known[$rel]) || $this->is_toolkit_root_php($rel)) {
                    continue;
                }
                $out[] = [
                    'file' => $rel,
                    'type' => 'created',
                    'scope' => 'core',
                    'severity' => 'critical',
                    'description' => 'New PHP under WordPress core since snapshot',
                ];
            }
        }
        foreach ($this->list_root_extra_php($root) as $abs) {
            $rel = $this->relative($root, $abs);
            if ($rel === '' || isset($known[$rel]) || $this->is_toolkit_root_php($rel)) {
                continue;
            }
            $out[] = [
                'file' => $rel,
                'type' => 'created',
                'scope' => 'core',
                'severity' => 'critical',
                'description' => 'New PHP in the WordPress root since snapshot',
            ];
        }
        return $out;
    }

    private function new_package_php_violations(string $key, array $pkg): array {
        if (!empty($pkg['single_file']) || $this->is_single_plugin_key($key)) {
            return [];
        }
        $dir = $this->package_abs($key, '');
        if (!is_dir($dir)) {
            return [];
        }
        $known = is_array($pkg['files'] ?? null) ? $pkg['files'] : [];
        $out = [];
        foreach ($this->list_pin_files($dir, self::PIN_PHP_PER_SLUG + 500) as $abs) {
            $rel = $this->relative($dir, $abs);
            if ($rel === '' || isset($known[$rel])) {
                continue;
            }
            $root_php = $this->is_package_root_pin($rel);
            $out[] = [
                'file' => $this->package_site_rel($key, $rel),
                'type' => 'created',
                'scope' => $key,
                'severity' => $root_php ? 'critical' : 'warning',
                'description' => $root_php
                    ? 'New PHP or pre-boot file at plugin/theme root since snapshot'
                    : 'New PHP or pre-boot file under this package not in the snapshot',
            ];
        }
        return $out;
    }

    private function is_package_root_php(string $rel): bool {
        return $this->is_package_root_pin($rel);
    }

    private function is_package_root_pin(string $rel): bool {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($rel, '/') !== false) {
            return false;
        }
        $base = strtolower($rel);
        if ($base === '.htaccess' || $base === '.user.ini') {
            return true;
        }
        return (bool) preg_match('/\.(?:php\d*|phtml|phar)$/i', $rel)
            || (bool) preg_match('/\.(?:php\d*|phtml|phar)(?:\.|$)/i', $base);
    }

    /**
     * Root-level PHP / pre-boot files in a package that was not in the snapshot.
     *
     * @return array<int,array>
     */
    private function new_package_root_files(string $key, string $path): array {
        $out = [];
        if (is_file($path) && !is_dir($path)) {
            $rel = $this->relative($this->site_root(), $path);
            $out[] = [
                'file' => $rel !== '' ? $rel : $this->package_site_rel($key, basename($path)),
                'type' => 'created',
                'scope' => $key,
                'severity' => 'critical',
                'description' => 'New plugin file since snapshot',
            ];
            return $out;
        }
        if (!is_dir($path)) {
            return $out;
        }
        $names = @scandir($path);
        if (!is_array($names)) {
            return $out;
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $abs = rtrim($path, '/\\') . '/' . $name;
            if (!is_file($abs) || !$this->is_exec_basename($name)) {
                continue;
            }
            $out[] = [
                'file' => $this->package_site_rel($key, $name),
                'type' => 'created',
                'scope' => $key,
                'severity' => 'critical',
                'description' => 'New PHP or pre-boot file at plugin/theme root since snapshot',
            ];
        }
        return $out;
    }

    private function is_watch_agent(string $rel): bool {
        $base = strtolower(basename(str_replace('\\', '/', $rel)));
        $agent = '00-clean-sweep-visit-watch.php';
        if (class_exists('CleanSweep_VisitWatch', false)) {
            $agent = strtolower(CleanSweep_VisitWatch::AGENT_BASENAME);
        }
        return $base === $agent;
    }

    /**
     * Packed PHP in a plugin/theme *root* file at pin time (any name).
     * Nested vendor functions.php size is not a signal.
     *
     * @param array<string,array> $files
     * @return string[]
     */
    private function bootstrap_pin_warnings(string $key, array $files): array {
        $out = [];
        if (strpos($key, 'theme:') !== 0 && strpos($key, 'plugin:') !== 0) {
            return $out;
        }
        $needles = ['gzinflate', 'gzdecode', 'gzuncompress', 'auto_prepend_file', 'create_function', 'eval('];
        foreach ($files as $rel => $sample) {
            if (!is_array($sample) || empty($sample['exists'])) {
                continue;
            }
            $rel = str_replace('\\', '/', (string) $rel);
            if ($rel === '' || strpos($rel, '/') !== false) {
                continue;
            }
            if (!preg_match('/\.(?:php\d*|phtml|phar)$/i', $rel)) {
                continue;
            }
            $abs = $this->package_abs($key, $rel);
            if (!is_readable($abs) || is_dir($abs)) {
                continue;
            }
            $fh = @fopen($abs, 'rb');
            if (!$fh) {
                continue;
            }
            $size = is_int($st = $sample['size'] ?? null) ? $st : 0;
            $blob = (string) fread($fh, 24576);
            if ($size > 49152) {
                fseek($fh, (int) max(0, (int) ($size / 2) - 8192));
                $blob .= (string) fread($fh, 16384);
            }
            if ($size > 24576) {
                fseek($fh, -24576, SEEK_END);
                $blob .= (string) fread($fh, 24576);
            }
            fclose($fh);
            $label = $this->package_site_rel($key, $rel);
            foreach ($needles as $n) {
                if (stripos($blob, $n) !== false) {
                    $out[] = $label . ' (suspicious content at pin time: ' . $n . ')';
                    break;
                }
            }
        }
        return $out;
    }

    private function is_single_plugin_key(string $key): bool {
        if (strpos($key, 'plugin:') !== 0) {
            return false;
        }
        $slug = strtolower(substr($key, 7));
        return (bool) preg_match('/\.(?:php\d*|phtml|phar)$/', $slug);
    }

    private function package_abs(string $key, string $rel): string {
        $root = $this->site_root();
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (strpos($key, 'theme:') === 0) {
            $base = $root . 'wp-content/themes/' . substr($key, 6);
            return $rel === '' ? $base : $base . '/' . $rel;
        }
        if (strpos($key, 'plugin:') === 0) {
            $slug = substr($key, 7);
            if ($this->is_single_plugin_key($key)) {
                return $root . 'wp-content/plugins/' . $slug;
            }
            $base = $root . 'wp-content/plugins/' . $slug;
            return $rel === '' ? $base : $base . '/' . $rel;
        }
        return $root . $rel;
    }

    /**
     * @return array<string,string> package key => dir
     */
    private function installed_package_dirs(string $root): array {
        $out = [];
        foreach (['plugin' => 'wp-content/plugins', 'theme' => 'wp-content/themes'] as $type => $rel) {
            $base = $root . $rel;
            if (!is_dir($base)) {
                continue;
            }
            $names = @scandir($base);
            if (!is_array($names)) {
                continue;
            }
            sort($names);
            foreach ($names as $slug) {
                if ($slug === '.' || $slug === '..' || $slug === 'index.php' || $slug === 'clean-sweep') {
                    continue;
                }
                $dir = $base . '/' . $slug;
                if (is_dir($dir)) {
                    $out[$type . ':' . $slug] = rtrim(str_replace('\\', '/', $dir), '/') . '/';
                    continue;
                }
                if ($type === 'plugin' && is_file($dir)) {
                    $ext = strtolower(pathinfo($dir, PATHINFO_EXTENSION));
                    if (in_array($ext, self::PHP_EXTS, true)) {
                        $out[$type . ':' . $slug] = str_replace('\\', '/', $dir);
                    }
                }
            }
        }
        return $out;
    }

    /** @return array<string,array> rel => sample */
    private function hash_tree_php(string $dir, int $max): array {
        return $this->hash_tree_pin($dir, $max);
    }

    /** @return array<string,array> rel => sample */
    private function hash_tree_pin(string $dir, int $max): array {
        $files = [];
        foreach ($this->list_pin_files($dir, $max) as $abs) {
            $rel = $this->relative($dir, $abs);
            $entry = $this->sample($abs);
            if ($entry !== null) {
                $files[$rel] = $entry;
            }
        }
        return $files;
    }

    /** @return string[] absolute paths, sorted */
    private function list_php_sorted(string $dir, int $max): array {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
        } catch (Throwable $e) {
            return $out;
        }
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, self::PHP_EXTS, true)) {
                continue;
            }
            $out[] = $file->getPathname();
        }
        sort($out);
        if (count($out) > $max) {
            $out = array_slice($out, 0, $max);
        }
        return $out;
    }

    /** PHP, php-in-name, .htaccess, .user.ini — sorted then capped. */
    private function list_pin_files(string $dir, int $max): array {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
        } catch (Throwable $e) {
            return $out;
        }
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (!$this->is_exec_basename($file->getFilename())) {
                continue;
            }
            $out[] = $file->getPathname();
        }
        sort($out);
        if (count($out) > $max) {
            $out = array_slice($out, 0, $max);
        }
        return $out;
    }

    private function is_exec_basename(string $base): bool {
        $base = strtolower($base);
        if ($base === '.htaccess' || $base === '.user.ini') {
            return true;
        }
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (in_array($ext, self::PHP_EXTS, true)) {
            return true;
        }
        return (bool) preg_match('/\.(?:php\d*|phtml|phar)(?:\.|$)/i', $base);
    }

    /** PHP / pre-boot sitting directly in wp-content/ (not plugins, themes, uploads, cache). */
    private function list_wp_content_root_exec(string $root): array {
        $dir = $root . 'wp-content';
        $out = [];
        $skip = [
            'db.php' => true,
            'object-cache.php' => true,
            'advanced-cache.php' => true,
            'sunrise.php' => true,
        ];
        foreach (['php', 'phtml', 'phar'] as $ext) {
            foreach (glob($dir . '/*.' . $ext) ?: [] as $abs) {
                $base = strtolower(basename($abs));
                if (isset($skip[$base])) {
                    continue;
                }
                $out[] = $abs;
            }
        }
        foreach (['.htaccess', '.user.ini'] as $name) {
            $abs = $dir . '/' . $name;
            if (is_file($abs)) {
                $out[] = $abs;
            }
        }
        return $out;
    }

    /**
     * Uploads PHP, php.* names, .htaccess/.user.ini, and images that contain PHP.
     *
     * @return string[]
     */
    private function list_uploads_exec(string $root): array {
        $dir = $root . 'wp-content/uploads';
        if (!is_dir($dir)) {
            return [];
        }
        $php = [];
        $images = [];
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
        } catch (Throwable $e) {
            return [];
        }
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            $base = $file->getFilename();
            $ext = strtolower($file->getExtension());
            if ($this->is_exec_basename($base)) {
                if (count($php) < 2000) {
                    $php[] = $abs;
                }
                continue;
            }
            if (count($images) < 400 && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
                $images[] = $abs;
            }
        }
        $out = $php;
        foreach ($images as $abs) {
            if ($this->php_in_image($abs)) {
                $out[] = $abs;
            }
        }
        return $out;
    }

    private function php_in_image(string $abs): bool {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
            return false;
        }
        if (!is_readable($abs)) {
            return false;
        }
        $fh = @fopen($abs, 'rb');
        if (!$fh) {
            return false;
        }
        $head = (string) fread($fh, 4096);
        $tail = '';
        $size = $this->caps->size($abs);
        if ($size !== null && $size > 8192) {
            fseek($fh, -4096, SEEK_END);
            $tail = (string) fread($fh, 4096);
        }
        fclose($fh);
        $blob = $head . $tail;
        return stripos($blob, '<?php') !== false || stripos($blob, '<?=') !== false;
    }

    /** Toolkit drop-in at the WordPress root — not site malware. */
    private function is_toolkit_root_php(string $rel): bool {
        $rel = str_replace('\\', '/', ltrim($rel, '/'));
        if (strpos($rel, '/') !== false) {
            return false;
        }
        return strtolower($rel) === 'clean-sweep.php';
    }

    /** @return string[] absolute paths */
    private function list_root_extra_php(string $root): array {
        $official = [
            'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php',
            'wp-config.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php',
            'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php',
            'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php',
        ];
        $flip = array_flip($official);
        $out = [];
        foreach (['php', 'phtml', 'phar'] as $ext) {
            foreach (glob($root . '*.' . $ext) ?: [] as $abs) {
                $base = basename($abs);
                if (isset($flip[$base]) || $this->is_toolkit_root_php($base)) {
                    continue;
                }
                $out[] = $abs;
            }
        }
        return $out;
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
