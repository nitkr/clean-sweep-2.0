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
// CLEAN SWEEP SPECIFIC FUNCTIONS ONLY
// ============================================================================

/**
 * Note: WordPress core functions (download_url, unzip_file, etc.) are NOT defined here
 * because clean-sweep-bootstrap.php loads WordPress in local_core mode, which provides them.
 *
 * Fallback functions are only defined in recovery mode when WordPress core is unavailable.
 */

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
