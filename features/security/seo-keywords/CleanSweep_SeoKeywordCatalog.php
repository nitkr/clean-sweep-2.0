<?php
/**
 * Merge regional SEO-spam token files, enforce the needle cap, and expose
 * regex fragments for signatures_build.php and SignatureMatcher.
 *
 * Always loads every region (Turkish brands appear on English-language sites).
 */

class CleanSweep_SeoKeywordCatalog {

    const NEEDLE_CAP = 50;

    /** @var array<string,string[]>|null */
    private static $loaded = null;

    /**
     * @return array{core:string[],brands:string[],generic:string[],hide_css:string[]}
     */
    public static function load() {
        if (self::$loaded !== null) {
            return self::$loaded;
        }

        $dir = __DIR__;
        $files = [
            'seo-kw-core.php',
            'seo-kw-brands-global.php',
            'seo-kw-brands-id.php',
            'seo-kw-brands-tr.php',
            'seo-kw-brands-ja.php',
        ];

        $core = [];
        $brands = [];
        $generic = [];
        $hide_css = [];

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (!is_readable($path)) {
                throw new RuntimeException("SEO keyword file missing: {$file}");
            }
            $chunk = require $path;
            if (!is_array($chunk)) {
                throw new RuntimeException("SEO keyword file must return an array: {$file}");
            }
            foreach (['core', 'brands', 'generic', 'hide_css'] as $key) {
                if (empty($chunk[$key]) || !is_array($chunk[$key])) {
                    continue;
                }
                foreach ($chunk[$key] as $token) {
                    $token = (string) $token;
                    if ($token === '') {
                        continue;
                    }
                    if ($key === 'core') {
                        $core[] = $token;
                    } elseif ($key === 'brands') {
                        $brands[] = $token;
                    } elseif ($key === 'generic') {
                        $generic[] = $token;
                    } else {
                        $hide_css[] = $token;
                    }
                }
            }
        }

        $core = self::unique_ci($core);
        $brands = self::unique_ci($brands);
        $generic = self::unique_ci($generic);

        $needle_count = count($core) + count($brands);
        if ($needle_count > self::NEEDLE_CAP) {
            throw new RuntimeException(
                "SEO keyword catalog exceeds cap: {$needle_count} core+brands (max " . self::NEEDLE_CAP . ')'
            );
        }

