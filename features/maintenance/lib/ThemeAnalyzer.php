<?php
/**
 * Clean Sweep - Theme Analyzer
 *
 * Handles theme analysis and categorization including suspicious file detection.
 * Separates WordPress.org, custom/non-repo themes, and identifies suspicious files.
 *
 * @version 1.1
 */

class CleanSweep_ThemeAnalyzer {

    /**
     * Analyze all themes in the themes directory
     *
     * @param string|null $progress_file Progress file for AJAX updates
     * @return array Analysis results
     */
    public function analyze($progress_file = null) {
        clean_sweep_log_message("ThemeAnalyzer: Starting theme analysis", 'info');

        // Ensure shared analyzer + theme API helper are available
        $lib_dir = __DIR__;
        if (!class_exists('CleanSweep_SuspiciousItemAnalyzer', false)) {
            require_once $lib_dir . '/SuspiciousItemAnalyzer.php';
        }
        if (!class_exists('CleanSweep_PackageIdentity', false)) {
            require_once $lib_dir . '/PackageIdentity.php';
        }
        $cs_file = dirname(__DIR__, 2) . '/security/scan/PackageChecksums.php';
        if (!class_exists('CleanSweep_PackageChecksums', false) && is_readable($cs_file)) {
            require_once $cs_file;
        }
        if (!function_exists('clean_sweep_fetch_theme_info')) {
            require_once dirname(__DIR__) . '/plugin-utils.php';
        }

        // Get all themes using WordPress API
        $all_themes = wp_get_themes();

        // Categorize themes
        $wp_org_themes = [];
        $custom_themes = [];
        $likely_fake_themes = [];
        $themes_dir = defined('ORIGINAL_WP_CONTENT_DIR')
            ? ORIGINAL_WP_CONTENT_DIR . '/themes'
            : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/themes' : get_theme_root());

        foreach ($all_themes as $theme_slug => $theme) {
            $theme_data = [
                'slug' => $theme_slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'description' => $theme->get('Description'),
                'author' => $theme->get('Author'),
                'theme_uri' => $theme->get('ThemeURI'),
                'template' => $theme->get('Template'), // For child themes
                'status' => $theme->get('Status'),
            ];

            $info = [];
            if (function_exists('clean_sweep_fetch_theme_info')) {
                $info = clean_sweep_fetch_theme_info($theme_slug);
            }
            if (!empty($info) && !empty($info['version'])) {
                $theme_data['last_updated'] = $info['last_updated'] ?? null;
                $theme_data['plugin_url'] = $info['homepage'] ?? ("https://wordpress.org/themes/{$theme_slug}/");
            }

            $checksum_row = null;
            if (class_exists('CleanSweep_PackageChecksums', false)) {
                $latest = CleanSweep_PackageChecksums::load_latest();
                $checksum_row = $latest['theme:' . $theme_slug] ?? null;
            }
            $verdict = class_exists('CleanSweep_PackageIdentity', false)
                ? CleanSweep_PackageIdentity::evaluate([
                    'type' => 'theme',
                    'slug' => $theme_slug,
                    'name' => (string) $theme_data['name'],
                    'version' => (string) $theme_data['version'],
                    'author' => (string) $theme_data['author'],
                    'theme_uri' => (string) $theme_data['theme_uri'],
                    'author_uri' => (string) $theme->get('AuthorURI'),
                    'dir' => rtrim($themes_dir, '/') . '/' . $theme_slug,
                    'org_info' => (!empty($info) && !empty($info['version'])) ? $info : [],
                    'checksum_status' => is_array($checksum_row) ? ($checksum_row['status'] ?? null) : null,
                    'checksum_outcome' => is_array($checksum_row) ? ($checksum_row['outcome'] ?? null) : null,
                ])
                : ['kind' => 'ok'];

            if (($verdict['kind'] ?? 'ok') !== 'ok') {
                $theme_data['identity_kind'] = $verdict['kind'];
                $theme_data['reasons'] = $verdict['reasons'];
                $theme_data['org_name'] = $verdict['org_name'];
                $theme_data['org_version'] = $verdict['org_version'];
                $theme_data['reason'] = $verdict['reasons'][0] ?? 'Likely fake or impersonating theme';
                $likely_fake_themes[$theme_slug] = $theme_data;
            } elseif (!empty($info) && !empty($info['version'])) {
                $wp_org_themes[$theme_slug] = $theme_data;
            } else {
                $custom_themes[$theme_slug] = $theme_data;
            }
        }

        // Detect suspicious files in themes directory
        $suspicious_files = $this->detect_suspicious_files($wp_org_themes, $custom_themes, $likely_fake_themes);

        // Generate copy lists for UI
        $copy_lists = $this->generate_copy_lists($wp_org_themes, $custom_themes, $suspicious_files, $likely_fake_themes);

        $wp_org_count = count($wp_org_themes);
        $custom_count = count($custom_themes);
        $suspicious_count = count($suspicious_files);
        $likely_fake_count = count($likely_fake_themes);
        $total_themes = $wp_org_count + $custom_count + $likely_fake_count;

        clean_sweep_log_message("=== Theme Analysis Completed ===");
        clean_sweep_log_message("WordPress.org: {$wp_org_count}, Custom: {$custom_count}, Likely fake: {$likely_fake_count}, Suspicious files: {$suspicious_count}");

        if (class_exists('CleanSweep_PackageIdentity', false)) {
            $items = [];
            foreach ($likely_fake_themes as $row) {
                $items[] = [
                    'type' => 'theme',
                    'slug' => $row['slug'] ?? '',
                    'name' => $row['name'] ?? '',
                    'kind' => $row['identity_kind'] ?? 'decoy',
                    'reasons' => $row['reasons'] ?? [],
                ];
            }
            CleanSweep_PackageIdentity::replace_type('theme', $items);
        }

        return [
            'success' => true,
            'wp_org_themes' => $wp_org_themes,
            'custom_themes' => $custom_themes,
            'likely_fake_themes' => $likely_fake_themes,
            'suspicious_files' => $suspicious_files,
            'copy_lists' => $copy_lists,
            'totals' => [
                'wp_org' => $wp_org_count,
                'custom' => $custom_count,
                'likely_fake' => $likely_fake_count,
                'suspicious' => $suspicious_count,
                'total' => $total_themes
            ]
        ];
    }

