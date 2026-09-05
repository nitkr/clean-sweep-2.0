<?php
/**
 * Clean Sweep - Signature Pre-Filter
 *
 * File-type based signature pre-filtering for optimized detection.
 * Reduces scan time by filtering signatures based on file extension.
 *
 * Category maps should come from csig entry metadata
 * (CleanSweep_MalwareSignatures::get_category_index_map()). Index-band
 * fallbacks are intentionally avoided — they previously parked ~100 PHP
 * WordPress rules in a "js_malicious" band and skipped them on .php files.
 */

class CleanSweep_SignaturePreFilter {

    /** @var array Full signature array */
    private $signatures = [];

    /** @var array Category to signature indices mapping */
    private $category_map = [];

    /** @var array File type to categories mapping */
    private $type_to_categories = [];

    /** @var array Loaded category => indices map (from csig entries or JSON) */
    private $categories_json = [];

    /** @var array|null Extension/target => indices (from csig entry targets[]) */
    private $target_index_map = null;

    /** @var array Compiled regex patterns (pre-compiled for performance) */
    private $compiled_patterns = [];

    /** @var bool Whether patterns have been compiled */
    private $patterns_compiled = false;

    /**
     * Initialize with signatures and optional categories.
     *
     * @param array $signatures Array of signature patterns
     * @param array|null $categories Category => int[] indices (from csig entries)
     * @param array|null $target_index_map Extension/target => int[] (from entry targets)
     */
    public function __construct($signatures = [], $categories = null, $target_index_map = null) {
        $this->signatures = $signatures;
        $this->categories_json = is_array($categories) ? $categories : [];
        $this->target_index_map = is_array($target_index_map) ? $target_index_map : null;
        $this->initialize_type_mapping();
        $this->build_category_map();
    }

    /**
     * Set signatures and rebuild filter.
     *
     * @param array $signatures
     */
    public function set_signatures($signatures) {
        $this->signatures = $signatures;
        $this->patterns_compiled = false;
        $this->compiled_patterns = [];
        $this->build_category_map();
    }

    /**
     * Set category => indices map (typically from csig entry metadata).
     *
     * @param array $categories
     */
    public function set_categories($categories) {
        $this->categories_json = is_array($categories) ? $categories : [];
        $this->build_category_map();
    }

    /**
     * Set extension/target => indices map from csig entry targets[].
     * When set, file-type filtering prefers targets over category bands.
     *
     * @param array|null $target_index_map
     */
    public function set_target_index_map($target_index_map) {
        $this->target_index_map = is_array($target_index_map) ? $target_index_map : null;
    }

    /**
     * Pre-compile all signature patterns once for efficient matching.
     * Compilation validates patterns and stores them in compiled form.
     *
     * @return array List of [index, pattern] tuples
     */
    public function compile_signatures() {
        if ($this->patterns_compiled && !empty($this->compiled_patterns)) {
            return $this->compiled_patterns;
        }

        $this->compiled_patterns = [];

        foreach ($this->signatures as $index => $pattern) {
            $test_result = @preg_match($pattern, '');
            if ($test_result === false) {
                if (function_exists('clean_sweep_log_message')) {
                    clean_sweep_log_message("Invalid signature pattern at index {$index}", 'warning');
                }
                continue;
            }

            $this->compiled_patterns[] = [
                'index' => $index,
                'pattern' => $pattern,
            ];
        }

        $this->patterns_compiled = true;
        return $this->compiled_patterns;
    }

    /**
     * Get compiled patterns for a specific file type.
     *
     * @param string $extension File extension
     * @return array Compiled patterns for the file type
     */
    public function get_compiled_for_filetype($extension) {
        $extension = strtolower($extension);

        if (!$this->patterns_compiled) {
            $this->compile_signatures();
        }

        $indices = $this->indices_for_extension($extension);
        if ($indices === []) {
            return [];
        }

        $valid_indices = [];
        foreach ($indices as $i) {
            if ($i >= 0 && $i < count($this->signatures)) {
                $valid_indices[$i] = true;
            }
        }

        $compiled = [];
        foreach ($this->compiled_patterns as $item) {
            if (isset($valid_indices[$item['index']])) {
                $compiled[] = $item;
            }
        }

        return $compiled;
    }

