<?php
/**
 * First-scan core check against WordPress.org checksums.
 *
 * Complements the post-reinstall local baseline: this runs even when
 * no Clean Sweep baseline exists. Soft-fails when the API is unreachable.
 *
 * Flags:
 *  - official wp-admin / wp-includes / root core file whose MD5 does not match
 *  - extra .php sitting under wp-admin or wp-includes
 *
 * Root extras (custom scripts) are not auto-flagged. Missing official
 * files are not flagged (incomplete installs). wp-config.php and
 * wp-content are out of scope.
 */
require_once dirname(__DIR__) . '/SitePaths.php';

final class CleanSweep_CoreChecksumWorker implements CleanSweep_Worker {

    private const API = 'https://api.wordpress.org/core/checksums/1.0/';
    private const CACHE_TTL = 604800;

    /** Reuse a clean core-check result for this long (6 hours). */
    private const REUSE_TTL = 21600;

    public function type(): string {
        return CleanSweep_ScanWorkUnit::TYPE_CORE_CHECKSUM;
    }

    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        $started = time();
        $root = CleanSweep_SitePaths::root();
        $version = CleanSweep_SitePaths::wordpress_version();

        if ($root === null || $version === '') {
            return CleanSweep_WorkerResult::completed([
                'skipped' => true,
                'note' => 'Could not resolve site root or WordPress version',
                'duration_seconds' => time() - $started,
            ]);
        }

        $locale = CleanSweep_SitePaths::locale();
        $profile = $ctx->profile();
        $force = !empty($payload['force'])
            || (method_exists($profile, 'get_profile_id') && $profile->get_profile_id() === 'deep');

        // Skip the full core walk when a clean, same-fingerprint result is fresh.
        if (!$force) {
            $reused = $this->try_reuse_result($root, $version, $locale, $ctx, $started);
            if ($reused !== null) {
                return $reused;
            }
        }

        $checksums = $this->load_checksums($version, $locale);
        if ($checksums === null || empty($checksums)) {
            $note = "WordPress.org checksums unavailable for {$version} ({$locale}); skipped.";
            $ctx->mergeState([
                'options' => array_merge($ctx->state()->options ?? [], [
                    'checksum_note' => $note,
                ]),
            ]);
            clean_sweep_log_message("CleanSweep_CoreChecksumWorker: {$note}", 'warning');
            return CleanSweep_WorkerResult::completed([
                'skipped' => true,
                'note' => $note,
                'duration_seconds' => time() - $started,
            ]);
        }

        $findings = [];
        $checked = 0;
        $this->check_official_root_files($root, $checksums, $findings, $checked, $ctx);
        foreach (['wp-admin', 'wp-includes'] as $dir) {
            if ($ctx->isCancelled()) {
                break;
            }
            $abs = $root . $dir;
            if (!is_dir($abs)) {
                continue;
            }
            $this->walk_tree($abs, $dir, $root, $checksums, $findings, $checked, $ctx);
        }

        foreach ($findings as $threat) {
            $ctx->recordThreat($threat);
        }
        if (count($findings) > 0) {
            $ctx->incrementCounter('threats_found', count($findings));
            $ctx->incrementCounter('integrity_violations', count($findings));
        }

        // Phase 3: content-scan mismatched / unexpected core files (not all of core).
        $content_hits = 0;
        $content_scanned = 0;
        if (!$ctx->isCancelled() && count($findings) > 0) {
            $content = $this->content_scan_mismatches($findings, $ctx);
            $content_hits = (int) ($content['threats'] ?? 0);
            $content_scanned = (int) ($content['scanned'] ?? 0);
            if ($content_hits > 0) {
                $ctx->incrementCounter('threats_found', $content_hits);
            }
        }

        $note = count($findings) > 0
            ? count($findings) . " core file(s) differ from WordPress.org {$version} checksums."
            : "WordPress core files match WordPress.org checksums for {$version}.";
        if ($content_scanned > 0) {
            $note .= " Content-scanned {$content_scanned} mismatched file(s)"
                . ($content_hits > 0 ? ", {$content_hits} signature hit(s)." : '.');
        }

