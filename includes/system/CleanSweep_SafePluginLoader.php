<?php
/**
 * Clean Sweep - Safe Plugin Loader
 *
 * Handles selective loading of safe plugins in recovery mode.
 * Works with the SafePluginRegistry to determine which plugins can be loaded.
 *
 * @version 1.0
 * @author Nithin K R
 */

class CleanSweep_SafePluginLoader {

    /**
     * @var CleanSweep_SafePluginRegistry
     */
    private $registry;

    /**
     * Currently loaded safe plugins
     *
     * @var array
     */
    private $loaded_plugins = [];

    public function __construct() {
        $this->registry = new CleanSweep_SafePluginRegistry();
    }

    /**
     * Load safe plugins required for a specific operation
     *
     * @param string $operation Operation name (e.g., 'wpmu_dev_reinstall')
     * @return array Results of loading operation
     */
    public function loadSafePluginsForOperation($operation) {
        clean_sweep_log_message("SafePluginLoader: Loading safe plugins for operation: {$operation}", 'info');

        $safe_plugins = $this->registry->getSafePluginsForOperation($operation);

        if (empty($safe_plugins)) {
            clean_sweep_log_message("SafePluginLoader: No safe plugins required for operation: {$operation}", 'debug');
            return ['loaded' => [], 'failed' => []];
        }

        $results = ['loaded' => [], 'failed' => []];

        foreach ($safe_plugins as $plugin_key => $plugin_config) {
            $result = $this->loadSafePlugin($plugin_config);

            if ($result['success']) {
                $results['loaded'][$plugin_key] = $result;
                $this->loaded_plugins[$plugin_key] = $plugin_config;
                clean_sweep_log_message("SafePluginLoader: Successfully loaded safe plugin: {$plugin_config['name']}", 'info');
            } else {
                $results['failed'][$plugin_key] = $result;
                clean_sweep_log_message("SafePluginLoader: Failed to load safe plugin: {$plugin_config['name']} - {$result['error']}", 'warning');
            }
        }

        clean_sweep_log_message("SafePluginLoader: Operation '{$operation}' - Loaded: " . count($results['loaded']) . ", Failed: " . count($results['failed']), 'info');

        return $results;
    }

    /**
     * Load a specific safe plugin
     *
     * @param array $plugin_config Plugin configuration from registry
     * @return array Loading result
     */
    private function loadSafePlugin($plugin_config) {
        $plugin_file = $plugin_config['file'];
        $plugin_name = $plugin_config['name'];
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

        // Double-check safety (registry should have already validated)
        if (!$this->registry->isPluginSafe($plugin_file)) {
            return [
                'success' => false,
                'error' => 'Plugin failed safety validation',
                'plugin' => $plugin_name
            ];
        }

        // Check if plugin file exists
        if (!file_exists($plugin_path)) {
            return [
                'success' => false,
                'error' => 'Plugin file not found',
                'plugin' => $plugin_name,
                'path' => $plugin_path
            ];
        }

        // Attempt to load the plugin
        try {
            // Set up environment for plugin loading
            if (!defined('WP_PLUGIN_DIR')) {
                define('WP_PLUGIN_DIR', dirname(dirname($plugin_path)));
            }

            // Load the plugin file
            $load_result = include_once $plugin_path;

            if ($load_result === false) {
                return [
                    'success' => false,
                    'error' => 'Plugin file failed to load (include_once returned false)',
                    'plugin' => $plugin_name
                ];
            }

            // Verify the plugin loaded successfully
            if (!$this->verifyPluginLoaded($plugin_config)) {
                return [
                    'success' => false,
                    'error' => 'Plugin loaded but verification failed',
                    'plugin' => $plugin_name
                ];
            }

            return [
                'success' => true,
                'plugin' => $plugin_name,
                'file' => $plugin_file,
                'path' => $plugin_path
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Exception during plugin loading: ' . $e->getMessage(),
                'plugin' => $plugin_name
            ];
        }
    }

