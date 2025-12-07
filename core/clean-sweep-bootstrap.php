<?php
/**
 * Clean Sweep Independent Bootstrap
 *
 * Provides WordPress-compatible environment without loading WordPress core files.
 * Completely independent of site's wp-settings.php or wp-admin dependencies.
 *
 * @version 2.0
 */

// ============================================================================
// ESSENTIAL CONSTANTS
// ============================================================================

if (!defined('ABSPATH')) {
    // When loaded from Clean Sweep, ABSPATH should point to WordPress root
    // Find WordPress root by looking for wp-config.php
    $current_dir = CLEAN_SWEEP_ROOT;
    $wordpress_root = null;

    // Look for wp-config.php by walking up from Clean Sweep root
    for ($i = 0; $i < 5; $i++) { // Max 5 levels up
        if (file_exists($current_dir . 'wp-config.php')) {
            $wordpress_root = $current_dir;
            break;
        }
        $parent_dir = dirname(rtrim($current_dir, '/')) . '/';
        if ($parent_dir === $current_dir) {
            break; // Reached filesystem root
        }
        $current_dir = $parent_dir;
    }

    // Fallback to relative calculation if wp-config.php not found
    if (!$wordpress_root) {
        $wordpress_root = dirname(CLEAN_SWEEP_ROOT) . '/';
    }

    define('ABSPATH', $wordpress_root);
}

if (!defined('CLEAN_SWEEP_ROOT')) {
    // Find Clean Sweep root by locating the directory containing clean-sweep.php
    $current_dir = __DIR__;
    $clean_sweep_root = null;

    // Try to find clean-sweep.php by walking up directories
    for ($i = 0; $i < 5; $i++) { // Max 5 levels up to prevent infinite loop
        if (file_exists($current_dir . '/clean-sweep.php')) {
            $clean_sweep_root = $current_dir . '/';
            break;
        }
        $parent_dir = dirname($current_dir);
        if ($parent_dir === $current_dir) {
            break; // Reached filesystem root
        }
        $current_dir = $parent_dir;
    }

    // Fallback to relative path calculation if clean-sweep.php not found
    if (!$clean_sweep_root) {
        $clean_sweep_root = dirname(dirname(__DIR__)) . '/';
    }

    define('CLEAN_SWEEP_ROOT', $clean_sweep_root);
}

// ============================================================================
// INDEPENDENT WORDPRESS ENVIRONMENT
// ============================================================================

// Load Clean Sweep classes
require_once CLEAN_SWEEP_ROOT . 'includes/system/CleanSweep_DB.php';
require_once CLEAN_SWEEP_ROOT . 'includes/system/CleanSweep_Functions.php';

// Initialize database connection and functions
global $clean_sweep_db, $clean_sweep_functions;
$clean_sweep_db = new CleanSweep_DB();
$clean_sweep_functions = new CleanSweep_Functions($clean_sweep_db);

// ============================================================================
// CLEAN SWEEP SPECIFIC FUNCTIONS
// ============================================================================

/**
 * Download URL function - saves to Clean Sweep backups directory
 */
if (!function_exists('download_url')) {
    function download_url($url, $timeout = 300) {
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
 * Includes path translation for recovery mode compatibility
 */
if (!function_exists('unzip_file')) {
    function unzip_file($file, $to) {
        // Apply path translation in recovery mode for destination directory
        $to = clean_sweep_translate_path($to);

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
