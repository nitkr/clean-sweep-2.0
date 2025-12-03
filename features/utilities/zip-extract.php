<?php
/**
 * Clean Sweep - ZIP Extraction Feature
 *
 * Contains file upload and ZIP extraction functionality
 */

/**
 * Handle WordPress plugin/theme installation using WordPress's built-in upgraders
 */
function clean_sweep_wordpress_package_install($extract_path) {
    global $wp_filesystem;

    // Initialize filesystem from clean /core/fresh/ WordPress installation
    if (empty($wp_filesystem)) {
        $fresh_file_path = __DIR__ . '/../core/fresh/wp-admin/includes/file.php';
        clean_sweep_log_message("DEBUG: Attempting to load fresh file.php from: $fresh_file_path", 'debug');

        $real_path = realpath($fresh_file_path);
        clean_sweep_log_message("DEBUG: realpath() result: " . ($real_path ?: 'FALSE'), 'debug');

        if (!$real_path) {
            clean_sweep_log_message("DEBUG: realpath() failed for: $fresh_file_path", 'error');
            clean_sweep_log_message("DEBUG: __DIR__ is: " . __DIR__, 'debug');
            clean_sweep_log_message("DEBUG: getcwd() is: " . getcwd(), 'debug');
        } elseif (!file_exists($real_path)) {
            clean_sweep_log_message("DEBUG: file_exists() returned false for: $real_path", 'error');
        } elseif (!is_readable($real_path)) {
            clean_sweep_log_message("DEBUG: is_readable() returned false for: $real_path", 'error');
        } else {
            clean_sweep_log_message("DEBUG: File exists and is readable: $real_path", 'debug');
            require_once $real_path;
            if (function_exists('WP_Filesystem')) {
                WP_Filesystem();
                clean_sweep_log_message("DEBUG: WP_Filesystem() initialized successfully", 'debug');
            } else {
                clean_sweep_log_message("DEBUG: WP_Filesystem() function not available after require_once", 'error');
            }
        }
    }

    // Include required WordPress files for upgrader from clean /core/fresh/ installation
    $fresh_upgrader_path = __DIR__ . '/../core/fresh/wp-admin/includes/class-wp-upgrader.php';
    clean_sweep_log_message("DEBUG: Attempting to load fresh upgrader from: $fresh_upgrader_path", 'debug');

    $real_upgrader_path = realpath($fresh_upgrader_path);
    clean_sweep_log_message("DEBUG: upgrader realpath() result: " . ($real_upgrader_path ?: 'FALSE'), 'debug');

    if (!$real_upgrader_path) {
        clean_sweep_log_message("DEBUG: realpath() failed for upgrader: $fresh_upgrader_path", 'error');
    } elseif (!file_exists($real_upgrader_path)) {
        clean_sweep_log_message("DEBUG: upgrader file_exists() returned false for: $real_upgrader_path", 'error');
    } elseif (!is_readable($real_upgrader_path)) {
        clean_sweep_log_message("DEBUG: upgrader is_readable() returned false for: $real_upgrader_path", 'error');
    } else {
        clean_sweep_log_message("DEBUG: Upgrader file exists and is readable: $real_upgrader_path", 'debug');
        require_once $real_upgrader_path;
        clean_sweep_log_message("DEBUG: Upgrader class loaded, checking functions...", 'debug');
    }

    // Check if WordPress functions are available for plugin/theme installation AFTER loading classes
    if (!function_exists('WP_Upgrader') || !function_exists('Plugin_Upgrader')) {
        clean_sweep_log_message("WordPress upgrader functions not available in recovery mode", 'warning');
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
 * Execute ZIP file extraction for multiple files with clean replacement
 */
function clean_sweep_execute_zip_extraction() {
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
    // Use original site paths in recovery mode
    $base_path = defined('ORIGINAL_ABSPATH') ? ORIGINAL_ABSPATH : ABSPATH;
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
