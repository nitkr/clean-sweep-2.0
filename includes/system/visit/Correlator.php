<?php
/**
 * Rank a likely writer / entry from visit diffs, extras, and threats.
 *
 * Evidence hierarchy (hard → soft):
 *  1. same_hash / ctime_close / also_new  (linked to payload)
 *  2. schedule_file (WP-Cron or Action Scheduler callback file match only)
 *  3. always_on (mu-plugin / drop-in) / preboot (.htaccess, .user.ini, …)
 *  4. content_write (bounded static read; optional)
 *  5. signature / package_entrypoint (main-file checksum)
 *  6. package_noise (integrity extras) — never wins alone
 *
 * Restricted hosts: no exotic PHP; content pass uses size-capped reads only.
 */
final class CleanSweep_Correlator {

    private const HARD_EVIDENCE = [
        'same_hash',
        'ctime_close',
        'also_new',
        'schedule_file',
        'content_write',
        'always_on',
        'signature',
        'preboot',
        'package_entrypoint',
        'watch_event',
        'watch_request',
        'watch_same_tick',
    ];

    private CleanSweep_VisitStore $store;
    private CleanSweep_VisitCapabilities $caps;

    public function __construct(?CleanSweep_VisitStore $store = null, ?CleanSweep_VisitCapabilities $caps = null) {
        $this->store = $store ?: new CleanSweep_VisitStore();
        $this->caps = $caps ?: CleanSweep_VisitCapabilities::instance();
    }

