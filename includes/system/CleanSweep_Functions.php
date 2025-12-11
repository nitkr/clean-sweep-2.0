<?php
/**
 * Clean Sweep - Embedded WordPress Functions
 *
 * Provides WordPress-compatible functions without loading WordPress core.
 *
 * @version 1.0
 * @author Nithin K R
 */

/**
 * Translate paths from fresh environment to site in recovery mode
 * This ensures all file operations target the infected site instead of the fresh environment
 */
function clean_sweep_translate_path($path) {
    if (defined('CLEAN_SWEEP_RECOVERY_MODE') && CLEAN_SWEEP_RECOVERY_MODE && defined('SITE_ABSPATH')) {
        // Only translate if the path starts with the fresh environment ABSPATH
        if (is_string($path) && str_starts_with($path, ABSPATH)) {
            $translated = str_replace(ABSPATH, SITE_ABSPATH, $path);
            // Log translation for debugging
            clean_sweep_log_message("🔄 Path translated: {$path} → {$translated}", 'debug');
            return $translated;
        }
    }
    return $path;
}

class CleanSweep_Functions {

    private $db;

    public function __construct($db = null) {
        $this->db = $db;
        $this->init_functions();
    }

    /**
     * Initialize all embedded functions
     */
    private function init_functions() {
        $this->init_get_plugins();
        // $this->init_download_url(); // REMOVED: Duplicate declaration - already declared in clean-sweep-bootstrap.php
        // $this->init_unzip_file(); // REMOVED: Duplicate declaration - already declared in clean-sweep-bootstrap.php
        $this->init_wp_error();
        $this->init_is_wp_error();
        $this->init_filesystem();
        $this->init_integrity_checks();
    }

    /**
     * Initialize get_plugins() function
     */
    private function init_get_plugins() {
        if (!function_exists('get_plugins')) {
            function get_plugins($plugin_folder = '') {
                global $clean_sweep_db;

                if (!$clean_sweep_db) {
                    return [];
                }

                $table_prefix = $clean_sweep_db->get_table_prefix();
                $plugins_table = $table_prefix . 'options';

                // Get active plugins from database
                $active_plugins = $clean_sweep_db->get_var(
                    "SELECT option_value FROM {$plugins_table} WHERE option_name = 'active_plugins'"
                );

                if ($active_plugins) {
                    $active_plugins = unserialize($active_plugins);
                } else {
                    $active_plugins = [];
                }

                // Get all plugin files from filesystem
                // In recovery mode, scan site's plugins directory
                $plugins_dir = SITE_WP_PLUGIN_DIR;
                $all_plugins = [];

                if (is_dir($plugins_dir)) {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($plugins_dir, RecursiveDirectoryIterator::SKIP_DOTS)
                    );

                    foreach ($iterator as $file) {
                        if ($file->isFile() && $file->getExtension() === 'php') {
                            $relative_path = str_replace($plugins_dir, '', $file->getPathname());

                            // Only process main plugin files (not in subdirectories unless it's a plugin folder)
                            $path_parts = explode('/', $relative_path);
                            if (count($path_parts) === 1 || $path_parts[0] . '/' . $path_parts[0] . '.php' === $relative_path) {
                                $plugin_data = get_plugin_data($file->getPathname());
                                if (!empty($plugin_data['Name'])) {
                                    $plugin_key = $relative_path;
                                    $all_plugins[$plugin_key] = $plugin_data;

                                    // Mark as active if in active plugins list
                                    if (in_array($plugin_key, $active_plugins)) {
                                        $all_plugins[$plugin_key]['active'] = true;
                                    }
                                }
                            }
                        }
                    }
                }

                return $all_plugins;
            }
        }

