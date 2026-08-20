<?php
/**
 * Clean Sweep — WordPress user / access audit
 *
 * High-signal detection for post-compromise cleanup:
 *  - Administrator enumeration + IOC usernames/emails
 *  - Capability / role integrity (raw meta + dangerous caps)
 *  - Hidden admins (DB vs filtered get_users)
 *  - Users hidden from wp-admin Users screen (users.php filters, option IOCs, hook files)
 *  - Application passwords (naming + age heuristics)
 *  - Session tokens (bot UA / blank UA / cloud IP hints)
 *  - Credential integrity (empty/invalid hash, reset keys, shared hashes)
 *  - Multisite super admins + orphan site_admins entries
 *  - Sensitive auth / hide-user / hide-plugin hook origins
 *
 * @since Users tab live audit
 */

if (!class_exists('CleanSweep_UserAudit')) {

class CleanSweep_UserAudit {

    /**
     * Known campaign / toolkit usernames (IOC) — critical.
     * Prefer unique toolkit names; keep generic demo names out (separate soft list).
     */
    private static $IOC_USERNAMES = [
        'officialwp', 'wpsystem', 'backupadmin', 'db_admin',
        'wp_maintenance', 'security_check', 'admin_backup',
        'mr_administartor', 'wp_update', 'wp-bot', 'wp_bot',
        'supportadmin', 'plugincheck', 'wpsecurity', 'adminbackup',
        'wordpressuser',
    ];

    /** Common weak/demo admin logins — hygiene warning only, not campaign IOC */
    private static $WEAK_ADMIN_USERNAMES = [
        'administrator1', 'admin123', 'testadmin', 'demoadmin', 'admin1',
    ];

    private static $DISPOSABLE_EMAIL_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', 'tempmail.com', '10minutemail.com',
        'trashmail.com', 'yopmail.com', 'sharklasers.com', 'temp-mail.org',
        'discard.email', 'mailnesia.com', 'guerrillamailblock.com',
    ];

    /**
     * Automation / non-browser UA fragments.
     * Avoid bare "java/" / "httpclient" — too many legitimate app clients.
     */
    private static $BOT_UA_FRAGMENTS = [
        'python-requests', 'python-urllib', 'curl/', 'wget/', 'libwww-perl',
        'go-http-client', 'scrapy', 'apache-httpclient', 'node-fetch',
        'axios/', 'postmanruntime', 'insomnia/', 'httpie/', 'libcurl',
        'python-httpx', ' guzzlehttp/',
    ];

    /** Caps that imply high privilege when present without administrator role */
    private static $DANGEROUS_CAPS = [
        'manage_options',
        'edit_plugins',
        'install_plugins',
        'activate_plugins',
        'delete_plugins',
        'edit_themes',
        'install_themes',
        'delete_themes',
        'update_core',
        'update_plugins',
        'update_themes',
        'edit_files',
        'edit_users',
        'create_users',
        'delete_users',
        'promote_users',
        'list_users',
        'remove_users',
        'unfiltered_html',
        'unfiltered_upload',
        'manage_network',
        'manage_sites',
        'manage_network_users',
        'manage_network_plugins',
        'manage_network_themes',
        'manage_network_options',
    ];

    /**
     * Cloud / hosting IP prefixes — use longer prefixes only.
     * Single-octet matches (3., 52., 104.) caused mass false session anomalies.
     * Hint only; never sole critical reason.
     */
    private static $CLOUD_IP_PREFIXES = [
        // DigitalOcean
        '104.131.', '104.236.', '138.68.', '139.59.', '142.93.', '157.230.',
        '159.65.', '159.89.', '161.35.', '164.90.', '165.22.', '167.71.',
        '167.99.', '174.138.', '178.62.', '188.166.', '206.189.',
        // Linode / Akamai
        '45.33.', '45.56.', '45.79.', '50.116.', '66.175.', '69.164.',
        '72.14.', '96.126.', '97.107.', '139.144.', '172.104.', '173.230.',
        '173.255.', '192.46.', '192.53.', '192.81.', '192.155.', '198.58.',
        // Vultr
        '45.32.', '45.63.', '45.76.', '66.42.', '108.61.', '136.244.',
        '140.82.', '144.202.', '149.28.', '155.138.', '207.148.', '208.167.',
        // Hetzner
        '5.9.', '5.75.', '23.88.', '49.12.', '49.13.', '65.108.', '65.109.',
        '78.46.', '88.99.', '95.216.', '116.203.', '128.140.', '135.181.',
        '136.243.', '138.201.', '142.132.', '148.251.', '157.90.', '159.69.',
        '162.55.', '167.233.', '168.119.', '176.9.', '178.63.', '188.34.',
        '188.40.', '195.201.', '213.133.', '213.239.',
        // OVH
        '51.38.', '51.68.', '51.75.', '51.77.', '51.83.', '51.89.', '51.91.',
        '51.178.', '51.195.', '51.210.', '54.36.', '54.37.', '54.38.',
        '91.121.', '94.23.', '137.74.', '145.239.', '146.59.', '147.135.',
        '149.202.', '151.80.', '152.228.', '164.132.', '176.31.', '178.32.',
        '188.165.', '193.70.', '198.27.',
    ];

    /**
     * Suspicious application-password names (exact or as whole token).
     * Avoid broad fragments like "api"/"admin"/"token" — too many legit hits.
     */
    private static $SUSPICIOUS_APP_PW_NAMES = [
        'curl', 'wget', 'wp-cli', 'wpcli', 'shell', 'backdoor',
        'hack', 'malware', 'webshell', 'root', 'python-requests',
    ];

    /**
     * Run full user access audit.
     *
     * @return array UI payload
     */
    public function audit() {
        if (!function_exists('get_users') || !function_exists('get_userdata')) {
            return [
                'users' => [],
                'super_admins' => [],
                'sensitive_hooks' => [],
                'site_findings' => [],
                'summary' => [
                    'total_users' => 0,
                    'administrators' => 0,
                    'critical' => 0,
                    'warning' => 0,
                    'healthy' => 0,
                    'with_app_passwords' => 0,
                    'with_sessions' => 0,
                    'hidden_admins' => 0,
                    'hidden_from_admin' => 0,
                ],
                'is_multisite' => false,
                'current_user_id' => 0,
                'error' => 'WordPress user APIs not available',
                'audited_at' => time(),
            ];
        }

        $is_ms = function_exists('is_multisite') && is_multisite();
        $current_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        // Collect users: broad page + all admins by capability meta (beyond 500)
        $collected = $this->collect_users_for_audit();
        $wp_users = $collected['users'];
        $filtered_admin_ids = $collected['filtered_admin_ids'];

        // Precompute password-hash frequency for shared-hash detection
        $hash_counts = $this->password_hash_counts($wp_users);

        $users_out = [];
        $admin_reg_times = [];
        foreach ($wp_users as $user) {
            $row = $this->audit_one_user($user, $is_ms, $current_id, $hash_counts);
            $users_out[] = $row;
            if (!empty($row['is_administrator']) && !empty($row['registered'])) {
                $ts = strtotime($row['registered']);
                if ($ts) {
                    $admin_reg_times[] = ['id' => $row['id'], 'ts' => $ts];
                }
            }
        }

        // Registration-burst signal (multiple admins within 15 minutes)
        $this->apply_admin_registration_burst($users_out, $admin_reg_times);

        // Hidden admins: privileged in DB meta but missing from filtered get_users admin set
        $hidden = $this->detect_hidden_admins($users_out, $filtered_admin_ids, $is_ms, $current_id);
        foreach ($hidden as $h) {
            $users_out[] = $h;
        }

        // Hidden from wp-admin Users screen (users.php-only filters, option IOCs, hook files)
        $this->mark_users_hidden_from_dashboard($users_out, $is_ms, $current_id);

        // Site-level findings (default_role, hide-admin option IOCs)
        $site_findings = $this->audit_site_level_findings();

        // Multisite super admins
        $super_section = $this->audit_super_admins($users_out, $is_ms);

        // Sensitive hooks (auth / caps / hide-users / hide-plugins)
        $hooks = $this->audit_sensitive_hooks();

        // Sort: critical first, then warning, then admins, then id
        usort($users_out, function ($a, $b) {
            $rank = ['critical' => 0, 'warning' => 1, 'info' => 2, 'healthy' => 3];
            $ra = $rank[$a['status']] ?? 9;
            $rb = $rank[$b['status']] ?? 9;
            if ($ra !== $rb) {
                return $ra - $rb;
            }
            $ah = (!empty($a['hidden_from_admin']) || !empty($a['hidden_admin'])) ? 0 : 1;
            $bh = (!empty($b['hidden_from_admin']) || !empty($b['hidden_admin'])) ? 0 : 1;
            if ($ah !== $bh) {
                return $ah - $bh;
            }
            $a_admin = !empty($a['is_administrator']) ? 0 : 1;
            $b_admin = !empty($b['is_administrator']) ? 0 : 1;
            if ($a_admin !== $b_admin) {
                return $a_admin - $b_admin;
            }
            return ($a['id'] ?? 0) - ($b['id'] ?? 0);
        });

        $summary = [
            'total_users' => count($users_out),
            'administrators' => count(array_filter($users_out, function ($u) {
                return !empty($u['is_administrator']);
            })),
            'critical' => count(array_filter($users_out, function ($u) {
                return ($u['status'] ?? '') === 'critical';
            })),
            'warning' => count(array_filter($users_out, function ($u) {
                return ($u['status'] ?? '') === 'warning';
            })),
            'healthy' => count(array_filter($users_out, function ($u) {
                return ($u['status'] ?? '') === 'healthy';
            })),
            'with_app_passwords' => count(array_filter($users_out, function ($u) {
                return ($u['app_password_count'] ?? 0) > 0;
            })),
            'with_sessions' => count(array_filter($users_out, function ($u) {
                return ($u['session_count'] ?? 0) > 0;
            })),
            'hidden_admins' => count(array_filter($users_out, function ($u) {
                return !empty($u['hidden_admin']);
            })),
            'hidden_from_admin' => count(array_filter($users_out, function ($u) {
                return !empty($u['hidden_from_admin']) || !empty($u['hidden_admin']);
            })),
            'super_admin_issues' => count(array_filter($super_section, function ($s) {
                return ($s['status'] ?? '') !== 'healthy';
            })),
            'sensitive_hook_issues' => count(array_filter($hooks, function ($h) {
                return ($h['status'] ?? '') !== 'healthy';
            })),
            'site_finding_issues' => count(array_filter($site_findings, function ($f) {
                return in_array($f['status'] ?? '', ['critical', 'warning'], true);
            })),
        ];

        // Roll site-level + hook findings into headline critical/warning counts
        foreach ($site_findings as $f) {
            if (($f['status'] ?? '') === 'critical') {
                $summary['critical']++;
            } elseif (($f['status'] ?? '') === 'warning') {
                $summary['warning']++;
            }
        }
        foreach ($hooks as $h) {
            if (($h['status'] ?? '') === 'critical') {
                $summary['critical']++;
            } elseif (($h['status'] ?? '') === 'warning') {
                $summary['warning']++;
            }
        }
        foreach ($super_section as $s) {
            if (($s['status'] ?? '') === 'critical') {
                $summary['critical']++;
            } elseif (($s['status'] ?? '') === 'warning') {
                $summary['warning']++;
            }
        }

        return [
            'users' => $users_out,
            'super_admins' => $super_section,
            'sensitive_hooks' => $hooks,
            'site_findings' => $site_findings,
            'summary' => $summary,
            'is_multisite' => $is_ms,
            'current_user_id' => $current_id,
            'audited_at' => time(),
        ];
    }

    /**
     * Collect users for audit: first 500 + every user with admin-level caps in meta.
     *
     * @return array{users: WP_User[], filtered_admin_ids: int[]}
     */
    private function collect_users_for_audit() {
        $by_id = [];

        $page = get_users([
            'number' => 500,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'all_with_meta',
        ]);
        foreach ((array) $page as $user) {
            $by_id[(int) $user->ID] = $user;
        }

        // Admins as WordPress role API sees them (subject to hide-user filters on some setups).
        // Must NOT cap this list — a number limit false-flags higher-ID admins as "hidden".
        $filtered_admins = get_users([
            'role' => 'administrator',
            'number' => -1,
            'fields' => 'ID',
        ]);
        $filtered_admin_ids = array_map('intval', (array) $filtered_admins);

        // Also pull manage_options / administrator via capability queries (WP 5.9+)
        $wp_ver = function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '0';
        if (version_compare($wp_ver, '5.9', '>=')) {
            foreach (['administrator', 'manage_options'] as $cap) {
                $extra = get_users([
                    'capability' => $cap,
                    'number' => -1,
                    'fields' => 'all_with_meta',
                ]);
                foreach ((array) $extra as $user) {
                    $by_id[(int) $user->ID] = $user;
                }
            }
        }

        // Raw meta scan: anyone with administrator or manage_options in capabilities blob
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            $cap_key = $wpdb->get_blog_prefix() . 'capabilities';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT user_id, meta_value FROM {$wpdb->usermeta}
                     WHERE meta_key = %s
                     AND (meta_value LIKE %s OR meta_value LIKE %s)
                     LIMIT 2000",
                    $cap_key,
                    '%administrator%',
                    '%manage_options%'
                ),
                ARRAY_A
            );
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $uid = (int) ($row['user_id'] ?? 0);
                    if ($uid > 0 && !isset($by_id[$uid])) {
                        $u = get_userdata($uid);
                        if ($u) {
                            $by_id[$uid] = $u;
                        }
                    }
                }
            }
        }

        ksort($by_id);
        return [
            'users' => array_values($by_id),
            'filtered_admin_ids' => $filtered_admin_ids,
        ];
    }

    /**
     * Count password hash occurrences among collected users (for shared-hash signal).
     *
     * @param WP_User[] $users
     * @return array<string,int>
     */
    private function password_hash_counts(array $users) {
        $counts = [];
        foreach ($users as $user) {
            $hash = isset($user->user_pass) ? (string) $user->user_pass : '';
            if ($hash === '' || strlen($hash) < 8) {
                continue;
            }
            if (!isset($counts[$hash])) {
                $counts[$hash] = 0;
            }
            $counts[$hash]++;
        }
        return $counts;
    }

    /**
     * Flag clusters of admins registered within a short window (recent only).
     * Historical bulk imports from years ago must not trigger this.
     *
     * @param array $users_out
     * @param array $admin_reg_times
     */
    private function apply_admin_registration_burst(array &$users_out, array $admin_reg_times) {
        if (count($admin_reg_times) < 2) {
            return;
        }
        $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $recent_cutoff = time() - (30 * $day);
        // Only consider admins registered in the last 30 days
        $admin_reg_times = array_values(array_filter($admin_reg_times, function ($row) use ($recent_cutoff) {
            return ($row['ts'] ?? 0) >= $recent_cutoff;
        }));
        if (count($admin_reg_times) < 2) {
            return;
        }
        usort($admin_reg_times, function ($a, $b) {
            return $a['ts'] - $b['ts'];
        });
        $window = 15 * 60; // 15 minutes
        $burst_ids = [];
        $n = count($admin_reg_times);
        for ($i = 0; $i < $n; $i++) {
            $cluster = [$admin_reg_times[$i]['id']];
            for ($j = $i + 1; $j < $n; $j++) {
                if ($admin_reg_times[$j]['ts'] - $admin_reg_times[$i]['ts'] <= $window) {
                    $cluster[] = $admin_reg_times[$j]['id'];
                } else {
                    break;
                }
            }
            if (count($cluster) >= 2) {
                foreach ($cluster as $id) {
                    $burst_ids[$id] = true;
                }
            }
        }
        if (empty($burst_ids)) {
            return;
        }
        foreach ($users_out as &$u) {
            if (empty($burst_ids[$u['id'] ?? 0])) {
                continue;
            }
            $u['issues'][] = $this->issue(
                'warning',
                'admin_registration_burst',
                'Multiple administrator accounts registered within a 15-minute window (scripted create pattern)'
            );
            $u['score'] = (int) ($u['score'] ?? 0) + 25;
            $u['issues'] = $this->dedupe_issues($u['issues']);
            $u['status'] = $this->score_to_status($u['score'], $u['issues']);
        }
        unset($u);
    }

    /**
     * Detect accounts with raw administrator role meta that are missing from
     * get_users(role=administrator) — classic hide-user malware fingerprint.
     * (manage_options-without-role is handled separately as cap integrity.)
     *
     * @param array $users_out
     * @param int[] $filtered_admin_ids
     * @param bool $is_ms
     * @param int $current_id
     * @return array Additional user rows
     */
    private function detect_hidden_admins(array &$users_out, array $filtered_admin_ids, $is_ms, $current_id) {
        $filtered_set = array_fill_keys($filtered_admin_ids, true);
        $extra = [];

        $db_admin_ids = $this->raw_administrator_user_ids();
        $db_admin_set = array_fill_keys($db_admin_ids, true);
        $visible_ids = [];
        foreach ($users_out as $u) {
            $visible_ids[(int) $u['id']] = true;
        }

        foreach ($users_out as &$u) {
            $id = (int) ($u['id'] ?? 0);
            if ($id === $current_id || !isset($db_admin_set[$id])) {
                continue;
            }
            // Raw meta has administrator, but role query did not return them
            if (!isset($filtered_set[$id])) {
                $u['hidden_admin'] = true;
                $u['is_administrator'] = true;
                $u['issues'][] = $this->issue(
                    'critical',
                    'hidden_admin',
                    'Raw capabilities grant administrator but account is missing from standard admin listing. Possible hide-user malware.'
                );
                $u['score'] = (int) ($u['score'] ?? 0) + 85;
                $u['issues'] = $this->dedupe_issues($u['issues']);
                $u['status'] = $this->score_to_status($u['score'], $u['issues']);
            }
        }
        unset($u);

        // Admins that exist only in raw meta and were never collected
        foreach ($db_admin_ids as $uid) {
            if (isset($visible_ids[$uid]) || $uid === $current_id) {
                continue;
            }
            $user = get_userdata($uid);
            if (!$user) {
                continue;
            }
            $row = $this->audit_one_user($user, $is_ms, $current_id, []);
            $row['hidden_admin'] = true;
            $row['is_administrator'] = true;
            $row['issues'][] = $this->issue(
                'critical',
                'hidden_admin',
                'Administrator found via raw capability meta but missing from user list APIs'
            );
            $row['score'] = (int) ($row['score'] ?? 0) + 85;
            $row['issues'] = $this->dedupe_issues($row['issues']);
            $row['status'] = $this->score_to_status($row['score'], $row['issues']);
            $extra[] = $row;
        }

        return $extra;
    }

    /**
     * Flag accounts hidden from the WordPress Users screen even when
     * get_users(role=administrator) still returns them (users.php-only filters).
     *
     * @param array $users_out
     */
    private function mark_users_hidden_from_dashboard(array &$users_out, $is_ms, $current_id): void {
        $refs = $this->collect_hide_user_refs();
        $explicit_ids = $refs['ids'];
        $explicit_logins = $refs['logins'];
        $via = $refs['via'];

        $raw_ids = $this->raw_all_user_ids();
        $dash_ids = $this->dashboard_visible_user_ids();
        $dash_set = array_fill_keys($dash_ids, true);
        $use_dash_diff = $dash_ids !== [] && $raw_ids !== [] && count($dash_ids) < count($raw_ids);

        $hidden_ids = $explicit_ids;
        $hidden_logins = $explicit_logins;
        if ($use_dash_diff) {
            foreach ($raw_ids as $uid) {
                if (!isset($dash_set[$uid])) {
                    $hidden_ids[$uid] = true;
                    if (!isset($via[$uid])) {
                        $via[$uid] = 'missing from simulated wp-admin Users list';
                    }
                }
            }
        }

        if ($hidden_ids === [] && $hidden_logins === []) {
            return;
        }

        $seen = [];
        foreach ($users_out as $u) {
            $seen[(int) ($u['id'] ?? 0)] = true;
        }
        foreach (array_keys($hidden_ids) as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0 || isset($seen[$uid])) {
                continue;
            }
            $user = function_exists('get_userdata') ? get_userdata($uid) : null;
            if (!$user) {
                continue;
            }
            $row = $this->audit_one_user($user, $is_ms, $current_id, []);
            $users_out[] = $row;
            $seen[$uid] = true;
        }
        if ($hidden_logins !== []) {
            foreach ($hidden_logins as $login => $_) {
                if (!is_string($login) || $login === '' || !function_exists('get_user_by')) {
                    continue;
                }
                $user = get_user_by('login', $login);
                if (!$user || isset($seen[(int) $user->ID])) {
                    continue;
                }
                $row = $this->audit_one_user($user, $is_ms, $current_id, []);
                $users_out[] = $row;
                $seen[(int) $user->ID] = true;
                $hidden_ids[(int) $user->ID] = true;
                if (!isset($via[(int) $user->ID])) {
                    $via[(int) $user->ID] = 'login excluded by hide-user code';
                }
            }
        }

        foreach ($users_out as &$u) {
            $id = (int) ($u['id'] ?? 0);
            $login = strtolower((string) ($u['username'] ?? ''));
            $hit = isset($hidden_ids[$id]) || ($login !== '' && isset($hidden_logins[$login]));
            if (!$hit) {
                continue;
            }
            $detail = $via[$id] ?? ($login !== '' && isset($via[$login]) ? $via[$login] : 'hidden from WordPress Users screen');
            $this->apply_hidden_from_admin_flag($u, $detail);
        }
        unset($u);
    }

    /**
     * @param array $u
     */
    private function apply_hidden_from_admin_flag(array &$u, string $detail): void {
        $already = false;
        foreach ($u['issues'] ?? [] as $iss) {
            $code = is_array($iss) ? (string) ($iss['code'] ?? '') : '';
            if ($code === 'hidden_admin' || $code === 'hidden_from_admin') {
                $already = true;
                break;
            }
        }
        $u['hidden_from_admin'] = true;
        if (!empty($u['is_administrator']) || !empty($u['hidden_admin'])) {
            $u['hidden_admin'] = true;
        }
        if ($already) {
            return;
        }
        $is_admin = !empty($u['hidden_admin']) || !empty($u['is_administrator']);
        $u['issues'][] = $this->issue(
            $is_admin ? 'critical' : 'warning',
            $is_admin ? 'hidden_admin' : 'hidden_from_admin',
            $is_admin
                ? 'This administrator is hidden from the WordPress Users screen. ' . $detail
                : 'This account is hidden from the WordPress Users screen. ' . $detail
        );
        $u['score'] = (int) ($u['score'] ?? 0) + ($is_admin ? 85 : 40);
        $u['issues'] = $this->dedupe_issues($u['issues']);
        $u['status'] = $this->score_to_status($u['score'], $u['issues']);
    }

    /**
     * User IDs from wp_users (unfiltered SQL).
     *
     * @return int[]
     */
    private function raw_all_user_ids(): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_col("SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 3000");
        if (!is_array($rows)) {
            return [];
        }
        return array_map('intval', $rows);
    }

    /**
     * User IDs as the wp-admin Users list would see them (pagenow + list-table args).
     *
     * @return int[]
     */
    private function dashboard_visible_user_ids(): array {
        if (!class_exists('WP_User_Query')) {
            return [];
        }
        $prev = isset($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : null;
        $GLOBALS['pagenow'] = 'users.php';
        $ids = [];
        try {
            $args = [
                'number' => -1,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ID',
                'count_total' => false,
            ];
            if (function_exists('apply_filters')) {
                $filtered = apply_filters('users_list_table_query_args', $args);
                if (is_array($filtered)) {
                    $args = $filtered;
                    $args['fields'] = 'ID';
                    $args['number'] = -1;
                    $args['count_total'] = false;
                }
            }
            $q = new WP_User_Query($args);
            $ids = array_map('intval', (array) $q->get_results());
        } catch (Throwable $e) {
            $ids = [];
        }
        if ($prev === null) {
            unset($GLOBALS['pagenow']);
        } else {
            $GLOBALS['pagenow'] = $prev;
        }
        return $ids;
    }

    /**
     * Hidden user IDs/logins from campaign options and hide-user hook callback files.
     *
     * @return array{ids: array<int,true>, logins: array<string,true>, via: array<int|string,string>}
     */
    private function collect_hide_user_refs(): array {
        $ids = [];
        $logins = [];
        $via = [];

        $add_id = function ($uid, $reason) use (&$ids, &$via) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                return;
            }
            $ids[$uid] = true;
            if (!isset($via[$uid])) {
                $via[$uid] = $reason;
            }
        };
        $add_login = function ($login, $reason) use (&$logins, &$via) {
            $login = strtolower(trim((string) $login));
            if ($login === '' || strlen($login) > 60) {
                return;
            }
            $logins[$login] = true;
            if (!isset($via[$login])) {
                $via[$login] = $reason;
            }
        };

        if (function_exists('get_option')) {
            $pre = get_option('_pre_user_id', false);
            if (is_numeric($pre) && (int) $pre > 0) {
                $add_id((int) $pre, 'option _pre_user_id');
            } elseif (is_array($pre)) {
                foreach ($pre as $v) {
                    if (is_numeric($v)) {
                        $add_id($v, 'option _pre_user_id');
                    }
                }
            }
            $ga = get_option('__ga_hidden_users', false);
            if (is_string($ga) && $ga !== '') {
                $dec = json_decode($ga, true);
                if (is_array($dec)) {
                    $ga = $dec;
                }
            }
            if (is_array($ga)) {
                foreach ($ga as $v) {
                    if (is_numeric($v)) {
                        $add_id($v, 'option __ga_hidden_users');
                    } elseif (is_string($v)) {
                        $add_login($v, 'option __ga_hidden_users');
                    }
                }
            } elseif (is_numeric($ga) && (int) $ga > 0) {
                $add_id((int) $ga, 'option __ga_hidden_users');
            }
        }

        global $wp_filter;
        $hooks = ['pre_user_query', 'users_list_table_query_args', 'views_users', 'load-users.php'];
        $sniffed = 0;
        foreach ($hooks as $hook) {
            if (!isset($wp_filter[$hook]) || $sniffed >= 12) {
                continue;
            }
            foreach ($this->extract_callbacks($wp_filter[$hook]) as $cb) {
                if ($sniffed >= 12) {
                    break;
                }
                $origin = $this->resolve_callback_origin($cb['callable']);
                $file = (string) ($origin['file'] ?? '');
                if ($file === '') {
                    continue;
                }
                $kind = (string) ($origin['kind'] ?? '');
                if ($kind === 'core') {
                    continue;
                }
                $sniffed++;
                $parsed = $this->parse_hide_user_refs_from_file($file);
                $label = 'hide-user hook ' . $hook . ' in ' . basename($file);
                foreach ($parsed['ids'] as $uid) {
                    $add_id($uid, $label);
                }
                foreach ($parsed['logins'] as $login) {
                    $add_login($login, $label);
                }
            }
        }

        return ['ids' => $ids, 'logins' => $logins, 'via' => $via];
    }

    /**
     * @return array{ids: int[], logins: string[]}
     */
    private function parse_hide_user_refs_from_file(string $path): array {
        $ids = [];
        $logins = [];
        $abs = str_replace('\\', '/', $path);
        $abspath = defined('ABSPATH') ? str_replace('\\', '/', ABSPATH) : '';
        if ($abspath && $abs !== '' && $abs[0] !== '/' && !preg_match('#^[A-Za-z]:/#', $abs)) {
            $abs = rtrim($abspath, '/') . '/' . ltrim($abs, '/');
        }
        if (!is_readable($abs)) {
            return ['ids' => [], 'logins' => []];
        }
        $buf = @file_get_contents($abs, false, null, 0, 65536);
        if (!is_string($buf) || $buf === '') {
            return ['ids' => [], 'logins' => []];
        }
        if (preg_match_all('/\b(?:ID|user_id)\s*(?:!=|<>)\s*[\'"]?(\d{1,10})/i', $buf, $m)) {
            foreach ($m[1] as $n) {
                $ids[] = (int) $n;
            }
        }
        if (preg_match_all('/NOT\s+IN\s*\(\s*([\d,\s]{1,80})/i', $buf, $m)) {
            foreach ($m[1] as $list) {
                foreach (preg_split('/\D+/', $list) as $n) {
                    if ($n !== '') {
                        $ids[] = (int) $n;
                    }
                }
            }
        }
        if (preg_match_all('/[\'"]exclude[\'"]\s*=>\s*(?:array\s*\(|\[)\s*(\d{1,10})/i', $buf, $m)) {
            foreach ($m[1] as $n) {
                $ids[] = (int) $n;
            }
        }
        if (preg_match_all('/user_login\s*(?:!=|<>)\s*[\'"]([^\'"]{1,60})/i', $buf, $m)) {
            foreach ($m[1] as $login) {
                $logins[] = $login;
            }
        }
        return [
            'ids' => array_values(array_unique(array_filter($ids))),
            'logins' => array_values(array_unique($logins)),
        ];
    }

    /**
     * User IDs with administrator => true in raw capabilities usermeta.
     *
     * @return int[]
     */
    private function raw_administrator_user_ids() {
        global $wpdb;
        $ids = [];
        if (!isset($wpdb) || !is_object($wpdb)) {
            return $ids;
        }
        $cap_key = $wpdb->get_blog_prefix() . 'capabilities';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_value FROM {$wpdb->usermeta}
                 WHERE meta_key = %s
                 AND meta_value LIKE %s
                 LIMIT 2000",
                $cap_key,
                '%administrator%'
            ),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return $ids;
        }
        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $raw = $row['meta_value'] ?? '';
            $caps = is_array($raw) ? $raw : @unserialize($raw);
            if (!is_array($caps)) {
                continue;
            }
            if (!empty($caps['administrator'])) {
                $ids[] = $uid;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Site-wide options that enable persistence or hide admins.
     *
     * @return array
     */
    private function audit_site_level_findings() {
        $findings = [];

        $default_role = function_exists('get_option') ? get_option('default_role', 'subscriber') : 'subscriber';
        if ($default_role === 'administrator') {
            $findings[] = [
                'code' => 'default_role_administrator',
                'status' => 'critical',
                'message' => 'default_role is administrator. New registrations become admins.',
                'detail' => (string) $default_role,
            ];
        } elseif (in_array($default_role, ['editor', 'shop_manager'], true)) {
            $findings[] = [
                'code' => 'default_role_elevated',
                'status' => 'warning',
                'message' => 'default_role is elevated (' . $default_role . ')',
                'detail' => (string) $default_role,
            ];
        }

        // Known hide-admin campaign option (require a meaningful non-empty value)
        if (function_exists('get_option')) {
            // Use default false so "not set" is distinguishable; 0 / '' / empty array are not IOCs
            $pre = get_option('_pre_user_id', false);
            $pre_set = ($pre !== false && $pre !== null && $pre !== '' && $pre !== 0 && $pre !== '0');
            if ($pre_set && !(is_array($pre) && empty($pre))) {
                $findings[] = [
                    'code' => 'hide_admin_option_ioc',
                    'status' => 'critical',
                    'message' => 'Option _pre_user_id present. Known hide-admin / backdoor-user campaign IOC.',
                    'detail' => is_scalar($pre) ? (string) $pre : 'set',
                ];
            }

            // GA_INJ leftovers — option *names* are the IOC. DB content scan
            // only matches option_value, so these would be missed after the PHP
            // inject is deleted. Presence is enough (including JSON "[]").
            $ga_inj_options = [
                '__ga_hidden_users' => 'GA_INJ hidden-admin list option (__ga_hidden_users)',
                '_theme_inject_status' => 'GA_INJ theme-inject status option (_theme_inject_status)',
                '__ga_cleanup_done' => 'GA_INJ cleanup marker option (__ga_cleanup_done)',
                '__ga_snip_id' => 'GA_INJ Code Snippets hide-id option (__ga_snip_id)',
                'ganalytics_data_sent' => 'GA_INJ credential-exfil marker (ganalytics_data_sent)',
            ];
            foreach ($ga_inj_options as $opt => $message) {
                $val = get_option($opt, false);
                if ($val === false) {
                    continue;
                }
                $findings[] = [
                    'code' => 'ga_inj_option_ioc',
                    'status' => 'critical',
                    'message' => $message,
                    'detail' => is_scalar($val) ? (string) $val : 'set',
                ];
            }
            $ga_cache = get_option('_transient___ga_r_cache', false);
            if ($ga_cache === false) {
                $ga_cache = get_option('__ga_r_cache', false);
            }
            if ($ga_cache !== false) {
                $findings[] = [
                    'code' => 'ga_inj_option_ioc',
                    'status' => 'critical',
                    'message' => 'GA_INJ resolver cache leftover (__ga_r_cache)',
                    'detail' => is_scalar($ga_cache) ? (string) $ga_cache : 'set',
                ];
            }
        }

        // users_can_register + default elevated is worse (already covered default_role)
        if (function_exists('get_option') && get_option('users_can_register') && $default_role === 'administrator') {
            $findings[] = [
                'code' => 'open_registration_admin',
                'status' => 'critical',
                'message' => 'Open registration is enabled while default_role is administrator',
                'detail' => 'users_can_register=1',
            ];
        }

        $this->audit_plugin_bootstraps($findings);

        $id_file = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'features/maintenance/lib/PackageIdentity.php'
            : dirname(__DIR__) . '/../maintenance/lib/PackageIdentity.php';
        if (!class_exists('CleanSweep_PackageIdentity', false) && is_readable($id_file)) {
            require_once $id_file;
        }
        if (class_exists('CleanSweep_PackageIdentity', false)) {
            $sum = CleanSweep_PackageIdentity::summary();
            if (($sum['count'] ?? 0) > 0) {
                $names = [];
                foreach ($sum['items'] as $it) {
                    $names[] = ($it['slug'] ?? '') !== '' ? $it['slug'] : ($it['name'] ?? '');
                }
                $findings[] = [
                    'code' => 'likely_fake_packages',
                    'status' => 'warning',
                    'message' => $sum['count'] . ' likely fake plugin/theme package(s). Review Plugins & themes.',
                    'detail' => implode(', ', array_slice(array_filter($names), 0, 8)),
                ];
            }
        }

        return $findings;
    }

    /**
     * Persistence sniff on plugin bootstrap files only (not vendor trees).
     * Flag only stacked evidence: hide+admin, shell+admin, or shell+hide.
     *
     * @param array $findings
     */
    private function audit_plugin_bootstraps(array &$findings): void {
        if (!function_exists('get_plugins')) {
            return;
        }
        $dir = defined('ORIGINAL_WP_PLUGIN_DIR')
            ? ORIGINAL_WP_PLUGIN_DIR
            : (defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '');
        $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        if ($dir === '') {
            return;
        }
        $n = 0;
        foreach (get_plugins() as $file => $data) {
            if ($n++ > 80) {
                break;
            }
            $full = $dir . '/' . ltrim(str_replace('\\', '/', (string) $file), '/');
            $sniff = $this->sniff_file_for_persistence($full);
            if (($sniff['risk'] ?? '') !== 'critical') {
                continue;
            }
            $reasons = $sniff['reasons'] ?? [];
            $blob = strtolower(implode(' ', $reasons));
            $hide = strpos($blob, 'hide-user') !== false || strpos($blob, 'hide-plugin') !== false;
            $admin = strpos($blob, 'administrator') !== false;
            $shell = strpos($blob, 'shell') !== false;
            if (!(($hide && $admin) || ($shell && $admin) || ($shell && $hide))) {
                continue;
            }
            $findings[] = [
                'code' => 'plugin_bootstrap_persistence',
                'status' => 'critical',
                'message' => 'Plugin ' . ($data['Name'] ?? $file) . ' bootstrap looks like persistence malware: ' . implode('; ', $reasons),
                'detail' => (string) $file,
            ];
        }
    }

    /**
     * @param WP_User $user
     * @param bool $is_ms
     * @param int $current_id
     * @param array<string,int> $hash_counts
     * @return array
     */
    private function audit_one_user($user, $is_ms, $current_id, array $hash_counts = []) {
        $id = (int) $user->ID;
        $login = (string) $user->user_login;
        $email = (string) $user->user_email;
        $url = (string) $user->user_url;
        $registered = (string) $user->user_registered;
        $roles = is_array($user->roles) ? array_values($user->roles) : [];
        $caps = [];
        if (isset($user->allcaps) && is_array($user->allcaps)) {
            foreach ($user->allcaps as $cap => $grant) {
                if ($grant) {
                    $caps[] = (string) $cap;
                }
            }
            sort($caps);
        }

        $is_admin = in_array('administrator', $roles, true)
            || !empty($user->allcaps['manage_options'])
            || !empty($user->allcaps['administrator']);

        $is_super = $is_ms && function_exists('is_super_admin') && is_super_admin($id);

        $app_passwords = $this->read_application_passwords($id);
        $sessions = $this->read_sessions($id, $is_admin);
        $raw_caps = $this->read_raw_capabilities($id);

        $issues = [];
        $score = 0;

        // ── IOC / weak username ────────────────────────────────
        $login_l = strtolower($login);
        if (in_array($login_l, self::$IOC_USERNAMES, true)) {
            $issues[] = $this->issue('critical', 'ioc_username', 'Known suspicious username (IOC)');
            $score += 80;
        } elseif (preg_match('/^usr_[a-f0-9]{8}$/i', $login)) {
            $issues[] = $this->issue('critical', 'ioc_username_pattern', 'Suspicious username pattern usr_ + hex');
            $score += 80;
        } elseif (preg_match('/^(sync_agent|cdn_worker|seo_service|system)[a-f0-9]{8}$/i', $login)) {
            $issues[] = $this->issue('critical', 'ioc_username_pattern', 'Suspicious username pattern GA_INJ hidden-admin prefix + hex');
            $score += 80;
        } elseif (preg_match('/^(wp|wpadmin|admin)[_-]?[a-f0-9]{6,}$/i', $login)) {
            $issues[] = $this->issue('critical', 'ioc_username_pattern', 'Suspicious username pattern admin/wp + hex');
            $score += 70;
        } elseif ($is_admin && in_array($login_l, self::$WEAK_ADMIN_USERNAMES, true)) {
            $issues[] = $this->issue('warning', 'weak_admin_username', 'Common weak/demo administrator username. Review it.');
            $score += 15;
        } elseif ($is_admin && preg_match('/^[a-f0-9]{10,32}$/i', $login)) {
            $issues[] = $this->issue('warning', 'random_hex_admin', 'Administrator username looks like random hex');
            $score += 25;
        }

        // ── IOC email ─────────────────────────────────────────
        if (preg_match('/^wp-[a-f0-9]{6}@/i', $email)) {
            $issues[] = $this->issue('critical', 'ioc_email', 'Suspicious email pattern wp- + hex');
            $score += 70;
        } elseif (preg_match('/^(sync-agent|seo-service|cdn-worker)@/i', $email)) {
            $issues[] = $this->issue('critical', 'ioc_email', 'Suspicious email pattern GA_INJ hidden-admin local part');
            $score += 70;
        } elseif ($is_admin && preg_match('/^[a-f0-9]{8,}@(mailinator|guerrillamail|tempmail|yopmail)/i', $email)) {
            $issues[] = $this->issue('critical', 'ioc_email_disposable_hex', 'Hex-like local part on disposable email (toolkit pattern)');
            $score += 65;
        }

        // Empty / disposable email on admin
        if ($is_admin) {
            $email_invalid = ($email === '');
            if (!$email_invalid) {
                $email_invalid = function_exists('is_email')
                    ? !is_email($email)
                    : !filter_var($email, FILTER_VALIDATE_EMAIL);
            }
            if ($email_invalid) {
                $issues[] = $this->issue('high', 'bad_email', 'Administrator has empty or invalid email');
                $score += 40;
            } else {
                $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
                if ($domain && in_array($domain, self::$DISPOSABLE_EMAIL_DOMAINS, true)) {
                    $issues[] = $this->issue('high', 'disposable_email', 'Administrator uses disposable email domain');
                    $score += 35;
                }
            }
        }

        // user_url malware-ish patterns
        if ($url !== '') {
            if (preg_match('/<script|javascript:|vbscript:|data:text\/html|onclick|onerror|<\?php|eval\s*\(|base64_decode/i', $url)) {
                $issues[] = $this->issue('critical', 'malicious_url', 'User URL contains script / payload patterns');
                $score += 75;
            } elseif (preg_match('/(?:https?:\/\/)?(?:\d{1,3}\.){3}\d{1,3}/', $url) && $is_admin) {
                $issues[] = $this->issue('warning', 'url_raw_ip', 'Administrator user URL points at a raw IP');
                $score += 15;
            }
        }

        // ── Raw capability meta integrity (before admin-only signals) ──
        $cap_issues = $this->audit_capability_integrity($roles, $raw_caps, $user);
        foreach ($cap_issues as $ci) {
            $issues[] = $ci['issue'];
            $score += $ci['score'];
            if (!empty($ci['force_admin'])) {
                $is_admin = true;
            }
        }

        // Caps without matching administrator role (API view).
        // Multisite super admins often have manage_options via mapping without blog admin role — not malware.
        if (!$is_admin && !$is_super && !empty($user->allcaps['manage_options'])) {
            $issues[] = $this->issue('critical', 'cap_without_role', 'Has manage_options capability without administrator role');
            $score += 70;
            $is_admin = true;
        }

        // Classic username admin (hygiene) — after is_admin may have been elevated
        if ($login_l === 'admin' && $is_admin) {
            $issues[] = $this->issue('info', 'default_admin_login', 'Username "admin" is a common brute-force target');
            $score += 5;
        }

        // New administrator (registered in last 14 days) — soft hygiene signal
        $reg_ts = $registered ? strtotime($registered) : false;
        $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        if ($is_admin && $reg_ts && $reg_ts > (time() - 14 * $day)) {
            if ($reg_ts > (time() - 2 * $day)) {
                $issues[] = $this->issue('warning', 'very_new_admin', 'Administrator registered within the last 48 hours');
                $score += 20;
            } else {
                $issues[] = $this->issue('warning', 'new_admin', 'Administrator account registered within the last 14 days');
                $score += 15;
            }
        }

        // Multiple roles
        if (count($roles) > 1) {
            $issues[] = $this->issue('info', 'multiple_roles', 'User has multiple roles: ' . implode(', ', $roles));
            $score += 5;
        }

        // ── Credential integrity (not strength) ───────────────
        $pass_hash = isset($user->user_pass) ? (string) $user->user_pass : '';
        $hash_family = $this->classify_password_hash($pass_hash);
        $cred = $this->audit_credential_integrity($pass_hash, $user, $is_admin, $hash_counts, $hash_family);
        foreach ($cred as $c) {
            $issues[] = $c['issue'];
            $score += $c['score'];
        }

        // ── Application passwords ─────────────────────────────
        $app_count = count($app_passwords);
        $app_score = $this->score_application_passwords($app_passwords, $is_admin, $reg_ts);
        foreach ($app_score['issues'] as $iss) {
            $issues[] = $iss;
        }
        $score += $app_score['score'];
        // Combo boost only when app passwords have *suspicious* signals (not mere presence)
        $has_sus_app = false;
        foreach ($app_score['issues'] as $iss) {
            if (in_array($iss['code'] ?? '', ['app_pw_suspicious_name', 'app_pw_at_registration'], true)) {
                $has_sus_app = true;
                break;
            }
        }
        if ($has_sus_app && $score >= 50) {
            $score += 15;
        }

        // ── Session soft signals ──────────────────────────────
        // Cloud-IP alone is too common on hosted WP — only count as signal with bot/blank UA.
        $bot_or_blank = 0;
        $cloud_with_bot = 0;
        $distinct_ips = [];
        $session_before_reg = false;
        foreach ($sessions as $sess) {
            $is_bot = !empty($sess['bot_ua']);
            $is_blank = !empty($sess['blank_ua']);
            $is_cloud = !empty($sess['dc_ip_hint']);
            if ($is_bot || $is_blank) {
                $bot_or_blank++;
                if ($is_cloud) {
                    $cloud_with_bot++;
                }
            }
            if (!empty($sess['ip'])) {
                $distinct_ips[$sess['ip']] = true;
            }
            // Session older than account registration (impossible → tampered tokens)
            if (!$session_before_reg && $reg_ts && !empty($sess['login']) && (int) $sess['login'] > 0
                && (int) $sess['login'] < ($reg_ts - 3600)) {
                $session_before_reg = true;
            }
        }
        if ($session_before_reg) {
            $issues[] = $this->issue(
                'critical',
                'session_before_registration',
                'Session login time predates account registration. Session tokens may be tampered with.'
            );
            $score += 60;
        }
        if ($is_admin && $bot_or_blank > 0) {
            $msg = 'Admin session(s) with bot or blank User-Agent. Verify them.';
            if ($cloud_with_bot > 0) {
                $msg = 'Admin session(s) with bot/blank UA from a hosting-range IP. Verify them.';
            }
            $issues[] = $this->issue('warning', 'session_anomaly', $msg);
            $score += min(30, 10 * $bot_or_blank + 5 * $cloud_with_bot);
        }
        // Many distinct IPs alone is soft (travel/VPN); only warn at higher threshold
        if ($is_admin && count($distinct_ips) >= 6) {
            $issues[] = $this->issue(
                'info',
                'many_session_ips',
                count($distinct_ips) . ' distinct session IPs on administrator. Verify if unexpected.'
            );
            $score += 8;
        }
        if ($is_admin && count($sessions) >= 12) {
            $issues[] = $this->issue(
                'info',
                'many_sessions',
                count($sessions) . ' active session tokens on administrator'
            );
            $score += 5;
        }

        if ($is_super) {
            $issues[] = $this->issue('info', 'super_admin', 'Network super admin');
        }

        $issues = $this->dedupe_issues($issues);
        $status = $this->score_to_status($score, $issues);

        return [
            'id' => $id,
            'username' => $login,
            'email' => $email,
            'url' => $url,
            'registered' => $registered,
            'roles' => $roles,
            'capabilities' => array_slice($caps, 0, 40),
            'raw_capabilities' => $raw_caps['caps_list'] ?? [],
            'is_administrator' => $is_admin,
            'is_super_admin' => $is_super,
            'is_current_user' => $id === $current_id,
            'hidden_admin' => false,
            'hidden_from_admin' => false,
            'app_password_count' => $app_count,
            'application_passwords' => $app_passwords,
            'session_count' => count($sessions),
            'sessions' => $sessions,
            'password_hash_family' => $hash_family,
            'issues' => $issues,
            'score' => $score,
            'status' => $status,
        ];
    }

    /**
     * Read and normalize raw {$prefix}capabilities usermeta.
     *
     * @param int $user_id
     * @return array{raw: mixed, caps: array, caps_list: string[], has_administrator: bool, has_manage_options: bool}
     */
    private function read_raw_capabilities($user_id) {
        global $wpdb;
        $out = [
            'raw' => null,
            'caps' => [],
            'caps_list' => [],
            'has_administrator' => false,
            'has_manage_options' => false,
            'unserialize_ok' => true,
        ];
        $cap_key = isset($wpdb) && is_object($wpdb)
            ? $wpdb->get_blog_prefix() . 'capabilities'
            : 'wp_capabilities';
        $raw = get_user_meta($user_id, $cap_key, true);
        $out['raw'] = $raw;
        if ($raw === '' || $raw === null || $raw === false) {
            return $out;
        }
        if (is_string($raw)) {
            $caps = @unserialize($raw);
            if ($caps === false && $raw !== 'b:0;') {
                $out['unserialize_ok'] = false;
                return $out;
            }
        } elseif (is_array($raw)) {
            $caps = $raw;
        } else {
            $out['unserialize_ok'] = false;
            return $out;
        }
        if (!is_array($caps)) {
            $out['unserialize_ok'] = false;
            return $out;
        }
        $out['caps'] = $caps;
        $list = [];
        foreach ($caps as $cap => $grant) {
            if ($grant) {
                $list[] = (string) $cap;
            }
        }
        sort($list);
        $out['caps_list'] = $list;
        $out['has_administrator'] = !empty($caps['administrator']);
        $out['has_manage_options'] = !empty($caps['manage_options']);
        return $out;
    }

    /**
     * Compare roles vs raw capability meta; flag dangerous grants on non-admins.
     *
     * @param string[] $roles
     * @param array $raw_caps
     * @param WP_User $user
     * @return array<int, array{issue: array, score: int, force_admin?: bool}>
     */
    private function audit_capability_integrity(array $roles, array $raw_caps, $user) {
        $out = [];
        $is_role_admin = in_array('administrator', $roles, true);
        $is_super = false;
        if (is_object($user) && !empty($user->ID) && function_exists('is_multisite') && is_multisite()
            && function_exists('is_super_admin')) {
            $is_super = is_super_admin((int) $user->ID);
        }

        // Only flag corrupt when meta was present but failed to unserialize.
        if (array_key_exists('unserialize_ok', $raw_caps) && $raw_caps['unserialize_ok'] === false) {
            $out[] = [
                'issue' => $this->issue('critical', 'caps_meta_corrupt', 'Capabilities usermeta is not a valid serialized array'),
                'score' => 70,
            ];
            return $out;
        }
        // Administrator role in WP_User but empty/missing capabilities meta is suspicious
        if ($is_role_admin && empty($raw_caps['caps']) && empty($raw_caps['raw'])) {
            $out[] = [
                'issue' => $this->issue(
                    'warning',
                    'caps_meta_missing',
                    'User has administrator role but capabilities usermeta is empty/missing'
                ),
                'score' => 35,
            ];
        }

        // Super admins often lack per-site administrator role — skip role-mismatch FPs
        if ($is_super) {
            return $out;
        }

        if (!empty($raw_caps['has_administrator']) && !$is_role_admin) {
            $out[] = [
                'issue' => $this->issue(
                    'critical',
                    'raw_admin_without_role',
                    'Raw capabilities meta grants administrator but WP roles do not list administrator'
                ),
                'score' => 80,
                'force_admin' => true,
            ];
        }

        if (!empty($raw_caps['has_manage_options']) && !$is_role_admin && empty($raw_caps['has_administrator'])) {
            $out[] = [
                'issue' => $this->issue(
                    'critical',
                    'raw_manage_options',
                    'Raw capabilities meta includes manage_options without administrator role'
                ),
                'score' => 75,
                'force_admin' => true,
            ];
        }

        // Dangerous caps granted explicitly in raw meta (not role-derived allcaps).
        if (!$is_role_admin && empty($raw_caps['has_administrator'])) {
            $granted = [];
            foreach (self::$DANGEROUS_CAPS as $cap) {
                if (!empty($raw_caps['caps'][$cap])) {
                    $granted[] = $cap;
                }
            }
            $granted = array_values(array_diff($granted, ['manage_options', 'administrator']));
            // Role keys + caps that are normal for editor/author on many sites
            $noise_caps = [
                'subscriber', 'contributor', 'author', 'editor', 'customer', 'shop_manager',
                'unfiltered_html', 'list_users', 'unfiltered_upload',
            ];
            $granted = array_values(array_diff($granted, $noise_caps));
            if (!empty($granted)) {
                $sev = count(array_intersect($granted, [
                    'edit_plugins', 'install_plugins', 'edit_files', 'update_core',
                    'edit_users', 'create_users', 'delete_users', 'promote_users',
                    'activate_plugins', 'delete_plugins', 'install_themes', 'edit_themes',
                ])) > 0 ? 'critical' : 'warning';
                $out[] = [
                    'issue' => $this->issue(
                        $sev,
                        'dangerous_caps_non_admin',
                        'Non-administrator has elevated capabilities in raw meta: ' . implode(', ', array_slice($granted, 0, 8))
                    ),
                    'score' => $sev === 'critical' ? 70 : 30,
                    'force_admin' => $sev === 'critical',
                ];
            }
        }

        return $out;
    }

    /**
     * Credential integrity signals (empty/unknown hash, reset keys, shared hashes).
     * Does not assess password strength.
     *
     * @param string $pass_hash
     * @param WP_User $user
     * @param bool $is_admin
     * @param array<string,int> $hash_counts
     * @param string $hash_family
     * @return array<int, array{issue: array, score: int}>
     */
    private function audit_credential_integrity($pass_hash, $user, $is_admin, array $hash_counts, $hash_family = '') {
        $out = [];
        $pass_hash = (string) $pass_hash;
        if ($hash_family === '') {
            $hash_family = $this->classify_password_hash($pass_hash);
        }

        if ($hash_family === 'empty') {
            $out[] = [
                'issue' => $this->issue(
                    $is_admin ? 'critical' : 'warning',
                    'empty_password_hash',
                    'User has an empty password hash'
                ),
                'score' => $is_admin ? 80 : 40,
            ];
        } elseif (!$this->is_recognized_password_hash_family($hash_family)) {
            // Unknown format: soft signal. Critical reserved for empty / clearly unsafe values.
            $looks_plaintext = $this->password_hash_looks_plaintext($pass_hash);
            if ($looks_plaintext) {
                $out[] = [
                    'issue' => $this->issue(
                        $is_admin ? 'warning' : 'info',
                        'invalid_password_hash',
                        'Password value does not look like a hashed credential. Verify how this account authenticates.'
                    ),
                    'score' => $is_admin ? 35 : 12,
                ];
            } else {
                $out[] = [
                    'issue' => $this->issue(
                        $is_admin ? 'warning' : 'info',
                        'uncommon_password_hash',
                        'Password hash uses an uncommon format. Verify if a custom hasher or SSO plugin is in use.'
                    ),
                    'score' => $is_admin ? 18 : 6,
                ];
            }
        // Shared hashes: staging clones often share 2 accounts; flag stronger at 3+
        } elseif ($pass_hash !== '' && isset($hash_counts[$pass_hash]) && $hash_counts[$pass_hash] >= 3) {
            $out[] = [
                'issue' => $this->issue(
                    $is_admin ? 'warning' : 'info',
                    'shared_password_hash',
                    'Password hash is shared with ' . ($hash_counts[$pass_hash] - 1) . ' other account(s). Possible cloned users.'
                ),
                'score' => $is_admin ? 25 : 8,
            ];
        } elseif ($pass_hash !== '' && $is_admin && isset($hash_counts[$pass_hash]) && $hash_counts[$pass_hash] === 2) {
            $out[] = [
                'issue' => $this->issue(
                    'info',
                    'shared_password_hash',
                    'Password hash is shared with 1 other account. Verify if that is intentional.'
                ),
                'score' => 8,
            ];
        }

        // Pending password reset key — common leftover; info unless admin is also brand-new
        $activation = isset($user->user_activation_key) ? (string) $user->user_activation_key : '';
        if ($activation === '' && isset($user->data->user_activation_key)) {
            $activation = (string) $user->data->user_activation_key;
        }
        if ($is_admin && $activation !== '') {
            $out[] = [
                'issue' => $this->issue(
                    'info',
                    'pending_activation_key',
                    'Administrator has a non-empty activation/reset key. Clear it if unexpected.'
                ),
                'score' => 5,
            ];
        }

        return $out;
    }

    /**
     * Classify user_pass into a hash family.
     *
     * @return string empty|phpass|wp_bcrypt|bcrypt|argon2|md5|unknown
     */
    private function classify_password_hash($hash) {
        $hash = (string) $hash;
        if ($hash === '') {
            return 'empty';
        }
        // Use # delimiters — phpass alphabet includes "/" which would break /.../ patterns
        if (preg_match('#^\$P\$[./0-9A-Za-z]{31}$#', $hash) || preg_match('#^\$H\$[./0-9A-Za-z]{31}$#', $hash)) {
            return 'phpass';
        }
        // WP 6.8+ default: "$wp" + bcrypt of HMAC-SHA384(password)
        if (preg_match('#^\$wp\$2[ayb]\$\d{2}\$[./0-9A-Za-z]{53}$#', $hash)) {
            return 'wp_bcrypt';
        }
        // Vanilla bcrypt (pre-6.8 plugins / custom hashers / migrated)
        if (preg_match('#^\$2[ayb]\$\d{2}\$[./0-9A-Za-z]{53}$#', $hash)) {
            return 'bcrypt';
        }
        if (preg_match('#^\$argon2(id|i|d)\$#', $hash) && strlen($hash) >= 48) {
            return 'argon2';
        }
        // Very old MD5 (32 hex) — recognized but weak
        if (preg_match('#^[a-f0-9]{32}$#i', $hash)) {
            return 'md5';
        }
        return 'unknown';
    }

    private function is_recognized_password_hash_family($family) {
        return in_array($family, ['phpass', 'wp_bcrypt', 'bcrypt', 'argon2', 'md5'], true);
    }

    /** Heuristic: value looks more like plaintext than a hash. */
    private function password_hash_looks_plaintext($hash) {
        $hash = (string) $hash;
        if ($hash === '' || strpos($hash, '$') === 0) {
            return false;
        }
        if (strlen($hash) > 64) {
            return false;
        }
        return (bool) preg_match('#^[\x20-\x7e]+$#', $hash);
    }

    /**
     * Score application passwords with naming and timing heuristics.
     *
     * @param array $app_passwords
     * @param bool $is_admin
     * @param int|false $reg_ts
     * @return array{issues: array, score: int}
     */
    private function score_application_passwords(array $app_passwords, $is_admin, $reg_ts) {
        $issues = [];
        $score = 0;
        $count = count($app_passwords);
        if ($count === 0) {
            return ['issues' => $issues, 'score' => 0];
        }

        // Presence alone is hygiene, not malware — keep as info so legit apps do not force "warning"
        $issues[] = $this->issue(
            'info',
            'application_passwords',
            $count . ' application password(s). Review and revoke if unexpected.'
        );
        $score += $is_admin ? 5 : 2;

        $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        foreach ($app_passwords as $ap) {
            $name = strtolower(trim((string) ($ap['name'] ?? '')));
            $created = isset($ap['created']) ? (int) $ap['created'] : 0;

            if ($name === '' || $name === 'unnamed') {
                if ($is_admin) {
                    $issues[] = $this->issue('info', 'app_pw_unnamed', 'Application password with empty/unnamed label');
                    $score += 5;
                }
            } else {
                foreach (self::$SUSPICIOUS_APP_PW_NAMES as $frag) {
                    $hit = ($name === $frag)
                        || preg_match('/(?:^|[\s_\-./])' . preg_quote($frag, '/') . '(?:[\s_\-./]|$)/i', $name);
                    if ($hit) {
                        $issues[] = $this->issue(
                            $is_admin ? 'critical' : 'warning',
                            'app_pw_suspicious_name',
                            'Application password name looks automated/suspicious: ' . ($ap['name'] ?? $name)
                        );
                        $score += $is_admin ? 40 : 15;
                        break;
                    }
                }
                // Pure hex blob names only (UUID-style with hyphens is normal for integrations)
                if (preg_match('/^[a-f0-9]{16,}$/i', $name)) {
                    $issues[] = $this->issue(
                        'info',
                        'app_pw_random_name',
                        'Application password name looks randomly generated (hex)'
                    );
                    $score += 5;
                }
            }

            // Toolkit pattern: app password minted at account birth
            if ($is_admin && $reg_ts && $created > 0 && abs($created - $reg_ts) <= 3600) {
                $issues[] = $this->issue(
                    'warning',
                    'app_pw_at_registration',
                    'Application password created within an hour of account registration'
                );
                $score += 20;
            }
        }

        // Deduplicate issue codes that can fire per-password (keep first of each code+message combo is fine;
        // collapse repeated same code to one if many)
        $issues = $this->dedupe_issues($issues);

        return ['issues' => $issues, 'score' => $score];
    }

    private function dedupe_issues(array $issues) {
        $seen = [];
        $out = [];
        foreach ($issues as $iss) {
            $key = ($iss['code'] ?? '') . '|' . ($iss['message'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $iss;
        }
        return $out;
    }

    /**
     * @param int $user_id
     * @return array
     */
    private function read_application_passwords($user_id) {
        $raw = get_user_meta($user_id, '_application_passwords', true);
        if (!is_array($raw) || empty($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'uuid' => $item['uuid'] ?? ($item['name'] ?? null),
                'name' => $item['name'] ?? 'Unnamed',
                'created' => isset($item['created']) ? (int) $item['created'] : null,
                'last_used' => isset($item['last_used']) ? (int) $item['last_used'] : null,
                // never expose password hash / raw token
            ];
        }
        return $out;
    }

    /**
     * @param int $user_id
     * @param bool $is_admin
     * @return array
     */
    private function read_sessions($user_id, $is_admin = false) {
        unset($is_admin); // reserved for future admin-only session detail caps
        $raw = get_user_meta($user_id, 'session_tokens', true);
        if (!is_array($raw) || empty($raw)) {
            return [];
        }
        $out = [];
        $i = 0;
        foreach ($raw as $verifier => $data) {
            if ($i >= 20) {
                break; // cap UI noise
            }
            if (!is_array($data)) {
                continue;
            }
            $ip = isset($data['ip']) ? (string) $data['ip'] : '';
            $ua = isset($data['ua']) ? (string) $data['ua'] : '';
            $login = isset($data['login']) ? (int) $data['login'] : null;
            $exp = isset($data['expiration']) ? (int) $data['expiration'] : null;

            $blank_ua = ($ua === '' || trim($ua) === '');
            $bot_ua = false;
            $ua_l = strtolower($ua);
            foreach (self::$BOT_UA_FRAGMENTS as $frag) {
                if ($ua_l !== '' && strpos($ua_l, $frag) !== false) {
                    $bot_ua = true;
                    break;
                }
            }
            $dc_hint = $this->ip_looks_hosting_range($ip);

            $out[] = [
                'ip' => $ip,
                'ua' => function_exists('mb_substr') ? mb_substr($ua, 0, 180) : substr($ua, 0, 180),
                'login' => $login,
                'expiration' => $exp,
                'blank_ua' => $blank_ua,
                'bot_ua' => $bot_ua,
                'dc_ip_hint' => $dc_hint,
            ];
            $i++;
        }
        return $out;
    }

    /**
     * Soft heuristic — coarse cloud/hosting prefix list. Hint only.
     */
    private function ip_looks_hosting_range($ip) {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // IPv6: light touch — common cloud hextets
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $l = strtolower($ip);
            if (strpos($l, '2a0') === 0 || strpos($l, '2600:') === 0 || strpos($l, '2607:') === 0
                || strpos($l, '2a01:') === 0 || strpos($l, '2a02:') === 0) {
                return true;
            }
            return false;
        }
        foreach (self::$CLOUD_IP_PREFIXES as $prefix) {
            if (strpos($ip, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Multisite super admin list vs wp_users.
     */
    private function audit_super_admins(array $users_out, $is_ms) {
        if (!$is_ms) {
            return [];
        }
        $logins = [];
        if (function_exists('get_super_admins')) {
            $logins = get_super_admins();
        } else {
            $opt = get_site_option('site_admins', []);
            $logins = is_array($opt) ? $opt : [];
        }
        if (!is_array($logins)) {
            return [];
        }

        $by_login = [];
        foreach ($users_out as $u) {
            $by_login[strtolower($u['username'])] = $u;
        }

        $out = [];
        foreach ($logins as $login) {
            $login = (string) $login;
            $key = strtolower($login);
            $issues = [];
            $status = 'healthy';
            $user = $by_login[$key] ?? null;
            if (!$user) {
                $wp_user = function_exists('get_user_by') ? get_user_by('login', $login) : false;
                if (!$wp_user) {
                    $issues[] = $this->issue('critical', 'orphan_super_admin', 'Listed as super admin but no matching user account');
                    $status = 'critical';
                    $out[] = [
                        'username' => $login,
                        'user_id' => null,
                        'orphan' => true,
                        'issues' => $issues,
                        'status' => $status,
                    ];
                    continue;
                }
                $user = ['id' => (int) $wp_user->ID, 'username' => $login, 'email' => $wp_user->user_email];
            }
            $out[] = [
                'username' => $login,
                'user_id' => $user['id'] ?? null,
                'email' => $user['email'] ?? '',
                'orphan' => false,
                'issues' => $issues,
                'status' => $status,
            ];
        }
        return $out;
    }

    /**
     * Inspect sensitive hooks for callbacks outside core/plugins/themes.
     */
    private function audit_sensitive_hooks() {
        global $wp_filter;
        if (!is_array($wp_filter) && !is_object($wp_filter)) {
            return [];
        }

        // Focused list — omit high-volume hooks (user_has_cap, map_meta_cap, profile_update,
        // setted_transient) that every security plugin legitimately touches.
        $hooks = [
            'authenticate',
            'determine_current_user',
            'wp_authenticate_user',
            'login_redirect',
            'set_auth_cookie',
            'set_logged_in_cookie',
            // Hide users
            'pre_user_query',
            'users_list_table_query_args',
            'views_users',
            'load-users.php',
            'load-user-edit.php',
            // Hide plugins
            'all_plugins',
            'pre_current_active_plugins',
            // REST auth bypass
            'rest_authentication_errors',
            // User create
            'user_register',
            'register_new_user',
        ];

        $high_risk_hooks = [
            'authenticate', 'determine_current_user', 'pre_user_query',
            'views_users', 'users_list_table_query_args', 'all_plugins',
            'pre_current_active_plugins', 'rest_authentication_errors',
            'set_auth_cookie', 'wp_authenticate_user',
        ];

        $out = [];
        $sniffed = 0;
        $max_sniff = 25;
        foreach ($hooks as $hook) {
            if (!isset($wp_filter[$hook])) {
                continue;
            }
            $callbacks = $this->extract_callbacks($wp_filter[$hook]);
            foreach ($callbacks as $cb) {
                $origin = $this->resolve_callback_origin($cb['callable']);
                $status = 'healthy';
                $issues = [];
                $kind = $origin['kind'] ?? '';
                $risk = $origin['risk'] ?? 'info';
                $is_high = in_array($hook, $high_risk_hooks, true);

                // Malware-safe content sniff on sensitive hooks:
                //  - Inspect non-core paths (mu-plugin / theme / uploads / root / site / plugin)
                //  - Also inspect core for *strict* execution sinks only (infected core files);
                //    never elevate on base64_decode alone (common in WP core)
                //  - Plugins: elevate on critical content only (eval/shell), not hide-user
                //    registration strings that legitimate security plugins also contain
                $sniff_reasons = [];
                $sniff_risk = 'healthy';
                if ($is_high && !empty($origin['file']) && $sniffed < $max_sniff) {
                    $sniff = $this->sniff_file_for_persistence($origin['file']);
                    $sniffed++;
                    $sniff_reasons = $sniff['reasons'] ?? [];
                    $sniff_risk = $sniff['risk'] ?? 'healthy';
                    if ($kind === 'core') {
                        $strict = array_values(array_intersect($sniff_reasons, [
                            'Dynamic code execution patterns',
                            'Shell execution functions',
                            'system() with variable argument',
                            'Superglobal fed into dangerous sink',
                            'Writes request data to disk',
                            'Creates WordPress administrator user',
                            'Elevates user to administrator',
                        ]));
                        if (!empty($strict)) {
                            $risk = 'critical';
                            $sniff_risk = 'critical';
                            $sniff_reasons = $strict;
                            $origin['reason'] = 'WordPress core file with execution sinks: ' . implode('; ', $strict);
                        }
                    } elseif ($sniff_risk === 'critical') {
                        $risk = 'critical';
                        $origin['reason'] = ($origin['reason'] ?? 'Callback') . ': ' . implode('; ', $sniff_reasons);
                    } elseif ($sniff_risk === 'warning' && $kind !== 'plugin' && $risk !== 'critical') {
                        // Warning-level content (hide-user registration) for mu/theme/uploads
                        $risk = 'warning';
                        if (!empty($sniff_reasons)) {
                            $origin['reason'] = ($origin['reason'] ?? 'Callback') . ': ' . implode('; ', $sniff_reasons);
                        }
                    }
                }

                if ($risk === 'critical') {
                    $status = 'critical';
                    $issues[] = $this->issue('critical', 'hook_bad_path', $origin['reason']);
                } elseif ($risk === 'warning') {
                    // Path-only mu-plugin on high-risk hooks: keep as warning (persistence surface).
                    // Skip mu-plugin path-only on non-high-risk hooks to limit noise.
                    if ($kind === 'mu-plugin' && !$is_high && empty($sniff_reasons)) {
                        continue;
                    }
                    // Plugin path is normally healthy; only surface plugins when content was critical
                    // (handled above). Path-only plugin = skip.
                    if ($kind === 'plugin' && $sniff_risk !== 'critical') {
                        continue;
                    }
                    $status = 'warning';
                    $issues[] = $this->issue('warning', 'hook_review_path', $origin['reason']);
                }

                if ($status === 'healthy' && $is_high && $kind === 'theme') {
                    $status = 'warning';
                    $issues[] = $this->issue(
                        'warning',
                        'hook_theme_auth',
                        'Sensitive hook implemented from a theme path. Uncommon. Review it.'
                    );
                }

                if ($status === 'healthy') {
                    continue;
                }
                $out[] = [
                    'hook' => $hook,
                    'callback' => $cb['label'],
                    'priority' => $cb['priority'],
                    'file' => $origin['file'],
                    'origin_kind' => $origin['kind'],
                    'issues' => $issues,
                    'status' => $status,
                ];
            }
        }
        return $out;
    }

    private function extract_callbacks($hook_obj) {
        $list = [];
        $callbacks = [];
        if (is_object($hook_obj) && isset($hook_obj->callbacks) && is_array($hook_obj->callbacks)) {
            $callbacks = $hook_obj->callbacks;
        } elseif (is_array($hook_obj)) {
            $callbacks = $hook_obj;
        }
        foreach ($callbacks as $priority => $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $entry) {
                $fn = $entry['function'] ?? null;
                if ($fn === null) {
                    continue;
                }
                $list[] = [
                    'priority' => (int) $priority,
                    'callable' => $fn,
                    'label' => $this->callable_label($fn),
                ];
            }
        }
        return $list;
    }

    private function callable_label($fn) {
        if (is_string($fn)) {
            return $fn;
        }
        if (is_array($fn)) {
            $obj = $fn[0];
            $method = $fn[1] ?? '?';
            if (is_object($obj)) {
                return get_class($obj) . '::' . $method;
            }
            return (string) $obj . '::' . $method;
        }
        if ($fn instanceof Closure) {
            return 'Closure';
        }
        return 'callable';
    }

    /**
     * Resolve where a callable lives on disk.
     *
     * @return array{file:?string,kind:string,risk:string,reason:string}
     */
    public function resolve_callback_origin($fn) {
        $file = null;
        try {
            if (is_string($fn) && function_exists($fn)) {
                $r = new ReflectionFunction($fn);
                $file = $r->getFileName() ?: null;
            } elseif (is_array($fn)) {
                $obj = $fn[0];
                $method = $fn[1] ?? null;
                if (is_object($obj) && $method) {
                    $r = new ReflectionMethod($obj, $method);
                    $file = $r->getFileName() ?: null;
                } elseif (is_string($obj) && $method && method_exists($obj, $method)) {
                    $r = new ReflectionMethod($obj, $method);
                    $file = $r->getFileName() ?: null;
                }
            } elseif ($fn instanceof Closure) {
                $r = new ReflectionFunction($fn);
                $file = $r->getFileName() ?: null;
            }
        } catch (Throwable $e) {
            return [
                'file' => null,
                'kind' => 'unknown',
                'risk' => 'warning',
                'reason' => 'Could not resolve callback location',
            ];
        }

        if (!$file) {
            return [
                'file' => null,
                'kind' => 'unknown',
                'risk' => 'info',
                'reason' => 'No file path for callback',
            ];
        }

        $file = str_replace('\\', '/', $file);
        $kind = 'other';
        $risk = 'info';
        $reason = 'Callback in ' . $file;

        $abspath = defined('ABSPATH') ? str_replace('\\', '/', ABSPATH) : '';
        $content = defined('WP_CONTENT_DIR') ? str_replace('\\', '/', WP_CONTENT_DIR) : ($abspath . 'wp-content');
        $plugin_dir = defined('WP_PLUGIN_DIR') ? str_replace('\\', '/', WP_PLUGIN_DIR) : ($content . '/plugins');
        $mu_dir = defined('WPMU_PLUGIN_DIR') ? str_replace('\\', '/', WPMU_PLUGIN_DIR) : ($content . '/mu-plugins');
        $theme_root = function_exists('get_theme_root') ? str_replace('\\', '/', get_theme_root()) : ($content . '/themes');

        if ($abspath && (strpos($file, $abspath . 'wp-includes/') === 0 || strpos($file, $abspath . 'wp-admin/') === 0)) {
            $kind = 'core';
            $risk = 'healthy';
            $reason = 'WordPress core';
        } elseif (strpos($file, $plugin_dir . '/') === 0) {
            $kind = 'plugin';
            $risk = 'healthy';
            $reason = 'Plugin path';
        } elseif (strpos($file, $theme_root . '/') === 0) {
            $kind = 'theme';
            $risk = 'healthy';
            $reason = 'Theme path';
        } elseif (strpos($file, $mu_dir . '/') === 0) {
            $kind = 'mu-plugin';
            $risk = 'warning';
            $reason = 'Must-use plugin. Review it (common persistence location).';
        } elseif (strpos($file, $content . '/uploads/') !== false || strpos($file, '/uploads/') !== false) {
            $kind = 'uploads';
            $risk = 'critical';
            $reason = 'Callback defined under uploads/';
        } elseif ($abspath && strpos($file, rtrim($abspath, '/')) === 0) {
            $rel = ltrim(substr($file, strlen(rtrim($abspath, '/'))), '/');
            if (preg_match('#^(wp-config\.php|[^/]+\.php)$#', $rel)) {
                $kind = 'root';
                $risk = 'critical';
                $reason = 'Callback in site root PHP file';
            } else {
                $kind = 'site';
                $risk = 'warning';
                $reason = 'Callback outside plugins/themes/core';
            }
        } else {
            $kind = 'external';
            $risk = 'warning';
            $reason = 'Callback outside WordPress tree';
        }

        // Relative display path
        $display = $file;
        if ($abspath && strpos($file, $abspath) === 0) {
            $display = ltrim(substr($file, strlen($abspath)), '/');
        }

        return [
            'file' => $display,
            'kind' => $kind,
            'risk' => $risk === 'healthy' ? 'healthy' : $risk,
            'reason' => $reason,
        ];
    }

    /**
     * Lightweight content sniff of a PHP file for high-risk persistence patterns.
     * Used by cron audit for non-trusted callback origins.
     *
     * @param string $relative_or_abs File path (relative to ABSPATH or absolute)
     * @param int $max_bytes
     * @return array{risk: string, reasons: string[]}
     */
    public function sniff_file_for_persistence($relative_or_abs, $max_bytes = 65536) {
        $reasons = [];
        $risk = 'healthy';
        $path = (string) $relative_or_abs;
        if ($path === '') {
            return ['risk' => 'info', 'reasons' => ['No path']];
        }
        $abspath = defined('ABSPATH') ? str_replace('\\', '/', ABSPATH) : '';
        $full = $path;
        if ($abspath && $path[0] !== '/' && !preg_match('#^[A-Za-z]:/#', $path)) {
            $full = rtrim($abspath, '/') . '/' . ltrim($path, '/');
        }
        $full = str_replace('\\', '/', $full);
        // Avoid stale stat cache when the same path was just rewritten (common in tests / rescan)
        clearstatcache(true, $full);
        if (!is_file($full) || !is_readable($full)) {
            return ['risk' => 'warning', 'reasons' => ['Callback file missing or unreadable']];
        }
        $size = @filesize($full);
        if ($size === false) {
            return ['risk' => 'info', 'reasons' => []];
        }
        $read = (int) min($max_bytes, max(0, $size));
        if ($read === 0) {
            return ['risk' => 'info', 'reasons' => ['Empty file']];
        }
        // Prefer file_get_contents — always reads current bytes; avoid partial fread after truncate/grow
        $buf = @file_get_contents($full, false, null, 0, $read);
        if ($buf === false) {
            return ['risk' => 'warning', 'reasons' => ['Could not read callback file']];
        }
        $buf = (string) $buf;

        $patterns = [
            ['/(\beval\s*\(|\bassert\s*\(|create_function\s*\(|preg_replace\s*\([^)]*\/e|\bgzinflate\s*\(|\bstr_rot13\s*\(.*base64)/i', 'critical', 'Dynamic code execution patterns'],
            // Avoid bare \bexec( — too easy to over-match; require clear shell sinks
            ['/\b(shell_exec|passthru|proc_open|popen)\s*\(/i', 'critical', 'Shell execution functions'],
            ['/(?<![>\w])(?:system)\s*\(\s*\$/i', 'critical', 'system() with variable argument'],
            ['/base64_decode\s*\(\s*["\'][A-Za-z0-9+\/=]{40,}/i', 'critical', 'Inline base64 payload'],
            // User create + administrator elevation is the high-signal combo
            ['/(?:wp_insert_user|wp_create_user)\s*\([^;]{0,200}administrator/is', 'critical', 'Creates WordPress administrator user'],
            ['/set_role\s*\(\s*[\'"]administrator[\'"]/i', 'critical', 'Elevates user to administrator'],
            // Hide-user only when registered as hook (string mention alone is noise)
            ['/add_(?:action|filter)\s*\(\s*[\'"](?:pre_user_query|views_users|all_plugins|pre_current_active_plugins)[\'"]/i', 'warning', 'Hide-user or hide-plugin hooks'],
            ['/__GA_INJ_(?:START|END)__/', 'critical', 'GA_INJ inject fence'],
            ['/class\s+GAwp_[a-f0-9]{6,16}\b/i', 'critical', 'GA_INJ GAwp_ backdoor class'],
            ['/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[[^\]]{0,80}\].{0,40}(?:eval|assert|base64_decode|shell_exec)\s*\(/is', 'critical', 'Superglobal fed into dangerous sink'],
            ['/file_put_contents\s*\(\s*[^;]{0,120}\$_(?:GET|POST|REQUEST)/is', 'critical', 'Writes request data to disk'],
            ['/wp_remote_(?:get|post)\s*\(\s*[\'"]https?:\/\/\d{1,3}\.\d{1,3}/i', 'warning', 'HTTP to raw IP'],
            // Dense hex obfuscation only (3 pairs is normal in some strings)
            ['/(?:\\\\x[0-9a-f]{2}){8,}/i', 'warning', 'Hex-encoded string obfuscation'],
        ];

        foreach ($patterns as $p) {
            if (preg_match($p[0], $buf)) {
                $reasons[] = $p[2];
                if ($p[1] === 'critical') {
                    $risk = 'critical';
                } elseif ($p[1] === 'warning' && $risk !== 'critical') {
                    $risk = 'warning';
                }
            }
        }

        return ['risk' => $risk, 'reasons' => array_values(array_unique($reasons))];
    }

    private function issue($severity, $code, $message) {
        return [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function score_to_status($score, array $issues) {
        $has_warn = false;
        foreach ($issues as $iss) {
            $sev = $iss['severity'] ?? '';
            if ($sev === 'critical') {
                return 'critical';
            }
            // "high" maps to warning status in the UI (no separate bucket)
            if ($sev === 'warning' || $sev === 'high') {
                $has_warn = true;
            }
        }
        // Align with cron: do not force critical from stacked soft signals alone
        // (e.g. new admin + disposable email + app passwords was over-firing).
        if ($score >= 75 && $has_warn) {
            return 'critical';
        }
        if ($score >= 20 || $has_warn) {
            return 'warning';
        }
        if ($score > 0 || !empty($issues)) {
            return empty($issues) ? 'healthy' : 'info';
        }
        return 'healthy';
    }

    // ── Remediations ──────────────────────────────────────────

    public function revoke_application_passwords($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return ['success' => false, 'error' => 'Invalid user'];
        }
        if (class_exists('WP_Application_Passwords')) {
            WP_Application_Passwords::delete_all_application_passwords($user_id);
        } else {
            delete_user_meta($user_id, '_application_passwords');
        }
        return ['success' => true, 'user_id' => $user_id];
    }

    public function destroy_sessions($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return ['success' => false, 'error' => 'Invalid user'];
        }
        if (class_exists('WP_Session_Tokens')) {
            $manager = WP_Session_Tokens::get_instance($user_id);
            if ($manager && method_exists($manager, 'destroy_all')) {
                $manager->destroy_all();
            } else {
                delete_user_meta($user_id, 'session_tokens');
            }
        } else {
            delete_user_meta($user_id, 'session_tokens');
        }
        return ['success' => true, 'user_id' => $user_id];
    }

    public function demote_user($user_id, $role = 'subscriber') {
        $user_id = (int) $user_id;
        $current = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id <= 0) {
            return ['success' => false, 'error' => 'Invalid user'];
        }
        if ($user_id === $current) {
            return ['success' => false, 'error' => 'Cannot demote the account currently running Clean Sweep'];
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }
        $allowed = ['subscriber', 'contributor', 'author', 'editor'];
        if (!in_array($role, $allowed, true)) {
            $role = 'subscriber';
        }
        // Same hide-user-aware last-admin guard as delete_user
        $listed = get_users(['role' => 'administrator', 'number' => -1, 'fields' => 'ID']);
        $admin_ids = array_map('intval', (array) $listed);
        $admin_ids = array_values(array_unique(array_merge($admin_ids, $this->raw_administrator_user_ids())));
        $is_admin_target = in_array('administrator', (array) $user->roles, true)
            || in_array($user_id, $admin_ids, true);
        if ($is_admin_target && count($admin_ids) <= 1) {
            return ['success' => false, 'error' => 'Cannot demote the last administrator'];
        }
        $user->set_role($role);
        // Ensure raw administrator / manage_options grants do not linger after demote
        // (filters or partial meta writes can leave elevated caps; force role-only blob)
        $raw = $this->read_raw_capabilities($user_id);
        if (!empty($raw['has_administrator']) || !empty($raw['has_manage_options'])) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb) && function_exists('update_user_meta')) {
                $cap_key = $wpdb->get_blog_prefix() . 'capabilities';
                update_user_meta($user_id, $cap_key, [$role => true]);
                if (function_exists('clean_user_cache')) {
                    clean_user_cache($user_id);
                }
            }
        }
        return ['success' => true, 'user_id' => $user_id, 'role' => $role];
    }

    public function delete_user($user_id, $reassign = null) {
        $user_id = (int) $user_id;
        $current = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id <= 0) {
            return ['success' => false, 'error' => 'Invalid user'];
        }
        if ($user_id === $current) {
            return ['success' => false, 'error' => 'Cannot delete the account currently running Clean Sweep'];
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }
        // Prevent deleting the last administrator.
        // Use raw capability meta as well as get_users() — hide-user malware can shrink
        // the role query so a hidden backdoor looks like the "only" admin and blocks deletion.
        $listed = get_users(['role' => 'administrator', 'number' => -1, 'fields' => 'ID']);
        $admin_ids = array_map('intval', (array) $listed);
        $admin_ids = array_values(array_unique(array_merge($admin_ids, $this->raw_administrator_user_ids())));
        $is_admin_target = in_array('administrator', (array) $user->roles, true)
            || in_array($user_id, $admin_ids, true);
        if ($is_admin_target && count($admin_ids) <= 1) {
            return ['success' => false, 'error' => 'Cannot delete the last administrator'];
        }
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $reassign_id = $reassign !== null ? (int) $reassign : null;
        $ok = wp_delete_user($user_id, $reassign_id);
        if (!$ok) {
            return ['success' => false, 'error' => 'wp_delete_user failed'];
        }
        return ['success' => true, 'user_id' => $user_id];
    }
}

} // class_exists
