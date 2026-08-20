<?php
/**
 * Opt-in always-on file watch (Phase 3).
 *
 * Installs a tiny must-use plugin that re-hashes high-value paths on normal
 * WordPress requests and records drift events for the correlator / Integrity UI.
 *
 * Design goals:
 *  - General malware signal (core, pre-boot, mu-plugins, drop-ins, active plugins,
 *    new PHP under sensitive trees) — not a single reinfection recipe.
 *  - Restricted-host safe: no shell/pid; optional hashes via CleanSweep_VisitCapabilities.
 *  - Low cost: small path set, round-robin, rate limits, fail-open.
 *  - Opt-in only; clean uninstall.
 */
final class CleanSweep_VisitWatch {

    public const AGENT_BASENAME = '00-clean-sweep-visit-watch.php';
    public const MAX_EVENTS = 80;
    public const MAX_PATHS_PER_TICK = 40;
    public const MIN_TICK_SECONDS = 8;
    public const EVENT_TTL_SECONDS = 172800; // 48h kept for correlator
    public const COALESCE_SECONDS = 900; // same path+kind within 15m is one infection window
    public const MAX_OPERATIONS = 20;
    public const OP_TTL_SECONDS = 1200;

    private CleanSweep_VisitState $state;
    private CleanSweep_VisitCapabilities $caps;

    /** Real WordPress root for this request (never core/fresh). */
    private string $resolved_root = '';

    public function __construct(?CleanSweep_VisitState $state = null, ?CleanSweep_VisitCapabilities $caps = null) {
        $this->state = $state ?: new CleanSweep_VisitState();
        $this->caps = $caps ?: CleanSweep_VisitCapabilities::instance();
    }

    public static function agent_rel(): string {
        return 'wp-content/mu-plugins/' . self::AGENT_BASENAME;
    }

    public function is_enabled(): bool {
        $s = $this->state->load();
        return !empty($s['watch']['enabled']);
    }

    public function agent_installed(): bool {
        $abs = $this->agent_abs();
        return $abs !== '' && is_file($abs);
    }

    /**
     * Enable live watch: snapshot baselines + install mu-plugin.
     *
     * @return array{ok:bool,error?:string,paths?:int,agent?:string}
     */
    public function enable(): array {
        $root = $this->resolve_real_site_root();
        if ($root === '') {
            return ['ok' => false, 'error' => 'Could not resolve WordPress root'];
        }
        $mu_dir = $root . 'wp-content/mu-plugins';
        if (!is_dir($mu_dir) && !@mkdir($mu_dir, 0755, true)) {
            return ['ok' => false, 'error' => 'Cannot create mu-plugins directory'];
        }
        if (!is_writable($mu_dir)) {
            return ['ok' => false, 'error' => 'mu-plugins directory is not writable'];
        }

        $toolkit = $this->toolkit_root();
        $agent = $this->render_agent($toolkit);
        $agent_abs = rtrim($mu_dir, '/\\') . '/' . self::AGENT_BASENAME;
        if (@file_put_contents($agent_abs, $agent) === false) {
            return ['ok' => false, 'error' => 'Failed to write watch agent mu-plugin'];
        }

        // Hash after writing the agent, and include fast-lane trees so the first
        // tick does not dump pre-existing uploads stubs as "created".
        $baselines = $this->build_baselines($root);
        if ($baselines === []) {
            @unlink($agent_abs);
            return ['ok' => false, 'error' => 'No readable paths to watch (seal core or allow file hashing first)'];
        }

        $s = $this->state->load();
        $s['watch'] = [
            'enabled' => true,
            'enabled_at' => time(),
            'root' => $root,
            'toolkit_root' => $toolkit,
            'agent' => self::agent_rel(),
            'baselines' => $baselines,
            'cursor' => 0,
            'last_tick' => 0,
            'events' => is_array($s['watch']['events'] ?? null) ? $s['watch']['events'] : [],
            'operations' => [],
            'stats' => [
                'ticks' => 0,
                'changes' => 0,
                'paths' => count($baselines),
            ],
        ];
        $this->state->save($s);
        $this->state->event('watch:enabled', count($baselines) . ' paths');

        return [
            'ok' => true,
            'paths' => count($baselines),
            'agent' => self::agent_rel(),
        ];
    }

    /**
     * Disable and remove agent.
     *
     * @return array{ok:bool,error?:string}
     */
    public function disable(): array {
        $this->unlink_agent_everywhere();
        $s = $this->state->load();
        // Full wipe of watch state — toolkit delete should leave no residual journal
        $s['watch'] = [
            'enabled' => false,
            'disabled_at' => time(),
            'events' => [],
            'baselines' => [],
            'stats' => [],
        ];
        $this->state->save($s);
        $this->state->event('watch:disabled', '');
        return ['ok' => true];
    }

