<?php
/**
 * Phase 5: optional labels for package verification (not gates).
 * Collision map, Pro-like headers, non-.org update channel.
 */
final class CleanSweep_PackageAnnotations {

    /**
     * Known free/Pro slug collisions (annotation only).
     *
     * @var array<string,array{reason:string,pro_name_substrings:string[]}>
     */
    private static $COLLISIONS = [
        'forminator' => [
            'reason' => 'free/Pro slug collision (WPMU DEV Forminator Pro shares free slug)',
            'pro_name_substrings' => ['forminator pro', 'wpmudev'],
        ],
        'wordpress-seo' => [
            'reason' => 'free Yoast SEO; premium is often a separate package',
            'pro_name_substrings' => ['premium', 'yoast seo premium'],
        ],
        'elementor' => [
            'reason' => 'free Elementor; Pro is usually a separate plugin folder',
            'pro_name_substrings' => ['elementor pro'],
        ],
    ];

    /**
     * @param array $pkg from CleanSweep_PackageChecksums::list_targets (+ optional header fields)
     * @return string[] short annotation phrases
     */
    public static function for_package(array $pkg): array {
        $out = [];
        $slug = strtolower((string) ($pkg['slug'] ?? ''));
        $name = (string) ($pkg['name'] ?? '');
        $name_l = strtolower($name);
        $type = (string) ($pkg['type'] ?? 'plugin');

        if ($slug !== '' && isset(self::$COLLISIONS[$slug])) {
            $c = self::$COLLISIONS[$slug];
            $looks_pro = false;
            foreach ($c['pro_name_substrings'] as $sub) {
                if ($sub !== '' && strpos($name_l, strtolower($sub)) !== false) {
                    $looks_pro = true;
                    break;
                }
            }
            if ($looks_pro || self::looks_premium_headers($pkg)) {
                $out[] = $c['reason'];
            }
        }

        if (self::looks_premium_headers($pkg)) {
            $out[] = 'Name/URI suggests a commercial or non-.org build';
        }

        if ($type === 'plugin') {
            $ch = self::update_channel_note($slug);
            if ($ch !== null) {
                $out[] = $ch;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Append annotations to a finding description (idempotent-ish).
     *
     * @param array $finding
     * @param string[] $annotations
     * @return array
     */
    public static function apply_to_finding(array $finding, array $annotations): array {
        if ($annotations === []) {
            return $finding;
        }
        $finding['annotations'] = $annotations;
        $extra = ' [' . implode('; ', $annotations) . ']';
        $desc = (string) ($finding['description'] ?? '');
        if ($desc !== '' && strpos($desc, $annotations[0]) === false) {
            $finding['description'] = rtrim($desc, '.') . '.' . $extra;
        }
        return $finding;
    }

    /**
     * @param array $findings
     * @param array $pkg
     * @return array
     */
    public static function apply_to_findings(array $findings, array $pkg): array {
        $ann = self::for_package($pkg);
        if ($ann === []) {
            return $findings;
        }
        $out = [];
        foreach ($findings as $f) {
            $out[] = is_array($f) ? self::apply_to_finding($f, $ann) : $f;
        }
        return $out;
    }

    private static function looks_premium_headers(array $pkg): bool {
        $name = strtolower((string) ($pkg['name'] ?? ''));
        if ($name !== '' && preg_match('/\b(pro|premium|business|agency|elite|ultimate)\b/i', $name)) {
            return true;
        }
        $update = strtolower((string) ($pkg['update_uri'] ?? ''));
        $plugin_uri = strtolower((string) ($pkg['plugin_uri'] ?? $pkg['theme_uri'] ?? ''));
        foreach ([$update, $plugin_uri] as $uri) {
            if ($uri === '') {
                continue;
            }
            if (strpos($uri, 'wordpress.org') !== false) {
                continue;
            }
            if (preg_match('#^https?://#', $uri)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Inspect update_plugins transient for non-.org package URL.
     */
    private static function update_channel_note(string $slug): ?string {
        if ($slug === '' || !function_exists('get_site_transient')) {
            return null;
        }
        $t = get_site_transient('update_plugins');
        if (!is_object($t)) {
            return null;
        }
        $candidates = [];
        foreach (['response', 'no_update'] as $bucket) {
            if (empty($t->$bucket) || !is_array($t->$bucket)) {
                continue;
            }
            foreach ($t->$bucket as $file => $row) {
                if (!is_object($row) && !is_array($row)) {
                    continue;
                }
                $row = (object) $row;
                $row_slug = (string) ($row->slug ?? '');
                if ($row_slug === '') {
                    $row_slug = dirname((string) $file);
                    if ($row_slug === '.' || $row_slug === '') {
                        $row_slug = basename((string) $file, '.php');
                    }
                }
                if (strtolower($row_slug) !== $slug) {
                    continue;
                }
                $url = (string) ($row->package ?? $row->url ?? $row->download_link ?? '');
                if ($url === '') {
                    continue;
                }
                $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
                if ($host === '') {
                    continue;
                }
                if (strpos($host, 'wordpress.org') !== false || strpos($host, 'downloads.wordpress.org') !== false) {
                    return null; // clearly .org
                }
                return 'Update channel is not wordpress.org (' . $host . ')';
            }
        }
        return null;
    }
}
