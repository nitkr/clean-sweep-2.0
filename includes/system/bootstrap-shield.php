<?php
/**
 * Clean Sweep - Bootstrap Shield
 *
 * Enhanced WordPress bootstrap protection system that prevents Clean Sweep
 * from failing on infected sites by using clean local WordPress core files.
 *
 * Features:
 * - Local clean WordPress core files (guaranteed safe)
 * - Site file verification and infection detection
 * - Reinfection monitoring during operations
 * - Automatic fallback to protected mode
 */

// ============================================================================
// BOOTSTRAP SHIELD - ENHANCED WORDPRESS BOOTSTRAP
// ============================================================================

/**
 * Bootstrap Shield - Enhanced WordPress environment bootstrap with infection protection
 * Attempts to load WordPress from clean local files first, then verified site files
 *
 * @return bool True if bootstrap successful, false otherwise
 */
function clean_sweep_bootstrap_wordpress() {
    $bootstrap_success = false;
    $bootstrap_mode = 'unknown';
    $site_wp_version = null;

    // ============================================================================
    // PHASE 1: Try Clean Local Core Files (Bootstrap Shield)
    // ============================================================================

    clean_sweep_log_message("🛡️ Bootstrap Shield activated - attempting secure bootstrap", 'info');

    // Define bootstrap priority: PERFORMANCE FIRST, COMPATIBILITY SECOND
    $bootstrap_attempts = [
        // PRIMARY: Fast WordPress bootstrap for scanning (performance optimized)
        'local_core' => [
            'path' => __DIR__ . '/../../core/clean-sweep-bootstrap.php',
            'mode' => 'local_core',
            'description' => 'Clean Sweep WordPress bootstrap (fast scanning)',
            'check_malware' => true // Check wp-settings.php before loading
        ],

        // FALLBACK: Clean WordPress environment for corrupted sites (maximum compatibility)
        'recovery' => [
            'path' => __DIR__ . '/../../core/recovery/recovery-wp-load.php',
            'mode' => 'recovery',
            'description' => 'Clean Sweep recovery bootstrap (corrupted sites)'
        ],

        // SECONDARY: Site's WordPress files (with verification)
        'site_primary' => [
            'path' => __DIR__ . '/../../../wp-load.php',
            'mode' => 'site_verified',
            'description' => 'Site WordPress files (verified)'
        ],
        'site_secondary' => [
            'path' => dirname(__DIR__ . '/../../../') . '/wp-load.php',
            'mode' => 'site_verified',
            'description' => 'Site WordPress files (fallback)'
        ],
        'site_tertiary' => [
            'path' => dirname(dirname(__DIR__ . '/../../../')) . '/wp-load.php',
            'mode' => 'site_verified',
            'description' => 'Site WordPress files (tertiary)'
        ],

        // EMERGENCY: Any available wp-load.php (last resort)
        'emergency' => [
            'path' => __DIR__ . '/../../wp-load.php',
            'mode' => 'emergency',
            'description' => 'Emergency fallback'
        ]
    ];

    foreach ($bootstrap_attempts as $attempt_key => $attempt) {
        $wp_load_path = $attempt['path'];

        if (file_exists($wp_load_path)) {
            clean_sweep_log_message("🔄 Attempting bootstrap via: {$attempt['description']} ({$wp_load_path})", 'info');

            // For local_core mode, check wp-settings.php for any corruption before loading
            if (isset($attempt['check_malware']) && $attempt['check_malware']) {
                // Use same path resolution as local core bootstrap
                $abspath = dirname(dirname(dirname(__DIR__))) . '/';
                $wp_settings_paths = [
                    $abspath . 'wp-settings.php',
                    dirname($abspath) . '/wp-settings.php'
                ];

                $wp_settings_path = null;
                foreach ($wp_settings_paths as $path) {
                    if (file_exists($path)) {
                        $wp_settings_path = $path;
                        break;
                    }
                }

                if ($wp_settings_path && file_exists($wp_settings_path)) {
                    $content = @file_get_contents($wp_settings_path);
                    if ($content !== false) {
                        // Check specific malware patterns
                        $str_rot13_found = stripos($content, 'str_rot13') !== false;
                        $double_php_found = stripos($content, '<?php<?php') !== false;

                        // Comprehensive corruption checks - malware patterns AND structural validation
                        $corruption_indicators = [
                            // Malware patterns
                            '<?php' . '/*', 'base64_decode', 'eval(', 'gzinflate', 'str_rot13',
                            'md5(', 'create_function', 'assert(', 'shell_exec', 'system(',
                            'exec(', 'passthru', 'obfuscate', 'encode', 'gzuncompress',

                            // Real corruption patterns
                            'Parse error', 'syntax error', 'unexpected',
                            'Call to undefined function', 'Class not found', 'Interface not found',

                            // Suspicious modifications
                            '$_SERVER[\"HTTP', '$_GET[\"', '$_POST[\"', // Common injection patterns
                            'file_get_contents(', 'curl_exec(', // Network functions in core
                            'mail(', 'fsockopen(', // Communication functions

                            // Base64 malware patterns
                            'ZXZhbC', 'ZW52', 'ZWNoby', // Common base64 encoded malware
                        ];

                        $has_corruption = false;

                        // Check for structural PHP syntax issues that cause fatal errors
                        $php_open_tags = substr_count($content, '<?php');
                        $php_close_tags = substr_count($content, '?>');

                        if ($php_open_tags > 1) {
                            $has_corruption = true;
                            clean_sweep_log_message("🚨 Multiple PHP open tags detected: {$php_open_tags} (will cause parse error)", 'warning');
                        }

                        // Check for unmatched PHP tags that cause syntax errors
                        if ($php_open_tags > $php_close_tags + 1) { // Allow for 1 unclosed tag at end
                            $has_corruption = true;
                            clean_sweep_log_message("🚨 Unmatched PHP tags: {$php_open_tags} open, {$php_close_tags} close", 'warning');
                        }

                        // Check for consecutive PHP tags (malware signature)
                        if (preg_match('/<\?php.*<\?php/s', $content)) {
                            $has_corruption = true;
                            clean_sweep_log_message("🚨 Consecutive PHP open tags detected (malware pattern)", 'warning');
                        }

                        // If no structural issues found, check content patterns
                        if (!$has_corruption) {
                            foreach ($corruption_indicators as $indicator) {
                                if (stripos($content, $indicator) !== false) {
                                    $has_corruption = true;
                                    clean_sweep_log_message("🚨 Corruption indicator found: {$indicator}", 'warning');
                                    break;
                                }
                            }
                        }

                        // File size sanity checks
                        $file_size = strlen($content);
                        if ($file_size < 1000 || $file_size > 50000) { // Unusual size for wp-settings.php
                            $has_corruption = true;
                            clean_sweep_log_message("🚨 Unusual file size detected: {$file_size} bytes", 'warning');
                        }

                        // Check for basic PHP structure
                        if (substr($content, 0, 5) !== '<?php') {
                            $has_corruption = true;
                            clean_sweep_log_message("🚨 Invalid PHP opening tag", 'warning');
                        }

                        if ($has_corruption) {
                            clean_sweep_log_message("🚨 wp-settings.php corruption detected - skipping local_core mode", 'warning');
                            continue; // Skip this attempt, try recovery mode
                        }
                    } else {
                        clean_sweep_log_message("🚨 Cannot read wp-settings.php - skipping local_core mode", 'warning');
                        continue; // Skip this attempt, try recovery mode
                    }
                } else {
                    clean_sweep_log_message("🚨 wp-settings.php not found - skipping local_core mode", 'warning');
                    continue; // Skip this attempt, try recovery mode
                }
                clean_sweep_log_message("✅ wp-settings.php verified as safe", 'info');
            }

            // For site files, add infection verification
            if ($attempt['mode'] === 'site_verified') {
                if (!clean_sweep_verify_site_files_safe($wp_load_path)) {
                    clean_sweep_log_message("⚠️ Site file verification failed - potential infection detected", 'warning');
                    continue; // Skip this attempt, try next
                }
                clean_sweep_log_message("✅ Site files verified as safe", 'info');
            }

            // Attempt to load WordPress
            try {
                require_once $wp_load_path;
                $bootstrap_success = true;
                $bootstrap_mode = $attempt['mode'];

                // Detect WordPress version for compatibility logging
                if (defined('ABSPATH') && function_exists('get_bloginfo')) {
                    $site_wp_version = get_bloginfo('version');
                    clean_sweep_log_message("📊 WordPress version detected: {$site_wp_version}", 'info');
                }

                clean_sweep_log_message("✅ Bootstrap successful via: {$attempt['description']} (Mode: {$bootstrap_mode})", 'info');

                // Store bootstrap mode globally for monitoring
                global $clean_sweep_bootstrap_mode;
                $clean_sweep_bootstrap_mode = $bootstrap_mode;

                break;

            } catch (Exception $e) {
                clean_sweep_log_message("❌ Bootstrap failed via {$attempt['description']}: " . $e->getMessage(), 'warning');
                continue; // Try next attempt
            }
        }
    }

    if (!$bootstrap_success) {
        clean_sweep_log_message("💀 CRITICAL: All bootstrap attempts failed. Cannot load WordPress environment.", 'error');
        return false;
    }

    // ============================================================================
    // PHASE 2: Verify WordPress Environment Integrity
    // ============================================================================

    // Initialize WordPress filesystem before loading admin files
    // This ensures filesystem constants like FS_CHMOD_DIR are defined
    if (function_exists('WP_Filesystem')) {
        WP_Filesystem();
        clean_sweep_log_message("💾 WordPress filesystem initialized", 'info');
    }

    // Include required WordPress admin files for non-local modes
    // Local core mode provides its own implementations to avoid conflicts
    $admin_files_loaded = true;
    if ($bootstrap_mode !== 'local_core') {
        try {
            if (defined('ABSPATH')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            } else {
                throw new Exception("ABSPATH not defined");
            }
        } catch (Exception $e) {
            clean_sweep_log_message("❌ Failed to load WordPress admin files: " . $e->getMessage(), 'error');
            $admin_files_loaded = false;
        }
    } else {
        clean_sweep_log_message("📦 Local core mode - using Clean Sweep implementations (skipping WordPress admin files)", 'info');
    }

    // Verify required functions are available (mode-specific)
    if ($bootstrap_mode === 'local_core') {
        // Local core provides its own implementations
        $required_functions = ['get_plugins'];
    } else {
        // Other modes rely on WordPress admin functions
        $required_functions = ['get_plugins', 'download_url'];
    }

    $functions_available = true;
    foreach ($required_functions as $function) {
        if (!function_exists($function)) {
            clean_sweep_log_message("❌ Required WordPress function not available: {$function}", 'error');
            $functions_available = false;
        }
    }

    if (($bootstrap_mode !== 'local_core' && !$admin_files_loaded) || !$functions_available) {
        clean_sweep_log_message("❌ WordPress environment verification failed", 'error');
        return false;
    }

    // ============================================================================
    // PHASE 3: Initialize Reinfection Monitoring (if clean hashes available)
    // ============================================================================

    // Enable reinfection monitoring if clean hashes exist (from core reinstallation)
    $clean_hashes_file = __DIR__ . '/../../features/maintenance/clean-core-hashes.json';
    if (file_exists($clean_hashes_file)) {
        clean_sweep_initialize_reinfection_monitoring();
        clean_sweep_log_message("🔍 Reinfection monitoring activated (clean hashes available)", 'info');
    } elseif ($bootstrap_mode === 'site_verified') {
        // Fallback: enable for site_verified mode without clean hashes
        clean_sweep_initialize_reinfection_monitoring();
        clean_sweep_log_message("🔍 Reinfection monitoring activated for site files", 'info');
    }

    // Log successful bootstrap with details
    $log_message = "🛡️ Bootstrap Shield successful - Mode: {$bootstrap_mode}";
    if ($site_wp_version) {
        $log_message .= ", WP Version: {$site_wp_version}";
    }
    clean_sweep_log_message($log_message, 'info');

    return true;
}