        self::$loaded = [
            'core' => $core,
            'brands' => $brands,
            'generic' => $generic,
            'hide_css' => $hide_css,
        ];
        return self::$loaded;
    }

    /**
     * Core + brands (counts toward cap). Used for doorway slugs.
     *
     * @return string[]
     */
    public static function needles() {
        $d = self::load();
        return array_merge($d['core'], $d['brands']);
    }

    /**
     * Core + brands + generic. Used for the matcher gate and cs_0264.
     *
     * @return string[]
     */
    public static function gate_needles() {
        $d = self::load();
        return array_merge($d['core'], $d['brands'], $d['generic']);
    }

    /**
     * @return string[]
     */
    public static function generic() {
        return self::load()['generic'];
    }

    /**
     * @return string[]
     */
    public static function hide_css() {
        return self::load()['hide_css'];
    }

    /**
     * Expanded gate tokens (spaced brands also emit hyphen/underscore forms).
     *
     * @return string[]
     */
    public static function gate_needle_tokens() {
        $tokens = [];
        foreach (self::gate_needles() as $t) {
            $tokens[] = $t;
            if (strpos($t, ' ') !== false) {
                $tokens[] = str_replace(' ', '-', $t);
                $tokens[] = str_replace(' ', '_', $t);
            }
        }
        return self::unique_ci($tokens);
    }

    /**
     * Whole-word gate regex from a token list (pack needles or live catalog).
     *
     * @param string[] $tokens
     * @return string
     */
    public static function needle_regex_for(array $tokens) {
        $alt = self::alternation(self::unique_ci($tokens));
        return '/(?<![A-Za-z0-9])(?:' . $alt . ')(?![A-Za-z0-9])/i';
    }

    public static function needle_regex() {
        return self::needle_regex_for(self::gate_needle_tokens());
    }

    /**
     * Present a WP slug as a `/segment/` so cs_0396 can see a bare keyword
     * (`casino`) inside a concatenated title+content haystack without treating
     * spaces in body copy as delimiters (`casino night` stays clean).
     *
     * @param string $slug
     * @return string
     */
    public static function wrap_slug_segment($slug) {
        $slug = trim((string) $slug, "/ \t\n\r\0\x0B");
        if ($slug === '') {
            return '';
        }
        return '/' . $slug . '/';
    }

    /**
     * Inner alternation for cs_0264 / PHP injectors (caller wraps \\b).
     *
     * @return string
     */
    public static function keyword_alternation() {
        return self::alternation(self::gate_needles());
    }

    /**
     * Inner alternation for cs_0396. Spaces become hyphens (slug form).
     *
     * @return string
     */
    public static function slug_alternation() {
        $tokens = [];
        foreach (self::needles() as $t) {
            $tokens[] = str_replace(' ', '-', $t);
        }
        return self::alternation(self::unique_ci($tokens));
    }

    /**
     * Core + brands, plus hyphen/underscore forms of spaced tokens.
     * Generic stays out (cs_0397 is two-operator spam, not poker+roulette).
     *
     * @return string
     */
    public static function brand_alternation() {
        $tokens = [];
        foreach (self::needles() as $t) {
            $tokens[] = $t;
            if (strpos($t, ' ') !== false) {
                $tokens[] = str_replace(' ', '-', $t);
                $tokens[] = str_replace(' ', '_', $t);
            }
        }
        return self::alternation(self::unique_ci($tokens));
    }

    /**
     * Two distinct core/brand tokens within 200 chars (cs_0397).
     *
     * FP policy: skip comparison discourse (vs / versus / compared /
     * comparison / review) in the gap so "Bet365 vs 1xbet: our comparison"
     * does not hit. Do not require hide/href (that is cs_0264).
     *
     * @return string
     */
    public static function two_brand_pattern() {
        $alt = self::brand_alternation();
        $edge = '(?<![A-Za-z0-9])';
        $end = '(?![A-Za-z0-9])';
        $gap = '(?:(?!\\b(?:vs\\.?|versus|compared|comparison|review)\\b)[\\s\\S]){0,200}?';
        return '/' . $edge . '(' . $alt . ')' . $end . $gap . $edge . '(?!\\1)' . '(?:' . $alt . ')' . $end . '/i';
    }

    /**
     * Hide-CSS fragment alternation (already regex).
     *
     * @return string
     */
    public static function hide_css_alternation() {
        return implode('|', self::hide_css());
    }

    /**
     * Bounded hide/href conjunction used as cs_0264.
     *
     * @return string
     */
    public static function content_conjunction_pattern() {
        $kw = '\\b(?:' . self::keyword_alternation() . ')\\b';
        $hide = '(?:' . self::hide_css_alternation() . ')';
        $w = '[\\s\\S]{0,120}';
        $wh = '[\\s\\S]{0,160}';
        $link = '(?:<a\\s' . $wh . '|href\\s*=' . $wh . '|src\\s*=' . $wh . '|https?:\\/\\/[^\\s\'"]{0,160})';
        return '/(?:' . $hide . $w . $kw . '|' . $kw . $w . $hide . '|' . $link . $kw . ')/i';
    }

    /**
     * Doorway slug/title: whole segment between ^ $ - _ /.
     *
     * @return string
     */
    public static function slug_segment_pattern() {
        $alt = self::slug_alternation();
        return '/(?:^|[-_\\/])(?:' . $alt . ')(?:[-_\\/]|$)/i';
    }

    /**
     * @param string[] $tokens
     * @return string
     */
    private static function alternation(array $tokens) {
        $out = [];
        foreach ($tokens as $t) {
            $out[] = preg_quote((string) $t, '/');
        }
        if ($out === []) {
            throw new RuntimeException('SEO keyword alternation is empty');
        }
        return implode('|', $out);
    }

    /**
     * @param string[] $tokens
     * @return string[]
     */
    private static function unique_ci(array $tokens) {
        $seen = [];
        $out = [];
        foreach ($tokens as $t) {
            $k = strtolower($t);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $t;
        }
        return $out;
    }
}