    /**
     * Verify that a plugin loaded successfully
     *
     * @param array $plugin_config Plugin configuration
     * @return bool Whether verification passed
     */
    private function verifyPluginLoaded($plugin_config) {
        // Different verification strategies based on plugin category
        switch ($plugin_config['category']) {
            case 'premium_api':
                return $this->verifyPremiumApiPlugin($plugin_config);

            // Add more categories as needed
            default:
                // Basic verification - check if main class/function exists
                return $this->verifyBasicPluginLoad($plugin_config);
        }
    }

    /**
     * Verify premium API plugin loaded correctly
     *
     * @param array $plugin_config Plugin configuration
     * @return bool Whether verification passed
     */
    private function verifyPremiumApiPlugin($plugin_config) {
        // For WPMU DEV specifically
        if (strpos($plugin_config['file'], 'wpmudev-dashboard') !== false) {
            return class_exists('WPMUDEV_Dashboard');
        }

        // Generic premium API verification
        return true; // Assume success if no specific checks fail
    }

    /**
     * Basic plugin load verification
     *
     * @param array $plugin_config Plugin configuration
     * @return bool Whether verification passed
     */
    private function verifyBasicPluginLoad($plugin_config) {
        // Check if plugin functions/classes are available
        // This is a basic check - could be enhanced based on specific plugins

        return true; // Default to success
    }

    /**
     * Unload a previously loaded safe plugin
     *
     * @param string $plugin_key Plugin key from registry
     * @return bool Success
     */
    public function unloadSafePlugin($plugin_key) {
        if (!isset($this->loaded_plugins[$plugin_key])) {
            return false;
        }

        // Note: PHP doesn't have a direct "unload" mechanism for included files
        // We can only mark it as unloaded in our tracking
        unset($this->loaded_plugins[$plugin_key]);

        clean_sweep_log_message("SafePluginLoader: Marked plugin as unloaded: {$plugin_key}", 'debug');
        return true;
    }

    /**
     * Get list of currently loaded safe plugins
     *
     * @return array Loaded plugin configurations
     */
    public function getLoadedPlugins() {
        return $this->loaded_plugins;
    }

    /**
     * Check if a specific plugin is currently loaded
     *
     * @param string $plugin_key Plugin key from registry
     * @return bool Whether the plugin is loaded
     */
    public function isPluginLoaded($plugin_key) {
        return isset($this->loaded_plugins[$plugin_key]);
    }

    /**
     * Generate WordPress filter function for selective plugin loading
     *
     * @param string $operation Operation name
     * @return string PHP code for the filter function
     */
    public function generateSelectivePluginFilter($operation) {
        $safe_plugins = $this->registry->getSafePluginsForOperation($operation);

        if (empty($safe_plugins)) {
            // No safe plugins needed, suppress all
            return 'add_filter(\'option_active_plugins\', \'__return_empty_array\');';
        }

        // Generate code to load only safe plugins
        $code = "// Selective plugin loading for operation: {$operation}\n";
        $code .= "add_filter('option_active_plugins', function(\$plugins) {\n";

        // Convert safe plugin files to array for filtering
        $safe_files = array_column($safe_plugins, 'file');
        $code .= "    \$safe_plugins = " . var_export($safe_files, true) . ";\n";
        $code .= "    return array_intersect(\$plugins, \$safe_plugins);\n";
        $code .= "});\n\n";

        // Load the safe plugins explicitly
        $code .= "// Explicitly load safe plugins\n";
        foreach ($safe_plugins as $plugin_key => $plugin_config) {
            $code .= "if (file_exists(WP_PLUGIN_DIR . '/{$plugin_config['file']}')) {\n";
            $code .= "    include_once WP_PLUGIN_DIR . '/{$plugin_config['file']}';\n";
            $code .= "    clean_sweep_log_message('Loaded safe plugin: {$plugin_config['name']}', 'debug');\n";
            $code .= "}\n";
        }

        return $code;
    }

    /**
     * Get the registry instance for direct access
     *
     * @return CleanSweep_SafePluginRegistry
     */
    public function getRegistry() {
        return $this->registry;
    }
}