/**
 * Verify that site WordPress files are safe to load (basic infection check)
 *
 * @param string $wp_load_path Path to wp-load.php
 * @return bool True if safe, false if potentially infected
 */
function clean_sweep_verify_site_files_safe($wp_load_path) {
    $critical_files = [
        $wp_load_path, // wp-load.php
        dirname($wp_load_path) . '/wp-settings.php' // wp-settings.php in same directory
    ];

    foreach ($critical_files as $file_path) {
        if (!file_exists($file_path)) {
            clean_sweep_log_message("⚠️ Critical WordPress file missing: {$file_path}", 'warning');
            continue; // Don't fail for missing files, just warn
        }

        $content = @file_get_contents($file_path);
        if ($content === false) {
            clean_sweep_log_message("⚠️ Cannot read critical WordPress file: {$file_path}", 'warning');
            continue;
        }

        // Check for common infection patterns in first few lines
        $first_150_chars = substr($content, 0, 150); // Check more chars for better detection

        // Look for suspicious patterns that indicate infection
        $infection_indicators = [
            '<?php' . '/*', // Obfuscated code starting with comment
            'base64_decode',
            'eval(',
            'gzinflate',
            'str_rot13',
            '<?php <?php', // Double PHP tags
            'create_function',
            'assert(',
            'shell_exec',
            'system(',
            'exec(',
            'passthru'
        ];

        foreach ($infection_indicators as $indicator) {
            if (stripos($first_150_chars, $indicator) !== false) {
                $file_name = basename($file_path);
                clean_sweep_log_message("🚨 Infection indicator detected in site {$file_name}: {$indicator}", 'warning');
                return false;
            }
        }

        // File size sanity check (critical files should be reasonable size)
        if (strlen($content) > 20000) {
            $file_name = basename($file_path);
            clean_sweep_log_message("⚠️ Suspiciously large {$file_name} file detected", 'warning');
            return false;
        }
    }

    clean_sweep_log_message("✅ Critical WordPress files verified as safe", 'info');
    return true; // All checked files appear safe
}

