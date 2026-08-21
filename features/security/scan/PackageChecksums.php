<?php
/**
 * WordPress.org plugin file checksums (themes have no published map).
 *
 * Soft-fails for premium/custom packages (HTTP 404).
 * Reporting: Type A (mapped outliers), Type B (extras rollup/top-K),
 * Type C (bulk divergence → one package finding). See plans/checksum-integrity-results-improvement.md.
 *
 * Themes: wordpress.org publishes plugin-checksums and core checksums only.
 * Do not GET theme-checksums/*.json — that URL 404s for every slug and is not
 * an API. Identity / zip baseline still run for themes. If a theme map ships,
 * add THEME_URL and use it from fetch_map() the same way as PLUGIN_URL.
 */
require_once __DIR__ . '/SitePaths.php';
require_once __DIR__ . '/PackageAnnotations.php';
require_once __DIR__ . '/PackageVerificationBaseline.php';

final class CleanSweep_PackageChecksums {

    private const PLUGIN_URL = 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json';
    // Holder: wordpress.org does not publish this. Do not request it.
    // private const THEME_URL = 'https://downloads.wordpress.org/theme-checksums/%s/%s.json';
    private const CACHE_TTL = 604800;

    /** Below this covered count, never use match_rate / A_RATE for Type C. */
    private const MIN_COVERED_FOR_RATE = 20;
    /** Absolute mapped mismatches → Type C when covered is large enough. */
    private const A_ABS = 40;
    private const A_RATE = 0.15;
    private const MATCH_MIN = 0.90;
    /** Max Type A paths buffered / emitted. */
    private const A_MAX = 40;
    /** Extra PHP count → single rollup (+ optional top-K). */
    private const B_ROLLUP = 25;
    private const B_TOPK = 10;
    /** Soft max size for typical *.asset.php build companions. */
    private const ASSET_SOFT_MAX = 4096;

