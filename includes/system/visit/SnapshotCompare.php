<?php
/**
 * Classify sealed/pinned diffs after import: official wordpress.org match = update.
 * Package vendor churn is rolled up so always-on and bootstrap files stay visible.
 */
final class CleanSweep_SnapshotCompare {

    /**
     * @param array<int,array> $violations
     * @return array{updates:array,tamper:array,package_churn:array,persistence:array}
     */
    public function classify(array $violations): array {
        $checksums = $this->official_core_checksums();
        $updates = [];
        $tamper = [];
        $by_pkg = [];
        foreach ($violations as $v) {
            if (!is_array($v)) {
                continue;
            }
            $rel = (string) ($v['file'] ?? '');
            $scope = (string) ($v['scope'] ?? '');
            $type = (string) ($v['type'] ?? '');
            if ($scope === 'core' && $checksums && $rel !== '' && isset($checksums[$rel])) {
                $root = $this->site_root();
                $abs = $root . $rel;
                $md5 = (is_readable($abs) && function_exists('md5_file')) ? @md5_file($abs) : false;
                if (is_string($md5) && strcasecmp($md5, $checksums[$rel]) === 0) {
                    $updates[] = $v + ['class' => 'update'];
                    continue;
                }
            }
            if (strpos($scope, 'plugin:') === 0 && $this->plugin_file_matches_org($scope, $rel)) {
                $updates[] = $v + ['class' => 'update'];
                continue;
            }
            if ($type === 'deleted' && strpos($scope, 'plugin:') === 0
                && $this->plugin_delete_is_official_removal($scope, $rel)) {
                $updates[] = $v + ['class' => 'update', 'update_kind' => 'org_removed'];
                continue;
            }
            if (!empty($v['new_package']) || $this->is_high_value_path($rel, $scope)) {
                $tamper[] = $v + ['class' => 'tamper'];
                continue;
            }
            if (strpos($scope, 'plugin:') === 0 || strpos($scope, 'theme:') === 0) {
                $by_pkg[$scope][] = $v;
                continue;
            }
            $tamper[] = $v + ['class' => 'tamper'];
        }
        $churn = [];
        foreach ($by_pkg as $key => $rows) {
            // Few files in one package: list each (malware often edits one helper, not the whole tree).
            if (count($rows) <= 12) {
                foreach ($rows as $row) {
                    $tamper[] = $row + ['class' => 'tamper'];
                }
                continue;
            }
            $changed = 0;
            $created = 0;
            $deleted = 0;
            foreach ($rows as $row) {
                $t = (string) ($row['type'] ?? 'modified');
                $n = (int) ($row['count'] ?? 1);
                if ($n < 1) {
                    $n = 1;
                }
                if ($t === 'created') {
                    $created += $n;
                } elseif ($t === 'deleted') {
                    $deleted += $n;
                } else {
                    $changed += $n;
                }
            }
            if ($changed + $created + $deleted === 0) {
                continue;
            }
            $churn[] = [
                'file' => $key,
                'path' => $key,
                'type' => 'package_churn',
                'scope' => $key,
                'class' => 'churn',
                'count' => $changed + $created + $deleted,
                'changed' => $changed,
                'new' => $created,
                'gone' => $deleted,
            ];
        }
        foreach ($tamper as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $tamper[$i]['tamper_group'] = $this->tamper_group($row);
        }
        usort($tamper, function ($a, $b) {
            $order = ['bootstrap' => 0, 'always_on' => 1, 'new_php' => 2, 'new_core_php' => 2, 'other' => 3];
            $ga = $order[(string) ($a['tamper_group'] ?? 'other')] ?? 3;
            $gb = $order[(string) ($b['tamper_group'] ?? 'other')] ?? 3;
            if ($ga !== $gb) {
                return $ga <=> $gb;
            }
            return strcasecmp((string) ($a['file'] ?? $a['path'] ?? ''), (string) ($b['file'] ?? $b['path'] ?? ''));
        });
        return [
            'updates' => $updates,
            'tamper' => $tamper,
            'package_churn' => $churn,
            'persistence' => $this->persistence_diff(),
        ];
    }

