<?php
/**
 * Portable visit snapshot. HMAC key is generated at export and shown to the user.
 */
final class CleanSweep_Snapshot {

    private CleanSweep_VisitState $state;
    private CleanSweep_ScopeSealer $sealer;

    public function __construct(?CleanSweep_VisitState $state = null, ?CleanSweep_ScopeSealer $sealer = null) {
        $this->state = $state ?: new CleanSweep_VisitState();
        $this->sealer = $sealer ?: new CleanSweep_ScopeSealer($this->state);
    }

    /**
     * @return array{success:bool,filename?:string,data?:string,secret?:string,error?:string,scopes?:array}
     */
    public function export(): array {
        $tk = function_exists('clean_sweep_toolkit_integrity') ? clean_sweep_toolkit_integrity() : ['kind' => 'ok'];
        if (($tk['kind'] ?? 'ok') === 'patched' || ($tk['kind'] ?? 'ok') === 'extra') {
            return ['success' => false, 'error' => 'Refusing to export: Clean Sweep files were added or modified'];
        }
        $pin = $this->sealer->pin_for_snapshot();
        $state = $this->state->load();
        $persist = class_exists('CleanSweep_SnapshotCompare')
            ? (new CleanSweep_SnapshotCompare())->persistence_snapshot()
            : ['admins' => [], 'cron_hooks' => [], 'cron_events' => []];
        $all_media = !empty($state['include_all_media']);
        $watch = $this->hashes_only($this->collect_watch($all_media, false));
        $this->persist_watch_samples($watch);
        $payload = [
            'format' => 'clean-sweep-snapshot-v1',
            'exported_at' => time(),
            'host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'scopes' => $state['scopes'] ?? [],
            'include_all_media' => $all_media,
            'persistence' => $persist,
            'watch' => $watch,
            'extra_php' => $watch['extra_php'] ?? [],
            'options' => $state['options'] ?? [],
            'toolkit_self_check' => $tk['kind'] ?? 'ok',
            'pin_warnings' => $pin['warnings'] ?? [],
            'pin_warning_groups' => $pin['warning_groups'] ?? [],
            'baseline_kind' => ($state['scopes']['core']['origin'] ?? 'snapshot'),
        ];
        $secret = bin2hex(random_bytes(16));
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['success' => false, 'error' => 'Failed to encode snapshot'];
        }
        $envelope = [
            'payload' => $payload,
            'algorithm' => 'HMAC-SHA256',
            'signature' => hash_hmac('sha256', $json, $secret),
        ];
        // Compact JSON: a sealed core snapshot is ~1k files and must survive
        // download + re-upload. Pretty-print only inflates the form post.
        $out = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($out === false) {
            return ['success' => false, 'error' => 'Failed to encode envelope'];
        }
        $this->state->merge(['snapshot_downloaded' => true]);
        $wc = $this->watch_counts($watch);
        $this->state->event(
            'snapshot:downloaded',
            'pinned ' . (int) ($pin['file_count'] ?? 0)
            . ' files · watch extra-php ' . $wc['extra_php']
            . ', uploads ' . $wc['uploads']
            . ', site-owned ' . $wc['site_owned']
        );
        return [
            'success' => true,
            'filename' => 'clean-sweep-snapshot-' . date('Y-m-d-H-i-s') . '.json',
            'data' => $out,
            'secret' => $secret,
            'scopes' => $this->scope_summary($state),
            'pin_warnings' => $pin['warnings'] ?? [],
            'pin_warning_groups' => $pin['warning_groups'] ?? [],
            'pinned_file_count' => (int) ($pin['file_count'] ?? 0),
        ];
    }

    /**
     * @return array{success:bool,error?:string,scopes?:array}
     */
    public function import(string $json, string $secret): array {
        $json = preg_replace('/^\xEF\xBB\xBF/', '', ltrim($json)) ?? '';
        $env = json_decode($json, true);
        if (!is_array($env)) {
            $why = function_exists('json_last_error_msg') ? json_last_error_msg() : 'decode failed';
            $len = strlen($json);
            $hint = ($len > 0 && ($json[0] === '{' || $json[0] === '['))
                ? 'The file looks truncated or was altered in transit. Use the file picker, not paste.'
                : 'Choose the downloaded .json file.';
            return [
                'success' => false,
                'error' => 'Invalid snapshot JSON (' . $why . ', ' . $len . ' bytes). ' . $hint,
            ];
        }

        if (isset($env['payload']) && isset($env['signature'])) {
            if (trim($secret) === '') {
                return ['success' => false, 'error' => 'Snapshot secret is required'];
            }
            $canonical = json_encode($env['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $expect = hash_hmac('sha256', (string) $canonical, $secret);
            if (!hash_equals((string) $env['signature'], $expect)) {
                return ['success' => false, 'error' => 'Snapshot signature does not match the secret'];
            }
            $payload = $env['payload'];
        } elseif (isset($env['baseline'])) {
            if (empty($GLOBALS['clean_sweep_confirm_legacy_snapshot'])) {
                return ['success' => false, 'error' => 'Legacy unsigned baseline. Confirm import to continue.', 'needs_legacy_confirm' => true];
            }
            $payload = [
                'format' => 'legacy-baseline',
                'scopes' => [
                    'core' => [
                        'sealed' => true,
                        'sealed_at' => $env['baseline']['established_at'] ?? time(),
                        'files' => $env['baseline']['files'] ?? [],
                        'file_count' => isset($env['baseline']['files']) ? count($env['baseline']['files']) : 0,
                    ],
                ],
            ];
        } else {
            return ['success' => false, 'error' => 'Unrecognized snapshot format'];
        }

        $state = $this->state->load();
        $state['scopes'] = $payload['scopes'] ?? $state['scopes'];
        if (!empty($payload['persistence'])) {
            $state['persistence'] = $payload['persistence'];
        }
        $prev_watch = $this->watch_from_payload($payload);
        if (!isset($state['samples']) || !is_array($state['samples'])) {
            $state['samples'] = [];
        }
        foreach ($prev_watch as $bucket => $samples) {
            $state['samples'][$bucket] = $samples;
        }
        if (!empty($payload['options'])) {
            $state['options'] = $payload['options'];
        }
        if (isset($payload['include_all_media'])) {
            $state['include_all_media'] = !empty($payload['include_all_media']);
        }
        $state['snapshot_imported'] = true;
        $this->state->save($state);

        $violations = [];
        $compare = ['updates' => [], 'tamper' => [], 'package_churn' => [], 'persistence' => ['new_admins' => [], 'new_cron' => []]];
        $drift = ['new' => [], 'changed' => [], 'gone' => [], 'priority' => [], 'packages' => [], 'watched' => 0, 'previous' => 0];
        $skip_pkgs = [];
        foreach ($payload['scopes']['packages'] ?? [] as $pk => $pkg) {
            if (is_array($pkg) && (!empty($pkg['pinned']) || !empty($pkg['sealed']))) {
                $skip_pkgs[] = (string) $pk;
            }
        }
        $curr_watch = $this->collect_watch(!empty($payload['include_all_media']), true, $skip_pkgs);
        if (class_exists('CleanSweep_SnapshotCompare')) {
            $cmp = new CleanSweep_SnapshotCompare();
            if (class_exists('CleanSweep_ScopeSealer')) {
                $violations = (new CleanSweep_ScopeSealer($this->state))->compare_sealed();
                $compare = $cmp->classify($violations);
            }
            $drift_buckets = ['site_owned', 'extra_php'];
            if (isset($payload['watch'])) {
                $drift_buckets[] = 'uploads';
                if (array_key_exists('wp_content', $payload['watch'])) {
                    $drift_buckets[] = 'wp_content';
                }
            }
            $drift = $cmp->drift($prev_watch, $curr_watch, $payload['scopes'] ?? [], $drift_buckets);
        }
        $store = new CleanSweep_VisitStore($this->state);
        foreach (array_merge($drift['priority'] ?? [], $drift['changed'] ?? [], $drift['new'] ?? [], $drift['gone'] ?? []) as $item) {
            $path = (string) ($item['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $store->add_unexpected([
                [
                    'path' => $path,
                    'reason' => $item['type'] ?? 'change',
                    'sample' => $curr_watch[$item['bucket'] ?? ''][$path] ?? [],
                ],
            ]);
        }
        $cmp_paths = class_exists('CleanSweep_SnapshotCompare') ? new CleanSweep_SnapshotCompare() : null;
        foreach ($compare['tamper'] ?? [] as $v) {
            if (!is_array($v)) {
                continue;
            }
            $path = (string) ($v['file'] ?? $v['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $store->add_unexpected([
                [
                    'path' => $path,
                    'reason' => $v['type'] ?? 'change',
                    'sample' => [],
                ],
            ]);
        }
        $payload_paths = $cmp_paths
            ? $cmp_paths->persistence_payload_paths($compare['tamper'] ?? [], $drift)
            : [];
        $persist_violations = [];
        foreach ($compare['tamper'] ?? [] as $v) {
            if (!is_array($v)) {
                continue;
            }
            $path = (string) ($v['file'] ?? $v['path'] ?? '');
            $scope = (string) ($v['scope'] ?? '');
            if ($path === '') {
                continue;
            }
            // Created always-on is the persistence payload, not the writer.
            if (($v['type'] ?? '') === 'created'
                && $cmp_paths && $cmp_paths->is_always_on_path($path, $scope)) {
                $persist_violations[] = $v;
            }
        }
        $likely = null;
        if (class_exists('CleanSweep_Correlator')) {
            // Modified theme/plugin bootstrap stays out of payload_keys so it can
            // be named as a writer even when no new mu-plugin appeared.
            $likely = (new CleanSweep_Correlator($store))->run($persist_violations, [], $payload_paths);
            if (is_array($likely) && empty($likely['writer']) && $cmp_paths) {
                $fb = $this->fallback_compare_writer($compare['tamper'] ?? [], $cmp_paths);
                if ($fb !== null) {
                    $likely = array_merge($likely, $fb);
                    $store->set_likely_source($likely);
                }
            }
        }
        $compare['drift'] = $drift;
        $compare['likely_source'] = $likely;
        $compare = $this->compare_summary($payload, $violations, $compare);
        $this->state->merge(['last_compare' => $compare]);
        $this->state->event(
            'snapshot:imported',
            $this->import_event_detail($compare)
        );

        return [
            'success' => true,
            'scopes' => $this->scope_summary($state),
            'compare' => $compare,
        ];
    }

    /**
     * Counts the UI can show even when every file still matches.
     *
     * @param array<int,array> $violations
     * @param array $compare
     */
    private function compare_summary(array $payload, array $violations, array $compare): array {
        $compared = 0;
        $core = $payload['scopes']['core'] ?? null;
        if (is_array($core) && !empty($core['files']) && is_array($core['files'])) {
            $compared += count($core['files']);
        } elseif (is_array($core)) {
            $compared += (int) ($core['file_count'] ?? 0);
        }
        $owned = $payload['scopes']['site_owned']['files'] ?? [];
        if (is_array($owned)) {
            $compared += count($owned);
        }
        $mu = $payload['scopes']['mu_plugins']['files'] ?? [];
        if (is_array($mu)) {
            $compared += count($mu);
        }
        $up = $payload['scopes']['uploads_exec']['files'] ?? [];
        if (is_array($up)) {
            $compared += count($up);
        }
        foreach (($payload['scopes']['packages'] ?? []) as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            if (!empty($pkg['files']) && is_array($pkg['files'])) {
                $compared += count($pkg['files']);
            } else {
                $compared += (int) ($pkg['file_count'] ?? 0);
            }
        }
        $changed = 0;
        $missing = 0;
        foreach ($violations as $v) {
            if (!is_array($v)) {
                continue;
            }
            if (($v['type'] ?? '') === 'deleted') {
                $missing++;
            } else {
                $changed++;
            }
        }
        $persist = is_array($compare['persistence'] ?? null) ? $compare['persistence'] : [];
        $drift = is_array($compare['drift'] ?? null) ? $compare['drift'] : [];
        $priority_hits = count($drift['priority'] ?? []);
        $churn = $compare['package_churn'] ?? [];
        $tamper = $compare['tamper'] ?? [];
        $file_problems = count($tamper);
        $persist_problems = !empty($persist['new_admins']) || !empty($persist['new_cron']);
        $compare['compared'] = $compared;
        $compare['matched'] = max(0, $compared - count($violations));
        $compare['changed'] = $changed;
        $compare['missing'] = $missing;
        $compare['exported_at'] = $payload['exported_at'] ?? null;
        $compare['host'] = $payload['host'] ?? null;
        $compare['baseline_kind'] = $payload['baseline_kind'] ?? ($payload['scopes']['core']['origin'] ?? null);
        $compare['watch_counts'] = $this->watch_counts($this->watch_from_payload($payload));
        $groups = $payload['pin_warning_groups'] ?? null;
        if (!is_array($groups) || $groups === []) {
            $groups = class_exists('CleanSweep_SnapshotCompare')
                ? CleanSweep_SnapshotCompare::pin_warning_groups_from_list(
                    is_array($payload['pin_warnings'] ?? null) ? $payload['pin_warnings'] : []
                )
                : [];
        }
        $compare['pin_context'] = [
            'root_php' => count($groups['root_php'] ?? []),
            'mu_plugins' => count($groups['mu_plugins'] ?? []),
            'wp_content' => count($groups['wp_content'] ?? []),
            'uploads_exec' => count($groups['uploads_exec'] ?? []),
            'bootstrap' => count($groups['bootstrap'] ?? []),
            'site_owned' => count($groups['site_owned'] ?? []),
            'groups' => $groups,
        ];
        $compare['file_clean'] = $file_problems === 0 && $priority_hits === 0;
        $compare['persist_clean'] = !$persist_problems;
        $compare['sealed_clean'] = $file_problems === 0;
        $compare['clean'] = !empty($compare['file_clean']) && !empty($compare['persist_clean'])
            && $churn === [];
        return $compare;
    }

    /**
     * Name a writer from compare rows when correlator had no bootstrap hit:
     * packed PHP first (any path), else the only changed PHP file, else a package-root PHP.
     *
     * @param array<int,array> $tamper
     * @return array{writer:array,writer_is_payload:bool,confidence:string,summary:string}|null
     */
    private function fallback_compare_writer(array $tamper, CleanSweep_SnapshotCompare $cmp): ?array {
        $php = [];
        $packed = [];
        $bootstrap = [];
        foreach ($tamper as $v) {
            if (!is_array($v) || ($v['type'] ?? '') === 'deleted') {
                continue;
            }
            $path = (string) ($v['file'] ?? $v['path'] ?? '');
            $scope = (string) ($v['scope'] ?? '');
            if ($path === '' || !$this->path_is_php($path)) {
                continue;
            }
            $php[] = $v;
            if ($cmp->is_bootstrap_path($path, $scope)) {
                $bootstrap[] = $v;
            }
            if ($this->path_looks_packed($path)) {
                $packed[] = $v;
            }
        }
        $pick = null;
        $why = '';
        $evidence = ['package_entrypoint'];
        $score = 62;
        if ($packed !== []) {
            foreach ($packed as $v) {
                if ($cmp->is_bootstrap_path((string) ($v['file'] ?? ''), (string) ($v['scope'] ?? ''))) {
                    $pick = $v;
                    break;
                }
            }
            $pick = $pick ?: $packed[0];
            $why = 'Packed PHP changed since the snapshot';
            $evidence = ['signature'];
            $score = 74;
        } elseif (count($php) === 1) {
            $pick = $php[0];
            $why = 'Only PHP file that changed since the snapshot';
        } elseif ($bootstrap !== []) {
            $pick = $bootstrap[0];
            $why = 'Plugin/theme root PHP changed since the snapshot';
        }
        if ($pick === null) {
            return null;
        }
        $path = (string) ($pick['file'] ?? $pick['path'] ?? '');
        return [
            'writer' => [
                'path' => $path,
                'why' => $why,
                'evidence' => $evidence,
                'role' => 'writer',
                'score' => $score,
            ],
            'writer_is_payload' => false,
            'confidence' => $packed !== [] ? 'high' : 'medium',
            'summary' => 'Likely writer: ' . $path . ' (' . $why . ')',
        ];
    }

    private function path_is_php(string $path): bool {
        return (bool) preg_match('/\.(?:php\d*|phtml|phar)$/i', str_replace('\\', '/', $path));
    }

    private function path_looks_packed(string $rel): bool {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $root = function_exists('clean_sweep_detect_site_root')
            ? clean_sweep_detect_site_root()
            : (defined('ABSPATH') ? rtrim(str_replace('\\', '/', ABSPATH), '/') . '/' : '');
        $abs = $root . $rel;
        if ($abs === '' || !is_readable($abs) || is_dir($abs)) {
            return false;
        }
        $fh = @fopen($abs, 'rb');
        if (!$fh) {
            return false;
        }
        $size = (int) @filesize($abs);
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
        return (bool) preg_match(
            '/\bgzinflate\s*\(|\bgzdecode\s*\(|\bgzuncompress\s*\(|\beval\s*\(|create_function\s*\(|auto_prepend_file/i',
            $blob
        );
    }

    /** @return array<string,array<string,array{hash:?string}>> */
    private function collect_watch(bool $all_media, bool $include_extra_php = true, array $skip_package_keys = []): array {
        if (!class_exists('CleanSweep_Census')) {
            return ['site_owned' => [], 'extra_php' => [], 'uploads' => [], 'wp_content' => []];
        }
        return (new CleanSweep_Census(new CleanSweep_VisitStore($this->state)))
            ->collect_watch($all_media, $include_extra_php, $skip_package_keys);
    }

    /** @param array<string,array<string,mixed>> $watch */
    private function persist_watch_samples(array $watch): void {
        $store = new CleanSweep_VisitStore($this->state);
        foreach ($watch as $bucket => $samples) {
            if (is_array($samples)) {
                $store->put_samples((string) $bucket, $samples, true);
            }
        }
    }

    /**
     * @param array<string,mixed> $watch
     * @return array<string,array<string,array{hash:?string}>>
     */
    private function hashes_only(array $watch): array {
        $out = ['site_owned' => [], 'extra_php' => [], 'uploads' => [], 'wp_content' => []];
        foreach ($out as $bucket => $_) {
            foreach (($watch[$bucket] ?? []) as $path => $sample) {
                $hash = is_array($sample) ? ($sample['hash'] ?? null) : $sample;
                $out[$bucket][(string) $path] = ['hash' => $hash];
                if (is_array($sample) && !empty($sample['php_in_image'])) {
                    $out[$bucket][(string) $path]['php_in_image'] = true;
                }
            }
        }
        return $out;
    }

    /** @return array{site_owned:array,extra_php:array,uploads:array} */
    private function watch_from_payload(array $payload): array {
        if (!empty($payload['watch']) && is_array($payload['watch'])) {
            return $this->hashes_only($payload['watch']);
        }
        $watch = ['site_owned' => [], 'extra_php' => [], 'uploads' => [], 'wp_content' => []];
        foreach (($payload['extra_php'] ?? []) as $path => $sample) {
            $watch['extra_php'][(string) $path] = [
                'hash' => is_array($sample) ? ($sample['hash'] ?? null) : $sample,
            ];
        }
        foreach (($payload['scopes']['site_owned']['files'] ?? []) as $path => $sample) {
            $watch['site_owned'][(string) $path] = [
                'hash' => is_array($sample) ? ($sample['hash'] ?? null) : null,
            ];
        }
        return $watch;
    }

    /** @param array<string,array> $watch */
    private function watch_counts(array $watch): array {
        return [
            'site_owned' => count($watch['site_owned'] ?? []),
            'extra_php' => count($watch['extra_php'] ?? []),
            'uploads' => count($watch['uploads'] ?? []),
            'wp_content' => count($watch['wp_content'] ?? []),
        ];
    }

    private function import_event_detail(array $compare): string {
        $drift = $compare['drift'] ?? [];
        $n = count($drift['changed'] ?? []);
        $c = count($drift['new'] ?? []);
        $g = count($drift['gone'] ?? []);
        $sealed = (int) ($compare['compared'] ?? 0);
        $bits = ['baseline ' . $sealed];
        $prio = count($drift['priority'] ?? []);
        if ($prio) {
            $bits[] = $prio . ' high-value';
        }
        if ($n || $c || $g) {
            $bits[] = 'watch ' . $n . ' changed, ' . $c . ' new, ' . $g . ' gone';
        } else {
            $bits[] = 'watch unchanged (' . (int) ($drift['watched'] ?? 0) . ' files)';
        }
        return implode(' · ', $bits);
    }

    public function scope_summary(?array $state = null): array {
        $state = $state ?: $this->state->load();
        $core = $state['scopes']['core'] ?? null;
        $packages = $state['scopes']['packages'] ?? [];
        $sealed = [];
        $pinned = [];
        foreach ($packages as $k => $p) {
            if (!empty($p['sealed'])) {
                $sealed[] = $k;
            }
            if (!empty($p['pinned'])) {
                $pinned[] = $k;
            }
        }
        $core_pinned = is_array($core) && !empty($core['pinned']);
        $core_sealed = is_array($core) && !empty($core['sealed']);
        return [
            'core_sealed' => $core_sealed,
            'core_pinned' => $core_pinned,
            'core_origin' => is_array($core) ? ($core['origin'] ?? null) : null,
            'core_file_count' => is_array($core) ? (int) ($core['file_count'] ?? 0) : 0,
            'packages_sealed' => $sealed,
            'packages_pinned' => $pinned,
            'packages_sealed_count' => count($sealed),
            'packages_pinned_count' => count($pinned),
            'site_owned' => !empty($state['scopes']['site_owned']),
            'not_sealed' => $this->not_sealed_message($core, $sealed, $core_pinned, count($pinned)),
        ];
    }

    private function not_sealed_message($core, array $sealed, bool $core_pinned, int $pinned_n): string {
        if ($core_pinned || $pinned_n > 0) {
            $kind = (is_array($core) && ($core['origin'] ?? '') === 'reinstall')
                ? 'Core reinstall-sealed'
                : 'Baseline pinned on snapshot download';
            return $kind . '. Next visit, import this file to see what changed. Unchanged is not the same as clean.';
        }
        $bits = [];
        if (!(is_array($core) && !empty($core['sealed']))) {
            $bits[] = 'WordPress core';
        }
        $bits[] = 'plugins/themes not listed as sealed';
        $bits[] = 'mu-plugins';
        if ($sealed) {
            return 'Sealed packages: ' . implode(', ', $sealed) . '. Still untrusted: ' . implode(', ', $bits);
        }
        return 'Not sealed: ' . implode(', ', $bits) . '. Download a snapshot to pin current hashes.';
    }
}
