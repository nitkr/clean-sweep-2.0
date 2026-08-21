<?php
/**
 * Clean Sweep - Shared Signature Matcher
 *
 * Single match/enrich/order path used by FileScanner and DatabaseScanner
 * so file and DB detection cannot drift.
 */

class CleanSweep_SignatureMatcher {

    /** @var array<string,int> */
    private static $severity_rank = [
        'critical' => 0,
        'high' => 1,
        'medium' => 2,
        'low' => 3,
    ];

    /**
     * Order compiled signature items by pack severity (critical → low).
     *
     * @param array $signatures List of ['index'=>int,'pattern'=>string] (or legacy strings)
     * @return array
     */
    public static function order_by_severity(array $signatures) {
        if ($signatures === []) {
            return $signatures;
        }
        $mgr = self::manager();
        if (!$mgr || !method_exists($mgr, 'get_severity')) {
            return $signatures;
        }

        $decorated = [];
        foreach ($signatures as $key => $item) {
            if (is_array($item)) {
                $index = (int) ($item['index'] ?? $key);
            } else {
                $index = is_int($key) ? $key : 0;
            }
            $sev = strtolower((string) $mgr->get_severity($index));
            $decorated[] = [
                'rank' => self::$severity_rank[$sev] ?? 2,
                'index' => $index,
                'key' => $key,
                'item' => $item,
            ];
        }

        usort($decorated, function ($a, $b) {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }
            return $a['index'] <=> $b['index'];
        });

        $out = [];
        foreach ($decorated as $row) {
            $out[$row['key']] = $row['item'];
        }
        return $out;
    }

    /**
     * Public metadata for a signature index (never exposes raw regex as id).
     *
     * @param int $index
     * @param string $pattern Fallback pattern for heuristic category
     * @return array{id:string,category:string,severity:?string,family:string,risk_score:int,threat_level:string}
     */
    public static function enrich(int $index, $pattern = '') {
        $id = 'sig_' . $index;
        $category = CleanSweep_SignaturePreFilter::guess_category_for_pattern((string) $pattern);
        $severity = null;
        $family = '';

        $mgr = self::manager();
        if ($mgr) {
            if (method_exists($mgr, 'get_signature_id')) {
                $id = (string) $mgr->get_signature_id($index);
            }
            if (method_exists($mgr, 'get_category')) {
                $cat = (string) $mgr->get_category($index);
                if ($cat !== '') {
                    $category = $cat;
                }
            }
            if (method_exists($mgr, 'get_severity')) {
                $severity = $mgr->get_severity($index);
            }
            if (method_exists($mgr, 'get_family')) {
                $family = (string) $mgr->get_family($index);
            }
        }

        $risk_score = CleanSweep_ThreatCollector::resolve_risk_score((string) $pattern, '', $severity);
        $threat_level = CleanSweep_ThreatCollector::risk_score_to_level($risk_score);

        return [
            'id' => $id,
            'category' => $category,
            'severity' => $severity,
            'family' => $family,
            'risk_score' => $risk_score,
            'threat_level' => $threat_level,
        ];
    }

    /**
     * Run compiled signatures against content.
     *
     * @param string $content
     * @param array $signatures Compiled list (ordered by caller if desired)
     * @param callable|null $on_tick function(int $n): bool  return true to pause/stop
     * @return array<int, array{index:int,pattern:string,match:string,meta:array}>
     */
    public static function match_content($content, array $signatures, $on_tick = null) {
        $hits = [];
        $n = 0;

        foreach ($signatures as $key => $item) {
            if (is_array($item)) {
                $index = (int) ($item['index'] ?? $key);
                $pattern = (string) ($item['pattern'] ?? '');
            } else {
                $index = is_int($key) ? $key : $n;
                $pattern = (string) $item;
            }
            if ($pattern === '') {
                continue;
            }

            if ($on_tick) {
                $n++;
                if ($on_tick($n) === true) {
                    break;
                }
            }

            if (@preg_match($pattern, $content, $matches) === false) {
                continue;
            }
            if (empty($matches[0])) {
                continue;
            }

            $meta = self::enrich($index, $pattern);
            // Re-score with matched content for heuristic fallback path
            $meta['risk_score'] = CleanSweep_ThreatCollector::resolve_risk_score(
                $pattern,
                $matches[0],
                $meta['severity']
            );
            $meta['threat_level'] = CleanSweep_ThreatCollector::risk_score_to_level($meta['risk_score']);

            $hits[] = [
                'index' => $index,
                'pattern' => $pattern,
                'match' => $matches[0],
                'meta' => $meta,
            ];
        }

        return $hits;
    }

    /**
     * @return object|null
     */
    private static function manager() {
        if (!function_exists('clean_sweep_get_malware_signatures')) {
            return null;
        }
        return clean_sweep_get_malware_signatures();
    }
}
