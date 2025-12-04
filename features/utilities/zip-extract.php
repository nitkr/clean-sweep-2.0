<?php
/**
 * Handle WordPress plugin/theme installation using WordPress's built-in upgraders
 * Option B: WordPress is already loaded in recovery mode, just use it!
 */
function clean_sweep_wordpress_package_install($extract_path) {
    clean_sweep_log_message("🎯 Using WordPress package installer (Option B architecture)", 'info');
    clean_sweep_log_message("   → WordPress is already loaded from: " . (defined('ABSPATH') ? ABSPATH : 'N/A'), 'info');
    clean_sweep_log_message("   → Extraction target: $extract_path", 'info');

global $wp_filesystem;

// Initialize WP_Filesystem (load required file if not already included)
if (empty($wp_filesystem)) {
    // Load file.php if not loaded
    if (!function_exists('request_filesystem_credentials')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    WP_Filesystem();
    clean_sweep_log_message("✅ WP_Filesystem initialized", 'info');
}
    // Verify upgrader classes are available (should be loaded from wp-settings.php in recovery mode)
    if (!class_exists('Plugin_Upgrader')) {
        clean_sweep_log_message("⚠️ Plugin_Upgrader not loaded, loading now...", 'warning');
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
    }

    if (!class_exists('Theme_Upgrader')) {
        clean_sweep_log_message("⚠️ Theme_Upgrader not loaded, loading now...", 'warning');
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
    }

    clean_sweep_log_message("✅ WordPress upgrader classes available", 'info');

    // Check if WordPress classes are available for plugin/theme installation AFTER loading classes
if (!class_exists('WP_Upgrader') || !class_exists('Plugin_Upgrader') || !class_exists('Theme_Upgrader')) {
    clean_sweep_log_message("WordPress upgrader classes not available in recovery mode", 'warning');
    return ['success' => false, 'message' => 'WordPress upgrader not available in recovery mode'];
}

    $file_count = count($_FILES['zip_files']['name']);
    $results = [
        'total_files' => $file_count,
        'successful' => [],
        'failed' => [],
        'installer_type' => $extract_path === 'wp-content/plugins' ? 'Plugin' : 'Theme'
    ];

    clean_sweep_log_message("Using WordPress {$results['installer_type']} installer for $extract_path");

    /**
     * Custom upgrader skin to redirect WordPress installer messages to Clean Sweep logging
     * Defined here after WordPress files are loaded to ensure WP_Upgrader_Skin is available
     */
    class CleanSweep_Upgrader_Skin extends WP_Upgrader_Skin {
    private $filename;

    public function __construct($filename = '') {
        parent::__construct();
        $this->filename = $filename;
    }

    public function feedback($string, ...$args) {
        if (!empty($args)) {
            $string = vsprintf($string, $args);
        }

        // Log WordPress installer progress messages
        clean_sweep_log_message("WordPress installer: $string", 'info');

        // Enhance message with filename context for clarity
        $context_prefix = $this->filename ? '[' . htmlspecialchars($this->filename) . '] ' : '';
        $enhanced_message = $context_prefix . $string;

        // Display progress messages to user in real-time (like other Clean Sweep progress)
        if (!defined('WP_CLI') || !WP_CLI) {
            echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:5px;border-radius:3px;margin:5px 0;color:#0066cc;">';
            echo '<small>📦 ' . htmlspecialchars($enhanced_message) . '</small>';
            echo '</div>';
            ob_flush();
            flush();
        }
    }

    public function footer() {
            // Suppress footer output for clean integration
        }
    }

    // Process each uploaded file
    for ($i = 0; $i < $file_count; $i++) {
        $file_name = $_FILES['zip_files']['name'][$i];
        $file_tmp = $_FILES['zip_files']['tmp_name'][$i];
        $file_error = $_FILES['zip_files']['error'][$i];

        if (!defined('WP_CLI') || !WP_CLI) {
            $progress = round(($i + 1) / $file_count * 100);
            echo '<script>updateProgress(' . ($i + 1) . ', ' . $file_count . ', "Installing: ' . addslashes($file_name) . '");</script>';
            ob_flush();
            flush();
        }

        // Check for upload errors
        if ($file_error !== UPLOAD_ERR_OK) {
            $error_msg = "Upload error for $file_name: $file_error";
            clean_sweep_log_message($error_msg, 'error');
            $results['failed'][] = ['file' => $file_name, 'error' => $error_msg];
            continue;
        }

        // Validate file type
        if (!preg_match('/\.zip$/i', $file_name)) {
            $error_msg = "Invalid file type: $file_name";
            clean_sweep_log_message($error_msg, 'error');
            $results['failed'][] = ['file' => $file_name, 'error' => $error_msg];
            continue;
        }

        // Use WordPress's install method with force clear option for malware removal
        clean_sweep_log_message("Force installing $file_name using WordPress {$results['installer_type']} installer (malware removal mode)");

        if ($extract_path === 'wp-content/plugins') {
            // Hook to force clear destination (malware removal - ensure complete replacement)
            add_filter('upgrader_package_options', function($options) {
                $options['clear_destination'] = true;           // Delete existing directory contents
                $options['abort_if_destination_exists'] = false; // Don't abort, just delete and replace
                return $options;
            });

            // SIMPLE RETURN VALUE CHECKING - Using WordPress's direct API
            $skin = new CleanSweep_Upgrader_Skin($file_name);
            $upgrader = new Plugin_Upgrader($skin);
            $result = $upgrader->install($file_tmp);

            if (is_wp_error($result)) {
                // WordPress clearly indicates FAILURE
                $error_msg = $result->get_error_message();
                clean_sweep_log_message("Plugin installation failed for $file_name: $error_msg", 'error');
                $results['failed'][] = [
                    'file' => $file_name,
                    'error' => $error_msg,
                    'action' => 'install'
                ];
            } elseif ($result === true) {
                // WordPress clearly indicates SUCCESS - install() returns true
                clean_sweep_log_message("Successfully force-installed plugin (complete replacement): $file_name");
                $results['successful'][] = $file_name;
            } else {
                // Edge cases like null or other unexpected returns
                clean_sweep_log_message("Plugin installation result unclear for $file_name", 'warning');
                $results['failed'][] = [
                    'file' => $file_name,
                    'error' => 'Installation result unclear',
                    'action' => 'install'
                ];
            }
        } elseif ($extract_path === 'wp-content/themes') {
            // Hook to force clear destination (malware removal - ensure complete replacement)
            add_filter('upgrader_package_options', function($options) {
                $options['clear_destination'] = true;           // Delete existing directory contents
                $options['abort_if_destination_exists'] = false; // Don't abort, just delete and replace
                return $options;
            });

            // SIMPLE RETURN VALUE CHECKING - Using WordPress's direct API
            $skin = new CleanSweep_Upgrader_Skin($file_name);
            $upgrader = new Theme_Upgrader($skin);
            $result = $upgrader->install($file_tmp);

            if (is_wp_error($result)) {
                // WordPress clearly indicates FAILURE
                $error_msg = $result->get_error_message();
                clean_sweep_log_message("Theme installation failed for $file_name: $error_msg", 'error');
                $results['failed'][] = [
                    'file' => $file_name,
                    'error' => $error_msg,
                    'action' => 'install'
                ];
            } elseif ($result === true) {
                // WordPress clearly indicates SUCCESS - install() returns true
                clean_sweep_log_message("Successfully force-installed theme (complete replacement): $file_name");
                $results['successful'][] = $file_name;
            } else {
                // Edge cases like null or other unexpected returns
                clean_sweep_log_message("Theme installation result unclear for $file_name", 'warning');
                $results['failed'][] = [
                    'file' => $file_name,
                    'error' => 'Installation result unclear',
                    'action' => 'install'
                ];
            }
        }

        if (!defined('WP_CLI') || !WP_CLI) {
            // Custom messages only for failures - WordPress handles success messages
            if (!empty($results['failed']) && end($results['failed'])['file'] === $file_name) {
                // This file failed - show error message from results
                $failed_item = end($results['failed']);
                $error_message = $failed_item['error'] ?? 'Installation failed';
                echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:10px;border-radius:4px;margin:10px 0;color:#721c24;">';
                echo '<strong>❌ Failed:</strong> ' . htmlspecialchars($file_name) . ' - ' . htmlspecialchars($error_message);
                echo '</div>';
            }
            // Success messages are handled by WordPress's native feedback
        }
    }

    $success_count = count($results['successful']);
    $fail_count = count($results['failed']);

    clean_sweep_log_message("WordPress installer batch completed. Success: $success_count, Failed: $fail_count");

    // Display final results
    if (!defined('WP_CLI') || !WP_CLI) {
        echo '<h2>⚙️ WordPress ' . $results['installer_type'] . ' Installation Complete</h2>';
        echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
        echo '<h3>📊 Installation Results Summary</h3>';
        echo '<div style="display:flex;gap:20px;margin:15px 0;">';
        echo '<div style="background:#d4edda;color:#155724;padding:10px;border-radius:4px;text-align:center;min-width:80px;">';
        echo '<div style="font-size:24px;font-weight:bold;">' . $success_count . '</div>';
        echo '<div style="font-size:12px;">Successful</div>';
        echo '</div>';
        echo '<div style="background:#f8d7da;color:#721c24;padding:10px;border-radius:4px;text-align:center;min-width:80px;">';
        echo '<div style="font-size:24px;font-weight:bold;">' . $fail_count . '</div>';
        echo '<div style="font-size:12px;">Failed</div>';
        echo '</div>';
        echo '<div style="background:#f8f9fa;color:#666;padding:10px;border-radius:4px;text-align:center;min-width:80px;">';
        echo '<div style="font-size:24px;font-weight:bold;">' . $file_count . '</div>';
        echo '<div style="font-size:12px;">Total</div>';
        echo '</div>';
        echo '</div>';

        echo '<p><strong>Installation Method:</strong> WordPress ' . $results['installer_type'] . ' Upgrader (proper activation and management)</p>';
        echo '<p><strong>Benefits:</strong> Automatic directory naming, dependency checking, and activation</p>';

        echo '<button class="back-to-menu-btn visible" onclick="window.location.href = window.location.pathname">⬅️ Back to Main Menu</button>';

        echo '</div>';
    } else {
        echo "\n⚙️ WORDPRESS " . strtoupper($results['installer_type']) . " INSTALLATION COMPLETE\n";
        echo str_repeat("=", 50) . "\n";
        echo "Total files: $file_count\n";
        echo "Successful: $success_count\n";
        echo "Failed: $fail_count\n";
        echo str_repeat("=", 50) . "\n";
    }

    return [
        'success' => $fail_count === 0,
        'total_files' => $file_count,
        'successful' => $results['successful'],
        'failed' => $results['failed'],
        'extract_path' => $extract_path,
        'installer_type' => $results['installer_type']
    ];
}

/**
 * Check if a directory path is safe to delete during ZIP extraction
 * Only allows deletion of WordPress-related directories to prevent accidents
 */
function clean_sweep_is_safe_zip_replace_directory($extract_path, $target_dir) {
    // Get relative path from WordPress root
    $relative_path = str_replace(ABSPATH, '', $target_dir);

    // Allow replacement of WordPress core directories
    $safe_core_paths = [
        'wordpress',
        'wp-admin',
        'wp-includes',
        'wp-content/themes',
        'wp-content/plugins'
    ];

    // Allow replacement of specific WordPress subdirectories
    foreach ($safe_core_paths as $safe_path) {
        if (strpos($relative_path, $safe_path) === 0 ||
            $relative_path === $safe_path) {
            return true;
        }
    }

    // Allow replacement in wp-content subdirectories (themes, plugins, etc.)
    if (strpos($relative_path, 'wp-content/') === 0) {
        return true;
    }

    // Special case: complete WordPress installations
    if (basename($target_dir) === 'wordpress' ||
        strpos(basename($target_dir), 'wordpress') === 0) {
        return true;
    }

    // Default: not safe to replace
    return false;
}

/**
 * Detect the real WordPress site root directory for Option B architecture
 * Used when ABSPATH points to /core/fresh/ but operations need to target real site
 *
 * @return string Real WordPress site root path with trailing slash
 */
function clean_sweep_detect_real_site_root() {
    // Try to find wp-config.php by walking up from current directory
    $current_dir = dirname(__DIR__); // utilities/ directory
    $max_levels = 5;

    for ($i = 0; $i < $max_levels; $i++) {
        $config_path = $current_dir . '/wp-config.php';
        if (file_exists($config_path)) {
            return rtrim($current_dir, '/') . '/';
        }
        $current_dir = dirname($current_dir);
    }

    // Fallback: assume we're in wp-content/plugins/ structure
    $current_dir = dirname(dirname(dirname(__DIR__))); // Go up 3 levels from utilities/
    return rtrim($current_dir, '/') . '/';
}

/**
 * Execute ZIP file extraction for multiple files with clean replacement
 */
function clean_sweep_execute_zip_extraction() {
    // DEBUG: Check /core/fresh state before any processing
    // Use absolute path from FRESH_DIR_ABSOLUTE constant (set before WordPress loads)
    $fresh_dir_entry = defined('FRESH_DIR_ABSOLUTE') ? FRESH_DIR_ABSOLUTE : __DIR__ . '/../core/fresh';
    $file_php_entry = $fresh_dir_entry . '/wp-admin/includes/file.php';
    clean_sweep_log_message("DEBUG: At execute_zip_extraction start - /core/fresh exists: " . (is_dir($fresh_dir_entry) ? 'YES' : 'NO'), 'debug');
    clean_sweep_log_message("DEBUG: At execute_zip_extraction start - file.php exists: " . (file_exists($file_php_entry) ? 'YES' : 'NO'), 'debug');
    clean_sweep_log_message("DEBUG: Using FRESH_DIR_ABSOLUTE in execute_zip_extraction: " . (defined('FRESH_DIR_ABSOLUTE') ? 'YES' : 'NO'), 'debug');

    clean_sweep_log_message("=== ZIP File Extraction Started ===");

    // Check if files were uploaded
    if (!isset($_FILES['zip_files']) || !is_array($_FILES['zip_files']['name'])) {
        clean_sweep_log_message("No files uploaded or invalid upload format", 'error');

        if (!defined('WP_CLI') || !WP_CLI) {
            echo '<h2>📁 ZIP Extraction Failed</h2>';
            echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:20px;border-radius:4px;margin:20px 0;color:#721c24;">';
            echo '<h3>❌ Upload Error</h3>';
            echo '<p>No ZIP files were uploaded. Please select files to extract.</p>';
            echo '</div>';
        }

        return ['success' => false, 'message' => 'No files uploaded'];
    }

    $file_count = count($_FILES['zip_files']['name']);
    clean_sweep_log_message("Processing $file_count ZIP file(s) for extraction");

    // Get extraction path
    $extract_path = isset($_POST['extract_path']) ? $_POST['extract_path'] : 'wp-content';
    // Remove leading slash to ensure relative path from WordPress root
    $extract_path = ltrim($extract_path, '/');
    // Use real site root detection for Option B architecture compatibility
    $base_path = clean_sweep_detect_real_site_root();
    $full_extract_path = $base_path . $extract_path;

    clean_sweep_log_message("Extracting files to: $full_extract_path");
    clean_sweep_log_message("Extract path type: $extract_path");

    // Route to appropriate installer based on destination
    $use_wordpress_installer = ($extract_path === 'wp-content/plugins' || $extract_path === 'wp-content/themes');

    if ($use_wordpress_installer) {
        clean_sweep_log_message("Using WordPress installer for $extract_path");
        return clean_sweep_wordpress_package_install($extract_path);
    } else {
        clean_sweep_log_message("Using standard ZIP extraction for $extract_path");

        // Ensure extraction directory exists - use PHP mkdir as fallback
        if (!is_dir($full_extract_path)) {
            if (!mkdir($full_extract_path, 0755, true)) {
                clean_sweep_log_message("Failed to create extraction directory: $full_extract_path", 'error');

                if (!defined('WP_CLI') || !WP_CLI) {
                    echo '<h2>📁 ZIP Extraction Failed</h2>';
                    echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:20px;border-radius:4px;margin:20px 0;color:#721c24;">';
                    echo '<h3>❌ Directory Error</h3>';
                    echo '<p>Failed to create extraction directory.</p>';
                    echo '</div>';
                }

                return ['success' => false, 'message' => 'Failed to create extraction directory'];
            }
        }

        $results = [
            'total_files' => $file_count,
            'successful' => [],
            'failed' => []
        ];

        // Handle pre-extraction malware removal if enabled
        if (isset($_POST['enable_malware_removal']) && isset($_POST['delete_paths']) && !empty($_POST['delete_paths'])) {
            $delete_paths = array_map('trim', explode("\n", $_POST['delete_paths']));

            clean_sweep_log_message("=== PRE-EXTRACTION MALWARE REMOVAL STARTED ===");
            clean_sweep_log_message("Processing " . count($delete_paths) . " paths for deletion");

            $deleted_count = 0;
            $skipped_count = 0;

            foreach ($delete_paths as $path) {
                if (empty($path)) continue; // Skip empty lines

                $full_path = $full_extract_path . '/' . $path;

                // SECURITY: Ensure path is within extraction directory to prevent directory traversal
                $real_extract_path = realpath($full_extract_path);
                $real_full_path = realpath($full_path);

                if ($real_full_path === false) {
                    // Path doesn't exist, try to check if it's within allowed directory
                    $path_parts = explode('/', $path);
                    $allowed_base = basename($full_extract_path);

                    if (!empty($path_parts) && $path_parts[0] === $allowed_base) {
                        // Convert to absolute path for checking
                        $real_full_path = realpath($full_extract_path . '/' . implode('/', array_slice($path_parts, 1)));
                    }
                }

                if ($real_full_path && strpos($real_full_path, $real_extract_path) === 0) {
                    // Path is within extraction directory - safe to delete
                    if (file_exists($full_path) || file_exists($real_full_path)) {
                        $target_path = $real_full_path ?: $full_path;

                        if (is_dir($target_path)) {
                            // Delete directory recursively
                            if (clean_sweep_recursive_delete($target_path)) {
                                clean_sweep_log_message("Deleted directory: $path", 'info');
                                $deleted_count++;
                            } else {
                                clean_sweep_log_message("Failed to delete directory: $path", 'error');
                            }
                        } else {
                            // Delete file
                            if (unlink($target_path)) {
                                clean_sweep_log_message("Deleted file: $path", 'info');
                                $deleted_count++;
                            } else {
                                clean_sweep_log_message("Failed to delete file: $path", 'error');
                            }
                        }
                    } else {
                        clean_sweep_log_message("Path does not exist: $path", 'warning');
                    }
                } else {
                    // Path is outside extraction directory - skip for security
                    clean_sweep_log_message("Skipped dangerous path (outside extraction directory): $path", 'warning');
                    $skipped_count++;
                }
            }

            clean_sweep_log_message("Pre-extraction malware removal completed. Deleted: $deleted_count, Skipped: $skipped_count");
            clean_sweep_log_message("=== PRE-EXTRACTION MALWARE REMOVAL COMPLETED ===");
        }

        // Process each uploaded file with standard ZIP extraction
        for ($i = 0; $i < $file_count; $i++) {
            $file_name = $_FILES['zip_files']['name'][$i];
            $file_tmp = $_FILES['zip_files']['tmp_name'][$i];
            $file_error = $_FILES['zip_files']['error'][$i];

            if (!defined('WP_CLI') || !WP_CLI) {
                $progress = round(($i + 1) / $file_count * 100);
                echo '<script>updateProgress(' . ($i + 1) . ', ' . $file_count . ', "Extracting: ' . addslashes($file_name) . '");</script>';
                ob_flush();
                flush();
            }

            // Check for upload errors
            if ($file_error !== UPLOAD_ERR_OK) {
                $error_msg = "Upload error for $file_name: $file_error";
                clean_sweep_log_message($error_msg, 'error');
                $results['failed'][] = ['file' => $file_name, 'error' => $error_msg];

                if (!defined('WP_CLI') || !WP_CLI) {
                    echo '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:10px;border-radius:4px;margin:10px 0;color:#856404;">';
                    echo '<strong>⚠️ Skipped:</strong> ' . htmlspecialchars($file_name) . ' - Upload error';
                    echo '</div>';
                }
                continue;
            }

            // Validate file type
            if (!preg_match('/\.zip$/i', $file_name)) {
                $error_msg = "Invalid file type: $file_name";
                clean_sweep_log_message($error_msg, 'error');
                $results['failed'][] = ['file' => $file_name, 'error' => $error_msg];

                if (!defined('WP_CLI') || !WP_CLI) {
                    echo '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:10px;border-radius:4px;margin:10px 0;color:#856404;">';
                    echo '<strong>⚠️ Skipped:</strong> ' . htmlspecialchars($file_name) . ' - Invalid file type';
                    echo '</div>';
                }
                continue;
            }

            // Extract the ZIP file
            clean_sweep_log_message("Extracting ZIP file $file_name to $full_extract_path");
            $result = clean_sweep_unzip_file($file_tmp, $full_extract_path);

            if (is_wp_error($result)) {
                $error_msg = "Failed to extract $file_name: " . $result->get_error_message();
                clean_sweep_log_message($error_msg, 'error');
                $results['failed'][] = ['file' => $file_name, 'error' => $result->get_error_message()];

                if (!defined('WP_CLI') || !WP_CLI) {
                    echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:10px;border-radius:4px;margin:10px 0;color:#721c24;">';
                    echo '<strong>❌ Failed:</strong> ' . htmlspecialchars($file_name) . ' - ' . htmlspecialchars($result->get_error_message());
                    echo '</div>';
                }
            } else {
                clean_sweep_log_message("Successfully extracted: $file_name");
                $results['successful'][] = $file_name;

                if (!defined('WP_CLI') || !WP_CLI) {
                    echo '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:10px;border-radius:4px;margin:10px 0;color:#155724;">';
                    echo '<strong>✅ Extracted:</strong> ' . htmlspecialchars($file_name);
                    echo '</div>';
                }
            }
        }

        $success_count = count($results['successful']);
        $fail_count = count($results['failed']);

        clean_sweep_log_message("ZIP extraction batch completed. Success: $success_count, Failed: $fail_count");

        // Display final results
        if (!defined('WP_CLI') || !WP_CLI) {
            echo '<h2>📁 ZIP Extraction Batch Complete</h2>';
            echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
            echo '<h3>📊 Batch Results Summary</h3>';
            echo '<div style="display:flex;gap:20px;margin:15px 0;">';
            echo '<div style="background:#d4edda;color:#155724;padding:10px;border-radius:4px;text-align:center;min-width:80px;">';
            echo '<div style="font-size:24px;font-weight:bold;">' . $success_count . '</div>';
            echo '<div style="font-size:12px;">Successful</div>';
            echo '</div>';
            echo '<div style="background:#f8d7da;color:#721c24;padding:10px;border-radius:4px;text-align:center;min-width:80px;">';
            echo '<div style="font-size:24px;font-weight:bold;">' . $fail_count . '</div>';
            echo '<div style="font-size:12px;">Failed</div>';
            echo '</div>';
            echo '<div style="background:#f8f9fa;color:#666;padding:10px;border-radius:4px;text-align:center;min-width:80px;">';
            echo '<div style="font-size:24px;font-weight:bold;">' . $file_count . '</div>';
            echo '<div style="font-size:12px;">Total</div>';
            echo '</div>';
            echo '</div>';

            if ($success_count > 0) {
                echo '<p><strong>Extracted to:</strong> <code>' . htmlspecialchars($full_extract_path) . '</code></p>';
                echo '<p><strong>Note:</strong> Standard ZIP extraction - existing files overwritten but external files preserved.</p>';
            }

            echo '<button class="back-to-menu-btn visible" onclick="window.location.reload()">⬅️ Back to Main Menu</button>';

            echo '</div>';
        } else {
            echo "\n📁 ZIP EXTRACTION BATCH COMPLETE\n";
            echo str_repeat("=", 50) . "\n";
            echo "Total files: $file_count\n";
            echo "Successful: $success_count\n";
            echo "Failed: $fail_count\n";
            echo "Extracted to: $full_extract_path\n";
            echo str_repeat("=", 50) . "\n";
        }

        return [
            'success' => $fail_count === 0,
            'total_files' => $file_count,
            'successful' => $results['successful'],
            'failed' => $results['failed'],
            'extract_path' => $full_extract_path
        ];
    }
}
