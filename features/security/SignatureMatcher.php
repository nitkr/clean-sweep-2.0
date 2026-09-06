<?php
/**
 * Clean Sweep - Shared Signature Matcher
 *
 * Single match/enrich/order path used by FileScanner and DatabaseScanner
 * so file and DB detection cannot drift.
 */

require_once __DIR__ . '/seo-keywords/CleanSweep_SeoKeywordCatalog.php';

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
     * @param int $start_offset Byte offset to start searching (not a substr; ^ still means start of $content)
     * @param callable|null $on_preg_error function(int $index, string $pattern, int $code): void
     * @return array<int, array{index:int,pattern:string,match:string,offset:int,meta:array}>
     */
    public static function match_content($content, array $signatures, $on_tick = null, $start_offset = 0, $on_preg_error = null) {
        $hits = [];
        $n = 0;
        $start_offset = (int) $start_offset;
        if ($start_offset < 0) {
            $start_offset = 0;
        }

        $seo_needles_hit = null;

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

            if (self::is_gated($index)) {
                if ($seo_needles_hit === null) {
                    $seo_needles_hit = self::seo_gate_needles_present($content);
                }
                if ($seo_needles_hit === false) {
                    continue;
                }
            }

            if (@preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE, $start_offset) === false) {
                if (is_callable($on_preg_error)) {
                    $on_preg_error($index, $pattern, (int) preg_last_error());
                }
                continue;
            }
            if (!isset($matches[0][0]) || $matches[0][0] === '') {
                continue;
            }

            $match_text = $matches[0][0];
            $match_offset = (int) $matches[0][1];

            $meta = self::enrich($index, $pattern);
            // Re-score with matched content for heuristic fallback path
            $meta['risk_score'] = CleanSweep_ThreatCollector::resolve_risk_score(
                $pattern,
                $match_text,
                $meta['severity']
            );
            $meta['threat_level'] = CleanSweep_ThreatCollector::risk_score_to_level($meta['risk_score']);

            $hits[] = [
                'index' => $index,
                'pattern' => $pattern,
                'match' => $match_text,
                'offset' => $match_offset,
                'meta' => $meta,
            ];
        }

        return $hits;
    }

    /**
     * Pack `gated` flag. Old packs without the field fall back to SEO family
     * except cs_0374 (inject-shape, no catalog keywords).
     *
     * @param int $index
     * @return bool
     */
    private static function is_gated($index) {
        $mgr = self::manager();
        if (!$mgr) {
            return false;
        }
        if (method_exists($mgr, 'is_gated')) {
            return $mgr->is_gated($index);
        }
        $id = method_exists($mgr, 'get_signature_id') ? (string) $mgr->get_signature_id($index) : '';
        if ($id === 'cs_0374') {
            return false;
        }
        $family = method_exists($mgr, 'get_family') ? (string) $mgr->get_family($index) : '';
        return $family === 'seo_spam' || $family === 'seo_spam_inject';
    }

    /**
     * Whole-word gate (alnum lookaround, not PCRE \b). Prefer pack needles[]
     * so the sealed regexes and the gate cannot drift.
     *
     * @param string $content
     * @return bool
     */
    private static function seo_gate_needles_present($content) {
        $tokens = [];
        $mgr = self::manager();
        if ($mgr && method_exists($mgr, 'get_needles')) {
            $tokens = $mgr->get_needles('seo');
        }
        $rx = $tokens !== []
            ? CleanSweep_SeoKeywordCatalog::needle_regex_for($tokens)
            : CleanSweep_SeoKeywordCatalog::needle_regex();
        $hit = @preg_match($rx, (string) $content);
        return $hit === 1;
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