        $ctx->mergeState([
            'options' => array_merge($ctx->state()->options ?? [], [
                'checksum_note' => $note,
                'checksum_checked' => $checked,
                'checksum_findings' => count($findings),
                'checksum_version' => $version,
                'checksum_reused' => false,
            ]),
        ]);

        $this->save_result_snapshot([
            'updated_at' => time(),
            'version' => $version,
            'locale' => $locale,
            'fingerprint' => $this->core_fingerprint($root, $version),
            'checked' => $checked,
            'findings' => count($findings),
            'content_scanned' => $content_scanned,
            'content_threats' => $content_hits,
            'note' => $note,
        ]);

        clean_sweep_log_message(
            "CleanSweep_CoreChecksumWorker: checked {$checked} files, " . count($findings)
            . " integrity finding(s), content-scanned {$content_scanned} ({$content_hits} sig hit(s)) (WP {$version})",
            'info'
        );

        return CleanSweep_WorkerResult::completed([
            'checked' => $checked,
            'findings' => count($findings),
            'content_scanned' => $content_scanned,
            'content_threats' => $content_hits,
            'version' => $version,
            'locale' => $locale,
            'note' => $note,
            'duration_seconds' => time() - $started,
        ]);
    }

    /**
     * @return CleanSweep_WorkerResult|null Completed reuse result, or null to run a full check
     */
    private function try_reuse_result(
        string $root,
        string $version,
        string $locale,
        CleanSweep_WorkerContext $ctx,
        int $started
    ): ?CleanSweep_WorkerResult {
        $snap = $this->load_result_snapshot();
        if ($snap === null) {
            return null;
        }
        if ((time() - (int) ($snap['updated_at'] ?? 0)) > self::REUSE_TTL) {
            return null;
        }
        if ((string) ($snap['version'] ?? '') !== $version
            || (string) ($snap['locale'] ?? '') !== $locale
        ) {
            return null;
        }
        // Only reuse clean runs — dirty results must re-check so threats are
        // recorded against this scan_id's CleanSweep_ThreatStore.
        if ((int) ($snap['findings'] ?? 0) > 0 || (int) ($snap['content_threats'] ?? 0) > 0) {
            return null;
        }
        $fp = $this->core_fingerprint($root, $version);
        if ($fp === '' || (string) ($snap['fingerprint'] ?? '') !== $fp) {
            return null;
        }

        $note = (string) ($snap['note'] ?? "WordPress core files match WordPress.org checksums for {$version}.");
        $note .= ' (reused prior clean result)';
        $checked = (int) ($snap['checked'] ?? 0);
        $ctx->mergeState([
            'options' => array_merge($ctx->state()->options ?? [], [
                'checksum_note' => $note,
                'checksum_checked' => $checked,
                'checksum_findings' => 0,
                'checksum_version' => $version,
                'checksum_reused' => true,
            ]),
        ]);
        clean_sweep_log_message("CleanSweep_CoreChecksumWorker: {$note}", 'info');
        return CleanSweep_WorkerResult::completed([
            'skipped' => true,
            'reused' => true,
            'checked' => $checked,
            'findings' => 0,
            'version' => $version,
            'locale' => $locale,
            'note' => $note,
            'duration_seconds' => time() - $started,
        ]);
    }

    private function core_fingerprint(string $root, string $version): string {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $ver_file = $root . 'wp-includes/version.php';
        $mtime = is_readable($ver_file) ? (int) @filemtime($ver_file) : 0;
        $size = is_readable($ver_file) ? (int) @filesize($ver_file) : 0;
        return hash('sha256', $version . '|' . $mtime . '|' . $size);
    }

    private function result_snapshot_path(): string {
        $dir = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'backups/'
            : dirname(__DIR__, 3) . '/backups/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . 'core_checksum_result.json';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_result_snapshot(): ?array {
        $file = $this->result_snapshot_path();
        if (!is_readable($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string,mixed> $snap
     */
    private function save_result_snapshot(array $snap): void {
        @file_put_contents(
            $this->result_snapshot_path(),
            json_encode($snap, JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * Run signature scan only on paths that failed checksum / unexpected-core checks.
     *
     * @param array<int,array> $findings
     * @return array{scanned:int,threats:int}
     */
    private function content_scan_mismatches(array $findings, CleanSweep_WorkerContext $ctx): array {
        $paths = [];
        foreach ($findings as $f) {
            $abs = (string) ($f['file'] ?? '');
            if ($abs === '' || !is_file($abs) || !is_readable($abs)) {
                continue;
            }
            $paths[$abs] = true;
        }
        $paths = array_keys($paths);
        if ($paths === []) {
            return ['scanned' => 0, 'threats' => 0];
        }

        if (!function_exists('clean_sweep_get_malware_signatures')) {
            $sig_file = dirname(__DIR__, 2) . '/signatures.php';
            if (is_readable($sig_file)) {
                require_once $sig_file;
            }
        }
        if (!function_exists('clean_sweep_get_malware_signatures')) {
            return ['scanned' => 0, 'threats' => 0];
        }

        require_once dirname(__DIR__, 2) . '/content-scanners/FileScanner.php';
        require_once dirname(__DIR__, 2) . '/ThreatCollector.php';
        require_once dirname(__DIR__) . '/ThreatStore.php';

        $profile = $ctx->profile();
        $scanner = new CleanSweep_FileScanner($profile, $ctx->throttle());
        $scanner->set_signatures(clean_sweep_get_malware_signatures()->get_signatures());
        $collector = new CleanSweep_ThreatCollector(50);
        $scanner->set_collector($collector);
        $scanner->set_context($ctx);
        $collector->set_threat_store(new CleanSweep_ThreatStore($ctx->state()->scan_id));

        $sig = $scanner->scan_explicit_paths($paths);
        $collector->flush();

        $count = method_exists($collector, 'get_total')
            ? (int) $collector->get_total()
            : count($sig['threats'] ?? []);

        clean_sweep_log_message(
            'CleanSweep_CoreChecksumWorker: content-scanned ' . count($paths) . ' mismatched core path(s), '
            . $count . ' signature hit(s)',
            'info'
        );

        return [
            'scanned' => (int) ($sig['scanned'] ?? count($paths)),
            'threats' => $count,
        ];
    }

    /**
     * Hash official packaged files that live in the site root
     * (wp-activate.php, wp-login.php, wp-settings.php, …).
     * Extra root PHP is ignored — sites often keep custom scripts there.
     *
     * @param array<string,string> $checksums
     * @param array<int,array> $findings
     */
    private function check_official_root_files(
        string $root,
        array $checksums,
        array &$findings,
        int &$checked,
        CleanSweep_WorkerContext $ctx
    ): void {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $n = 0;
        foreach ($checksums as $rel => $hash) {
            if ($n++ % 80 === 0 && $ctx->isCancelled()) {
                return;
            }
            $rel = str_replace('\\', '/', (string) $rel);
            if ($rel === '' || strpos($rel, '/') !== false) {
                continue;
            }
            if (strcasecmp($rel, 'wp-config.php') === 0) {
                continue;
            }
            $full = $root . $rel;
            if (!is_file($full) || is_link($full)) {
                continue;
            }
            $checked++;
            $md5 = @md5_file($full);
            if (!is_string($md5) || strcasecmp($md5, (string) $hash) === 0) {
                continue;
            }
            $findings[] = $this->finding(
                $full,
                $rel,
                'checksum_mismatch',
                'File does not match the official WordPress.org checksum for this version. May be malware or a host patch.',
                'critical',
                90
            );
        }
    }

    /**
     * @param array<string,string> $checksums relative path => md5
     * @param array<int,array> $findings
     */
    private function walk_tree(
        string $abs_dir,
        string $rel_dir,
        string $root,
        array $checksums,
        array &$findings,
        int &$checked,
        CleanSweep_WorkerContext $ctx
    ): void {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $abs_dir,
                    FilesystemIterator::SKIP_DOTS
                )
            );
        } catch (Throwable $e) {
            clean_sweep_log_message("CleanSweep_CoreChecksumWorker: cannot walk {$abs_dir}: " . $e->getMessage(), 'warning');
            return;
        }

        $n = 0;
        foreach ($iterator as $item) {
            if ($n++ % 80 === 0 && $ctx->isCancelled()) {
                return;
            }
            if ($item->isLink() || !$item->isFile()) {
                continue;
            }
            $full = str_replace('\\', '/', $item->getPathname());
            $rel = ltrim(substr($full, strlen(rtrim($root, '/'))), '/');
            if ($rel === '') {
                continue;
            }

            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            $in_official = isset($checksums[$rel]);

            if ($in_official) {
                $checked++;
                $md5 = @md5_file($full);
                if (!is_string($md5) || strcasecmp($md5, $checksums[$rel]) === 0) {
                    continue;
                }
                $findings[] = $this->finding(
                    $full,
                    $rel,
                    'checksum_mismatch',
                    'File does not match the official WordPress.org checksum for this version. May be malware or a host patch.',
                    'critical',
                    90
                );
                continue;
            }

            if ($this->is_unexpected_executable($ext)) {
                $checked++;
                $findings[] = $this->finding(
                    $full,
                    $rel,
                    'unexpected_core_php',
                    'PHP file under wp-admin / wp-includes is not in the official WordPress package.',
                    'critical',
                    95
                );
            }
        }
    }

    private function is_unexpected_executable(string $ext): bool {
        return in_array($ext, ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar'], true);
    }

    private function finding(string $abs, string $rel, string $code, string $description, string $level, int $score): array {
        return [
            'id' => md5('checksum|' . $rel . '|' . $code),
            'source' => 'integrity',
            'type' => $code === 'checksum_mismatch' ? 'modified' : 'extra',
            'file' => $abs,
            'path' => $rel,
            'pattern' => $code,
            'signature_id' => $code,
            'match' => $rel,
            'description' => $description,
            'risk_level' => $level,
            'threat_level' => $level,
            'severity' => $level,
            'risk_score' => $score,
            'line_number' => null,
            'open_in_editor' => $abs,
            'integrity' => true,
            'checksum' => true,
            'package_type' => 'core',
            'detected_at' => date('c'),
        ];
    }

    /**
     * @return array<string,string>|null
     */
    private function load_checksums(string $version, string $locale): ?array {
        $cached = $this->read_cache($version, $locale);
        if (is_array($cached)) {
            return $cached;
        }

        $map = $this->fetch_checksums($version, $locale);
        if ($map === null && $locale !== 'en_US') {
            $map = $this->fetch_checksums($version, 'en_US');
        }
        if (is_array($map) && !empty($map)) {
            $this->write_cache($version, $locale, $map);
        }
        return $map;
    }

    /**
     * @return array<string,string>|null
     */
    private function fetch_checksums(string $version, string $locale): ?array {
        $url = self::API . '?version=' . rawurlencode($version) . '&locale=' . rawurlencode($locale);
        $body = $this->http_get($url);
        if ($body === null || $body === '') {
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['checksums'])) {
            return null;
        }
        $raw = $json['checksums'];
        if (isset($raw[$version]) && is_array($raw[$version])) {
            $raw = $raw[$version];
        }
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $rel => $hash) {
            if (is_string($rel) && is_string($hash) && $hash !== '') {
                $out[str_replace('\\', '/', $rel)] = $hash;
            }
        }
        return $out === [] ? null : $out;
    }

    private function cache_path(string $version, string $locale): string {
        $dir = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'backups/'
            : dirname(__DIR__, 3) . '/backups/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $safe_v = preg_replace('/[^0-9a-z._-]/i', '_', $version);
        $safe_l = preg_replace('/[^0-9a-z._-]/i', '_', $locale);
        return $dir . 'wporg_checksums_' . $safe_v . '_' . $safe_l . '.json';
    }

    /**
     * @return array<string,string>|null
     */
    private function read_cache(string $version, string $locale): ?array {
        $file = $this->cache_path($version, $locale);
        if (!is_readable($file)) {
            return null;
        }
        if ((time() - (int) @filemtime($file)) > self::CACHE_TTL) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) && !empty($data) ? $data : null;
    }

    /**
     * @param array<string,string> $map
     */
    private function write_cache(string $version, string $locale, array $map): void {
        $file = $this->cache_path($version, $locale);
        @file_put_contents($file, json_encode($map));
    }

    private function http_get(string $url): ?string {
        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($url, [
                'timeout' => 10,
                'redirection' => 2,
                'sslverify' => true,
            ]);
            if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200) {
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
        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'follow_location' => 1],
            'ssl' => ['verify_peer' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : null;
    }
}
