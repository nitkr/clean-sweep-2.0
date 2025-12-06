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
            'wp_version' => $wp_version ?: (defined('WP_VERSION') ? WP_VERSION : 'unknown'),
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
            'wp_version' => $wp_version ?: (defined('WP_VERSION') ? WP_VERSION : 'unknown'),
            'mode' => 'comprehensive',
            'files' => [],
            'directories' => []
        ];

        clean_sweep_log_message("🔍 Establishing comprehensive integrity baseline (all WordPress files)", 'info');

        // Comprehensive file baselining - scan ALL directories in wp-content
        $wp_content_path = $real_site_root . 'wp-content';
        $directories_to_scan = [];

        if (is_dir($wp_content_path)) {
            // Get ALL subdirectories in wp-content
            $wp_content_dirs = glob($wp_content_path . '/*', GLOB_ONLYDIR);
            foreach ($wp_content_dirs as $dir_path) {
                $relative_dir = str_replace($real_site_root, '', $dir_path);
                $directories_to_scan[] = $relative_dir;
            }
        }

        $total_files = 0;

        // Scan ALL wp-content subdirectories
        foreach ($directories_to_scan as $dir) {
            $full_dir_path = $real_site_root . $dir;
            if (is_dir($full_dir_path)) {
                $php_files = clean_sweep_get_all_php_files($full_dir_path);
                foreach ($php_files as $file_path) {
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
            }
        }

        // Also baseline core files for comprehensive monitoring
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
                $total_files++;
            }
        }

        // For comprehensive mode, track individual files instead of directory counts
        // This allows pinpointing exactly which files were added/modified/deleted
        $directories_to_track_files = ['wp-admin', 'wp-includes', 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads'];

        foreach ($directories_to_track_files as $dir) {
            $dir_path = $real_site_root . $dir;
            if (is_dir($dir_path)) {
                // Get ALL PHP files recursively in this directory
                $php_files = clean_sweep_get_all_php_files($dir_path);
                foreach ($php_files as $file_path) {
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

                // Also track the directory itself
                $baseline['directories'][$dir] = [
                    'php_count' => count($php_files),
                    'exists' => true
                ];
            } else {
                $baseline['directories'][$dir] = ['exists' => false];
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
                        'description' => 'Critical WordPress core file was deleted'
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
                        'description' => 'Unexpected core file appeared'
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
                            'pattern' => 'Cryptographic hash changed',
                            'match' => "SHA256 integrity violation detected",
                            'severity' => 'critical',
                            'description' => 'Core file cryptographic integrity compromised - potential reinfection'
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
                            'description' => 'Core file size changed from baseline - potential reinfection'
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

                        // ANY increase in PHP files is suspicious in core directories
                        if ($current_count > $baseline_count) {
                            $new_files = $current_count - $baseline_count;
                            $violations[] = [
                                'file' => $dir . '/',
                                'type' => 'directory_modified',
                                'pattern' => 'Unexpected PHP files in core directory',
                                'match' => "PHP files: {$baseline_count} → {$current_count} (+{$new_files})",
                                'severity' => 'critical',
                                'description' => 'Core directory gained PHP files since baseline - potential malware injection'
                            ];
                            clean_sweep_log_message("🚨 REINFECTION: Core directory gained {$new_files} PHP files - {$dir}", 'error');
                        }
                    }
                }
            }
        }

        // For comprehensive mode, check for NEW files in ALL wp-content directories
        if (isset($baseline['mode']) && $baseline['mode'] === 'comprehensive') {
            $wp_content_path = $real_site_root . 'wp-content';
            $monitored_dirs = ['wp-admin', 'wp-includes']; // Always monitor core dirs

            // Add ALL wp-content subdirectories
            if (is_dir($wp_content_path)) {
                $wp_content_dirs = glob($wp_content_path . '/*', GLOB_ONLYDIR);
                foreach ($wp_content_dirs as $dir_path) {
                    $relative_dir = str_replace($real_site_root, '', $dir_path);
                    $monitored_dirs[] = $relative_dir;
                }
            }

            foreach ($monitored_dirs as $dir) {
                $dir_path = $real_site_root . $dir;
                if (is_dir($dir_path)) {
                    // Get all current PHP files in this directory (recursive)
                    $current_files = clean_sweep_get_all_php_files($dir_path);

                    foreach ($current_files as $file_path) {
                        $relative_path = str_replace($real_site_root, '', $file_path);

                        // Check if this file exists in baseline
                        if (!isset($baseline['files'][$relative_path])) {
                            // NEW FILE DETECTED - wasn't in baseline!
                            $violations[] = [
                                'file' => $relative_path,
                                'type' => 'created',
                                'pattern' => 'New PHP file in monitored directory',
                                'match' => 'File exists now but was not in baseline',
                                'severity' => 'critical',
                                'description' => 'New PHP file detected in monitored directory - potential malware'
                            ];
                            clean_sweep_log_message("🚨 REINFECTION: New PHP file detected - {$relative_path}", 'error');
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

        clean_sweep_log_message("📥 Baseline imported and verified successfully", 'info');

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