    /**
     * Detect orphan files/folders in the themes directory root.
     *
     * Orphans only: not an exact match to a recognized WP.org or custom theme.
     * Does not scan inside theme packages (reinstall + malware scan cover that).
     *
     * @param array $wp_org_themes WordPress.org themes
     * @param array $custom_themes Custom/non-repo themes
     * @param array $likely_fake_themes
     * @return array Suspicious files
     */
    private function detect_suspicious_files($wp_org_themes, $custom_themes, $likely_fake_themes = []) {
        clean_sweep_log_message("ThemeAnalyzer: Detecting orphan files in themes directory", 'info');

        $themes_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/themes' : get_theme_root();
        if (defined('ORIGINAL_WP_CONTENT_DIR')) {
            $themes_dir = ORIGINAL_WP_CONTENT_DIR . '/themes';
        }

        clean_sweep_log_message("ThemeAnalyzer: Scanning themes directory: " . $themes_dir, 'debug');

        if (!is_dir($themes_dir)) {
            clean_sweep_log_message("ThemeAnalyzer: Themes directory does not exist: " . $themes_dir, 'warning');
            return [];
        }

        $recognized_dirs = array_values(array_unique(array_merge(
            array_keys($wp_org_themes),
            array_keys($custom_themes),
            array_keys($likely_fake_themes)
        )));

        $suspicious_files = CleanSweep_SuspiciousItemAnalyzer::scan_orphans(
            $themes_dir,
            $recognized_dirs,
            'orphan'
        );

        clean_sweep_log_message("ThemeAnalyzer: Found " . count($suspicious_files) . " orphan files/folders in themes root", 'info');
        return $suspicious_files;
    }

    /**
     * Generate copy lists for UI
     *
     * @param array $wp_org_themes WordPress.org themes
     * @param array $custom_themes Custom themes
     * @param array $suspicious_files Suspicious files
     * @return array Copy lists
     */
    private function generate_copy_lists($wp_org_themes, $custom_themes, $suspicious_files, $likely_fake_themes = []) {
        return [
            'wordpress_org' => $this->format_theme_list($wp_org_themes),
            'custom' => $this->format_theme_list($custom_themes),
            'likely_fake' => $this->format_theme_list($likely_fake_themes),
            'suspicious' => $this->format_suspicious_list($suspicious_files)
        ];
    }

    /**
     * Format theme list for copy functionality
     *
     * @param array $themes Themes array
     * @return string Formatted list
     */
    private function format_theme_list($themes) {
        if (empty($themes)) {
            return '';
        }

        $lines = [];
        foreach ($themes as $theme) {
            $lines[] = $theme['name'] . ' (' . $theme['version'] . ')';
        }
        return implode("\n", $lines);
    }

    /**
     * Format suspicious files list for copy functionality
     *
     * @param array $suspicious_files Suspicious files
     * @return string Formatted list
     */
    private function format_suspicious_list($suspicious_files) {
        if (empty($suspicious_files)) {
            return '';
        }

        $lines = [];
        foreach ($suspicious_files as $file) {
            $type = !empty($file['is_directory']) ? 'Directory' : 'File';
            $severity = $file['severity'] ?? 'unknown';
            $reason = '';
            if (!empty($file['reasons']) && is_array($file['reasons'])) {
                $reason = ': ' . $file['reasons'][0];
            }
            $lines[] = "[{$severity}] [{$type}] {$file['name']}{$reason}";
        }
        return implode("\n", $lines);
    }
}