        if (!function_exists('get_plugin_data')) {
            function get_plugin_data($plugin_file, $markup = true, $translate = true) {
                $default_headers = [
                    'Name' => 'Plugin Name',
                    'PluginURI' => 'Plugin URI',
                    'Version' => 'Version',
                    'Description' => 'Description',
                    'Author' => 'Author',
                    'AuthorURI' => 'Author URI',
                    'TextDomain' => 'Text Domain',
                    'DomainPath' => 'Domain Path',
                    'Network' => 'Network',
                    'RequiresWP' => 'Requires at least',
                    'RequiresPHP' => 'Requires PHP',
                    'UpdateURI' => 'Update URI',
                ];

                $plugin_data = [];

                if (!file_exists($plugin_file)) {
                    return $plugin_data;
                }

                $file_data = file_get_contents($plugin_file, false, null, 0, 8192);

                foreach ($default_headers as $field => $regex) {
                    if (preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $file_data, $match) && $match[1]) {
                        $plugin_data[$field] = trim(preg_replace("/\s*(?:\*\/|\?>).*/", '', $match[1]));
                    } else {
                        $plugin_data[$field] = '';
                    }
                }

                if ($markup || $translate) {
                    $plugin_data = array_map('trim', $plugin_data);

                    if ($translate) {
                        $plugin_data = array_map('translate', $plugin_data);
                    }

                    if ($markup) {
                        if (!empty($plugin_data['PluginURI']) && !empty($plugin_data['Name'])) {
                            $plugin_data['Title'] = '<a href="' . $plugin_data['PluginURI'] . '">' . $plugin_data['Name'] . '</a>';
                        } else {
                            $plugin_data['Title'] = $plugin_data['Name'];
                        }

                        if (!empty($plugin_data['AuthorURI']) && !empty($plugin_data['Author'])) {
                            $plugin_data['AuthorName'] = '<a href="' . $plugin_data['AuthorURI'] . '">' . $plugin_data['Author'] . '</a>';
                        } else {
                            $plugin_data['AuthorName'] = $plugin_data['Author'];
                        }
                    }
                }

                return $plugin_data;
            }
        }
    }





    /**
     * Initialize WP_Error class reference
     */
    private function init_wp_error() {
        // WP_Error class is defined outside this class to avoid nesting
        // This method just ensures it's loaded
    }

    /**
     * Initialize is_wp_error() function
     */
    private function init_is_wp_error() {
        if (!function_exists('is_wp_error')) {
            function is_wp_error($thing) {
                return ($thing instanceof WP_Error);
            }
        }
    }

    /**
     * Initialize filesystem functions
     */
    private function init_filesystem() {
        // WP_Filesystem is provided by WordPress core - don't declare it here
        // Just ensure we initialize the filesystem with our custom class if WordPress hasn't done it

        // Initialize filesystem on first call
        if (!function_exists('clean_sweep_init_filesystem')) {
            function clean_sweep_init_filesystem() {
                global $wp_filesystem;
                // If WordPress hasn't initialized filesystem yet, do it with our class
                if (!isset($wp_filesystem) && function_exists('WP_Filesystem')) {
                    WP_Filesystem(); // Use WordPress core function
                }
                // If still not set, use our fallback
                if (!isset($wp_filesystem)) {
                    $wp_filesystem = new Clean_Sweep_Filesystem();
                }
            }
        }
    }

    /**
     * Initialize integrity checking functions
     */
    private function init_integrity_checks() {
        // Include global integrity functions
        require_once __DIR__ . '/CleanSweep_Integrity.php';

        // Initialize integrity class for advanced features
        $this->integrity_manager = new CleanSweep_Integrity();
    }

    /**
     * Get integrity manager instance
     */
    public function get_integrity_manager() {
        return $this->integrity_manager;
    }
}

/**
 * Check disk space availability for backup operations
 *
 * @param string $operation_type Type of operation ('plugin_reinstall', 'core_reinstall', etc.)
 * @return array Disk space information
 */
