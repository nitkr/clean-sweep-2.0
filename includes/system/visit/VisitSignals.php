<?php
/**
 * Last vuln scan + cron callback origins for automatic writer/entry ranking.
 */
final class CleanSweep_VisitSignals {

    /** Default window for restoring last vuln results in the UI (48h). */
    public const VULN_UI_TTL_SECONDS = 172800;

    /**
     * Persist last vuln scan: slim by_slug index for correlator + UI payload for dashboard restore.
     *
     * @param array $flat Normalized vulnerability items
     * @param array|null $ui UI payload (summary, vulnerabilities, groups, scanned_at)
     */
    public static function persist_vulns(array $flat, ?array $ui = null): void {
        $by = [];
        foreach ($flat as $v) {
            if (!is_array($v)) {
                continue;
            }
            $slug = (string) ($v['target_slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $cve = self::extract_cve($v);
            $by[$slug][] = [
                'risk' => strtolower((string) ($v['risk_level'] ?? 'medium')),
                'name' => (string) ($v['short_title'] ?? $v['name'] ?? 'vulnerability'),
                'cve' => $cve,
                'type' => (string) ($v['target_type'] ?? 'plugin'),
            ];
        }
        $path = self::vuln_latest_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = [
            'scanned_at' => time(),
            'by_slug' => $by,
        ];
        if (is_array($ui)) {
            // Keep correlator file usable; skip raw WPVulnerability blobs (core/plugins/themes).
            $payload['ui'] = [
                'summary' => $ui['summary'] ?? ['total' => count($flat)],
                'vulnerabilities' => $ui['vulnerabilities'] ?? $flat,
                'groups' => $ui['groups'] ?? [],
                'scanned_at' => (int) ($ui['scanned_at'] ?? time()),
            ];
        }
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Last UI payload for dashboard / scanner restore after refresh.
     * Returns null when missing, unreadable, or older than TTL.
     *
     * @return array|null
     */
    public static function latest_ui(int $ttl_seconds = self::VULN_UI_TTL_SECONDS): ?array {
        if ($ttl_seconds < 3600) {
            $ttl_seconds = 3600;
        }
        if ($ttl_seconds > 7 * 86400) {
            $ttl_seconds = 7 * 86400;
        }
        $data = self::read_vuln_latest();
        if ($data === null) {
            return null;
        }
        $scanned = (int) ($data['scanned_at'] ?? 0);
        if ($scanned > 0 && (time() - $scanned) > $ttl_seconds) {
            return null;
        }
        $ui = $data['ui'] ?? null;
        if (!is_array($ui)) {
            return null;
        }
        if (empty($ui['scanned_at']) && $scanned > 0) {
            $ui['scanned_at'] = $scanned;
        }
        return $ui;
    }

    /** @return array<string,array<int,array>> */
    public static function vulns_by_slug(): array {
        $data = self::read_vuln_latest();
        $by = $data['by_slug'] ?? [];
        return is_array($by) ? $by : [];
    }

    /** @return string */
    private static function vuln_latest_path(): string {
        $root = defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 2) . '/';
        return $root . 'backups/vuln_latest.json';
    }

    /** @return array|null */
    private static function read_vuln_latest(): ?array {
        $file = self::vuln_latest_path();
        if (!is_readable($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /** @param array $v */
    private static function extract_cve(array $v): string {
        $lists = [];
        if (!empty($v['primary_cves']) && is_array($v['primary_cves'])) {
            $lists[] = $v['primary_cves'];
        }
        if (!empty($v['source']) && is_array($v['source'])) {
            $lists[] = $v['source'];
        }
        if (!empty($v['sources']) && is_array($v['sources'])) {
            $lists[] = $v['sources'];
        }
        foreach ($lists as $list) {
            foreach ($list as $src) {
                if (!is_array($src)) {
                    continue;
                }
                $id = (string) ($src['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $kind = strtolower((string) ($src['kind'] ?? ''));
                if ($kind === 'cve' || preg_match('/^CVE-\d{4}-\d+/i', $id)) {
                    return $id;
                }
            }
        }
        return '';
    }

    /**
     * Schedule callback origins for writer ranking (file-level only).
     * Includes WP-Cron and Action Scheduler when present.
     *
     * @return array<int,array{hook:string,file:string,slug:?string,source:string}>
     */
    public static function cron_origins(): array {
        if (!class_exists('CleanSweep_CronAudit')) {
            $f = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 2) . '/')
                . 'features/security/cron-audit.php';
            if (is_readable($f)) {
                require_once $f;
            }
        }
        if (!class_exists('CleanSweep_CronAudit')) {
            return [];
        }
        try {
            $raw = (new CleanSweep_CronAudit())->audit();
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        $seen = [];

        $push = static function (string $hook, string $file, string $source) use (&$out, &$seen): void {
            $file = str_replace('\\', '/', $file);
            $key = strtolower($hook . '|' . $file . '|' . $source);
            if ($file === '' && $hook === '') {
                return;
            }
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $slug = null;
            if (preg_match('#(?:wp-content/)?plugins/([^/]+)#i', $file, $m)) {
                $slug = $m[1];
            } elseif (preg_match('#(?:wp-content/)?themes/([^/]+)#i', $file, $m)) {
                $slug = $m[1];
            } elseif (preg_match('#(?:wp-content/)?mu-plugins/#i', $file)) {
                $slug = null;
            }
            $out[] = [
                'hook' => $hook,
                'file' => $file,
                'slug' => $slug,
                'source' => $source,
            ];
        };

        foreach ($raw['wp_cron']['events'] ?? [] as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $hook = (string) ($ev['hook'] ?? '');
            foreach ($ev['callbacks'] ?? [] as $cb) {
                if (!is_array($cb)) {
                    continue;
                }
                $push($hook, (string) ($cb['file'] ?? ''), 'wp_cron');
            }
        }

        // Action Scheduler: same callback resolution as cron-audit (hook → callbacks)
        foreach ($raw['action_scheduler']['actions'] ?? [] as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $hook = (string) ($ev['hook'] ?? '');
            foreach ($ev['callbacks'] ?? [] as $cb) {
                if (!is_array($cb)) {
                    continue;
                }
                $push($hook, (string) ($cb['file'] ?? ''), 'action_scheduler');
            }
        }

        return $out;
    }
}
