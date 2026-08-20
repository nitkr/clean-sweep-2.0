<?php
/**
 * Persistent visit status for Slice 1: seals, snapshot flags, recent events.
 * Not the hostile-environment journal (Slice 2). Lives under backups/.
 */
final class CleanSweep_VisitState {

    private const MAX_EVENTS = 200;

    private string $path;

    public function __construct(?string $path = null) {
        $root = defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 2) . '/';
        if ($path) {
            $this->path = $path;
            return;
        }
        $dir = $root . 'backups/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $ptr = $dir . '.cs_visit_ptr';
        $name = is_readable($ptr) ? trim((string) @file_get_contents($ptr)) : '';
        if ($name === '' || !preg_match('/^cs_visit_[a-f0-9]{16}\.json$/', $name)) {
            $name = 'cs_visit_' . bin2hex(random_bytes(8)) . '.json';
            @file_put_contents($ptr, $name);
            $legacy = $dir . 'visit_state.json';
            $dest = $dir . $name;
            if (is_readable($legacy) && !is_readable($dest)) {
                @rename($legacy, $dest);
            }
        }
        $this->path = $dir . $name;
    }

    public function load(): array {
        $empty = $this->empty_state();
        if (!is_readable($this->path)) {
            return $empty;
        }
        $raw = @file_get_contents($this->path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return $empty;
        }
        return array_merge($empty, $data);
    }

    public static function request_key(): ?string {
        $h = (string) ($_SERVER['HTTP_X_CS_VISIT_KEY'] ?? '');
        if ($h === '') {
            $h = (string) ($_POST['visit_key'] ?? $_GET['visit_key'] ?? '');
        }
        $h = strtolower(preg_replace('/[^a-f0-9]/', '', $h) ?? '');
        return strlen($h) >= 16 ? $h : null;
    }

    public function save(array $state): bool {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $state['updated_at'] = time();
        $key = self::request_key();
        if ($key) {
            unset($state['_hmac'], $state['_worker_write']);
            $state['_hmac'] = $this->sign($state, $key);
            $state['_worker_write'] = false;
        } else {
            $state['_worker_write'] = true;
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return @file_put_contents($this->path, $json, LOCK_EX) !== false;
    }

    /**
     * Verify HMAC. Returns visit_key when this request minted a new one.
     *
     * @return array{tamper:bool,visit_key:?string,reason:?string}
     */
    public function verify_and_bind(): array {
        $state = $this->load();
        $key = self::request_key();
        $out = ['tamper' => false, 'visit_key' => null, 'reason' => null];

        if ($key === null && empty($state['_hmac'])) {
            $key = bin2hex(random_bytes(16));
            $out['visit_key'] = $key;
            $_SERVER['HTTP_X_CS_VISIT_KEY'] = $key;
            $this->save($state);
            return $out;
        }

        if ($key === null && !empty($state['_hmac'])) {
            // Drain/CLI have no browser key — not tamper.
            return $out;
        }

        $stored = (string) ($state['_hmac'] ?? '');
        $worker = !empty($state['_worker_write']);
        $expect = $this->sign($state, $key);
        if ($stored !== '' && !hash_equals($stored, $expect)) {
            if ($worker) {
                $this->save($state);
                return $out;
            }
            $out['tamper'] = true;
            $out['reason'] = 'hmac_mismatch';
            return $out;
        }

        if ($worker) {
            $this->save($state);
        }
        return $out;
    }

    private function sign(array $state, string $key): string {
        $canon = [
            'started_at' => $state['started_at'] ?? 0,
            'scopes' => $state['scopes'] ?? [],
            'samples' => $state['samples'] ?? [],
            'events' => $state['events'] ?? [],
            'options' => $state['options'] ?? [],
            'unexpected' => $state['unexpected'] ?? [],
            'census_ready' => $state['census_ready'] ?? [],
            'snapshot_downloaded' => !empty($state['snapshot_downloaded']),
            'snapshot_skipped' => !empty($state['snapshot_skipped']),
            'snapshot_imported' => !empty($state['snapshot_imported']),
        ];
        $json = json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash_hmac('sha256', (string) $json, $key);
    }

    public function event(string $code, string $detail = ''): void {
        $state = $this->load();
        $state['events'][] = [
            't' => time(),
            'code' => $code,
            'detail' => $detail,
            'text' => self::event_text($code, $detail),
        ];
        if (count($state['events']) > self::MAX_EVENTS) {
            $state['events'] = array_slice($state['events'], -self::MAX_EVENTS);
        }
        $this->save($state);
    }

    public static function event_text(string $code, string $detail = ''): string {
        $d = trim($detail);
        if (strpos($code, 'sealed:theme:') === 0) {
            $slug = substr($code, strlen('sealed:theme:'));
            return 'Trusted theme "' . $slug . '" after reinstall'
                . ($d !== '' ? ' (' . $d . ' hashed)' : '');
        }
        if (strpos($code, 'sealed:plugin:') === 0) {
            $slug = substr($code, strlen('sealed:plugin:'));
            return 'Trusted plugin "' . $slug . '" after reinstall'
                . ($d !== '' ? ' (' . $d . ' hashed)' : '');
        }
        switch ($code) {
            case 'self-check:ok':
                return 'Clean Sweep files match the copy that shipped in the zip';
            case 'self-check:extra':
                return 'Unexpected file inside Clean Sweep'
                    . ($d !== '' ? ': ' . $d : '') . '. Reinstall/export paused';
            case 'self-check:patched':
                return 'A shipped Clean Sweep file no longer matches the zip'
                    . ($d !== '' ? ': ' . $d : '');
            case 'self-check:no_manifest':
                return 'Clean Sweep hash list is missing; extras cannot be verified';
            case 'sealed:core':
                return 'Trusted WordPress core after reinstall'
                    . ($d !== '' ? ' (' . $d . ' hashed)' : '');
            case 'action:seal_core':
                return 'Core reinstall recorded in the visit journal'
                    . ($d !== '' ? ' (' . $d . ')' : '');
            case 'census:site-owned':
                return 'Watching site-owned files (wp-config, drop-ins, root extras)'
                    . ($d !== '' ? ': ' . $d : '');
            case 'census:extra-php':
                return 'Watching PHP files in plugins and themes'
                    . ($d !== '' ? ': ' . $d : '');
            case 'census:uploads':
                return 'Watching uploads (PHP, configs, PHP-in-images'
                    . ($d !== '' ? ': ' . $d . ' files)' : ')');
            case 'census:wp-content':
                return 'Watching other wp-content files (cache, upgrade, loose PHP)'
                    . ($d !== '' ? ': ' . $d : '');
            case 'census:options':
                return 'Recorded WordPress options for later compare'
                    . ($d !== '' ? ': ' . $d : '');
            case 'watch:enabled':
                return 'Live file watch enabled'
                    . ($d !== '' ? ' (' . $d . ')' : '')
                    . '. A must-use agent re-hashes high-value paths on normal requests';
            case 'watch:rebased':
                return 'Live watch pinned to the live WordPress site'
                    . ($d !== '' ? '. ' . $d : '')
                    . '. Recovery core/fresh is not watched';
            case 'watch:disabled':
                return 'Live file watch disabled; must-use agent removed';
            case 'watch:operation':
                return 'Live watch: expected site writes from Clean Sweep'
                    . ($d !== '' ? ' (' . $d . ')' : '');
            case 'watch:modified':
                return 'Live watch: file content changed'
                    . ($d !== '' ? ': ' . $d : '');
            case 'watch:created':
                return 'Live watch: new high-value file'
                    . ($d !== '' ? ': ' . $d : '');
            case 'watch:already_present':
                return 'Live watch: file was already on disk'
                    . ($d !== '' ? ': ' . $d : '');
            case 'watch:deleted':
                return 'Live watch: high-value file removed'
                    . ($d !== '' ? ': ' . $d : '');
            case 'snapshot:downloaded':
                return 'Snapshot downloaded. Save the one-time secret shown on this page'
                    . ($d !== '' ? '. ' . $d : '');
            case 'snapshot:imported':
                return 'Imported last snapshot and compared this site'
                    . ($d !== '' ? '. ' . $d : '');
            case 'snapshot:skipped':
                return 'Snapshot skipped. Later visits cannot compare to this one.';
            case 'media:all':
                return 'Uploads watch now includes all media files (large)';
            case 'media:suspects':
                return 'Uploads watch is PHP, configs, and PHP-in-images only';
            case 'watch:drift':
                return 'Watched files drifted since last visit'
                    . ($d !== '' ? ': ' . $d : '');
            case 'unexpected:created':
                return 'New file since last watch' . ($d !== '' ? ': ' . $d : '');
            case 'unexpected:modified':
                return 'File content changed since last watch' . ($d !== '' ? ': ' . $d : '');
            case 'unexpected:deleted':
            case 'unexpected:gone':
                return 'Watched file is gone' . ($d !== '' ? ': ' . $d : '');
            case 'unexpected:change':
                return 'Watched file changed' . ($d !== '' ? ': ' . $d : '');
            case 'unexpected:option':
                return 'WordPress option changed' . ($d !== '' ? ': ' . $d : '');
            default:
                if (strpos($code, 'unexpected:') === 0) {
                    return 'Unexpected change' . ($d !== '' ? ': ' . $d : '');
                }
                return $d !== '' ? $code . ': ' . $d : $code;
        }
    }

    public function merge(array $patch): array {
        $state = array_merge($this->load(), $patch);
        $this->save($state);
        return $state;
    }

    public function empty_state(): array {
        return [
            'started_at' => time(),
            'updated_at' => time(),
            'scopes' => [
                'core' => null,
                'site_owned' => null,
                'packages' => [],
            ],
            'snapshot_downloaded' => false,
            'snapshot_skipped' => false,
            'snapshot_imported' => false,
            'include_all_media' => true,
            'events' => [],
            'samples' => [],
            'actions' => [],
            'unexpected' => [],
            'options' => [],
            'likely_source' => null,
            'last_compare' => null,
            'journal_tamper' => false,
            'census_ready' => [],
        ];
    }

    public function status_payload(): array {
        $s = $this->load();
        $core = $s['scopes']['core'] ?? null;
        $packages = $s['scopes']['packages'] ?? [];
        $sealed_packages = [];
        foreach ($packages as $key => $meta) {
            if (!empty($meta['sealed'])) {
                $sealed_packages[] = $key;
            }
        }
        return [
            'toolkit' => null, // filled by caller
            'core_sealed' => is_array($core) && !empty($core['sealed']),
            'core_file_count' => is_array($core) ? (int) ($core['file_count'] ?? 0) : 0,
            'core_sealed_at' => is_array($core) ? ($core['sealed_at'] ?? null) : null,
            'site_owned_recorded' => !empty($s['scopes']['site_owned']),
            'packages_sealed' => $sealed_packages,
            'packages_sealed_count' => count($sealed_packages),
            'snapshot_downloaded' => !empty($s['snapshot_downloaded']),
            'snapshot_skipped' => !empty($s['snapshot_skipped']),
            'snapshot_imported' => !empty($s['snapshot_imported']),
            'include_all_media' => !empty($s['include_all_media']),
            'capabilities' => CleanSweep_VisitCapabilities::instance()->to_array(),
            'events' => $s['events'] ?? [],
            'likely_source' => is_array($s['likely_source'] ?? null) ? $s['likely_source'] : null,
            'last_compare' => is_array($s['last_compare'] ?? null) ? $s['last_compare'] : null,
            'journal_tamper' => !empty($s['journal_tamper']),
            'visit_watch' => !empty($s['scopes']['core']['sealed']) || !empty($s['samples']),
            'watch_counts' => [
                'site_owned' => is_array($s['samples']['site_owned'] ?? null) ? count($s['samples']['site_owned']) : 0,
                'extra_php' => is_array($s['samples']['extra_php'] ?? null) ? count($s['samples']['extra_php']) : 0,
                'uploads' => is_array($s['samples']['uploads'] ?? null) ? count($s['samples']['uploads']) : 0,
                'wp_content' => is_array($s['samples']['wp_content'] ?? null) ? count($s['samples']['wp_content']) : 0,
            ],
            // Live always-on agent (Phase 3) — filled/merged by caller if CleanSweep_VisitWatch available
            'live_watch_enabled' => !empty($s['watch']['enabled']),
            'live_watch_paths' => (int) ($s['watch']['stats']['paths']
                ?? (is_array($s['watch']['baselines'] ?? null) ? count($s['watch']['baselines']) : 0)),
            'live_watch_last_tick' => $s['watch']['last_tick'] ?? null,
            'live_watch_enabled_at' => $s['watch']['enabled_at'] ?? null,
            'live_watch_stats' => is_array($s['watch']['stats'] ?? null) ? $s['watch']['stats'] : [],
            'live_watch_events' => is_array($s['watch']['events'] ?? null)
                ? array_slice($s['watch']['events'], -30)
                : [],
        ];
    }
}

