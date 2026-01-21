<?php
/**
 * Clean Sweep Core Integrity Baseline Functions
 * These functions are defined globally for use throughout the application
 */

// Core integrity baseline management functions
if (!function_exists('clean_sweep_establish_core_baseline')) {
    function clean_sweep_establish_core_baseline($wp_version = null) {
        // Check if comprehensive monitoring is enabled
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $comprehensive_mode = isset($_SESSION['clean_sweep_comprehensive_baseline']) && $_SESSION['clean_sweep_comprehensive_baseline'];

        if ($comprehensive_mode) {
            return clean_sweep_establish_comprehensive_baseline($wp_version);
        } else {
            return clean_sweep_establish_core_only_baseline($wp_version);
        }
    }
}

if (!function_exists('clean_sweep_establish_core_only_baseline')) {
    function clean_sweep_establish_core_only_baseline($wp_version = null) {
        $baseline_file = dirname(__DIR__, 2) . '/backups/core_integrity_baseline.json';

        // Ensure backups directory exists
        $backups_dir = dirname($baseline_file);
        if (!is_dir($backups_dir)) {
            mkdir($backups_dir, 0755, true);
        }

        // Define critical WordPress core files to monitor
        $critical_files = [
            'wp-config.php',
            'wp-load.php',
            'wp-settings.php',
            'wp-admin/index.php',
            'wp-admin/admin.php',
            'wp-includes/version.php',
            'wp-includes/functions.php',
            'wp-includes/wp-db.php',
            '.htaccess',
            'index.php'
        ];

        // Define critical directories to monitor
        $critical_dirs = [
            'wp-admin',
            'wp-includes'
        ];

        $baseline = [
            'established_at' => time(),
            'wp_version' => $wp_version ?: clean_sweep_get_wordpress_version(),
            'files' => [],
            'directories' => []
        ];

        clean_sweep_log_message("🔍 Establishing persistent core integrity baseline", 'info');

                // Get real site root by finding wp-config.php (not fresh environment)
                $real_site_root = clean_sweep_detect_site_root();

                // Baseline critical files with SHA256 hashes
                foreach ($critical_files as $file) {
                    $file_path = $real_site_root . $file;
                    if (file_exists($file_path) && is_readable($file_path)) {
                        $baseline['files'][$file] = [
                            'hash' => hash_file('sha256', $file_path),
                            'size' => filesize($file_path),
                            'mtime' => filemtime($file_path),
                            'exists' => true
                        ];
                        clean_sweep_log_message("✓ Baslined core file: {$file} (path: {$file_path})", 'debug');
                    } else {
                        $baseline['files'][$file] = ['exists' => false];
                        clean_sweep_log_message("⚠️ Core file not found: {$file} (path: {$file_path})", 'debug');
                    }
                }

        // Baseline critical directories
        foreach ($critical_dirs as $dir) {
            $dir_path = $real_site_root . $dir;
            if (is_dir($dir_path)) {
                $php_files = glob($dir_path . '/*.php');
                $baseline['directories'][$dir] = [
                    'php_count' => count($php_files),
                    'exists' => true
                ];
                clean_sweep_log_message("✓ Baslined core directory: {$dir} ({$baseline['directories'][$dir]['php_count']} PHP files)", 'debug');
            } else {
                $baseline['directories'][$dir] = ['exists' => false];
                clean_sweep_log_message("⚠️ Core directory not found: {$dir}", 'debug');
            }
        }

        // Save baseline to file
        $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($baseline_file, $json) !== false) {
            chmod($baseline_file, 0644);
            clean_sweep_log_message("✅ Core integrity baseline established and saved", 'info');
            return true;
        } else {
            clean_sweep_log_message("❌ Failed to save core integrity baseline", 'error');
            return false;
        }
    }
}