/**
 * Initialize reinfection monitoring for site-based bootstrap
 */
function clean_sweep_initialize_reinfection_monitoring() {
    global $clean_sweep_baseline_hashes;

    // First, try to load clean hashes from previous core installation
    $clean_hashes_file = __DIR__ . '/../../features/maintenance/clean-core-hashes.json';
    if (file_exists($clean_hashes_file)) {
        $clean_data = json_decode(file_get_contents($clean_hashes_file), true);
        if ($clean_data && isset($clean_data['files'])) {
            $clean_sweep_baseline_hashes = $clean_data['files'];
            clean_sweep_log_message("📋 Using clean core hashes from previous installation (" . count($clean_sweep_baseline_hashes) . " files)", 'info');
            clean_sweep_log_message("🔍 Integrity verification will detect modifications from clean state", 'info');
            return;
        }
    }

    // Fallback: Store current baseline file hashes for critical WordPress files
    $critical_files = [
        ABSPATH . 'wp-load.php',
        ABSPATH . 'wp-config.php',
        ABSPATH . 'wp-settings.php',
        ABSPATH . 'index.php'
    ];

    $baseline_hashes = [];
    foreach ($critical_files as $file) {
        if (file_exists($file)) {
            $baseline_hashes[$file] = hash_file('sha256', $file);
        }
    }

    // Store in global for monitoring during operations
    $clean_sweep_baseline_hashes = $baseline_hashes;

    clean_sweep_log_message("📋 Baseline file integrity established for " . count($baseline_hashes) . " critical files", 'info');
    clean_sweep_log_message("💡 Tip: Run core reinstallation to establish clean hashes for better integrity checking", 'info');
}