    /**
     * Every installed plugin/theme. $active_only is kept for callers; Quick,
     * Standard, and Deep all pass false so skip covers inactive .org packages.
     *
     * @return array<int,array{type:string,slug:string,version:string,dir:string,name:string,active:bool}>
     */
    public static function list_targets(bool $active_only = false): array {
        $out = [];
        $root = CleanSweep_SitePaths::root();
        $content = CleanSweep_SitePaths::content_dir();
        if (!$content) {
            return $out;
        }

        if (function_exists('get_plugins')) {
            $active = function_exists('get_option') ? (array) get_option('active_plugins', []) : [];
            $sitewide = function_exists('get_site_option')
                ? (array) get_site_option('active_sitewide_plugins', [])
                : [];
            foreach (get_plugins() as $file => $data) {
                $is_active = in_array($file, $active, true) || isset($sitewide[$file]);
                if ($active_only && !$is_active) {
                    continue;
                }
                $slug = dirname($file);
                $single = null;
                if ($slug === '.' || $slug === '') {
                    $slug = basename($file, '.php');
                    $single = basename($file);
                    $dir = $content . 'plugins';
                } else {
                    $dir = $content . 'plugins/' . $slug;
                    if (!is_dir($dir) && is_file($content . 'plugins/' . basename($file))) {
                        $single = basename($file);
                        $dir = $content . 'plugins';
                    }
                }
                $out[] = [
                    'type' => 'plugin',
                    'slug' => $slug,
                    'version' => (string) ($data['Version'] ?? ''),
                    'dir' => $dir,
                    'name' => (string) ($data['Name'] ?? $slug),
                    'active' => $is_active,
                    'single_file' => $single,
                    'update_uri' => (string) ($data['UpdateURI'] ?? ''),
                    'plugin_uri' => (string) ($data['PluginURI'] ?? ''),
                    'author_uri' => (string) ($data['AuthorURI'] ?? ''),
                    'author' => (string) ($data['Author'] ?? ''),
                ];
            }
        }

        if (function_exists('wp_get_themes')) {
            $current = function_exists('get_stylesheet') ? (string) get_stylesheet() : '';
            foreach (wp_get_themes() as $slug => $theme) {
                $is_active = ($slug === $current);
                if ($active_only && !$is_active) {
                    continue;
                }
                $ver = method_exists($theme, 'get') ? (string) $theme->get('Version') : '';
                $name = method_exists($theme, 'get') ? (string) $theme->get('Name') : (string) $slug;
                $dir = $content . 'themes/' . $slug;
                $out[] = [
                    'type' => 'theme',
                    'slug' => (string) $slug,
                    'version' => $ver,
                    'dir' => $dir,
                    'name' => $name !== '' ? $name : (string) $slug,
                    'active' => $is_active,
                    'theme_uri' => method_exists($theme, 'get') ? (string) $theme->get('ThemeURI') : '',
                    'author_uri' => method_exists($theme, 'get') ? (string) $theme->get('AuthorURI') : '',
                    'author' => method_exists($theme, 'get') ? (string) $theme->get('Author') : '',
                ];
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   status:string,
     *   outcome:string,
     *   checked:int,
     *   findings:array,
     *   note:?string,
     *   official_ok:array,
     *   dir:string,
     *   metrics:array
     * }
     *   status: match | modified | unavailable | skipped
     *   outcome: verified | outliers | divergent | unverifiable | skipped
     */
    public static function check_package(array $pkg): array {
        $slug = $pkg['slug'] ?? '';
        $version = $pkg['version'] ?? '';
        $type = $pkg['type'] ?? 'plugin';
        $dir = $pkg['dir'] ?? '';
        $single = $pkg['single_file'] ?? null;
        $empty = [
            'status' => 'skipped',
            'outcome' => 'skipped',
            'checked' => 0,
            'findings' => [],
            'note' => 'missing slug/version/dir',
            'official_ok' => [],
            'dir' => '',
            'metrics' => self::empty_metrics(),
        ];
        if ($slug === '' || $version === '' || !is_dir($dir)) {
            return $empty;
        }

        $dir_n = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        $baseline = CleanSweep_PackageVerificationBaseline::get($type, $slug);

        // Themes: no wordpress.org file-checksum API. Never HTTP a 404 endpoint.
        $map = ($type === 'theme') ? null : self::fetch_map($type, $slug, $version);

        // No wordpress.org map: use verification baseline when present (Pro / premium).
        if ($map === null) {
            if (is_array($baseline)) {
                $br = CleanSweep_PackageVerificationBaseline::compare($pkg, $baseline);
                if (is_array($br)) {
                    return self::finalize_result($br, $pkg, $dir_n);
                }
            }
            $ann = CleanSweep_PackageAnnotations::for_package($pkg);
            $note = ($type === 'theme')
                ? 'wordpress.org does not publish theme file checksums'
                : 'not listed on wordpress.org';
            if ($ann !== []) {
                $note .= ' [' . implode('; ', $ann) . ']';
            }
            if ($type !== 'theme' || $ann !== []) {
                $note .= ' Upload a trusted zip via Clean Sweep to create a verification baseline.';
            }
            $identity = self::identity_finding($pkg, $dir_n);
            $findings = [];
            if ($identity !== null) {
                $findings[] = $identity['finding'];
                $note = $identity['note'];
            }
            return [
                'status' => 'unavailable',
                'outcome' => $identity ? 'identity' : 'unverifiable',
                'checked' => 0,
                'findings' => $findings,
                'note' => $note,
                'official_ok' => [],
                'dir' => $dir_n,
                'metrics' => self::empty_metrics(),
                'annotations' => $ann,
                'identity_kind' => $identity['kind'] ?? null,
            ];
        }

        $covered_ok = 0;
        $covered_bad = 0;
        $skipped = 0;
        $extra_php = 0;
        $official_ok = [];
        $bad_paths = []; // list of [full, rel]
        $top_k = []; // list of [score, full, rel]

        foreach ($map as $rel => $hash) {
            $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
            if ($rel === '') {
                continue;
            }
            $full = $dir_n . $rel;
            if (!is_file($full) || is_link($full)) {
                continue;
            }
            if (!is_readable($full)) {
                $skipped++;
                continue;
            }
            $md5 = @md5_file($full);
            if (!is_string($md5) || $md5 === '') {
                $skipped++;
                continue;
            }
            $ok = false;
            foreach ((array) $hash as $h) {
                if (is_string($h) && $h !== '' && strcasecmp($md5, $h) === 0) {
                    $ok = true;
                    break;
                }
            }
            if ($ok) {
                $covered_ok++;
                $official_ok[$rel] = true;
            } else {
                $covered_bad++;
                if (count($bad_paths) < self::A_MAX) {
                    $bad_paths[] = [$full, $rel];
                }
            }
        }

        try {
            if (!(is_string($single) && $single !== '')) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $item) {
                    if ($item->isLink() || !$item->isFile()) {
                        continue;
                    }
                    $full = str_replace('\\', '/', $item->getPathname());
                    $rel = ltrim(substr($full, strlen(rtrim($dir_n, '/'))), '/');
                    if ($rel === '' || isset($map[$rel])) {
                        continue;
                    }
                    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar'], true)) {
                        continue;
                    }
                    if (!is_readable($full)) {
                        $skipped++;
                        continue;
                    }
                    $extra_php++;
                    $size = (int) @filesize($full);
                    $score = self::cheap_score($rel, $size);
                    self::top_k_push($top_k, $score, $full, $rel, self::B_TOPK);
                }
            }
        } catch (Throwable $e) {
            // ignore walk errors
        }

