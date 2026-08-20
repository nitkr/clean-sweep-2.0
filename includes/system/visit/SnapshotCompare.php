<?php
/**
 * Classify sealed-file diffs after import: official wordpress.org match = update.
 */
final class CleanSweep_SnapshotCompare {

    /**
     * @param array<int,array> $violations
     * @return array{updates:array,tamper:array,persistence:array}
     */
    public function classify(array $violations): array {
        $checksums = $this->official_core_checksums();
        $updates = [];
        $tamper = [];
        foreach ($violations as $v) {
            if (!is_array($v)) {
                continue;
            }
            $rel = (string) ($v['file'] ?? '');
            $scope = (string) ($v['scope'] ?? '');
            if ($scope === 'core' && $checksums && $rel !== '' && isset($checksums[$rel])) {
                $root = $this->site_root();
                $abs = $root . $rel;
                $md5 = (is_readable($abs) && function_exists('md5_file')) ? @md5_file($abs) : false;
                if (is_string($md5) && strcasecmp($md5, $checksums[$rel]) === 0) {
                    $updates[] = $v + ['class' => 'update'];
                    continue;
                }
            }
            $tamper[] = $v + ['class' => 'tamper'];
        }
        return [
            'updates' => $updates,
            'tamper' => $tamper,
            'persistence' => $this->persistence_diff(),
        ];
    }

    /**
     * New / changed / gone among watched (untrusted) hashes.
     * Sealed paths are omitted so they are not reported twice.
     *
     * @param array<string,array<string,mixed>> $previous
     * @param array<string,array<string,mixed>> $current
     * @return array{new:array,changed:array,gone:array,watched:int,previous:int}
     */
    public function drift(array $previous, array $current, ?array $scopes = null, array $buckets = ['site_owned', 'extra_php', 'uploads', 'wp_content']): array {
        $new = [];
        $changed = [];
        $gone = [];
        $prev_n = 0;
        $curr_n = 0;
        foreach ($buckets as $bucket) {
            $prev = $this->hash_map($previous[$bucket] ?? []);
            $curr = $this->hash_map($current[$bucket] ?? []);
            $prev_n += count($prev);
            $curr_n += count($curr);
            foreach ($curr as $path => $hash) {
                if ($this->is_sealed_watch_path($path, $scopes)) {
                    continue;
                }
                if (!isset($prev[$path])) {
                    $new[] = ['path' => $path, 'bucket' => $bucket, 'type' => 'created'];
                    continue;
                }
                if ($hash !== '' && $prev[$path] !== '' && !hash_equals($prev[$path], $hash)) {
                    $changed[] = ['path' => $path, 'bucket' => $bucket, 'type' => 'modified'];
                }
            }
            foreach ($prev as $path => $hash) {
                if ($this->is_sealed_watch_path($path, $scopes)) {
                    continue;
                }
                if (!isset($curr[$path])) {
                    $gone[] = ['path' => $path, 'bucket' => $bucket, 'type' => 'deleted'];
                }
            }
        }
        return [
            'new' => $new,
            'changed' => $changed,
            'gone' => $gone,
            'watched' => $curr_n,
            'previous' => $prev_n,
        ];
    }

    /** @param array<string,mixed> $entries */
    private function hash_map($entries): array {
        $out = [];
        if (!is_array($entries)) {
            return $out;
        }
        foreach ($entries as $path => $sample) {
            $path = (string) $path;
            if ($path === '') {
                continue;
            }
            if (is_array($sample)) {
                $out[$path] = (string) ($sample['hash'] ?? '');
            } elseif (is_string($sample)) {
                $out[$path] = $sample;
            }
        }
        return $out;
    }

    private function is_sealed_watch_path(string $path, ?array $scopes): bool {
        if (!is_array($scopes)) {
            return false;
        }
        $n = str_replace('\\', '/', ltrim($path, '/'));
        $core = $scopes['core'] ?? null;
        if (is_array($core) && !empty($core['sealed'])) {
            if (isset($core['files'][$n]) || isset($core['files'][$path])) {
                return true;
            }
        }
        $packages = $scopes['packages'] ?? [];
        if (!is_array($packages)) {
            return false;
        }
        foreach ($packages as $key => $pkg) {
            if (!is_array($pkg) || empty($pkg['sealed'])) {
                continue;
            }
            $prefix = '';
            if (strpos((string) $key, 'plugin:') === 0) {
                $prefix = 'wp-content/plugins/' . substr((string) $key, 7) . '/';
            } elseif (strpos((string) $key, 'theme:') === 0) {
                $prefix = 'wp-content/themes/' . substr((string) $key, 6) . '/';
            }
            if ($prefix !== '' && strpos($n, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    public function persistence_snapshot(): array {
        $admins = [];
        if (function_exists('get_users')) {
            foreach (get_users(['role' => 'administrator', 'fields' => ['ID', 'user_login', 'user_email']]) as $u) {
                $admins[] = [
                    'id' => (int) $u->ID,
                    'login' => (string) $u->user_login,
                    'email_hash' => md5((string) $u->user_email),
                ];
            }
        }
        $cron_hooks = [];
        if (function_exists('get_option')) {
            $cron = get_option('cron');
            if (is_array($cron)) {
                foreach ($cron as $ts => $events) {
                    if (!is_array($events)) {
                        continue;
                    }
                    foreach (array_keys($events) as $hook) {
                        $cron_hooks[] = (string) $hook;
                    }
                }
            }
        }
        $cron_hooks = array_values(array_unique($cron_hooks));
        sort($cron_hooks);
        return [
            'admins' => $admins,
            'cron_hooks' => $cron_hooks,
        ];
    }

    private function persistence_diff(): array {
        $data = (new CleanSweep_VisitState())->load();
        $prev = $data['persistence'] ?? null;
        $now = $this->persistence_snapshot();
        if (!is_array($prev)) {
            return ['new_admins' => [], 'new_cron' => [], 'note' => 'No prior persistence in snapshot'];
        }
        $prev_logins = [];
        foreach ($prev['admins'] ?? [] as $a) {
            $prev_logins[(string) ($a['login'] ?? '')] = true;
        }
        $new_admins = [];
        foreach ($now['admins'] as $a) {
            if (empty($prev_logins[$a['login']])) {
                $new_admins[] = $a['login'];
            }
        }
        $prev_cron = array_flip($prev['cron_hooks'] ?? []);
        $new_cron = [];
        foreach ($now['cron_hooks'] as $h) {
            if (!isset($prev_cron[$h])) {
                $new_cron[] = $h;
            }
        }
        return ['new_admins' => $new_admins, 'new_cron' => $new_cron];
    }

    /** @return array<string,string>|null */
    private function official_core_checksums(): ?array {
        $version = function_exists('clean_sweep_get_wordpress_version')
            ? clean_sweep_get_wordpress_version()
            : '';
        if ($version === '' || $version === 'unknown') {
            return null;
        }
        $dir = defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT . 'backups/' : dirname(__DIR__, 2) . '/backups/';
        foreach (glob($dir . 'wporg_checksums_' . preg_replace('/[^0-9a-z._-]/i', '_', $version) . '_*.json') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data) && $data) {
                return $data;
            }
        }
        return null;
    }

    private function site_root(): string {
        if (function_exists('clean_sweep_detect_site_root')) {
            return clean_sweep_detect_site_root();
        }
        return defined('ABSPATH') ? rtrim(str_replace('\\', '/', ABSPATH), '/') . '/' : '/';
    }
}
