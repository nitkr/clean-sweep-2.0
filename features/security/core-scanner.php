<?php
/**
 * Clean Sweep - Core Malware Scanner
 *
 * Basic scanning operations and database scanning methods
 * Optimized for memory efficiency and comprehensive coverage
 */

/**
 * Core Malware Scanner Class
 * Handles basic scanning operations and batch processing
 */
class Clean_Sweep_Core_Malware_Scanner {

    private $batch_size = 200; // Conservative batch size for low memory
    private $file_batch_size = 50;
    private $chunk_size = 4096; // 4KB chunks for file reading
    private $max_depth = 2; // Default directory scanning (2 levels deep for performance)
    private $scan_depth_override = null; // Allow overriding scan depth

    /**
     * Set scan depth override (for advanced/deep scanning)
     */
    public function set_scan_depth($depth) {
        $this->scan_depth_override = (int)$depth;
        clean_sweep_log_message("DEBUG: CoreMalwareScanner - set_scan_depth called with depth: {$depth}, override now: {$this->scan_depth_override}");
    }

    /**
     * Reset scan depth to default
     */
    public function reset_scan_depth() {
        $this->scan_depth_override = null;
    }

    /**
     * Enhanced database scanning with Smart Tiered approach (Tier 1)
     */
    public function scan_database($progress_callback = null) {
        $results = [
            'wp_posts' => [],
            'wp_options' => [],
            'wp_comments' => [],
            'wp_postmeta' => [],
            'wp_users' => [],
            'total_scanned' => 0,
            'threats_found' => 0
        ];

        clean_sweep_log_message("=== Starting enhanced database malware scan (Tier 1) ===");

        // Scan high-risk areas efficiently
        $results = $this->scan_critical_posts($results, $progress_callback);
        $results = $this->scan_critical_options($results, $progress_callback);
        $results = $this->scan_high_risk_postmeta($results, $progress_callback);
        $results = $this->scan_user_data($results, $progress_callback);
        $results = $this->scan_comments($results, $progress_callback);

        $results['threats_found'] = count($results['wp_posts']) + count($results['wp_options']) +
                                   count($results['wp_comments']) + count($results['wp_postmeta']) +
                                   count($results['wp_users']);

        clean_sweep_log_message("Enhanced database scan completed. Threats found: {$results['threats_found']}, Total scanned: {$results['total_scanned']}");

        return $results;
    }

    /**
     * Smart scanning of critical post content
     */
    private function scan_critical_posts($results, $progress_callback = null) {
        global $wpdb;

        // Scan posts for malware - focus on published/draft posts
        $query = "SELECT ID, post_title, post_content, post_excerpt FROM {$wpdb->posts}
                 WHERE post_status IN ('publish', 'draft', 'private', 'pending')
                 AND (LENGTH(post_content) > 0 OR LENGTH(post_title) > 0 OR LENGTH(post_excerpt) > 0)
                 LIMIT 500";

        $posts = $wpdb->get_results($query);
        $results['total_scanned'] += count($posts);

        foreach ($posts as $post) {
            $content = $post->post_content . ' ' . $post->post_title . ' ' . $post->post_excerpt;

            $threats = $this->scan_content($content, 'wp_posts');
            if (!empty($threats)) {
                foreach ($threats as $threat) {
                    $threat['post_id'] = $post->ID;
                    $results['wp_posts'][] = $threat;
                }
            }
        }

        if ($progress_callback) {
            $progress_callback(count($posts), count($posts), "Scanning critical posts");
        }

        return $results;
    }

