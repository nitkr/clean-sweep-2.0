<?php
/**
 * Chunked high-value sampling for the visit store.
 */
final class CleanSweep_Census {

    private const PHP_EXTS = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar'];

    private const EXTRA_PHP_PER_TREE = 8000;
    private const UPLOAD_PHP_CAP = 2000;
    private const UPLOAD_IMAGE_CAP = 400;
    private const UPLOAD_ALL_MEDIA_CAP = 800;
    private const WP_CONTENT_OTHER_CAP = 2000;

    private CleanSweep_VisitCapabilities $caps;
    private CleanSweep_VisitStore $store;
    private ?string $root_override = null;

    public function __construct(
        ?CleanSweep_VisitStore $store = null,
        ?CleanSweep_VisitCapabilities $caps = null,
        ?string $root = null
    ) {
        $this->store = $store ?: new CleanSweep_VisitStore();
        $this->caps = $caps ?: CleanSweep_VisitCapabilities::instance();
        if (is_string($root) && $root !== '') {
            $this->root_override = rtrim(str_replace('\\', '/', $root), '/') . '/';
        }
    }

    public function store(): CleanSweep_VisitStore {
        return $this->store;
    }

    public function sample_file(string $abs): array {
        return [
            'hash' => $this->caps->hash_path($abs),
            'size' => $this->caps->size($abs),
            'mtime' => $this->caps->mtime($abs),
            'ctime' => $this->caps->ctime($abs),
            'inode' => $this->inode($abs),
        ];
    }

    /**
     * @param object|null $ctx Optional worker context (slice/cancel). Snapshot callers omit it.
     */
    public function run_phase(string $phase, int $offset = 0, array $payload = [], $ctx = null): array {
        switch ($phase) {
            case 'site_owned':
                return $this->phase_site_owned($ctx);
            case 'extra_php':
                return $this->phase_extra_php($offset, $payload, $ctx);
            case 'wp_content':
                return $this->phase_wp_content($offset, $payload, $ctx);
            case 'uploads':
                return $this->phase_uploads($offset, $payload, $ctx);
            case 'options':
                return $this->phase_options();
            default:
                return ['done' => true, 'next' => null, 'count' => 0];
        }
    }

    private function phase_site_owned($ctx = null): array {
        $root = $this->site_root();
        $names = [
            'wp-config.php', '.htaccess', '.user.ini', 'php.ini', 'web.config',
            'wp-content/db.php', 'wp-content/object-cache.php',
            'wp-content/advanced-cache.php', 'wp-content/sunrise.php',
        ];
        $samples = [];
        foreach ($names as $rel) {
            $abs = $root . $rel;
            if (is_file($abs) && is_readable($abs)) {
                $samples[$rel] = $this->sample_file($abs);
            }
        }
        $mu = $root . 'wp-content/mu-plugins';
        if (is_dir($mu)) {
            foreach ($this->list_php($mu, 80) as $abs) {
                $rel = $this->rel($root, $abs);
                $samples[$rel] = $this->sample_file($abs);
            }
        }
        foreach ($this->list_root_extra_php($root) as $abs) {
            $rel = $this->rel($root, $abs);
            $samples[$rel] = $this->sample_file($abs);
        }
        $unexpected = $this->store->diff_bucket('site_owned', $samples);
        $this->store->add_unexpected($unexpected);
        $this->store->state()->event('census:site-owned', (string) count($samples));
        if ($this->ctx_cancelled($ctx)) {
            return ['cancelled' => true, 'done' => true, 'next' => null, 'count' => count($samples)];
        }
        return ['done' => true, 'next' => 'extra_php', 'count' => count($samples)];
    }

