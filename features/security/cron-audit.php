<?php
/**
 * Clean Sweep — WP-Cron + Action Scheduler audit
 *
 * High-signal detection for scheduled-task malware / persistence:
 *  - Hook name heuristics + expanded core allowlist
 *  - Callback path origin + lightweight file content sniff
 *  - Args as payload (serialized objects, base64, remote URLs, file paths)
 *  - Orphan recurring vs one-shot nuance
 *  - Server crontab with low false positives on legitimate wp-cron
 *  - Action Scheduler pending/failed + recent completed with payload-like args
 *
 * @since Cron tab live audit
 */

if (!class_exists('CleanSweep_CronAudit')) {

require_once __DIR__ . '/user-audit.php'; // reuse resolve_callback_origin + sniff_file_for_persistence

class CleanSweep_CronAudit {

    /**
     * True WordPress core hooks only.
     * Plugin hooks must NOT live here — is_core dampening drops non-critical findings
     * (e.g. off-site C2 URLs), which would hide malware on faked "woocommerce_*" names
     * if those were treated as core.
     */
    private static $CORE_HOOKS = [
        'wp_version_check',
        'wp_update_plugins',
        'wp_update_themes',
        'wp_scheduled_delete',
        'delete_expired_transients',
        'wp_scheduled_auto_draft_delete',
        'wp_update_user_counts',
        'wp_site_health_scheduled_check',
        'recovery_mode_clean_expired_keys',
        'wp_https_detection',
        'wp_privacy_delete_old_export_files',
        'publish_future_post',
        'do_pings',
        'importers_scheduled_cleanup',
        'wp_update_comment_type_batch',
        'wp_delete_temp_updater_backups',
        'wp_delete_all_temp_backups',
        'wp_site_health_auto_updates_enabled',
        'wp_check_https_support',
        'wp_update_https_detection',
        'wp_schedule_update_checks',
        'update_network_counts',
        'wp_maybe_auto_update',
        'wp_calendar_sync',
        'delete_orphaned_tables',
        'upgrader_scheduled_cleanup',
    ];

    /**
     * Known-good exact plugin hooks (trusted noise reduction, NOT core dampening).
     */
    private static $TRUSTED_EXACT_HOOKS = [
        'action_scheduler_run_queue',
        'action_scheduler_run_recurring_actions_schedule_hook',
        'woocommerce_scheduled_sales',
        'woocommerce_cancel_unpaid_orders',
        'woocommerce_cleanup_sessions',
        'woocommerce_cleanup_personal_data',
        'woocommerce_geoip_updater',
        'wc_admin_process_orders_milestone',
        'jetpack_clean_nonces',
        'jetpack_v2_heartbeat',
        'elementor/tracker/send_event',
        'elementor/tracker/send',
        'clean_sweep_scan_kick',
    ];

    /**
     * Prefixes treated as trusted for noise reduction.
     * Do NOT use bare "wp_" — malware often uses wp_* hook names.
     */
    private static $TRUSTED_HOOK_PREFIXES = [
        'woocommerce_',
        'wc_',
        'action_scheduler_',
        'jetpack_',
        'elementor/',
        'elementor_',
        'yoast_',
        'wpseo_',
        'wordfence_',
        'sucuri_',
        'rank_math_',
        'litespeed_',
        'w3tc_',
        'wp_mail_smtp_',
        'gravityforms_',
        'gf_',
        'fluentform_',
        'monsterinsights_',
        'exactmetrics_',
        'googlesitekit_',
        'redirection_',
        'akismet_',
        'duplicate_post_',
        'clean_sweep_',
    ];

    /** Max callback files to content-sniff per audit */
    private static $MAX_SNIFFS = 40;

    private $sniff_count = 0;
    private $sniff_cache = [];

    /**
     * Full cron audit payload.
     */
    public function audit() {
        $this->sniff_count = 0;
        $this->sniff_cache = [];

        $wp_cron = $this->audit_wp_cron();
        $action_scheduler = $this->audit_action_scheduler();
        $server_cron = $this->audit_server_crontab();
        $sensitive = $this->audit_transient_hooks();

        $critical = 0;
        $warning = 0;
        foreach ($wp_cron['events'] as $e) {
            if (($e['status'] ?? '') === 'critical') {
                $critical++;
            } elseif (($e['status'] ?? '') === 'warning') {
                $warning++;
            }
        }
        foreach ($action_scheduler['actions'] as $e) {
            if (($e['status'] ?? '') === 'critical') {
                $critical++;
            } elseif (($e['status'] ?? '') === 'warning') {
                $warning++;
            }
        }
        // Include server crontab + transient-hook findings in summary totals
        foreach ($server_cron['entries'] ?? [] as $entry) {
            if (($entry['status'] ?? '') === 'critical') {
                $critical++;
            } elseif (($entry['status'] ?? '') === 'warning') {
                $warning++;
            }
        }
        foreach ($sensitive as $h) {
            if (($h['status'] ?? '') === 'critical') {
                $critical++;
            } elseif (($h['status'] ?? '') === 'warning') {
                $warning++;
            }
        }

        return [
            'wp_cron' => $wp_cron,
            'action_scheduler' => $action_scheduler,
            'server_crontab' => $server_cron,
            'sensitive_hooks' => $sensitive,
            'summary' => [
                'wp_cron_events' => count($wp_cron['events']),
                'wp_cron_critical' => count(array_filter($wp_cron['events'], function ($e) {
                    return ($e['status'] ?? '') === 'critical';
                })),
                'wp_cron_warning' => count(array_filter($wp_cron['events'], function ($e) {
                    return ($e['status'] ?? '') === 'warning';
                })),
                'as_available' => !empty($action_scheduler['available']),
                'as_actions' => count($action_scheduler['actions']),
                'as_critical' => count(array_filter($action_scheduler['actions'], function ($e) {
                    return ($e['status'] ?? '') === 'critical';
                })),
                'server_crontab_available' => !empty($server_cron['available']),
                'server_crontab_suspicious' => count($server_cron['entries'] ?? []),
                'critical' => $critical,
                'warning' => $warning,
                'files_sniffed' => $this->sniff_count,
            ],
            'audited_at' => time(),
        ];
    }

    private function audit_wp_cron() {
        $crons = function_exists('_get_cron_array') ? _get_cron_array() : [];
        if (!is_array($crons)) {
            $crons = [];
        }

        $schedules = function_exists('wp_get_schedules') ? wp_get_schedules() : [];
        $events = [];
        $id = 0;
        $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $year_ahead = time() + 365 * $day;
        $day_ago = time() - $day;

        foreach ($crons as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }
            $ts = (int) $timestamp;
            foreach ($hooks as $hook => $instances) {
                if (!is_array($instances)) {
                    continue;
                }
                foreach ($instances as $sig => $data) {
                    $id++;
                    $schedule = $data['schedule'] ?? false;
                    $args = $data['args'] ?? [];
                    $interval = $data['interval'] ?? null;
                    $schedule_label = is_string($schedule) ? $schedule : ($schedule === false ? 'once' : 'custom');
                    if ($interval && isset($schedules[$schedule]['display'])) {
                        $schedule_label = $schedules[$schedule]['display'];
                    } elseif (is_string($schedule) && isset($schedules[$schedule]['display'])) {
                        $schedule_label = $schedules[$schedule]['display'];
                    }

                    $callbacks = $this->callbacks_for_hook($hook);
                    // Pass raw schedule key for recurring detection; label is display-only
                    $raw_schedule_key = is_string($schedule) ? $schedule : ($schedule === false ? 'once' : '');
                    $scored = $this->score_event([
                        'hook' => (string) $hook,
                        'timestamp' => $ts,
                        'schedule' => $raw_schedule_key,
                        'interval_seconds' => is_numeric($interval) ? (int) $interval : $this->interval_seconds($schedule, $schedules),
                        'args' => $args,
                        'callbacks' => $callbacks,
                        'source' => 'wp_cron',
                        'sig' => (string) $sig,
                        'is_recurring' => $schedule !== false && $schedule !== null && $schedule !== 'once',
                    ], $year_ahead, $day_ago);

                    $events[] = array_merge($scored, [
                        'id' => 'wp_' . $id,
                        'hook' => (string) $hook,
                        'timestamp' => $ts,
                        'next_run' => $ts > 0 ? gmdate('Y-m-d H:i:s', $ts) . ' UTC' : null,
                        'schedule' => $schedule_label,
                        'args_preview' => $this->args_preview($args),
                        'callbacks' => $callbacks,
                        'source' => 'wp_cron',
                        'sig' => (string) $sig,
                    ]);
                }
            }
        }

        usort($events, function ($a, $b) {
            $rank = ['critical' => 0, 'warning' => 1, 'info' => 2, 'healthy' => 3];
            $ra = $rank[$a['status']] ?? 9;
            $rb = $rank[$b['status']] ?? 9;
            if ($ra !== $rb) {
                return $ra - $rb;
            }
            return ($a['timestamp'] ?? 0) - ($b['timestamp'] ?? 0);
        });

        return [
            'events' => $events,
            'total' => count($events),
        ];
    }

    private function interval_seconds($schedule, $schedules) {
        if (is_string($schedule) && isset($schedules[$schedule]['interval'])) {
            return (int) $schedules[$schedule]['interval'];
        }
        return null;
    }

    private function callbacks_for_hook($hook) {
        global $wp_filter;
        $out = [];
        if (!isset($wp_filter[$hook])) {
            return $out;
        }
        $user_audit = new CleanSweep_UserAudit();
        $hook_obj = $wp_filter[$hook];
        $callbacks = [];
        if (is_object($hook_obj) && isset($hook_obj->callbacks)) {
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
                $label = $this->callable_label($fn);
                $origin = $user_audit->resolve_callback_origin($fn);
                $sniff = ['risk' => 'healthy', 'reasons' => []];

                // Content sniff policy (malware-safe first, then noise control):
                //  - Priority: uploads/root/site/external/mu-plugin/theme (common persistence)
                //  - Also sniff plugins/unknown (compromised packages use benign hook names)
                //  - Also sniff core for *strict* execution sinks only (infected core files);
                //    never elevate on base64_decode alone (ubiquitous in WP core)
                //  - Critical content ALWAYS elevates (eval/shell/etc.) for non-core
                //  - Warning content elevates for priority kinds / hostile hooks
                //  - Path alone for mu-plugin is not a finding
                $kind = $origin['kind'] ?? '';
                $risk = $origin['risk'] ?? 'info';
                $priority_kind = in_array($kind, ['uploads', 'root', 'site', 'external', 'mu-plugin', 'theme'], true);
                $should_sniff = $priority_kind
                    || $risk === 'critical'
                    || $kind === 'core'
                    || in_array($kind, ['plugin', 'unknown'], true)
                    || $this->hook_name_looks_hostile($hook);
                if ($should_sniff && !empty($origin['file'])) {
                    $sniff = $this->cached_sniff($user_audit, $origin['file'], $priority_kind || $kind === 'core');
                    $reasons = $sniff['reasons'] ?? [];
                    if ($kind === 'core') {
                        // Infected core: only unequivocal execution sinks
                        $strict = array_values(array_intersect($reasons, [
                            'Dynamic code execution patterns',
                            'Shell execution functions',
                            'system() with variable argument',
                            'Superglobal fed into dangerous sink',
                            'Writes request data to disk',
                            'Creates WordPress administrator user',
                            'Elevates user to administrator',
                        ]));
                        if (!empty($strict)) {
                            $origin['risk'] = 'critical';
                            $origin['reason'] = 'WordPress core file with execution sinks: ' . implode('; ', $strict);
                            $sniff['risk'] = 'critical';
                            $sniff['reasons'] = $strict;
                        }
                    } elseif ($sniff['risk'] === 'critical') {
                        $origin['risk'] = 'critical';
                        $origin['reason'] = ($origin['reason'] ?? 'Callback') . ': ' . implode('; ', $reasons);
                    } elseif ($sniff['risk'] === 'warning'
                        && ($priority_kind || $this->hook_name_looks_hostile($hook))) {
                        if ($risk !== 'critical') {
                            $origin['risk'] = 'warning';
                            if (!empty($reasons)) {
                                $origin['reason'] = ($origin['reason'] ?? 'Callback') . ': ' . implode('; ', $reasons);
                            }
                        }
                    }
                }
                // Bare mu-plugin *path* without content hits is normal — demote path-only warning
                if (($origin['kind'] ?? '') === 'mu-plugin'
                    && ($origin['risk'] ?? '') === 'warning'
                    && !$this->hook_name_looks_hostile($hook)
                    && empty($sniff['reasons'])) {
                    $origin['risk'] = 'info';
                    $origin['reason'] = 'Must-use plugin (path only, no content indicators)';
                }

                $out[] = [
                    'label' => $label,
                    'priority' => (int) $priority,
                    'file' => $origin['file'],
                    'origin_kind' => $origin['kind'],
                    'origin_risk' => $origin['risk'],
                    'origin_reason' => $origin['reason'],
                    'sniff_risk' => $sniff['risk'],
                    'sniff_reasons' => $sniff['reasons'],
                ];
            }
        }
        return $out;
    }

    /**
     * @param bool $priority When true (uploads/root/mu-plugin), may exceed soft cap so
     *                       high-risk persistence locations are not skipped after noise sniffs.
     */
    private function cached_sniff(CleanSweep_UserAudit $user_audit, $file, $priority = false) {
        $key = (string) $file;
        if (isset($this->sniff_cache[$key])) {
            return $this->sniff_cache[$key];
        }
        // Soft cap for non-priority; hard cap is 2× so priority can still run
        $hard = self::$MAX_SNIFFS * 2;
        if ($this->sniff_count >= $hard) {
            return ['risk' => 'info', 'reasons' => []];
        }
        if (!$priority && $this->sniff_count >= self::$MAX_SNIFFS) {
            return ['risk' => 'info', 'reasons' => []];
        }
        $this->sniff_count++;
        $result = $user_audit->sniff_file_for_persistence($file);
        $this->sniff_cache[$key] = $result;
        return $result;
    }

    private function hook_name_looks_hostile($hook) {
        $hook = (string) $hook;
        if (preg_match('/^[a-f0-9]{16,}$/i', $hook) || preg_match('/^_[a-z]+_\d{3,}$/i', $hook)) {
            return true;
        }
        // Multi-char high-risk tokens (avoid substring hits like "evaluation" containing "eval")
        if (preg_match('/(?:^|[^a-z0-9])(?:backdoor|webshell|base64_decode|malware|passwd|bypass)(?:[^a-z0-9]|$)/i', $hook)) {
            return true;
        }
        // Short tokens only as underscore-delimited words
        if (preg_match('/(?:^|_)(?:eval|hack|shell|base64)(?:_|$)/i', $hook)) {
            return true;
        }
        return false;
    }

    private function is_trusted_hook($hook) {
        if (in_array($hook, self::$CORE_HOOKS, true)) {
            return true;
        }
        if (in_array($hook, self::$TRUSTED_EXACT_HOOKS, true)) {
            return true;
        }
        foreach (self::$TRUSTED_HOOK_PREFIXES as $prefix) {
            if (strpos($hook, $prefix) === 0) {
                return true;
            }
        }
        return false;
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

    private function score_event(array $ev, $year_ahead, $day_ago) {
        $issues = [];
        $score = 0;
        $hook = $ev['hook'] ?? '';
        $is_core = in_array($hook, self::$CORE_HOOKS, true);
        $is_trusted = $this->is_trusted_hook($hook);
        $source = (string) ($ev['source'] ?? 'wp_cron');
        // Action Scheduler: respect explicit is_recurring only. Its "schedule" column is often
        // a serialized schedule object / cron expression even for one-shot jobs — deriving
        // recurring from that caused mass false "orphan recurring" criticals.
        $is_recurring = !empty($ev['is_recurring']);
        if (!$is_recurring && $source !== 'action_scheduler') {
            $sched = (string) ($ev['schedule'] ?? '');
            // Use raw schedule keys only, not translated display labels
            $is_recurring = $sched !== '' && $sched !== 'once' && $sched !== 'false'
                && !preg_match('/^once$/i', $sched);
        }

        // Hook name heuristics (same token rules as hook_name_looks_hostile)
        if (preg_match('/^[a-f0-9]{16,}$/i', $hook) || preg_match('/^_[a-z]+_\d{3,}$/i', $hook)) {
            $issues[] = $this->issue('critical', 'obfuscated_hook', 'Hook name looks random / obfuscated');
            $score += 70;
        } elseif ($this->hook_name_looks_hostile($hook)
            && !preg_match('/^[a-f0-9]{16,}$/i', $hook)
            && !preg_match('/^_[a-z]+_\d{3,}$/i', $hook)) {
            // Hostile by keyword tokens (obfuscated already handled above)
            $issues[] = $this->issue('critical', 'suspicious_hook_name', 'Hook name contains high-risk keywords');
            $score += 60;
        }

        // Callbacks
        $callbacks = $ev['callbacks'] ?? [];
        $hook_hostile = $this->hook_name_looks_hostile($hook);
        if (empty($callbacks) && !$is_core && !$is_trusted) {
            if ($is_recurring) {
                // Deactivated-plugin leftovers are common; only critical when name also looks hostile
                if ($hook_hostile) {
                    $issues[] = $this->issue(
                        'critical',
                        'orphan_recurring_hook',
                        'Recurring event with no registered callback and hostile/obfuscated hook name'
                    );
                    $score += 55;
                } else {
                    $issues[] = $this->issue(
                        'warning',
                        'orphan_recurring_hook',
                        'Recurring event with no registered callback (orphaned schedule, often a deactivated plugin)'
                    );
                    $score += 28;
                }
            } else {
                // One-shot orphans are usually stale leftovers — info only, low score
                $issues[] = $this->issue(
                    'info',
                    'orphan_oneshot',
                    'One-shot event with no registered callback (stale leftover)'
                );
                $score += 3;
            }
        } elseif (empty($callbacks) && $is_trusted && !$is_core) {
            // Trusted plugin hook without in-process callback (e.g. AS-driven) — ignore
        }
        foreach ($callbacks as $cb) {
            $label = $cb['label'] ?? '';
            if (preg_match('/base64_decode|eval\s*\(|assert\s*\(|create_function|preg_replace.*\/e|shell_exec|passthru|proc_open|popen\s*\(/i', $label)) {
                $issues[] = $this->issue('critical', 'dangerous_callback', 'Callback label suggests dangerous function');
                $score += 80;
            }
            $risk = $cb['origin_risk'] ?? 'info';
            if ($risk === 'critical') {
                $issues[] = $this->issue('critical', 'callback_path', $cb['origin_reason'] ?? 'Dangerous callback path');
                $score += 80;
            } elseif ($risk === 'warning' && !$is_core && !$is_trusted) {
                $issues[] = $this->issue('warning', 'callback_path_review', $cb['origin_reason'] ?? 'Review callback path');
                $score += 20;
            }
            // Content sniff: avoid double-counting when path risk was already elevated from sniff
            $sniff_reasons = $cb['sniff_reasons'] ?? [];
            $sniff_risk = $cb['sniff_risk'] ?? '';
            $cb_kind = $cb['origin_kind'] ?? '';
            $cb_priority = in_array($cb_kind, ['uploads', 'root', 'site', 'external', 'mu-plugin', 'theme'], true);
            $missing_file = in_array('Callback file missing or unreadable', $sniff_reasons, true);
            $content_reasons = array_values(array_filter($sniff_reasons, function ($r) {
                return $r !== 'Callback file missing or unreadable'
                    && $r !== 'Could not read callback file'
                    && $r !== 'No path'
                    && $r !== 'Empty file';
            }));
            // Only add content issues when path issue didn't already embed the same reasons
            $origin_reason = (string) ($cb['origin_reason'] ?? '');
            $already_in_path = $origin_reason !== '' && !empty($content_reasons)
                && strpos($origin_reason, $content_reasons[0]) !== false;
            // Critical content (eval/shell) always surfaces — even from normal-looking plugins
            if (!$already_in_path && !empty($content_reasons) && $sniff_risk === 'critical') {
                $issues[] = $this->issue(
                    'critical',
                    'callback_content',
                    'Callback file content: ' . implode('; ', array_slice($content_reasons, 0, 3))
                );
                $score += 50;
            } elseif (!$already_in_path && !empty($content_reasons) && $sniff_risk === 'warning' && !$is_trusted
                // Warning content (e.g. hide-user registration strings) only for high-risk locations
                // or hostile hooks — not every security plugin cron callback
                && ($cb_priority || $hook_hostile || $cb_kind === 'unknown')) {
                $issues[] = $this->issue(
                    'warning',
                    'callback_content_review',
                    'Callback file content: ' . implode('; ', array_slice($content_reasons, 0, 3))
                );
                $score += 20;
            }
            // Missing callback file — only for non-trusted, non-core (plugin uninstall leftovers are common)
            if ($missing_file && !$is_core && !$is_trusted) {
                if ($is_recurring && $hook_hostile) {
                    $issues[] = $this->issue(
                        'critical',
                        'callback_file_missing_recurring',
                        'Recurring hostile hook callback file missing. Possible self-healing dropper schedule.'
                    );
                    $score += 50;
                } elseif ($is_recurring) {
                    $issues[] = $this->issue(
                        'warning',
                        'callback_file_missing_recurring',
                        'Recurring callback file missing (often deactivated plugin)'
                    );
                    $score += 25;
                } else {
                    $issues[] = $this->issue(
                        'info',
                        'callback_file_missing',
                        'Callback file missing or unreadable'
                    );
                    $score += 8;
                }
            }
        }

        // Args — payload / remote / path analysis
        $args = $ev['args'] ?? [];
        $args_str = $this->args_preview($args, 4000);
        $arg_hits = $this->analyze_args($args, $args_str, $is_core, $is_trusted);
        foreach ($arg_hits as $hit) {
            $issues[] = $hit['issue'];
            $score += $hit['score'];
        }

        // Frequency — alone is soft; many plugins use sub-5-minute schedules
        $interval = $ev['interval_seconds'] ?? null;
        if ($interval !== null && $interval > 0 && $interval < 300 && !$is_core && !$is_trusted) {
            $url_norm = str_replace('\\/', '/', $args_str);
            $has_remote = (bool) preg_match('#https?://#i', $url_norm);
            if ($has_remote || $hook_hostile || $score >= 40) {
                $issues[] = $this->issue('warning', 'high_frequency', 'Runs more often than every 5 minutes');
                $score += 15;
                if ($has_remote) {
                    $score += 10;
                }
            } else {
                // Noise-only signal when nothing else is suspicious
                $issues[] = $this->issue('info', 'high_frequency', 'Runs more often than every 5 minutes');
                $score += 5;
            }
        }

        // Time anomalies
        $ts = (int) ($ev['timestamp'] ?? 0);
        if ($ts > $year_ahead) {
            $issues[] = $this->issue('warning', 'far_future', 'Scheduled more than 1 year in the future');
            $score += 20;
        }
        if ($ts > 0 && $ts < $day_ago && $is_recurring) {
            $issues[] = $this->issue('info', 'overdue', 'Timestamp is overdue (may be stuck WP-Cron)');
            $score += 5;
        }

        // Dedupe issues by code+message
        $issues = $this->dedupe_issues($issues);

        if ($is_core && $score < 40) {
            // True WP core: keep critical evidence AND off-site/path warnings (malware C2 on
            // core hooks must not be dropped). Drop only pure noise codes.
            $issues = array_values(array_filter($issues, function ($i) {
                $sev = $i['severity'] ?? '';
                $code = $i['code'] ?? '';
                if ($sev === 'critical') {
                    return true;
                }
                if (in_array($sev, ['warning', 'high'], true)
                    && !in_array($code, ['high_frequency', 'overdue', 'orphan_oneshot', 'far_future'], true)) {
                    return true;
                }
                return false;
            }));
            if (empty($issues)) {
                $score = min($score, 15);
            }
        } elseif ($is_trusted && !$is_core && $score < 35) {
            // Trusted plugin hooks: keep critical + warnings (including remote_url_args);
            // drop only pure noise codes — never silence off-site URL / path warnings.
            $issues = array_values(array_filter($issues, function ($i) {
                return in_array($i['severity'] ?? '', ['critical', 'warning', 'high'], true)
                    && !in_array($i['code'] ?? '', ['high_frequency', 'overdue', 'orphan_oneshot'], true);
            }));
            if (empty($issues)) {
                $score = 0;
            }
        }

        $status = $this->score_to_status($score, $issues);
        return [
            'issues' => $issues,
            'score' => $score,
            'status' => $status,
            'is_core' => $is_core,
            'is_trusted' => $is_trusted,
        ];
    }

    /**
     * Deep-ish args inspection for malware payloads.
     *
     * @param mixed $args
     * @param string $args_str
     * @param bool $is_core
     * @param bool $is_trusted
     * @return array<int, array{issue: array, score: int}>
     */
    private function analyze_args($args, $args_str, $is_core, $is_trusted) {
        $hits = [];
        if ($args_str === '' || $args_str === '[]' || $args_str === 'null') {
            return $hits;
        }

        // Serialized PHP objects — require a clear object class name pattern
        if (preg_match('/(^|[^A-Za-z0-9_])O:\d+:"[a-zA-Z_\\\\]{3,}/', $args_str)) {
            $hits[] = [
                'issue' => $this->issue('critical', 'serialized_object_args', 'Arguments contain serialized PHP object(s)'),
                'score' => 80,
            ];
        }

        // Executable / decoder payload markers (avoid bare "system(" / str_rot13 alone)
        if (preg_match('/base64_decode\s*\(|\beval\s*\(|\bassert\s*\(|<\?php|gzinflate\s*\(|create_function\s*\(|preg_replace\s*\([^)]*\/e|shell_exec\s*\(|passthru\s*\(|proc_open\s*\(/i', $args_str)) {
            $hits[] = [
                'issue' => $this->issue('critical', 'payload_args', 'Arguments look like encoded / executable payload'),
                'score' => 75,
            ];
        }

        // Long base64 blob with decoder context
        if (preg_match('/[A-Za-z0-9+\/]{80,}={0,2}/', $args_str) && preg_match('/base64_decode|gzinflate|eval\s*\(/i', $args_str)) {
            $hits[] = [
                'issue' => $this->issue('critical', 'base64_blob_args', 'Arguments contain a large base64-like blob with decoder context'),
                'score' => 70,
            ];
        } elseif (!$is_trusted && !$is_core && preg_match('/^[A-Za-z0-9+\/]{200,}={0,2}$/', trim($args_str, "[]\"' "))) {
            $hits[] = [
                'issue' => $this->issue('warning', 'long_base64ish_args', 'Arguments are a single long base64-like string'),
                'score' => 25,
            ];
        }

        // Remote URLs — JSON may escape slashes as \/
        // Raw IP is always critical (even on "trusted" prefixes — malware often fakes those).
        // Off-site non-allowlisted hosts always surface; trusted prefixes only reduce score slightly
        // so woocommerce.org/api.wordpress.org stay quiet via allowlist, not via prefix trust alone.
        $url_haystack = str_replace('\\/', '/', $args_str);
        if (preg_match('#https?://#i', $url_haystack)) {
            $site_host = '';
            if (function_exists('home_url')) {
                $parts = parse_url(home_url());
                $site_host = strtolower($parts['host'] ?? '');
            }
            if (preg_match('#https?://(?:\d{1,3}\.){3}\d{1,3}#i', $url_haystack)) {
                $hits[] = [
                    'issue' => $this->issue('critical', 'remote_ip_url_args', 'Arguments contain a URL to a raw IP address'),
                    'score' => 55,
                ];
            } elseif (preg_match('#https?://([^/\'"\s]+)#i', $url_haystack, $m)) {
                $arg_host = strtolower(preg_replace('/:\d+$/', '', $m[1]));
                // Never skip solely because hook is "core" — infected core can still C2.
                // Allowlist (wordpress.org, same-site, known CDNs) keeps legitimate update traffic quiet.
                if (!$this->is_known_legit_remote_host($arg_host, $site_host)) {
                    $hits[] = [
                        'issue' => $this->issue(
                            'warning',
                            'remote_url_args',
                            'Arguments contain a remote URL (off-site host)'
                        ),
                        // Slightly lower weight for trusted/core ecosystem hooks (noise), but never silent
                        'score' => ($is_core || $is_trusted) ? 18 : 25,
                    ];
                }
            }
        }

        // PHP under uploads/ or temp — single finding (avoid double critical with path probe)
        $abspath = defined('ABSPATH') ? str_replace('\\', '/', ABSPATH) : '';
        $args_paths = str_replace('\\/', '/', $args_str);
        $flagged_uploads_php = false;
        if (preg_match('#(?:uploads|wp-content/uploads)/[^\s\'"]+\.php#i', $args_paths)) {
            $hits[] = [
                'issue' => $this->issue('critical', 'dangerous_path_args', 'Arguments reference a PHP file under uploads/'),
                'score' => 75,
            ];
            $flagged_uploads_php = true;
        } elseif (preg_match('#/(?:tmp|var/tmp|dev/shm)/[^\s\'"]+\.php#i', $args_paths)) {
            $hits[] = [
                'issue' => $this->issue('critical', 'dangerous_path_args', 'Arguments reference a PHP file under a temp path'),
                'score' => 70,
            ];
        }

        // Path existence: only add missing-file signal when pattern not already critical above
        if (!$flagged_uploads_php) {
            $paths = $this->extract_path_candidates($args_paths, $abspath);
            foreach (array_slice($paths, 0, 5) as $p) {
                if (stripos($p, 'uploads') === false && stripos($p, 'wp-content') === false) {
                    continue;
                }
                $full = $p;
                if ($abspath && $p[0] !== '/' && !preg_match('#^[A-Za-z]:/#', $p)) {
                    $full = rtrim($abspath, '/') . '/' . ltrim($p, '/');
                }
                $norm = str_replace('\\', '/', $full);
                if (!preg_match('/\.php$/i', $norm) || strpos($norm, '/uploads/') === false) {
                    continue;
                }
                if (!is_file($full)) {
                    $hits[] = [
                        'issue' => $this->issue(
                            'warning',
                            'args_path_missing',
                            'Arguments reference an uploads PHP path that does not exist (dropper may re-create it)'
                        ),
                        'score' => 35,
                    ];
                } else {
                    $hits[] = [
                        'issue' => $this->issue(
                            'critical',
                            'args_php_in_uploads',
                            'Arguments point at a PHP file under uploads/'
                        ),
                        'score' => 70,
                    ];
                }
                break;
            }
        }

        // Opaque token: skip UUIDs; require long continuous base64-ish token
        if (!$is_trusted && !$is_core && is_array($args) && count($args) === 1) {
            $only = reset($args);
            if (is_string($only) && strlen($only) >= 48
                && preg_match('/^[A-Za-z0-9+\/_]{48,}={0,2}$/', $only)
                && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $only)) {
                $hits[] = [
                    'issue' => $this->issue('info', 'opaque_token_args', 'Single long opaque token argument'),
                    'score' => 8,
                ];
            }
        }

        return $hits;
    }

    /**
     * Hosts that commonly appear in legitimate plugin/cron args.
     */
    private function is_known_legit_remote_host($host, $site_host) {
        $host = strtolower((string) $host);
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
            return true;
        }
        if ($site_host && (strpos($host, $site_host) !== false || strpos($site_host, $host) !== false)) {
            return true;
        }
        $suffixes = [
            'wordpress.org',
            'api.wordpress.org',
            'downloads.wordpress.org',
            'woocommerce.com',
            'jetpack.com',
            'automattic.com',
            'gravatar.com',
            'wp.com',
            'googleapis.com',
            'google.com',
            'github.com',
            'githubusercontent.com',
            'cloudflare.com',
        ];
        foreach ($suffixes as $s) {
            if ($host === $s || substr($host, -strlen('.' . $s)) === '.' . $s) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $args_str
     * @param string $abspath
     * @return string[]
     */
    private function extract_path_candidates($args_str, $abspath) {
        $paths = [];
        if (preg_match_all('#(?:[A-Za-z]:)?(?:/[\w.\-]+)+\.php#', $args_str, $m)) {
            foreach ($m[0] as $p) {
                $paths[] = $p;
            }
        }
        if (preg_match_all('#wp-content/[^\s\'"]+\.php#i', $args_str, $m2)) {
            foreach ($m2[0] as $p) {
                $paths[] = $p;
            }
        }
        return array_values(array_unique($paths));
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

    private function args_preview($args, $max = 240) {
        if ($args === null || $args === []) {
            return '';
        }
        $s = @json_encode($args);
        if ($s === false) {
            $s = @serialize($args);
        }
        $s = (string) $s;
        if (strlen($s) > $max) {
            return substr($s, 0, $max) . '…';
        }
        return $s;
    }

    private function audit_action_scheduler() {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return ['available' => false, 'actions' => [], 'note' => 'No database'];
        }
        $table = $wpdb->prefix . 'actionscheduler_actions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            return ['available' => false, 'actions' => [], 'note' => 'Action Scheduler tables not present'];
        }

        // Active + recent completed (completed may hold one-shot malware jobs)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT action_id, hook, status, scheduled_date_gmt, args, schedule, extended_args
             FROM `{$table}`
             WHERE status IN ('pending','in-progress','failed','fail','running')
             ORDER BY scheduled_date_gmt ASC
             LIMIT 200",
            ARRAY_A
        );
        if (!is_array($rows)) {
            $rows = [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $completed = $wpdb->get_results(
            "SELECT action_id, hook, status, scheduled_date_gmt, args, schedule, extended_args
             FROM `{$table}`
             WHERE status IN ('complete','completed')
             ORDER BY scheduled_date_gmt DESC
             LIMIT 50",
            ARRAY_A
        );
        if (is_array($completed)) {
            // Keep completed only if args look interesting (payload-like) to limit noise
            foreach ($completed as $row) {
                $raw = (string) ($row['extended_args'] ?: ($row['args'] ?? ''));
                if ($this->args_look_interesting($raw)) {
                    $rows[] = $row;
                }
            }
        }

        $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $year_ahead = time() + 365 * $day;
        $day_ago = time() - $day;
        $actions = [];
        foreach ($rows as $row) {
            $hook = (string) ($row['hook'] ?? '');
            $args_raw = $row['extended_args'] ?: ($row['args'] ?? '');
            $args = json_decode((string) $args_raw, true);
            if (!is_array($args)) {
                $args = ['_raw' => (string) $args_raw];
            }
            $ts = !empty($row['scheduled_date_gmt']) ? strtotime($row['scheduled_date_gmt'] . ' UTC') : 0;
            $callbacks = $this->callbacks_for_hook($hook);
            $as_status = (string) ($row['status'] ?? '');
            $scored = $this->score_event([
                'hook' => $hook,
                'timestamp' => $ts,
                'schedule' => (string) ($row['schedule'] ?? ''),
                'interval_seconds' => null,
                'args' => $args,
                'callbacks' => $callbacks,
                'source' => 'action_scheduler',
                // AS jobs are not WP-Cron recurring; never infer recurring from schedule blob
                'is_recurring' => false,
            ], $year_ahead, $day_ago);

            // Failed AS actions with hostile hooks/args get a mild boost
            if (in_array($as_status, ['failed', 'fail'], true) && ($scored['score'] ?? 0) >= 20) {
                $scored['issues'][] = $this->issue('info', 'as_failed', 'Action Scheduler status is failed');
                $scored['score'] += 5;
                $scored['status'] = $this->score_to_status($scored['score'], $scored['issues']);
            }

            $actions[] = array_merge($scored, [
                'id' => 'as_' . ($row['action_id'] ?? uniqid()),
                'action_id' => (int) ($row['action_id'] ?? 0),
                'hook' => $hook,
                'as_status' => $as_status,
                'timestamp' => $ts,
                'next_run' => $row['scheduled_date_gmt'] ?? null,
                'schedule' => (string) ($row['schedule'] ?? ''),
                'args_preview' => $this->args_preview($args),
                'callbacks' => $callbacks,
                'source' => 'action_scheduler',
            ]);
        }

        usort($actions, function ($a, $b) {
            $rank = ['critical' => 0, 'warning' => 1, 'info' => 2, 'healthy' => 3];
            return ($rank[$a['status']] ?? 9) - ($rank[$b['status']] ?? 9);
        });

        // Prefer showing non-healthy first; cap healthy noise
        $non_healthy = array_values(array_filter($actions, function ($a) {
            return ($a['status'] ?? '') !== 'healthy';
        }));
        $healthy = array_values(array_filter($actions, function ($a) {
            return ($a['status'] ?? '') === 'healthy';
        }));
        $actions = array_merge($non_healthy, array_slice($healthy, 0, 30));

        return [
            'available' => true,
            'actions' => $actions,
            'total_fetched' => count($rows),
        ];
    }

    private function args_look_interesting($raw) {
        $raw = (string) $raw;
        if (strlen($raw) < 8) {
            return false;
        }
        // Keep tight — "base64" alone and Action Scheduler O: schedule blobs must not pull every complete job
        if (preg_match('/base64_decode|gzinflate|shell_exec|\beval\s*\(|https?:\/\/(?:\d{1,3}\.){3}\d{1,3}|uploads\/.+\.php/i', $raw)) {
            return true;
        }
        if (preg_match('/O:\d+:"[a-zA-Z_\\\\]{3,}/', $raw) && preg_match('/eval|base64_decode|shell_exec/i', $raw)) {
            return true;
        }
        if (strlen($raw) > 400 && preg_match('/[A-Za-z0-9+\/]{120,}/', $raw) && preg_match('/base64_decode|gzinflate/i', $raw)) {
            return true;
        }
        return false;
    }

    private function audit_server_crontab() {
        $result = [
            'available' => false,
            'entries' => [],
            'raw_lines' => 0,
            'note' => null,
        ];
        if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
            $result['note'] = 'shell_exec not available on this host';
            return $result;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true)) {
            $result['note'] = 'shell_exec is disabled';
            return $result;
        }

        $out = @shell_exec('crontab -l 2>/dev/null');
        if ($out === null || $out === false || trim((string) $out) === '') {
            $result['note'] = 'No crontab for web user (or empty)';
            $result['available'] = true;
            return $result;
        }
        $result['available'] = true;
        $lines = preg_split('/\r\n|\r|\n/', (string) $out);
        $result['raw_lines'] = count($lines);
        $abspath = defined('ABSPATH') ? str_replace('\\', '/', ABSPATH) : '';
        $site_host = '';
        if (function_exists('home_url')) {
            $parts = parse_url(home_url());
            $site_host = strtolower($parts['host'] ?? '');
        }

        $suspicious = [];
        $benign_count = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $lower = strtolower($line);
            $assessment = $this->assess_crontab_line($line, $lower, $abspath, $site_host);
            if ($assessment['status'] === 'healthy' || $assessment['status'] === 'info') {
                $benign_count++;
                continue; // do not list pure-benign wp-cron lines as findings
            }
            if ($assessment['status'] === 'skip') {
                continue;
            }
            $suspicious[] = [
                'line' => function_exists('mb_substr') ? mb_substr($line, 0, 300) : substr($line, 0, 300),
                'reasons' => $assessment['reasons'],
                'status' => $assessment['status'],
            ];
        }
        $result['entries'] = $suspicious;
        if (empty($suspicious)) {
            $result['note'] = $benign_count > 0
                ? "No suspicious crontab lines ({$benign_count} benign line(s) including legitimate wp-cron patterns)."
                : 'No suspicious lines found for this user.';
        }
        return $result;
    }

    /**
     * Classify a single crontab line.
     *
     * @return array{status: string, reasons: string[]}
     */
    private function assess_crontab_line($line, $lower, $abspath, $site_host) {
        $reasons = [];
        $status = 'skip';

        // High-risk: pipe to shell interpreters, reverse shells, encoded payloads
        if (preg_match('/\b(bash|sh|dash|zsh)\s+-c\b/', $lower)
            || preg_match('/\|\s*(ba)?sh\b/', $lower)
            || preg_match('/\b(python|perl|ruby|php)\s+-c\b|python3?\s+-e\b|perl\s+-e\b/', $lower)) {
            $reasons[] = 'Executes inline interpreter (-c / pipe to shell)';
            $status = 'critical';
        }
        if (preg_match('/\bbase64\b.*(-d|--decode)|eval\s*\(|\bnc\b.*-e|\/dev\/tcp\/|mkfifo\b|reverse.?shell/i', $lower)) {
            $reasons[] = 'Encoded payload / reverse-shell indicators';
            $status = 'critical';
        }
        if (preg_match('/\b(wget|curl)\b.*\|\s*(ba)?sh\b/', $lower)
            || preg_match('/\b(wget|curl)\b.*>\s*\/tmp\//', $lower)
            || preg_match('/\b(wget|curl)\b.*-o\s*[^\s]+\.php/', $lower)) {
            $reasons[] = 'Download piped to shell or written as PHP/temp';
            $status = 'critical';
        }

        // wget/curl to external hosts (not this site, not plain wp-cron trigger)
        $is_legit_wpcron = $this->is_legitimate_wpcron_line($lower, $abspath, $site_host);
        if ($is_legit_wpcron && $status === 'skip') {
            return ['status' => 'healthy', 'reasons' => ['Legitimate wp-cron trigger']];
        }

        if (preg_match('/\b(wget|curl)\b/', $lower) && !$is_legit_wpcron) {
            // External URL?
            if (preg_match('#https?://([^\s/\'"]+)#i', $line, $m)) {
                $host = strtolower($m[1]);
                $host = preg_replace('/:\d+$/', '', $host);
                if ($site_host && (strpos($host, $site_host) !== false || strpos($site_host, $host) !== false)) {
                    // Same site but not the clean wp-cron pattern
                    $reasons[] = 'HTTP client hits this site (non-standard path)';
                    $status = $status === 'critical' ? 'critical' : 'warning';
                } elseif (preg_match('/^(?:\d{1,3}\.){3}\d{1,3}$/', $host)) {
                    $reasons[] = 'HTTP client to raw IP';
                    $status = 'critical';
                } else {
                    $reasons[] = 'HTTP client to external host: ' . $host;
                    $status = $status === 'critical' ? 'critical' : 'warning';
                }
            } else {
                $reasons[] = 'Uses wget/curl';
                $status = $status === 'critical' ? 'critical' : 'warning';
            }
        }

        // PHP executing non-wp-cron scripts under the site, especially uploads
        if (preg_match('/\bphp\b/', $lower) && !$is_legit_wpcron) {
            if (preg_match('#uploads/.*\.php#i', $line) || preg_match('#/tmp/.*\.php#i', $line)) {
                $reasons[] = 'PHP runs a script under uploads/ or /tmp';
                $status = 'critical';
            } elseif ($abspath && strpos($line, $abspath) !== false) {
                $reasons[] = 'PHP targets a site path (not standard wp-cron.php)';
                $status = $status === 'critical' ? 'critical' : 'warning';
            } elseif (preg_match('/\bphp\b.*\.php\b/', $lower)) {
                $reasons[] = 'PHP CLI runs a script';
                $status = $status === 'critical' ? 'critical' : 'warning';
            }
        }

        // Site path reference without being legit wp-cron
        if ($abspath && strpos($line, $abspath) !== false && !$is_legit_wpcron && $status === 'skip') {
            $reasons[] = 'References site path';
            $status = 'warning';
        }

        if ($status === 'skip') {
            // Completely unrelated cron lines — ignore
            return ['status' => 'skip', 'reasons' => []];
        }

        return ['status' => $status, 'reasons' => array_values(array_unique($reasons))];
    }

    /**
     * True for normal host patterns that trigger WordPress cron.
     */
    private function is_legitimate_wpcron_line($lower, $abspath, $site_host) {
        // php /path/to/wp-cron.php or php -q .../wp-cron.php
        if (preg_match('/\bphp\b.*wp-cron\.php/', $lower)) {
            return true;
        }
        // wget/curl https://site/wp-cron.php (optional query args)
        if (preg_match('/\b(wget|curl)\b.*wp-cron\.php/', $lower)) {
            return true;
        }
        // wp cli cron event run
        if (preg_match('/\bwp\b.*\bcron\b/', $lower)) {
            return true;
        }
        return false;
    }

    private function audit_transient_hooks() {
        global $wp_filter;
        if (!isset($wp_filter) || (!is_array($wp_filter) && !is_object($wp_filter))) {
            return [];
        }
        $out = [];
        $keys = is_array($wp_filter) ? array_keys($wp_filter) : array_keys((array) $wp_filter);
        foreach ($keys as $hook) {
            $hook = (string) $hook;
            if (strpos($hook, 'transient') === false) {
                continue;
            }
            if (!isset($wp_filter[$hook])) {
                continue;
            }
            $cbs = $this->callbacks_for_hook($hook);
            foreach ($cbs as $cb) {
                // Only surface critical paths for transient hooks (mu-plugin warnings are noise)
                if (($cb['origin_risk'] ?? '') === 'critical') {
                    $out[] = [
                        'hook' => $hook,
                        'callback' => $cb['label'],
                        'file' => $cb['file'],
                        'origin_kind' => $cb['origin_kind'],
                        'status' => 'critical',
                        'issues' => [[
                            'severity' => 'critical',
                            'code' => 'transient_hook_path',
                            'message' => $cb['origin_reason'] ?? 'Dangerous transient-related callback path',
                        ]],
                    ];
                }
            }
        }
        return array_slice($out, 0, 50);
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
            if ($sev === 'warning' || $sev === 'high') {
                $has_warn = true;
            }
        }
        // Do not escalate to critical on score alone without a critical issue —
        // stacked soft warnings (orphan + high_frequency + overdue) were over-firing.
        if ($score >= 70 && $has_warn) {
            return 'critical';
        }
        if ($score >= 18 || $has_warn) {
            return 'warning';
        }
        if ($score > 0 || !empty($issues)) {
            return empty($issues) ? 'healthy' : 'info';
        }
        return 'healthy';
    }

    /**
     * Unschedule a single WP-Cron event.
     */
    public function delete_wp_cron_event($hook, $timestamp, $sig = null) {
        $hook = (string) $hook;
        $timestamp = (int) $timestamp;
        if ($hook === '' || $timestamp <= 0) {
            return ['success' => false, 'error' => 'hook and timestamp required'];
        }
        if (in_array($hook, self::$CORE_HOOKS, true)) {
            // Allow but caller should confirm — we still allow with success note
        }
        $crons = _get_cron_array();
        if (!isset($crons[$timestamp][$hook])) {
            return ['success' => false, 'error' => 'Event not found'];
        }
        if ($sig !== null && $sig !== '' && isset($crons[$timestamp][$hook][$sig])) {
            $args = $crons[$timestamp][$hook][$sig]['args'] ?? [];
            wp_unschedule_event($timestamp, $hook, $args);
        } else {
            // Remove all at this timestamp for hook
            foreach ($crons[$timestamp][$hook] as $entry) {
                $args = $entry['args'] ?? [];
                wp_unschedule_event($timestamp, $hook, $args);
            }
        }
        return ['success' => true, 'hook' => $hook, 'timestamp' => $timestamp];
    }

    /**
     * Clear all scheduled events for a hook.
     */
    public function clear_hook($hook) {
        $hook = (string) $hook;
        if ($hook === '') {
            return ['success' => false, 'error' => 'hook required'];
        }
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook($hook);
        } else {
            wp_clear_scheduled_hook($hook);
        }
        return ['success' => true, 'hook' => $hook];
    }

    /**
     * Cancel Action Scheduler action by id if API available.
     */
    public function cancel_as_action($action_id) {
        $action_id = (int) $action_id;
        if ($action_id <= 0) {
            return ['success' => false, 'error' => 'Invalid action_id'];
        }
        if (function_exists('as_unschedule_action') && class_exists('ActionScheduler')) {
            // Prefer store cancel
        }
        if (class_exists('ActionScheduler') && method_exists('ActionScheduler', 'store')) {
            try {
                ActionScheduler::store()->cancel_action($action_id);
                return ['success' => true, 'action_id' => $action_id];
            } catch (Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }
        global $wpdb;
        $table = $wpdb->prefix . 'actionscheduler_actions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->update($table, ['status' => 'canceled'], ['action_id' => $action_id]);
        if ($updated === false) {
            return ['success' => false, 'error' => 'Failed to cancel Action Scheduler row'];
        }
        return ['success' => true, 'action_id' => $action_id];
    }
}

} // class_exists