        $covered = $covered_ok + $covered_bad;
        $match_rate = $covered > 0 ? ($covered_ok / $covered) : 1.0;
        $metrics = [
            'covered_ok' => $covered_ok,
            'covered_bad' => $covered_bad,
            'extra_php' => $extra_php,
            'match_rate' => round($match_rate, 4),
            'skipped_unreadable' => $skipped,
        ];
        $checked = $covered + $extra_php;

        // Type C only when sample is large enough for rates / absolute bulk.
        $type_c = false;
        if ($covered >= self::MIN_COVERED_FOR_RATE) {
            $type_c = (
                $covered_bad >= self::A_ABS
                || $covered_bad >= (int) ceil(self::A_RATE * $covered)
                || $match_rate < self::MATCH_MIN
            );
        }

        $findings = [];
        $name = (string) ($pkg['name'] ?? $pkg['slug'] ?? $slug);

        // Type C (bulk vs free .org): prefer verification baseline when available.
        if ($type_c) {
            if (is_array($baseline)) {
                $br = CleanSweep_PackageVerificationBaseline::compare($pkg, $baseline);
                if (is_array($br)) {
                    // Keep .org official_ok matches for signature-skip of free-overlapping files.
                    $br_ok = $br['official_ok'] ?? [];
                    if (!is_array($br_ok)) {
                        $br_ok = [];
                    }
                    $br['official_ok'] = array_values(array_unique(array_merge(
                        array_keys($official_ok),
                        $br_ok
                    )));
                    $br['checked'] = max((int) ($br['checked'] ?? 0), $checked);
                    return self::finalize_result($br, $pkg, $dir_n);
                }
            }
            $pct = (int) round($match_rate * 100);
            $findings[] = self::package_finding(
                $pkg,
                $dir_n,
                'package_divergent',
                sprintf(
                    'Installed tree does not match wordpress.org free package %s %s (bulk divergence: %d/%d mapped files differ, ~%d%% match). Per-file .org integrity suppressed; content scan still applies. Upload a trusted zip via Clean Sweep to create a verification baseline.',
                    $slug,
                    $version,
                    $covered_bad,
                    $covered,
                    $pct
                ),
                'medium',
                50,
                $metrics
            );
            return self::finalize_result([
                'status' => 'modified',
                'outcome' => 'divergent',
                'checked' => $checked,
                'findings' => $findings,
                'official_ok' => array_keys($official_ok),
                'note' => sprintf('bulk divergence from wordpress.org (%d mapped mismatches)', $covered_bad),
                'metrics' => $metrics,
            ], $pkg, $dir_n);
        }