    /**
     * Smart scanning of critical WordPress options
     */
    private function scan_critical_options($results, $progress_callback = null) {
        global $wpdb;

        // Separate exact option names from patterns
        $exact_options = [
            'active_plugins',
            'cron',
            'recently_activated',
            'rewrite_rules'
        ];

        $pattern_options = [
            'theme_mods_%',
            'widget_%',
            '_transient_%',
            '_site_transient_%'
        ];

        $total_items = count($exact_options) + count($pattern_options);
        $current_item = 0;

        // Scan exact option names
        foreach ($exact_options as $option_name) {
            $current_item++;
            if ($progress_callback) {
                $progress_callback($current_item, $total_items, "Scanning {$option_name}");
            }

            $results_sql = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options}
                     WHERE option_name = %s AND LENGTH(option_value) > 0
                     LIMIT 10",
                    $option_name
                )
            );

            foreach ($results_sql as $row) {
                // Skip legitimate base64 data (images, etc.)
                if ($this->is_legitimate_option($row->option_name, $row->option_value)) {
                    continue;
                }

                $threats = $this->scan_content($row->option_value, 'wp_options');
                if (!empty($threats)) {
                    foreach ($threats as $threat) {
                        $threat['option_name'] = $row->option_name;
                        $results['wp_options'][] = $threat;
                    }
                }
            }
        }

        // Scan option patterns
        foreach ($pattern_options as $pattern) {
            $current_item++;
            if ($progress_callback) {
                $progress_callback($current_item, $total_items, "Scanning {$pattern}");
            }

            $results_sql = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options}
                     WHERE option_name LIKE %s AND LENGTH(option_value) > 0
                     LIMIT 50",
                    $pattern
                )
            );

            foreach ($results_sql as $row) {
                // Skip legitimate base64 data (images, etc.)
                if ($this->is_legitimate_option($row->option_name, $row->option_value)) {
                    continue;
                }

                $threats = $this->scan_content($row->option_value, 'wp_options');
                if (!empty($threats)) {
                    foreach ($threats as $threat) {
                        $threat['option_name'] = $row->option_name;
                        $results['wp_options'][] = $threat;
                    }
                }
            }
        }

        $results['total_scanned'] += 100; // Approximate count since we're using patterns
        return $results;
    }

    /**
     * Check if an option is legitimate to avoid false positives
     */
    private function is_legitimate_option($option_name, $option_value) {
        // Skip legitimate base64 images
        if (preg_match('/^data:image\/(png|jpg|jpeg|gif|svg);base64,/', $option_value)) {
            return true;
        }

        // Skip known legitimate large options
        if ($option_name === 'cron' && is_string($option_value) && strlen($option_value) > 10000) {
            return true; // Cron can be large serialized data
        }

        return false;
    }

    /**
     * Scan high-risk postmeta entries
     */
    private function scan_high_risk_postmeta($results, $progress_callback = null) {
        global $wpdb;

        // Only scan meta_keys known for malware abuses
        $suspicious_keys = [
            '_edit_last',
            '_wp_page_template',
            'custom_css',
            'header_scripts',
            'footer_scripts',
            '_wp_attached_file', // PHP file uploads
            'content_filters',
            'post_filters'
        ];

        $placeholders = '(' . implode(',', array_fill(0, count($suspicious_keys), '%s')) . ')';
        $query = $wpdb->prepare(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key IN $placeholders AND LENGTH(meta_value) > 0
             LIMIT 200",
            ...$suspicious_keys
        );

        $meta_results = $wpdb->get_results($query);
        $results['total_scanned'] += count($meta_results);

        foreach ($meta_results as $row) {
            $threats = $this->scan_content($row->meta_value, 'wp_postmeta');
            if (!empty($threats)) {
                foreach ($threats as $threat) {
                    $threat['post_id'] = $row->post_id;
                    $threat['meta_key'] = $row->meta_key;
                    $results['wp_postmeta'][] = $threat;
                }
            }
        }

        if ($progress_callback) {
            $progress_callback(count($meta_results), count($meta_results), "Scanning high-risk postmeta");
        }

        return $results;
    }

    /**
     * Scan user data for malicious links and patterns
     */
    private function scan_user_data($results, $progress_callback = null) {
        global $wpdb;

        // Focus on fields that commonly contain malicious data
        $users = $wpdb->get_results(
            "SELECT ID, user_url, user_email FROM {$wpdb->users}
             WHERE LENGTH(user_url) > 0 OR LENGTH(user_email) > 0
             LIMIT 100"
        );

        $results['total_scanned'] += count($users);

        // Scan for malware patterns (not just phishing - actual code execution)
        $malware_patterns = [
            '/<script/i', // Script injection
            '/javascript:/i', // JavaScript URLs
            '/vbscript:/i', // VBScript
            '/data:/i', // Data URLs that might contain JS
            '/onclick|onload|onerror/i', // Event handlers
        ];

        foreach ($users as $user) {
            $content = $user->user_url . ' ' . $user->user_email;

            foreach ($malware_patterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    $results['wp_users'][] = [
                        'pattern' => $pattern,
                        'match' => substr($content, 0, 100),
                        'user_id' => $user->ID,
                        'table' => 'wp_users',
                        'content_preview' => $content
                    ];
                }
            }
        }

        if ($progress_callback) {
            $progress_callback(count($users), count($users), "Scanning user data");
        }

        return $results;
    }

    /**
     * Enhanced scan of comments (content + author URLs)
     */
    private function scan_comments($results, $progress_callback = null) {
        global $wpdb;

        $comments = $wpdb->get_results(
            "SELECT comment_ID, comment_content, comment_author_url FROM {$wpdb->comments}
             WHERE comment_approved = '1' AND (LENGTH(comment_content) > 0 OR LENGTH(comment_author_url) > 0)
             LIMIT 300"
        );

        $results['total_scanned'] += count($comments);

        foreach ($comments as $comment) {
            $content = $comment->comment_content . ' ' . $comment->comment_author_url;

            $threats = $this->scan_content($content, 'wp_comments');
            if (!empty($threats)) {
                foreach ($threats as $threat) {
                    $threat['comment_id'] = $comment->comment_ID;
                    $results['wp_comments'][] = $threat;
                }
            }
        }

        if ($progress_callback) {
            $progress_callback(count($comments), count($comments), "Scanning comments");
        }

        return $results;
    }

    /**
     * Scan files for malware signatures
     */
    public function scan_files($folder_path = null, $progress_callback = null) {
        $results = [
            'wp_config' => [],
            'wp_content' => [],
            'total_files_scanned' => 0,
            'file_threats_found' => 0
        ];

        clean_sweep_log_message("=== Starting file malware scan ===" . ($folder_path ? " (Folder: {$folder_path})" : " (Full content)"));
        clean_sweep_log_message("DEBUG: scan_files called - max_depth: {$this->max_depth}, override: " . ($this->scan_depth_override ?? 'null'));

        // If a specific folder is provided, scan only that folder
        if ($folder_path) {
            // Validate and normalize the folder path
            $folder_path = $this->validate_and_normalize_folder_path($folder_path);
            if (!$folder_path) {
                clean_sweep_log_message("Invalid folder path provided for scanning", 'error');
                return $results; // Return empty results if invalid
            }

            clean_sweep_log_message("Scanning specific folder: {$folder_path}");

            // Scan the specified directory
            $folder_threats = $this->scan_specific_directory($folder_path, $progress_callback);
            $results['wp_content'] = $folder_threats['threats'];
            $results['total_files_scanned'] = $folder_threats['files_scanned'];
            $results['file_threats_found'] = count($folder_threats['threats']);
            $results['scan_path'] = $folder_path; // Store which folder was scanned
        } else {
            // Default behavior: scan wp-config.php and wp-content directory
            // Scan wp-config.php
            $results['wp_config'] = $this->scan_wp_config();
            if ($progress_callback) {
                $progress_callback(1, 1, "Scanning wp-config.php");
            }

            // Scan wp-content directory
            $content_threats = $this->scan_wp_content_directory($progress_callback);
            $results['wp_content'] = $content_threats['threats'];
            $results['total_files_scanned'] = $content_threats['files_scanned'];
            $results['file_threats_found'] = count($content_threats['threats']);
        }

        clean_sweep_log_message("File scan completed. Threats found: {$results['file_threats_found']}, Files scanned: {$results['total_files_scanned']}" . (isset($results['scan_path']) ? " (Path: {$results['scan_path']})" : ""));

        return $results;
    }

    /**
     * Scan wp-config.php file
     */
    private function scan_wp_config() {
        // Use original site path in recovery mode, otherwise use current ABSPATH
        $base_path = defined('ORIGINAL_ABSPATH') ? ORIGINAL_ABSPATH : ABSPATH;
        $wp_config_path = $base_path . 'wp-config.php';
        $threats = [];

        if (!file_exists($wp_config_path)) {
            return [['error' => 'wp-config.php not found', 'file' => $wp_config_path]];
        }

        // Check file integrity (known clean SHA-256 hash)
        $current_hash = hash_file('sha256', $wp_config_path);
        $clean_hash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'; // Placeholder

        if ($current_hash !== $clean_hash) {
            // Scan file content in chunks
            $handle = fopen($wp_config_path, 'r');
            if ($handle) {
                $file_content = '';
                while (!feof($handle)) {
                    $chunk = fread($handle, $this->chunk_size);
                    $file_content .= $chunk;

                    // Scan each chunk
                    $chunk_threats = $this->scan_content($chunk, 'wp_config');
                    if (!empty($chunk_threats)) {
                        foreach ($chunk_threats as $threat) {
                            $threat['file'] = $wp_config_path;
                            $threats[] = $threat;
                        }
                    }
                }
                fclose($handle);
            }
        }

        // Memory cleanup
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        return $threats;
    }

    /**
     * Scan wp-content directory recursively with memory optimization
     */
    private function scan_wp_content_directory($progress_callback = null) {
        // In recovery mode, scan the original site's wp-content directory
        $wp_content_path = ORIGINAL_WP_CONTENT_DIR;
        $results = [
            'threats' => [],
            'files_scanned' => 0
        ];

        if (!is_dir($wp_content_path)) {
            return $results;
        }

        // Get all files to scan (shallow directory traversal)
        $files_to_scan = $this->get_files_to_scan($wp_content_path);

        foreach (array_chunk($files_to_scan, $this->file_batch_size) as $batch) {
            foreach ($batch as $file_path) {
                $results['files_scanned']++;

                // Scan file in chunks
                $file_threats = $this->scan_file_in_chunks($file_path);
                if (!empty($file_threats)) {
                    $results['threats'] = array_merge($results['threats'], $file_threats);
                }

                if ($progress_callback && $results['files_scanned'] % 10 === 0) {
                    $progress_callback($results['files_scanned'], count($files_to_scan), "Scanning files ({$results['files_scanned']}/" . count($files_to_scan) . ")");
                }
            }

            // Memory cleanup between batches
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        return $results;
    }

    /**
     * Get list of files to scan (non-recursive, excludes certain directories)
     */
    private function get_files_to_scan($directory, $current_depth = 0) {
        $files = [];

        // Use scan depth override if set, otherwise use default max_depth
        $effective_max_depth = isset($this->scan_depth_override) ? $this->scan_depth_override : $this->max_depth;

        clean_sweep_log_message("DEBUG: get_files_to_scan - directory: {$directory}, current_depth: {$current_depth}, effective_max_depth: {$effective_max_depth}");

        if ($current_depth > $effective_max_depth) {
            clean_sweep_log_message("DEBUG: Depth exceeded - current_depth ({$current_depth}) > effective_max_depth ({$effective_max_depth})");
            return $files;
        }

        // Directories to exclude (including JS build dirs to avoid scanning .js files with PHP signatures)
        $exclude_dirs = ['cache', 'uploads/logs', 'node_modules', 'bower_components', 'dist', 'build', 'assets', 'js', 'requirejs'];

        $items = scandir($directory);
        if ($items === false) {
            return $files;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full_path = $directory . '/' . $item;

            // Skip excluded directories
            if (is_dir($full_path)) {
                $should_exclude = false;
                foreach ($exclude_dirs as $exclude) {
                    if (strpos($full_path, $exclude) !== false) {
                        $should_exclude = true;
                        break;
                    }
                }

                if (!$should_exclude) {
                    // Recursively scan subdirectory (limited depth)
                    $sub_files = $this->get_files_to_scan($full_path, $current_depth + 1);
                    $files = array_merge($files, $sub_files);
                }
                continue;
            }

            // Include files that can contain malware: PHP, JS, JSON, configs
            $extension = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($extension, ['php', 'js', 'json', 'conf', 'cfg', 'ini', 'config'])) {
                $files[] = $full_path;
            }
        }

        return $files;
    }

    /**
     * Scan a file in memory-efficient chunks
     */
    private function scan_file_in_chunks($file_path) {
        $threats = [];

        if (!file_exists($file_path) || !is_readable($file_path)) {
            return [['error' => 'File not accessible', 'file' => $file_path]];
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return [['error' => 'Cannot open file', 'file' => $file_path]];
        }

        $line_number = 0;
        $chunk_start_line = 0;

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $this->chunk_size);
                $lines = explode("\n", $chunk);
                $chunk_start_line = $line_number + 1;

                foreach ($lines as $line) {
                    $line_number++;
                    $chunk_threats = $this->scan_content($line, 'file');
                    if (!empty($chunk_threats)) {
                        foreach ($chunk_threats as $threat) {
                            $threat['file'] = $file_path;
                            $threat['line_number'] = $line_number;
                            $threats[] = $threat;
                        }
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $threats;
    }

    /**
     * Scan content against malware signatures (uses global signatures)
     */
    private function scan_content($content, $table) {
        require_once 'signatures.php';
        return clean_sweep_get_malware_signatures()->scan_content($content, $table);
    }

    /**
     * Validate and normalize folder path for security
     */
    private function validate_and_normalize_folder_path($input_path) {
        // Remove leading/trailing slashes and normalize
        $path = trim($input_path, '/');

        // Use original site ABSPATH in recovery mode for path validation
        $wordpress_root = defined('ORIGINAL_ABSPATH') ? ORIGINAL_ABSPATH : ABSPATH;

        // Convert relative paths to absolute paths within WordPress root
        if (!str_starts_with($path, $wordpress_root)) {
            // Allow ANY relative path within WordPress root (much more flexible)
            $full_path = $wordpress_root . $path;
        } else {
            $full_path = $path;
        }

        // Resolve any ../ or ./ references (prevents directory traversal)
        $full_path = realpath($full_path);
        if (!$full_path) {
            clean_sweep_log_message("Path does not exist: {$path}", 'error');
            return false;
        }

        // CRITICAL Security: Ensure path is within WordPress root
        if (!str_starts_with($full_path, $wordpress_root)) {
            clean_sweep_log_message("Rejected scan path: {$full_path} - outside WordPress root", 'warning');
            return false;
        }

        // Ensure it's a directory or readable file
        if (!is_dir($full_path) && !is_file($full_path)) {
            clean_sweep_log_message("Path is neither a valid directory nor readable file: {$full_path}", 'error');
            return false;
        }

        // Additional security: Ensure it's readable (we need to scan it)
        if (!is_readable($full_path)) {
            clean_sweep_log_message("Path is not readable: {$full_path}", 'error');
            return false;
        }

        return $full_path;
    }

    /**
     * Scan a specific directory or individual file for threats
     */
    private function scan_specific_directory($path, $progress_callback = null) {
        $results = [
            'threats' => [],
            'files_scanned' => 0
        ];

        // Handle individual files
        if (is_file($path)) {
            clean_sweep_log_message("Scanning individual file: {$path}");
            $results['files_scanned'] = 1;
            $file_threats = $this->scan_file_in_chunks($path);
            if (!empty($file_threats)) {
                $results['threats'] = $file_threats;
            }
            if ($progress_callback) {
                $progress_callback(1, 1, "Scanning file: " . basename($path));
            }
            clean_sweep_log_message("File scan completed. Threats found: " . count($results['threats']));
            return $results;
        }

        // Handle directories
        if (!is_dir($path)) {
            clean_sweep_log_message("Path is neither a valid directory nor file: {$path}", 'error');
            return $results;
        }

        clean_sweep_log_message("Scanning directory: {$path}");

        // Get all files to scan from this specific directory
        $files_to_scan = $this->get_files_to_scan($path, 0); // Start with depth 0 for this specific directory

        clean_sweep_log_message("Found " . count($files_to_scan) . " files to scan in directory: {$path}");

        if (empty($files_to_scan)) {
            clean_sweep_log_message("No scannable files (PHP/JS/JSON/CONFIG) found in directory: {$path}", 'warning');
            return $results;
        }

        foreach (array_chunk($files_to_scan, $this->file_batch_size) as $batch) {
            foreach ($batch as $file_path) {
                $results['files_scanned']++;

                // Scan file in chunks
                $file_threats = $this->scan_file_in_chunks($file_path);
                if (!empty($file_threats)) {
                    $results['threats'] = array_merge($results['threats'], $file_threats);
                }

                if ($progress_callback && $results['files_scanned'] % 10 === 0) {
                    $progress_callback($results['files_scanned'], count($files_to_scan),
                        "Scanning files ({$results['files_scanned']}/" . count($files_to_scan) . ") in " . basename($path));
                }
            }

            // Memory cleanup between batches
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        clean_sweep_log_message("Directory scan completed. Files scanned: {$results['files_scanned']}, Threats found: " . count($results['threats']));

        return $results;
    }

    /**
     * Run complete malware scan (database + files)
     */
    public function scan_all($progress_callback = null) {
        $database_results = $this->scan_database($progress_callback);
        $file_results = $this->scan_files(null, $progress_callback);  // Fixed parameter order

        return [
            'database' => $database_results,
            'files' => $file_results,
            'summary' => [
                'total_scanned' => $database_results['total_scanned'],
                'database_threats' => $database_results['threats_found'],
                'file_threats' => $file_results['file_threats_found'],
                'files_scanned' => $file_results['total_files_scanned'],
                'total_threats' => $database_results['threats_found'] + $file_results['file_threats_found']
            ]
        ];
    }
}
