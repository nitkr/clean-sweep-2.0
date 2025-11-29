<?php
/**
 * Clean Sweep - Utility Functions
 *
 * Contains utility functions for logging, file operations, and helpers
 * used throughout the Clean Sweep toolkit.
 *
 * @author Nithin K R
 */

/**
 * Logging function
 */
function clean_sweep_log_message($message, $type = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$type] $message\n";

    // Ensure logs directory exists
    if (!is_dir(LOGS_DIR)) {
        mkdir(LOGS_DIR, 0755, true);
    }

    // Write to log file
    file_put_contents(LOGS_DIR . LOG_FILE, $log_entry, FILE_APPEND);

    // Output to screen/console (only for CLI, hide from browser since we have separate log file)
    if (defined('WP_CLI') && WP_CLI) {
        echo $log_entry;
    }
    // Browser logs are now hidden - all logging goes to file only
}

/**
 * Sanitize array data to ensure valid UTF-8 encoding (fixes JSON encoding errors)
 */
function clean_sweep_sanitize_utf8_array($array) {
    if (!is_array($array)) {
        return $array;
    }

    // Check if mbstring extension is available (graceful degradation)
    static $mbstring_available = null;
    if ($mbstring_available === null) {
        $mbstring_available = function_exists('mb_check_encoding') &&
                             function_exists('mb_convert_encoding') &&
                             function_exists('mb_strpos'); // Extra check
    }

    if (!$mbstring_available) {
        // Log warning about missing extension (once per session)
        static $warning_logged = false;
        if (!$warning_logged) {
            clean_sweep_log_message("WARNING: PHP mbstring extension not enabled. " .
                                   "UTF-8 sanitization degraded to basic replacement. " .
                                   "Consider asking your hosting provider to enable mbstring " .
                                   "for better JSON encoding reliability.", 'warning');
            $warning_logged = true;
        }

        // Fall back to basic UTF-8 sanitization without mbstring
        return clean_sweep_basic_utf8_cleanup($array);
    }

    // Proceed with advanced mbstring-based processing
    foreach ($array as $key => $value) {
        if (is_string($value)) {
            // Convert invalid UTF-8 sequences to valid UTF-8
            $cleaned = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($cleaned === false) {
                // If iconv fails, try mb_convert_encoding
                $cleaned = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
            // If still problematic, fall back to a safe replacement
            if ($cleaned === false || !mb_check_encoding($cleaned, 'UTF-8')) {
                $cleaned = preg_replace('/[^\x{0000}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '?', $value);
            }
            $array[$key] = $cleaned;
        } elseif (is_array($value)) {
            $array[$key] = clean_sweep_sanitize_utf8_array($value);
        } elseif (is_object($value)) {
            // Handle objects (convert to array and process)
            $obj_as_array = (array) $value;
            $sanitized_obj = clean_sweep_sanitize_utf8_array($obj_as_array);
            $array[$key] = (object) $sanitized_obj;
        }
        // Leave other types (int, float, bool, null) unchanged
    }
    return $array;
}

/**
 * Basic UTF-8 cleanup without mbstring (fallback for servers without mbstring)
 */
function clean_sweep_basic_utf8_cleanup($array) {
    if (!is_array($array)) {
        return $array;
    }

    foreach ($array as $key => $value) {
        if (is_string($value)) {
            // Basic UTF-8 cleanup using iconv (if available) and preg_replace
            $cleaned = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($cleaned === false) {
                // If iconv also fails, use simple regex replacement
                $cleaned = preg_replace('/[^\x{0000}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '?', $value);
                if ($cleaned === null) { // preg_replace can fail on bad UTF-8
                    $cleaned = $value; // Use original as last resort
                }
            }
            $array[$key] = $cleaned;
        } elseif (is_array($value)) {
            $array[$key] = clean_sweep_basic_utf8_cleanup($value);
        } elseif (is_object($value)) {
            // Handle objects (convert to array and process)
            $obj_as_array = (array) $value;
            $sanitized_obj = clean_sweep_basic_utf8_cleanup($obj_as_array);
            $array[$key] = (object) $sanitized_obj;
        }
        // Leave other types unchanged
    }
    return $array;
}

/**
 * Recursively delete a directory and all its contents
 */
function clean_sweep_recursive_delete($dir) {
    if (!is_dir($dir)) {
        return false;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }

    return rmdir($dir);
}

/**
 * Check if plugin is from WordPress.org repository
 */
function clean_sweep_is_wordpress_org_plugin($plugin_file) {
    $plugin_data = get_plugin_data($plugin_file);

    // Check if Plugin URI contains wordpress.org
    if (isset($plugin_data['PluginURI']) && strpos($plugin_data['PluginURI'], 'wordpress.org') !== false) {
        return true;
    }

    // Check if plugin slug can be derived and exists in WP.org API
    $slug = basename(dirname($plugin_file));
    if ($slug && $slug !== '.' && $slug !== '..') {
        $api_url = "https://api.wordpress.org/plugins/info/1.0/$slug.json";
        $response = wp_remote_get($api_url);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            return isset($data['slug']) && $data['slug'] === $slug;
        }
    }

    return false;
}

/**
 * Get list of active plugins
 */
function clean_sweep_get_active_plugins_list() {
    $active_plugins = get_option('active_plugins', array());
    return $active_plugins;
}

/**
 * Write progress data to JSON file for AJAX polling
 * Keeps files in logs directory for web-accessibility throughout entire operation
 */
function clean_sweep_write_progress_file($progress_file, $data) {
    if (!$progress_file) return;

    // Store in PROGRESS_DIR (logs/ directory) for web access during operations
    $file_path = PROGRESS_DIR . $progress_file;

    $json_data = json_encode($data, JSON_PRETTY_PRINT);

    // Ensure directory exists
    $dir = dirname($file_path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    @file_put_contents($file_path, $json_data);
}

/**
 * Reset PHP execution time to prevent timeouts on shared hosting
 * Call this regularly during long-running operations
 */
function clean_sweep_reset_execution_time() {
    // Only reset if we're still within the shared hosting limit
    if (HOSTING_SHARED_LIMITS) {
        $current_execution_time = ini_get('max_execution_time');
        // Reset execution time if we have more than 5 seconds left
        if ($current_execution_time && $current_execution_time <= MAX_EXECUTION_TIME) {
            set_time_limit(MAX_EXECUTION_TIME);
        }
    } else {
        // For dedicated servers, be more aggressive with time limits
        set_time_limit(120); // 2 minutes
    }
}

/**
 * Aggressive memory cleanup to prevent memory exhaustion on shared hosting
 */
function clean_sweep_memory_cleanup() {
    // Force garbage collection if available
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    // Clear any large variables from memory
    if (isset($GLOBALS['clean_sweep_temp_data'])) {
        unset($GLOBALS['clean_sweep_temp_data']);
    }

    // Log memory usage if we're approaching limits
    $memory_usage = memory_get_usage(true);
    $memory_limit = ini_get('memory_limit');

    if ($memory_limit) {
        $memory_limit_bytes = 0;
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            if ($matches[2] == 'M') {
                $memory_limit_bytes = $matches[1] * 1048576; // nnnM -> nnn MB
            } else if ($matches[2] == 'K') {
                $memory_limit_bytes = $matches[1] * 1024; // nnnK -> nnn KB
            }
        }

        // Warn if using more than 80% of available memory
        if ($memory_limit_bytes > 0 && ($memory_usage / $memory_limit_bytes) > 0.8) {
            clean_sweep_log_message(
                "High memory usage detected: " . round($memory_usage / 1048576, 1) . "MB / " .
                round($memory_limit_bytes / 1048576, 1) . "MB - forcing garbage collection",
                'warning'
            );

            // Force another garbage collection and clear output buffers
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // Clear any output buffering
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Reinitialize output buffering
            ob_start();
        }
    }
}

/**
 * Progress heartbeat - send frequent updates to prevent AJAX timeouts
 */
function clean_sweep_progress_heartbeat($progress_file, $data, &$last_heartbeat) {
    $current_time = time();

    // Only send heartbeat every PROGRESS_HEARTBEAT_INTERVAL seconds
    if (($current_time - $last_heartbeat) >= PROGRESS_HEARTBEAT_INTERVAL) {
        clean_sweep_write_progress_file($progress_file, $data);
        $last_heartbeat = $current_time;

        // Also do memory cleanup and execution time reset during heartbeat
        clean_sweep_memory_cleanup();
        clean_sweep_reset_execution_time();
    }
}

/**
 * Calculate directory size recursively (for backup size estimation)
 */
function clean_sweep_get_directory_size($directory) {
    $size = 0;

    if (!is_dir($directory)) {
        return filesize($directory);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }

    return $size;
}

/**
 * Calculate backup size for plugin reinstallation
 * Fast estimation using directory size + buffer for compression
 */
function clean_sweep_calculate_plugin_backup_size() {
    $plugins_dir = WP_PLUGIN_DIR;
    $total_size = 0;

    clean_sweep_log_message("Calculating plugin backup size using fast directory estimation", 'info');

    // Calculate size of all plugin directories (recursive)
    $plugin_dirs = glob($plugins_dir . '/*', GLOB_ONLYDIR);
    foreach ($plugin_dirs as $dir) {
        $total_size += clean_sweep_get_directory_size($dir);
        clean_sweep_log_message("Directory {$dir}: " . clean_sweep_get_directory_size($dir) . " bytes", 'debug');
    }

    // Calculate size of single files in plugins root directory
    $plugin_files = glob($plugins_dir . '/*.php');
    $single_file_size = 0;
    foreach ($plugin_files as $file) {
        if (is_file($file)) {
            $file_size = filesize($file);
            $single_file_size += $file_size;
            clean_sweep_log_message("Single file {$file}: {$file_size} bytes", 'debug');
        }
    }

    $total_size += $single_file_size;

    // Add 15% buffer for ZIP compression overhead and filesystem metadata
    $estimated_size = $total_size * 1.15;

    clean_sweep_log_message("Plugin backup estimation: directories=" . ($total_size - $single_file_size) .
                           " bytes, single_files={$single_file_size} bytes, total={$total_size} bytes, " .
                           "estimated_with_buffer=" . round($estimated_size) . " bytes", 'info');

    return round($estimated_size);
}

/**
 * Fallback directory size calculation (original method)
 */
function clean_sweep_calculate_directory_size_fallback() {
    $total_size = 0;

    // Calculate size of all plugin directories
    $plugin_dirs = glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR);

    foreach ($plugin_dirs as $dir) {
        $total_size += clean_sweep_get_directory_size($dir);
    }

    // Also include single-file plugins in root directory
    $plugin_files = glob(WP_PLUGIN_DIR . '/*.php');
    foreach ($plugin_files as $file) {
        if (is_file($file)) {
            $total_size += filesize($file);
        }
    }

    clean_sweep_log_message("Using fallback directory size calculation: {$total_size} bytes", 'info');
    return $total_size;
}

/**
 * Calculate backup size for core reinstallation
 * Fast estimation using directory size + buffer for compression
 */
function clean_sweep_calculate_core_backup_size() {
    $total_size = 0;

    clean_sweep_log_message("Calculating core backup size using fast directory estimation", 'info');

    // Files that get backed up during core reinstallation
    $preserve_files = [
        'wp-config.php',
        'wp-content',    // This is a directory
        '.htaccess',
        'robots.txt'
    ];

    foreach ($preserve_files as $file) {
        $full_path = ABSPATH . $file;
        if (file_exists($full_path)) {
            if (is_dir($full_path)) {
                $dir_size = clean_sweep_get_directory_size($full_path);
                $total_size += $dir_size;
                clean_sweep_log_message("Directory {$file}: {$dir_size} bytes", 'debug');
            } else {
                $file_size = filesize($full_path);
                $total_size += $file_size;
                clean_sweep_log_message("File {$file}: {$file_size} bytes", 'debug');
            }
        }
    }

    // Add 15% buffer for ZIP compression overhead and filesystem metadata
    $estimated_size = $total_size * 1.15;

    clean_sweep_log_message("Core backup estimation: raw_size={$total_size} bytes, " .
                           "estimated_with_buffer=" . round($estimated_size) . " bytes", 'info');

    return round($estimated_size);
}

/**
 * Check disk space and provide backup size information with user choice
 * Always returns size info and allows user to choose backup or skip
 */
function clean_sweep_check_disk_space($operation_type, $allow_proceed_without_backup = true) {
    // Calculate actual backup size needed
    if ($operation_type === 'plugin_reinstall') {
        $backup_size_bytes = clean_sweep_calculate_plugin_backup_size();
        $operation_name = 'plugin reinstallation';
    } elseif ($operation_type === 'core_reinstall') {
        $backup_size_bytes = clean_sweep_calculate_core_backup_size();
        $operation_name = 'core reinstallation';
    } else {
        return ['success' => false, 'message' => 'Unknown operation type'];
    }

    // Convert to MB for display
    $backup_size_mb = round($backup_size_bytes / (1024 * 1024), 1);

    // Add 20% buffer for overhead (compression, temporary files, etc.)
    $required_size_bytes = $backup_size_bytes * 1.2;
    $required_size_mb = round($required_size_bytes / (1024 * 1024), 1);

    // Get available disk space with debug logging
    $abspath = ABSPATH;
    $available_bytes = disk_free_space($abspath);
    $available_mb = round($available_bytes / (1024 * 1024), 1);

    // Debug logging for disk space issues
    clean_sweep_log_message("Disk space check debug: Operation='$operation_type', ABSPATH='$abspath'", 'info');
    clean_sweep_log_message("Disk space check debug: Backup size={$backup_size_mb}MB, Required with buffer={$required_size_mb}MB, Available={$available_mb}MB", 'info');

    // Always provide backup choice information
    $result = [
        'success' => true, // Always allow proceeding (with or without backup)
        'can_proceed' => true,
        'backup_size_mb' => $backup_size_mb,
        'required_mb' => $required_size_mb,
        'available_mb' => $available_mb,
        'show_choice' => true, // Always show backup choice UI
        'recommendation' => 'backup' // Default recommendation
    ];

    // Check if sufficient space is available for backup
    if ($available_bytes >= $required_size_bytes) {
        $result['space_status'] = 'sufficient';
        $result['message'] = "Sufficient space available for {$operation_name} backup.";
    } else {
        // Insufficient space for backup
        $shortfall_mb = round(($required_size_bytes - $available_bytes) / (1024 * 1024), 1);
        $result['space_status'] = 'insufficient';
        $result['shortfall_mb'] = $shortfall_mb;
        $result['recommendation'] = 'skip_backup'; // Recommend skipping backup
        $result['message'] = "{$operation_name} backup needs {$required_size_mb}MB (includes 20% buffer). Only {$available_mb}MB available.";
        $result['warning'] = "⚠️ Insufficient space for backup. Proceeding without backup increases risk of data loss if {$operation_name} fails.";
        $result['recommendations'] = [
            'Free up disk space by deleting unnecessary files',
            'Consider external backup before proceeding',
            'Contact hosting provider for disk space increase'
        ];
    }

    return $result;
}