    /**
     * Unlink agent under all plausible site roots (ABSPATH, detected root, toolkit parent).
     * Used by disable() and full toolkit cleanup.
     */
    public function unlink_agent_everywhere(): void {
        $name = self::AGENT_BASENAME;
        $roots = [];
        $r = $this->site_root();
        if ($r !== '') {
            $roots[] = $r;
        }
        if (defined('ABSPATH') && ABSPATH) {
            $roots[] = rtrim(str_replace('\\', '/', ABSPATH), '/') . '/';
        }
        $parent = dirname($this->toolkit_root());
        if (is_string($parent) && $parent !== '' && $parent !== '.') {
            $roots[] = rtrim(str_replace('\\', '/', $parent), '/') . '/';
        }
        foreach (array_unique($roots) as $root) {
            $abs = $root . 'wp-content/mu-plugins/' . $name;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    }

    /**
     * One lightweight tick. Safe to call from mu-plugin or API.
     *
     * @return array{ok:bool,checked?:int,changes?:int,skipped?:string}
     */
    public function tick(): array {
        $s = $this->state->load();
        if (empty($s['watch']['enabled'])) {
            return ['ok' => false, 'skipped' => 'disabled'];
        }
        $now = time();
        $last = (int) ($s['watch']['last_tick'] ?? 0);
        if ($last > 0 && ($now - $last) < self::MIN_TICK_SECONDS) {
            return ['ok' => true, 'skipped' => 'rate_limit', 'checked' => 0, 'changes' => 0];
        }

        $root = $this->resolve_real_site_root();
        if ($root === '') {
            return ['ok' => false, 'skipped' => 'no_site_root', 'checked' => 0, 'changes' => 0];
        }

        $stored_root = $this->normalize_root((string) ($s['watch']['root'] ?? ''));
        if ($stored_root === '' || $this->is_recovery_root($stored_root) || $stored_root !== $root) {
            // Pin to the live site. Do not emit created/modified — old events were
            // recovery-copy hashes mixed with the real tree.
            $s['watch']['root'] = $root;
            $s['watch']['baselines'] = $this->build_baselines($root);
            $s['watch']['cursor'] = 0;
            $s['watch']['last_tick'] = $now;
            $this->journal($s, 'watch:rebased', 'Watching the live site, not Clean Sweep recovery core/fresh');
            $this->state->save($s);
            return ['ok' => true, 'skipped' => 'rebased_root', 'checked' => 0, 'changes' => 0];
        }

        $baselines = is_array($s['watch']['baselines'] ?? null) ? $s['watch']['baselines'] : [];
        if ($baselines === []) {
            // Rebuild if missing (upgrade path)
            $baselines = $this->build_baselines($root);
            $s['watch']['baselines'] = $baselines;
        }
        if ($baselines === []) {
            $s['watch']['last_tick'] = $now;
            $this->state->save($s);
            return ['ok' => true, 'skipped' => 'no_paths', 'checked' => 0, 'changes' => 0];
        }

        $req = $this->request_context();
        $changes = 0;
        $checked = 0;
        $events = is_array($s['watch']['events'] ?? null) ? $s['watch']['events'] : [];
        $ops = $this->prune_operations(is_array($s['watch']['operations'] ?? null) ? $s['watch']['operations'] : [], $now);
        $s['watch']['operations'] = $ops;
        $enabled_at = (int) ($s['watch']['enabled_at'] ?? 0);

        // Relabel leftover "created" rows from before this distinction existed.
        $events = $this->normalize_legacy_created($events, $enabled_at);

        // Fast lane: new PHP under high-risk trees. Pre-existing files are
        // baselined quietly; only disk times at/after enable count as created.
        $dyn = $this->discover_new_php_paths($baselines, $root);
        $baselines = $dyn['baselines'];
        $guard_n = 0;
        foreach ($dyn['found'] as $row) {
            $rel = (string) ($row['path'] ?? '');
            if ($rel === '') {
                continue;
            }
            $abs = $this->abs_from_rel($rel);
            $mtime = isset($row['mtime']) ? (int) $row['mtime'] : null;
            $ctime = isset($row['ctime']) ? (int) $row['ctime'] : null;
            $extra = [
                'mtime' => $mtime,
                'ctime' => $ctime,
            ];
            $guard = $this->is_directory_guard($abs, $rel);
            $is_new = $this->disk_time_after_watch($mtime, $enabled_at);
            if (!$is_new) {
                if ($guard) {
                    $guard_n++;
                    continue;
                }
                $this->emit_event(
                    $s,
                    $events,
                    $rel,
                    'already_present',
                    null,
                    $row['hash'] ?? null,
                    $req,
                    $now,
                    $ops,
                    $extra
                );
                continue;
            }
            if ($guard) {
                $extra['noise'] = 'directory_guard';
            }
            $this->emit_event(
                $s,
                $events,
                $rel,
                'created',
                null,
                $row['hash'] ?? null,
                $req,
                $now,
                $ops,
                $extra
            );
            $changes++;
        }
        if ($guard_n > 0) {
            $this->emit_event(
                $s,
                $events,
                'directory guards',
                'already_present',
                null,
                null,
                $req,
                $now,
                $ops,
                ['noise' => 'directory_guard', 'collapsed' => $guard_n]
            );
        }

        $keys = array_keys($baselines);
        $total = count($keys);
        $cursor = (int) ($s['watch']['cursor'] ?? 0);
        if ($cursor < 0 || $cursor >= $total) {
            $cursor = 0;
        }

        $limit = min(self::MAX_PATHS_PER_TICK, $total);

        for ($i = 0; $i < $limit; $i++) {
            $idx = ($cursor + $i) % $total;
            $rel = $keys[$idx];
            $prev = is_array($baselines[$rel] ?? null) ? $baselines[$rel] : [];
            $prev_hash = (string) ($prev['hash'] ?? '');
            $prev_exists = !array_key_exists('exists', $prev) || !empty($prev['exists']);

            $abs = $this->abs_from_rel($rel);
            if ($abs !== '' && $this->is_under_toolkit($abs)) {
                continue;
            }
            $exists = $abs !== '' && is_file($abs) && is_readable($abs);
            $hash = null;
            if ($exists) {
                $hash = $this->caps->hash_path($abs);
            }

            $kind = null;
            if ($prev_exists && !$exists) {
                $kind = 'deleted';
            } elseif (!$prev_exists && $exists) {
                $kind = 'created';
            } elseif ($exists && $prev_hash !== '' && is_string($hash) && $hash !== '' && !hash_equals($prev_hash, $hash)) {
                $kind = 'modified';
            } elseif ($exists && $prev_hash === '' && is_string($hash) && $hash !== '') {
                // First successful hash after hash was unavailable
                $kind = null;
            }

            $checked++;
            if ($kind !== null) {
                $mtime = $exists ? $this->caps->mtime($abs) : null;
                $ctime = $exists ? $this->caps->ctime($abs) : null;
                $extra = [
                    'mtime' => $mtime,
                    'ctime' => $ctime,
                ];
                if ($kind === 'created' && $this->is_directory_guard($abs, $rel)) {
                    $extra['noise'] = 'directory_guard';
                }
                $this->emit_event(
                    $s,
                    $events,
                    $rel,
                    $kind,
                    $prev_hash !== '' ? $prev_hash : null,
                    is_string($hash) ? $hash : null,
                    $req,
                    $now,
                    $ops,
                    $extra
                );
                if ($kind !== 'already_present') {
                    $changes++;
                }
            }

            // Always refresh baseline so we observe the next change (do not discard signal —
            // events retain history). Missing hash keeps prior when unreadable.
            $baselines[$rel] = [
                'hash' => is_string($hash) && $hash !== '' ? $hash : ($kind === 'deleted' ? null : $prev_hash),
                'exists' => $exists,
                'size' => $exists ? $this->caps->size($abs) : null,
                'mtime' => $exists ? $this->caps->mtime($abs) : null,
            ];
        }

        $events = $this->prune_events($events);
        $s['watch']['baselines'] = $baselines;
        $s['watch']['events'] = $events;
        $s['watch']['cursor'] = ($cursor + $limit) % max(1, $total);
        $s['watch']['last_tick'] = $now;
        $s['watch']['stats'] = [
            'ticks' => (int) ($s['watch']['stats']['ticks'] ?? 0) + 1,
            'changes' => (int) ($s['watch']['stats']['changes'] ?? 0) + $changes,
            'paths' => count($baselines),
            'last_checked' => $checked,
            'last_changes' => $changes,
        ];
        // CleanSweep_Worker-style write from front-end requests
        $s['_worker_write'] = true;
        $this->state->save($s);

        return ['ok' => true, 'checked' => $checked, 'changes' => $changes];
    }

    /**
     * Recent watch events for UI / correlator.
     *
     * @return array<int,array>
     */
    public function recent_events(int $max = 40): array {
        $s = $this->state->load();
        $events = is_array($s['watch']['events'] ?? null) ? $s['watch']['events'] : [];
        $events = $this->normalize_legacy_created($events, (int) ($s['watch']['enabled_at'] ?? 0));
        $events = $this->prune_events($events);
        if (count($events) > $max) {
            $events = array_slice($events, -$max);
        }
        foreach ($events as &$e) {
            if (!is_array($e)) {
                continue;
            }
            $t = (int) ($e['t'] ?? 0);
            if (!isset($e['first_seen'])) {
                $e['first_seen'] = $t;
            }
            if (!isset($e['last_seen'])) {
                $e['last_seen'] = $t;
            }
            if (!isset($e['count'])) {
                $e['count'] = 1;
            }
        }
        unset($e);
        return array_values($events);
    }

    public function status_slice(): array {
        $s = $this->state->load();
        $w = is_array($s['watch'] ?? null) ? $s['watch'] : [];
        return [
            'live_watch_enabled' => !empty($w['enabled']),
            'live_watch_agent' => !empty($w['enabled']) && $this->agent_installed(),
            'live_watch_paths' => (int) ($w['stats']['paths'] ?? (is_array($w['baselines'] ?? null) ? count($w['baselines']) : 0)),
            'live_watch_events' => $this->recent_events(30),
            'live_watch_stats' => is_array($w['stats'] ?? null) ? $w['stats'] : [],
            'live_watch_enabled_at' => $w['enabled_at'] ?? null,
            'live_watch_last_tick' => $w['last_tick'] ?? null,
        ];
    }

    /**
     * Mark an upcoming Clean Sweep write so Live Watch can tag matching
     * created/modified/deleted events as expected (reinstall, scan, …).
     *
     * @param string   $op       plugin_reinstall|theme_reinstall|core_reinstall|scan
     * @param string[] $prefixes relative path prefixes that this op is allowed to touch
     * @param int      $ttl      seconds the tag stays active
     * @param array    $meta     optional {detail:string}
     */
    public function note_operation(string $op, array $prefixes = [], int $ttl = 0, array $meta = []): bool {
        $s = $this->state->load();
        if (empty($s['watch']['enabled'])) {
            return false;
        }
        $now = time();
        if ($ttl <= 0) {
            $ttl = self::OP_TTL_SECONDS;
        }
        $ops = $this->prune_operations(is_array($s['watch']['operations'] ?? null) ? $s['watch']['operations'] : [], $now);
        $clean = [];
        foreach ($prefixes as $p) {
            if (!is_string($p) || $p === '') {
                continue;
            }
            $clean[] = ltrim(str_replace('\\', '/', $p), '/');
        }
        $detail = (string) ($meta['detail'] ?? '');
        $ops[] = [
            'op' => $op,
            'prefixes' => array_values(array_unique($clean)),
            'started_at' => $now,
            'until' => $now + max(30, $ttl),
            'detail' => $detail,
        ];
        if (count($ops) > self::MAX_OPERATIONS) {
            $ops = array_slice($ops, -self::MAX_OPERATIONS);
        }
        $s['watch']['operations'] = $ops;
        $s['_worker_write'] = true;
        $this->journal($s, 'watch:operation', $op . ($detail !== '' ? ' · ' . $detail : ''));
        $this->state->save($s);
        return true;
    }

    /**
     * Build general-purpose baseline map: path_rel => sample.
     *
     * @return array<string,array>
     */
    private function build_baselines(string $root): array {
        $out = [];
        $add = function (string $rel) use (&$out, $root) {
            $rel = ltrim(str_replace('\\', '/', $rel), '/');
            if ($rel === '' || isset($out[$rel])) {
                return;
            }
            $abs = rtrim($root, '/\\') . '/' . $rel;
            if ($this->is_under_toolkit($abs)) {
                return;
            }
            if (!is_file($abs) || !is_readable($abs)) {
                // Still track absence for created detection on known paths
                if ($this->is_high_value_missing_track($rel)) {
                    $out[$rel] = ['hash' => null, 'exists' => false, 'size' => null, 'mtime' => null];
                }
                return;
            }
            $hash = $this->caps->hash_path($abs);
            $out[$rel] = [
                'hash' => is_string($hash) ? $hash : null,
                'exists' => true,
                'size' => $this->caps->size($abs),
                'mtime' => $this->caps->mtime($abs),
            ];
        };

        // 1) Site-owned / pre-boot / drop-ins
        foreach ([
            'wp-config.php', '.htaccess', '.user.ini', 'php.ini', 'web.config',
            'wp-content/db.php', 'wp-content/object-cache.php',
            'wp-content/advanced-cache.php', 'wp-content/sunrise.php',
        ] as $rel) {
            $add($rel);
        }

        // 2) High-value core bootstrap (always — sealed or not)
        foreach ([
            'index.php', 'wp-load.php', 'wp-settings.php', 'wp-config-sample.php',
            'wp-login.php', 'wp-blog-header.php', 'wp-cron.php', 'xmlrpc.php',
        ] as $rel) {
            $add($rel);
        }

        // 3) Sealed core files (capped — general integrity, not one scenario)
        $s = $this->state->load();
        $sealed = $s['scopes']['core']['files'] ?? null;
        if (is_array($sealed)) {
            $n = 0;
            foreach ($sealed as $rel => $_sample) {
                if ($n >= 120) {
                    break;
                }
                $add((string) $rel);
                $n++;
            }
        }

        // 4) All mu-plugin PHP (always-on malware home)
        $mu = $root . 'wp-content/mu-plugins';
        if (is_dir($mu)) {
            foreach ($this->list_php($mu, 80) as $abs) {
                $add($this->rel($root, $abs));
            }
        }

        // 5) Active plugin / theme main files (options if present)
        $opts = is_array($s['options'] ?? null) ? $s['options'] : [];
        $active = $opts['active_plugins'] ?? null;
        if (is_array($active)) {
            foreach ($active as $plugin_file) {
                if (!is_string($plugin_file) || $plugin_file === '') {
                    continue;
                }
                $add('wp-content/plugins/' . ltrim(str_replace('\\', '/', $plugin_file), '/'));
            }
        }
        foreach (['template', 'stylesheet'] as $tk) {
            $slug = (string) ($opts[$tk] ?? '');
            if ($slug !== '') {
                $add('wp-content/themes/' . $slug . '/functions.php');
                $add('wp-content/themes/' . $slug . '/style.css');
            }
        }

        // 6) CleanSweep_Census samples already known as interesting (site_owned + wp_content PHP)
        foreach (['site_owned', 'wp_content'] as $bucket) {
            $samples = $s['samples'][$bucket] ?? null;
            if (!is_array($samples)) {
                continue;
            }
            $n = 0;
            foreach ($samples as $rel => $_s) {
                if ($n >= 60) {
                    break;
                }
                $add((string) $rel);
                $n++;
            }
        }

        // Never watch the agent against itself in a way that loops thrash — still track it
        // so tampering with the agent is visible.
        $add(self::agent_rel());

        // 7) Fast-lane trees (uploads PHP/config, wp-content root, site-root extras).
        // Seeded here so the first tick does not report them as created.
        $this->seed_fast_lane($out, $root);

        return $out;
    }

    /**
     * Fast-lane discovery of PHP/config not yet in the baseline.
     * Cheap: mu-plugins, drop-ins, wp-content root, site-root extras, uploads
     * PHP/config at depth 2. Does not walk plugins/themes.
     *
     * @param array<string,array> $baselines
     * @return array{baselines:array<string,array>,found:array<int,array>}
     */
    private function discover_new_php_paths(array $baselines, string $root): array {
        $found = [];
        foreach ($this->collect_fast_lane_abs($root) as $abs) {
            $rel = $this->rel($root, $abs);
            if ($rel === '' || isset($baselines[$rel])) {
                continue;
            }
            if (strcasecmp(basename($rel), self::AGENT_BASENAME) === 0) {
                $hash = $this->caps->hash_path($abs);
                $baselines[$rel] = [
                    'hash' => is_string($hash) ? $hash : null,
                    'exists' => true,
                    'size' => $this->caps->size($abs),
                    'mtime' => $this->caps->mtime($abs),
                ];
                continue;
            }
            $hash = $this->caps->hash_path($abs);
            $mtime = $this->caps->mtime($abs);
            $baselines[$rel] = [
                'hash' => is_string($hash) ? $hash : null,
                'exists' => true,
                'size' => $this->caps->size($abs),
                'mtime' => $mtime,
            ];
            $found[] = [
                'path' => $rel,
                'hash' => is_string($hash) ? $hash : null,
                'mtime' => $mtime,
                'ctime' => $this->caps->ctime($abs),
            ];
        }
        return ['baselines' => $baselines, 'found' => $found];
    }

    /** @param array<string,array> $out */
    private function seed_fast_lane(array &$out, string $root): void {
        foreach ($this->collect_fast_lane_abs($root) as $abs) {
            $rel = $this->rel($root, $abs);
            if ($rel === '' || isset($out[$rel])) {
                continue;
            }
            $hash = $this->caps->hash_path($abs);
            $out[$rel] = [
                'hash' => is_string($hash) ? $hash : null,
                'exists' => true,
                'size' => $this->caps->size($abs),
                'mtime' => $this->caps->mtime($abs),
            ];
        }
    }

    /** @return string[] absolute paths */
    private function collect_fast_lane_abs(string $root): array {
        $out = [];
        $push = function (string $abs) use (&$out) {
            if ($abs === '' || $this->is_under_toolkit($abs) || !is_file($abs) || !is_readable($abs)) {
                return;
            }
            $out[] = $abs;
        };

        $mu = $root . 'wp-content/mu-plugins';
        if (is_dir($mu)) {
            foreach ($this->list_php($mu, 80) as $abs) {
                $push($abs);
            }
        }
        foreach ([
            'wp-content/db.php', 'wp-content/object-cache.php',
            'wp-content/advanced-cache.php', 'wp-content/sunrise.php',
        ] as $rel) {
            $push($root . $rel);
        }
        $wc = $root . 'wp-content';
        if (is_dir($wc)) {
            foreach ($this->list_php_shallow($wc, 40) as $abs) {
                $push($abs);
            }
        }
        foreach ($this->list_root_extra_php($root) as $abs) {
            $push($abs);
        }
        $uploads = $root . 'wp-content/uploads';
        if (is_dir($uploads)) {
            foreach ($this->list_uploads_php_config($uploads, 40, 2) as $abs) {
                $push($abs);
            }
        }
        return $out;
    }

    /**
     * @param array<int,array> $events
     * @param array<int,array> $ops
     */
    private function emit_event(
        array &$s,
        array &$events,
        string $rel,
        string $kind,
        $prev_hash,
        $hash,
        array $req,
        int $now,
        array $ops,
        array $extra = []
    ): void {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || $kind === '') {
            return;
        }
        $expected = $this->match_operation($rel, $ops, $now);
        $collapsed_add = (int) ($extra['collapsed'] ?? 0);
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $e = $events[$i];
            if (!is_array($e) || (string) ($e['path'] ?? '') !== $rel || (string) ($e['kind'] ?? '') !== $kind) {
                continue;
            }
            $last = (int) ($e['last_seen'] ?? $e['t'] ?? 0);
            if ($last > 0 && ($now - $last) <= self::COALESCE_SECONDS) {
                $events[$i]['last_seen'] = $now;
                $events[$i]['count'] = (int) ($e['count'] ?? 1) + max(1, $collapsed_add);
                $events[$i]['hash'] = is_string($hash) && $hash !== '' ? $hash : ($e['hash'] ?? null);
                $events[$i]['last_request'] = $req;
                if ($expected && empty($e['expected'])) {
                    $events[$i]['expected'] = $expected;
                }
                if ($collapsed_add > 0) {
                    $events[$i]['collapsed'] = (int) ($e['collapsed'] ?? 0) + $collapsed_add;
                }
                return;
            }
            break;
        }
        $row = [
            't' => $now,
            'first_seen' => $now,
            'last_seen' => $now,
            'count' => max(1, $collapsed_add),
            'path' => $rel,
            'kind' => $kind,
            'prev_hash' => is_string($prev_hash) && $prev_hash !== '' ? $prev_hash : null,
            'hash' => is_string($hash) && $hash !== '' ? $hash : null,
            'request' => $req,
            'expected' => $expected,
            'mtime' => isset($extra['mtime']) ? $extra['mtime'] : null,
            'ctime' => isset($extra['ctime']) ? $extra['ctime'] : null,
        ];
        if (!empty($extra['noise'])) {
            $row['noise'] = (string) $extra['noise'];
        }
        if ($collapsed_add > 0) {
            $row['collapsed'] = $collapsed_add;
        }
        $events[] = $row;
        if ($kind === 'already_present' && !empty($extra['noise'])) {
            return;
        }
        $via = is_string($req['script'] ?? null) && $req['script'] !== '' ? ' · via ' . $req['script'] : '';
        $tag = $expected ? ' · expected ' . (string) ($expected['op'] ?? '') : '';
        $since = '';
        if (is_int($extra['mtime'] ?? null) && (int) $extra['mtime'] > 0) {
            $since = ' · on disk since ' . gmdate('Y-m-d', (int) $extra['mtime']);
        }
        $this->journal($s, 'watch:' . $kind, $rel . $via . $tag . $since);
    }

    /**
     * @param array<int,array> $ops
     * @return array{op:string,detail:string}|null
     */
    private function match_operation(string $rel, array $ops, int $now): ?array {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach ($ops as $op) {
            if (!is_array($op) || (int) ($op['until'] ?? 0) < $now) {
                continue;
            }
            $name = (string) ($op['op'] ?? '');
            if ($name === '') {
                continue;
            }
            foreach ((array) ($op['prefixes'] ?? []) as $p) {
                $p = ltrim(str_replace('\\', '/', (string) $p), '/');
                if ($p === '') {
                    continue;
                }
                $base = rtrim($p, '/');
                if ($rel === $p || $rel === $base || ($base !== '' && strpos($rel, $base . '/') === 0)) {
                    return [
                        'op' => $name,
                        'detail' => (string) ($op['detail'] ?? ''),
                    ];
                }
            }
        }
        return null;
    }

    /** @param array<int,array> $ops @return array<int,array> */
    private function prune_operations(array $ops, int $now): array {
        $out = [];
        foreach ($ops as $op) {
            if (!is_array($op) || (int) ($op['until'] ?? 0) < $now) {
                continue;
            }
            $out[] = $op;
        }
        return $out;
    }

    private function journal(array &$s, string $code, string $detail = ''): void {
        if (!isset($s['events']) || !is_array($s['events'])) {
            $s['events'] = [];
        }
        $s['events'][] = [
            't' => time(),
            'code' => $code,
            'detail' => $detail,
            'text' => CleanSweep_VisitState::event_text($code, $detail),
        ];
        if (count($s['events']) > 200) {
            $s['events'] = array_slice($s['events'], -200);
        }
    }

    private function is_high_value_missing_track(string $rel): bool {
        $rel = strtolower($rel);
        return (bool) preg_match(
            '#^(wp-config\.php|\.htaccess|\.user\.ini|php\.ini|web\.config|wp-content/(db|object-cache|advanced-cache|sunrise)\.php)$#',
            $rel
        );
    }

    /**
     * Relabel first-tick "created" events whose files were already on disk
     * before watch started. Collapses directory guards into one row.
     *
     * @param array<int,array> $events
     * @return array<int,array>
     */
    private function normalize_legacy_created(array $events, int $enabled_at): array {
        $out = [];
        $guards = [];
        foreach ($events as $e) {
            if (!is_array($e)) {
                continue;
            }
            $kind = (string) ($e['kind'] ?? '');
            $rel = (string) ($e['path'] ?? '');
            $abs = $rel !== '' && $rel !== 'directory guards' ? $this->abs_from_rel($rel) : '';
            if ($abs !== '' && is_file($abs)) {
                if (empty($e['mtime'])) {
                    $e['mtime'] = $this->caps->mtime($abs);
                }
                if (empty($e['ctime'])) {
                    $e['ctime'] = $this->caps->ctime($abs);
                }
            }
            $mtime = isset($e['mtime']) ? (int) $e['mtime'] : 0;
            if ($kind === 'created' && !$this->disk_time_after_watch($mtime > 0 ? $mtime : null, $enabled_at)) {
                $e['kind'] = 'already_present';
                $kind = 'already_present';
            }
            if ($kind === 'already_present' && ($rel === 'directory guards' || $this->is_directory_guard($abs, $rel))) {
                $e['noise'] = 'directory_guard';
                $guards[] = $e;
                continue;
            }
            $out[] = $e;
        }
        if ($guards !== []) {
            $first = (int) ($guards[0]['t'] ?? 0);
            $last = $first;
            $n = 0;
            foreach ($guards as $g) {
                $t = (int) ($g['t'] ?? 0);
                if ($first <= 0 || ($t > 0 && $t < $first)) {
                    $first = $t;
                }
                if ($t > $last) {
                    $last = $t;
                }
                $n += max(1, (int) ($g['collapsed'] ?? $g['count'] ?? 1));
            }
            $out[] = [
                't' => $first,
                'first_seen' => $first,
                'last_seen' => $last,
                'count' => $n,
                'path' => 'directory guards',
                'kind' => 'already_present',
                'noise' => 'directory_guard',
                'collapsed' => $n,
                'request' => $guards[0]['request'] ?? null,
                'expected' => null,
                'mtime' => null,
                'ctime' => null,
            ];
        }
        return $out;
    }

    /** True when file content time is at or after watch started (mtime, 5s slack). */
    private function disk_time_after_watch(?int $mtime, int $enabled_at): bool {
        if ($enabled_at <= 0) {
            return true;
        }
        if (!is_int($mtime) || $mtime <= 0) {
            return false;
        }
        return $mtime >= ($enabled_at - 5);
    }

    private function is_directory_guard(string $abs, string $rel): bool {
        if ($abs === '' || $rel === '' || $rel === 'directory guards') {
            return $rel === 'directory guards';
        }
        $base = strtolower(basename($rel));
        if ($base === '.htaccess' || $base === 'web.config') {
            $raw = $this->read_small($abs, 800);
            if (!is_string($raw) || strlen($raw) > 800) {
                return false;
            }
            $n = strtolower($raw);
            return strpos($n, 'deny from all') !== false || strpos($n, 'require all denied') !== false;
        }
        if (!preg_match('/^index\.(php\d*|phtml)$/', $base)) {
            return false;
        }
        $size = $this->caps->size($abs);
        if (is_int($size) && $size > 400) {
            return false;
        }
        $raw = $this->read_small($abs, 400);
        if (!is_string($raw)) {
            return is_int($size) && $size === 0;
        }
        $trim = strtolower(trim($raw));
        if ($trim === '' || $trim === '<?php' || $trim === '<?php?>') {
            return true;
        }
        if (strpos($trim, 'silence is golden') !== false) {
            return true;
        }
        return (bool) preg_match('/^<\?php\s*(\/\/[^\n]*)?\s*$/', $trim);
    }

    private function read_small(string $abs, int $max): ?string {
        if ($abs === '' || !is_readable($abs)) {
            return null;
        }
        $raw = @file_get_contents($abs, false, null, 0, $max);
        return is_string($raw) ? $raw : null;
    }

    /**
     * @return array{uri:?string,method:?string,script:?string,ip:?string,actor:string,ua:?string,user_id:?int}
     */
    private function request_context(): array {
        $script_abs = '';
        if (!empty($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME'])) {
            $script_abs = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']);
        }
        $script_real = $this->real_path($script_abs);
        $toolkit_real = $this->real_path($this->toolkit_root());
        $in_toolkit = $this->path_is_under($script_real !== '' ? $script_real : $script_abs, $toolkit_real);
        $script = null;
        if ($in_toolkit) {
            $base = rtrim($toolkit_real !== '' ? $toolkit_real : $this->toolkit_root(), '/');
            $from = $script_real !== '' ? $script_real : $script_abs;
            $script = 'clean-sweep/' . ltrim(substr($from, strlen($base)), '/');
        } elseif ($script_abs !== '') {
            $site = $this->real_path(rtrim($this->site_root(), '/'));
            $from = $script_real !== '' ? $script_real : $script_abs;
            if ($site !== '' && strpos($from, $site . '/') === 0) {
                $script = ltrim(substr($from, strlen($site)), '/');
            } else {
                $script = $this->rel($this->site_root(), $script_abs);
                if ($script === $script_abs || strpos($script, '/') === false && strlen($script) > 80) {
                    $script = basename($script_abs);
                }
            }
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : null;
        if (is_string($uri) && strlen($uri) > 300) {
            $uri = substr($uri, 0, 300);
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null;
        if (is_string($ua) && strlen($ua) > 180) {
            $ua = substr($ua, 0, 180);
        }
        $user_id = null;
        if (function_exists('get_current_user_id')) {
            $uid = (int) get_current_user_id();
            if ($uid > 0) {
                $user_id = $uid;
            }
        }
        return [
            'uri' => $uri,
            'method' => isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : null,
            'script' => $script,
            'ip' => $ip,
            'actor' => $in_toolkit ? 'clean_sweep' : 'site',
            'ua' => $ua,
            'user_id' => $user_id,
        ];
    }

    /** @param array<int,array> $events */
    private function prune_events(array $events): array {
        $cut = time() - self::EVENT_TTL_SECONDS;
        $out = [];
        foreach ($events as $e) {
            if (!is_array($e)) {
                continue;
            }
            if ((int) ($e['t'] ?? 0) < $cut) {
                continue;
            }
            $out[] = $e;
        }
        if (count($out) > self::MAX_EVENTS) {
            $signal = [];
            $rest = [];
            foreach ($out as $e) {
                $kind = (string) ($e['kind'] ?? '');
                if ($kind === 'already_present' || (($e['noise'] ?? '') === 'directory_guard')) {
                    $rest[] = $e;
                } else {
                    $signal[] = $e;
                }
            }
            $keep_rest = self::MAX_EVENTS - count($signal);
            if ($keep_rest < 0) {
                $out = array_slice($signal, -self::MAX_EVENTS);
            } elseif ($keep_rest === 0) {
                $out = $signal;
            } else {
                $out = array_merge(array_slice($rest, -$keep_rest), $signal);
            }
        }
        return $out;
    }

    private function render_agent(string $toolkit_root): string {
        $toolkit_root = rtrim(str_replace('\\', '/', $toolkit_root), '/');
        $boot = $toolkit_root . '/includes/system/visit/bootstrap.php';
        // Escape for single-quoted PHP string
        $boot_e = str_replace(['\\', "'"], ['\\\\', "\\'"], $boot);
        $ver = date('c');
        return <<<PHP
<?php
/**
 * Clean Sweep — live visit watch agent (opt-in).
 * Installed by Clean Sweep Integrity → Live watch. Safe to delete to disable.
 * Generated: {$ver}
 */
if (!defined('ABSPATH')) {
    return;
}
// Fail open: never break the site
try {
    \$__cs_boot = '{$boot_e}';
    if (!is_readable(\$__cs_boot)) {
        return;
    }
    require_once \$__cs_boot;
    if (!class_exists('CleanSweep_VisitWatch', false)) {
        return;
    }
    // Prefer shutdown so page work finishes first
    if (function_exists('add_action')) {
        add_action('shutdown', static function () {
            try {
                (new CleanSweep_VisitWatch())->tick();
            } catch (Throwable \$e) {
                // swallow
            }
        }, 99);
    } else {
        (new CleanSweep_VisitWatch())->tick();
    }
} catch (Throwable \$e) {
    // swallow
}

PHP;
    }

    private function agent_abs(): string {
        $root = $this->site_root();
        if ($root === '') {
            return '';
        }
        return $root . self::agent_rel();
    }

    private function toolkit_root(): string {
        if (defined('CLEAN_SWEEP_ROOT') && CLEAN_SWEEP_ROOT) {
            return rtrim(str_replace('\\', '/', CLEAN_SWEEP_ROOT), '/');
        }
        return rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
    }

    /**
     * Live WordPress root. Never Clean Sweep's recovery copy (core/fresh) or
     * anything inside the toolkit folder — those are not the site being watched.
     */
    private function site_root(): string {
        if ($this->resolved_root !== '') {
            return $this->resolved_root;
        }
        return $this->resolve_real_site_root();
    }

    private function resolve_real_site_root(): string {
        $candidates = [];
        foreach ([
            defined('SITE_ABSPATH') ? SITE_ABSPATH : null,
            defined('ORIGINAL_ABSPATH') ? ORIGINAL_ABSPATH : null,
            defined('ORIGINAL_WP_CONTENT_DIR') ? dirname(ORIGINAL_WP_CONTENT_DIR) : null,
            dirname($this->toolkit_root()),
        ] as $c) {
            if (is_string($c) && $c !== '' && $c !== '.') {
                $candidates[] = $c;
            }
        }
        if (function_exists('clean_sweep_detect_site_root')) {
            $d = clean_sweep_detect_site_root();
            if (is_string($d) && $d !== '') {
                $candidates[] = $d;
            }
        }
        if (defined('ABSPATH') && ABSPATH) {
            $candidates[] = ABSPATH;
        }

        foreach ($candidates as $c) {
            $c = $this->normalize_root((string) $c);
            if ($c === '' || $this->is_recovery_root($c)) {
                continue;
            }
            if (is_readable($c . 'wp-config.php') || is_readable($c . 'wp-includes/version.php')) {
                $this->resolved_root = $c;
                return $c;
            }
        }
        $this->resolved_root = '';
        return '';
    }

    private function normalize_root(string $root): string {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($root === '' || $root === '.') {
            return '';
        }
        return $root . '/';
    }

    private function is_recovery_root(string $root): bool {
        $root = $this->normalize_root($root);
        if ($root === '') {
            return true;
        }
        $toolkit = $this->normalize_root($this->toolkit_root());
        if ($toolkit !== '' && strpos($root, $toolkit) === 0) {
            return true;
        }
        return (bool) preg_match('#/core/fresh/?$#', rtrim($root, '/'));
    }

    private function is_under_toolkit(string $abs): bool {
        return $this->path_is_under($abs, $this->toolkit_root());
    }

    private function real_path(string $path): string {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }
        $r = @realpath($path);
        if (!is_string($r) || $r === '') {
            return $path;
        }
        return rtrim(str_replace('\\', '/', $r), '/');
    }

    private function path_is_under(string $abs, string $root): bool {
        $a = $this->real_path($abs);
        $r = $this->real_path($root);
        if ($a === '' || $r === '') {
            $a = str_replace('\\', '/', $abs);
            $r = rtrim(str_replace('\\', '/', $root), '/');
        }
        if ($a === '' || $r === '') {
            return false;
        }
        return $a === $r || strpos($a, $r . '/') === 0;
    }

    private function abs_from_rel(string $rel): string {
        $root = $this->site_root();
        if ($root === '') {
            return '';
        }
        return $root . ltrim(str_replace('\\', '/', $rel), '/');
    }

    private function rel(string $root, string $abs): string {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $abs = str_replace('\\', '/', $abs);
        if (strpos($abs, $root) === 0) {
            return ltrim(substr($abs, strlen($root)), '/');
        }
        return ltrim($abs, '/');
    }

    /** PHP sitting in the WordPress root that is not a packaged core file. @return string[] */
    private function list_root_extra_php(string $root): array {
        $out = [];
        $official = [
            'index.php' => true, 'wp-activate.php' => true, 'wp-blog-header.php' => true,
            'wp-comments-post.php' => true, 'wp-config.php' => true, 'wp-config-sample.php' => true,
            'wp-cron.php' => true, 'wp-links-opml.php' => true, 'wp-load.php' => true,
            'wp-login.php' => true, 'wp-mail.php' => true, 'wp-settings.php' => true,
            'wp-signup.php' => true, 'wp-trackback.php' => true, 'xmlrpc.php' => true,
        ];
        foreach (['php', 'phtml', 'phar'] as $ext) {
            foreach (glob($root . '*.' . $ext) ?: [] as $abs) {
                if (isset($official[strtolower(basename($abs))])) {
                    continue;
                }
                $out[] = $abs;
            }
        }
        return $out;
    }

    /** Direct children only (not recursive). @return string[] */
    private function list_php_shallow(string $dir, int $cap): array {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $dh = @opendir($dir);
        if ($dh === false) {
            return $out;
        }
        while (($name = readdir($dh)) !== false) {
            if (count($out) >= $cap) {
                break;
            }
            if ($name === '.' || $name === '..') {
                continue;
            }
            $abs = $dir . '/' . $name;
            if (!is_file($abs)) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['php', 'phtml', 'php5', 'php7', 'php8', 'phar'], true)) {
                continue;
            }
            $out[] = $abs;
        }
        closedir($dh);
        return $out;
    }

    /** PHP/config under uploads, depth-capped. @return string[] */
    private function list_uploads_php_config(string $dir, int $cap, int $max_depth): array {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        try {
            $inner = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
            $it = new RecursiveIteratorIterator($inner);
            $it->setMaxDepth(max(0, $max_depth));
            foreach ($it as $file) {
                if (count($out) >= $cap) {
                    break;
                }
                if (!$file->isFile()) {
                    continue;
                }
                $name = strtolower($file->getFilename());
                $ext = strtolower($file->getExtension());
                $hit = in_array($ext, ['php', 'phtml', 'php5', 'php7', 'php8', 'phar', 'ini'], true)
                    || $name === '.htaccess'
                    || $name === '.user.ini'
                    || $name === 'web.config';
                if (!$hit) {
                    continue;
                }
                $out[] = $file->getPathname();
            }
        } catch (Throwable $e) {
            return $out;
        }
        return $out;
    }

    /** @return string[] absolute paths */
    private function list_php(string $dir, int $cap): array {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (count($out) >= $cap) {
                    break;
                }
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['php', 'phtml', 'php5', 'php7', 'php8'], true)) {
                    continue;
                }
                // Skip our agent thrash on itself during listing size — still included in baselines
                $out[] = $file->getPathname();
            }
        } catch (Throwable $e) {
            return $out;
        }
        return $out;
    }
}
