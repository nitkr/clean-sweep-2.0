<?php
/**
 * Clean Sweep - Vulnerability Scan API
 *
 * Separate from malware scanning (Phase 2 / CleanSweep_Scanner UX).
 * Network-bound WPVulnerability.com checks for core, plugins, themes.
 *
 * Actions:
 *   - scan                    Run a full vulnerability check (synchronous, usually <60s)
 *   - latest_vulnerabilities  Restore last UI payload after refresh (48h TTL)
 *
 * @since CleanSweep_Scanner UI split
 */

require_once __DIR__ . '/bootstrap.php';
require_once CLEAN_SWEEP_ROOT . 'includes/ApiResponse.php';
require_once CLEAN_SWEEP_ROOT . 'features/security/vulnerability-scanner.php';

// Note: bootstrap.php only routes when the request target is bootstrap.php itself.
// Direct hits to this file run the local switch after bootstrap returns.

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'scan':
    case 'scan_vulnerabilities': // alias used by older clients / malware.php
        clean_sweep_handle_vuln_scan();
        break;
    case 'latest':
    case 'latest_vulnerabilities':
        clean_sweep_handle_vuln_latest();
        break;
    default:
        CleanSweep_ApiResponse::sendError('Unknown action: ' . $action . ' (use action=scan or latest_vulnerabilities)', 'UNKNOWN_ACTION');
}

/**
 * Run full vulnerability scan and return a UI-friendly payload.
 */
function clean_sweep_handle_vuln_scan() {
    @set_time_limit(120);
    @ignore_user_abort(true);

    try {
        $scanner = new CleanSweep_VulnerabilityScanner();
        $raw = $scanner->scan_all(null);

        $flat = [];

        // Core
        if (!empty($raw['core']['vulnerabilities']) && is_array($raw['core']['vulnerabilities'])) {
            foreach ($raw['core']['vulnerabilities'] as $v) {
                $flat[] = clean_sweep_normalize_vuln_item(
                    $v,
                    'core',
                    'WordPress Core',
                    $raw['core']['version'] ?? '',
                    null,
                    'https://wordpress.org/download/'
                );
            }
        }

        // Plugins
        if (!empty($raw['plugins']) && is_array($raw['plugins'])) {
            foreach ($raw['plugins'] as $plugin) {
                $slug = $plugin['slug'] ?? null;
                $name = $plugin['name'] ?? $slug ?? 'Plugin';
                $ver = $plugin['version'] ?? '';
                $link = !empty($slug)
                    ? 'https://wordpress.org/plugins/' . rawurlencode($slug) . '/'
                    : null;
                if (empty($plugin['vulnerabilities']) || !is_array($plugin['vulnerabilities'])) {
                    continue;
                }
                foreach ($plugin['vulnerabilities'] as $v) {
                    $flat[] = clean_sweep_normalize_vuln_item($v, 'plugin', $name, $ver, $slug, $link);
                }
            }
        }

        // Themes
        if (!empty($raw['themes']) && is_array($raw['themes'])) {
            foreach ($raw['themes'] as $theme) {
                $slug = $theme['slug'] ?? null;
                $name = $theme['name'] ?? $slug ?? 'Theme';
                $ver = $theme['version'] ?? '';
                $link = !empty($slug)
                    ? 'https://wordpress.org/themes/' . rawurlencode($slug) . '/'
                    : null;
                if (empty($theme['vulnerabilities']) || !is_array($theme['vulnerabilities'])) {
                    continue;
                }
                foreach ($theme['vulnerabilities'] as $v) {
                    $flat[] = clean_sweep_normalize_vuln_item($v, 'theme', $name, $ver, $slug, $link);
                }
            }
        }

        $summary = $raw['summary'] ?? [
            'core_vulnerabilities' => 0,
            'plugin_vulnerabilities' => 0,
            'theme_vulnerabilities' => 0,
            'total' => count($flat),
        ];
        if (!isset($summary['total'])) {
            $summary['total'] = count($flat);
        }

        // Group by component for the UI (plugin/theme/core)
        $groups = clean_sweep_group_vulns_by_component($flat);
        $scanned_at = time();
        $ui = [
            'summary' => $summary,
            'vulnerabilities' => $flat,
            'groups' => $groups,
            'scanned_at' => $scanned_at,
        ];

        try {
            if (!class_exists('CleanSweep_VisitSignals')
                && is_readable(CLEAN_SWEEP_ROOT . 'includes/system/visit/VisitSignals.php')) {
                require_once CLEAN_SWEEP_ROOT . 'includes/system/visit/VisitSignals.php';
            }
            if (class_exists('CleanSweep_VisitSignals')) {
                CleanSweep_VisitSignals::persist_vulns($flat, $ui);
            }
        } catch (Throwable $persist_error) {
            clean_sweep_log_message(
                'Vulnerability scan persist skipped: ' . $persist_error->getMessage(),
                'debug'
            );
        }

        CleanSweep_ApiResponse::sendSuccess($ui + [
            'core' => $raw['core'] ?? null,
            'plugins' => $raw['plugins'] ?? [],
            'themes' => $raw['themes'] ?? [],
        ]);
    } catch (Throwable $e) {
        clean_sweep_log_message('Vulnerability scan failed: ' . $e->getMessage(), 'error');
        CleanSweep_ApiResponse::sendError(
            'Vulnerability scan failed: ' . $e->getMessage(),
            'VULN_SCAN_FAILED'
        );
    }
}