if (!function_exists('clean_sweep_establish_comprehensive_baseline')) {
    function clean_sweep_establish_comprehensive_baseline($wp_version = null) {
        $baseline_file = dirname(__DIR__, 2) . '/backups/core_integrity_baseline.json';

        // Ensure backups directory exists
        $backups_dir = dirname($baseline_file);
        if (!is_dir($backups_dir)) {
            mkdir($backups_dir, 0755, true);
        }

        // Get real site root
        $real_site_root = clean_sweep_detect_site_root();

        $baseline = [
            'established_at' => time(),
            'wp_version' => $wp_version ?: clean_sweep_get_wordpress_version(),
            'mode' => 'comprehensive',
            'files' => [],
            'directories' => []
        ];

        clean_sweep_log_message("🔍 Establishing comprehensive integrity baseline (all WordPress files)", 'info');

        $total_files = 0;

        // Get ALL WordPress core files (replaces the limited critical files list)
        $all_core_files = clean_sweep_get_all_core_files($real_site_root);
        foreach ($all_core_files as $file_path) {
            $relative_path = str_replace($real_site_root, '', $file_path);
            if (file_exists($file_path) && is_readable($file_path)) {
                $baseline['files'][$relative_path] = [
                    'hash' => hash_file('sha256', $file_path),
                    'size' => filesize($file_path),
                    'mtime' => filemtime($file_path),
                    'exists' => true
                ];
                $total_files++;
            }
        }

        // Comprehensive root directory scanning - include ALL monitorable files in root
        clean_sweep_log_message("🔍 Comprehensive mode: Scanning root directory for all monitorable files", 'debug');

        if (is_dir($real_site_root)) {
            // Get all files directly in root directory (non-recursive)
            $root_files = scandir($real_site_root);
            if ($root_files !== false) {
                foreach ($root_files as $file) {
                    // Skip directories (they will be handled separately)
                    if ($file === '.' || $file === '..' || is_dir($real_site_root . $file)) {
                        continue;
                    }

                    $file_path = $real_site_root . $file;
                    $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

                    // Check if this is a monitorable file type
                    $monitorable_extensions = [
                        'php', 'js', 'css', 'json', 'svg',
                        'htaccess', 'htpasswd', 'conf', 'config', 'cfg', 'ini',
                        'txt', 'md', 'xml',
                        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'ico'
                    ];

                    $is_monitorable = in_array($file_extension, $monitorable_extensions) ||
                                     in_array($file, ['robots.txt', 'web.config', '.htaccess', '.htpasswd']);

                    if ($is_monitorable && file_exists($file_path) && is_readable($file_path)) {
                        $baseline['files'][$file] = [
                            'hash' => hash_file('sha256', $file_path),
                            'size' => filesize($file_path),
                            'mtime' => filemtime($file_path),
                            'exists' => true
                        ];
                        $total_files++;
                        clean_sweep_log_message("✓ Baslined root file: {$file}", 'debug');
                    }
                }
            }
        }

        // Comprehensive wp-content monitoring - ALL directories and files
        $wp_content_path = $real_site_root . 'wp-content';

        if (is_dir($wp_content_path)) {
            // Get ALL directories and subdirectories in wp-content (recursive)
            $wp_content_dirs = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($wp_content_path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    $relative_dir = str_replace($real_site_root, '', $item->getPathname());
                    $wp_content_dirs[] = $relative_dir;
                }
            }

            // Also include the wp-content root directory itself
            $wp_content_dirs[] = 'wp-content';

            // Remove duplicates and sort
            $wp_content_dirs = array_unique($wp_content_dirs);
            sort($wp_content_dirs);

            clean_sweep_log_message("🔍 Comprehensive mode: Found " . count($wp_content_dirs) . " wp-content directories to monitor", 'debug');

            // Scan ALL wp-content directories for monitorable files
            foreach ($wp_content_dirs as $dir) {
                $full_dir_path = $real_site_root . $dir;
                if (is_dir($full_dir_path)) {
                    // Exclude wp-content/uploads/ images for performance (too many files)
                    $exclude_patterns = ['wp-content/uploads/'];
                    $monitorable_files = clean_sweep_get_all_monitorable_files($full_dir_path, $exclude_patterns);

                    foreach ($monitorable_files as $file_path) {
                        $relative_path = str_replace($real_site_root, '', $file_path);
                        if (file_exists($file_path) && is_readable($file_path)) {
                            $baseline['files'][$relative_path] = [
                                'hash' => hash_file('sha256', $file_path),
                                'size' => filesize($file_path),
                                'mtime' => filemtime($file_path),
                                'exists' => true
                            ];
                            $total_files++;
                        }
                    }

                    // Track directory info with both PHP and total monitorable file counts
                    $php_files = clean_sweep_get_all_php_files($full_dir_path);
                    $baseline['directories'][$dir] = [
                        'php_count' => count($php_files),
                        'monitorable_count' => count($monitorable_files),
                        'exists' => true
                    ];
                } else {
                    $baseline['directories'][$dir] = ['exists' => false];
                }
            }
        }

        // Process core directories with same logic as wp-content (all monitorable files)
        $core_dirs = ['wp-admin', 'wp-includes'];
        foreach ($core_dirs as $dir) {
            $full_dir_path = $real_site_root . $dir;
            if (is_dir($full_dir_path)) {
                // Use same logic as wp-content directories - get all monitorable files
                $monitorable_files = clean_sweep_get_all_monitorable_files($full_dir_path, []);
                $php_files = clean_sweep_get_all_php_files($full_dir_path);

                // Add all monitorable files to baseline
                foreach ($monitorable_files as $file_path) {
                    $relative_path = str_replace($real_site_root, '', $file_path);
                    if (file_exists($file_path) && is_readable($file_path)) {
                        $baseline['files'][$relative_path] = [
                            'hash' => hash_file('sha256', $file_path),
                            'size' => filesize($file_path),
                            'mtime' => filemtime($file_path),
                            'exists' => true
                        ];
                        $total_files++;
                    }
                }

                // Track directory info with both PHP and total monitorable file counts
                $baseline['directories'][$dir] = [
                    'php_count' => count($php_files),
                    'monitorable_count' => count($monitorable_files),
                    'exists' => true
                ];
            } else {
                $baseline['directories'][$dir] = ['exists' => false];
            }
        }

        // Include ALL existing directories in root in the baseline (prevent false positives)
        // Exclude common development/security tool directories to avoid noise
        $excluded_dev_dirs = [
            'clean-sweep',     // Clean Sweep toolkit directory
            '.git',           // Git repository
            'node_modules',   // Node.js dependencies
            'vendor',         // Composer/PHP dependencies
            '.vscode',        // VS Code settings
            '.idea',          // PHPStorm/IntelliJ settings
            '__pycache__',    // Python cache
            '.pytest_cache',  // pytest cache
            'venv',           // Python virtual environment
            'env',            // Python virtual environment
            '.env',           // Environment files directory
            'logs',           // Log files directory (often auto-generated)
            'tmp',            // Temporary files
            'temp',           // Temporary files
            'cache',          // Cache directories
            '.DS_Store',      // macOS system files
            'Thumbs.db'       // Windows system files
        ];

        clean_sweep_log_message("🔍 Comprehensive mode: Including existing root directories in baseline (excluding dev tools)", 'debug');

        if (is_dir($real_site_root)) {
            // Get all items directly in root directory (non-recursive)
            $root_items = scandir($real_site_root);
            if ($root_items !== false) {
                foreach ($root_items as $item) {
                    // Skip special entries and files
                    if ($item === '.' || $item === '..' || !is_dir($real_site_root . $item)) {
                        continue;
                    }

                    // Skip excluded development/security tool directories
                    if (in_array($item, $excluded_dev_dirs)) {
                        clean_sweep_log_message("⏭️ Skipping excluded directory: {$item}", 'debug');
                        continue;
                    }

                    $dir_path = $real_site_root . $item;

                    // Get monitorable files in this directory
                    $monitorable_files = clean_sweep_get_all_monitorable_files($dir_path, []);
                    $php_files = clean_sweep_get_all_php_files($dir_path);

                    // Add all monitorable files to baseline
                    foreach ($monitorable_files as $file_path) {
                        $relative_path = str_replace($real_site_root, '', $file_path);
                        if (file_exists($file_path) && is_readable($file_path)) {
                            $baseline['files'][$relative_path] = [
                                'hash' => hash_file('sha256', $file_path),
                                'size' => filesize($file_path),
                                'mtime' => filemtime($file_path),
                                'exists' => true
                            ];
                            $total_files++;
                        }
                    }

                    // Include this directory in baseline to prevent false positives
                    $baseline['directories'][$item] = [
                        'php_count' => count($php_files),
                        'monitorable_count' => count($monitorable_files),
                        'exists' => true,
                        'baselined_at_creation' => true // Mark as existing at baseline time
                    ];

                    clean_sweep_log_message("✓ Baslined existing root directory: {$item} ({$baseline['directories'][$item]['monitorable_count']} monitorable files)", 'debug');
                }
            }
        }

        // Save comprehensive baseline
        $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($baseline_file, $json) !== false) {
            chmod($baseline_file, 0644);
            clean_sweep_log_message("✅ Comprehensive integrity baseline established with {$total_files} files", 'info');
            return true;
        } else {
            clean_sweep_log_message("❌ Failed to save comprehensive integrity baseline", 'error');
            return false;
        }
    }
}

