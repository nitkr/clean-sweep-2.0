<?php
/**
 * Scan pre-boot override files (.htaccess, .user.ini, php.ini) at the
 * site root, wp-content, and wp-admin. These run before WordPress boots
 * and are not covered by the wp-content file walk.
 */
require_once dirname(__DIR__) . '/SitePaths.php';

final class CleanSweep_RootConfigWorker implements CleanSweep_Worker {

    public function type(): string {
        return CleanSweep_ScanWorkUnit::TYPE_ROOT_CONFIG;
    }

    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        $started = time();
        $paths = array_values(array_unique(array_merge(
            CleanSweep_SitePaths::root_override_files(),
            CleanSweep_SitePaths::root_php_files()
        )));
        if (empty($paths)) {
            return CleanSweep_WorkerResult::completed([
                'scanned' => 0,
                'note' => 'No root override files found',
                'duration_seconds' => time() - $started,
            ]);
        }

        require_once dirname(__DIR__, 2) . '/content-scanners/FileScanner.php';
        require_once dirname(__DIR__, 2) . '/ThreatCollector.php';
        if (!function_exists('clean_sweep_get_malware_signatures')) {
            require_once dirname(__DIR__, 2) . '/signatures.php';
        }

        $profile = $ctx->profile();
        $scanner = new CleanSweep_FileScanner($profile, $ctx->throttle());
        $scanner->set_signatures(clean_sweep_get_malware_signatures()->get_signatures());
        $collector = new CleanSweep_ThreatCollector(50);
        $scanner->set_collector($collector);
        $scanner->set_context($ctx);
        $collector->set_threat_store(new CleanSweep_ThreatStore($ctx->state()->scan_id));

        $sig = $scanner->scan_explicit_paths($paths);
        $heuristic_count = 0;

        foreach ($paths as $path) {
            $found = $this->heuristic_scan($path);
            foreach ($found as $threat) {
                $collector->add($threat);
                $heuristic_count++;
            }
        }

        $collector->flush();

        $sig_count = count($sig['threats'] ?? []);
        $total = $sig_count + $heuristic_count;
        $scanned = (int) ($sig['scanned'] ?? count($paths));

        $ctx->incrementCounter('files_scanned', $scanned);
        if ($scanned > 0) {
            $ctx->incrementCounter('files_visited', $scanned);
            require_once dirname(__DIR__) . '/ScannedPathStore.php';
            (new CleanSweep_ScannedPathStore($ctx->state()->scan_id))->appendMany($paths);
        }
        if ($total > 0) {
            $ctx->incrementCounter('threats_found', $total);
        }

        clean_sweep_log_message(
            "CleanSweep_RootConfigWorker: scanned {$scanned} override file(s), {$total} finding(s)",
            'info'
        );

        return CleanSweep_WorkerResult::completed([
            'scanned' => $scanned,
            'threats' => $total,
            'paths' => $paths,
            'duration_seconds' => time() - $started,
        ]);
    }

    /**
     * High-signal checks signatures often miss: auto_prepend_file and
     * RewriteRule / handler abuse.
     *
     * @return array<int,array>
     */
    private function heuristic_scan(string $path): array {
        if (!is_readable($path)) {
            return [];
        }
        $size = (int) @filesize($path);
        if ($size <= 0 || $size > 262144) {
            return [];
        }
        $content = (string) @file_get_contents($path);
        if ($content === '') {
            return [];
        }

        $base = basename($path);
        $is_htaccess = strcasecmp($base, '.htaccess') === 0;
        $threats = [];

        if (preg_match('/^\s*(?:php_value\s+)?auto_(?:prepend|append)_file\s*=\s*\S+/im', $content, $m)) {
            $threats[] = $this->finding(
                $path,
                'auto_prepend_file',
                $m[0],
                'auto_prepend_file / auto_append_file forces PHP to run before WordPress boots. Common persistence.',
                'critical',
                95
            );
        }

        if (!$is_htaccess) {
            return $threats;
        }

        if (preg_match('/(?:AddHandler|SetHandler|AddType)\s+[^\r\n]*(?:application\/x-httpd-php|php)[^\r\n]*\.(?:jpg|jpeg|png|gif|txt|ico|pdf|webp)/i', $content, $m)) {
            $threats[] = $this->finding(
                $path,
                'php_handler_on_asset',
                $m[0],
                'Apache is treating image/text extensions as PHP. Classic backdoor hide.',
                'critical',
                95
            );
        }

        if (preg_match('/RewriteRule\s+[^\r\n]*(uploads|wp-content\/cache|\/tmp\/|\/temp\/)[^\r\n]*\.php/i', $content, $m)) {
            $threats[] = $this->finding(
                $path,
                'rewrite_to_uploads_php',
                $m[0],
                'RewriteRule sends traffic to a PHP file under uploads/cache/tmp.',
                'critical',
                90
            );
        }

        if (preg_match('/RewriteCond\s+%\{HTTP_USER_AGENT\}[^\r\n]*(google|bing|yahoo|slurp|bot|facebook)/i', $content)
            && preg_match('/RewriteRule\s+/i', $content)) {
            $threats[] = $this->finding(
                $path,
                'ua_cloaking_rewrite',
                'RewriteCond HTTP_USER_AGENT + RewriteRule',
                'User-agent cloaking in .htaccess (bots vs humans). Often used for SEO spam or redirects.',
                'high',
                75
            );
        }

        return $threats;
    }

    private function finding(string $path, string $code, string $match, string $description, string $level, int $score): array {
        return [
            'id' => md5($path . '|' . $code . '|' . $match),
            'source' => 'file',
            'pattern' => $code,
            'signature_id' => $code,
            'category' => 'wp_specific',
            'match' => substr($match, 0, 160),
            'file' => $path,
            'line_number' => null,
            'open_in_editor' => $path,
            'content_preview' => substr($match, 0, 200),
            'matched_content' => $match,
            'description' => $description,
            'threat_level' => $level,
            'risk_level' => $level,
            'risk_score' => $score,
            'detected_at' => date('c'),
        ];
    }
}
