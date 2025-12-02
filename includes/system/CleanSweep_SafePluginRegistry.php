<?php
/**
 * Clean Sweep - Safe Plugin Registry
 *
 * Manages a registry of plugins that are considered "safe" to load in recovery mode.
 * These plugins are required for specific operations but are deemed low-risk.
 *
 * @version 1.0
 * @author Nithin K R
 */

class CleanSweep_SafePluginRegistry {

    /**
     * Registry of safe plugins with their metadata
     *
     * @var array
     */
    private $safe_plugins = [];

    /**
     * Operations that require specific safe plugins
     *
     * @var array
     */
    private $operation_requirements = [];

    public function __construct() {
        $this->initializeRegistry();
        $this->initializeOperationRequirements();
    }

    /**
     * Initialize the safe plugins registry
     */
    private function initializeRegistry() {
        $this->safe_plugins = [
            'wpmudev_dashboard' => [
                'file' => 'wpmudev-dashboard/wpmudev-dashboard.php',
                'name' => 'WPMU DEV Dashboard',
                'category' => 'premium_api',
                'description' => 'WPMU DEV API provider for premium plugin management',
                'version_check' => true,
                'integrity_required' => true,
                'reason_safe' => 'Required for WPMU DEV API authentication and premium plugin downloads'
            ]
            // Future safe plugins can be added here
        ];
    }

    /**
     * Initialize operation requirements mapping
     */
    private function initializeOperationRequirements() {
        $this->operation_requirements = [
            'wpmu_dev_reinstall' => ['wpmudev_dashboard'],
            'premium_plugin_reinstall' => ['wpmudev_dashboard'],
            // Future operations can be mapped here
        ];
    }

    /**
     * Check if a plugin file is registered as safe
     *
     * @param string $plugin_file Plugin file path (e.g., 'plugin-slug/plugin-file.php')
     * @return bool Whether the plugin is safe to load
     */
    public function isPluginSafe($plugin_file) {
        foreach ($this->safe_plugins as $plugin) {
            if ($plugin['file'] === $plugin_file) {
                return $this->validatePluginSafety($plugin);
            }
        }
        return false;
    }

    /**
     * Get safe plugins required for a specific operation
     *
     * @param string $operation Operation name (e.g., 'wpmu_dev_reinstall')
     * @return array Array of safe plugin configurations
     */
    public function getSafePluginsForOperation($operation) {
        if (!isset($this->operation_requirements[$operation])) {
            return [];
        }

        $required_plugins = [];
        foreach ($this->operation_requirements[$operation] as $plugin_key) {
            if (isset($this->safe_plugins[$plugin_key])) {
                $plugin = $this->safe_plugins[$plugin_key];
                if ($this->validatePluginSafety($plugin)) {
                    $required_plugins[$plugin_key] = $plugin;
                }
            }
        }

        return $required_plugins;
    }

    /**
     * Get all registered safe plugins
     *
     * @return array All safe plugin configurations
     */
    public function getAllSafePlugins() {
        $validated_plugins = [];
        foreach ($this->safe_plugins as $key => $plugin) {
            if ($this->validatePluginSafety($plugin)) {
                $validated_plugins[$key] = $plugin;
            }
        }
        return $validated_plugins;
    }

    /**
     * Validate that a plugin meets safety criteria
     *
     * @param array $plugin Plugin configuration
     * @return bool Whether the plugin passes safety validation
     */
    private function validatePluginSafety($plugin) {
        // Check if plugin file exists in the site's plugins directory
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin['file'];
        if (!file_exists($plugin_path)) {
            clean_sweep_log_message("Safe plugin validation failed - file not found: {$plugin_path}", 'warning');
            return false;
        }

        // Version check if required
        if (!empty($plugin['version_check'])) {
            if (!$this->validatePluginVersion($plugin)) {
                clean_sweep_log_message("Safe plugin validation failed - version check: {$plugin['name']}", 'warning');
                return false;
            }
        }

        // Integrity check if required
        if (!empty($plugin['integrity_required'])) {
            if (!$this->validatePluginIntegrity($plugin)) {
                clean_sweep_log_message("Safe plugin validation failed - integrity check: {$plugin['name']}", 'warning');
                return false;
            }
        }

        return true;
    }

    /**
     * Validate plugin version compatibility
     *
     * @param array $plugin Plugin configuration
     * @return bool Whether version is compatible
     */
    private function validatePluginVersion($plugin) {
        // Get plugin data
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin['file'];
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_data = get_plugin_data($plugin_path);

        // Basic version validation - ensure it's a reasonable version string
        if (empty($plugin_data['Version'])) {
            return false;
        }

        // Could add more sophisticated version validation here
        return true;
    }

    /**
     * Validate plugin file integrity
     *
     * @param array $plugin Plugin configuration
     * @return bool Whether integrity check passes
     */
    private function validatePluginIntegrity($plugin) {
        // Basic integrity check - ensure file is readable and not empty
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin['file'];
        if (!is_readable($plugin_path)) {
            return false;
        }

        // Check file size (not empty)
        if (filesize($plugin_path) < 1000) { // At least 1KB
            return false;
        }

        // Could add more sophisticated integrity checks here
        // Like checksum validation against known good versions

        return true;
    }

    /**
     * Add a new safe plugin to the registry
     *
     * @param string $key Unique key for the plugin
     * @param array $config Plugin configuration
     * @return bool Success
     */
    public function addSafePlugin($key, $config) {
        // Validate configuration
        $required_fields = ['file', 'name', 'category', 'description'];
        foreach ($required_fields as $field) {
            if (!isset($config[$field])) {
                clean_sweep_log_message("Failed to add safe plugin - missing required field: {$field}", 'error');
                return false;
            }
        }

        $this->safe_plugins[$key] = $config;
        clean_sweep_log_message("Added safe plugin to registry: {$config['name']}", 'info');
        return true;
    }

    /**
     * Remove a safe plugin from the registry
     *
     * @param string $key Plugin key to remove
     * @return bool Success
     */
    public function removeSafePlugin($key) {
        if (isset($this->safe_plugins[$key])) {
            unset($this->safe_plugins[$key]);
            clean_sweep_log_message("Removed safe plugin from registry: {$key}", 'info');
            return true;
        }
        return false;
    }

    /**
     * Get plugin information by key
     *
     * @param string $key Plugin key
     * @return array|null Plugin configuration or null if not found
     */
    public function getPluginInfo($key) {
        return $this->safe_plugins[$key] ?? null;
    }
}
