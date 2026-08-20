<?php
/**
 * Likely-fake / impersonating plugin and theme packages.
 *
 * Stacked evidence only. Does not use the Orphans list (those are
 * non-packages). Real premium/custom packages stay Custom.
 */
final class CleanSweep_PackageIdentity {

    /** @var string[] */
    private static $GENERIC_AUTHORS = [
        '',
        'wordpress',
        'wordpress.org',
        'developer tools',
        'developer',
        'wp developer',
        'admin',
        'plugin author',
        'theme author',
        'author',
        'plugins',
        'themes',
    ];

    /**
     * @param array $ctx {
     *   type: plugin|theme,
     *   slug: string,
     *   name: string,
     *   version: string,
     *   author: string,
     *   plugin_uri?: string,
     *   author_uri?: string,
     *   theme_uri?: string,
     *   dir: string,
     *   single_file?: ?string,
     *   org_info?: array|null,  // wordpress.org info payload (empty if slug missing)
     *   checksum_status?: ?string,
     *   checksum_outcome?: ?string,
     * }
     * @return array{kind:string,reasons:string[],signals:string[],org_name:?string,org_author:?string,org_version:?string}
     */
    public static function evaluate(array $ctx): array {
        $type = (($ctx['type'] ?? 'plugin') === 'theme') ? 'theme' : 'plugin';
        $slug = (string) ($ctx['slug'] ?? '');
        $name = (string) ($ctx['name'] ?? '');
        $version = (string) ($ctx['version'] ?? '');
        $author = self::normalize_author((string) ($ctx['author'] ?? ''));
        $uri = (string) ($ctx['plugin_uri'] ?? $ctx['theme_uri'] ?? '');
        $author_uri = (string) ($ctx['author_uri'] ?? '');
        $dir = (string) ($ctx['dir'] ?? '');
        $org = is_array($ctx['org_info'] ?? null) ? $ctx['org_info'] : [];
        $org_on_directory = !empty($org['version']);

        $tree = self::tree_stats($dir, $ctx['single_file'] ?? null);
        $empty = [
            'kind' => 'ok',
            'reasons' => [],
            'signals' => [],
            'org_name' => null,
            'org_author' => null,
            'org_version' => null,
        ];

        if ($slug === '' || $dir === '') {
            return $empty;
        }

        if ($org_on_directory) {
            return self::evaluate_impersonation($type, $slug, $name, $version, $author, $org, $tree, $ctx);
        }

        return self::evaluate_decoy($type, $name, $author, $uri, $author_uri, $tree);
    }