/**
 * Return last persisted vulnerability UI payload (dashboard / scanner restore).
 */
function clean_sweep_handle_vuln_latest() {
    $ttl = (int) ($_POST['completed_ttl_seconds'] ?? $_GET['completed_ttl_seconds'] ?? 172800);
    if ($ttl < 3600) {
        $ttl = 3600;
    }
    if ($ttl > 7 * 86400) {
        $ttl = 7 * 86400;
    }

    try {
        if (!class_exists('CleanSweep_VisitSignals')
            && is_readable(CLEAN_SWEEP_ROOT . 'includes/system/visit/VisitSignals.php')) {
            require_once CLEAN_SWEEP_ROOT . 'includes/system/visit/VisitSignals.php';
        }
        if (!class_exists('CleanSweep_VisitSignals')) {
            CleanSweep_ApiResponse::sendSuccess(['results' => null]);
            return;
        }
        $ui = CleanSweep_VisitSignals::latest_ui($ttl);
        if ($ui === null) {
            CleanSweep_ApiResponse::sendSuccess(['results' => null]);
            return;
        }
        CleanSweep_ApiResponse::sendSuccess($ui);
    } catch (Throwable $e) {
        clean_sweep_log_message('Vulnerability latest restore failed: ' . $e->getMessage(), 'debug');
        CleanSweep_ApiResponse::sendSuccess(['results' => null]);
    }
}

/**
 * Decode HTML entities commonly returned by WPVulnerability (e.g. &#8211;, &amp;).
 */
function clean_sweep_vuln_decode_text($text) {
    if (!is_string($text) || $text === '') {
        return '';
    }
    // Double-decode handles &amp;amp; style chains occasionally seen in feeds
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim($decoded);
}

/**
 * Classify a source id for UI: cve | euvd | advisory | other
 */
function clean_sweep_vuln_source_kind(string $id): string {
    $id = trim($id);
    if ($id === '') {
        return 'other';
    }
    if (preg_match('/^CVE-\d{4}-\d+/i', $id)) {
        return 'cve';
    }
    if (preg_match('/^EUVD-\d+/i', $id)) {
        return 'euvd';
    }
    // Long hex hashes from Patchstack / Wordfence — not user-facing chips
    if (preg_match('/^[a-f0-9]{32,}$/i', $id)) {
        return 'hash';
    }
    // UUID-ish
    if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-/i', $id)) {
        return 'hash';
    }
    return 'advisory';
}

/**
 * Normalize a single vulnerability for the CleanSweep_Scanner UI list.
 */