    /**
     * @param array<int,array> $core_violations from CleanSweep_ScopeSealer::compare_sealed
     * @param array<int,array> $threats optional scan threats
     * @param array<int,string|array> $payload_paths files that changed (victims, not writers)
     */
    public function run(array $core_violations = [], array $threats = [], array $payload_paths = []): array {
        $payload_keys = $this->payload_keys($core_violations, $payload_paths);
        $has_payload = $payload_keys !== [];
        $unexpected = $this->store->unexpected();
        $candidates = [];
        $seen = [];

        foreach ($unexpected as $u) {
            $path = (string) ($u['path'] ?? '');
            $key = $this->path_key($path);
            if ($path === '' || isset($seen[$key]) || !isset($payload_keys[$key])) {
                continue;
            }
            $seen[$key] = true;
            $row = $this->score_path($path, is_array($u['sample'] ?? null) ? $u['sample'] : [], $u['reason'] ?? 'change', $core_violations);
            $row['role'] = 'payload';
            $row['score'] = 12;
            $row['why'] = 'This file changed (the finding). It is not automatically the writer';
            $row['evidence'] = ['payload'];
            $candidates[] = $row;
        }

        $payload_meta = $this->payload_meta($payload_keys, $unexpected);

        if ($threats === []) {
            $threats = $this->latest_scan_threats();
        }

        foreach ($this->same_hash_hits($payload_keys, $payload_meta) as $hit) {
            $key = $this->path_key($hit['path']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $row = $this->score_path($hit['path'], $hit['sample'], 'same hash', $core_violations);
            $row['score'] = 95;
            $row['why'] = 'Same hash as a changed file (copy of the drop)';
            $row['evidence'] = ['same_hash'];
            $row['role'] = 'writer';
            $candidates[] = $row;
        }

        foreach ($threats as $t) {
            if (!is_array($t)) {
                continue;
            }
            $path = (string) ($t['file'] ?? $t['path'] ?? '');
            // Prefer absolute file when path is package-relative
            if ($path !== '' && $path[0] !== '/' && !empty($t['file']) && is_string($t['file']) && $t['file'][0] === '/') {
                $path = (string) $t['file'];
            }
            $key = $this->path_key($path);
            if ($path === '' || isset($seen[$key]) || isset($payload_keys[$key])) {
                continue;
            }
            $thash = (string) ($t['hash'] ?? $t['file_hash'] ?? '');
            $linked = $core_violations
                || ($thash !== '' && $this->hash_in_payloads($thash, $payload_meta));
            if (!$linked) {
                continue;
            }
            $ctx = $this->path_kind($path);
            $pattern = (string) ($t['pattern'] ?? $t['type'] ?? '');
            // Sealed-core "file changed" is the payload, not the writer.
            if ($ctx['kind'] === 'core' && ($t['source'] ?? '') === 'integrity' && $pattern !== 'unexpected_core_php') {
                continue;
            }

            $built = $this->score_threat_as_candidate($path, $ctx, $pattern, $t, (bool) $core_violations);
            if ($built === null) {
                continue;
            }
            $seen[$key] = true;
            $candidates[] = $built;
        }

        // Soft package leftovers only when sealed core drifted — never as sole writer.
        if ($has_payload && $core_violations) {
            foreach ($this->store->samples('extra_php') as $path => $sample) {
                $key = $this->path_key((string) $path);
                if (isset($seen[$key]) || isset($payload_keys[$key])) {
                    continue;
                }
                if (!$this->looks_leftover_name((string) $path)) {
                    continue;
                }
                $seen[$key] = true;
                $row = $this->score_path((string) $path, is_array($sample) ? $sample : [], 'leftover name', $core_violations);
                $row['score'] = min(35, (int) ($row['score'] ?? 20));
                $row['why'] = 'Dropper-style leftover name in ' . ($this->path_kind((string) $path)['kind'] ?? 'plugin');
                $row['evidence'] = ['package_noise'];
                $row['role'] = 'noise';
                $candidates[] = $row;
            }
            foreach ($this->store->samples('uploads') as $path => $sample) {
                $key = $this->path_key((string) $path);
                if (isset($seen[$key]) || isset($payload_keys[$key])) {
                    continue;
                }
                $sample = is_array($sample) ? $sample : [];
                $is_php = (bool) preg_match('/\.(php\d*|phtml|phar)(\.|$)/i', (string) $path);
                if (!$is_php && empty($sample['php_in_image'])) {
                    continue;
                }
                // Silence stubs / empty directory guards — not writers
                if ($this->is_silence_stub((string) $path, $sample)) {
                    continue;
                }
                $seen[$key] = true;
                $row = $this->score_path((string) $path, $sample, 'leftover uploads', $core_violations);
                $row['why'] = !empty($sample['php_in_image'])
                    ? 'Leftover PHP inside an uploads image'
                    : 'Leftover executable in uploads';
                $row['score'] = 50;
                $row['evidence'] = ['package_noise'];
                $row['role'] = 'noise';
                $candidates[] = $row;
            }
        }

        if ($has_payload) {
            $this->link_by_time_and_new($candidates, $seen, $payload_keys, $payload_meta, $unexpected, $core_violations);
            if ($core_violations) {
                $this->add_site_owned_writers($candidates, $seen, $payload_keys, $core_violations);
                $this->add_wp_content_writers($candidates, $seen, $payload_keys, $core_violations);
            }
        }

        $this->apply_schedule_and_vulns($candidates);
        if ($core_violations || $has_payload) {
            $this->boost_content_writes($candidates, $payload_keys);
        }
        // Live watch events (Phase 3) — additive only; never used to discard malware signals
        $this->apply_watch_events($candidates, $seen, $payload_keys, $core_violations);
        $this->apply_path_priors($candidates);

        usort($candidates, static function ($a, $b) {
            $sa = (int) ($a['score'] ?? 0);
            $sb = (int) ($b['score'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            // Prefer harder evidence, then shallower paths
            $ha = self::hard_count($a);
            $hb = self::hard_count($b);
            if ($ha !== $hb) {
                return $hb <=> $ha;
            }
            $da = substr_count(str_replace('\\', '/', (string) ($a['path'] ?? '')), '/');
            $db = substr_count(str_replace('\\', '/', (string) ($b['path'] ?? '')), '/');
            return $da <=> $db;
        });

        $writer = $this->pick_writer($candidates, $payload_keys);
        $core_changed = $core_violations !== [];
        $entry = $this->entry_for($writer, $core_changed);
        $writer_is_payload = $writer && isset($payload_keys[$this->path_key((string) ($writer['path'] ?? ''))]);

        $confidence = $this->confidence_for($writer);
        $summary = $this->build_summary($writer, $writer_is_payload, $has_payload, $core_changed, $confidence);

        $writer_list = [];
        foreach ($candidates as $c) {
            if (!$this->include_in_suspect_list($c, $payload_keys, $writer)) {
                continue;
            }
            $writer_list[] = $c;
            if (count($writer_list) >= 6) {
                break;
            }
        }

        $out = [
            'summary' => $summary,
            'core_changed' => $core_changed,
            'writer_is_payload' => (bool) $writer_is_payload,
            'confidence' => $confidence,
            'core_files' => array_values(array_filter(array_map(static function ($v) {
                return $v['file'] ?? null;
            }, $core_violations))),
            'writer' => $writer,
            'entry' => $entry,
            'candidates' => $writer_list,
        ];
        $this->store->set_likely_source($out);
        return $out;
    }

    /**
     * @param array{kind:string,slug:?string} $ctx
     * @param array $t threat row
     */
    private function score_threat_as_candidate(
        string $path,
        array $ctx,
        string $pattern,
        array $t,
        bool $core_violations
    ): ?array {
        $source = (string) ($t['source'] ?? '');
        $score = 30;
        $why = 'Scan finding in ' . $ctx['kind'];
        $evidence = ['scan'];
        $role = 'writer';

        // Root official PHP that integrity flags as "modified" is almost always
        // the reinfection *payload*, not a separate writer.
        if ($this->is_root_official_basename($path)) {
            return null;
        }

        // Pre-boot scan heuristics (CleanSweep_RootConfigWorker) — hard persistence signals
        if (in_array($pattern, [
            'auto_prepend_file',
            'php_handler_on_asset',
            'rewrite_to_uploads_php',
            'ua_cloaking_rewrite',
        ], true)) {
            return [
                'path' => $path,
                'kind' => $ctx['kind'],
                'slug' => $ctx['slug'],
                'score' => $pattern === 'ua_cloaking_rewrite' ? 78 : 92,
                'why' => 'Pre-boot / rewrite persistence: ' . $pattern,
                'role' => 'persistence',
                'evidence' => ['preboot', 'signature'],
            ];
        }

        // Package map noise — low score, cannot win alone
        if (
            $pattern === 'unexpected_package_php'
            || $pattern === 'extra'
            || $pattern === 'baseline_unexpected_php'
            || $pattern === 'package_extras_rollup'
            || $pattern === 'package_baseline_extras_rollup'
            || $pattern === 'package_divergent'
            || $pattern === 'package_baseline_divergent'
            || $pattern === 'package_baseline_stale'
        ) {
            $slug = $ctx['slug'] ?: (string) ($t['package_slug'] ?? $ctx['kind']);
            if ($pattern === 'package_divergent' || $pattern === 'package_baseline_divergent') {
                $score = 22;
                $why = 'Package bulk-diverges from official/baseline map (' . $slug . '). Package hygiene, not a file writer.';
            } elseif ($pattern === 'package_extras_rollup' || $pattern === 'package_baseline_extras_rollup') {
                $score = 18;
                $why = 'Many PHP paths not in official/baseline package ' . $slug . ' (rolled up)';
            } elseif ($pattern === 'package_baseline_stale') {
                $score = 16;
                $why = 'Verification baseline version stale for ' . $slug;
            } else {
                $score = 24;
                $why = 'PHP file not in the official ' . ($ctx['slug'] ?: $ctx['kind']) . ' package (package map extra)';
            }
            $evidence = ['package_noise'];
            $role = 'noise';
            // Depth penalty for vendor leaves
            if ($this->is_deep_vendor_path($path)) {
                $score = max(10, $score - 8);
            }
            return [
                'path' => $path,
                'kind' => $ctx['kind'],
                'slug' => $ctx['slug'],
                'score' => $score,
                'why' => $why,
                'role' => $role,
                'evidence' => $evidence,
            ];
        }

        if ($pattern === 'baseline_mismatch') {
            $score = 52;
            $why = 'File changed since Clean Sweep verification baseline (' . ($ctx['slug'] ?: $ctx['kind']) . ')';
            $evidence = ['package_entrypoint'];
        } elseif ($pattern === 'unexpected_core_php') {
            $score = 70;
            $why = 'Extra PHP under WordPress core (not in the official package)';
            $evidence = ['signature'];
        } elseif ($pattern === 'checksum_mismatch' || (!empty($t['package_checksum']) && $pattern !== '')) {
            // Root core files already excluded above; remaining are package files
            $score = 58;
            $why = 'Official package file was modified (' . ($ctx['slug'] ?: $ctx['kind']) . ')';
            $evidence = ['package_entrypoint'];
            // Main plugin/theme bootstrap files are stronger entrypoints than nested files
            if ($this->looks_package_main_file($path, $ctx)) {
                $score += 12;
                $why .= '; package main/bootstrap file';
            }
        } elseif ($source !== 'integrity') {
            $score = 55 + (int) (($t['risk_score'] ?? 0) / 15);
            $why = 'Signature hit in ' . ($ctx['slug'] ?: $ctx['kind'] ?: 'site');
            $evidence = ['signature'];
        } elseif ($ctx['kind'] === 'other') {
            $score = 55;
            $why = 'Unexpected file outside plugins/themes (site root or other path)';
            $evidence = ['scan'];
        }

        if ($ctx['kind'] === 'mu-plugin' || $ctx['kind'] === 'drop-in') {
            $score += 28;
            $evidence[] = 'always_on';
            $why .= '; always loads';
        }

        // Core drift raises non-noise scan hits slightly — not package extras
        if ($core_violations && $role !== 'noise') {
            $score += 8;
        }

        if ($this->is_deep_vendor_path($path) && $role !== 'noise') {
            $score = max(20, $score - 18);
        }

        return [
            'path' => $path,
            'kind' => $ctx['kind'],
            'slug' => $ctx['slug'],
            'score' => $score,
            'why' => $why,
            'role' => $role,
            'evidence' => array_values(array_unique($evidence)),
        ];
    }

    /**
     * @param array<int,array> $core_violations
     * @param array<int,string|array> $payload_paths
     * @return array<string,true>
     */
    private function payload_keys(array $core_violations, array $payload_paths): array {
        $keys = [];
        foreach ($core_violations as $v) {
            $p = is_array($v) ? (string) ($v['file'] ?? $v['path'] ?? '') : (string) $v;
            if ($p !== '') {
                $keys[$this->path_key($p)] = true;
            }
        }
        foreach ($payload_paths as $v) {
            $p = is_array($v) ? (string) ($v['path'] ?? $v['file'] ?? '') : (string) $v;
            if ($p !== '') {
                $keys[$this->path_key($p)] = true;
            }
        }
        return $keys;
    }

    /**
     * Prefer a leftover / plugin / mu-plugin over the file that merely changed.
     * Requires hard evidence so package map noise cannot win.
     *
     * @param array<int,array> $candidates
     * @param array<string,true> $payload_keys
     */
    private function pick_writer(array $candidates, array $payload_keys): ?array {
        foreach ($candidates as $c) {
            $path = (string) ($c['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $role = (string) ($c['role'] ?? '');
            if ($role === 'payload' || $role === 'noise' || $role === 'expected') {
                continue;
            }
            if (isset($payload_keys[$this->path_key($path)])) {
                continue;
            }
            if ($this->is_root_official_basename($path)) {
                continue;
            }
            if ($this->is_clean_sweep_owned_path($path) && $this->only_always_on_evidence($c['evidence'] ?? [])) {
                continue;
            }
            if (!$this->is_sufficient_writer($c)) {
                continue;
            }
            if ((int) ($c['score'] ?? 0) < 40) {
                continue;
            }
            return $c;
        }
        return null;
    }

    /**
     * Named writer needs hard evidence that is not a weak solo prior.
     * always_on / ctime_close / also_new alone must not win (G1/G2).
     */
    private function is_sufficient_writer(array $c): bool {
        if (!$this->has_hard_evidence($c)) {
            return false;
        }
        $ev = [];
        foreach ($c['evidence'] ?? [] as $e) {
            $e = (string) $e;
            if (in_array($e, self::HARD_EVIDENCE, true)) {
                $ev[$e] = true;
            }
        }
        if ($ev === []) {
            return false;
        }
        // Strong channels may stand alone (incl. live watch — general malware signal)
        $solo_ok = [
            'same_hash',
            'schedule_file',
            'content_write',
            'preboot',
            'signature',
            'package_entrypoint',
            'watch_event',
            'watch_request',
            'watch_same_tick',
        ];
        foreach (array_keys($ev) as $tag) {
            if (in_array($tag, $solo_ok, true)) {
                return true;
            }
        }
        // Weak priors need a second hard tag
        return count($ev) >= 2;
    }

    private function has_hard_evidence(array $c): bool {
        $ev = $c['evidence'] ?? [];
        if (!is_array($ev) || $ev === []) {
            return false;
        }
        foreach ($ev as $e) {
            if (in_array((string) $e, self::HARD_EVIDENCE, true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array $c */
    private static function hard_count(array $c): int {
        $n = 0;
        foreach ($c['evidence'] ?? [] as $e) {
            if (in_array((string) $e, self::HARD_EVIDENCE, true)) {
                $n++;
            }
        }
        return $n;
    }

    private function confidence_for(?array $writer): string {
        if (!$writer) {
            return 'none';
        }
        $hard = self::hard_count($writer);
        $score = (int) ($writer['score'] ?? 0);
        if ($hard >= 2 || $score >= 100) {
            return 'high';
        }
        if ($hard >= 1 && $score >= 55) {
            return 'medium';
        }
        return 'low';
    }

    private function build_summary(
        ?array $writer,
        bool $writer_is_payload,
        bool $has_payload,
        bool $core_changed,
        string $confidence
    ): string {
        if ($writer && !$writer_is_payload) {
            $conf = $confidence !== 'none' ? ' [' . $confidence . ' confidence]' : '';
            return ($core_changed ? 'Sealed files changed. ' : '')
                . 'Likely writer: ' . $writer['path']
                . ($writer['why'] ? ' (' . $writer['why'] . ')' : '')
                . $conf;
        }
        if ($has_payload || $core_changed) {
            return ($core_changed ? 'Sealed files changed. ' : '')
                . 'No strong writer link (need same hash, matching schedule callback file, always-on loader, or content that targets the payload). Package map extras alone are not writers.';
        }
        return 'No separate writer.';
    }

    /**
     * @param array<string,true> $payload_keys
     * @param array<int,array> $unexpected
     * @return array<string,array{path:string,hash:?string,ctime:?int,mtime:?int}>
     */
    private function payload_meta(array $payload_keys, array $unexpected): array {
        $meta = [];
        foreach ($unexpected as $u) {
            $path = (string) ($u['path'] ?? '');
            $key = $this->path_key($path);
            if ($path === '' || !isset($payload_keys[$key])) {
                continue;
            }
            $sample = is_array($u['sample'] ?? null) ? $u['sample'] : [];
            $meta[$key] = [
                'path' => $path,
                'hash' => $sample['hash'] ?? null,
                'ctime' => isset($sample['ctime']) ? (int) $sample['ctime'] : null,
                'mtime' => isset($sample['mtime']) ? (int) $sample['mtime'] : null,
            ];
        }
        foreach (['extra_php', 'uploads', 'site_owned', 'wp_content'] as $bucket) {
            foreach ($this->store->samples($bucket) as $path => $sample) {
                $key = $this->path_key((string) $path);
                if (!isset($payload_keys[$key]) || isset($meta[$key]['hash'])) {
                    continue;
                }
                $sample = is_array($sample) ? $sample : [];
                $meta[$key] = [
                    'path' => (string) $path,
                    'hash' => $sample['hash'] ?? null,
                    'ctime' => isset($sample['ctime']) ? (int) $sample['ctime'] : null,
                    'mtime' => isset($sample['mtime']) ? (int) $sample['mtime'] : null,
                ];
            }
        }
        return $meta;
    }

    /**
     * @param array<string,true> $payload_keys
     * @param array<string,array> $payload_meta
     * @return array<int,array{path:string,sample:array}>
     */
    private function same_hash_hits(array $payload_keys, array $payload_meta): array {
        $want = [];
        foreach ($payload_meta as $m) {
            $h = (string) ($m['hash'] ?? '');
            if ($h !== '') {
                $want[$h] = true;
            }
        }
        if ($want === []) {
            return [];
        }
        $hits = [];
        foreach (['extra_php', 'uploads', 'site_owned', 'wp_content'] as $bucket) {
            foreach ($this->store->samples($bucket) as $path => $sample) {
                $key = $this->path_key((string) $path);
                if (isset($payload_keys[$key])) {
                    continue;
                }
                $sample = is_array($sample) ? $sample : [];
                $h = (string) ($sample['hash'] ?? '');
                if ($h !== '' && isset($want[$h])) {
                    $hits[] = ['path' => (string) $path, 'sample' => $sample];
                }
            }
        }
        return $hits;
    }

    /**
     * @param array<int,array> $candidates
     * @param array<string,true> $seen
     * @param array<string,true> $payload_keys
     * @param array<string,array> $payload_meta
     * @param array<int,array> $unexpected
     * @param array<int,array> $core_violations
     */
    private function link_by_time_and_new(
        array &$candidates,
        array &$seen,
        array $payload_keys,
        array $payload_meta,
        array $unexpected,
        array $core_violations
    ): void {
        $payload_times = [];
        foreach ($payload_meta as $m) {
            foreach (['ctime', 'mtime'] as $k) {
                if (!empty($m[$k])) {
                    $payload_times[] = (int) $m[$k];
                }
            }
        }
        $new_keys = [];
        foreach ($unexpected as $u) {
            if (($u['reason'] ?? '') === 'created' || ($u['reason'] ?? '') === 'new') {
                $new_keys[$this->path_key((string) ($u['path'] ?? ''))] = true;
            }
        }
        $payload_also_new = false;
        foreach ($payload_keys as $k => $_) {
            if (isset($new_keys[$k])) {
                $payload_also_new = true;
                break;
            }
        }
        foreach (['extra_php', 'uploads', 'site_owned', 'wp_content'] as $bucket) {
            foreach ($this->store->samples($bucket) as $path => $sample) {
                $key = $this->path_key((string) $path);
                if ($key === '' || isset($seen[$key]) || isset($payload_keys[$key])) {
                    continue;
                }
                $sample = is_array($sample) ? $sample : [];
                $evidence = [];
                $why = [];
                if ($payload_also_new && isset($new_keys[$key])) {
                    $evidence[] = 'also_new';
                    $why[] = 'Also new since the snapshot';
                }
                $ct = isset($sample['ctime']) ? (int) $sample['ctime'] : 0;
                $mt = isset($sample['mtime']) ? (int) $sample['mtime'] : 0;
                foreach ($payload_times as $pt) {
                    if (($ct && abs($ct - $pt) <= 90) || ($mt && abs($mt - $pt) <= 90)) {
                        $evidence[] = 'ctime_close';
                        $why[] = 'Written within 90 seconds of the changed file';
                        break;
                    }
                }
                if ($evidence === []) {
                    continue;
                }
                $seen[$key] = true;
                $row = $this->score_path((string) $path, $sample, implode('; ', $why), $core_violations);
                // Time proximity alone is a weak prior (plugin updates during reinfection).
                $row['score'] = in_array('also_new', $evidence, true) ? 58 : 48;
                $row['why'] = implode('; ', $why);
                $row['evidence'] = $evidence;
                $row['role'] = 'writer';
                $candidates[] = $row;
            }
        }
    }

    /** @param array<string,array> $payload_meta */
    private function hash_in_payloads(string $hash, array $payload_meta): bool {
        foreach ($payload_meta as $m) {
            if (!empty($m['hash']) && hash_equals((string) $m['hash'], $hash)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,array> $candidates
     * @param array<string,true> $seen
     * @param array<string,true> $payload_keys
     */
    private function add_wp_content_writers(array &$candidates, array &$seen, array $payload_keys, array $core_violations): void {
        foreach ($this->store->samples('wp_content') as $path => $sample) {
            $key = $this->path_key((string) $path);
            if (isset($seen[$key]) || isset($payload_keys[$key])) {
                continue;
            }
            if (!$core_violations && !$this->looks_leftover_name((string) $path)) {
                continue;
            }
            if ($this->is_silence_stub((string) $path, is_array($sample) ? $sample : [])) {
                continue;
            }
            $seen[$key] = true;
            $row = $this->score_path((string) $path, is_array($sample) ? $sample : [], 'wp-content leftover', $core_violations);
            $row['why'] = 'Leftover executable under wp-content (not plugins/themes/uploads)';
            $row['score'] = 48;
            $row['evidence'] = ['package_noise'];
            $row['role'] = 'noise';
            $candidates[] = $row;
        }
    }

    /**
     * @param array<int,array> $candidates
     * @param array<string,true> $seen
     * @param array<string,true> $payload_keys
     */
    private function add_site_owned_writers(array &$candidates, array &$seen, array $payload_keys, array $core_violations): void {
        $unexpected_keys = $this->unexpected_path_keys();
        $sealed_owned = $this->sealed_site_owned_hashes();

        foreach ($this->store->samples('site_owned') as $path => $sample) {
            $key = $this->path_key((string) $path);
            if (isset($seen[$key]) || isset($payload_keys[$key])) {
                continue;
            }
            $base = strtolower(basename(str_replace('\\', '/', (string) $path)));
            $ctx = $this->path_kind((string) $path);
            $sample = is_array($sample) ? $sample : [];

            // Pre-boot config — when drifted (unexpected / vs seal) and/or hostile content
            if (in_array($base, ['.htaccess', '.user.ini', 'php.ini', 'web.config'], true)) {
                $hostile = $this->preboot_hostile_signals((string) $path);
                $drifted = isset($unexpected_keys[$key])
                    || $this->preboot_drifted_vs_seal((string) $path, $sample, $sealed_owned)
                    || $this->preboot_drifted_vs_live((string) $path, $sample);

                // Hostile pre-boot is always worth listing (even without core drift).
                // Clean unchanged pre-boot is not.
                if ($hostile === [] && !$drifted) {
                    continue;
                }
                // Drift-only without core change or hostile: keep as soft persistence noise only
                // if core also drifted (reinfection context).
                if ($hostile === [] && $drifted && !$core_violations) {
                    continue;
                }

                $seen[$key] = true;
                $row = $this->score_path((string) $path, $sample, 'pre-boot config', $core_violations);
                $score = 50;
                $why_bits = [];
                $evidence = ['preboot'];
                if ($drifted) {
                    $score += 22;
                    $why_bits[] = 'changed since seal/census baseline';
                }
                if ($hostile !== []) {
                    $score += 30;
                    $why_bits[] = 'hostile directives: ' . implode(', ', $hostile);
                    $evidence[] = 'signature';
                } else {
                    $why_bits[] = 'pre-boot config (can run PHP before WordPress loads)';
                }
                if ($core_violations) {
                    $score += 8;
                }
                $row['score'] = $score;
                $row['why'] = implode('; ', $why_bits);
                $row['evidence'] = array_values(array_unique($evidence));
                $row['role'] = 'persistence';
                $candidates[] = $row;
                continue;
            }

            if ($base === 'wp-config.php' || $this->is_root_official_basename((string) $path)) {
                continue;
            }

            if (!in_array($ctx['kind'], ['mu-plugin', 'drop-in'], true)) {
                continue;
            }
            if ($this->is_clean_sweep_owned_path((string) $path)) {
                continue;
            }

            $seen[$key] = true;
            $row = $this->score_path((string) $path, $sample, 'always-on loader', $core_violations);
            if ($ctx['kind'] === 'mu-plugin') {
                // Prior only — is_sufficient_writer requires a second hard tag
                $row['score'] = 70;
                $row['why'] = 'Must-use plugin (always loads on every request)';
                $row['evidence'] = ['always_on'];
                $row['role'] = 'writer';
            } else {
                $row['score'] = 52;
                $row['why'] = 'Drop-in (loads early). Review if unexpected. Weak signal alone.';
                $row['evidence'] = ['always_on'];
                $row['role'] = 'writer';
            }
            $candidates[] = $row;
        }
    }

    /**
     * Sealed site-owned hashes from CleanSweep_ScopeSealer (recorded at core seal).
     *
     * @return array<string,string> path_key => hash
     */
    private function sealed_site_owned_hashes(): array {
        $data = $this->store->state()->load();
        $files = $data['scopes']['site_owned']['files'] ?? null;
        if (!is_array($files)) {
            return [];
        }
        $out = [];
        foreach ($files as $rel => $sample) {
            if (!is_array($sample) || empty($sample['hash'])) {
                continue;
            }
            $out[$this->path_key((string) $rel)] = (string) $sample['hash'];
        }
        return $out;
    }

    /**
     * @param array $sample census sample
     * @param array<string,string> $sealed_owned
     */
    private function preboot_drifted_vs_seal(string $path, array $sample, array $sealed_owned): bool {
        $key = $this->path_key($path);
        $base = strtolower(basename(str_replace('\\', '/', $path)));
        // Seal stores bare names like ".htaccess"
        $sealed_hash = $sealed_owned[$key]
            ?? $sealed_owned[$base]
            ?? $sealed_owned[$this->path_key($base)]
            ?? null;
        if ($sealed_hash === null || $sealed_hash === '') {
            return false;
        }
        $cur = (string) ($sample['hash'] ?? '');
        if ($cur === '') {
            $abs = $this->abs_path($path);
            $cur = (string) ($this->caps->hash_path($abs) ?? '');
        }
        return $cur !== '' && !hash_equals($sealed_hash, $cur);
    }

    /**
     * Re-hash disk vs census sample when sample is stale relative to live file.
     * Handles correlator run after a write without a fresh unexpected entry.
     */
    private function preboot_drifted_vs_live(string $path, array $sample): bool {
        $stored = (string) ($sample['hash'] ?? '');
        if ($stored === '') {
            return false;
        }
        $abs = $this->abs_path($path);
        $live = $this->caps->hash_path($abs);
        if (!is_string($live) || $live === '') {
            return false;
        }
        // Sample is current after census; live mismatch means post-census change
        return !hash_equals($stored, $live);
    }

    /**
     * Paths that census marked unexpected (created/modified/gone).
     *
     * @return array<string,true>
     */
    private function unexpected_path_keys(): array {
        $keys = [];
        foreach ($this->store->unexpected() as $u) {
            if (!is_array($u)) {
                continue;
            }
            $p = (string) ($u['path'] ?? '');
            if ($p === '') {
                continue;
            }
            $keys[$this->path_key($p)] = true;
        }
        return $keys;
    }

    /**
     * Hostile pre-boot directives (restricted-host safe: size-capped file read).
     *
     * @return string[] short codes
     */
    private function preboot_hostile_signals(string $path): array {
        $abs = $this->abs_path($path);
        if ($abs === '' || !is_readable($abs) || is_dir($abs)) {
            return [];
        }
        $size = $this->caps->size($abs);
        if ($size !== null && ($size <= 0 || $size > 262144)) {
            return [];
        }
        $content = @file_get_contents($abs, false, null, 0, 262144);
        if (!is_string($content) || $content === '') {
            return [];
        }
        $hits = [];
        if (preg_match('/^\s*(?:php_value\s+)?auto_(?:prepend|append)_file\s*=\s*\S+/im', $content)) {
            $hits[] = 'auto_prepend/append';
        }
        if (preg_match('/(?:AddHandler|SetHandler|AddType)\s+[^\r\n]*(?:application\/x-httpd-php|php)/i', $content)) {
            $hits[] = 'php_handler';
        }
        if (preg_match('/RewriteRule\s+[^\r\n]*(uploads|wp-content\/cache|\/tmp\/)[^\r\n]*\.php/i', $content)) {
            $hits[] = 'rewrite_to_php';
        }
        if (preg_match('/RewriteCond\s+%\{HTTP_USER_AGENT\}/i', $content) && preg_match('/RewriteRule\s+/i', $content)) {
            $hits[] = 'ua_cloaking';
        }
        return $hits;
    }

    /**
     * Whether a candidate belongs on the "other suspects" shortlist.
     *
     * @param array $c
     * @param array<string,true> $payload_keys
     * @param array|null $winner
     */
    private function include_in_suspect_list(array $c, array $payload_keys, ?array $winner): bool {
        $role = (string) ($c['role'] ?? '');
        if ($role === 'payload' || $role === 'noise' || $role === 'expected') {
            return false;
        }
        $path = (string) ($c['path'] ?? '');
        if ($path === '' || empty($c['evidence'])) {
            return false;
        }
        if (isset($payload_keys[$this->path_key($path)])) {
            return false;
        }
        if ($this->is_root_official_basename($path)) {
            return false;
        }
        if ($this->is_silence_stub($path, [])) {
            return false;
        }
        $ev = $c['evidence'] ?? [];
        if (!is_array($ev)) {
            return false;
        }
        // Soft package_noise alone never makes the shortlist
        $hard = array_values(array_intersect($ev, self::HARD_EVIDENCE));
        $score = (int) ($c['score'] ?? 0);
        if ($hard === [] && $score < 55) {
            return false;
        }
        if ($hard === [] && in_array('package_noise', $ev, true)) {
            return false;
        }
        if ($this->is_clean_sweep_owned_path($path) && $this->only_always_on_evidence($ev)) {
            return false;
        }
        // Mu-plugin / drop-in with only always_on is clutter once a strong writer is named
        if (
            in_array((string) ($c['kind'] ?? ''), ['drop-in', 'mu-plugin'], true)
            && $this->only_always_on_evidence($ev)
            && $winner
            && self::hard_count($winner) >= 2
        ) {
            return false;
        }
        // With a strong primary writer, only list competitive suspects
        if ($winner && self::hard_count($winner) >= 2) {
            if ($score < 55) {
                return false;
            }
            // Single weak hard tag (e.g. low-score signature) without schedule/content
            $strong = array_intersect($hard, [
                'schedule_file',
                'content_write',
                'same_hash',
                'ctime_close',
                'also_new',
                'preboot',
                'package_entrypoint',
                'always_on',
                'watch_event',
                'watch_request',
            ]);
            if ($hard === ['signature'] && $score < 70) {
                return false;
            }
            if ($strong === [] && $hard === ['signature']) {
                return false;
            }
        }
        if (!$this->has_hard_evidence($c) && $score < 50) {
            return false;
        }
        return true;
    }

    /**
     * True only for site-root official WordPress basenames (not nested namesakes).
     * e.g. wp-load.php at ABSPATH — not plugins/evil/index.php or plugins/x/wp-load.php.
     */
    private function is_root_official_basename(string $path): bool {
        $p = str_replace('\\', '/', strtolower(trim($path)));
        $p = ltrim($p, '/');
        // Nested under WP trees → not site root
        if (preg_match('#(?:^|/)(wp-content|wp-admin|wp-includes)(/|$)#', $p)) {
            return false;
        }
        // Absolute path: only basename after last slash of install root — require no extra dirs
        // after a known parent. If path has multiple segments that aren't just the basename, reject.
        $base = basename($p);
        if (!preg_match(
            '/^(index|wp-activate|wp-blog-header|wp-comments-post|wp-config-sample|wp-config|wp-cron|wp-links-opml|wp-load|wp-login|wp-mail|wp-settings|wp-signup|wp-trackback|xmlrpc)\.php$/',
            $base
        )) {
            return false;
        }
        // Bare basename
        if ($p === $base) {
            return true;
        }
        // Absolute FS path ending with /basename and not under wp-* (e.g. /var/www/site/wp-load.php)
        // Allow exactly one trailing basename; parent may be anything except wp-content/admin/includes
        return (bool) preg_match('#/' . preg_quote($base, '#') . '$#', $p)
            && !preg_match('#/(wp-content|wp-admin|wp-includes)/#', $p);
    }

    /**
     * Common empty / "silence is golden" directory guards under uploads/cache only.
     *
     * @param array $sample optional census sample (size)
     */
    private function is_silence_stub(string $path, array $sample): bool {
        $p = str_replace('\\', '/', strtolower($path));
        $base = basename($p);
        if ($base !== 'index.php' && $base !== 'index.html') {
            return false;
        }
        // Path gate: only guard stubs in uploads / cache-like trees (not plugins/themes)
        $in_guard_tree = (bool) preg_match(
            '#(?:^|/)(uploads|cache|wphb-cache|wp-rocket-config|smush|wpforms|et-cache|litespeed|w3tc-config)(/|$)#',
            $p
        );
        if (!$in_guard_tree) {
            return false;
        }

        $size = isset($sample['size']) ? (int) $sample['size'] : null;
        if ($size !== null && $size > 0 && $size <= 80) {
            return true;
        }
        $abs = $this->abs_path($path);
        if ($abs === '' || !is_readable($abs)) {
            return $size !== null && $size <= 80;
        }
        $sz = $this->caps->size($abs);
        if ($sz !== null && $sz > 120) {
            return false;
        }
        $data = @file_get_contents($abs, false, null, 0, 200);
        if (!is_string($data)) {
            return $sz !== null && $sz <= 80;
        }
        $trim = trim($data);
        if ($trim === '' || $trim === '<?php' || $trim === '<?php // Silence is golden.'
            || preg_match('/^<\?php\s*(\/\/\s*Silence is golden\.?\s*)?$/i', $trim)
            || (preg_match('/Silence is golden/i', $trim) && strlen($trim) < 80)) {
            return true;
        }
        return strlen($trim) <= 40 && strpos($trim, '<?php') === 0;
    }

    /** @return array<int,array> */
    private function latest_scan_threats(): array {
        $cp = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'features/security/scan/Checkpoint.php'
            : dirname(__DIR__, 2) . '/features/security/scan/Checkpoint.php';
        $ts = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'features/security/scan/ThreatStore.php'
            : dirname(__DIR__, 2) . '/features/security/scan/ThreatStore.php';
        if (is_readable($cp)) {
            require_once $cp;
        }
        if (is_readable($ts)) {
            require_once $ts;
        }
        if (!class_exists('CleanSweep_Checkpoint') || !class_exists('CleanSweep_ThreatStore')) {
            return [];
        }
        try {
            $state = CleanSweep_Checkpoint::findLatestForUi(172800);
            $id = is_object($state) ? (string) ($state->scan_id ?? '') : '';
            if ($id === '') {
                return [];
            }
            // Prefer more rows so mu-plugin + package hits are both available
            return (new CleanSweep_ThreatStore($id))->all(200);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function looks_leftover_name(string $path): bool {
        $base = strtolower(basename(str_replace('\\', '/', $path)));
        if ($base === '') {
            return false;
        }
        if (preg_match('/\.(?:php\d*|phtml|phar)\./i', $base)) {
            return true;
        }
        return (bool) preg_match(
            '/^(extra|radio|wp-tmp|wp-cache|settings_bak|boo)\d*\.(?:php\d*|phtml|phar)$/i',
            $base
        );
    }

    private function is_deep_vendor_path(string $path): bool {
        // Use site-relative key so absolute FS prefixes do not inflate depth
        $p = $this->path_key($path);
        if ($p === '') {
            $p = str_replace('\\', '/', strtolower($path));
        }
        if (preg_match('#/(vendor|vendor_prefixed|node_modules|third[-_]?party)/#', $p)
            || preg_match('#^(vendor|vendor_prefixed|node_modules)/#', $p)) {
            return true;
        }
        // Deep only relative to site (e.g. wp-content/plugins/x/a/b/c/d/e.php)
        return substr_count($p, '/') >= 6;
    }

    /** @param array{kind:string,slug:?string} $ctx */
    private function looks_package_main_file(string $path, array $ctx): bool {
        $p = $this->path_key($path);
        if ($p === '') {
            $p = str_replace('\\', '/', strtolower($path));
        }
        $base = basename($p);
        $slug = (string) ($ctx['slug'] ?? '');
        if ($slug !== '' && strcasecmp($base, $slug . '.php') === 0) {
            return true;
        }
        if ($slug !== '' && preg_match('#(?:^|/)plugins/' . preg_quote($slug, '#') . '/' . preg_quote($slug, '#') . '\.php$#i', $p)) {
            return true;
        }
        // Site-relative depth only
        return (bool) preg_match('#(?:^|/)plugins/[^/]+/[^/]+\.php$#', $p)
            && substr_count($p, '/') <= 3;
    }

    private function score_path(string $path, array $sample, string $reason, array $core_violations): array {
        $ctx = $this->path_kind($path);
        $score = 10;
        $why = $reason;
        $evidence = [];
        if ($ctx['kind'] === 'mu-plugin') {
            $score += 50;
            $why = 'Must-use plugin (always loads)';
            $evidence[] = 'always_on';
        } elseif ($ctx['kind'] === 'plugin') {
            $score += 35;
            $why = 'Extra/changed PHP in plugin ' . ($ctx['slug'] ?: '');
        } elseif ($ctx['kind'] === 'uploads') {
            $score += 40;
            $why = !empty($sample['php_in_image']) ? 'PHP inside an uploads image' : 'Executable in uploads';
        } elseif ($ctx['kind'] === 'drop-in') {
            $score += 48;
            $why = 'Drop-in';
            $evidence[] = 'always_on';
        } elseif ($ctx['kind'] === 'theme') {
            $score += 30;
            $why = 'Extra/changed PHP in theme ' . ($ctx['slug'] ?: '');
        } elseif ($ctx['kind'] === 'other') {
            $score += 45;
            $why = 'Unexpected file (site root or non-package path)';
        } elseif ($ctx['kind'] === 'core') {
            $score += 25;
            $why = 'Extra or changed file under WordPress core';
        }

        $mtime = $sample['mtime'] ?? null;
        $ctime = $sample['ctime'] ?? null;
        if ($this->caps->ctime_is_inode && $ctime && $mtime && ($ctime - $mtime) > 86400 * 30) {
            $score += 20;
            $why .= '; timestomp (ctime recent, mtime old)';
        } elseif ($mtime && $core_violations) {
            $score += 3;
        }

        return [
            'path' => $path,
            'kind' => $ctx['kind'],
            'slug' => $ctx['slug'],
            'score' => $score,
            'why' => $why,
            'role' => 'writer',
            'evidence' => $evidence,
        ];
    }

    /** Clean Sweep's own agent / toolkit paths are not site malware writers. */
    private function is_clean_sweep_owned_path(string $path): bool {
        $p = str_replace('\\', '/', strtolower($path));
        $base = basename($p);
        $agent = '00-clean-sweep-visit-watch.php';
        if (class_exists('CleanSweep_VisitWatch', false)) {
            $agent = strtolower(CleanSweep_VisitWatch::AGENT_BASENAME);
        }
        if ($base === $agent) {
            return true;
        }
        if (preg_match('#(?:^|/)clean-sweep/#', $p)) {
            return true;
        }
        return false;
    }

    /** @param mixed $evidence */
    private function only_always_on_evidence($evidence): bool {
        if (!is_array($evidence) || $evidence === []) {
            return false;
        }
        $hard = [];
        foreach ($evidence as $e) {
            $e = (string) $e;
            if (in_array($e, self::HARD_EVIDENCE, true)) {
                $hard[$e] = true;
            }
        }
        return array_keys($hard) === ['always_on'];
    }

    /** @return array{kind:string,slug:?string} */
    private function path_kind(string $path): array {
        $p = str_replace('\\', '/', strtolower($path));
        if (preg_match('#(?:^|/)wp-content/mu-plugins/#', $p)) {
            return ['kind' => 'mu-plugin', 'slug' => null];
        }
        if (preg_match('#^plugin:([^/]+)/#', $p, $m)) {
            return ['kind' => 'plugin', 'slug' => $m[1]];
        }
        if (preg_match('#^theme:([^/]+)/#', $p, $m)) {
            return ['kind' => 'theme', 'slug' => $m[1]];
        }
        if (preg_match('#(?:wp-content/)?plugins/([^/]+)#', $p, $m)) {
            return ['kind' => 'plugin', 'slug' => $m[1]];
        }
        if (preg_match('#(?:wp-content/)?themes/([^/]+)#', $p, $m)) {
            return ['kind' => 'theme', 'slug' => $m[1]];
        }
        if (strpos($p, 'uploads/') !== false) {
            return ['kind' => 'uploads', 'slug' => null];
        }
        if (preg_match('#wp-content/(db|object-cache|advanced-cache|sunrise)\.php$#', $p)) {
            return ['kind' => 'drop-in', 'slug' => null];
        }
        if (strpos($p, 'wp-admin/') !== false || strpos($p, 'wp-includes/') !== false) {
            return ['kind' => 'core', 'slug' => null];
        }
        return ['kind' => 'other', 'slug' => null];
    }

    /**
     * Apply opt-in live watch events (CleanSweep_VisitWatch). Additive only:
     *  - Expected Clean Sweep writes (reinstall) never become writers
     *  - Unexpected created PHP in always-on / drop-in / uploads is persistence
     *  - Request script (if known) is boosted as a writer candidate
     *  - Same-tick pairing: new PHP + core/config change in one tick
     * Never filters out scan/signature findings.
     *
     * @param array<int,array> $candidates
     * @param array<string,true> $seen
     * @param array<string,true> $payload_keys
     * @param array<int,array> $core_violations
     */
    private function apply_watch_events(
        array &$candidates,
        array &$seen,
        array &$payload_keys,
        array $core_violations
    ): void {
        if (!class_exists('CleanSweep_VisitWatch', false)) {
            return;
        }
        try {
            $events = (new CleanSweep_VisitWatch($this->store->state()))->recent_events(60);
        } catch (Throwable $e) {
            return;
        }
        if ($events === []) {
            return;
        }

        $cut = time() - 172800;
        $unexpected = [];
        foreach ($events as $ev) {
            if (!is_array($ev) || (int) ($ev['t'] ?? $ev['first_seen'] ?? 0) < $cut) {
                continue;
            }
            $path = (string) ($ev['path'] ?? '');
            $kind = (string) ($ev['kind'] ?? 'modified');
            if ($path === '') {
                continue;
            }
            if (!empty($ev['expected'])) {
                // Reinstall / known CS write — keep off the writer list.
                continue;
            }
            if ((string) ($ev['kind'] ?? '') === 'already_present'
                || (string) ($ev['noise'] ?? '') === 'directory_guard') {
                continue;
            }
            if ($this->is_clean_sweep_owned_path($path)) {
                continue;
            }
            $unexpected[] = $ev;
            $key = $this->path_key($path);
            $ctx = $this->path_kind($path);
            $when = (int) ($ev['first_seen'] ?? $ev['t'] ?? 0);
            $who = $this->watch_who($ev);

            // Changed high-value / core-like paths count as payload context
            if (
                $this->is_root_official_basename($path)
                || $ctx['kind'] === 'core'
                || in_array($ctx['kind'], ['drop-in', 'mu-plugin'], true)
                || preg_match('#(?:^|/)(\.htaccess|\.user\.ini|php\.ini|web\.config)$#', strtolower($path))
            ) {
                $payload_keys[$key] = true;
            }

            $persistence = $kind === 'created' && (
                in_array($ctx['kind'], ['mu-plugin', 'drop-in', 'uploads'], true)
                || $this->is_wp_content_root_php($path)
            );

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $row = $this->score_path($path, [], 'live watch ' . $kind, $core_violations);
                $row['score'] = $kind === 'created' ? 82 : 70;
                $row['why'] = 'Live watch: ' . $kind
                    . ($when > 0 ? ' first seen ' . gmdate('Y-m-d H:i:s', $when) . ' UTC' : '')
                    . $who;
                $row['evidence'] = ['watch_event'];
                $row['first_seen'] = $when;
                $row['watch_last_seen'] = (int) ($ev['last_seen'] ?? $when);
                if ($persistence) {
                    $row['role'] = 'writer';
                    $row['evidence'][] = 'always_on';
                    $row['score'] += 10;
                    $row['why'] .= '; always-on / drop location (persistence)';
                } elseif ($this->is_root_official_basename($path) || $ctx['kind'] === 'core') {
                    $row['role'] = 'payload';
                    $row['score'] = 20;
                } else {
                    $row['role'] = 'writer';
                }
                $candidates[] = $row;
            } else {
                foreach ($candidates as &$c) {
                    if ($this->path_key((string) ($c['path'] ?? '')) !== $key) {
                        continue;
                    }
                    if (!isset($c['evidence']) || !is_array($c['evidence'])) {
                        $c['evidence'] = [];
                    }
                    $c['evidence'][] = 'watch_event';
                    $c['evidence'] = array_values(array_unique($c['evidence']));
                    $c['score'] = (int) ($c['score'] ?? 0) + 25;
                    $c['why'] = trim(($c['why'] ?? '') . '; live watch confirmed ' . $kind . $who);
                    if (empty($c['first_seen']) && $when > 0) {
                        $c['first_seen'] = $when;
                    }
                    if (($c['role'] ?? '') === 'noise') {
                        $c['role'] = 'writer';
                    }
                    if ($persistence && ($c['role'] ?? '') === 'payload') {
                        $c['role'] = 'writer';
                    }
                }
                unset($c);
            }

            // Request script that ran when the change was observed — skip CS toolkit scripts
            $actor = (string) (($ev['request']['actor'] ?? '') ?: '');
            if ($actor === 'clean_sweep') {
                continue;
            }
            $script = (string) (($ev['request']['script'] ?? '') ?: '');
            if ($script === '') {
                continue;
            }
            $sk = $this->path_key($script);
            if ($sk === '' || isset($payload_keys[$sk])) {
                continue;
            }
            if ($this->is_root_official_basename($script) || preg_match('#(?:^|/)(index|wp-cron|xmlrpc)\.php$#i', $script)) {
                continue;
            }
            if (!isset($seen[$sk])) {
                $seen[$sk] = true;
                $sctx = $this->path_kind($script);
                $row = $this->score_path($script, [], 'request during live watch change', $core_violations);
                $row['score'] = 75;
                $row['why'] = 'Request script active when live watch saw ' . $path . ' ' . $kind . $who;
                $row['evidence'] = ['watch_request'];
                $row['role'] = 'writer';
                if ($sctx['kind'] === 'mu-plugin' || $sctx['kind'] === 'drop-in') {
                    $row['evidence'][] = 'always_on';
                    $row['score'] += 15;
                }
                $candidates[] = $row;
            } else {
                foreach ($candidates as &$c) {
                    if ($this->path_key((string) ($c['path'] ?? '')) !== $sk) {
                        continue;
                    }
                    if (!isset($c['evidence']) || !is_array($c['evidence'])) {
                        $c['evidence'] = [];
                    }
                    $c['evidence'][] = 'watch_request';
                    $c['evidence'] = array_values(array_unique($c['evidence']));
                    $c['score'] = (int) ($c['score'] ?? 0) + 22;
                    $c['why'] = trim(($c['why'] ?? '') . '; request script during live watch change' . $who);
                    if (($c['role'] ?? '') === 'noise') {
                        $c['role'] = 'writer';
                    }
                }
                unset($c);
            }
        }

        $this->pair_watch_same_tick($candidates, $seen, $unexpected, $core_violations);
    }

    /**
     * New PHP in a drop location in the same tick as a core/config change
     * is a stronger writer than either signal alone.
     *
     * @param array<int,array> $candidates
     * @param array<string,true> $seen
     * @param array<int,array> $events unexpected watch events
     * @param array<int,array> $core_violations
     */
    private function pair_watch_same_tick(
        array &$candidates,
        array &$seen,
        array $events,
        array $core_violations
    ): void {
        $window = 8;
        $created = [];
        $payloads = [];
        foreach ($events as $ev) {
            $path = (string) ($ev['path'] ?? '');
            $kind = (string) ($ev['kind'] ?? '');
            $t = (int) ($ev['last_seen'] ?? $ev['t'] ?? 0);
            if ($path === '' || $t <= 0) {
                continue;
            }
            $ctx = $this->path_kind($path);
            if ($kind === 'created' && (
                in_array($ctx['kind'], ['mu-plugin', 'drop-in', 'uploads'], true)
                || $this->is_wp_content_root_php($path)
            )) {
                $created[] = ['path' => $path, 't' => $t];
            }
            if (in_array($kind, ['modified', 'created'], true) && (
                $ctx['kind'] === 'core'
                || $this->is_root_official_basename($path)
                || (bool) preg_match('#(?:^|/)(\.htaccess|\.user\.ini|php\.ini|web\.config|wp-config\.php)$#', strtolower($path))
            )) {
                $payloads[] = ['path' => $path, 't' => $t];
            }
        }
        if ($created === [] || $payloads === []) {
            return;
        }
        foreach ($created as $crow) {
            $paired = [];
            foreach ($payloads as $prow) {
                if ($prow['path'] === $crow['path']) {
                    continue;
                }
                if (abs($prow['t'] - $crow['t']) > $window) {
                    continue;
                }
                $paired[] = $prow['path'];
            }
            if ($paired === []) {
                continue;
            }
            $key = $this->path_key($crow['path']);
            $extra = '; same tick as ' . implode(', ', array_slice($paired, 0, 3));
            $hit = false;
            foreach ($candidates as &$c) {
                if ($this->path_key((string) ($c['path'] ?? '')) !== $key) {
                    continue;
                }
                $hit = true;
                if (!isset($c['evidence']) || !is_array($c['evidence'])) {
                    $c['evidence'] = [];
                }
                $c['evidence'][] = 'watch_same_tick';
                $c['evidence'] = array_values(array_unique($c['evidence']));
                $c['score'] = (int) ($c['score'] ?? 0) + 20;
                $c['why'] = trim(($c['why'] ?? '') . $extra);
                $c['role'] = 'writer';
            }
            unset($c);
            if ($hit || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $row = $this->score_path($crow['path'], [], 'live watch same tick', $core_violations);
            $row['score'] = 90;
            $row['why'] = 'Live watch: new PHP' . $extra;
            $row['evidence'] = ['watch_event', 'watch_same_tick'];
            $row['role'] = 'writer';
            $candidates[] = $row;
        }
    }

    /** @param array $ev watch event */
    private function watch_who(array $ev): string {
        $req = is_array($ev['request'] ?? null) ? $ev['request'] : [];
        $bits = [];
        $actor = (string) ($req['actor'] ?? '');
        if ($actor === 'clean_sweep') {
            $bits[] = 'during Clean Sweep';
        }
        $script = (string) ($req['script'] ?? '');
        if ($script !== '' && $actor !== 'clean_sweep') {
            $bits[] = 'via ' . $script;
        }
        $uid = $req['user_id'] ?? null;
        if (is_int($uid) || (is_string($uid) && ctype_digit($uid))) {
            $bits[] = 'user ' . (int) $uid;
        }
        $ip = (string) ($req['ip'] ?? '');
        if ($ip !== '') {
            $bits[] = $ip;
        }
        return $bits === [] ? '' : ' (' . implode(', ', $bits) . ')';
    }

    private function is_wp_content_root_php(string $path): bool {
        return (bool) preg_match('#(?:^|/)wp-content/[^/]+\.(php\d*|phtml|phar)$#i', str_replace('\\', '/', $path));
    }

    /**
     * Boost only when scheduled callback file matches the candidate file.
     * Never apply whole-plugin-slug schedule boosts (false "loads this path").
     *
     * @param array<int,array> $candidates
     */
    private function apply_schedule_and_vulns(array &$candidates): void {
        if (!class_exists('CleanSweep_VisitSignals')) {
            return;
        }
        $schedules = CleanSweep_VisitSignals::cron_origins();
        $vulns = CleanSweep_VisitSignals::vulns_by_slug();
        foreach ($candidates as &$c) {
            $path = str_replace('\\', '/', (string) ($c['path'] ?? ''));
            $slug = (string) ($c['slug'] ?? '');
            foreach ($schedules as $cr) {
                $cf = str_replace('\\', '/', (string) ($cr['file'] ?? ''));
                $hook = (string) ($cr['hook'] ?? '');
                if ($cf === '' || $path === '') {
                    continue;
                }
                if (!$this->same_file($cf, $path)) {
                    continue;
                }
                $src = (string) ($cr['source'] ?? 'wp_cron');
                $label = $src === 'action_scheduler' ? 'Action Scheduler' : 'WP-Cron';
                $c['score'] = (int) ($c['score'] ?? 0) + 28;
                $c['why'] = trim(($c['why'] ?? '') . '; ' . $label . ' hook ' . $hook . ' callback is this file');
                $c['cron_hook'] = $hook;
                $c['schedule_source'] = $src;
                if (!isset($c['evidence']) || !is_array($c['evidence'])) {
                    $c['evidence'] = [];
                }
                $c['evidence'][] = 'schedule_file';
                if (($c['role'] ?? '') === 'noise') {
                    $c['role'] = 'writer';
                }
                break;
            }
            // Vuln is entry context only — small score, not hard writer proof
            if ($slug !== '' && !empty($vulns[$slug])) {
                $top = $vulns[$slug][0];
                $c['score'] = (int) ($c['score'] ?? 0) + 8;
                $c['vuln'] = $top;
                if (!isset($c['evidence']) || !is_array($c['evidence'])) {
                    $c['evidence'] = [];
                }
                $c['evidence'][] = 'vuln';
            }
            if (isset($c['evidence']) && is_array($c['evidence'])) {
                $c['evidence'] = array_values(array_unique($c['evidence']));
            }
        }
        unset($c);
    }

    /**
     * Bounded static content pass: write APIs + core path strings.
     * Optional channel — skipped when unreadable / too large / functions limited.
     *
     * @param array<int,array> $candidates
     * @param array<string,true> $payload_keys
     */
    private function boost_content_writes(array &$candidates, array $payload_keys): void {
        $payload_bases = [];
        foreach ($payload_keys as $k => $_) {
            $b = basename(str_replace('\\', '/', $k));
            if ($b !== '') {
                $payload_bases[$b] = true;
            }
        }
        $core_names = ['wp-load.php', 'wp-settings.php', 'wp-config.php', 'wp-config-sample.php'];
        foreach ($core_names as $b) {
            $payload_bases[$b] = true;
        }

        $checked = 0;
        foreach ($candidates as &$c) {
            if ($checked >= 40) {
                break;
            }
            $role = (string) ($c['role'] ?? '');
            if ($role === 'payload' || $role === 'noise') {
                continue;
            }
            $path = $this->abs_path((string) ($c['path'] ?? ''));
            if ($path === '' || !is_readable($path) || is_dir($path)) {
                continue;
            }
            $size = $this->caps->size($path);
            if ($size === null) {
                // filesize disabled — try a small read with hard cap via fopen if available
                if (!$this->caps->fopen) {
                    continue;
                }
            } elseif ($size > 180000 || $size <= 0) {
                continue;
            }
            $checked++;
            $data = @file_get_contents($path, false, null, 0, 180000);
            if (!is_string($data) || $data === '') {
                continue;
            }

            $has_write = (bool) preg_match(
                '/\b(file_put_contents|fwrite|fputs|file_put|copy)\s*\(/i',
                $data
            );
            $core_hits = 0;
            foreach (array_keys($payload_bases) as $base) {
                if ($base !== '' && stripos($data, $base) !== false) {
                    $core_hits++;
                }
            }
            if ($has_write && $core_hits >= 1) {
                $c['score'] = (int) ($c['score'] ?? 0) + 42;
                $c['why'] = trim(($c['why'] ?? '') . '; content references write APIs and core/payload paths');
                if (!isset($c['evidence']) || !is_array($c['evidence'])) {
                    $c['evidence'] = [];
                }
                $c['evidence'][] = 'content_write';
                $c['evidence'] = array_values(array_unique($c['evidence']));
                if ($role === 'noise') {
                    $c['role'] = 'writer';
                }
            }
        }
        unset($c);
    }

    /**
     * Final path priors: demote deep vendor leaves; slight boost always-on kinds.
     *
     * @param array<int,array> $candidates
     */
    private function apply_path_priors(array &$candidates): void {
        foreach ($candidates as &$c) {
            $path = (string) ($c['path'] ?? '');
            $kind = (string) ($c['kind'] ?? '');
            if ($this->is_deep_vendor_path($path) && !in_array('content_write', $c['evidence'] ?? [], true)
                && !in_array('schedule_file', $c['evidence'] ?? [], true)
            ) {
                $c['score'] = max(8, (int) ($c['score'] ?? 0) - 20);
            }
            if (($kind === 'mu-plugin' || $kind === 'drop-in')
                && !in_array('always_on', $c['evidence'] ?? [], true)
            ) {
                if (!isset($c['evidence']) || !is_array($c['evidence'])) {
                    $c['evidence'] = [];
                }
                $c['evidence'][] = 'always_on';
            }
        }
        unset($c);
    }

    private function entry_for(?array $writer, bool $core_changed): ?array {
        if (!$writer) {
            return null;
        }
        $slug = (string) ($writer['slug'] ?? '');
        $kind = (string) ($writer['kind'] ?? '');
        $vuln = $writer['vuln'] ?? null;
        $path = (string) ($writer['path'] ?? '');

        if ($kind === 'mu-plugin') {
            $why = 'Must-use plugin always loads; treat as persistence/writer until cleaned';
            if (!empty($writer['cron_hook'])) {
                $why .= '; scheduled as ' . $writer['cron_hook'];
            }
            return [
                'path' => $path,
                'slug' => $slug ?: null,
                'why' => $why,
            ];
        }

        if ($kind === 'drop-in') {
            return [
                'path' => $path,
                'slug' => null,
                'why' => 'Drop-in always loads before WordPress; treat as persistence until removed',
            ];
        }

        if (is_array($vuln) && $slug !== '') {
            $label = ($vuln['cve'] ?? '') !== '' ? $vuln['cve'] : ($vuln['name'] ?? 'known vulnerability');
            return [
                'path' => $path,
                'slug' => $slug,
                'why' => $kind . ' ' . $slug . ' has ' . $label
                    . ' (' . ($vuln['risk'] ?? 'risk') . '). Possible entry. The file may be the writer.',
                'cve' => $vuln['cve'] ?? '',
            ];
        }

        if (!empty($writer['cron_hook'])) {
            $src = (string) ($writer['schedule_source'] ?? 'wp_cron');
            $label = $src === 'action_scheduler' ? 'Action Scheduler' : 'WP-Cron';
            return [
                'path' => $path,
                'slug' => $slug ?: null,
                'why' => $label . ' hook ' . $writer['cron_hook'] . ' callback is this file (scheduled persistence)',
            ];
        }

        if (in_array('preboot', $writer['evidence'] ?? [], true)) {
            return [
                'path' => $path,
                'slug' => null,
                'why' => 'Pre-boot configuration can alter PHP execution before WordPress loads',
            ];
        }

        if ($slug !== '' && ($kind === 'plugin' || $kind === 'theme')) {
            $why = $core_changed
                ? 'Finding is inside this ' . $kind . '. Verify it is not the entrypoint (reinstall from a trusted zip if modified).'
                : 'Finding is inside this ' . $kind;
            $vulns = class_exists('CleanSweep_VisitSignals') ? CleanSweep_VisitSignals::vulns_by_slug() : [];
            if ($vulns === [] || !isset($vulns[$slug])) {
                $why .= '. Run a vulnerability scan to check for a known entry on this slug.';
            }
            return [
                'path' => $path,
                'slug' => $slug,
                'why' => $why,
            ];
        }

        return null;
    }

    private function path_key(string $path): string {
        $p = str_replace('\\', '/', strtolower($path));
        foreach (['wp-content/', 'wp-admin/', 'wp-includes/'] as $mark) {
            $i = strpos($p, $mark);
            if ($i !== false) {
                return substr($p, $i);
            }
        }
        // Root-level WordPress files (payload often listed as bare basename)
        $base = basename($p);
        if (preg_match(
            '/^(index|wp-activate|wp-blog-header|wp-comments-post|wp-config-sample|wp-config|wp-cron|wp-links-opml|wp-load|wp-login|wp-mail|wp-settings|wp-signup|wp-trackback|xmlrpc)\.php$/',
            $base
        )) {
            return $base;
        }
        return ltrim($p, '/');
    }

    private function same_file(string $a, string $b): bool {
        $a = str_replace('\\', '/', $a);
        $b = str_replace('\\', '/', $b);
        if ($a === $b) {
            return true;
        }
        $ka = $this->path_key($a);
        $kb = $this->path_key($b);
        return $ka !== '' && $ka === $kb;
    }

    private function abs_path(string $rel): string {
        if ($rel !== '' && ($rel[0] === '/' || preg_match('#^[A-Za-z]:/#', $rel))) {
            return $rel;
        }
        if (!function_exists('clean_sweep_detect_site_root')) {
            return $rel;
        }
        return clean_sweep_detect_site_root() . ltrim(str_replace('\\', '/', $rel), '/');
    }
}