    /**
     * Live path => {hash} for the portable snapshot (and import drift).
     *
     * @return array{site_owned:array<string,array>,extra_php:array<string,array>,uploads:array<string,array>}
     */
    public function collect_watch(bool $all_media = false, bool $include_extra_php = true, array $skip_package_keys = []): array {
        $root = $this->site_root();
        $skip = [];
        foreach ($skip_package_keys as $k) {
            $skip[(string) $k] = true;
        }
        return [
            'site_owned' => $this->hash_rels($this->list_site_owned_abs($root), $root),
            'extra_php' => $include_extra_php
                ? $this->hash_rels($this->list_plugin_theme_php($root, self::EXTRA_PHP_PER_TREE, $skip), $root)
                : [],
            'wp_content' => $this->hash_rels($this->list_wp_content_other($root, self::WP_CONTENT_OTHER_CAP), $root),
            'uploads' => $this->hash_upload_watch($root, $all_media),
        ];
    }

    private function phase_extra_php(int $offset, array $payload = [], $ctx = null): array {
        $root = $this->site_root();
        $resume_after = $this->norm_path((string) ($payload['resume_after'] ?? ''));
        $slug_php_seen = max(0, (int) ($payload['slug_php_seen'] ?? 0));
        $resume_slug = (string) ($payload['resume_slug'] ?? '');
        $skip_count = ($resume_after === '') ? max(0, $offset) : 0;
        if ($resume_after !== '' && !file_exists($resume_after) && !is_link($resume_after)) {
            $resume_after = '';
            $slug_php_seen = 0;
            $resume_slug = '';
        }
        $skipping = $resume_after !== '';
        $samples = [];
        $budget = 80;
        $hit_resume_slug = ($resume_slug === '');

        foreach (['wp-content/plugins' => 'plugin', 'wp-content/themes' => 'theme'] as $seg => $type) {
            $base = $root . $seg;
            if (!is_dir($base)) {
                continue;
            }
            $names = @scandir($base);
            if (!is_array($names)) {
                continue;
            }
            sort($names);
            foreach ($names as $slug) {
                if ($slug === '.' || $slug === '..' || $slug === 'index.php') {
                    continue;
                }
                $key = $type . ':' . $slug;
                if (!$hit_resume_slug) {
                    if ($key !== $resume_slug) {
                        continue;
                    }
                    $hit_resume_slug = true;
                }
                $dir = $base . '/' . $slug;
                $php_in_slug = ($key === $resume_slug) ? $slug_php_seen : 0;
                $paths = [];
                if (is_dir($dir)) {
                    $paths[] = $dir;
                } elseif (is_file($dir)) {
                    $paths[] = $dir;
                }
                foreach ($paths as $start) {
                    if (is_file($start)) {
                        $files = [$start];
                    } else {
                        try {
                            $files = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS)
                            );
                        } catch (Throwable $e) {
                            continue;
                        }
                    }
                    foreach ($files as $file) {
                        $abs = is_string($file) ? $file : $file->getPathname();
                        if (!is_string($file) && (!$file->isFile())) {
                            continue;
                        }
                        if (is_string($file) && !is_file($abs)) {
                            continue;
                        }
                        $path = $this->norm_path($abs);
                        $skip_item = false;
                        if ($skipping) {
                            if ($path === $resume_after) {
                                $skipping = false;
                            }
                            $skip_item = true;
                        }

                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                        $is_php = in_array($ext, self::PHP_EXTS, true);
                        if (!$skip_item && $is_php) {
                            if ($skip_count > 0) {
                                $skip_count--;
                                $php_in_slug++;
                            } elseif ($php_in_slug < self::EXTRA_PHP_PER_TREE) {
                                $php_in_slug++;
                                $samples[$this->rel($root, $abs)] = $this->sample_file($abs);
                            }
                        }

                        $stop = $this->census_stop(
                            $ctx,
                            $skip_item,
                            $resume_after,
                            $path,
                            $samples,
                            'extra_php',
                            $budget,
                            [
                                'slug_php_seen' => $php_in_slug,
                                'resume_slug' => $key,
                            ]
                        );
                        if ($stop !== null) {
                            return $stop;
                        }
                        if (!$skipping && $php_in_slug >= self::EXTRA_PHP_PER_TREE) {
                            break;
                        }
                    }
                }
            }
        }

        $miss = $this->cursor_missed_retry('extra_php', $skipping, $samples);
        if ($miss !== null) {
            return $miss;
        }
        if ($samples !== []) {
            $this->store->put_samples('extra_php', $samples, false);
        }
        $merged = $this->store->samples('extra_php');
        $unexpected = $this->store->diff_bucket('extra_php', $merged);
        $this->store->add_unexpected($unexpected);
        $this->store->state()->event('census:extra-php', (string) count($merged));
        return ['done' => true, 'next' => 'wp_content', 'offset' => 0, 'count' => count($samples)];
    }

    private function phase_wp_content(int $offset, array $payload = [], $ctx = null): array {
        $root = $this->site_root();
        $dir = $root . 'wp-content';
        if (!is_dir($dir)) {
            return ['done' => true, 'next' => 'uploads', 'count' => 0];
        }
        $resume_after = $this->norm_path((string) ($payload['resume_after'] ?? ''));
        $seen = max(0, (int) ($payload['tree_seen'] ?? 0));
        $skip_count = ($resume_after === '') ? max(0, $offset) : 0;
        if ($resume_after !== '' && !file_exists($resume_after) && !is_link($resume_after)) {
            $resume_after = '';
        }
        $skipping = $resume_after !== '';
        $samples = [];
        $budget = 80;
        $skip_dirs = [
            'plugins' => true, 'themes' => true, 'uploads' => true,
            'mu-plugins' => true, 'node_modules' => true, '.git' => true, 'clean-sweep' => true,
        ];
        $skip_files = [
            'db.php' => true, 'object-cache.php' => true,
            'advanced-cache.php' => true, 'sunrise.php' => true,
        ];
        try {
            $inner = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
            $filtered = new RecursiveCallbackFilterIterator($inner, static function ($current) use ($skip_dirs) {
                if ($current->isDir()) {
                    return !isset($skip_dirs[strtolower($current->getFilename())]);
                }
                return true;
            });
            $iter = new RecursiveIteratorIterator($filtered);
        } catch (Throwable $e) {
            return ['done' => true, 'next' => 'uploads', 'count' => 0];
        }
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $this->norm_path($file->getPathname());
            $skip_item = false;
            if ($skipping) {
                if ($path === $resume_after) {
                    $skipping = false;
                }
                $skip_item = true;
            }
            $base = $file->getFilename();
            $ext = strtolower($file->getExtension());
            $watch = !isset($skip_files[strtolower($base)]) && (
                in_array($ext, self::PHP_EXTS, true)
                || $base === '.htaccess'
                || $base === '.user.ini'
                || (bool) preg_match('/\.(?:php\d*|phtml|phar)(?:\.|$)/i', $base)
            );
            if (!$skip_item && $watch) {
                if ($skip_count > 0) {
                    $skip_count--;
                    $seen++;
                } elseif ($seen < self::WP_CONTENT_OTHER_CAP) {
                    $seen++;
                    $samples[$this->rel($root, $file->getPathname())] = $this->sample_file($file->getPathname());
                }
            }
            $stop = $this->census_stop(
                $ctx,
                $skip_item,
                $resume_after,
                $path,
                $samples,
                'wp_content',
                $budget,
                ['tree_seen' => $seen]
            );
            if ($stop !== null) {
                return $stop;
            }
            if ($seen >= self::WP_CONTENT_OTHER_CAP && !$skip_item) {
                break;
            }
        }
        $miss = $this->cursor_missed_retry('wp_content', $skipping, $samples);
        if ($miss !== null) {
            return $miss;
        }
        if ($samples !== []) {
            $this->store->put_samples('wp_content', $samples, false);
        }
        $merged = $this->store->samples('wp_content');
        $unexpected = $this->store->diff_bucket('wp_content', $merged);
        $this->store->add_unexpected($unexpected);
        $this->store->state()->event('census:wp-content', (string) count($merged));
        return ['done' => true, 'next' => 'uploads', 'offset' => 0, 'count' => count($samples)];
    }

    private function phase_uploads(int $offset, array $payload = [], $ctx = null): array {
        $root = $this->site_root();
        $dir = $root . 'wp-content/uploads';
        if (!is_dir($dir)) {
            $this->store->state()->event('census:uploads', '0');
            return ['done' => true, 'next' => 'options', 'count' => 0];
        }
        $state = $this->store->state()->load();
        $all_media = !empty($state['include_all_media']);
        $resume_after = $this->norm_path((string) ($payload['resume_after'] ?? ''));
        $php_count = max(0, (int) ($payload['php_count'] ?? 0));
        $media_checked = max(0, (int) ($payload['media_checked'] ?? 0));
        $skip_count = ($resume_after === '') ? max(0, $offset) : 0;
        if ($resume_after !== '' && !file_exists($resume_after) && !is_link($resume_after)) {
            $resume_after = '';
        }
        $skipping = $resume_after !== '';
        $samples = [];
        $budget = 40;
        $media_cap = $all_media ? self::UPLOAD_ALL_MEDIA_CAP : self::UPLOAD_IMAGE_CAP;
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
        } catch (Throwable $e) {
            return ['done' => true, 'next' => 'options', 'count' => 0];
        }
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            $path = $this->norm_path($abs);
            $skip_item = false;
            if ($skipping) {
                if ($path === $resume_after) {
                    $skipping = false;
                }
                $skip_item = true;
            }
            $base = $file->getFilename();
            $ext = strtolower($file->getExtension());
            $is_php = in_array($ext, self::PHP_EXTS, true)
                || in_array($base, ['.htaccess', '.user.ini'], true)
                || (bool) preg_match('/\.(?:php\d*|phtml|phar)(?:\.|$)/i', $base);
            $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true);

            if (!$skip_item) {
                if ($skip_count > 0 && ($is_php || $is_image || $all_media)) {
                    $skip_count--;
                } elseif ($is_php && $php_count < self::UPLOAD_PHP_CAP) {
                    $php_count++;
                    $samples[$this->rel($root, $abs)] = $this->sample_file($abs) + [
                        'php_in_image' => false,
                    ];
                } elseif (!$is_php && ($all_media || $is_image) && $media_checked < $media_cap) {
                    $media_checked++;
                    $flag = $this->php_in_image($abs);
                    if ($all_media || $flag) {
                        $samples[$this->rel($root, $abs)] = $this->sample_file($abs) + [
                            'php_in_image' => $flag,
                        ];
                    }
                }
            }

            $stop = $this->census_stop(
                $ctx,
                $skip_item,
                $resume_after,
                $path,
                $samples,
                'uploads',
                $budget,
                [
                    'php_count' => $php_count,
                    'media_checked' => $media_checked,
                ]
            );
            if ($stop !== null) {
                return $stop;
            }
            if ($php_count >= self::UPLOAD_PHP_CAP) {
                break;
            }
        }
        $miss = $this->cursor_missed_retry('uploads', $skipping, $samples);
        if ($miss !== null) {
            return $miss;
        }
        if ($samples !== []) {
            $this->store->put_samples('uploads', $samples, false);
        }
        $merged = $this->store->samples('uploads');
        $unexpected = $this->store->diff_bucket('uploads', $merged);
        $this->store->add_unexpected($unexpected);
        $this->store->state()->event('census:uploads', (string) count($merged));
        return ['done' => true, 'next' => 'options', 'offset' => 0, 'count' => count($samples)];
    }

    private function phase_options(): array {
        $keys = ['siteurl', 'home', 'default_role', 'users_can_register', 'active_plugins', 'template', 'stylesheet'];
        $opts = [];
        foreach ($keys as $key) {
            if (function_exists('get_option')) {
                $val = get_option($key);
                $opts[$key] = is_scalar($val) ? (string) $val : md5(serialize($val));
            }
        }
        $this->store->put_options($opts);
        $this->store->state()->event('census:options', (string) count($opts));
        return ['done' => true, 'next' => null, 'count' => count($opts)];
    }

    /**
     * Find copies of a basename and/or hash under the site (not CS internals noise).
     *
     * @return array<int,array{path:string,hash:?string}>
     */
    public function find_elsewhere(string $basename, ?string $hash = null, int $limit = 40): array {
        $root = $this->site_root();
        $hits = [];
        $skip = [
            '/node_modules/',
            '/.git/',
            '/cache/',
            '/clean-sweep/logs/',
            '/clean-sweep/backups/',
            '/clean-sweep/core/fresh/',
            '/core/fresh/',
        ];
        if (!is_dir($root)) {
            return $hits;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        $base_l = strtolower($basename);
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $abs = str_replace('\\', '/', $file->getPathname());
            $ok = false;
            foreach ($skip as $s) {
                if (strpos($abs, $s) !== false) {
                    $ok = true;
                    break;
                }
            }
            if ($ok) {
                continue;
            }
            $name = strtolower($file->getFilename());
            $h = null;
            $name_match = ($name === $base_l);
            if (!$name_match && $hash === null) {
                continue;
            }
            if ($hash !== null) {
                $h = $this->caps->hash_path($abs);
                if (!$name_match && $h !== $hash) {
                    continue;
                }
            }
            if ($name_match || ($hash !== null && $h === $hash)) {
                $hits[] = [
                    'path' => $this->rel($root, $abs),
                    'hash' => $h ?? $this->caps->hash_path($abs),
                ];
            }
            if (count($hits) >= $limit) {
                break;
            }
        }
        return $hits;
    }

    private function ctx_cancelled($ctx): bool {
        return is_object($ctx) && method_exists($ctx, 'isCancelled') && $ctx->isCancelled();
    }

    private function ctx_slice($ctx): bool {
        return is_object($ctx) && method_exists($ctx, 'sliceExpired') && $ctx->sliceExpired();
    }

    private function norm_path(string $path): string {
        $path = str_replace('\\', '/', $path);
        if ($path !== '/' && $path !== '') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>|null
     */
    private function census_stop(
        $ctx,
        bool $skip_item,
        string $resume_after,
        string $path,
        array $samples,
        string $phase,
        int $budget,
        array $extra
    ): ?array {
        if ($this->ctx_cancelled($ctx)) {
            if ($samples !== []) {
                $this->store->put_samples($phase, $samples, false);
            }
            return ['cancelled' => true, 'done' => true, 'next' => null, 'count' => count($samples)];
        }
        $full = count($samples) >= $budget;
        $slice = $this->ctx_slice($ctx);
        if (!$full && !$slice) {
            return null;
        }
        if ($samples !== []) {
            $this->store->put_samples($phase, $samples, false);
        }
        // Always advance to the current path. Keeping the original resume_after
        // while skipping restarted the same tree every slice and never finished.
        return [
            'done' => false,
            'next' => $phase,
            'offset' => 0,
            'count' => count($samples),
            'phase' => $phase,
            'follow_on_payload' => array_merge($extra, [
                'phase' => $phase,
                'offset' => 0,
                'resume_after' => $path,
            ]),
        ];
    }

    /**
     * Resume cursor was not in this listing. Do not mark the bucket complete.
     *
     * @return array<string,mixed>|null
     */
    private function cursor_missed_retry(string $phase, bool $skipping, array $samples): ?array {
        if (!$skipping) {
            return null;
        }
        if ($samples !== []) {
            $this->store->put_samples($phase, $samples, false);
        }
        return [
            'done' => false,
            'next' => $phase,
            'offset' => 0,
            'count' => count($samples),
            'phase' => $phase,
            'follow_on_payload' => [
                'phase' => $phase,
                'offset' => 0,
                'resume_after' => '',
                'resume_slug' => '',
                'slug_php_seen' => 0,
                'tree_seen' => 0,
                'php_count' => 0,
                'media_checked' => 0,
            ],
        ];
    }

    /** Official packaged root PHP — not "random" extras. */
    private function official_root_php(): array {
        return [
            'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php',
            'wp-config.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php',
            'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php',
            'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php',
        ];
    }

    /** @return string[] absolute paths */
    private function list_site_owned_abs(string $root): array {
        $out = [];
        $names = [
            'wp-config.php', '.htaccess', '.user.ini', 'php.ini', 'web.config',
            'wp-content/db.php', 'wp-content/object-cache.php',
            'wp-content/advanced-cache.php', 'wp-content/sunrise.php',
        ];
        foreach ($names as $rel) {
            $abs = $root . $rel;
            if (is_file($abs) && is_readable($abs)) {
                $out[] = $abs;
            }
        }
        $mu = $root . 'wp-content/mu-plugins';
        if (is_dir($mu)) {
            $out = array_merge($out, $this->list_php($mu, 80));
        }
        return array_merge($out, $this->list_root_extra_php($root));
    }

    /** @return string[] */
    /** @param array<string,true> $skip_keys */
    private function list_plugin_theme_php(string $root, int $per_tree, array $skip_keys = []): array {
        $out = [];
        foreach (['wp-content/plugins' => 'plugin', 'wp-content/themes' => 'theme'] as $d => $type) {
            $base = $root . $d;
            if (!is_dir($base)) {
                continue;
            }
            $names = @scandir($base);
            if (!is_array($names)) {
                continue;
            }
            sort($names);
            foreach ($names as $slug) {
                if ($slug === '.' || $slug === '..' || $slug === 'index.php') {
                    continue;
                }
                $key = $type . ':' . $slug;
                if (isset($skip_keys[$key])) {
                    continue;
                }
                $dir = $base . '/' . $slug;
                if (is_dir($dir)) {
                    $out = array_merge($out, $this->list_php($dir, $per_tree));
                    continue;
                }
                if (is_file($dir) && in_array(strtolower(pathinfo($dir, PATHINFO_EXTENSION)), self::PHP_EXTS, true)) {
                    $out[] = $dir;
                }
            }
        }
        return $out;
    }

    /**
     * @param string[] $abs_list
     * @return array<string,array{hash:?string}>
     */
    private function hash_rels(array $abs_list, string $root): array {
        $out = [];
        foreach ($abs_list as $abs) {
            $rel = $this->rel($root, $abs);
            if ($rel === '') {
                continue;
            }
            $out[$rel] = ['hash' => $this->caps->hash_path($abs)];
        }
        return $out;
    }

    /**
     * PHP / configs first so images cannot crowd them out of the cap.
     *
     * @return array<string,array{hash:?string,php_in_image?:bool}>
     */
    private function hash_upload_watch(string $root, bool $all_media): array {
        $dir = $root . 'wp-content/uploads';
        $out = [];
        foreach ($this->list_upload_watch_abs($dir, $all_media) as $abs) {
            $rel = $this->rel($root, $abs);
            if ($rel === '') {
                continue;
            }
            $row = ['hash' => $this->caps->hash_path($abs)];
            if ($this->php_in_image($abs)) {
                $row['php_in_image'] = true;
            }
            $out[$rel] = $row;
        }
        return $out;
    }

    /**
     * Executables under wp-content that are not plugins/themes/uploads/mu-plugins.
     * Catches wp-content/evil.php, cache/, upgrade/, and other dropper dirs.
     *
     * @return string[]
     */
    private function list_wp_content_other(string $root, int $max): array {
        $dir = $root . 'wp-content';
        if (!is_dir($dir)) {
            return [];
        }
        $skip_dirs = [
            'plugins' => true,
            'themes' => true,
            'uploads' => true,
            'mu-plugins' => true,
            'node_modules' => true,
            '.git' => true,
            'clean-sweep' => true,
        ];
        $skip_files = [
            'db.php' => true,
            'object-cache.php' => true,
            'advanced-cache.php' => true,
            'sunrise.php' => true,
        ];
        $out = [];
        try {
            $inner = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
            $filtered = new RecursiveCallbackFilterIterator($inner, static function ($current) use ($skip_dirs) {
                if ($current->isDir()) {
                    return !isset($skip_dirs[strtolower($current->getFilename())]);
                }
                return true;
            });
            $iter = new RecursiveIteratorIterator($filtered);
        } catch (Throwable $e) {
            return [];
        }
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $base = $file->getFilename();
            if (isset($skip_files[strtolower($base)])) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            $watch = in_array($ext, self::PHP_EXTS, true)
                || $base === '.htaccess'
                || $base === '.user.ini'
                || (bool) preg_match('/\.(?:php\d*|phtml|phar)(?:\.|$)/i', $base);
            if (!$watch) {
                continue;
            }
            $out[] = $file->getPathname();
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /** Extra PHP sitting in the WordPress root (common dropper target). */
    private function list_root_extra_php(string $root): array {
        $out = [];
        $official = array_flip($this->official_root_php());
        foreach (['php', 'phtml', 'phar'] as $ext) {
            foreach (glob($root . '*.' . $ext) ?: [] as $abs) {
                $base = basename($abs);
                if (isset($official[$base]) || strtolower($base) === 'clean-sweep.php') {
                    continue;
                }
                $out[] = $abs;
            }
        }
        return $out;
    }

    /** @return string[] sorted, then capped */
    private function list_php(string $dir, int $max): array {
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
        if ($max > 0 && count($out) > $max) {
            $out = array_slice($out, 0, $max);
        }
        return $out;
    }

    /** @return string[] PHP/configs first, then a capped set of images. */
    private function list_upload_watch_abs(string $dir, bool $all_media): array {
        if (!is_dir($dir)) {
            return [];
        }
        $php = [];
        $media = [];
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $base = $file->getFilename();
            $ext = strtolower($file->getExtension());
            $is_php = in_array($ext, self::PHP_EXTS, true)
                || in_array($base, ['.htaccess', '.user.ini'], true)
                || (bool) preg_match('/\.(?:php\d*|phtml|phar)(?:\.|$)/i', $base);
            if ($is_php) {
                $php[] = $file->getPathname();
                if (count($php) >= self::UPLOAD_PHP_CAP) {
                    break;
                }
                continue;
            }
            $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true);
            if ($all_media || $is_image) {
                $media[] = $file->getPathname();
            }
        }
        $media_cap = $all_media ? self::UPLOAD_ALL_MEDIA_CAP : self::UPLOAD_IMAGE_CAP;
        if (!$all_media) {
            $kept = [];
            foreach (array_slice($media, 0, $media_cap) as $abs) {
                if ($this->php_in_image($abs)) {
                    $kept[] = $abs;
                }
            }
            $media = $kept;
        } else {
            $media = array_slice($media, 0, $media_cap);
        }
        return array_merge($php, $media);
    }

    private function php_in_image(string $abs): bool {
        if (!$this->caps->fopen || !is_readable($abs)) {
            return false;
        }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
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
        if (stripos($blob, '<?php') !== false || stripos($blob, '<?=') !== false) {
            return true;
        }
        return false;
    }

    private function inode(string $abs): ?int {
        $st = @stat($abs);
        if (!is_array($st) || !isset($st['ino'])) {
            return null;
        }
        return (int) $st['ino'];
    }

    private function site_root(): string {
        if ($this->root_override !== null) {
            return $this->root_override;
        }
        if (!function_exists('clean_sweep_detect_site_root')) {
            $f = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 2) . '/')
                . 'features/maintenance/core-reinstall.php';
            if (is_readable($f)) {
                require_once $f;
            }
        }
        if (function_exists('clean_sweep_detect_site_root')) {
            return clean_sweep_detect_site_root();
        }
        return defined('ABSPATH') ? rtrim(str_replace('\\', '/', ABSPATH), '/') . '/' : '/';
    }

    private function rel(string $root, string $abs): string {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $abs = str_replace('\\', '/', $abs);
        if (strpos($abs, $root) === 0) {
            return substr($abs, strlen($root));
        }
        return ltrim($abs, '/');
    }
}
