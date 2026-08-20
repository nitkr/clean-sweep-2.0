<?php
/**
 * Clean Sweep - Plugin Analyzer
 *
 * Handles plugin analysis and categorization including suspicious file detection.
 * Separates WordPress.org, WPMU DEV, and non-repository plugins, plus identifies
 * potentially malicious files/folders in the plugins directory.
 */

class CleanSweep_PluginAnalyzer {

    /**
     * Analyze all installed plugins and categorize them
     *
     * @param string|null $progress_file Optional progress file for AJAX updates
     * @return array Analysis results
     */
    public function analyze($progress_file = null) {
        // Load WordPress plugin functions conditionally to avoid redeclaration errors
        // Only load if not already available from selective plugin loading
        if (!function_exists('get_plugins')) {
            // Path from PluginAnalyzer.php: features/maintenance/lib/
            // Go up 3 levels: ../../../../ then to: core/fresh/wp-admin/includes/plugin.php
            $fresh_plugin_php = dirname(__DIR__, 3) . '/core/fresh/wp-admin/includes/plugin.php';
            require_once $fresh_plugin_php;
            clean_sweep_log_message("Loaded WordPress plugin functions from fresh installation: $fresh_plugin_php", 'debug');
        }

        $cs_file = dirname(__DIR__, 2) . '/security/scan/PackageChecksums.php';
        if (!class_exists('CleanSweep_PackageChecksums', false) && is_readable($cs_file)) {
            require_once $cs_file;
        }
        if (!class_exists('CleanSweep_PackageIdentity', false)) {
            require_once __DIR__ . '/PackageIdentity.php';
        }

        clean_sweep_log_message("=== WordPress Plugin Analysis Started ===");
        clean_sweep_log_message("Version: " . CLEAN_SWEEP_VERSION);
        clean_sweep_log_message("WordPress Version: " . get_bloginfo('version'));
        clean_sweep_log_message("Site URL: " . get_site_url());
        clean_sweep_log_message("Progress file: " . ($progress_file ?: 'none'));

        try {
            // Determine the correct plugins directory
            // Use ORIGINAL_WP_PLUGIN_DIR if available (for recovery mode), otherwise use WP_PLUGIN_DIR
            $target_plugins_dir = defined('ORIGINAL_WP_PLUGIN_DIR') ? ORIGINAL_WP_PLUGIN_DIR : WP_PLUGIN_DIR;
            clean_sweep_log_message("Target plugins directory: " . $target_plugins_dir, 'debug');
            
            // Check if we can write to plugins directory
            if (!wp_is_writable($target_plugins_dir)) {
                throw new Exception("Plugins directory is not writable. Please check file permissions.");
            }

            // Initialize categorized plugin arrays
            $wp_org_plugins = [];
            $wpmu_dev_plugins = [];
            $non_repo_plugins = [];
            $likely_fake_plugins = [];
            $skipped_plugins = [];

            // Check WPMU DEV availability and get cached projects for lookup
            $wpmu_dev_available = clean_sweep_is_wpmudev_available();
            $wpmudev_projects = [];
            if ($wpmu_dev_available) {
                WPMUDEV_Dashboard::$site->refresh_local_projects('local');
                $wpmudev_projects = WPMUDEV_Dashboard::$site->get_cached_projects();
            }

            // Get all plugins
            $all_plugins = get_plugins();
            $total_plugins = count($all_plugins);
            clean_sweep_log_message("Found $total_plugins installed plugins");

            // Analyze each plugin
            $current_count = 0;
            foreach ($all_plugins as $plugin_file => $plugin_data) {
                $current_count++;
                $result = $this->analyze_single_plugin($plugin_file, $plugin_data, $wpmudev_projects);

                // Categorize the plugin
                if ($result['type'] === 'wpmu_dev') {
                    $wpmu_dev_plugins[$plugin_file] = $result['data'];
                } elseif ($result['type'] === 'wordpress_org') {
                    $wp_org_plugins[$plugin_file] = $result['data'];
                } elseif ($result['type'] === 'skipped') {
                    $skipped_plugins[$plugin_file] = $result['data'];
                } elseif ($result['type'] === 'likely_fake') {
                    $likely_fake_plugins[$plugin_file] = $result['data'];
                } else {
                    $non_repo_plugins[$plugin_file] = $result['data'];
                }

                // Update progress for plugin analysis
                if ($progress_file) {
                    $progress_data = [
                        'status' => 'analyzing',
                        'progress' => round(($current_count / $total_plugins) * 100),
                        'message' => "Analyzing plugin $current_count of $total_plugins: {$plugin_data['Name']}",
                        'current' => $current_count,
                        'total' => $total_plugins,
                        'step' => 1,
                        'total_steps' => 1
                    ];
                    @clean_sweep_write_progress_file($progress_file, $progress_data);
                }
            }

            // Detect suspicious files/folders
            $suspicious_files = $this->detect_suspicious_files($wp_org_plugins, $wpmu_dev_plugins, $non_repo_plugins, $skipped_plugins, $likely_fake_plugins);

            // Generate copy lists for UI
            $copy_lists = $this->generate_copy_lists($wp_org_plugins, $wpmu_dev_plugins, $non_repo_plugins, $suspicious_files, $likely_fake_plugins);

            $wp_org_count = count($wp_org_plugins);
            $wpmu_dev_count = count($wpmu_dev_plugins);
            $non_repo_count = count($non_repo_plugins);
            $skipped_count = count($skipped_plugins);
            $suspicious_count = count($suspicious_files);
            $likely_fake_count = count($likely_fake_plugins);

            // Analysis data is now passed directly with each JavaScript batch request
            // Analysis data is now passed directly with each JavaScript batch request
            // No database storage needed with JavaScript-only batching

            clean_sweep_log_message("=== Advanced Plugin Analysis Completed ===");
            clean_sweep_log_message("WordPress.org: {$wp_org_count}, WPMU DEV: {$wpmu_dev_count}, Non-repository: {$non_repo_count}, Likely fake: {$likely_fake_count}, Suspicious files: {$suspicious_count}");

            $this->persist_identity_hits($likely_fake_plugins);

            return [
                'success' => true,
                'wp_org_plugins' => $wp_org_plugins,
                'wpmu_dev_plugins' => $wpmu_dev_plugins,
                'non_repo_plugins' => $non_repo_plugins,
                'likely_fake_plugins' => $likely_fake_plugins,
                'skipped_plugins' => $skipped_plugins,
                'suspicious_files' => $suspicious_files,
                'copy_lists' => $copy_lists,
                'wpmu_dev_available' => $wpmu_dev_available,
                'totals' => [
                    'wordpress_org' => $wp_org_count,
                    'wpmu_dev' => $wpmu_dev_count,
                    'non_repository' => $non_repo_count,
                    'likely_fake' => $likely_fake_count,
                    'skipped' => $skipped_count,
                    'suspicious' => $suspicious_count,
                    'total' => $total_plugins
                ]
            ];

        } catch (Exception $e) {
            clean_sweep_log_message("Plugin analysis failed: " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Analyze a single plugin and determine its type
     *
     * @param string $plugin_file
     * @param array $plugin_data
     * @param array $wpmudev_projects
     * @return array
     */
    private function analyze_single_plugin($plugin_file, $plugin_data, $wpmudev_projects) {
        // Use ORIGINAL_WP_PLUGIN_DIR if available
        $plugins_dir = defined('ORIGINAL_WP_PLUGIN_DIR') ? ORIGINAL_WP_PLUGIN_DIR : WP_PLUGIN_DIR;
        $plugin_path = $plugins_dir . '/' . $plugin_file;

        // Special handling for Hello Dolly - remove it entirely
        $slug = $this->extract_plugin_slug($plugin_file);
        if ($slug === 'hello') {
            $this->remove_hello_dolly($plugin_file, $plugin_data);
            return ['type' => 'removed', 'data' => []];
        }

        // Check WDP ID header first (definitive WPMU DEV detection)
        $wdp = get_file_data($plugin_path, ['id' => 'WDP ID'])['id'];
        if ($wdp && is_numeric($wdp)) {
            // Skip WPMU DEV Dashboard (ID 119) - cannot be reinstalled
            if ((int) $wdp === 119) {
                clean_sweep_log_message("Skipping WPMU DEV Dashboard (ID 119) - cannot be reinstalled", 'info');
                return ['type' => 'skipped', 'data' => [
                    'name' => $plugin_data['Name'] ?? $plugin_file,
                    'reason' => 'WPMU DEV Dashboard cannot be reinstalled'
                ]];
            }

            $project_info = clean_sweep_is_wpmudev_available() ?
                WPMUDEV_Dashboard::$site->get_project_info($wdp) : null;

            $data = [
                'wdp_id' => $wdp,
                'slug' => $slug,
                'plugin_file' => $plugin_file,
                'name' => $project_info->name ?? $plugin_data['Name'] ?? $plugin_file,
                'version' => $project_info->version_installed ?? $plugin_data['Version'] ?? 'Unknown',
                'description' => $project_info->description ?? $plugin_data['Description'] ?? '',
            ];

            clean_sweep_log_message("Scheduled {$data['name']} for WPMU DEV reinstallation (WDP ID: {$wdp})", 'info');
            return ['type' => 'wpmu_dev', 'data' => $data];
        }

        // Check if plugin appears in WPMU DEV cached projects
        clean_sweep_log_message("DEBUG: Checking cached projects for plugin_file='$plugin_file', slug='$slug'", 'debug');
        foreach ((array) $wpmudev_projects as $pid => $project) {
            $cached_filename = $project['filename'] ?? 'NOT_SET';
            
            // Handle format mismatch: plugin_file might be 'ultimate-branding' but filename is 'ultimate-branding/ultimate-branding.php'
            $plugin_dir = dirname($plugin_file);
            $plugin_basename = pathinfo($plugin_file, PATHINFO_FILENAME);
            $cached_dir = dirname($cached_filename);
            $cached_basename = pathinfo($cached_filename, PATHINFO_FILENAME);
            
            // Check different matching strategies
            $match = false;
            if ($cached_filename === $plugin_file) {
                // Exact match
                $match = true;
            } elseif ($plugin_dir !== '.' && $plugin_dir === $cached_dir) {
                // Directory match: 'ultimate-branding' === 'ultimate-branding'
                $match = true;
            } elseif ($plugin_dir === '.' && $plugin_basename === $cached_dir) {
                // plugin_file is just name like 'forminator-pro', compare basename with cached dirname
                $match = true;
            } elseif ($plugin_dir === '.' && $plugin_basename === $cached_basename) {
                // Basename comparison as last resort
                $match = true;
            }
            
            clean_sweep_log_message("DEBUG: Comparing cached project pid=$pid, filename='$cached_filename' (dir: '$cached_dir', base: '$cached_basename') vs plugin_file='$plugin_file' (dir: '$plugin_dir', base: '$plugin_basename') " . ($match ? 'MATCH' : 'NO MATCH'), 'debug');
            
            if ($match) {
                clean_sweep_log_message("Scheduled {$plugin_data['Name']} for WPMU DEV reinstallation (cached project PID: {$pid})", 'info');
                return ['type' => 'wpmu_dev', 'data' => [
                    'wdp_id' => $pid,
                    'slug' => $slug,
                    'plugin_file' => $plugin_file,
                    'name' => $plugin_data['Name'] ?? $plugin_file,
                    'version' => $plugin_data['Version'] ?? 'Unknown',
                ]];
            }
        }

        $plugin_dir = dirname($plugin_path);
        $single = (dirname($plugin_file) === '.' || dirname($plugin_file) === '') ? basename($plugin_file) : null;
        $checksum_row = null;
        if (class_exists('CleanSweep_PackageChecksums', false)) {
            $latest = CleanSweep_PackageChecksums::load_latest();
            $checksum_row = $latest['plugin:' . $slug] ?? null;
        }
        $identity_ctx = [
            'type' => 'plugin',
            'slug' => $slug,
            'name' => (string) ($plugin_data['Name'] ?? $slug),
            'version' => (string) ($plugin_data['Version'] ?? ''),
            'author' => (string) ($plugin_data['Author'] ?? ''),
            'plugin_uri' => (string) ($plugin_data['PluginURI'] ?? ''),
            'author_uri' => (string) ($plugin_data['AuthorURI'] ?? ''),
            'dir' => $plugin_dir,
            'single_file' => $single,
            'plugin_file' => $plugin_file,
            'checksum_status' => is_array($checksum_row) ? ($checksum_row['status'] ?? null) : null,
            'checksum_outcome' => is_array($checksum_row) ? ($checksum_row['outcome'] ?? null) : null,
        ];

        // Check if this is a WordPress.org plugin
        $wp_org_info = clean_sweep_fetch_plugin_info($slug);
        if (!empty($wp_org_info) && isset($wp_org_info['version'])) {
            $identity_ctx['org_info'] = $wp_org_info;
            $id = $this->identity_verdict($identity_ctx);
            if ($id) {
                return $id;
            }
            $data = [
                'name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'slug' => $slug,
                'plugin_file' => $plugin_file,
                'last_updated' => $wp_org_info['last_updated'] ?? null,
                'plugin_url' => $wp_org_info['homepage'] ?? "https://wordpress.org/plugins/{$slug}/",
            ];

            clean_sweep_log_message("Scheduled {$plugin_data['Name']} for WordPress.org reinstallation", 'info');
            return ['type' => 'wordpress_org', 'data' => $data];
        }

        $identity_ctx['org_info'] = [];
        $id = $this->identity_verdict($identity_ctx);
        if ($id) {
            return $id;
        }

        // Non-repository plugin
        $data = [
            'slug' => $slug,
            'name' => $plugin_data['Name'],
            'version' => $plugin_data['Version'],
            'reason' => 'Not found in WordPress.org repository'
        ];

        clean_sweep_log_message("Skipping non-repository plugin: {$plugin_data['Name']}", 'warning');
        return ['type' => 'non_repository', 'data' => $data];
    }

    /**
     * @param array $ctx
     * @return array|null
     */
    private function identity_verdict(array $ctx) {
        if (!class_exists('CleanSweep_PackageIdentity', false)) {
            return null;
        }
        $verdict = CleanSweep_PackageIdentity::evaluate($ctx);
        if (($verdict['kind'] ?? 'ok') === 'ok') {
            return null;
        }
        $data = [
            'slug' => $ctx['slug'],
            'name' => $ctx['name'],
            'version' => $ctx['version'],
            'plugin_file' => $ctx['plugin_file'] ?? $ctx['slug'],
            'identity_kind' => $verdict['kind'],
            'reasons' => $verdict['reasons'],
            'org_name' => $verdict['org_name'],
            'org_version' => $verdict['org_version'],
            'reason' => $verdict['reasons'][0] ?? 'Likely fake or impersonating package',
        ];
        clean_sweep_log_message(
            "Likely fake plugin {$ctx['name']} ({$verdict['kind']}): " . implode('; ', $verdict['reasons']),
            'warning'
        );
        return ['type' => 'likely_fake', 'data' => $data];
    }

    /**
     * Extract plugin slug from plugin file path
     *
     * @param string $plugin_file
     * @return string
     */
    private function extract_plugin_slug($plugin_file) {
        $plugin_dir = dirname($plugin_file);
        if ($plugin_dir === '.' || $plugin_dir === '') {
            return pathinfo($plugin_file, PATHINFO_FILENAME);
        } else {
            return basename($plugin_dir);
        }
    }

    /**
     * Remove Hello Dolly plugin
     *
     * @param string $plugin_file
     * @param array $plugin_data
     */
    private function remove_hello_dolly($plugin_file, $plugin_data) {
        // Use ORIGINAL_WP_PLUGIN_DIR if available
        $plugins_dir = defined('ORIGINAL_WP_PLUGIN_DIR') ? ORIGINAL_WP_PLUGIN_DIR : WP_PLUGIN_DIR;
        $plugin_path = $plugins_dir . '/' . $plugin_file;
        if (file_exists($plugin_path)) {
            clean_sweep_log_message("Removing Hello Dolly plugin (demo plugin): {$plugin_data['Name']}", 'info');

            global $wp_filesystem;
            if (!$wp_filesystem) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }

            if ($wp_filesystem->delete($plugin_path)) {
                clean_sweep_log_message("Successfully removed Hello Dolly plugin", 'info');
            } else {
                clean_sweep_log_message("Failed to remove Hello Dolly plugin", 'warning');
            }
        }
    }

    /**
     * Detect orphan files/folders in the plugins directory root.
     *
     * Orphans only: items that are not an exact match to a recognized WP.org,
     * WPMU DEV, custom, or skipped plugin package. Does not scan inside those
     * packages (reinstall + malware scan cover in-package issues). Does not
     * list mu-plugins (avoid false positives on legitimate drop-ins).
     *
     * @param array $wp_org_plugins
     * @param array $wpmu_dev_plugins
     * @param array $non_repo_plugins
     * @param array $skipped_plugins
     * @param array $likely_fake_plugins
     * @return array
     */
    private function detect_suspicious_files($wp_org_plugins, $wpmu_dev_plugins, $non_repo_plugins, $skipped_plugins = [], $likely_fake_plugins = []) {
        clean_sweep_log_message("Detecting orphan files in plugins directory", 'info');

        $plugins_dir = defined('ORIGINAL_WP_PLUGIN_DIR') ? ORIGINAL_WP_PLUGIN_DIR : WP_PLUGIN_DIR;
        clean_sweep_log_message("Scanning plugins directory: " . $plugins_dir, 'debug');

        $all_known = array_merge($wp_org_plugins, $wpmu_dev_plugins, $non_repo_plugins, $skipped_plugins, $likely_fake_plugins);

        $recognized_exact = [];
        foreach ($all_known as $plugin_file => $plugin_data) {
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
                $recognized_exact[] = $plugin_file;
                $recognized_exact[] = pathinfo($plugin_file, PATHINFO_FILENAME);
            } else {
                $recognized_exact[] = basename($plugin_dir);
            }
        }
        $recognized_exact = array_values(array_unique($recognized_exact));

        $suspicious_files = CleanSweep_SuspiciousItemAnalyzer::scan_orphans(
            $plugins_dir,
            $recognized_exact,
            'orphan'
        );

        clean_sweep_log_message("Found " . count($suspicious_files) . " orphan files/folders in plugins root", 'info');
        return $suspicious_files;
    }

    /**
     * Generate copy lists for UI display
     *
     * @param array $wp_org_plugins
     * @param array $wpmu_dev_plugins
     * @param array $non_repo_plugins
     * @param array $suspicious_files
     * @param array $likely_fake_plugins
     * @return array
     */
    private function generate_copy_lists($wp_org_plugins, $wpmu_dev_plugins, $non_repo_plugins, $suspicious_files, $likely_fake_plugins = []) {
        return [
            'wordpress_org' => $this->format_plugin_list($wp_org_plugins),
            'wpmu_dev' => $this->format_plugin_list($wpmu_dev_plugins),
            'non_repository' => $this->format_plugin_list($non_repo_plugins),
            'likely_fake' => $this->format_plugin_list($likely_fake_plugins),
            'suspicious' => $this->format_suspicious_list($suspicious_files)
        ];
    }

    /**
     * @param array $likely_fake_plugins
     */
    private function persist_identity_hits(array $likely_fake_plugins): void {
        if (!class_exists('CleanSweep_PackageIdentity', false)) {
            return;
        }
        $items = [];
        foreach ($likely_fake_plugins as $row) {
            $items[] = [
                'type' => 'plugin',
                'slug' => $row['slug'] ?? '',
                'name' => $row['name'] ?? '',
                'kind' => $row['identity_kind'] ?? 'decoy',
                'reasons' => $row['reasons'] ?? [],
            ];
        }
        CleanSweep_PackageIdentity::replace_type('plugin', $items);
    }

    /**
     * Format plugin list for copy functionality
     *
     * @param array $plugins
     * @return string
     */
    private function format_plugin_list($plugins) {
        if (empty($plugins)) {
            return '';
        }

        $lines = [];
        foreach ($plugins as $plugin_file => $plugin_data) {
            $name = $plugin_data['name'] ?? $plugin_file;
            $version = $plugin_data['version'] ?? 'Unknown';
            $lines[] = "{$name} (v{$version})";
        }

        return implode("\n", $lines);
    }

    /**
     * Format suspicious files list for copy functionality
     *
     * @param array $suspicious_files
     * @return string
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
            $size = isset($file['size_mb']) ? $file['size_mb'] . ' MB' : '';
            $count = !empty($file['is_directory']) && isset($file['file_count'])
                ? " ({$file['file_count']} files)"
                : '';
            $lines[] = "[{$severity}] {$file['name']} - {$type} - {$size}{$count}{$reason}";
        }

        return implode("\n", $lines);
    }
}
