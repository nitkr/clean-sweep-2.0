<?php
/**
 * Clean Sweep - Display Utility Functions
 *
 * Helper functions for display operations and data processing
 */

/**
 * Count total displayed threats in truncated scan results
 */
function clean_sweep_count_displayed_threats($scan_results) {
    $count = 0;
    foreach (['database', 'files'] as $section) {
        if (isset($scan_results[$section]) && is_array($scan_results[$section])) {
            foreach ($scan_results[$section] as $data) {
                if (is_array($data)) {
                    $count += count($data);
                }
            }
        }
    }
    return $count;
}

/**
 * Assign risk level to a threat (Critical/Warning/Info) based on source
 */
function clean_sweep_assign_risk_level($threat, $source = null) {
    $pattern = strtolower($threat['pattern'] ?? '');
    $match = strtolower($threat['match'] ?? '');
    $section = $threat['_section'] ?? $source ?? 'unknown';

    // DATABASE THREATS - Apply database-specific risk assessment
    if ($section === 'database') {
        // CRITICAL: Dangerous database content patterns
        $db_critical_patterns = [
            // Obvious malware injection
            'base64_decode', 'eval', 'assert', 'create_function',
            // Remote code execution indicators
            'shell_exec', 'system', 'exec', 'passthru', 'proc_open', 'popen',
            // File system access from database
            'fopen', 'fwrite', 'unlink', 'file_put_contents', 'include', 'require',
            // Database-specific critical patterns
            'drop table', 'drop database', 'truncate table', 'delete from.*where.*1=1',
            // Serialized objects that could contain malware
            'o:13:"phpmailerlite"', 'o:15:"constructorless"', 's:13:"wp_cache"',
            // Known exploit payloads in serialized data
            'eval(base64_decode', 'assert(', 'create_function(',
            // Backdoor signatures
            'backdoor', 'webshell', 'c99shell', 'r57shell', 'wso shell'
        ];

        foreach ($db_critical_patterns as $cp) {
            if (strpos($pattern, $cp) !== false || strpos($match, $cp) !== false) {
                return 'critical';
            }
        }

        // WARNING: Suspicious database patterns (default for most DB threats)
        $db_warning_patterns = [
            // SQL injection indicators
            'union select', 'information_schema', 'concat(', 'char(',
            'load_file', 'into outfile', 'benchmark(', 'sleep(',
            // JavaScript in database (XSS attempts)
            '<script', 'javascript:', 'onload=', 'onclick=',
            // Malicious links in content
            'http://russianteens', 'http://teenporn', 'http://pharmacy',
            // Spam content patterns
            'viagra', 'casino', 'lottery', 'winner',
            // Suspicious encoded content
            'data:text/html', 'vbscript:', 'onmouseover=',
            // Malicious URLs in database
            '.exe', '.scr', 'cmd.exe', 'powershell',
            // Base64 encoded content (potential malware evasion)
            'data:application/x-', 'data:image/',
            // Obfuscated content patterns
            'chr(', 'ord(', 'hex(', 'dechex(',
            // Common spam domains
            'pill', 'med', 'soma', 'drug', 'cheap'
        ];

        foreach ($db_warning_patterns as $wp) {
            if (strpos($pattern, $wp) !== false || strpos($match, $wp) !== false) {
                return 'warning';
            }
        }

        // ALL OTHER DATABASE THREATS - Default to WARNING (not info!)
        // Database threats are inherently suspicious and need review
        return 'warning';
    }

    // FILE THREATS - Apply file-specific risk assessment
    elseif ($section === 'files') {
        // CRITICAL: Dangerous file patterns
        $file_critical_patterns = [
            'eval(', 'base64_decode(', 'assert(', 'create_function(',
            'shell_exec(', 'system(', 'exec(', 'passthru(', 'proc_open(', 'popen(',
            'fopen(', 'fwrite(', 'unlink(', 'file_put_contents(', 'chmod(',
            'include(', 'require(', 'include_once(', 'require_once(',
            'document.write(', 'innerHTML', 'outerHTML',
            'wp_ajax_admin', 'wp_admin', 'admin_init', 'wp_login',
            'wp_create_user', 'wp_insert_user', 'wp_set_user_role', 'add_cap',
            'wp_cron', 'wp_schedule_event', 'wp_clear_scheduled_hook',
            'wp_remote_post(', 'wp_remote_get(', 'curl_exec(', 'file_get_contents(',
            'wp_mail(', 'mail(', 'phpinfo(', 'getenv(', 'putenv(',
            'serialize(', 'unserialize(', 'json_decode(', 'json_encode(',
            // Backdoor signatures
            'backdoor', 'webshell', 'c99', 'r57', 'wso',
            // File upload exploits
            'move_uploaded_file', 'copy(', 'rename(',
            // Database connection patterns that shouldn't be in files
            'mysql_connect(', 'mysqli_connect(', 'pdo(',
            // WordPress core file modification indicators
            'wp-config', 'wp-load.php', 'functions.php',
            // Plugin/theme manipulation
            'wp_enqueue_script', 'wp_enqueue_style'
        ];

        foreach ($file_critical_patterns as $cp) {
            if (strpos($pattern, $cp) !== false || strpos($match, $cp) !== false) {
                return 'critical';
            }
        }

        // WARNING: Suspicious file patterns
        $file_warning_patterns = [
            'javascript:', 'onclick', 'onload', 'onerror', 'iframe', 'script',
            'union select', 'information_schema', 'concat(', 'char(',
            'admin-ajax.php', 'ajaxurl', 'wp_verify_nonce', 'wp_create_nonce',
            'wp_redirect', 'wp_safe_redirect', 'wp_http_referer',
            'wp_option', 'get_option', 'update_option', 'add_option',
            'wp_usermeta', 'wp_commentmeta', 'wp_postmeta',
            'check_ajax_referer', 'wp_die', 'gzip', 'deflate', 'zlib', 'bzip2',
            // Obfuscation techniques
            'str_rot13', 'gzinflate', 'gzdecode', 'base64', 'encode', 'decode',
            // Suspicious file extensions in content
            '.exe', '.scr', '.bat', '.cmd', '.vbs', '.js', '.jar',
            // Directory traversal
            '..', '../', '..\\', '\\..', '/etc/passwd', '/etc/shadow',
            // Malicious domain patterns
            'russianteens', 'pharmacy', 'casino', 'lottery', 'viagra'
        ];

        foreach ($file_warning_patterns as $wp) {
            if (strpos($pattern, $wp) !== false || strpos($match, $wp) !== false) {
                return 'warning';
            }
        }

        // INFO: Low-risk file patterns (monitoring, tracking, etc.)
        return 'info';
    }

    // INTEGRITY VIOLATIONS - Always critical (potential reinfection)
    elseif ($section === 'integrity') {
        return 'critical';
    }

    // UNKNOWN SOURCE - Fallback logic for edge cases
    else {
        // Apply general critical checks
        $general_critical_patterns = [
            'eval(', 'base64_decode', 'assert', 'system(', 'exec(', 'shell_exec',
            'shell_exec', 'passthru', 'create_function'
        ];

        foreach ($general_critical_patterns as $cp) {
            if (strpos($pattern, $cp) !== false || strpos($match, $cp) !== false) {
                return 'critical';
            }
        }

        // Default to warning for unknown sources (better safe than sorry)
        return 'warning';
    }
}