if (!function_exists('clean_sweep_get_all_php_files')) {
    function clean_sweep_get_all_php_files($directory) {
        $php_files = [];

        if (!is_dir($directory)) {
            return $php_files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $php_files[] = $file->getRealPath();
            }
        }

        return $php_files;
    }
}

if (!function_exists('clean_sweep_get_all_monitorable_files')) {
    function clean_sweep_get_all_monitorable_files($directory, $exclude_patterns = []) {
        $monitorable_files = [];

        if (!is_dir($directory)) {
            return $monitorable_files;
        }

        // File extensions to monitor for integrity
        $monitorable_extensions = [
            // Executable/script files
            'php', 'js', 'css', 'json', 'svg',
            // Configuration files
            'htaccess', 'htpasswd', 'conf', 'config', 'cfg', 'ini',
            // Text files that can contain malware
            'txt', 'md', 'xml',
            // Image files (with smart filtering)
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'ico'
        ];

        // Special filenames to always monitor (regardless of extension)
        $special_files = [
            'robots.txt',
            'web.config',
            '.htaccess',
            '.htpasswd'
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $file_path = $file->getRealPath();
            $file_name = $file->getFilename();
            $extension = strtolower($file->getExtension());

            // Check exclude patterns (e.g., exclude wp-content/uploads/ images)
            $should_exclude = false;
            foreach ($exclude_patterns as $pattern) {
                if (strpos($file_path, $pattern) !== false) {
                    $should_exclude = true;
                    break;
                }
            }

            if ($should_exclude) {
                continue;
            }

            // Include files by extension or special filename
            if (in_array($extension, $monitorable_extensions) || in_array($file_name, $special_files)) {
                $monitorable_files[] = $file_path;
            }
        }

        return $monitorable_files;
    }
}