        foreach ($bad_paths as [$full, $rel]) {
            $findings[] = self::finding(
                $full,
                $rel,
                $pkg,
                'checksum_mismatch',
                'does not match the wordpress.org package',
                'high',
                75
            );
        }

        if ($extra_php >= self::B_ROLLUP) {
            $findings[] = self::package_finding(
                $pkg,
                $dir_n,
                'package_extras_rollup',
                sprintf(
                    '%s: %d PHP file(s) not in the official wordpress.org %s package (rolled up). Content scan still applies to extras.',
                    $name,
                    $extra_php,
                    $version
                ),
                'info',
                25,
                $metrics
            );
            foreach ($top_k as $row) {
                $findings[] = self::finding(
                    $row['full'],
                    $row['rel'],
                    $pkg,
                    'unexpected_package_php',
                    'PHP file is not in the official wordpress.org package',
                    'medium',
                    55
                );
            }
        } elseif ($extra_php > 0) {
            foreach ($top_k as $row) {
                $findings[] = self::finding(
                    $row['full'],
                    $row['rel'],
                    $pkg,
                    'unexpected_package_php',
                    'PHP file is not in the official wordpress.org package',
                    'medium',
                    55
                );
            }
            if ($extra_php > count($top_k)) {
                $findings[] = self::package_finding(
                    $pkg,
                    $dir_n,
                    'package_extras_rollup',
                    sprintf(
                        '%s: %d additional unexpected PHP path(s) not listed individually.',
                        $name,
                        $extra_php - count($top_k)
                    ),
                    'info',
                    25,
                    $metrics
                );
            }
        }

        $status = empty($findings) ? 'match' : 'modified';
        $outcome = empty($findings) ? 'verified' : 'outliers';

