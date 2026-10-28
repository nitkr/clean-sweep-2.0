<?php
/**
 * Likely-fake / impersonating plugin and theme packages.
 *
 * Stacked evidence only. Does not use the Orphans list (those are
 * non-packages). Real premium/custom packages stay Custom.
 *
 * A small plugin that is not a matching directory listing is Likely Fake
 * when its PHP hides the plugin, plants an mu-plugin, hides users, or saves
 * an option from a query string and exits. A hardcoded author or category id
 * alone is not enough. Hiding that slice and rewriting the admin counts is.
 * So is product structured data for that slice when another SEO plugin's
 * title or description is replaced.
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
     * Theme directory author is often a nicename (wordpressdotorg); style.css
     * says "the WordPress team". Same publisher, not a stolen identity.
     *
     * @var string[]
     */
    private static $WP_ORG_AUTHOR_ALIASES = [
        'wordpressdotorg',
        'wordpress.org',
        'wordpress',
        'the wordpress team',
        'automattic',
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
        // A closed listing has a name and no version. That name still identifies
        // the slug. A missing version is not an unpublished release.
        $org_on_directory = !empty($org['version'])
            || (!empty($org['closed']) && trim((string) ($org['name'] ?? '')) !== '');

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

        $decoy = self::evaluate_decoy($type, $name, $author, $uri, $author_uri, $tree);
        if (($decoy['kind'] ?? 'ok') !== 'ok' || $type !== 'plugin') {
            return $decoy;
        }

        $malware = self::evaluate_malware_plugin($dir, $ctx['single_file'] ?? null);
        if (($malware['kind'] ?? 'ok') !== 'ok') {
            return $malware;
        }

        return $decoy;
    }

    /**
     * A closed listing whose name still matches the installed header.
     * Plugin analysis must not score that copy as an unknown plugin.
     */
    public static function is_closed_name_match(array $org, string $local_name): bool {
        if (empty($org['closed']) || trim((string) ($org['name'] ?? '')) === '') {
            return false;
        }
        return self::names_similar($local_name, (string) $org['name']);
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

        if ($org_author !== '' && $author !== '' && !self::authors_match($author, $org_author)) {
            $signals[] = 'author_mismatch';
            $reasons[] = 'Local author does not match the WordPress.org listing';
        }

        $shape = !empty($tree['tiny']);
        $latest_differs = $org_version !== '' && $version !== '' && $version !== $org_version;
        $checksum_unavail = in_array((string) ($ctx['checksum_outcome'] ?? ''), ['unverifiable', 'skipped'], true)
            || (string) ($ctx['checksum_status'] ?? '') === 'unavailable';

        // Themes have no checksum map. "Older than the directory latest" is an
        // update, not an unpublished fake. Only plugins may use map 404 as catalog.
        $catalog = false;
        if ($type !== 'theme') {
            if ($latest_differs && $checksum_unavail) {
                $catalog = true;
            } elseif ($latest_differs && $shape) {
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
        }
        if ($catalog) {
            $signals[] = 'unpublished_version';
            $reasons[] = 'Installed version ' . $version . ' is not a published WordPress.org build (listing is ' . $org_version . ')';
        }

        if ($shape) {
            $official_n = null;
            if ($type !== 'theme'
                && class_exists('CleanSweep_PackageChecksums', false)
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

    /** True when local and directory authors are the same publisher. */
    public static function authors_match(string $a, string $b): bool {
        $a = self::normalize_author($a);
        $b = self::normalize_author($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if (strpos($a, $b) !== false || strpos($b, $a) !== false) {
            return true;
        }
        return in_array($a, self::$WP_ORG_AUTHOR_ALIASES, true)
            && in_array($b, self::$WP_ORG_AUTHOR_ALIASES, true);
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

    /**
     * Unknown plugin of at most 5 PHP files, no readme, and no vendor tree.
     * A hardcoded author or category id alone is not enough.
     *
     * @param mixed $single_file
     * @return array{kind:string,reasons:string[],signals:string[],org_name:?string,org_author:?string,org_version:?string}
     */
    private static function evaluate_malware_plugin(string $dir, $single_file): array {
        $empty = [
            'kind' => 'ok',
            'reasons' => [],
            'signals' => [],
            'org_name' => null,
            'org_author' => null,
            'org_version' => null,
        ];
        $sources = self::malware_sources($dir, $single_file);
        if ($sources === []) {
            return $empty;
        }

        $signals = [];
        $reasons = [];
        foreach ($sources as $code) {
            if (!isset($signals['hides_plugin_list']) && self::hides_plugin_list($code)) {
                $signals['hides_plugin_list'] = true;
                $reasons[] = 'Removes itself from the plugin list';
            }
            if (!isset($signals['mu_plugin_write']) && self::writes_mu_plugin($code)) {
                $signals['mu_plugin_write'] = true;
                $reasons[] = 'Copies itself into mu-plugins';
            }
            if (!isset($signals['hides_users']) && self::hides_users($code)) {
                $signals['hides_users'] = true;
                $reasons[] = 'Hides users from the user list';
            }
            if (!isset($signals['open_query_switch']) && self::open_query_switch($code)) {
                $signals['open_query_switch'] = true;
                $reasons[] = 'A query parameter writes an option and exits without a capability check';
            }
            if (!isset($signals['conceals_posts']) && self::conceals_posts($code)) {
                $signals['conceals_posts'] = true;
                $reasons[] = 'Hides posts for a hardcoded author or category and rewrites the admin counts';
            }
            if (!isset($signals['hidden_links']) && self::hides_link_list($code)) {
                $signals['hidden_links'] = true;
                $reasons[] = 'Prints a list of links in a box placed off screen';
            }
            if (!isset($signals['promotes_slice']) && self::promotes_slice($code)) {
                $signals['promotes_slice'] = true;
                $reasons[] = 'Prints product structured data for a hardcoded author or category and replaces an SEO plugin title or description';
            }
        }
        if ($signals === []) {
            return $empty;
        }

        return [
            'kind' => 'malware_plugin',
            'reasons' => $reasons,
            'signals' => array_keys($signals),
            'org_name' => null,
            'org_author' => null,
            'org_version' => null,
        ];
    }

    /**
     * PHP sources for a small unknown plugin. An empty result is not eligible:
     * a readme, a vendor directory, or a sixth PHP file.
     *
     * @param mixed $single_file
     * @return string[]
     */
    private static function malware_sources(string $dir, $single_file): array {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if (is_string($single_file) && $single_file !== '') {
            $path = $dir . '/' . $single_file;
            if (!is_file($path)) {
                return [];
            }
            $code = self::read_plugin_code($path);
            return $code === null ? [] : [$code];
        }
        if (!is_dir($dir)) {
            return [];
        }

        $sources = [];
        $php = 0;
        $stack = [$dir];
        while ($stack !== []) {
            $current = array_pop($stack);
            try {
                $it = new DirectoryIterator($current);
            } catch (Throwable $e) {
                return [];
            }
            foreach ($it as $item) {
                if ($item->isDot()) {
                    continue;
                }
                $low = strtolower($item->getFilename());
                if ($item->isDir()) {
                    if ($low === 'vendor' || $low === 'vendor_prefixed' || $low === 'node_modules') {
                        return [];
                    }
                    $stack[] = $item->getPathname();
                    continue;
                }
                if (!$item->isFile()) {
                    continue;
                }
                if ($low === 'readme.txt' || $low === 'readme.md') {
                    return [];
                }
                $ext = strtolower(pathinfo($low, PATHINFO_EXTENSION));
                if ($ext !== 'php' && $ext !== 'phtml') {
                    continue;
                }
                $php++;
                if ($php > 5) {
                    return [];
                }
                $code = self::read_plugin_code($item->getPathname());
                if ($code !== null) {
                    $sources[] = $code;
                }
            }
        }

        return $php >= 1 ? $sources : [];
    }

    /** @return string|null */
    private static function read_plugin_code(string $path): ?string {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== 'php' && $ext !== 'phtml') {
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size > 524288) {
            return null;
        }
        $code = @file_get_contents($path);
        return is_string($code) ? $code : null;
    }

    /**
     * The same numeric author or category id is excluded from queries and
     * subtracted from an admin count. One exclusion on its own does not match.
     */
    private static function conceals_posts(string $code): bool {
        $hidden = self::excluded_slice_ids($code);
        if ($hidden === []) {
            return false;
        }
        $counted = [];
        $bodies = self::hooked_bodies($code, 'wp_count_posts|views_edit-[A-Za-z0-9_-]+');
        foreach ($bodies as $body) {
            foreach (self::literal_slice_ids($body) as $id) {
                $counted[$id] = true;
            }
        }
        return array_intersect($hidden, array_keys($counted)) !== [];
    }

    /**
     * A generated list of links, not one skip link, inside a box placed
     * thousands of pixels off screen.
     */
    private static function hides_link_list(string $code): bool {
        if (!preg_match('/position\s*:\s*absolute[\s\S]{0,180}(?:left|top)\s*:\s*-/i', $code)) {
            return false;
        }
        if (!preg_match('/(?:left|top)\s*:\s*-[\s\S]{0,80}(?:\d{4,}|rand\s*\(\s*\d{3,})/i', $code)) {
            return false;
        }
        $offscreen_then_list = '/(?:left|top)\s*:\s*-[\s\S]{0,2500}(?:foreach|for|while)\s*\([\s\S]{0,800}<a\s+/i';
        $list_then_offscreen = '/(?:foreach|for|while)\s*\([\s\S]{0,800}<a\s+[\s\S]{0,2500}(?:left|top)\s*:\s*-/i';
        return (bool) preg_match($offscreen_then_list, $code) || (bool) preg_match($list_then_offscreen, $code);
    }

    /**
     * Product or offer JSON-LD for a hardcoded author or category, plus a
     * filter that replaces another SEO plugin's title or description.
     */
    private static function promotes_slice(string $code): bool {
        if (!preg_match('/ld\+json/i', $code)) {
            return false;
        }
        $offer = '/[\'"]@type[\'"]\s*=>\s*[\'"](?:Product|AggregateOffer|Offer)[\'"]/i';
        $offer_json = '/"@type"\s*:\s*"(?:Product|AggregateOffer|Offer)"/i';
        if (!preg_match($offer, $code) && !preg_match($offer_json, $code)) {
            return false;
        }
        if (self::literal_slice_ids($code) === []) {
            return false;
        }
        $hooks = [
            'wpseo_title',
            'wpseo_metadesc',
            'wpseo_opengraph_desc',
            'wpseo_opengraph_title',
            'rank_math/frontend/title',
            'rank_math/frontend/description',
            'aioseo_title',
            'aioseo_description',
            'seopress_titles_title',
            'seopress_titles_desc',
        ];
        foreach ($hooks as $hook) {
            $quoted = preg_quote($hook, '/');
            if (preg_match('/add_filter\s*\(\s*[\'"]' . $quoted . '[\'"]/', $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ids removed from a query. A selected author, such as 'author' => 12, is
     * not an exclusion.
     *
     * @return int[]
     */
    private static function excluded_slice_ids(string $code): array {
        $ids = self::literal_slice_ids_matching($code, [
            '/author__not_in[\'"]\s*,\s*(?:array\s*)?\(?\s*\[?\s*(\d+)/i',
            '/author__not_in[\'"]\]\s*=\s*(?:array\s*)?\(?\s*\[?\s*(\d+)/i',
            '/post_author\s*(?:!==|!=|<>)\s*(\d+)/i',
            '/post_author\s*(?:!==|!=|<>)\s*%d[\'"]\s*,\s*(\d+)/i',
            '/category__not_in[\'"]\s*,\s*(?:array\s*)?\(?\s*\[?\s*(\d+)/i',
            '/category__not_in[\'"]\]\s*=\s*(?:array\s*)?\(?\s*\[?\s*(\d+)/i',
        ]);
        if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(\d+)\s*;/', $code, $assigns, PREG_SET_ORDER)) {
            foreach ($assigns as $assign) {
                $n = (int) $assign[2];
                if ($n <= 0) {
                    continue;
                }
                $name = preg_quote($assign[1], '/');
                if (preg_match('/author__not_in[\'"]\s*,\s*(?:array\s*)?\(?\s*\[?\s*\$' . $name . '\b/', $code)
                    || preg_match('/post_author\s*(?:!==|!=|<>)[\s\S]{0,80}\$' . $name . '\b/', $code)) {
                    $ids[$n] = true;
                }
            }
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * Bodies of callbacks registered on a hook. Closures and named functions.
     *
     * @return string[]
     */
    private static function hooked_bodies(string $code, string $hookPattern): array {
        $named = [];
        foreach (self::named_function_bodies($code) as $fn) {
            $named[$fn['name']] = $fn['body'];
        }
        $bodies = [];
        $pattern = '/add_(?:filter|action)\s*\(\s*[\'"](?:' . $hookPattern . ')[\'"]\s*,\s*/i';
        if (!preg_match_all($pattern, $code, $found, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($found[0] as $hit) {
            $rest = substr($code, $hit[1] + strlen($hit[0]), 600);
            if (preg_match('/^[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $rest, $name) && isset($named[$name[1]])) {
                $bodies[] = $named[$name[1]];
                continue;
            }
            if (preg_match('/^function\s*\([^;{]*\)\s*\{/', $rest, $closure, PREG_OFFSET_CAPTURE)) {
                $open = $hit[1] + strlen($hit[0]) + $closure[0][1] + strlen($closure[0][0]) - 1;
                $body = self::brace_body($code, $open);
                if ($body !== null) {
                    $bodies[] = $body;
                }
            }
        }
        return $bodies;
    }

    /**
     * Author or category ids written as literals. Zero is the WordPress
     * "no author" value and is ignored.
     *
     * @return int[]
     */
    private static function literal_slice_ids(string $snippet): array {
        $ids = self::literal_slice_ids_matching($snippet, [
            '/author__not_in[\'"]\s*,\s*(?:array\s*)?\(?\s*\[?\s*(\d+)/i',
            '/author__not_in[\'"]\]\s*=\s*(?:array\s*)?\(?\s*\[?\s*(\d+)/i',
            '/post_author\s*(?:!==|===|!=|<>|==|=)\s*(\d+)/i',
            '/post_author\s*(?:!==|===|!=|<>|==|=)\s*%d[\'"]\s*,\s*(\d+)/i',
            '/[\'"]author[\'"]\s*=>\s*(\d+)/i',
            '/[\'"]cat(?:egory)?[\'"]\s*=>\s*(\d+)/i',
            '/category__in[\'"]\s*,\s*(?:array\s*)?\(?\s*\[?\s*(\d+)/i',
            '/category__in[\'"]\]\s*=\s*(?:array\s*)?\(?\s*\[?\s*(\d+)/i',
        ]);
        if (preg_match('/category|term_id/i', $snippet)) {
            $ids = self::literal_slice_ids_matching($snippet, ['/in_array\s*\(\s*(\d+)\s*,/i']) + $ids;
        }
        if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(\d+)\s*;/', $snippet, $assigns, PREG_SET_ORDER)) {
            foreach ($assigns as $assign) {
                $n = (int) $assign[2];
                if ($n <= 0) {
                    continue;
                }
                $name = preg_quote($assign[1], '/');
                if (preg_match('/(?:post_author|author__not_in|category)[\s\S]{0,2000}\$?\{?' . $name . '\b/', $snippet)
                    || preg_match('/\$\{?' . $name . '\b[\s\S]{0,2000}(?:post_author|author__not_in|category)/', $snippet)) {
                    $ids[$n] = true;
                }
            }
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * @param string[] $patterns
     * @return array<int,bool>
     */
    private static function literal_slice_ids_matching(string $snippet, array $patterns): array {
        $ids = [];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $snippet, $found)) {
                continue;
            }
            foreach ($found[1] as $id) {
                $n = (int) $id;
                if ($n > 0) {
                    $ids[$n] = true;
                }
            }
        }
        return $ids;
    }

    private static function hides_plugin_list(string $code): bool {
        return (bool) preg_match("/add_filter\\s*\\(\\s*['\\\"]all_plugins['\\\"]/i", $code)
            && preg_match('/unset\\s*\\(\\s*\\$[A-Za-z_][A-Za-z0-9_]*\\s*\\[/', $code);
    }

    private static function writes_mu_plugin(string $code): bool {
        if (preg_match('/\\b(copy|file_put_contents|fwrite)\\s*\\([^;]{0,400}(WPMU_PLUGIN_DIR|mu-plugins)/s', $code)) {
            return true;
        }
        if (!preg_match_all('/\\$([A-Za-z_][A-Za-z0-9_]*)\\s*=\\s*[^;]{0,300}(WPMU_PLUGIN_DIR|mu-plugins)/', $code, $found)) {
            return false;
        }
        foreach (array_unique($found[1]) as $var) {
            $quoted = preg_quote($var, '/');
            if (preg_match('/\\b(copy|file_put_contents|fwrite)\\s*\\([^;]{0,400}\\$' . $quoted . '\\b/s', $code)) {
                return true;
            }
        }
        return false;
    }

    private static function hides_users(string $code): bool {
        return (bool) preg_match("/['\\\"]pre_user_query['\\\"]/", $code)
            && preg_match('/query_where/', $code)
            && preg_match('/NOT\\s+IN|\\.ID\\s*!=|ID\\s*!=/i', $code);
    }

    private static function open_query_switch(string $code): bool {
        $functions = self::named_function_bodies($code);
        $by_name = [];
        foreach ($functions as $fn) {
            $by_name[$fn['name']] = $fn['body'];
        }
        foreach ($functions as $fn) {
            $body = $fn['body'];
            if (!preg_match('/\\$_(GET|REQUEST)\\b/', $body)) {
                continue;
            }
            if (!preg_match('/\\b(?:exit|die)\\s*(?:\\(|;)/', $body)) {
                continue;
            }
            if (preg_match('/\\b(?:current_user_can|check_admin_referer|wp_verify_nonce|check_ajax_referer)\\s*\\(/', $body)) {
                continue;
            }
            if (self::body_writes_option($body, $by_name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,string> $by_name
     */
    private static function body_writes_option(string $body, array $by_name): bool {
        if (preg_match('/\\bupdate_option\\s*\\(/', $body)) {
            return true;
        }
        if (!preg_match_all('/\\b([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/', $body, $calls)) {
            return false;
        }
        foreach ($calls[1] as $callee) {
            if (!isset($by_name[$callee])) {
                continue;
            }
            if (preg_match('/\\bupdate_option\\s*\\(/', $by_name[$callee])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int,array{name:string,body:string}>
     */
    private static function named_function_bodies(string $code): array {
        $out = [];
        $pattern = '/function\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*\\([^;{]*\\)\\s*(?::\\s*[^;{]+)?\\{/';
        if (!preg_match_all($pattern, $code, $found, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($found[0] as $i => $hit) {
            $open = $hit[1] + strlen($hit[0]) - 1;
            $body = self::brace_body($code, $open);
            if ($body === null) {
                continue;
            }
            $out[] = [
                'name' => $found[1][$i][0],
                'body' => $body,
            ];
        }
        return $out;
    }

    private static function brace_body(string $code, int $open): ?string {
        $length = strlen($code);
        $depth = 0;
        for ($i = $open; $i < $length; $i++) {
            $ch = $code[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open + 1, $i - $open - 1);
                }
            }
        }
        return null;
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