function clean_sweep_check_disk_space($operation_type = 'plugin_reinstall') {
    try {
        // Get disk space information with fallbacks for restricted hosting
        $total_space = false;
        $free_space = false;

        // Try to get total space (often disabled on shared hosting)
        if (function_exists('disk_total_space')) {
            $total_space = @disk_total_space(ABSPATH);
        }

        // Try to get free space (sometimes available even when total is not)
        if (function_exists('disk_free_space')) {
            $free_space = @disk_free_space(ABSPATH);
        }

        // If both functions failed, provide fallback behavior
        if ($total_space === false && $free_space === false) {
            clean_sweep_log_message("Disk space functions not available - using fallback mode", 'warning');

            // Provide reasonable defaults for backup size estimation
            // Without disk space info, we'll still show the UI but with warnings
            $estimated_free_mb = 100; // Assume 100MB minimum available
            $estimated_total_mb = 1000; // Assume 1GB total
            $free_mb = $estimated_free_mb;
            $total_mb = $estimated_total_mb;
            $disk_space_unavailable = true;
        } elseif ($free_space === false) {
            // Only free space failed - use estimated values
            clean_sweep_log_message("disk_free_space() not available - using estimated values", 'warning');
            $estimated_free_mb = 100; // Conservative estimate
            $free_mb = $estimated_free_mb;
            $total_mb = $total_space ? round($total_space / 1024 / 1024, 2) : 1000;
            $disk_space_unavailable = true;
        } else {
            // Disk space functions are available
            $total_mb = $total_space ? round($total_space / 1024 / 1024, 2) : null;
            $free_mb = round($free_space / 1024 / 1024, 2);
            $disk_space_unavailable = false;
        }

        if (!$disk_space_unavailable) {
            $used_mb = $total_mb ? $total_mb - $free_mb : 0;
        } else {
            $used_mb = 0; // Unknown when disk space unavailable
        }

        // Estimate backup size based on operation type
        $backup_size_mb = 0;
        $buffer_mb = 50; // Safety buffer

        switch ($operation_type) {
            case 'plugin_reinstall':
                // Estimate plugin backup size
                $plugins_dir = WP_PLUGIN_DIR;
                if (is_dir($plugins_dir)) {
                    $backup_size_mb = clean_sweep_calculate_directory_size($plugins_dir) / 1024 / 1024;
                    $backup_size_mb = round($backup_size_mb * 1.2, 2); // 20% overhead for zip compression
                }
                break;

            case 'core_reinstall':
                // Estimate core files backup size (wp-config.php, .htaccess, wp-content, etc.)
                $preserve_files = ['wp-config.php', 'wp-content', '.htaccess', 'robots.txt'];
                $site_root = clean_sweep_detect_site_root();

                foreach ($preserve_files as $file) {
                    $full_path = $site_root . '/' . $file;
                    if (file_exists($full_path)) {
                        if (is_dir($full_path)) {
                            $backup_size_mb += clean_sweep_calculate_directory_size($full_path) / 1024 / 1024;
                        } else {
                            $backup_size_mb += filesize($full_path) / 1024 / 1024;
                        }
                    }
                }
                $backup_size_mb = round($backup_size_mb * 1.3, 2); // 30% overhead for zip compression and logs
                break;

            default:
                $backup_size_mb = 100; // Default estimate
                break;
        }

        $required_mb = $backup_size_mb + $buffer_mb;
        $shortfall_mb = max(0, $required_mb - $free_mb);

        // Determine status
        $space_status = 'sufficient';
        $warning = '';
        $can_proceed = true;

        if ($shortfall_mb > 0) {
            $space_status = 'insufficient';
            $warning = "Insufficient disk space. Need {$required_mb}MB, only {$free_mb}MB available. Shortfall: {$shortfall_mb}MB.";
            $can_proceed = false;
        } elseif ($free_mb < 100) {
            $space_status = 'warning';
            $warning = "Low disk space warning. Only {$free_mb}MB available.";
        }

        clean_sweep_log_message("Disk space check for {$operation_type}: {$free_mb}MB available, {$backup_size_mb}MB backup needed, {$required_mb}MB required", 'info');

        return [
            'success' => true,
            'total_mb' => $total_mb,
            'used_mb' => $used_mb,
            'available_mb' => $free_mb,
            'backup_size_mb' => $backup_size_mb,
            'required_mb' => $required_mb,
            'shortfall_mb' => $shortfall_mb,
            'space_status' => $space_status,
            'warning' => $warning,
            'message' => $space_status === 'sufficient' ? 'Sufficient disk space available' : $warning,
            'can_proceed' => $can_proceed
        ];

    } catch (Exception $e) {
        clean_sweep_log_message("Disk space check failed: " . $e->getMessage(), 'error');
        return [
            'success' => false,
            'message' => 'Disk space check failed: ' . $e->getMessage(),
            'error' => $e->getMessage(),
            'space_status' => 'error'
        ];
    }
}

/**
 * Calculate directory size recursively
 *
 * @param string $directory Directory path
 * @return int Size in bytes
 */
function clean_sweep_calculate_directory_size($directory) {
    $size = 0;

    if (!is_dir($directory)) {
        return $size;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    } catch (Exception $e) {
        clean_sweep_log_message("Error calculating directory size for {$directory}: " . $e->getMessage(), 'warning');
    }

    return $size;
}