/**
 * Check for reinfection during operations (called periodically)
 *
 * @return array Array of integrity violations detected
 */
function clean_sweep_check_for_reinfection() {
    global $clean_sweep_baseline_hashes, $clean_sweep_bootstrap_mode;

    // Only monitor if we have baseline hashes (clean or current)
    if (empty($clean_sweep_baseline_hashes)) {
        return [];
    }

    $integrity_violations = [];

    foreach ($clean_sweep_baseline_hashes as $file => $baseline_hash) {
        if (file_exists($file)) {
            $current_hash = hash_file('sha256', $file);
            if ($current_hash !== $baseline_hash) {
                $violation = [
                    'pattern' => 'INTEGRITY_VIOLATION',
                    'match' => 'File hash changed from clean baseline',
                    'file' => $file,
                    'severity' => 'critical',
                    'action' => 'investigate_immediately',
                    'details' => 'File modified after core reinstallation - potential reinfection',
                    'baseline_hash' => $baseline_hash,
                    'current_hash' => $current_hash
                ];
                $integrity_violations[] = $violation;
                clean_sweep_log_message("🚨 REINFECTION DETECTED: {$file} has changed during operation!", 'error');
            }
        } else {
            $violation = [
                'pattern' => 'INTEGRITY_VIOLATION',
                'match' => 'Critical core file deleted',
                'file' => $file,
                'severity' => 'critical',
                'action' => 'investigate_immediately',
                'details' => 'Critical core file missing - potential reinfection or system compromise',
                'baseline_hash' => $baseline_hash,
                'current_hash' => null
            ];
            $integrity_violations[] = $violation;
            clean_sweep_log_message("🚨 CRITICAL FILE MISSING: {$file} was deleted during operation!", 'error');
        }
    }

    if (!empty($integrity_violations)) {
        clean_sweep_log_message("🛡️ Switching to Bootstrap Shield protection mode", 'warning');
        // Force reload with local core files on next request
        global $clean_sweep_force_local_bootstrap;
        $clean_sweep_force_local_bootstrap = true;
    }

    return $integrity_violations;
}

