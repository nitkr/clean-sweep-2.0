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
        // Load WordPress plugin functions FROM FRESH/CLEAN WordPress installation
        // Calculate path to fresh wp-admin/includes/plugin.php (3 levels up to project root)
        $fresh_wp_admin = dirname(dirname(dirname(__DIR__))) . '/core/fresh/wp-admin/includes/plugin.php';
        require_once $fresh_wp_admin;

        clean_sweep_log_message("=== WordPress Plugin Analysis Started ===");
        clean_sweep_log_message("Version: " . CLEAN_SWEEP_VERSION);
        clean_sweep_log_message("WordPress Version: " . get_bloginfo('version'));
        clean_sweep_log_message("Site URL: " . get_site_url());
        clean_sweep_log_message("Progress file: " . ($progress_file ?: 'none'));

        try {
            // Check if we can write to plugins directory
            if (!wp_is_writable(WP_PLUGIN_DIR)) {
                throw new Exception("Plugins directory is not writable. Please check file permissions.");
            }

            // Initialize categorized plugin arrays
            $wp_org_plugins = [];
            $wpmu_dev_plugins = [];
            $non_repo_plugins = [];

            // Get WPMU DEV cached projects for lookup
            $wpmudev_projects = [];
            if (clean_sweep_is_wpmudev_available()) {
                WPMUDEV_Dashboard::$site->refresh_local_projects('local');
                $wpmudev_projects = WPMUDEV_Dashboard::$site->get_cached_projects();
            }

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
            $suspicious_files = $this->detect_suspicious_files($wp_org_plugins, $wpmu_dev_plugins, $non_repo_plugins);

            // Generate copy lists for UI
            $copy_lists = $this->generate_copy_lists($wp_org_plugins, $wpmu_dev_plugins, $non_repo_plugins, $suspicious_files);

            $wp_org_count = count($wp_org_plugins);
            $wpmu_dev_count = count($wpmu_dev_plugins);
            $non_repo_count = count($non_repo_plugins);
            $suspicious_count = count($suspicious_files);

            // Store FULL analysis data in site_transient keyed by progress_file for AJAX persistence
            if ($progress_file) {
                $analysis_key = 'clean_sweep_analysis_' . md5($progress_file);
                $full_analysis_data = [
                    'wp_org_plugins' => $wp_org_plugins,
                    'wpmu_dev_plugins' => $wpmu_dev_plugins,
                    'non_repo_plugins' => $non_repo_plugins,
                    'suspicious_files' => $suspicious_files,
                    'copy_lists' => $copy_lists,
                    'totals' => [
                        'wordpress_org' => $wp_org_count,
                        'wpmu_dev' => $wpmu_dev_count,
                        'non_repository' => $non_repo_count,
                        'suspicious' => $suspicious_count,
                        'total' => $total_plugins
                    ]
                ];
                set_site_transient($analysis_key, $full_analysis_data);
            }

            clean_sweep_log_message("=== WordPress Plugin Analysis Completed ===");

            return [
                'success' => true,
                'wp_org_plugins' => $wp_org_plugins,
                'wpmu_dev_plugins' => $wpmu_dev_plugins,
                'non_repo_plugins' => $non_repo_plugins,
                'suspicious_files' => $suspicious_files,
                'copy_lists' => $copy_lists,
                'totals' => [
                    'wordpress_org' => $wp_org_count,
                    'wpmu_dev' => $wpmu_dev_count,
                    'non_repository' => $non_repo_count,
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
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

        // Special handling for Hello Dolly - remove it entirely
        $slug = $this->extract_plugin_slug($plugin_file);
        if ($slug === 'hello') {
            $this->remove_hello_dolly($plugin_file, $plugin_data);
            return ['type' => 'removed', 'data' => []];
        }

        // Check WDP ID header first (definitive WPMU DEV detection)
        $wdp = get_file_data($plugin_path, ['id' => 'WDP ID'])['id'];
        if ($wdp && is_numeric($wdp)) {
            $project_info = clean_sweep_is_wpmudev_available() ?
                WPMUDEV_Dashboard::$site->get_project_info($wdp) : null;

            $data = [
                'wdp_id' => $wdp,
                'name' => $project_info->name ?? $plugin_data['Name'] ?? $plugin_file,
                'version' => $project_info->version_installed ?? $plugin_data['Version'] ?? 'Unknown',
                'description' => $project_info->description ?? $plugin_data['Description'] ?? '',
            ];

            clean_sweep_log_message("Scheduled {$data['name']} for WPMU DEV reinstallation (WDP ID: {$wdp})", 'info');
            return ['type' => 'wpmu_dev', 'data' => $data];
        }

        // Check if plugin appears in WPMU DEV cached projects
        foreach ((array) $wpmudev_projects as $pid => $project) {
            if (isset($project['filename']) && $project['filename'] === $plugin_file) {
                clean_sweep_log_message("Scheduled {$plugin_data['Name']} for WPMU DEV reinstallation (cached project)", 'info');
                return ['type' => 'wpmu_dev', 'data' => ['wdp_id' => null]];
            }
        }

        // Check if this is a WordPress.org plugin
        $wp_org_info = clean_sweep_fetch_plugin_info($slug);
        if (!empty($wp_org_info) && isset($wp_org_info['version'])) {
            $data = [
                'name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'slug' => $slug,
                'last_updated' => $wp_org_info['last_updated'] ?? null,
                'plugin_url' => $wp_org_info['homepage'] ?? "https://wordpress.org/plugins/{$slug}/",
            ];

            clean_sweep_log_message("Scheduled {$plugin_data['Name']} for WordPress.org reinstallation", 'info');
            return ['type' => 'wordpress_org', 'data' => $data];
        }

        // Non-repository plugin
        $data = [
            'name' => $plugin_data['Name'],
            'version' => $plugin_data['Version'],
            'reason' => 'Not found in WordPress.org repository'
        ];

        clean_sweep_log_message("Skipping non-repository plugin: {$plugin_data['Name']}", 'warning');
        return ['type' => 'non_repository', 'data' => $data];
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
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
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
     * Detect suspicious files and folders in plugins directory
     *
     * @param array $wp_org_plugins
     * @param array $wpmu_dev_plugins
     * @param array $non_repo_plugins
     * @return array
     */
    private function detect_suspicious_files($wp_org_plugins, $wpmu_dev_plugins, $non_repo_plugins) {
        clean_sweep_log_message("Detecting suspicious files in plugins directory", 'info');

        // Get all recognized plugin directories/files
        $recognized_plugins = array_merge(
            array_keys($wp_org_plugins),
            array_keys($wpmu_dev_plugins),
            array_keys($non_repo_plugins)
        );

        // Convert to directory names for comparison
        $recognized_dirs = [];
        foreach ($recognized_plugins as $plugin_file) {
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
                // Single file plugin, get the filename without extension
                $recognized_dirs[] = pathinfo($plugin_file, PATHINFO_FILENAME);
            } else {
                $recognized_dirs[] = basename($plugin_dir);
            }
        }
        $recognized_dirs = array_unique($recognized_dirs);

        // Scan plugins directory for suspicious items
        $suspicious_files = [];
        $all_items = scandir(WP_PLUGIN_DIR);

        foreach ($all_items as $item) {
            if ($item === '.' || $item === '..') continue;
            if ($item === 'index.php') continue; // Standard WordPress file

            $full_path = WP_PLUGIN_DIR . '/' . $item;

            // Check if this item belongs to a recognized plugin
            $is_recognized = false;
            foreach ($recognized_dirs as $recognized_dir) {
                if (strpos($item, $recognized_dir) === 0) {
                    $is_recognized = true;
                    break;
                }
            }

            if (!$is_recognized) {
                $analysis = $this->analyze_suspicious_item($full_path, $item);
                if ($analysis) {
                    $suspicious_files[] = $analysis;
                }
            }
        }

        clean_sweep_log_message("Found " . count($suspicious_files) . " suspicious files/folders", 'info');
        return $suspicious_files;
    }

    /**
     * Analyze a suspicious file or folder
     *
     * @param string $full_path
     * @param string $item_name
     * @return array|null
     */
    private function analyze_suspicious_item($full_path, $item_name) {
        if (!file_exists($full_path)) {
            return null;
        }

        $is_dir = is_dir($full_path);
        $size_bytes = 0;
        $file_count = 0;

        if ($is_dir) {
            $size_bytes = $this->get_directory_size($full_path);
            $file_count = $this->count_files_in_directory($full_path);
        } else {
            $size_bytes = filesize($full_path);
            $file_count = 1;
        }

        $size_mb = round($size_bytes / 1024 / 1024, 2);

        return [
            'name' => $item_name,
            'path' => $full_path,
            'is_directory' => $is_dir,
            'size_bytes' => $size_bytes,
            'size_mb' => $size_mb,
            'file_count' => $file_count,
            'last_modified' => filemtime($full_path),
            'readable' => is_readable($full_path),
            'writable' => is_writable($full_path)
        ];
    }

    /**
     * Get directory size recursively
     *
     * @param string $directory
     * @return int
     */
    private function get_directory_size($directory) {
        $size = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Count files in directory recursively
     *
     * @param string $directory
     * @return int
     */
    private function count_files_in_directory($directory) {
        $count = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Generate copy lists for UI display
     *
     * @param array $wp_org_plugins
     * @param array $wpmu_dev_plugins
     * @param array $non_repo_plugins
     * @param array $suspicious_files
     * @return array
     */
    private function generate_copy_lists($wp_org_plugins, $wpmu_dev_plugins, $non_repo_plugins, $suspicious_files) {
        return [
            'wordpress_org' => $this->format_plugin_list($wp_org_plugins),
            'wpmu_dev' => $this->format_plugin_list($wpmu_dev_plugins),
            'non_repository' => $this->format_plugin_list($non_repo_plugins),
            'suspicious' => $this->format_suspicious_list($suspicious_files)
        ];
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
            $type = $file['is_directory'] ? 'Directory' : 'File';
            $size = $file['size_mb'] . ' MB';
            $count = $file['is_directory'] ? " ({$file['file_count']} files)" : '';
            $lines[] = "{$file['name']} - {$type} - {$size}{$count}";
        }

        return implode("\n", $lines);
    }
}