        return self::finalize_result([
            'status' => $status,
            'outcome' => $outcome,
            'checked' => $checked,
            'findings' => $findings,
            'official_ok' => array_keys($official_ok),
            'note' => null,
            'metrics' => $metrics,
        ], $pkg, $dir_n);
    }

    /**
     * Attach dir, Phase 5 annotations, normalize shape.
     *
     * @param array $result
     * @param array $pkg
     */
    private static function finalize_result(array $result, array $pkg, string $dir_n): array {
        $result['dir'] = $result['dir'] ?? $dir_n;
        $findings = $result['findings'] ?? [];
        if (!is_array($findings)) {
            $findings = [];
        }
        $findings = CleanSweep_PackageAnnotations::apply_to_findings($findings, $pkg);
        $result['findings'] = $findings;
        $ann = CleanSweep_PackageAnnotations::for_package($pkg);
        if ($ann !== []) {
            $result['annotations'] = $ann;
            $note = (string) ($result['note'] ?? '');
            if ($note === '') {
                $result['note'] = implode('; ', $ann);
            } elseif (strpos($note, $ann[0]) === false) {
                $result['note'] = $note . ' [' . implode('; ', $ann) . ']';
            }
        }
        if (!isset($result['metrics']) || !is_array($result['metrics'])) {
            $result['metrics'] = self::empty_metrics();
        }
        if (!isset($result['official_ok']) || !is_array($result['official_ok'])) {
            $result['official_ok'] = [];
        }
        return $result;
    }

    /**
     * @return array{covered_ok:int,covered_bad:int,extra_php:int,match_rate:float,skipped_unreadable:int}
     */
    private static function empty_metrics(): array {
        return [
            'covered_ok' => 0,
            'covered_bad' => 0,
            'extra_php' => 0,
            'match_rate' => 1.0,
            'skipped_unreadable' => 0,
        ];
    }

    /**
     * Cheap Type B ranking: size + path only (no mtime primary).
     */
    private static function cheap_score(string $rel, int $size): int {
        $rel = str_replace('\\', '/', strtolower($rel));
        $base = basename($rel);
        $score = 0;

        if (preg_match('#/(images?|img|cache|tmp|temp|uploads?)/#', '/' . $rel . '/')) {
            $score += 80;
        }
        if (strpos($rel, '/') === false) {
            $score += 50; // package-root unexpected PHP
        }
        if (preg_match('/\.(php|phtml)\.[a-z0-9]+$/i', $base) || preg_match('/\.(jpg|png|gif|css|js)\.php$/i', $base)) {
            $score += 70;
        }
        if (preg_match('/^(shell|c99|r57|wso|b374k|cmd|hack|x|xx|xxx|boo)\.php$/i', $base)) {
            $score += 100;
        }

        if (substr($rel, -10) === '.asset.php' || substr($base, -10) === '.asset.php') {
            if ($size > self::ASSET_SOFT_MAX) {
                $score += 40 + min(40, (int) ($size / 1024));
            } else {
                $score += 5;
            }
        } else {
            if ($size > 50 * 1024) {
                $score += 35;
            } elseif ($size > 15 * 1024) {
                $score += 20;
            } elseif ($size > 0 && $size < 80) {
                $score += 15; // tiny stub
            }
        }

        return $score;
    }

    /**
     * @param array<int,array{score:int,full:string,rel:string}> $top_k
     */
    private static function top_k_push(array &$top_k, int $score, string $full, string $rel, int $k): void {
        $top_k[] = ['score' => $score, 'full' => $full, 'rel' => $rel];
        usort($top_k, static function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        if (count($top_k) > $k) {
            $top_k = array_slice($top_k, 0, $k);
        }
    }

    /**
     * True when this path is an official package file that already matched
     * wordpress.org. Extra / rogue / third-party files return false.
     */
    public static function should_skip_signature_scan(string $path): bool {
        $n = str_replace('\\', '/', $path);
        foreach (self::verified_index() as $dir => $ok) {
            if ($dir === '' || strpos($n, $dir) !== 0) {
                continue;
            }
            $rel = ltrim(substr($n, strlen($dir)), '/');
            if ($rel !== '' && isset($ok[$rel])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string,array<string,true>> dir prefix => rel => true
     */
    private static function verified_index(): array {
        static $index = null;
        static $mtime = 0;
        $file = self::latest_status_path();
        $now = is_readable($file) ? (int) @filemtime($file) : 0;
        if ($index !== null && $now === $mtime) {
            return $index;
        }
        $mtime = $now;
        $index = [];
        foreach (self::load_latest() as $row) {
            $dir = rtrim(str_replace('\\', '/', (string) ($row['dir'] ?? '')), '/') . '/';
            $ok = $row['official_ok'] ?? [];
            if ($dir === '/' || !is_array($ok) || $ok === []) {
                continue;
            }
            $set = [];
            foreach ($ok as $rel) {
                $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
                if ($rel !== '') {
                    $set[$rel] = true;
                }
            }
            if ($set !== []) {
                $index[$dir] = $set;
            }
        }
        return $index;
    }

    public static function latest_status_path(): string {
        $dir = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'backups/'
            : dirname(__DIR__, 2) . '/backups/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . 'package_checksums_latest.json';
    }

    /**
     * @param array<string,array> $rows slug => status row
     */
    public static function save_latest(array $rows): void {
        @file_put_contents(self::latest_status_path(), json_encode([
            'updated_at' => time(),
            'packages' => $rows,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{updated_at:int,packages:array<string,array>}
     */
    public static function load_latest_meta(): array {
        $file = self::latest_status_path();
        if (!is_readable($file)) {
            return ['updated_at' => 0, 'packages' => []];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) {
            return ['updated_at' => 0, 'packages' => []];
        }
        return [
            'updated_at' => (int) ($data['updated_at'] ?? @filemtime($file) ?: 0),
            'packages' => is_array($data['packages'] ?? null) ? $data['packages'] : [],
        ];
    }

    /**
     * @return array<string,array>
     */
    public static function load_latest(): array {
        return self::load_latest_meta()['packages'];
    }

    /**
     * True when package_checksums_latest.json is fresh and covers every
     * installed target at the same version — safe to skip re-hashing.
     *
     * @param array<int,array> $targets from list_targets()
     * @param int $ttl_seconds Max age of the latest status file
     */
    public static function can_reuse_latest(array $targets, int $ttl_seconds = 21600): bool {
        if ($ttl_seconds <= 0) {
            return false;
        }
        $meta = self::load_latest_meta();
        $updated = (int) $meta['updated_at'];
        if ($updated <= 0 || (time() - $updated) > $ttl_seconds) {
            return false;
        }
        $packages = $meta['packages'];
        if ($packages === []) {
            return false;
        }
        foreach ($targets as $pkg) {
            $type = (string) ($pkg['type'] ?? 'plugin');
            $slug = (string) ($pkg['slug'] ?? '');
            if ($slug === '') {
                return false;
            }
            $key = $type . ':' . $slug;
            $row = $packages[$key] ?? null;
            if (!is_array($row) || empty($row['status'])) {
                return false;
            }
            if ((string) ($row['version'] ?? '') !== (string) ($pkg['version'] ?? '')) {
                return false;
            }
            // Only reuse clean results — prior findings are not re-emitted into
            // this scan's CleanSweep_ThreatStore, so a dirty cache must force a re-check.
            if ((int) ($row['finding_count'] ?? 0) > 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Identity finding for unverifiable packages (impersonating slug or decoy).
     *
     * @return array{finding:array,note:string,kind:string}|null
     */
    private static function identity_finding(array $pkg, string $dir_n): ?array {
        $id_file = dirname(__DIR__, 2) . '/maintenance/lib/PackageIdentity.php';
        if (!class_exists('CleanSweep_PackageIdentity', false) && is_readable($id_file)) {
            require_once $id_file;
        }
        if (!class_exists('CleanSweep_PackageIdentity', false)) {
            return null;
        }

        $type = (($pkg['type'] ?? 'plugin') === 'theme') ? 'theme' : 'plugin';
        $slug = (string) ($pkg['slug'] ?? '');
        $plugin_uri = (string) ($pkg['plugin_uri'] ?? '');
        $author_uri = (string) ($pkg['author_uri'] ?? '');
        $theme_uri = (string) ($pkg['theme_uri'] ?? '');
        $dir = rtrim($dir_n, '/');
        $tree = CleanSweep_PackageIdentity::tree_stats($dir, $pkg['single_file'] ?? null);
        $spoof = CleanSweep_PackageIdentity::claims_wordpress_org($plugin_uri)
            || CleanSweep_PackageIdentity::claims_wordpress_org($author_uri)
            || CleanSweep_PackageIdentity::claims_wordpress_org($theme_uri);
        // Premium/custom with a real tree and no .org URI: skip extra API.
        if (empty($tree['tiny']) && !$spoof) {
            return null;
        }

        $utils = dirname(__DIR__, 2) . '/maintenance/plugin-utils.php';
        if (!function_exists('clean_sweep_fetch_plugin_info') && is_readable($utils)) {
            require_once $utils;
        }
        $org = [];
        if ($type === 'plugin' && function_exists('clean_sweep_fetch_plugin_info')) {
            $org = clean_sweep_fetch_plugin_info($slug);
        } elseif ($type === 'theme' && function_exists('clean_sweep_fetch_theme_info')) {
            $org = clean_sweep_fetch_theme_info($slug);
        }

        $verdict = CleanSweep_PackageIdentity::evaluate([
            'type' => $type,
            'slug' => $slug,
            'name' => (string) ($pkg['name'] ?? $slug),
            'version' => (string) ($pkg['version'] ?? ''),
            'author' => (string) ($pkg['author'] ?? ''),
            'plugin_uri' => $plugin_uri,
            'author_uri' => $author_uri,
            'theme_uri' => $theme_uri,
            'dir' => $dir,
            'single_file' => $pkg['single_file'] ?? null,
            'org_info' => (!empty($org) && !empty($org['version'])) ? $org : [],
            'checksum_status' => $type === 'theme' ? 'n/a' : 'unavailable',
            'checksum_outcome' => $type === 'theme' ? 'n/a' : 'unverifiable',
        ]);
        if (($verdict['kind'] ?? 'ok') === 'ok') {
            CleanSweep_PackageIdentity::forget($type, $slug);
            return null;
        }

        CleanSweep_PackageIdentity::upsert([
            'type' => $type,
            'slug' => $slug,
            'name' => (string) ($pkg['name'] ?? $slug),
            'kind' => $verdict['kind'],
            'reasons' => $verdict['reasons'],
        ]);

        $desc = implode(' ', $verdict['reasons']);
        $finding = self::package_finding(
            $pkg,
            $dir_n,
            'package_identity',
            $desc,
            'high',
            70,
            self::empty_metrics()
        );
        $finding['identity_kind'] = $verdict['kind'];
        $finding['package_outcome'] = 'identity';
        $finding['type'] = 'identity';

        return [
            'finding' => $finding,
            'note' => $desc,
            'kind' => $verdict['kind'],
        ];
    }

    /**
     * Number of files in the official checksum map, or null if unpublished / fetch failed.
     */
    public static function official_file_count(string $type, string $slug, string $version): ?int {
        if ($slug === '' || $version === '' || $type === 'theme') {
            return null;
        }
        $map = self::fetch_map($type, $slug, $version);
        if ($map === null) {
            return null;
        }
        return count($map);
    }

    /**
     * @return array<string,string>|null rel => md5
     */
    private static function fetch_map(string $type, string $slug, string $version): ?array {
        // wordpress.org has no theme-checksums endpoint. Do not probe it.
        if ($type === 'theme') {
            return null;
        }

        $cache = self::cache_path($type, $slug, $version);
        if (is_readable($cache) && (time() - (int) @filemtime($cache)) < self::CACHE_TTL) {
            $cached = json_decode((string) @file_get_contents($cache), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = sprintf(self::PLUGIN_URL, rawurlencode($slug), rawurlencode($version));
        $body = self::http_get($url);
        if ($body === null || $body === '') {
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }
        $files = $json['files'] ?? $json;
        if (!is_array($files)) {
            return null;
        }
        $map = [];
        foreach ($files as $rel => $meta) {
            $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
            $hashes = [];
            if (is_string($meta) && $meta !== '' && strcasecmp($meta, 'Array') !== 0) {
                $hashes[] = $meta;
            } elseif (is_array($meta)) {
                $raw = $meta['md5'] ?? $meta['md5sum'] ?? [];
                foreach ((array) $raw as $h) {
                    if (is_string($h) && $h !== '' && strcasecmp($h, 'Array') !== 0) {
                        $hashes[] = $h;
                    }
                }
            }
            if ($rel !== '' && $hashes !== []) {
                $map[$rel] = $hashes;
            }
        }
        if ($map === []) {
            return null;
        }
        @file_put_contents($cache, json_encode($map));
        return $map;
    }

    private static function cache_path(string $type, string $slug, string $version): string {
        $dir = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'backups/'
            : dirname(__DIR__, 2) . '/backups/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $safe = preg_replace('/[^a-z0-9._-]/i', '_', $type . '_' . $slug . '_' . $version);
        return $dir . 'pkg_checksums_' . $safe . '.json';
    }

    private static function finding(
        string $abs,
        string $rel,
        array $pkg,
        string $code,
        string $desc,
        string $severity = 'high',
        int $score = 75
    ): array {
        $label = ($pkg['type'] ?? 'plugin') . ' ' . ($pkg['slug'] ?? '');
        // Prefer site-relative path for the editor / files API (not package-relative).
        $site_path = class_exists('CleanSweep_SitePaths', false)
            ? CleanSweep_SitePaths::to_site_relative($abs)
            : '';
        if ($site_path === '') {
            $site_path = $rel;
        }
        return [
            'id' => md5('pkg|' . $label . '|' . $rel . '|' . $code),
            'source' => 'integrity',
            'type' => $code === 'checksum_mismatch' ? 'modified' : 'extra',
            'file' => $abs,
            'path' => $site_path,
            'package_path' => $rel,
            'pattern' => $code,
            'signature_id' => $code,
            'match' => $rel,
            'description' => ($pkg['name'] ?? $pkg['slug'] ?? '') . ': ' . $desc,
            'risk_level' => $severity,
            'threat_level' => $severity,
            'severity' => $severity,
            'risk_score' => $score,
            'line_number' => null,
            'open_in_editor' => $abs,
            'integrity' => true,
            'checksum' => true,
            'package_checksum' => true,
            'package_type' => $pkg['type'] ?? 'plugin',
            'package_slug' => $pkg['slug'] ?? '',
            'package_version' => $pkg['version'] ?? '',
            'detected_at' => date('c'),
        ];
    }

    /**
     * Package-level integrity finding (Type C / Type B rollup).
     *
     * @param array $metrics
     */
    private static function package_finding(
        array $pkg,
        string $dir_n,
        string $code,
        string $description,
        string $severity,
        int $score,
        array $metrics
    ): array {
        $slug = (string) ($pkg['slug'] ?? '');
        $type = (string) ($pkg['type'] ?? 'plugin');
        $label = $type . ' ' . $slug;
        $main = rtrim($dir_n, '/') . '/';
        // Prefer a real file the editor can open (not the package directory).
        $file = $main;
        if (!empty($pkg['single_file'])) {
            $file = $main . $pkg['single_file'];
        } elseif ($type === 'theme') {
            $guess = $main . 'style.css';
            if (is_file($guess)) {
                $file = $guess;
            }
        } elseif ($type === 'plugin' && $slug !== '') {
            $guess = $main . $slug . '.php';
            if (is_file($guess)) {
                $file = $guess;
            }
        }

        $open = is_file($file) ? $file : $dir_n;
        $site_path = class_exists('CleanSweep_SitePaths', false)
            ? CleanSweep_SitePaths::to_site_relative(is_file($file) ? $file : rtrim($dir_n, '/'))
            : '';
        if ($site_path === '') {
            $site_path = $slug !== '' ? $slug : $file;
        }
        return [
            'id' => md5('pkg|' . $label . '|' . $code . '|' . ($pkg['version'] ?? '')),
            'source' => 'integrity',
            'type' => $code === 'package_divergent' ? 'divergent' : 'extra',
            'file' => $file,
            'path' => $site_path,
            'package_path' => $slug,
            'pattern' => $code,
            'signature_id' => $code,
            'match' => $slug,
            'description' => $description,
            'risk_level' => $severity,
            'threat_level' => $severity,
            'severity' => $severity,
            'risk_score' => $score,
            'line_number' => null,
            'open_in_editor' => $open,
            'integrity' => true,
            'checksum' => true,
            'package_checksum' => true,
            'package_type' => $type,
            'package_slug' => $slug,
            'package_version' => $pkg['version'] ?? '',
            'package_outcome' => $code === 'package_divergent' ? 'divergent' : 'extras_rollup',
            'metrics' => $metrics,
            'detected_at' => date('c'),
        ];
    }

    private static function http_get(string $url): ?string {
        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($url, ['timeout' => 10, 'redirection' => 2, 'sslverify' => true]);
            $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
            if ($code === 200) {
                $body = wp_remote_retrieve_body($res);
                return is_string($body) ? $body : null;
            }
            return null;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($body !== false && $code === 200) ? (string) $body : null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 10], 'ssl' => ['verify_peer' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : null;
    }
}