if (!function_exists('clean_sweep_get_all_core_files')) {
    function clean_sweep_get_all_core_files($site_root) {
        $core_files = [];

        // Define all WordPress core directories to scan
        $core_dirs = [
            'wp-admin',
            'wp-includes'
        ];

        // Define root-level core files (PHP files in WordPress root)
        $root_core_files = [
            'index.php',
            'wp-activate.php',
            'wp-blog-header.php',
            'wp-comments-post.php',
            'wp-cron.php',
            'wp-links-opml.php',
            'wp-load.php',
            'wp-login.php',
            'wp-mail.php',
            'wp-settings.php',
            'wp-signup.php',
            'wp-trackback.php',
            'xmlrpc.php'
        ];

        // Add root-level core PHP files
        foreach ($root_core_files as $file) {
            $file_path = $site_root . $file;
            if (file_exists($file_path) && is_readable($file_path)) {
                $core_files[] = $file_path;
            }
        }

        // Add all PHP files from core directories recursively
        foreach ($core_dirs as $dir) {
            $dir_path = $site_root . $dir;
            if (is_dir($dir_path)) {
                $php_files = clean_sweep_get_all_php_files($dir_path);
                $core_files = array_merge($core_files, $php_files);
            }
        }

        return array_unique($core_files);
    }
}

if (!function_exists('clean_sweep_get_wordpress_version')) {
    function clean_sweep_get_wordpress_version() {
        $site_root = clean_sweep_detect_site_root();
        $version_file = $site_root . 'wp-includes/version.php';

        if (file_exists($version_file) && is_readable($version_file)) {
            // Extract wp_version variable from the file
            $content = file_get_contents($version_file);
            if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $matches)) {
                return $matches[1];
            }
        }

        // Fallback: try to get from loaded WordPress if available
        if (defined('WP_VERSION')) {
            return WP_VERSION;
        }

        return 'unknown';
    }
}

if (!function_exists('clean_sweep_get_file_type_description')) {
    function clean_sweep_get_file_type_description($extension) {
        $descriptions = [
            // Executable/script files
            'php' => 'PHP script',
            'js' => 'JavaScript',
            'css' => 'CSS stylesheet',
            'json' => 'JSON configuration',
            'svg' => 'SVG vector image',

            // Configuration files
            'htaccess' => 'Apache configuration',
            'htpasswd' => 'Apache password',
            'conf' => 'configuration',
            'config' => 'configuration',
            'cfg' => 'configuration',
            'ini' => 'configuration',

            // Text files
            'txt' => 'text',
            'md' => 'markdown',
            'xml' => 'XML',

            // Image files
            'jpg' => 'JPEG image',
            'jpeg' => 'JPEG image',
            'png' => 'PNG image',
            'gif' => 'GIF image',
            'webp' => 'WebP image',
            'bmp' => 'bitmap image',
            'tiff' => 'TIFF image',
            'ico' => 'icon',

            // Special files (by filename)
            'robots.txt' => 'robots.txt',
            'web.config' => 'IIS configuration'
        ];

        return $descriptions[$extension] ?? $extension . ' file';
    }
}

if (!function_exists('clean_sweep_get_core_baseline')) {
    function clean_sweep_get_core_baseline() {
        $baseline_file = dirname(__DIR__, 2) . '/backups/core_integrity_baseline.json';

        if (!file_exists($baseline_file)) {
            return null; // No baseline established
        }

        $json = file_get_contents($baseline_file);
        if ($json === false) {
            return null;
        }

        $baseline = json_decode($json, true);
        return is_array($baseline) ? $baseline : null;
    }
}

if (!function_exists('clean_sweep_clear_core_baseline')) {
    function clean_sweep_clear_core_baseline() {
        $baseline_file = dirname(__DIR__, 2) . '/backups/core_integrity_baseline.json';

        if (file_exists($baseline_file)) {
            unlink($baseline_file);
            clean_sweep_log_message("🗑️ Core integrity baseline cleared", 'info');
            return true;
        }
        return false;
    }
}