function clean_sweep_normalize_vuln_item(
    array $v,
    string $target_type,
    string $target_name,
    string $target_version,
    $slug = null,
    $package_link = null
): array {
    $uuid = $v['uuid'] ?? $v['id'] ?? md5(
        $target_type . '|' . $target_name . '|' . ($v['name'] ?? '') . '|' . json_encode($v['source'] ?? [])
    );

    $risk = $v['risk_level'] ?? $v['severity'] ?? null;
    $cvss_score = null;
    $cvss_vector = null;
    $cwes = [];

    if (!empty($v['impact'][0]['cvss']['severity'])) {
        $risk = $risk ?: strtolower((string)$v['impact'][0]['cvss']['severity']);
    }
    if (isset($v['impact'][0]['cvss']['score'])) {
        $cvss_score = (float)$v['impact'][0]['cvss']['score'];
        if (!$risk) {
            if ($cvss_score >= 9.0) $risk = 'critical';
            elseif ($cvss_score >= 7.0) $risk = 'high';
            elseif ($cvss_score >= 4.0) $risk = 'medium';
            elseif ($cvss_score > 0) $risk = 'low';
            else $risk = 'info';
        }
    }
    if (!$risk) {
        $risk = 'medium';
    }
    if (!empty($v['impact'][0]['cvss']['vector'])) {
        $cvss_vector = (string)$v['impact'][0]['cvss']['vector'];
    }
    if (!empty($v['impact'][0]['cwe']) && is_array($v['impact'][0]['cwe'])) {
        foreach ($v['impact'][0]['cwe'] as $cwe) {
            $cwes[] = [
                'id' => $cwe['cwe'] ?? '',
                'name' => clean_sweep_vuln_decode_text($cwe['name'] ?? ''),
                'description' => clean_sweep_vuln_decode_text($cwe['description'] ?? ''),
            ];
        }
    }

    // Normalize sources + split primary (CVE) vs secondary
    $sources = [];
    $primary_cves = [];
    $other_advisories = [];
    $summary = clean_sweep_vuln_decode_text($v['description'] ?? '');

    if (!empty($v['source']) && is_array($v['source'])) {
        foreach ($v['source'] as $src) {
            $id = (string)($src['id'] ?? '');
            $entry = [
                'id' => $id,
                'name' => clean_sweep_vuln_decode_text($src['name'] ?? ''),
                'link' => $src['link'] ?? '',
                'description' => clean_sweep_vuln_decode_text($src['description'] ?? ''),
                'date' => $src['date'] ?? '',
                'kind' => clean_sweep_vuln_source_kind($id),
            ];
            $sources[] = $entry;

            if ($entry['kind'] === 'cve' || $entry['kind'] === 'euvd') {
                $primary_cves[] = $entry;
            } else {
                $other_advisories[] = $entry;
            }

            // Best available human summary for collapsed + expanded views
            if ($summary === '' && $entry['description'] !== '') {
                $summary = $entry['description'];
            }
        }
    }

    // Short title: prefer first CVE name without the long plugin prefix when possible
    $name = clean_sweep_vuln_decode_text($v['name'] ?? $v['title'] ?? 'Known vulnerability');
    $short_title = $name;
    if (!empty($primary_cves[0]['name'])) {
        $short_title = $primary_cves[0]['name'];
    } elseif (!empty($other_advisories[0]['name'])) {
        $short_title = $other_advisories[0]['name'];
    }

    $fixed = $v['fixed_version'] ?? null;
    $affected = clean_sweep_vuln_decode_text($v['affected_version'] ?? '');
    $unfixed = !empty($v['unfixed']);

    $remediation = null;
    if ($unfixed) {
        $remediation = 'No patched release is listed yet. Consider disabling the component or applying a vendor workaround until a fix ships.';
    } elseif ($fixed) {
        $remediation = 'Update to version ' . $fixed . ' or newer (if available for your site).';
    } elseif ($affected !== '') {
        $remediation = 'Update past the affected range: ' . $affected . '.';
    } else {
        $remediation = 'Update this component to the latest available version.';
    }

    return [
        'uuid' => (string)$uuid,
        'name' => $name,
        'short_title' => $short_title,
        'description' => $summary,
        'risk_level' => $risk,
        'target_type' => $target_type,
        'target_name' => clean_sweep_vuln_decode_text($target_name ?: ($slug ?: $target_type)),
        'target_version' => $target_version,
        'target_slug' => $slug,
        'package_link' => $package_link,
        'affected_version' => $affected,
        'fixed_version' => $fixed,
        'unfixed' => $unfixed,
        'operator' => $v['operator'] ?? null,
        'source' => $sources,
        'primary_cves' => $primary_cves,
        'other_advisories' => $other_advisories,
        'impact' => $v['impact'] ?? [],
        'cvss_score' => $cvss_score,
        'cvss_vector' => $cvss_vector,
        'cwes' => $cwes,
        'remediation' => $remediation,
        // Group key for UI
        'group_key' => $target_type . '|' . ($slug ?: $target_name) . '|' . $target_version,
    ];
}

/**
 * Group flat findings by component for cleaner UI.
 *
 * @param array $flat
 * @return array
 */
function clean_sweep_group_vulns_by_component(array $flat): array {
    $map = [];
    foreach ($flat as $item) {
        $key = $item['group_key'] ?? ($item['target_type'] . '|' . ($item['target_slug'] ?? $item['target_name']));
        if (!isset($map[$key])) {
            $map[$key] = [
                'group_key' => $key,
                'target_type' => $item['target_type'],
                'target_name' => $item['target_name'],
                'target_version' => $item['target_version'],
                'target_slug' => $item['target_slug'] ?? null,
                'package_link' => $item['package_link'] ?? null,
                'issue_count' => 0,
                'highest_risk' => 'info',
                'best_fixed_version' => null,
                'vulnerabilities' => [],
            ];
        }
        $map[$key]['vulnerabilities'][] = $item;
        $map[$key]['issue_count']++;

        // Track highest severity in group
        $rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'info' => 0];
        $cur = $rank[$map[$key]['highest_risk']] ?? 0;
        $next = $rank[$item['risk_level']] ?? 0;
        if ($next > $cur) {
            $map[$key]['highest_risk'] = $item['risk_level'];
        }

        // Prefer the highest fixed_version string when comparable
        if (!empty($item['fixed_version'])) {
            $prev = $map[$key]['best_fixed_version'];
            if ($prev === null || version_compare((string)$item['fixed_version'], (string)$prev, '>')) {
                $map[$key]['best_fixed_version'] = $item['fixed_version'];
            }
        }
    }

    // Sort groups: critical first, then by issue count
    $groups = array_values($map);
    usort($groups, function ($a, $b) {
        $rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'info' => 0];
        $ra = $rank[$a['highest_risk']] ?? 0;
        $rb = $rank[$b['highest_risk']] ?? 0;
        if ($ra !== $rb) {
            return $rb - $ra;
        }
        return ($b['issue_count'] ?? 0) - ($a['issue_count'] ?? 0);
    });

    return $groups;
}