# ============================================================================
# LEGACY BOOTSTRAP FUNCTION (for backward compatibility)
# ============================================================================

/**
 * Legacy bootstrap function - kept for backward compatibility
 * Simple fallback that tries common WordPress paths
 *
 * @return bool True if bootstrap successful, false otherwise
 */
function clean_sweep_bootstrap_wordpress_legacy() {
    $bootstrap_success = false;
    $possible_paths = [
        __DIR__ . '/../../wp-load.php',
        __DIR__ . '/../../../wp-load.php',
        dirname(__DIR__ . '/../../../') . '/wp-load.php',
        dirname(dirname(__DIR__ . '/../../../')) . '/wp-load.php'
    ];

    foreach ($possible_paths as $wp_load_path) {
        if (file_exists($wp_load_path)) {
            require_once $wp_load_path;
            $bootstrap_success = true;
            if (isset($_POST['action']) && !empty($_POST['action'])) {
                clean_sweep_log_message("WordPress bootstrapped successfully from: $wp_load_path");
            }
            break;
        }
    }

    if (!$bootstrap_success) {
        clean_sweep_log_message("Error: Could not find wp-load.php. Please ensure this script is placed in your WordPress root directory.", 'error');
        return false;
    }

    // Include required WordPress admin files
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    // Verify required functions are available
    if (!function_exists('get_plugins')) {
        clean_sweep_log_message("Error: WordPress functions not available. Bootstrap failed.", 'error');
        return false;
    }

    if (!function_exists('download_url')) {
        clean_sweep_log_message("Error: Required WordPress functions not available. Admin includes failed.", 'error');
        return false;
    }

    return true;
}