/**
 * Extract plugin/theme/core information from threat data
 */
function clean_sweep_categorize_threat($threat, $section) {
    if ($section === 'database') {
        // Database threats - categorize by table
        $table = $threat['_table'] ?? '';
        switch ($table) {
            case 'wp_posts':
                return 'WordPress Post Content';
            case 'wp_options':
                return 'WordPress Options';
            case 'wp_comments':
                return 'WordPress Comments';
            case 'wp_users':
            case 'wp_usermeta':
                return 'User Data';
            case 'wp_postmeta':
                return 'Post Metadata';
            case 'wp_commentmeta':
                return 'Comment Metadata';
            default:
                return 'Database (' . $table . ')';
        }
    } elseif ($section === 'files') {
        $file = $threat['file'] ?? '';

        // Extract plugin/theme from file path
        if (strpos($file, 'wp-content/plugins/') === 0) {
            // wp-content/plugins/plugin-name/...
            $plugin_path = substr($file, 20); // Remove wp-content/
            $parts = explode('/', $plugin_path);
            $plugin_slug = $parts[1] ?? 'unknown-plugin';

            // Try to get human-readable name
            $plugin_name = ucwords(str_replace(['-', '_'], ' ', $plugin_slug));
            return $plugin_name . ' Plugin';
        } elseif (strpos($file, 'wp-content/themes/') === 0) {
            // wp-content/themes/theme-name/...
            $theme_path = substr($file, 19); // Remove wp-content/
            $parts = explode('/', $theme_path);
            $theme_slug = $parts[1] ?? 'unknown-theme';

            $theme_name = ucwords(str_replace(['-', '_'], ' ', $theme_slug));
            return $theme_name . ' Theme';
        } elseif (strpos($file, 'wp-admin/') === 0) {
            return 'WordPress Admin';
        } elseif (strpos($file, 'wp-includes/') === 0) {
            return 'WordPress Core';
        } elseif ($file === 'wp-config.php' || strpos($file, 'wp-config') === 0) {
            return 'WordPress Configuration';
        } elseif (strpos($file, 'wp-content/uploads/') === 0) {
            return 'Uploads Directory';
        } else {
            return 'WordPress Files';
        }
    } elseif ($section === 'integrity') {
        return 'Reinfection Alerts';
    }

    return 'General Threats';
}