// Re-infection detection function using persistent baseline
if (!function_exists('clean_sweep_check_for_reinfection')) {
    function clean_sweep_check_for_reinfection() {
        $violations = [];

        // Get persistent baseline
        $baseline = clean_sweep_get_core_baseline();

        if ($baseline === null) {
            // No baseline established - return empty (not an error)
            clean_sweep_log_message("ℹ️ No core integrity baseline established - skipping reinfection check", 'debug');
            return $violations;
        }

        // Get real site root (not fresh environment)
        $real_site_root = clean_sweep_detect_site_root();

        clean_sweep_log_message("🔍 Checking core integrity against persistent baseline", 'info');
        clean_sweep_log_message("🔍 Real site root for checking: {$real_site_root}", 'debug');

        // Check critical files
        if (isset($baseline['files']) && is_array($baseline['files'])) {
            foreach ($baseline['files'] as $file => $file_baseline) {
                $file_path = $real_site_root . $file;
                $current_exists = file_exists($file_path);

                // File was in baseline but now doesn't exist
                if (isset($file_baseline['exists']) && $file_baseline['exists'] && !$current_exists) {
                    $violations[] = [
                        'file' => $file,
                        'type' => 'deleted',
                        'pattern' => 'Core file deletion',
                        'match' => 'File was present in baseline but is now missing',
                        'severity' => 'critical',
                        'description' => 'Critical WordPress core file was <span class="integrity-alert">deleted</span>'
                    ];
                    clean_sweep_log_message("🚨 REINFECTION: Core file deleted - {$file}", 'error');
                    continue;
                }

                // File didn't exist in baseline but now does (suspicious new file)
                if (isset($file_baseline['exists']) && !$file_baseline['exists'] && $current_exists) {
                    $violations[] = [
                        'file' => $file,
                        'type' => 'created',
                        'pattern' => 'Unexpected core file',
                        'match' => 'File was not in baseline but now exists',
                        'severity' => 'warning',
                        'description' => 'Unexpected core file <span class="integrity-alert">appeared</span>'
                    ];
                    clean_sweep_log_message("⚠️ REINFECTION: Suspicious core file created - {$file}", 'warning');
                    continue;
                }

                // File exists in both places - check for integrity violations
                if ($current_exists && isset($file_baseline['exists']) && $file_baseline['exists']) {
                    $current_hash = hash_file('sha256', $file_path);
                    $current_size = filesize($file_path);
                    $current_mtime = filemtime($file_path);

                    // Check for hash changes (cryptographic integrity violation)
                    if ($current_hash !== $file_baseline['hash']) {
                        $violations[] = [
                            'file' => $file,
                            'type' => 'modified',
                            'pattern' => 'File content modified',
                            'match' => "File integrity compromised - content has changed",
                            'severity' => 'critical',
                            'description' => 'Core file cryptographic integrity compromised - <span class="integrity-alert">potential reinfection</span>'
                        ];
                        clean_sweep_log_message("🚨 REINFECTION: Core file integrity compromised - {$file} (hash changed)", 'error');
                    }

                    // Check for size changes (backup check)
                    elseif ($current_size !== $file_baseline['size']) {
                        $size_diff = $current_size - $file_baseline['size'];
                        $violations[] = [
                            'file' => $file,
                            'type' => 'modified',
                            'pattern' => 'File size changed',
                            'match' => "Size: {$file_baseline['size']} → {$current_size} (Δ{$size_diff})",
                            'severity' => 'critical',
                            'description' => 'Core file size changed from baseline - <span class="integrity-alert">potential reinfection</span>'
                        ];
                        clean_sweep_log_message("🚨 REINFECTION: Core file size changed - {$file} (Δ{$size_diff})", 'error');
                    }

                    // Check for modification time changes (less critical)
                    elseif ($current_mtime > $file_baseline['mtime']) {
                        $time_diff = $current_mtime - $file_baseline['mtime'];
                        $violations[] = [
                            'file' => $file,
                            'type' => 'modified',
                            'pattern' => 'File timestamp changed',
                            'match' => "Modified: " . date('H:i:s', $file_baseline['mtime']) . " → " . date('H:i:s', $current_mtime) . " ({$time_diff}s ago)",
                            'severity' => 'warning',
                            'description' => 'Core file was modified after baseline establishment'
                        ];
                        clean_sweep_log_message("⚠️ REINFECTION: Core file timestamp changed - {$file}", 'warning');
                    }
                }
            }
        }

        // Check critical directories (legacy logic for core-only mode)
        if (isset($baseline['directories']) && is_array($baseline['directories'])) {
            foreach ($baseline['directories'] as $dir => $dir_baseline) {
                $dir_path = $real_site_root . $dir;

                if (is_dir($dir_path)) {
                    $current_php_files = glob($dir_path . '/*.php');
                    $current_count = count($current_php_files);

                    if (isset($dir_baseline['exists']) && $dir_baseline['exists']) {
                        $baseline_count = $dir_baseline['php_count'];

                        // ANY increase in PHP files is suspicious in monitored directories
                        if ($current_count > $baseline_count) {
                            $new_files = $current_count - $baseline_count;

                            $violations[] = [
                                'file' => $dir . '/',
                                'type' => 'directory_modified',
                                'pattern' => 'Unexpected PHP files in monitored directory',
                                'match' => "PHP files: {$baseline_count} → {$current_count} (+{$new_files})",
                                'severity' => 'critical',
                                'description' => 'Monitored directory gained PHP files since baseline - <span class="integrity-alert">potential malware injection</span>'
                            ];
                            clean_sweep_log_message("🚨 REINFECTION: Monitored directory gained {$new_files} PHP files - {$dir}", 'error');
                        }
                    }
                }
            }
        }

        // For comprehensive mode, check for NEW monitorable files in ALL monitored directories AND root directory
        if (isset($baseline['mode']) && $baseline['mode'] === 'comprehensive') {
            // Get ALL directories that were monitored during baseline establishment
            $monitored_dirs = [];
            if (isset($baseline['directories']) && is_array($baseline['directories'])) {
                $monitored_dirs = array_keys($baseline['directories']);
            }

            clean_sweep_log_message("🔍 Comprehensive mode: Checking " . count($monitored_dirs) . " monitored directories for new files", 'debug');

            foreach ($monitored_dirs as $dir) {
                $dir_path = $real_site_root . $dir;
                if (is_dir($dir_path)) {
                    // Get all current monitorable files in this directory (recursive)
                    // Use same exclusion patterns as baseline establishment
                    $exclude_patterns = ['wp-content/uploads/'];
                    $current_files = clean_sweep_get_all_monitorable_files($dir_path, $exclude_patterns);

                    foreach ($current_files as $file_path) {
                        $relative_path = str_replace($real_site_root, '', $file_path);

                        // Check if this file exists in baseline
                        if (!isset($baseline['files'][$relative_path])) {
                            // NEW FILE DETECTED - wasn't in baseline!
                            $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                            $file_description = clean_sweep_get_file_type_description($file_extension);

                            $violations[] = [
                                'file' => $relative_path,
                                'type' => 'created',
                                'pattern' => "New {$file_description} file in monitored directory",
                                'match' => 'File exists now but was not in baseline',
                                'severity' => 'critical',
                                'description' => "New {$file_description} file detected in monitored directory - <span class=\"integrity-alert\">potential malware</span>"
                            ];
                            clean_sweep_log_message("🚨 REINFECTION: New {$file_description} file detected - {$relative_path}", 'error');
                        }
                    }
                }
            }

            // Also check the WordPress ROOT directory for new files and new directories (critical security enhancement)
            clean_sweep_log_message("🔍 Comprehensive mode: Checking WordPress root directory for new files and directories", 'debug');

            if (is_dir($real_site_root)) {
                // Get all items directly in root directory (non-recursive)
                $root_items = scandir($real_site_root);
                if ($root_items !== false) {
                    foreach ($root_items as $item) {
                        // Skip special entries
                        if ($item === '.' || $item === '..') {
                            continue;
                        }

                        $item_path = $real_site_root . $item;

                        // Check if this is a directory (new directory detection)
                        if (is_dir($item_path)) {
                            // Skip known core directories that are already monitored
                            $known_dirs = ['wp-admin', 'wp-includes', 'wp-content'];
                            if (in_array($item, $known_dirs)) {
                                continue;
                            }

                            // Skip excluded development/security tool directories
                            $excluded_dev_dirs = [
                                'clean-sweep',     // Clean Sweep toolkit directory
                                '.git',           // Git repository
                                'node_modules',   // Node.js dependencies
                                'vendor',         // Composer/PHP dependencies
                                '.vscode',        // VS Code settings
                                '.idea',          // PHPStorm/IntelliJ settings
                                '__pycache__',    // Python cache
                                '.pytest_cache',  // pytest cache
                                'venv',           // Python virtual environment
                                'env',            // Python virtual environment
                                '.env',           // Environment files directory
                                'logs',           // Log files directory (often auto-generated)
                                'tmp',            // Temporary files
                                'temp',           // Temporary files
                                'cache',          // Cache directories
                                '.DS_Store',      // macOS system files
                                'Thumbs.db'       // Windows system files
                            ];
                            if (in_array($item, $excluded_dev_dirs)) {
                                continue;
                            }

                            // Check if this directory exists in baseline
                            if (!isset($baseline['directories'][$item])) {
                                // NEW DIRECTORY DETECTED! Scan all monitorable files within it
                                clean_sweep_log_message("🚨 REINFECTION: New directory detected in root - {$item}", 'error');

                                $new_dir_files = clean_sweep_get_all_monitorable_files($item_path, []);
                                foreach ($new_dir_files as $file_path) {
                                    $relative_path = str_replace($real_site_root, '', $file_path);
                                    $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                                    $file_description = clean_sweep_get_file_type_description($file_extension);

                                    $violations[] = [
                                        'file' => $relative_path,
                                        'type' => 'created',
                                        'pattern' => "New {$file_description} file in new directory",
                                        'match' => 'File exists in newly created directory',
                                        'severity' => 'critical',
                                        'description' => "New {$file_description} file detected in newly created directory '{$item}' - <span class=\"integrity-alert\">potential malware</span>"
                                    ];
                                    clean_sweep_log_message("🚨 REINFECTION: New {$file_description} file in new directory - {$relative_path}", 'error');
                                }

                                // Also flag the directory itself
                                if (!empty($new_dir_files)) {
                                    $violations[] = [
                                        'file' => $item . '/',
                                        'type' => 'directory_created',
                                        'pattern' => 'New directory created in WordPress root',
                                        'match' => 'Directory was not present during baseline establishment',
                                        'severity' => 'critical',
                                        'description' => "New directory '{$item}' created in WordPress root with " . count($new_dir_files) . " monitorable files - <span class=\"integrity-alert\">potential malware</span>"
                                    ];
                                }
                            }
                        } else {
                            // Handle files directly in root
                            $file_extension = strtolower(pathinfo($item_path, PATHINFO_EXTENSION));

                            // Check if this is a monitorable file type
                            $monitorable_extensions = [
                                'php', 'js', 'css', 'json', 'svg',
                                'htaccess', 'htpasswd', 'conf', 'config', 'cfg', 'ini',
                                'txt', 'md', 'xml',
                                'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'ico'
                            ];

                            $is_monitorable = in_array($file_extension, $monitorable_extensions) ||
                                             in_array($item, ['robots.txt', 'web.config', '.htaccess', '.htpasswd']);

                            if ($is_monitorable) {
                                // Check if this root file exists in baseline
                                if (!isset($baseline['files'][$item])) {
                                    // NEW FILE DETECTED in root directory!
                                    $file_description = clean_sweep_get_file_type_description($file_extension);

                                    $violations[] = [
                                        'file' => $item,
                                        'type' => 'created',
                                        'pattern' => "New {$file_description} file in WordPress root directory",
                                        'match' => 'File exists now but was not in baseline',
                                        'severity' => 'critical',
                                        'description' => "New {$file_description} file detected in WordPress root directory - <span class=\"integrity-alert\">potential malware backdoor</span>"
                                    ];
                                    clean_sweep_log_message("🚨 REINFECTION: New {$file_description} file in root directory - {$item}", 'error');
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!empty($violations)) {
            clean_sweep_log_message("🚨 REINFECTION DETECTED: " . count($violations) . " core integrity violations found", 'error');
        } else {
            clean_sweep_log_message("✅ Core integrity check passed - no violations detected", 'debug');
        }

        return $violations;
    }
}

/**
 * Clean Sweep Integrity Management Class
 * Handles baseline export, import, and advanced integrity features
 */
class CleanSweep_Integrity {

    private $baseline_file;

    public function __construct() {
        $this->baseline_file = dirname(__DIR__, 2) . '/backups/core_integrity_baseline.json';
    }

    /**
     * Export current baseline as signed JSON for offline storage
     */
    public function export_baseline() {
        $baseline = clean_sweep_get_core_baseline();

        if ($baseline === null) {
            return ['error' => 'No baseline established to export'];
        }

        // Add export metadata
        $export_data = [
            'baseline' => $baseline,
            'export_info' => [
                'exported_at' => time(),
                'site_domain' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'unknown',
                'clean_sweep_version' => '2.0',
                'wp_version' => defined('WP_VERSION') ? WP_VERSION : 'unknown'
            ]
        ];

        // Create cryptographic signature for tamper protection
        $json_data = json_encode($export_data['baseline'], JSON_UNESCAPED_UNICODE);
        $export_data['signature'] = $this->sign_data($json_data);
        $export_data['algorithm'] = 'SHA256';

        $json = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return ['error' => 'Failed to encode baseline data'];
        }

        clean_sweep_log_message("📤 Baseline exported successfully", 'info');
        return [
            'success' => true,
            'data' => $json,
            'filename' => 'clean-sweep-baseline-' . date('Y-m-d-H-i-s') . '.json'
        ];
    }

    /**
     * Import and verify baseline from uploaded JSON file
     */
    public function import_baseline($json_content) {
        $import_data = json_decode($json_content, true);

        if ($import_data === null) {
            return ['error' => 'Invalid JSON format'];
        }

        // Verify cryptographic signature
        if (!isset($import_data['signature']) || !isset($import_data['baseline'])) {
            return ['error' => 'Missing signature or baseline data'];
        }

        $json_data = json_encode($import_data['baseline'], JSON_UNESCAPED_UNICODE);
        if (!$this->verify_signature($json_data, $import_data['signature'])) {
            return ['error' => 'Baseline signature verification failed - file may be tampered with'];
        }

        $baseline = $import_data['baseline'];

        // Validate baseline structure
        if (!$this->validate_baseline_structure($baseline)) {
            return ['error' => 'Invalid baseline structure'];
        }

        // SAVE the imported baseline to local file for future comparisons
        $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($this->baseline_file, $json) !== false) {
            chmod($this->baseline_file, 0644);
            clean_sweep_log_message("✅ Imported baseline saved to local file for integrity monitoring", 'info');
        } else {
            clean_sweep_log_message("❌ Failed to save imported baseline to local file", 'error');
            return ['error' => 'Failed to save imported baseline locally'];
        }

        clean_sweep_log_message("📥 Baseline imported, verified, and saved successfully", 'info');

        return [
            'success' => true,
            'baseline' => $baseline,
            'metadata' => $import_data['export_info'] ?? null
        ];
    }

    /**
     * Compare current system state with imported baseline
     */
    public function compare_with_baseline($imported_baseline) {
        $current_violations = clean_sweep_check_for_reinfection();

        // Additional comparison logic can be added here
        // For now, we rely on the existing reinfection check

        return [
            'current_violations' => $current_violations,
            'comparison_summary' => $this->generate_comparison_summary($current_violations, $imported_baseline)
        ];
    }

    /**
     * Update baseline incrementally after operations
     */
    public function update_baseline_incremental($operation_type, $details = []) {
        $baseline = clean_sweep_get_core_baseline();

        if ($baseline === null) {
            // No existing baseline, create new one
            clean_sweep_establish_core_baseline();
            return;
        }

        // Add operation to history
        if (!isset($baseline['operations_applied'])) {
            $baseline['operations_applied'] = [];
        }

        $baseline['operations_applied'][] = [
            'type' => $operation_type,
            'timestamp' => time(),
            'details' => $details
        ];

        $baseline['last_updated'] = time();

        // Update baseline with current state
        $this->update_baseline_with_current_state($baseline);

        // Save updated baseline
        $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($this->baseline_file, $json) !== false) {
            chmod($this->baseline_file, 0644);
            clean_sweep_log_message("🔄 Baseline updated incrementally after {$operation_type}", 'info');
        }
    }

    /**
     * Update baseline with current system state
     */
    private function update_baseline_with_current_state(&$baseline) {
        // Get real site root
        $real_site_root = clean_sweep_detect_site_root();

        // Update critical files
        $critical_files = [
            'wp-config.php',
            'wp-load.php',
            'wp-settings.php',
            'wp-admin/index.php',
            'wp-admin/admin.php',
            'wp-includes/version.php',
            'wp-includes/functions.php',
            'wp-includes/wp-db.php',
            '.htaccess',
            'index.php'
        ];

        foreach ($critical_files as $file) {
            $file_path = $real_site_root . $file;
            if (file_exists($file_path) && is_readable($file_path)) {
                $baseline['files'][$file] = [
                    'hash' => hash_file('sha256', $file_path),
                    'size' => filesize($file_path),
                    'mtime' => filemtime($file_path),
                    'exists' => true
                ];
            } else {
                $baseline['files'][$file] = ['exists' => false];
            }
        }

        // Update directory counts
        $critical_dirs = ['wp-admin', 'wp-includes'];
        foreach ($critical_dirs as $dir) {
            $dir_path = $real_site_root . $dir;
            if (is_dir($dir_path)) {
                $php_files = glob($dir_path . '/*.php');
                $baseline['directories'][$dir] = [
                    'php_count' => count($php_files),
                    'exists' => true
                ];
            } else {
                $baseline['directories'][$dir] = ['exists' => false];
            }
        }
    }

    /**
     * Generate comparison summary between current state and imported baseline
     */
    private function generate_comparison_summary($violations, $imported_baseline) {
        $summary = [
            'total_violations' => count($violations),
            'critical_violations' => 0,
            'warning_violations' => 0,
            'imported_baseline_date' => isset($imported_baseline['established_at']) ?
                date('Y-m-d H:i:s', $imported_baseline['established_at']) : 'unknown'
        ];

        foreach ($violations as $violation) {
            if (isset($violation['severity'])) {
                if ($violation['severity'] === 'critical') {
                    $summary['critical_violations']++;
                } elseif ($violation['severity'] === 'warning') {
                    $summary['warning_violations']++;
                }
            }
        }

        return $summary;
    }

    /**
     * Create cryptographic signature for data integrity
     * Uses site-specific fingerprint that survives Clean Sweep reinstallations
     */
    private function sign_data($data) {
        $site_fingerprint = $this->generate_site_fingerprint();
        return hash_hmac('sha256', $data, $site_fingerprint);
    }

    /**
     * Generate site-specific fingerprint for baseline signatures
     * This fingerprint persists across Clean Sweep reinstallations but changes per site
     */
    private function generate_site_fingerprint() {
        global $table_prefix;

        // Collect site-specific data that remains constant across Clean Sweep versions
        $site_components = [
            isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'unknown-host',
            defined('DB_NAME') ? DB_NAME : 'unknown-db',
            isset($table_prefix) ? $table_prefix : 'unknown-prefix',
            get_option('siteurl', 'unknown-siteurl'),
            get_option('home', 'unknown-home'),
            ABSPATH // Installation path
        ];

        // Create deterministic fingerprint from site components
        $fingerprint_string = implode('|', $site_components);
        return hash('sha256', $fingerprint_string);
    }

    /**
     * Verify cryptographic signature
     */
    private function verify_signature($data, $signature) {
        $expected_signature = $this->sign_data($data);
        return hash_equals($expected_signature, $signature);
    }

    /**
     * Get user-friendly description for file type based on extension
     */
    private function get_file_type_description($extension) {
        $descriptions = [
            // Executable/script files
            'php' => 'PHP script',
            'js' => 'JavaScript',
            'css' => 'CSS stylesheet',
            'json' => 'JSON configuration',
            'svg' => 'SVG vector image',

            // Configuration files
            'htaccess' => 'Apache configuration',
            'htpasswd' => 'Apache password',
            'conf' => 'configuration',
            'config' => 'configuration',
            'cfg' => 'configuration',
            'ini' => 'configuration',

            // Text files
            'txt' => 'text',
            'md' => 'markdown',
            'xml' => 'XML',

            // Image files
            'jpg' => 'JPEG image',
            'jpeg' => 'JPEG image',
            'png' => 'PNG image',
            'gif' => 'GIF image',
            'webp' => 'WebP image',
            'bmp' => 'bitmap image',
            'tiff' => 'TIFF image',
            'ico' => 'icon',

            // Special files (by filename)
            'robots.txt' => 'robots.txt',
            'web.config' => 'IIS configuration'
        ];

        return $descriptions[$extension] ?? $extension . ' file';
    }

    /**
     * Validate baseline data structure
     */
    private function validate_baseline_structure($baseline) {
        $required_keys = ['established_at', 'files', 'directories'];

        foreach ($required_keys as $key) {
            if (!isset($baseline[$key])) {
                return false;
            }
        }

        return true;
    }
}
?>