    /**
     * Replace all persisted hits of one type (plugin|theme). Empty $items clears that type.
     *
     * @param array<int,array> $items
     */
    public static function replace_type(string $type, array $items): void {
        $type = ($type === 'theme') ? 'theme' : 'plugin';
        $existing = self::load_latest();
        $kept = [];
        foreach ($existing['items'] ?? [] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $t = (($it['type'] ?? 'plugin') === 'theme') ? 'theme' : 'plugin';
            if ($t !== $type) {
                $kept[] = $it;
            }
        }
        foreach ($items as $it) {
            if (!is_array($it) || ($it['kind'] ?? 'ok') === 'ok') {
                continue;
            }
            $slug = (string) ($it['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $it['type'] = $type;
            $kept[] = $it;
        }
        self::write_latest($kept);
    }

    /**
     * Upsert one hit (scan checksums, one package at a time).
     *
     * @param array $item
     */
    public static function upsert(array $item): void {
        if (($item['kind'] ?? 'ok') === 'ok') {
            return;
        }
        $type = (($item['type'] ?? 'plugin') === 'theme') ? 'theme' : 'plugin';
        $slug = (string) ($item['slug'] ?? '');
        if ($slug === '') {
            return;
        }
        $item['type'] = $type;
        $existing = self::load_latest();
        $kept = [];
        $key = $type . ':' . $slug;
        foreach ($existing['items'] ?? [] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $k = ((($it['type'] ?? 'plugin') === 'theme') ? 'theme' : 'plugin')
                . ':' . (string) ($it['slug'] ?? '');
            if ($k === $key) {
                continue;
            }
            $kept[] = $it;
        }
        $kept[] = $item;
        self::write_latest($kept);
    }

    public static function forget(string $type, string $slug): void {
        $type = ($type === 'theme') ? 'theme' : 'plugin';
        $slug = (string) $slug;
        if ($slug === '') {
            return;
        }
        $key = $type . ':' . $slug;
        $existing = self::load_latest();
        $kept = [];
        foreach ($existing['items'] ?? [] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $k = ((($it['type'] ?? 'plugin') === 'theme') ? 'theme' : 'plugin')
                . ':' . (string) ($it['slug'] ?? '');
            if ($k === $key) {
                continue;
            }
            $kept[] = $it;
        }
        self::write_latest($kept);
    }

    /** @return array{updated_at:int,items:array} */
    public static function load_latest(): array {
        $file = self::path();
        if (!is_readable($file)) {
            return ['updated_at' => 0, 'items' => []];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) {
            return ['updated_at' => 0, 'items' => []];
        }
        $items = $data['items'] ?? [];
        return [
            'updated_at' => (int) ($data['updated_at'] ?? 0),
            'items' => is_array($items) ? $items : [],
        ];
    }

    /** @return array{count:int,items:array} */
    public static function summary(): array {
        $latest = self::load_latest();
        $items = [];
        foreach ($latest['items'] as $it) {
            if (!is_array($it) || ($it['kind'] ?? '') === 'ok') {
                continue;
            }
            $items[] = [
                'type' => $it['type'] ?? 'plugin',
                'slug' => $it['slug'] ?? '',
                'name' => $it['name'] ?? '',
                'kind' => $it['kind'] ?? '',
                'reasons' => $it['reasons'] ?? [],
            ];
        }
        return ['count' => count($items), 'items' => $items];
    }

    public static function claims_wordpress_org(string $uri): bool {
        $uri = trim($uri);
        if ($uri === '' || !preg_match('#^https?://#i', $uri)) {
            return false;
        }
        $host = strtolower((string) (parse_url($uri, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return false;
        }
        return $host === 'wordpress.org'
            || substr($host, -14) === '.wordpress.org';
    }

    /**
     * @return bool True when names are close enough to be the same product
     */
    public static function names_similar(string $a, string $b): bool {
        $ta = self::name_tokens($a);
        $tb = self::name_tokens($b);
        if ($ta === [] || $tb === []) {
            return strtolower(trim($a)) === strtolower(trim($b));
        }
        if ($ta === $tb) {
            return true;
        }
        $sa = implode(' ', $ta);
        $sb = implode(' ', $tb);
        if ($sa === $sb) {
            return true;
        }
        // Prefix / lite-premium: shorter token set is a subset of the longer
        $short = count($ta) <= count($tb) ? $ta : $tb;
        $long = count($ta) <= count($tb) ? $tb : $ta;
        $missing = array_diff($short, $long);
        if ($missing === [] && count($short) >= 1) {
            return true;
        }
        $inter = array_intersect($ta, $tb);
        $union = array_unique(array_merge($ta, $tb));
        $jaccard = $union === [] ? 0 : (count($inter) / count($union));
        return $jaccard >= 0.6;
    }

    /**
     * @param array $org
     * @param array $tree
     * @param array $ctx
     */
    private static function evaluate_impersonation(
        string $type,
        string $slug,
        string $name,
        string $version,
        string $author,
        array $org,
        array $tree,
        array $ctx
    ): array {
        $org_name = (string) ($org['name'] ?? '');
        $org_author = self::normalize_author((string) ($org['author'] ?? ''));
        $org_version = (string) ($org['version'] ?? '');

        $signals = [];
        $reasons = [];

        $name_known = $org_name !== '';
        $name_ok = $name_known ? self::names_similar($name, $org_name) : null;
        if ($name_ok === false) {
            $signals[] = 'name_mismatch';
            $reasons[] = 'Local name "' . $name . '" does not match WordPress.org "' . $org_name . '" for slug ' . $slug;
        }

        if ($org_author !== '' && $author !== '' && $org_author !== $author) {
            // Allow org author contained in local (or vice versa) for "Author (Company)"
            if (strpos($author, $org_author) === false && strpos($org_author, $author) === false) {
                $signals[] = 'author_mismatch';
                $reasons[] = 'Local author does not match the WordPress.org listing';
            }
        }

        $shape = !empty($tree['tiny']);
        $latest_differs = $org_version !== '' && $version !== '' && $version !== $org_version;
        $checksum_unavail = in_array((string) ($ctx['checksum_outcome'] ?? ''), ['unverifiable', 'skipped'], true)
            || (string) ($ctx['checksum_status'] ?? '') === 'unavailable';

        $catalog = false;
        if ($latest_differs && $checksum_unavail) {
            $catalog = true;
        } elseif ($latest_differs && $shape) {
            // Confirm unpublished local version via checksum map (one extra HTTP)
            if (class_exists('CleanSweep_PackageChecksums', false)
                && method_exists('CleanSweep_PackageChecksums', 'official_file_count')) {
                $n = CleanSweep_PackageChecksums::official_file_count($type, $slug, $version);
                if ($n === null) {
                    $catalog = true;
                }
            } else {
                $catalog = true;
            }
        }
        if ($catalog) {
            $signals[] = 'unpublished_version';
            $reasons[] = 'Installed version ' . $version . ' is not a published WordPress.org build (listing is ' . $org_version . ')';
        }

        if ($shape) {
            $official_n = null;
            if (class_exists('CleanSweep_PackageChecksums', false)
                && method_exists('CleanSweep_PackageChecksums', 'official_file_count')
                && $org_version !== '') {
                $official_n = CleanSweep_PackageChecksums::official_file_count($type, $slug, $org_version);
            }
            if (is_int($official_n) && $official_n >= 8) {
                $signals[] = 'tree_mismatch';
                $reasons[] = 'Local package is ' . (int) $tree['php_files'] . ' PHP file(s); WordPress.org listing has ' . $official_n . ' files';
            } elseif ($official_n === null) {
                $signals[] = 'tiny_tree';
                $reasons[] = 'Package is only ' . (int) $tree['php_files'] . ' PHP file(s) with no readme';
            }
        }

        // Related shape signals count as one
        $has_identity = in_array('name_mismatch', $signals, true) || in_array('author_mismatch', $signals, true);
        $has_shape = in_array('tree_mismatch', $signals, true) || in_array('tiny_tree', $signals, true);
        $has_catalog = in_array('unpublished_version', $signals, true);
        $score = (int) $has_identity + (int) $has_shape + (int) $has_catalog;

        // Name+author both match: require catalog AND official tree mismatch (hijack of headers).
        // If org name is unknown (stale cache), do not treat that as a match.
        if (
            $name_known
            && !$has_identity
            && $has_shape
            && $has_catalog
            && !in_array('tree_mismatch', $signals, true)
        ) {
            $score = 1; // tiny unpublished copy of a real 1-file plugin — do not flag
        }

        if ($score < 2) {
            return [
                'kind' => 'ok',
                'reasons' => [],
                'signals' => $signals,
                'org_name' => $org_name !== '' ? $org_name : null,
                'org_author' => $org_author !== '' ? $org_author : null,
                'org_version' => $org_version !== '' ? $org_version : null,
            ];
        }

        return [
            'kind' => 'impersonating',
            'reasons' => $reasons,
            'signals' => $signals,
            'org_name' => $org_name !== '' ? $org_name : null,
            'org_author' => $org_author !== '' ? $org_author : null,
            'org_version' => $org_version !== '' ? $org_version : null,
        ];
    }

    /**
     * @param array $tree
     */
    private static function evaluate_decoy(
        string $type,
        string $name,
        string $author,
        string $uri,
        string $author_uri,
        array $tree
    ): array {
        $spoof = self::claims_wordpress_org($uri) || self::claims_wordpress_org($author_uri);
        $generic = self::is_generic_author($author);
        $tiny = !empty($tree['tiny']);
        $no_readme = empty($tree['has_readme']);

        if (!($spoof && $tiny && $no_readme && $generic)) {
            return [
                'kind' => 'ok',
                'reasons' => [],
                'signals' => [],
                'org_name' => null,
                'org_author' => null,
                'org_version' => null,
            ];
        }

        $reasons = [];
        $reasons[] = 'Not listed on WordPress.org but Plugin/Author URI claims wordpress.org';
        $reasons[] = 'Single-file package with no readme (decoy shape)';
        $reasons[] = 'Generic author header';

        return [
            'kind' => 'decoy',
            'reasons' => $reasons,
            'signals' => ['org_uri_spoof', 'tiny_tree', 'no_readme', 'generic_author'],
            'org_name' => null,
            'org_author' => null,
            'org_version' => null,
        ];
    }

    /**
     * @return array{php_files:int,total_files:int,has_readme:bool,tiny:bool}
     */
    public static function tree_stats(string $dir, $single_file = null): array {
        $php = 0;
        $total = 0;
        $readme = false;
        $dir_n = rtrim(str_replace('\\', '/', $dir), '/');

        if ($single_file && is_file($dir_n . '/' . $single_file)) {
            $php = 1;
            $total = 1;
            return ['php_files' => 1, 'total_files' => 1, 'has_readme' => false, 'tiny' => true];
        }

        if (!is_dir($dir_n)) {
            return ['php_files' => 0, 'total_files' => 0, 'has_readme' => false, 'tiny' => false];
        }

        try {
            $it = new DirectoryIterator($dir_n);
            foreach ($it as $item) {
                if ($item->isDot()) {
                    continue;
                }
                $base = $item->getFilename();
                $low = strtolower($base);
                if ($item->isFile()) {
                    $total++;
                    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
                    if ($ext === 'php' || $ext === 'phtml') {
                        $php++;
                    }
                    if ($low === 'readme.txt' || $low === 'readme.md') {
                        $readme = true;
                    }
                } elseif ($item->isDir()) {
                    // Any extra directory means this is not a one-file decoy
                    $total += 2;
                }
                if ($total > 20) {
                    break;
                }
            }
        } catch (Throwable $e) {
            // unreadable
        }

        $tiny = $php <= 2 && $total <= 3 && !$readme;
        return [
            'php_files' => $php,
            'total_files' => $total,
            'has_readme' => $readme,
            'tiny' => $tiny,
        ];
    }

    public static function normalize_author(string $author): string {
        $author = html_entity_decode(strip_tags($author), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $author = strtolower(trim(preg_replace('/\s+/', ' ', $author) ?? ''));
        return $author;
    }

    public static function is_generic_author(string $author): bool {
        $a = self::normalize_author($author);
        return in_array($a, self::$GENERIC_AUTHORS, true);
    }

    /** @return string[] */
    public static function name_tokens(string $name): array {
        $name = strtolower(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';
        $stop = ['the', 'a', 'an', 'for', 'and', 'of', 'plugin', 'theme', 'wordpress', 'wp', 'by'];
        $out = [];
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $t) {
            if ($t === '' || in_array($t, $stop, true) || strlen($t) < 2) {
                continue;
            }
            $out[] = $t;
        }
        return array_values(array_unique($out));
    }

    /** @param array<int,array> $items */
    private static function write_latest(array $items): void {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, json_encode([
            'updated_at' => time(),
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function path(): string {
        $root = defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 3) . '/';
        return $root . 'backups/package_identity_latest.json';
    }
}