/**
 * Count threats by risk level
 */
function clean_sweep_count_threats_by_risk($scan_results) {
    $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
    $threats = clean_sweep_extract_all_threats($scan_results);

    foreach ($threats as $threat) {
        $risk = clean_sweep_assign_risk_level($threat);
        $counts[$risk]++;
    }

    return $counts;
}

/**
 * Extract all threats from scan results (handles different scan formats)
 */
function clean_sweep_extract_all_threats($scan_results) {
    $all_threats = [];

    // ========================================================================
    // DATABASE THREATS - Handle multiple formats
    // ========================================================================
    if (isset($scan_results['database'])) {
        $db_data = $scan_results['database'];

        // FORMAT 1: Nested table structure (regular scan)
        // ['database' => ['wp_posts' => ['threat1', 'threat2'], 'wp_options' => [...]]]
        if (is_array($db_data) && !empty($db_data)) {
            foreach ($db_data as $table => $threats) {
                // Ensure $threats is an array of threat arrays
                if (is_array($threats) && !empty($threats)) {
                    foreach ($threats as $threat) {
                        if (is_array($threat)) {
                            $threat['_section'] = 'database';
                            $threat['_table'] = $table;
                            $all_threats[] = $threat;
                        }
                    }
                }
                // Handle case where threats is a single threat object (not array)
                elseif (is_array($threats) && isset($threats['pattern'])) {
                    $threats['_section'] = 'database';
                    $threats['_table'] = $table;
                    $all_threats[] = $threats;
                }
            }
        }
        // FORMAT 2: Flat array of threats (deep scan)
        // ['database' => ['threat1', 'threat2', 'threat3']]
        elseif (is_array($db_data) && isset($db_data[0]) && is_array($db_data[0])) {
            foreach ($db_data as $threat) {
                if (is_array($threat)) {
                    $threat['_section'] = 'database';
                    $threat['_table'] = 'various'; // Generic table name
                    $all_threats[] = $threat;
                }
            }
        }
        // FORMAT 3: Single threat object
        elseif (is_array($db_data) && isset($db_data['pattern'])) {
            $db_data['_section'] = 'database';
            $db_data['_table'] = 'various';
            $all_threats[] = $db_data;
        }
        // FORMAT 4: Skip scalars and unexpected types (prevents crash)
        // else: $db_data is scalar, null, or unexpected format - skip
    }

    // ========================================================================
    // FILE THREATS - Handle multiple formats
    // ========================================================================
    if (isset($scan_results['files'])) {
        $file_data = $scan_results['files'];

        // FORMAT 1: Categorized structure (regular scan)
        // ['files' => ['wp_content' => ['threat1', 'threat2'], 'wp_config' => [...]]]
        if (is_array($file_data) && !empty($file_data)) {
            $categories = ['wp_content', 'wp_config', 'wp_includes', 'wp_admin', 'root'];
            foreach ($categories as $category) {
                if (isset($file_data[$category]) && is_array($file_data[$category])) {
                    foreach ($file_data[$category] as $threat) {
                        if (is_array($threat)) {
                            $threat['_section'] = 'files';
                            $threat['_category'] = $category;
                            $all_threats[] = $threat;
                        }
                    }
                }
            }

            // Also handle any additional categories that might exist
            foreach ($file_data as $category => $threats) {
                if (is_array($threats) && !in_array($category, $categories)) {
                    foreach ($threats as $threat) {
                        if (is_array($threat)) {
                            $threat['_section'] = 'files';
                            $threat['_category'] = $category;
                            $all_threats[] = $threat;
                        }
                    }
                }
            }
        }
        // FORMAT 2: Flat array of file threats (deep scan)
        // ['files' => ['threat1', 'threat2', 'threat3']]
        elseif (is_array($file_data) && isset($file_data[0]) && is_array($file_data[0])) {
            foreach ($file_data as $threat) {
                if (is_array($threat)) {
                    $threat['_section'] = 'files';
                    $threat['_category'] = 'various';
                    $all_threats[] = $threat;
                }
            }
        }
        // FORMAT 3: Single threat object
        elseif (is_array($file_data) && isset($file_data['pattern'])) {
            $file_data['_section'] = 'files';
            $file_data['_category'] = 'various';
            $all_threats[] = $file_data;
        }
        // FORMAT 4: Skip scalars and unexpected types (prevents crash)
    }

    // ========================================================================
    // INTEGRITY VIOLATIONS - Handle integrity check results
    // ========================================================================
    if (isset($scan_results['integrity']) && is_array($scan_results['integrity'])) {
        foreach ($scan_results['integrity'] as $violation) {
            if (is_array($violation)) {
                $violation['_section'] = 'integrity';
                $violation['_category'] = 'File Integrity';
                $all_threats[] = $violation;
            }
        }
    }

    return $all_threats;
}
