<?php
/**
 * Phase 6: package verification baseline (scan integrity for Pro / non-.org trees).
 * Separate from visit CleanSweep_ScopeSealer — do not write visit scopes here.
 *
 * Preferred create path: Clean Sweep Upload reinstall success (source=upload).
 */
final class CleanSweep_PackageVerificationBaseline {

    private const MIN_COVERED_FOR_RATE = 20;
    private const A_ABS = 40;
    private const A_RATE = 0.15;
    private const MATCH_MIN = 0.90;
    private const A_MAX = 40;
    private const B_ROLLUP = 25;
    private const B_TOPK = 10;
    private const ASSET_SOFT_MAX = 4096;

    /** Extensions hashed into the baseline (security-relevant + install-stable). */
    private const HASH_EXTS = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'js', 'css', 'json'];

    public static function store_path(): string {
        $dir = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'backups/'
            : dirname(__DIR__, 2) . '/backups/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . 'package_verification_baselines.json';
    }

    public static function key(string $type, string $slug): string {
        $type = ($type === 'theme') ? 'theme' : 'plugin';
        return $type . ':' . trim($slug, '/');
    }

    /**
     * @return array<string,array>
     */
    public static function load_all(): array {
        $file = self::store_path();
        if (!is_readable($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data['packages'] ?? null) ? $data['packages'] : [];
    }

    /**
     * @return array|null
     */
    public static function get(string $type, string $slug): ?array {
        $all = self::load_all();
        $key = self::key($type, $slug);
        $row = $all[$key] ?? null;
        return is_array($row) ? $row : null;
    }

    /**
     * Hash a package directory into a baseline and persist.
     *
     * @param array{type:string,slug:string,dir:string,version?:string,name?:string,source?:string} $opts
     * @return array{success:bool,key?:string,file_count?:int,error?:string}
     */
    public static function create_from_dir(array $opts): array {
        $type = ($opts['type'] ?? 'plugin') === 'theme' ? 'theme' : 'plugin';
        $slug = trim((string) ($opts['slug'] ?? ''), '/');
        $dir = (string) ($opts['dir'] ?? '');
        $version = (string) ($opts['version'] ?? '');
        $source = (string) ($opts['source'] ?? 'upload');
        if ($slug === '' || $dir === '' || !is_dir($dir)) {
            return ['success' => false, 'error' => 'missing slug/dir'];
        }
        $dir_n = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        $files = self::hash_tree($dir_n);
        if ($files === []) {
            return ['success' => false, 'error' => 'no hashable files'];
        }
        if ($version === '') {
            $version = self::detect_version($dir_n, $type) ?: 'unknown';
        }
        $key = self::key($type, $slug);
        $all = self::load_all();
        $all[$key] = [
            'type' => $type,
            'slug' => $slug,
            'name' => (string) ($opts['name'] ?? $slug),
            'version' => $version,
            'source' => $source,
            'created_at' => time(),
            'dir' => $dir_n,
            'file_count' => count($files),
            'files' => $files,
        ];
        $ok = @file_put_contents(self::store_path(), json_encode([
            'updated_at' => time(),
            'packages' => $all,
        ]));
        if ($ok === false) {
            return ['success' => false, 'error' => 'write failed'];
        }
        if (function_exists('clean_sweep_log_message')) {
            clean_sweep_log_message(
                "Package verification baseline saved: {$key} v{$version} (" . count($files) . " files, source={$source})",
                'info'
            );
        }
        return ['success' => true, 'key' => $key, 'file_count' => count($files), 'version' => $version];
    }

    /**
     * Compare installed package to a stored baseline.
     *
     * @return array{
     *   status:string,
     *   outcome:string,
     *   findings:array,
     *   note:?string,
     *   metrics:array,
     *   official_ok:array,
     *   checked:int
     * }|null null if no usable baseline
     */
    public static function compare(array $pkg, array $baseline): ?array {
        $slug = (string) ($pkg['slug'] ?? '');
        $version = (string) ($pkg['version'] ?? '');
        $dir = (string) ($pkg['dir'] ?? '');
        $type = (string) ($pkg['type'] ?? 'plugin');
        if ($slug === '' || $dir === '' || !is_dir($dir)) {
            return null;
        }
        $dir_n = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        $base_ver = (string) ($baseline['version'] ?? '');
        $base_files = $baseline['files'] ?? [];
        if (!is_array($base_files) || $base_files === []) {
            return null;
        }

        // Version mismatch → one stale finding, do not flood
        if ($base_ver !== '' && $version !== '' && $base_ver !== $version) {
            $finding = self::package_finding(
                $pkg,
                $dir_n,
                'package_baseline_stale',
                sprintf(
                    'Package verification baseline is for %s %s; installed is %s. Re-upload via Clean Sweep Upload to refresh the baseline after you trust this install. Content scan still applies.',
                    $slug,
                    $base_ver,
                    $version
                ),
                'medium',
                45,
                [
                    'baseline_version' => $base_ver,
                    'installed_version' => $version,
                    'source' => $baseline['source'] ?? 'unknown',
                ]
            );
            return [
                'status' => 'modified',
                'outcome' => 'baseline_stale',
                'findings' => [$finding],
                'note' => "baseline stale ({$base_ver} → {$version})",
                'metrics' => [
                    'covered_ok' => 0,
                    'covered_bad' => 0,
                    'extra_php' => 0,
                    'match_rate' => 0.0,
                    'skipped_unreadable' => 0,
                    'baseline' => true,
                ],
                'official_ok' => [],
                'checked' => 0,
                'baseline_used' => true,
            ];
        }

        $covered_ok = 0;
        $covered_bad = 0;
        $skipped = 0;
        $extra = 0;
        $official_ok = [];
        $bad_paths = [];
        $top_k = [];

        foreach ($base_files as $rel => $hash) {
            $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
            if ($rel === '' || !is_string($hash) || $hash === '') {
                continue;
            }
            $full = $dir_n . $rel;
            if (!is_file($full) || is_link($full)) {
                // missing from install — count as bad if we care; soft skip for incomplete
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
            if (strcasecmp($md5, $hash) === 0) {
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
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $item) {
                if ($item->isLink() || !$item->isFile()) {
                    continue;
                }
                $full = str_replace('\\', '/', $item->getPathname());
                $rel = ltrim(substr($full, strlen(rtrim($dir_n, '/'))), '/');
                if ($rel === '' || isset($base_files[$rel])) {
                    continue;
                }
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                if (!in_array($ext, self::HASH_EXTS, true)) {
                    continue;
                }
                // Only treat unexpected PHP as extras (same security focus as Type B)
                if (!in_array($ext, ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar'], true)) {
                    continue;
                }
                if (!is_readable($full)) {
                    $skipped++;
                    continue;
                }
                $extra++;
                $size = (int) @filesize($full);
                $score = self::cheap_score($rel, $size);
                self::top_k_push($top_k, $score, $full, $rel, self::B_TOPK);
            }
        } catch (Throwable $e) {
            // ignore
        }

        $covered = $covered_ok + $covered_bad;
        $match_rate = $covered > 0 ? ($covered_ok / $covered) : 1.0;
        $metrics = [
            'covered_ok' => $covered_ok,
            'covered_bad' => $covered_bad,
            'extra_php' => $extra,
            'match_rate' => round($match_rate, 4),
            'skipped_unreadable' => $skipped,
            'baseline' => true,
            'baseline_version' => $base_ver,
            'baseline_source' => $baseline['source'] ?? 'unknown',
        ];
        $checked = $covered + $extra;

        $type_c = false;
        if ($covered >= self::MIN_COVERED_FOR_RATE) {
            $type_c = (
                $covered_bad >= self::A_ABS
                || $covered_bad >= (int) ceil(self::A_RATE * $covered)
                || $match_rate < self::MATCH_MIN
            );
        }

        $findings = [];
        $name = (string) ($pkg['name'] ?? $slug);
        $src_label = (string) ($baseline['source'] ?? 'upload');

        if ($type_c) {
            $pct = (int) round($match_rate * 100);
            $findings[] = self::package_finding(
                $pkg,
                $dir_n,
                'package_baseline_divergent',
                sprintf(
                    '%s: installed tree bulk-diverges from Clean Sweep verification baseline v%s (source=%s; %d/%d files differ, ~%d%% match). Content scan still applies.',
                    $name,
                    $base_ver !== '' ? $base_ver : '?',
                    $src_label,
                    $covered_bad,
                    $covered,
                    $pct
                ),
                'medium',
                55,
                $metrics
            );
            return [
                'status' => 'modified',
                'outcome' => 'baseline_divergent',
                'findings' => $findings,
                'note' => 'bulk divergence from verification baseline',
                'metrics' => $metrics,
                'official_ok' => array_keys($official_ok),
                'checked' => $checked,
                'baseline_used' => true,
            ];
        }

        foreach ($bad_paths as [$full, $rel]) {
            $findings[] = self::file_finding(
                $full,
                $rel,
                $pkg,
                'baseline_mismatch',
                'does not match the Clean Sweep verification baseline (possible change after Upload)',
                'high',
                80
            );
        }

        if ($extra >= self::B_ROLLUP) {
            $findings[] = self::package_finding(
                $pkg,
                $dir_n,
                'package_baseline_extras_rollup',
                sprintf(
                    '%s: %d PHP file(s) not in verification baseline (rolled up). Content scan still applies.',
                    $name,
                    $extra
                ),
                'info',
                25,
                $metrics
            );
            foreach ($top_k as $row) {
                $findings[] = self::file_finding(
                    $row['full'],
                    $row['rel'],
                    $pkg,
                    'baseline_unexpected_php',
                    'PHP file is not in the verification baseline',
                    'medium',
                    55
                );
            }
        } elseif ($extra > 0) {
            foreach ($top_k as $row) {
                $findings[] = self::file_finding(
                    $row['full'],
                    $row['rel'],
                    $pkg,
                    'baseline_unexpected_php',
                    'PHP file is not in the verification baseline',
                    'medium',
                    55
                );
            }
        }

        $status = empty($findings) ? 'match' : 'modified';
        $outcome = empty($findings) ? 'baseline_verified' : 'baseline_outliers';

        return [
            'status' => $status,
            'outcome' => $outcome,
            'findings' => $findings,
            'note' => empty($findings)
                ? "matches verification baseline v{$base_ver} ({$src_label})"
                : "differs from verification baseline v{$base_ver}",
            'metrics' => $metrics,
            'official_ok' => array_keys($official_ok),
            'checked' => $checked,
            'baseline_used' => true,
        ];
    }

    /**
     * @return array<string,string> rel => md5
     */
    private static function hash_tree(string $dir_n): array {
        $out = [];
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir_n, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $item) {
                if ($item->isLink() || !$item->isFile()) {
                    continue;
                }
                $full = str_replace('\\', '/', $item->getPathname());
                $rel = ltrim(substr($full, strlen(rtrim($dir_n, '/'))), '/');
                if ($rel === '') {
                    continue;
                }
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                if (!in_array($ext, self::HASH_EXTS, true)) {
                    continue;
                }
                if (!is_readable($full)) {
                    continue;
                }
                $md5 = @md5_file($full);
                if (is_string($md5) && $md5 !== '') {
                    $out[$rel] = $md5;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        return $out;
    }

    private static function detect_version(string $dir_n, string $type): string {
        if ($type === 'theme') {
            $style = $dir_n . 'style.css';
            if (is_readable($style)) {
                $c = (string) @file_get_contents($style);
                if (preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $c, $m) || preg_match('/^Version:\s*(.+)$/mi', $c, $m)) {
                    return trim($m[1]);
                }
            }
            return '';
        }
        // plugin: scan main-ish php headers
        $candidates = glob($dir_n . '*.php') ?: [];
        foreach ($candidates as $f) {
            $c = (string) @file_get_contents($f, false, null, 0, 8192);
            if (preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $c, $m)) {
                return trim($m[1]);
            }
        }
        return '';
    }

    private static function cheap_score(string $rel, int $size): int {
        $rel = str_replace('\\', '/', strtolower($rel));
        $base = basename($rel);
        $score = 0;
        if (preg_match('#/(images?|img|cache|tmp|temp|uploads?)/#', '/' . $rel . '/')) {
            $score += 80;
        }
        if (strpos($rel, '/') === false) {
            $score += 50;
        }
        if (substr($rel, -10) === '.asset.php' || substr($base, -10) === '.asset.php') {
            $score += ($size > self::ASSET_SOFT_MAX) ? 40 + min(40, (int) ($size / 1024)) : 5;
        } elseif ($size > 50 * 1024) {
            $score += 35;
        }
        return $score;
    }

    /** @param array<int,array{score:int,full:string,rel:string}> $top_k */
    private static function top_k_push(array &$top_k, int $score, string $full, string $rel, int $k): void {
        $top_k[] = ['score' => $score, 'full' => $full, 'rel' => $rel];
        usort($top_k, static function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        if (count($top_k) > $k) {
            $top_k = array_slice($top_k, 0, $k);
        }
    }

    private static function file_finding(
        string $abs,
        string $rel,
        array $pkg,
        string $code,
        string $desc,
        string $severity,
        int $score
    ): array {
        $label = ($pkg['type'] ?? 'plugin') . ' ' . ($pkg['slug'] ?? '');
        if (!class_exists('CleanSweep_SitePaths', false)) {
            require_once __DIR__ . '/SitePaths.php';
        }
        $site_path = CleanSweep_SitePaths::to_site_relative($abs);
        if ($site_path === '') {
            $site_path = $rel;
        }
        return [
            'id' => md5('pvb|' . $label . '|' . $rel . '|' . $code),
            'source' => 'integrity',
            'type' => $code === 'baseline_mismatch' ? 'modified' : 'extra',
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
            'package_baseline' => true,
            'package_type' => $pkg['type'] ?? 'plugin',
            'package_slug' => $pkg['slug'] ?? '',
            'package_version' => $pkg['version'] ?? '',
            'detected_at' => date('c'),
        ];
    }

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
        $file = rtrim($dir_n, '/') . '/';
        if (!empty($pkg['single_file'])) {
            $file = $file . $pkg['single_file'];
        }
        if (!class_exists('CleanSweep_SitePaths', false)) {
            require_once __DIR__ . '/SitePaths.php';
        }
        $open = is_file($file) ? $file : $dir_n;
        $site_path = CleanSweep_SitePaths::to_site_relative(is_file($file) ? $file : rtrim($dir_n, '/'));
        if ($site_path === '') {
            $site_path = $slug !== '' ? $slug : $file;
        }
        return [
            'id' => md5('pvb|' . $label . '|' . $code . '|' . ($pkg['version'] ?? '')),
            'source' => 'integrity',
            'type' => 'baseline',
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
            'package_baseline' => true,
            'package_type' => $type,
            'package_slug' => $slug,
            'package_version' => $pkg['version'] ?? '',
            'package_outcome' => $code,
            'metrics' => $metrics,
            'detected_at' => date('c'),
        ];
    }
}