    /**
     * New / changed / gone among watched (untrusted) hashes.
     * Sealed/pinned paths are omitted so they are not reported twice.
     *
     * @param array<string,array<string,mixed>> $previous
     * @param array<string,array<string,mixed>> $current
     * @return array{new:array,changed:array,gone:array,priority:array,packages:array,watched:int,previous:int}
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
        $split = $this->split_drift_rows($changed, $new, $gone);
        return [
            'new' => $new,
            'changed' => $changed,
            'gone' => $gone,
            'priority' => $split['priority'],
            'packages' => $split['packages'],
            'watched' => $curr_n,
            'previous' => $prev_n,
        ];
    }

    /**
     * Paths correlator may treat as the finding (persistence), not the writer.
     *
     * @param array<int,array> $tamper
     * @param array $drift
     * @return array<int,string>
     */
    public function persistence_payload_paths(array $tamper, array $drift): array {
        $out = [];
        foreach ($tamper as $v) {
            if (!is_array($v)) {
                continue;
            }
            $path = (string) ($v['file'] ?? $v['path'] ?? '');
            $scope = (string) ($v['scope'] ?? '');
            $type = (string) ($v['type'] ?? '');
            if ($path === '') {
                continue;
            }
            if ($type === 'created' && $this->is_always_on_path($path, $scope)) {
                $out[] = $path;
            }
        }
        foreach ($drift['priority'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = (string) ($row['path'] ?? '');
            if ($path !== '' && ($row['type'] ?? '') === 'created' && $this->is_always_on_path($path, (string) ($row['bucket'] ?? ''))) {
                $out[] = $path;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Deleted plugin file that the installed wordpress.org zip no longer ships.
     */
    private function plugin_delete_is_official_removal(string $scope, string $file): bool {
        $slug = substr($scope, 7);
        $rel = $this->package_rel($file, $scope);
        if ($slug === '' || $rel === '') {
            return false;
        }
        $version = $this->installed_plugin_version($slug);
        if ($version === '') {
            return false;
        }
        $map = $this->official_plugin_map($slug, $version);
        if (!is_array($map) || $map === []) {
            return false;
        }
        return !isset($map[$rel]);
    }

    /**
     * Split high-value rows so bootstrap edits are not mixed with vendor leftovers.
     *
     * @param array<string,mixed> $v
     */
    public function tamper_group(array $v): string {
        $path = (string) ($v['file'] ?? $v['path'] ?? '');
        $scope = (string) ($v['scope'] ?? '');
        $type = (string) ($v['type'] ?? '');
        if ($this->is_bootstrap_path($path, $scope)) {
            return 'bootstrap';
        }
        if ($this->is_always_on_path($path, $scope)) {
            return 'always_on';
        }
        if ($type === 'created') {
            return 'new_php';
        }
        return 'other';
    }

    public function is_bootstrap_path(string $path, string $scope = ''): bool {
        $n = str_replace('\\', '/', strtolower($path));
        if (preg_match('#(?:^|/)themes/[^/]+/[^/]+\.(?:php\d*|phtml|phar)$#', $n)) {
            return true;
        }
        if (preg_match('#(?:^|/)plugins/[^/]+\.(?:php\d*|phtml|phar)$#', $n)) {
            return true;
        }
        if (strpos($scope, 'theme:') === 0) {
            $rel = $this->package_rel($path, $scope);
            return $rel !== '' && strpos($rel, '/') === false
                && (bool) preg_match('/\.(?:php\d*|phtml|phar)$/i', $rel);
        }
        if (strpos($scope, 'plugin:') === 0) {
            $rel = $this->package_rel($path, $scope);
            $slug = strtolower(substr($scope, 7));
            if ($rel === '' || strpos($rel, '/') !== false) {
                return false;
            }
            $base = strtolower($rel);
            return $base === $slug || $base === $slug . '.php' || (bool) preg_match('/\.(?:php\d*|phtml|phar)$/i', $rel);
        }
        return false;
    }

    private function is_root_extra_rel(string $path): bool {
        $n = str_replace('\\', '/', ltrim($path, '/'));
        return $n !== '' && strpos($n, '/') === false
            && (bool) preg_match('/\.(?:php\d*|phtml|phar)$/i', $n);
    }

    /**
     * Rebuild grouped pin warnings from a flat list (older snapshots).
     *
     * @param array<int,string> $warnings
     * @return array{site_owned:array,mu_plugins:array,root_php:array,bootstrap:array}
     */
    public static function pin_warning_groups_from_list(array $warnings): array {
        $out = [
            'site_owned' => [],
            'mu_plugins' => [],
            'root_php' => [],
            'wp_content' => [],
            'uploads_exec' => [],
            'bootstrap' => [],
        ];
        $always = [
            '.user.ini', 'wp-content/db.php', '.htaccess', 'php.ini', 'web.config',
            'wp-content/object-cache.php', 'wp-content/advanced-cache.php', 'wp-content/sunrise.php',
        ];
        foreach ($warnings as $w) {
            $w = (string) $w;
            if ($w === '') {
                continue;
            }
            if (strpos($w, 'wp-content/mu-plugins/') === 0) {
                $out['mu_plugins'][] = $w;
                continue;
            }
            if (strpos($w, 'wp-content/uploads/') === 0) {
                $out['uploads_exec'][] = $w;
                continue;
            }
            if (preg_match('#^wp-content/[^/]+$#', $w)) {
                $out['wp_content'][] = $w;
                continue;
            }
            if (stripos($w, 'suspicious') !== false || stripos($w, 'unusually large') !== false
                || strpos($w, ' (') !== false) {
                $out['bootstrap'][] = $w;
                continue;
            }
            if (in_array($w, $always, true)) {
                $out['site_owned'][] = $w;
                continue;
            }
            $out['root_php'][] = $w;
        }
        return $out;
    }

    public function is_high_value_path(string $path, string $scope = ''): bool {
        $n = str_replace('\\', '/', strtolower($path));
        $base = basename($n);
        if (in_array($base, [
            '.user.ini', '.htaccess', 'php.ini', 'web.config',
            'db.php', 'object-cache.php', 'advanced-cache.php', 'sunrise.php', 'wp-config.php',
        ], true)) {
            return true;
        }
        if ($scope === 'site_owned' || $scope === 'mu_plugins' || $scope === 'uploads_exec'
            || strpos($n, 'mu-plugins/') !== false) {
            return true;
        }
        if (preg_match('#(?:^|/)wp-content/(?:plugins|themes)/[^/]+/?$#', $n)) {
            return true;
        }
        if (preg_match('#^wp-content/[^/]+\.(?:php\d*|phtml|phar)$#', $n)
            || $n === 'wp-content/.htaccess' || $n === 'wp-content/.user.ini') {
            return true;
        }
        if (strpos($n, 'wp-content/uploads/') === 0 && (
            (bool) preg_match('/\.(?:php\d*|phtml|phar)(?:\.|$)/i', $n)
            || substr($n, -9) === '.htaccess' || substr($n, -9) === '.user.ini'
        )) {
            return true;
        }
        if (preg_match('#(?:^|/)themes/[^/]+/[^/]+\.(?:php\d*|phtml|phar)$#', $n)) {
            return true;
        }
        if (preg_match('#(?:^|/)plugins/[^/]+\.(?:php\d*|phtml|phar)$#', $n)) {
            return true;
        }
        if (preg_match('#(?:^|/)plugins/([^/]+)/([^/]+)\.php$#', $n, $m) && substr_count($n, '/') <= 4) {
            return true;
        }
        if (strpos($scope, 'theme:') === 0 || strpos($scope, 'plugin:') === 0) {
            $pkg_rel = $this->package_rel($path, $scope);
            if ($pkg_rel !== '' && strpos($pkg_rel, '/') === false
                && (bool) preg_match('/\.(?:php\d*|phtml|phar)$/i', $pkg_rel)) {
                return true;
            }
        }
        return false;
    }

    public function is_vendor_path(string $path): bool {
        $p = str_replace('\\', '/', strtolower($path));
        return (bool) preg_match('#/(vendor|vendor_prefixed|lib/packages|node_modules|third[-_]?party)/#', $p);
    }

    public function is_always_on_path(string $path, string $scope = ''): bool {
        $n = str_replace('\\', '/', strtolower($path));
        $base = basename($n);
        if ($scope === 'mu_plugins' || strpos($n, 'mu-plugins/') !== false) {
            return true;
        }
        return in_array($base, [
            '.user.ini', '.htaccess', 'php.ini', 'web.config',
            'db.php', 'object-cache.php', 'advanced-cache.php', 'sunrise.php',
            'wp-config.php',
        ], true);
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

    /**
     * @param array<int,array> $changed
     * @param array<int,array> $new
     * @param array<int,array> $gone
     * @return array{priority:array,packages:array}
     */
    private function split_drift_rows(array $changed, array $new, array $gone): array {
        $priority = [];
        $by_pkg = [];
        foreach (['changed' => $changed, 'new' => $new, 'gone' => $gone] as $kind => $rows) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $path = (string) ($row['path'] ?? '');
                $bucket = (string) ($row['bucket'] ?? '');
                if ($this->is_high_value_path($path, $bucket)) {
                    $priority[] = $row + ['priority' => true];
                    continue;
                }
                $slug = $this->plugin_theme_key($path);
                if ($slug !== '') {
                    if (!isset($by_pkg[$slug])) {
                        $by_pkg[$slug] = ['changed' => 0, 'new' => 0, 'gone' => 0];
                    }
                    $by_pkg[$slug][$kind === 'changed' ? 'changed' : ($kind === 'new' ? 'new' : 'gone')]++;
                    continue;
                }
                $priority[] = $row;
            }
        }
        $packages = [];
        foreach ($by_pkg as $slug => $counts) {
            $packages[] = [
                'slug' => $slug,
                'path' => $slug,
                'type' => 'package_churn',
                'class' => 'churn',
                'changed' => $counts['changed'],
                'new' => $counts['new'],
                'gone' => $counts['gone'],
                'count' => $counts['changed'] + $counts['new'] + $counts['gone'],
            ];
        }
        usort($packages, static function ($a, $b) {
            return ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0));
        });
        return ['priority' => $priority, 'packages' => $packages];
    }

    private function plugin_theme_key(string $path): string {
        $n = str_replace('\\', '/', $path);
        if (preg_match('#(?:^|/)wp-content/plugins/([^/]+\.(?:php\d*|phtml|phar))$#i', $n, $m)) {
            return 'plugin:' . $m[1];
        }
        if (preg_match('#(?:^|/)wp-content/plugins/([^/]+)/#', $n, $m)) {
            return 'plugin:' . $m[1];
        }
        if (preg_match('#(?:^|/)wp-content/themes/([^/]+)/#', $n, $m)) {
            return 'theme:' . $m[1];
        }
        return '';
    }

    private function is_sealed_watch_path(string $path, ?array $scopes): bool {
        if (!is_array($scopes)) {
            return false;
        }
        $n = str_replace('\\', '/', ltrim($path, '/'));
        $core = $scopes['core'] ?? null;
        if (is_array($core) && (!empty($core['sealed']) || !empty($core['pinned']))) {
            if (isset($core['files'][$n]) || isset($core['files'][$path])) {
                return true;
            }
        }
        $owned = $scopes['site_owned']['files'] ?? [];
        if (is_array($owned) && (isset($owned[$n]) || isset($owned[$path]))) {
            return true;
        }
        $site_owned_pinned = is_array($scopes['site_owned'] ?? null)
            && (!empty($scopes['site_owned']['pinned']) || isset($owned[$n]) || isset($owned[$path]));
        if ($site_owned_pinned && (
            (bool) preg_match('#^wp-content/[^/]+\.(?:php\d*|phtml|phar)$#i', $n)
            || $n === 'wp-content/.htaccess' || $n === 'wp-content/.user.ini'
        )) {
            return true;
        }
        $up = $scopes['uploads_exec']['files'] ?? [];
        if (is_array($up) && (isset($up[$n]) || isset($up[$path]))) {
            return true;
        }
        if (is_array($scopes['uploads_exec'] ?? null) && !empty($scopes['uploads_exec']['pinned'])
            && strpos($n, 'wp-content/uploads/') === 0
            && (
                (bool) preg_match('/\.(?:php\d*|phtml|phar)(?:\.|$)/i', $n)
                || substr($n, -9) === '.htaccess' || substr($n, -9) === '.user.ini'
            )) {
            return true;
        }
        $mu = $scopes['mu_plugins']['files'] ?? [];
        if (is_array($mu) && $mu !== [] && strpos($n, 'wp-content/mu-plugins/') === 0) {
            return true;
        }
        $packages = $scopes['packages'] ?? [];
        if (!is_array($packages)) {
            return false;
        }
        foreach ($packages as $key => $pkg) {
            if (!is_array($pkg) || (empty($pkg['sealed']) && empty($pkg['pinned']))) {
                continue;
            }
            $prefix = '';
            if (strpos((string) $key, 'plugin:') === 0) {
                $slug = substr((string) $key, 7);
                if ((bool) preg_match('/\.(?:php\d*|phtml|phar)$/i', $slug) || !empty($pkg['single_file'])) {
                    if ($n === 'wp-content/plugins/' . $slug) {
                        return true;
                    }
                    continue;
                }
                $prefix = 'wp-content/plugins/' . $slug . '/';
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
        $cron_events = [];
        if (class_exists('CleanSweep_VisitSignals')) {
            foreach (CleanSweep_VisitSignals::cron_origins() as $row) {
                $hook = (string) ($row['hook'] ?? '');
                if ($hook === '') {
                    continue;
                }
                $cron_hooks[] = $hook;
                $cron_events[] = [
                    'hook' => $hook,
                    'file' => (string) ($row['file'] ?? ''),
                ];
            }
        }
        if ($cron_hooks === [] && function_exists('get_option')) {
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
            'cron_events' => $cron_events,
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
        $now_files = [];
        foreach ($now['cron_events'] ?? [] as $ev) {
            if (is_array($ev) && !empty($ev['hook'])) {
                $now_files[(string) $ev['hook']] = (string) ($ev['file'] ?? '');
            }
        }
        $new_cron = [];
        foreach ($now['cron_hooks'] as $h) {
            if (!isset($prev_cron[$h])) {
                $file = $now_files[$h] ?? '';
                $new_cron[] = $file !== ''
                    ? ['hook' => $h, 'file' => $file]
                    : $h;
            }
        }
        return ['new_admins' => $new_admins, 'new_cron' => $new_cron];
    }

    private function plugin_file_matches_org(string $scope, string $file): bool {
        $slug = substr($scope, 7);
        $rel = $this->package_rel($file, $scope);
        if ($slug === '' || $rel === '') {
            return false;
        }
        $version = $this->installed_plugin_version($slug);
        if ($version === '') {
            return false;
        }
        $map = $this->official_plugin_map($slug, $version);
        if (!is_array($map) || !isset($map[$rel])) {
            return false;
        }
        $root = $this->site_root();
        $abs = $root . 'wp-content/plugins/' . $slug . '/' . $rel;
        if (!is_readable($abs) || !function_exists('md5_file')) {
            return false;
        }
        $md5 = @md5_file($abs);
        if (!is_string($md5) || $md5 === '') {
            return false;
        }
        foreach ((array) $map[$rel] as $h) {
            if (is_string($h) && $h !== '' && strcasecmp($md5, $h) === 0) {
                return true;
            }
        }
        return false;
    }

    private function package_rel(string $file, string $scope): string {
        $file = str_replace('\\', '/', $file);
        $prefix = $scope . '/';
        if (strpos($file, $prefix) === 0) {
            return substr($file, strlen($prefix));
        }
        $n = ltrim($file, '/');
        if (strpos($scope, 'plugin:') === 0) {
            $p = 'wp-content/plugins/' . substr($scope, 7) . '/';
            if (strpos($n, $p) === 0) {
                return substr($n, strlen($p));
            }
        }
        if (strpos($scope, 'theme:') === 0) {
            $p = 'wp-content/themes/' . substr($scope, 6) . '/';
            if (strpos($n, $p) === 0) {
                return substr($n, strlen($p));
            }
        }
        return $n;
    }

    private function installed_plugin_version(string $slug): string {
        if (!function_exists('get_plugins')) {
            return '';
        }
        foreach (get_plugins() as $file => $data) {
            $s = dirname((string) $file);
            if ($s === '.' || $s === '') {
                $s = basename((string) $file, '.php');
            }
            if ($s === $slug) {
                return (string) ($data['Version'] ?? '');
            }
        }
        return '';
    }

    /** @return array<string,array<int,string>>|null */
    private function official_plugin_map(string $slug, string $version): ?array {
        if ($slug === '' || $version === '') {
            return null;
        }
        $file = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'features/security/scan/PackageChecksums.php'
            : dirname(__DIR__, 2) . '/features/security/scan/PackageChecksums.php';
        if (!class_exists('CleanSweep_PackageChecksums') && is_readable($file)) {
            require_once $file;
        }
        if (!class_exists('CleanSweep_PackageChecksums')
            || !method_exists('CleanSweep_PackageChecksums', 'official_md5_map')) {
            return null;
        }
        $map = CleanSweep_PackageChecksums::official_md5_map('plugin', $slug, $version);
        return is_array($map) ? $map : null;
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
