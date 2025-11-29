<?php
/**
 * Clean Sweep Dependent Bootstrap
 *
 * Loads site's WordPress environment for full functionality and performance.
 * Falls back to Recovery mode if site files are corrupted.
 *
 * @version 1.0
 */

// ============================================================================
// ESSENTIAL CONSTANTS
// ============================================================================

if (!defined('ABSPATH')) {
    // When loaded from Clean Sweep, ABSPATH should point to WordPress root
    define('ABSPATH', dirname(dirname(__DIR__)) . '/');
}

// ============================================================================
// LOAD WORDPRESS ENVIRONMENT (DEPENDENT MODE)
// ============================================================================

// Load site's wp-config.php and wp-settings.php for full WordPress functionality
// This gives us access to the real wpdb class and all WordPress functions
$wp_config_paths = [
    ABSPATH . 'wp-config.php',
    dirname(ABSPATH) . '/wp-config.php'
];

$wp_config_found = false;
foreach ($wp_config_paths as $config_path) {
    if (file_exists($config_path)) {
        require_once $config_path;
        $wp_config_found = true;
        break;
    }
}

if (!$wp_config_found) {
    die('Clean Sweep: Could not find wp-config.php. Please ensure Clean Sweep is placed in your WordPress root directory.');
}

require_once ABSPATH . 'wp-settings.php';

// ============================================================================
// CLEAN SWEEP SPECIFIC FUNCTIONS
// ============================================================================

// ============================================================================
// CONDITIONAL FALLBACK FUNCTIONS
// ============================================================================

/**
 * Download URL function - ONLY defined when WordPress core unavailable
 * In local_core mode: WordPress provides this, so we don't define it
 * In recovery mode: WordPress unavailable, so we provide fallback
 */
if (!function_exists('download_url')) {
    function download_url($url, $timeout = 300) {
        // This function only exists in recovery mode when WordPress core is unavailable
        // Use Clean Sweep backups directory instead of system temp
        $backup_dir = __DIR__ . '/../backups';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }

        // Download file and save to backups directory
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $data = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($data === false) {
            return false;
        }

        // Save to temp file in backups directory and return path
        $temp_file = tempnam($backup_dir, 'download_');
        if ($temp_file && file_put_contents($temp_file, $data) !== false) {
            return $temp_file;
        }

        return false;
    }
}

// ============================================================================
// CUSTOM UNZIP FUNCTION (OVERRIDES WORDPRESS VERSION)
// ============================================================================

/**
 * Custom unzip_file function that overrides WordPress's version
 * Doesn't require filesystem constants like FS_CHMOD_DIR
 */
if (!function_exists('unzip_file')) {
    function unzip_file($file, $to) {
        // Use PHP's ZipArchive if available
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $result = $zip->open($file);

            if ($result === true) {
                // Create destination directory if it doesn't exist
                if (!is_dir($to)) {
                    mkdir($to, 0755, true);
                }

                // Extract all files
                $success = $zip->extractTo($to);
                $zip->close();

                if ($success) {
                    return true;
                } else {
                    return new WP_Error('unzip_failed', 'Failed to extract ZIP file');
                }
            } else {
                return new WP_Error('zip_open_failed', 'Failed to open ZIP file');
            }
        }

        // Fallback: try system unzip command
        if (function_exists('exec')) {
            $command = "unzip -q -o '$file' -d '$to' 2>/dev/null";
            exec($command, $output, $return_var);

            if ($return_var === 0) {
                return true;
            }
        }

        // Last resort: return error
        return new WP_Error('unzip_not_available', 'ZIP extraction not available');
    }
}

// ============================================================================
// FILESYSTEM COMPATIBILITY
// ============================================================================

/**
 * Minimal filesystem class for Clean Sweep compatibility
 */
if (!class_exists('Clean_Sweep_Filesystem')) {
    class Clean_Sweep_Filesystem {
        public $method = 'direct';

        public function rmdir($path, $recursive = false) {
            if ($recursive) {
                // Implement recursive delete directly
                return $this->recursive_delete($path);
            } else {
                return @rmdir($path);
            }
        }

        public function mkdir($path, $chmod = false) {
            return @mkdir($path, 0755, true);
        }

        public function delete($path, $recursive = false) {
            if (is_dir($path)) {
                return $recursive ? $this->recursive_delete($path) : @rmdir($path);
            } else {
                return @unlink($path);
            }
        }

        public function exists($path) {
            return file_exists($path);
        }

        public function is_dir($path) {
            return is_dir($path);
        }

        /**
         * Recursive directory deletion
         */
        private function recursive_delete($dir_path) {
            if (!is_dir($dir_path)) {
                return true;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir_path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getRealPath());
                } else {
                    @unlink($item->getRealPath());
                }
            }

            return @rmdir($dir_path);
        }
    }
}

/**
 * Initialize filesystem access
 */
function clean_sweep_init_filesystem() {
    global $wp_filesystem;

    // Initialize WordPress filesystem if possible
    if (!function_exists('WP_Filesystem')) {
        // Minimal filesystem compatibility
        if (!isset($wp_filesystem)) {
            $wp_filesystem = new Clean_Sweep_Filesystem();
        }
    } else {
        WP_Filesystem();
    }
}

// ============================================================================
// BOOTSTRAP EXECUTION
// ============================================================================

// Initialize components needed by Clean Sweep
clean_sweep_init_filesystem();

// Set error reporting
if (function_exists('error_reporting')) {
    error_reporting(E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_ERROR | E_WARNING | E_PARSE | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR);
}

// Log successful minimal bootstrap
if (function_exists('clean_sweep_log_message')) {
    clean_sweep_log_message("✅ Clean Sweep minimal bootstrap completed successfully", 'info');
}
