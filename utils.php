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

    // Add .progress extension if not already present
    if (substr($progress_file, -9) !== '.progress') {
        $progress_file .= '.progress';
    }

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
 * Clean Sweep - Secure Download Function
 * Standalone implementation that doesn't depend on WordPress admin code
 * Downloads a file from URL to a temporary location
 *
 * @param string $url The URL to download from
 * @param int $timeout Timeout in seconds (default 300)
 * @return string|WP_Error Path to downloaded file or WP_Error on failure
 */
function clean_sweep_download_url($url, $timeout = 300) {
    // Ensure temp directory exists
    if (!is_dir(TEMP_DIR)) {
        mkdir(TEMP_DIR, 0755, true);
    }

    // Create temporary file
    $temp_file = tempnam(TEMP_DIR, 'clean_sweep_download_');

    // Initialize cURL
    $ch = curl_init($url);
    if (!$ch) {
        return new WP_Error('curl_init_failed', 'Failed to initialize cURL');
    }

    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_FILE, fopen($temp_file, 'w'));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Clean Sweep WordPress Recovery Tool');

    // Execute download
    $result = curl_exec($ch);
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Check for errors
    if ($result === false) {
        unlink($temp_file);
        return new WP_Error('download_failed', 'Download failed: ' . $error);
    }

    if ($http_code !== 200) {
        unlink($temp_file);
        return new WP_Error('http_error', 'HTTP error: ' . $http_code);
    }

    // Verify file was created and has content
    if (!file_exists($temp_file) || filesize($temp_file) === 0) {
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        return new WP_Error('empty_file', 'Downloaded file is empty');
    }

    clean_sweep_log_message("Successfully downloaded file to: $temp_file", 'info');
    return $temp_file;
}

/**
 * Clean Sweep - Secure Unzip Function
 * Standalone implementation using ZipArchive that doesn't depend on WordPress admin code
 *
 * @param string $zip_file Path to ZIP file
 * @param string $destination Destination directory
 * @return bool|WP_Error True on success or WP_Error on failure
 */
function clean_sweep_unzip_file($zip_file, $destination) {
    // Validate inputs
    if (!file_exists($zip_file)) {
        return new WP_Error('file_not_found', 'ZIP file does not exist: ' . $zip_file);
    }

    if (!is_readable($zip_file)) {
        return new WP_Error('file_not_readable', 'ZIP file is not readable: ' . $zip_file);
    }

    if (!is_dir($destination)) {
        if (!mkdir($destination, 0755, true)) {
            return new WP_Error('mkdir_failed', 'Failed to create destination directory: ' . $destination);
        }
    }

    if (!is_writable($destination)) {
        return new WP_Error('dir_not_writable', 'Destination directory is not writable: ' . $destination);
    }

    // Open ZIP file
    $zip = new ZipArchive();
    $result = $zip->open($zip_file);

    if ($result !== true) {
        $error_messages = [
            ZipArchive::ER_EXISTS => 'File already exists',
            ZipArchive::ER_INCONS => 'ZIP archive inconsistent',
            ZipArchive::ER_INVAL => 'Invalid argument',
            ZipArchive::ER_MEMORY => 'Memory allocation failure',
            ZipArchive::ER_NOENT => 'No such file',
            ZipArchive::ER_NOZIP => 'Not a ZIP archive',
            ZipArchive::ER_OPEN => 'Cannot open file',
            ZipArchive::ER_READ => 'Read error',
            ZipArchive::ER_SEEK => 'Seek error'
        ];

        $error_msg = isset($error_messages[$result]) ? $error_messages[$result] : 'Unknown ZIP error: ' . $result;
        return new WP_Error('zip_open_failed', 'Failed to open ZIP file: ' . $error_msg);
    }

    // Extract files
    if (!$zip->extractTo($destination)) {
        $zip->close();
        return new WP_Error('extract_failed', 'Failed to extract ZIP file to: ' . $destination);
    }

    $zip->close();

    clean_sweep_log_message("Successfully extracted ZIP file to: $destination", 'info');
    return true;
}
