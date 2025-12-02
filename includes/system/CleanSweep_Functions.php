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

    public function __construct($db) {
        $this->db = $db;
        $this->init_functions();
    }

    /**
     * Initialize all embedded functions
     */
    private function init_functions() {
        $this->init_get_plugins();
        $this->init_download_url();
        $this->init_unzip_file();
        $this->init_wp_error();
        $this->init_is_wp_error();
        $this->init_filesystem();
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
     * Initialize download_url() function
     */
    private function init_download_url() {
        if (!function_exists('download_url')) {
            function download_url($url, $timeout = 300) {
                // Use Clean Sweep backups directory instead of system temp
                $backup_dir = CLEAN_SWEEP_ROOT . 'backups';
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
    }

    /**
     * Initialize unzip_file() function
     */
    private function init_unzip_file() {
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
        // WP_Filesystem initialization
        if (!function_exists('WP_Filesystem')) {
            function WP_Filesystem() {
                global $wp_filesystem;
                if (!isset($wp_filesystem)) {
                    $wp_filesystem = new Clean_Sweep_Filesystem();
                }
                return true;
            }
        }

        // Initialize filesystem on first call
        if (!function_exists('clean_sweep_init_filesystem')) {
            function clean_sweep_init_filesystem() {
                WP_Filesystem();
            }
        }
    }
}