    /**
     * Resolve signature indices for a file extension.
     * Prefers entry targets[] when available; otherwise category map.
     *
     * @param string $extension
     * @return int[]
     */
    private function indices_for_extension($extension) {
        $extension = strtolower($extension);

        // HTML files keep the JS/skimmer alias AND any html/htm-specific rules.
        // A first `html` target used to replace the JS set (23 rules dropped).
        if (is_array($this->target_index_map) && in_array($extension, ['html', 'htm'], true)) {
            $indices = [];
            foreach (['js', 'html', 'htm'] as $key) {
                if (!empty($this->target_index_map[$key]) && is_array($this->target_index_map[$key])) {
                    $indices = array_merge($indices, $this->target_index_map[$key]);
                }
            }
            if ($indices !== []) {
                return array_values(array_unique($indices));
            }
        }

        if (is_array($this->target_index_map) && isset($this->target_index_map[$extension])) {
            return array_values(array_unique($this->target_index_map[$extension]));
        }

        // Alias: PHP shells / includes share php-targeted rules
        if (is_array($this->target_index_map) && in_array($extension, ['phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'inc'], true)
            && isset($this->target_index_map['php'])
        ) {
            return array_values(array_unique($this->target_index_map['php']));
        }

        // Config drop-ins are a common malware vector — apply php-targeted rules
        // (plus any extension-specific targets) instead of drifting category fallback.
        if (is_array($this->target_index_map) && in_array($extension, ['htaccess', 'ini', 'config', 'conf', 'cfg'], true)) {
            $indices = $this->target_index_map['php'] ?? [];
            if (!empty($this->target_index_map[$extension])) {
                $indices = array_merge($indices, $this->target_index_map[$extension]);
            }
            if ($indices !== []) {
                return array_values(array_unique($indices));
            }
        }

        if (!isset($this->type_to_categories[$extension])) {
            return [];
        }

        $indices = [];
        foreach ($this->type_to_categories[$extension] as $cat) {
            if (isset($this->category_map[$cat])) {
                $indices = array_merge($indices, $this->category_map[$cat]);
            }
        }
        return array_values(array_unique($indices));
    }

    /**
     * Initialize file type to category mapping.
     * Includes pack categories from build guess_category() (obfuscation, js_web).
     */
    private function initialize_type_mapping() {
        $php_cats = ['php_dangerous', 'wp_specific', 'general', 'encoding_chains', 'obfuscation'];
        $this->type_to_categories = [
            'php'  => $php_cats,
            'phtml' => $php_cats,
            'php3'  => $php_cats,
            'php4'  => $php_cats,
            'php5'  => $php_cats,
            'php7'  => $php_cats,
            'php8'  => $php_cats,
            'phar'  => $php_cats,
            'inc'   => $php_cats,
            'js'   => ['js_malicious', 'js_web', 'general'],
            'html' => ['js_malicious', 'js_web', 'general'],
            'htm'  => ['js_malicious', 'js_web', 'general'],
            'json' => ['general'],
            'conf' => ['general'],
            'cfg'  => ['general'],
            'ini'  => ['general', 'wp_specific', 'php_dangerous', 'obfuscation'],
            'config' => ['general', 'wp_specific'],
            'htaccess' => ['general', 'wp_specific', 'php_dangerous', 'obfuscation'],
        ];
    }

    /**
     * Build category map from csig/JSON metadata, else per-pattern heuristics.
     */
    private function build_category_map() {
        $this->category_map = [];

        if (!empty($this->categories_json)) {
            $this->category_map = $this->categories_json;
            return;
        }

        // Safe fallback: classify each pattern (never use index bands).
        foreach ($this->signatures as $index => $pattern) {
            $cat = self::guess_category_for_pattern((string) $pattern);
            if (!isset($this->category_map[$cat])) {
                $this->category_map[$cat] = [];
            }
            $this->category_map[$cat][] = (int) $index;
        }
    }

    /**
     * Rough category for a regex pattern (mirrors build_signatures guess_category).
     *
     * @param string $pattern
     * @return string
     */
    public static function guess_category_for_pattern($pattern) {
        $p = strtolower((string) $pattern);
        if (strpos($p, 'eval') !== false || strpos($p, 'assert') !== false) {
            return 'php_dangerous';
        }
        if (strpos($p, 'wp_') !== false || strpos($p, 'wordpress') !== false || strpos($p, 'admin-ajax') !== false) {
            return 'wp_specific';
        }
        if (strpos($p, 'base64') !== false || strpos($p, 'gzinflate') !== false || strpos($p, 'str_rot13') !== false) {
            return 'obfuscation';
        }
        if (strpos($p, 'document.') !== false || strpos($p, 'fromcharcode') !== false || strpos($p, 'innerhtml') !== false) {
            return 'js_web';
        }
        if (strpos($p, '$_cookie') !== false || strpos($p, '$_get') !== false
            || strpos($p, '$_post') !== false || strpos($p, '$_request') !== false
            || strpos($p, 'shell_exec') !== false || strpos($p, 'proc_open') !== false
            || strpos($p, 'popen') !== false || strpos($p, 'symlink') !== false) {
            return 'php_dangerous';
        }
        return 'general';
    }

    /**
     * @param string $extension File extension (php, js, etc.)
     * @return array Filtered signature patterns
     */
    public function get_for_filetype($extension) {
        $filtered = [];
        foreach ($this->indices_for_extension(strtolower($extension)) as $i) {
            if ($i >= 0 && $i < count($this->signatures)) {
                $filtered[] = $this->signatures[$i];
            }
        }
        return $filtered;
    }

    /**
     * @param array $extensions Array of extensions
     * @return array Filtered signatures
     */
    public function get_for_filetypes($extensions) {
        $result = [];
        foreach ($extensions as $ext) {
            $result = array_merge($result, $this->get_for_filetype($ext));
        }
        return array_unique($result, SORT_REGULAR);
    }

    /**
     * @param string $extension
     * @return bool
     */
    public function has_signatures_for($extension) {
        return !empty($this->get_for_filetype($extension));
    }

    /**
     * @param string $extension
     * @return array Categories and signature counts
     */
    public function get_category_breakdown($extension) {
        $extension = strtolower($extension);
        $breakdown = ['_total' => count($this->indices_for_extension($extension))];

        if (!isset($this->type_to_categories[$extension])) {
            return $breakdown;
        }

        foreach ($this->type_to_categories[$extension] as $cat) {
            if (isset($this->category_map[$cat])) {
                $breakdown[$cat] = count($this->category_map[$cat]);
            }
        }

        return $breakdown;
    }
}

/**
 * Resolve category => indices for scanners without breaking set_signatures(array).
 * Prefer the loaded malware signature pack when pattern counts match.
 *
 * @param array $signatures Pattern list passed to the scanner
 * @return array<string, int[]>
 */
function clean_sweep_resolve_signature_category_map(array $signatures) {
    if (function_exists('clean_sweep_get_malware_signatures')) {
        $mgr = clean_sweep_get_malware_signatures();
        if ($mgr && method_exists($mgr, 'get_category_index_map')
            && method_exists($mgr, 'count')
            && $mgr->count() === count($signatures)
        ) {
            return $mgr->get_category_index_map();
        }
    }

    $map = [];
    foreach ($signatures as $index => $pattern) {
        $cat = CleanSweep_SignaturePreFilter::guess_category_for_pattern((string) $pattern);
        if (!isset($map[$cat])) {
            $map[$cat] = [];
        }
        $map[$cat][] = (int) $index;
    }
    return $map;
}

/**
 * Resolve extension/target => indices from pack entry targets[] when available.
 *
 * @param array $signatures
 * @return array<string, int[]>|null Null when pack metadata unavailable
 */
function clean_sweep_resolve_signature_target_map(array $signatures) {
    if (!function_exists('clean_sweep_get_malware_signatures')) {
        return null;
    }
    $mgr = clean_sweep_get_malware_signatures();
    if (!$mgr || !method_exists($mgr, 'get_target_index_map') || !method_exists($mgr, 'count')) {
        return null;
    }
    if ($mgr->count() !== count($signatures)) {
        return null;
    }
    return $mgr->get_target_index_map();
}
