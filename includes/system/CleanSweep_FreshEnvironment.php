<?php
/**
 * Clean Sweep - Fresh Environment Manager
 *
 * Manages the isolated clean WordPress environment for secure recovery operations.
 * Never trusts or executes the site's own WordPress core files.
 *
 * @version 2.1
 * @author Nithin K R
 */

class CleanSweep_FreshEnvironment {

    private $fresh_dir;
    private $marker_file;
    private $htaccess_file;
    private $integrity_file;

    public function __construct() {
        $this->fresh_dir = __DIR__ . '/../../core/fresh';
        $this->marker_file = $this->fresh_dir . '/.clean-sweep-setup';
        $this->htaccess_file = $this->fresh_dir . '/.htaccess';
        $this->integrity_file = $this->fresh_dir . '/.integrity-hash';
    }

    /**
     * Check if fresh environment exists and is valid
     *
     * @return bool True if environment is ready
     */
    public function isValid() {
        clean_sweep_log_message("🔍 DEBUG: isValid() checking fresh environment...", 'error');
        clean_sweep_log_message("🔍 DEBUG: fresh_dir = " . $this->fresh_dir, 'error');
        clean_sweep_log_message("🔍 DEBUG: marker_file = " . $this->marker_file, 'error');

        // Check if directory exists
        if (!is_dir($this->fresh_dir)) {
            clean_sweep_log_message("❌ DEBUG: Fresh directory does not exist: " . $this->fresh_dir, 'error');
            return false;
        }
        clean_sweep_log_message("✅ DEBUG: Fresh directory exists", 'error');

        // Check essential WordPress files
        $required_files = [
            'wp-load.php',
            'wp-settings.php',
            'wp-config.php',
            'index.php'
        ];

        foreach ($required_files as $file) {
            $file_path = $this->fresh_dir . '/' . $file;
            if (!file_exists($file_path)) {
                clean_sweep_log_message("❌ DEBUG: Required file missing: " . $file_path, 'error');
                return false;
            }
        }
        clean_sweep_log_message("✅ DEBUG: All required files exist", 'error');

        // Check essential directories
        $required_dirs = [
            'wp-admin',
            'wp-includes'
        ];

        foreach ($required_dirs as $dir) {
            $dir_path = $this->fresh_dir . '/' . $dir;
            if (!is_dir($dir_path)) {
                clean_sweep_log_message("❌ DEBUG: Required directory missing: " . $dir_path, 'error');
                return false;
            }
        }
        clean_sweep_log_message("✅ DEBUG: All required directories exist", 'error');

        // Check marker file
        if (!file_exists($this->marker_file)) {
            clean_sweep_log_message("⚠️ DEBUG: Marker file missing: " . $this->marker_file, 'error');
            clean_sweep_log_message("🔄 DEBUG: Creating marker file for existing fresh environment", 'error');

            $marker_data = [
                'created' => time(),
                'method' => 'existing',
                'wordpress_version' => 'latest'
            ];

            $marker_result = file_put_contents($this->marker_file, json_encode($marker_data, JSON_PRETTY_PRINT));
            if ($marker_result === false) {
                clean_sweep_log_message("❌ DEBUG: Failed to create marker file!", 'error');
                return false;
            }

            clean_sweep_log_message("✅ DEBUG: Created marker file successfully", 'error');
        } else {
            clean_sweep_log_message("✅ DEBUG: Marker file exists", 'error');
        }

        // Verify integrity if hash file exists
        if (file_exists($this->integrity_file)) {
            clean_sweep_log_message("🔍 DEBUG: Integrity file exists, verifying...", 'error');
            $integrity_valid = $this->verifyIntegrity();
            clean_sweep_log_message("✅ DEBUG: Integrity check: " . ($integrity_valid ? 'PASSED' : 'FAILED'), 'error');
            return $integrity_valid;
        }

        clean_sweep_log_message("✅ DEBUG: isValid() returning TRUE - fresh environment is ready", 'error');
        return true;
    }

    /**
     * Download latest WordPress from wordpress.org
     *
     * @return bool True on success
     */
    public function download() {
        clean_sweep_log_message("🔄 Downloading latest WordPress for fresh environment...", 'info');

        // Create fresh directory
        if (!is_dir($this->fresh_dir) && !mkdir($this->fresh_dir, 0755, true)) {
            clean_sweep_log_message("❌ Failed to create fresh directory: " . $this->fresh_dir, 'error');
            return false;
        }

        // Download WordPress
        $download_url = 'https://wordpress.org/latest.zip';
        $temp_file = sys_get_temp_dir() . '/wordpress_fresh_' . time() . '.zip';

        $ch = curl_init($download_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $data = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($data === false) {
            clean_sweep_log_message("❌ Failed to download WordPress: " . $error, 'error');
            return false;
        }

        if (file_put_contents($temp_file, $data) === false) {
            clean_sweep_log_message("❌ Failed to save WordPress download", 'error');
            return false;
        }

        clean_sweep_log_message("📦 Downloaded WordPress successfully", 'info');

        // Extract WordPress
        $zip = new ZipArchive();
        $result = $zip->open($temp_file);
        if ($result !== true) {
            clean_sweep_log_message("❌ Failed to extract WordPress: " . $result, 'error');
            unlink($temp_file);
            return false;
        }

        $zip->extractTo($this->fresh_dir);
        $zip->close();

        // Move contents from wordpress/ subdirectory to fresh/
        $wordpress_dir = $this->fresh_dir . '/wordpress';
        if (is_dir($wordpress_dir)) {
            $this->moveDirectoryContents($wordpress_dir, $this->fresh_dir);
            rmdir($wordpress_dir);
        }

        // Cleanup
        unlink($temp_file);

        clean_sweep_log_message("📂 Extracted WordPress to fresh environment", 'info');
        return true;
    }

    /**
     * Handle manual ZIP upload
     *
     * @param string $uploaded_file Path to uploaded ZIP file
     * @return bool True on success
     */
    public function manualUpload($uploaded_file) {
        clean_sweep_log_message("📦 Processing manual WordPress upload...", 'info');

        if (!file_exists($uploaded_file)) {
            clean_sweep_log_message("❌ Uploaded file not found", 'error');
            return false;
        }

        // Create fresh directory
        if (!is_dir($this->fresh_dir) && !mkdir($this->fresh_dir, 0755, true)) {
            clean_sweep_log_message("❌ Failed to create fresh directory", 'error');
            return false;
        }

        // Extract uploaded ZIP
        $zip = new ZipArchive();
        $result = $zip->open($uploaded_file);
        if ($result !== true) {
            clean_sweep_log_message("❌ Failed to open uploaded ZIP: " . $result, 'error');
            return false;
        }

        $zip->extractTo($this->fresh_dir);
        $zip->close();

        // Move contents from wordpress/ subdirectory
        $wordpress_dir = $this->fresh_dir . '/wordpress';
        if (is_dir($wordpress_dir)) {
            $this->moveDirectoryContents($wordpress_dir, $this->fresh_dir);
            rmdir($wordpress_dir);
        }

        clean_sweep_log_message("📂 Extracted uploaded WordPress successfully", 'info');
        return true;
    }

    /**
     * Setup fresh environment (download/manual + configuration)
     *
     * @param string $upload_file Optional uploaded file path
     * @return bool True on success
     */
    public function setup($upload_file = null) {
        // Get WordPress files
        if ($upload_file) {
            $success = $this->manualUpload($upload_file);
        } else {
            $success = $this->download();
        }

        if (!$success) {
            return false;
        }

        // Generate wp-config.php
        if (!$this->generateConfig()) {
            return false;
        }

        // Replace wp-settings.php with recovery version
        if (!$this->installRecoverySettings()) {
            return false;
        }

        // Protect environment
        $this->protect();

        // Create marker file
        $marker_data = [
            'created' => time(),
            'method' => $upload_file ? 'manual' : 'auto',
            'wordpress_version' => 'latest'
        ];

        file_put_contents($this->marker_file, json_encode($marker_data, JSON_PRETTY_PRINT));

        // Generate integrity hash
        $this->generateIntegrityHash();

        clean_sweep_log_message("✅ Fresh environment setup complete", 'info');
        return true;
    }

    /**
     * Generate wp-config.php with site's database credentials
     *
     * @return bool True on success
     */
    private function generateConfig() {
        // Find site's wp-config.php
        $site_config_paths = [
            dirname(dirname(dirname(__DIR__))) . '/wp-config.php',
            dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-config.php',
            dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/wp-config.php'
        ];

        $site_config = null;
        foreach ($site_config_paths as $path) {
            if (file_exists($path)) {
                $site_config = $path;
                break;
            }
        }

        if (!$site_config) {
            clean_sweep_log_message("❌ Could not find site's wp-config.php", 'error');
            return false;
        }

        // Read and parse site config
        $config_content = file_get_contents($site_config);
        if ($config_content === false) {
            clean_sweep_log_message("❌ Could not read site's wp-config.php", 'error');
            return false;
        }

        // Extract database constants
        $db_constants = [];
        $patterns = [
            'DB_NAME' => "/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'DB_USER' => "/define\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'DB_PASSWORD' => "/define\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'DB_HOST' => "/define\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'DB_CHARSET' => "/define\(\s*['\"]DB_CHARSET['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'DB_COLLATE' => "/define\(\s*['\"]DB_COLLATE['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'table_prefix' => "/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]\s*;/"
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $config_content, $matches)) {
                $db_constants[$key] = $matches[1];
            }
        }

        // Generate fresh wp-config.php
        $config_template = <<<EOT
<?php
/**
 * Clean Sweep - Fresh Environment Configuration
 *
 * Auto-generated wp-config.php for isolated recovery environment.
 * Uses site's database credentials with clean WordPress core.
 */

// Database configuration from site
define( 'DB_NAME', '%s' );
define( 'DB_USER', '%s' );
define( 'DB_PASSWORD', '%s' );
define( 'DB_HOST', '%s' );
define( 'DB_CHARSET', '%s' );
define( 'DB_COLLATE', '%s' );

// Security keys - generate unique for this environment
define( 'AUTH_KEY',         '%s' );
define( 'SECURE_AUTH_KEY',  '%s' );
define( 'LOGGED_IN_KEY',    '%s' );
define( 'NONCE_KEY',        '%s' );
define( 'AUTH_SALT',        '%s' );
define( 'SECURE_AUTH_SALT', '%s' );
define( 'LOGGED_IN_SALT',   '%s' );
define( 'NONCE_SALT',       '%s' );

// Table prefix
\$table_prefix = '%s';

// Recovery mode constants
define( 'CLEAN_SWEEP_RECOVERY_MODE', true );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );
EOT;

        // Generate unique salts using PHP native functions
        $salts = [];
        for ($i = 0; $i < 8; $i++) {
            $salts[] = $this->generateSecurePassword(64);
        }

        $config_content = sprintf(
            $config_template,
            $db_constants['DB_NAME'] ?? '',
            $db_constants['DB_USER'] ?? '',
            $db_constants['DB_PASSWORD'] ?? '',
            $db_constants['DB_HOST'] ?? 'localhost',
            $db_constants['DB_CHARSET'] ?? 'utf8',
            $db_constants['DB_COLLATE'] ?? '',
            $salts[0], $salts[1], $salts[2], $salts[3],
            $salts[4], $salts[5], $salts[6], $salts[7],
            $db_constants['table_prefix'] ?? 'wp_'
        );

        $config_path = $this->fresh_dir . '/wp-config.php';
        if (file_put_contents($config_path, $config_content) === false) {
            clean_sweep_log_message("❌ Failed to create wp-config.php", 'error');
            return false;
        }

        clean_sweep_log_message("✅ Generated wp-config.php for fresh environment", 'info');
        return true;
    }

    /**
     * Install recovery wp-settings.php with class guards
     *
     * @return bool True on success
     */
    private function installRecoverySettings() {
        $recovery_settings = __DIR__ . '/../../core/recovery/recovery-wp-settings.php';
        $target_settings = $this->fresh_dir . '/wp-settings.php';

        if (!file_exists($recovery_settings)) {
            clean_sweep_log_message("❌ Recovery wp-settings.php not found", 'error');
            return false;
        }

        if (!copy($recovery_settings, $target_settings)) {
            clean_sweep_log_message("❌ Failed to install recovery wp-settings.php", 'error');
            return false;
        }

        clean_sweep_log_message("✅ Installed recovery wp-settings.php", 'info');
        return true;
    }

    /**
     * Protect fresh environment with .htaccess and permissions
     */
    private function protect() {
        // Create .htaccess
        $htaccess_content = <<<EOT
# Clean Sweep - Fresh Environment Protection
# Deny all web access to recovery environment

<Files "*">
    <RequireAll>
        Require all denied
    </RequireAll>
</Files>

# Allow access only from Clean Sweep
<Files "wp-load.php">
    <RequireAll>
        Require local
        Require ip 127.0.0.1
        Require ip ::1
    </RequireAll>
</Files>
EOT;

        file_put_contents($this->htaccess_file, $htaccess_content);

        // Set permissions (750 for directories, 640 for files)
        $this->setSecurePermissions($this->fresh_dir);

        clean_sweep_log_message("🔒 Protected fresh environment", 'info');
    }

    /**
     * Load the fresh WordPress environment safely using dynamic wp-settings.php generation
     * This generates a safe wp-settings.php that uses clean files but points content to real site
     *
     * @return bool True on success
     */
    public function load() {
        clean_sweep_log_message("Starting safe recovery environment load...", 'info');

        // 1. Detect the real infected site's root
        $site_root = $this->detectSiteRoot();
        if (!$site_root || !is_dir($site_root)) {
            clean_sweep_log_message("Could not detect real site root", 'error');
            return false;
        }

        // 2. Generate a safe wp-settings.php that uses clean files from fresh but content from real site
        $safe_settings = $this->generateSafeWpSettings($site_root);
        if (!$safe_settings) {
            clean_sweep_log_message("Failed to generate safe wp-settings.php", 'error');
            return false;
        }

        file_put_contents($this->fresh_dir . '/wp-settings.php', $safe_settings);

        // 3. Regenerate integrity hash to include the new wp-settings.php
        $this->generateIntegrityHash();

        // 3. Load fresh wp-config.php first (defines database credentials)
        $config_file = $this->fresh_dir . '/wp-config.php';
        if (!file_exists($config_file)) {
            clean_sweep_log_message("wp-config.php not found in fresh environment", 'error');
            return false;
        }

        clean_sweep_log_message("🔄 Loading fresh wp-config.php...", 'info');
        require_once $config_file;

        // 4. Load our generated safe wp-settings.php (WordPress initializes with clean files + real site paths)
        $settings_file = $this->fresh_dir . '/wp-settings.php';
        clean_sweep_log_message("🔄 Loading generated safe wp-settings.php...", 'info');
        require_once $settings_file;

        clean_sweep_log_message("Safe recovery environment loaded successfully!", 'info');
        clean_sweep_log_message("Real site root: " . $site_root, 'info');

        return true;
    }

    /**
     * Detect the real WordPress site root (where wp-config.php lives)
     */
    private function detectSiteRoot() {
        $clean_sweep_root = dirname(dirname(dirname(__DIR__)));

        $paths_to_check = [
            $clean_sweep_root,                    // Clean Sweep in root
            dirname($clean_sweep_root),           // Clean Sweep in subdir
            dirname(dirname($clean_sweep_root)),  // Deeper nesting
        ];

        foreach ($paths_to_check as $path) {
            $path = rtrim($path, '/') . '/';
            if (file_exists($path . 'wp-config.php') || file_exists($path . '../wp-config.php')) {
                return $path;
            }
        }

        // Fallback: assume Clean Sweep is in WordPress root
        return $clean_sweep_root;
    }

    /**
     * Generate a safe wp-settings.php that uses clean files from fresh but points content to real site
     *
     * @param string $site_root The real WordPress site root
     * @return string The generated wp-settings.php content
     */
    private function generateSafeWpSettings($site_root) {
        $fresh_dir = rtrim($this->fresh_dir, '/');

        // Build the safe wp-settings.php content
        $content = "<?php\n";
        $content .= "/**\n";
        $content .= " * Clean Sweep - Safe WordPress Settings\n";
        $content .= " * Generated dynamically for secure recovery mode\n";
        $content .= " * Uses clean core files but points content to real site\n";
        $content .= " */\n\n";

        // 1. Define ABSPATH to real site root (database compatibility)
        $content .= "// Define ABSPATH to real site root for database compatibility\n";
        $content .= "if (!defined('ABSPATH')) {\n";
        $content .= "    define('ABSPATH', '{$site_root}');\n";
        $content .= "}\n\n";

        // 2. Define content paths to real site
        $content .= "// Define content paths to real site\n";
        $content .= "define('WP_CONTENT_DIR', ABSPATH . 'wp-content');\n";
        $content .= "define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');\n";
        $content .= "define('WP_LANG_DIR', WP_CONTENT_DIR . '/languages');\n";
        $content .= "define('WP_CONTENT_URL', '');\n\n";

        // 3. Define constants needed by plugin analysis code
        $content .= "// Define constants needed by plugin analysis code\n";
        $content .= "define('ORIGINAL_WP_PLUGIN_DIR', '{$site_root}wp-content/plugins/');\n";
        $content .= "define('ORIGINAL_WP_CONTENT_DIR', '{$site_root}wp-content/');\n\n";

        // 4. Initialize WordPress globals that may not be set in recovery mode
        $content .= "// Initialize WordPress globals for plugin functions\n";
        $content .= "global \$wp_plugin_paths;\n";
        $content .= "\$wp_plugin_paths = [];\n\n";

        // 5. Define WPMU_PLUGIN_DIR for plugin_basename() function
        $content .= "// Define WPMU_PLUGIN_DIR for plugin_basename() function\n";
        $content .= "if (!defined('WPMU_PLUGIN_DIR')) {\n";
        $content .= "    define('WPMU_PLUGIN_DIR', '');\n";
        $content .= "}\n\n";

        // 3. Define essential constants
        $content .= "// Define essential constants\n";
        $relative_wpinc = str_replace(rtrim($site_root, '/'), '', rtrim($fresh_dir, '/')) . '/wp-includes';
        $content .= "if (!defined('WPINC')) define('WPINC', '" . ltrim($relative_wpinc, '/') . "');\n";
        $content .= "if (!defined('WP_DEBUG')) define('WP_DEBUG', false);\n\n";

        // 4. Include clean WordPress core files from fresh directory
        // Load core files in dependency order (base classes before subclasses)
        $core_files = [
            // Base infrastructure
            'version.php',
            'compat.php',
            'load.php',

            // Base classes that others extend
            'class-wp-walker.php',  // ← Must load BEFORE walker subclasses

            // Exception handling
            'class-wp-paused-extensions-storage.php',
            'class-wp-exception.php',
            'class-wp-fatal-error-handler.php',

            // Recovery mode
            'class-wp-recovery-mode-cookie-service.php',
            'class-wp-recovery-mode-key-service.php',
            'class-wp-recovery-mode-link-service.php',
            'class-wp-recovery-mode-email-service.php',
            'class-wp-recovery-mode.php',
            'error-protection.php',

            // Core constants and utilities
            'default-constants.php',
            'plugin.php',
            'class-wp-list-util.php',
            'class-wp-token-map.php',
            'formatting.php',
            'meta.php',
            'functions.php',

            // Database and query classes
            'class-wp-meta-query.php',
            'class-wp-matchesmapregex.php',
            'class-wp.php',
            'class-wp-error.php',

            // Translation system
            'pomo/mo.php',
            'l10n/class-wp-translation-controller.php',
            'l10n/class-wp-translations.php',
            'l10n/class-wp-translation-file.php',
            'l10n/class-wp-translation-file-mo.php',
            'l10n/class-wp-translation-file-php.php',

            // Database
            'wp-db.php',
            'default-filters.php',
            'class-wp-hook.php',
            'class-wp-object-cache.php',
            'kses.php',
            'user.php',
            'pluggable.php',
            'capabilities.php',

            // User and role system
            'class-wp-roles.php',
            'class-wp-role.php',
            'class-wp-user.php',

            // Query system
            'class-wp-query.php',
            'query.php',
            'class-wp-date-query.php',

            // Theme system
            'theme.php',
            'class-wp-theme.php',
            'class-wp-theme-json-schema.php',
            'class-wp-theme-json-data.php',
            'class-wp-theme-json.php',
            'class-wp-theme-json-resolver.php',
            'class-wp-duotone.php',
            'global-styles-and-settings.php',

            // Block system
            'class-wp-block-template.php',
            'class-wp-block-templates-registry.php',
            'block-template-utils.php',
            'block-template.php',
            'theme-templates.php',
            'theme-previews.php',
            'template.php',

            // Network utilities
            'https-detection.php',
            'https-migration.php',
            'class-wp-user-request.php',
            'user.php',
            'class-wp-user-query.php',
            'class-wp-session-tokens.php',
            'class-wp-user-meta-session-tokens.php',

            // Template functions
            'general-template.php',
            'link-template.php',
            'author-template.php',
            'robots-template.php',

            // Post system
            'post.php',

            // Walker subclasses (AFTER base Walker class)
            'class-walker-page.php',
            'class-walker-page-dropdown.php',

            'class-wp-post-type.php',
            'class-wp-post.php',
            'post-template.php',
            'revision.php',
            'post-formats.php',
            'post-thumbnail-template.php',

            // Taxonomy system
            'category.php',
            'class-walker-category.php',
            'class-walker-category-dropdown.php',
            'category-template.php',

            // Comment system
            'comment.php',
            'class-wp-comment.php',
            'class-wp-comment-query.php',
            'class-walker-comment.php',
            'comment-template.php',

            // Rewrite system
            'rewrite.php',
            'class-wp-rewrite.php',

            // Feed system
            'feed.php',

            // Bookmark system
            'bookmark.php',
            'bookmark-template.php',

            // Additional utilities
            'cron.php',
            'deprecated.php',
            'script-loader.php',
            'taxonomy.php',
            'class-wp-taxonomy.php',
            'class-wp-term.php',
            'class-wp-term-query.php',
            'class-wp-tax-query.php',
            'update.php',
            'canonical.php',
            'shortcodes.php',

            // Embed system
            'embed.php',
            'class-wp-embed.php',
            'class-wp-oembed.php',
            'class-wp-oembed-controller.php',

            // Media system
            'media.php',

            // HTTP system
            'http.php',
            'class-wp-http.php',
            'class-wp-http-streams.php',
            'class-wp-http-curl.php',
            'class-wp-http-proxy.php',
            'class-wp-http-cookie.php',
            'class-wp-http-encoding.php',
            'class-wp-http-response.php',
            'class-wp-http-requests-response.php',
            'class-wp-http-requests-hooks.php',

            // Widget system
            'widgets.php',
            'class-wp-widget.php',
            'class-wp-widget-factory.php',

            // Navigation
            'nav-menu-template.php',
            'nav-menu.php',

            // Admin
            'admin-bar.php',
            'class-wp-application-passwords.php',

            // REST API
            'rest-api.php',
            'rest-api/class-wp-rest-server.php',
            'rest-api/class-wp-rest-response.php',
            'rest-api/class-wp-rest-request.php',
            'rest-api/endpoints/class-wp-rest-controller.php',
            'rest-api/endpoints/class-wp-rest-posts-controller.php',
            'rest-api/endpoints/class-wp-rest-attachments-controller.php',
            'rest-api/endpoints/class-wp-rest-global-styles-controller.php',
            'rest-api/endpoints/class-wp-rest-post-types-controller.php',
            'rest-api/endpoints/class-wp-rest-post-statuses-controller.php',
            'rest-api/endpoints/class-wp-rest-revisions-controller.php',
            'rest-api/endpoints/class-wp-rest-global-styles-revisions-controller.php',
            'rest-api/endpoints/class-wp-rest-template-revisions-controller.php',
            'rest-api/endpoints/class-wp-rest-autosaves-controller.php',
            'rest-api/endpoints/class-wp-rest-template-autosaves-controller.php',
            'rest-api/endpoints/class-wp-rest-taxonomies-controller.php',
            'rest-api/endpoints/class-wp-rest-terms-controller.php',
            'rest-api/endpoints/class-wp-rest-menu-items-controller.php',
            'rest-api/endpoints/class-wp-rest-menus-controller.php',
            'rest-api/endpoints/class-wp-rest-menu-locations-controller.php',
            'rest-api/endpoints/class-wp-rest-users-controller.php',
            'rest-api/endpoints/class-wp-rest-comments-controller.php',
            'rest-api/search/class-wp-rest-search-handler.php',
            'rest-api/search/class-wp-rest-post-search-handler.php',
            'rest-api/search/class-wp-rest-term-search-handler.php',
            'rest-api/search/class-wp-rest-post-format-search-handler.php',

            // Sitemaps
            'sitemaps.php',
            'sitemaps/class-wp-sitemaps.php',
            'sitemaps/class-wp-sitemaps-index.php',
            'sitemaps/class-wp-sitemaps-provider.php',
            'sitemaps/class-wp-sitemaps-registry.php',
            'sitemaps/class-wp-sitemaps-renderer.php',
            'sitemaps/class-wp-sitemaps-stylesheet.php',
            'sitemaps/providers/class-wp-sitemaps-posts.php',
            'sitemaps/providers/class-wp-sitemaps-taxonomies.php',
            'sitemaps/providers/class-wp-sitemaps-users.php',

            // Block bindings
            'class-wp-block-bindings-source.php',
            'class-wp-block-bindings-registry.php',
            'class-wp-block-editor-context.php',
            'class-wp-block-type.php',
            'class-wp-block-pattern-categories-registry.php',
            'class-wp-block-patterns-registry.php',
            'class-wp-block-styles-registry.php',
            'class-wp-block-type-registry.php',
            'class-wp-block.php',
            'class-wp-block-list.php',
            'class-wp-block-metadata-registry.php',
            'class-wp-block-parser-block.php',
            'class-wp-block-parser-frame.php',
            'class-wp-block-parser.php',
            'class-wp-classic-to-block-menu-converter.php',
            'class-wp-navigation-fallback.php',
            'block-bindings.php',
            'block-bindings/pattern-overrides.php',
            'block-bindings/post-meta.php',

            // Blocks
            'blocks.php',
            'blocks/index.php',
            'block-editor.php',
            'block-patterns.php',
            'class-wp-block-supports.php',
            'block-supports/utils.php',
            'block-supports/align.php',
            'block-supports/custom-classname.php',
            'block-supports/custom-classname.php',
            'block-supports/generated-classname.php',
            'block-supports/settings.php',
            'block-supports/elements.php',
            'block-supports/colors.php',
            'block-supports/typography.php',
            'block-supports/border.php',
            'block-supports/layout.php',
            'block-supports/position.php',
            'block-supports/spacing.php',
            'block-supports/dimensions.php',
            'block-supports/duotone.php',
            'block-supports/shadow.php',
            'block-supports/background.php',
            'block-supports/block-style-variations.php',
            'block-supports/aria-label.php',

            // Style engine
            'style-engine.php',
            'style-engine/class-wp-style-engine.php',
            'style-engine/class-wp-style-engine-css-declarations.php',
            'style-engine/class-wp-style-engine-css-rule.php',
            'style-engine/class-wp-style-engine-css-rules-store.php',
            'style-engine/class-wp-style-engine-processor.php',

            // Fonts
            'fonts/class-wp-font-face-resolver.php',
            'fonts/class-wp-font-collection.php',
            'fonts/class-wp-font-face.php',
            'fonts/class-wp-font-library.php',
            'fonts/class-wp-font-utils.php',
            'fonts.php',

            // HTML API (BEFORE interactivity API - contains WP_HTML_Tag_Processor)
            'html-api/class-wp-html-tag-processor.php',
            'html-api/class-wp-html-active-formatting-elements.php',
            'html-api/class-wp-html-open-elements.php',
            'html-api/class-wp-html-decoder.php',
            'html-api/class-wp-html-token.php',

            // Script modules
            'class-wp-script-modules.php',
            'script-modules.php',

            // Interactivity API (AFTER HTML API - can now find WP_HTML_Tag_Processor)
            'interactivity-api/class-wp-interactivity-api.php',
            'interactivity-api/class-wp-interactivity-api-directives-processor.php',
            'interactivity-api/interactivity-api.php',

            // Plugin dependencies
            'class-wp-plugin-dependencies.php',
            'class-wp-url-pattern-prefixer.php',
            'class-wp-speculation-rules.php',
            'speculative-loading.php',
        ];

        $content .= "// Include clean WordPress core files from fresh directory\n";
        foreach ($core_files as $file) {
            $content .= "require_once '{$fresh_dir}/wp-includes/{$file}';\n";
        }
        $content .= "\n";

        // 5. Environment setup for recovery mode
        $content .= "// Environment setup for recovery mode\n";
        $content .= "putenv('WP_CONTENT_DIR={$site_root}wp-content');\n";
        $content .= "putenv('WP_PLUGIN_DIR={$site_root}wp-content/plugins');\n";
        $content .= "\$_SERVER['DOCUMENT_ROOT'] = '{$site_root}';\n\n";

        // 6. Standard WordPress initialization (from wp-settings.php)
        $content .= "// Standard WordPress initialization\n";

        // wp_initial_constants
        $content .= "wp_initial_constants();\n\n";

        // Register fatal error handler
        $content .= "wp_register_fatal_error_handler();\n\n";

        // Set timezone
        $content .= "date_default_timezone_set('UTC');\n\n";

        // Fix server vars
        $content .= "wp_fix_server_vars();\n\n";

        // Maintenance mode
        $content .= "wp_maintenance();\n\n";

        // Start timer
        $content .= "timer_start();\n\n";

        // Debug mode
        $content .= "wp_debug_mode();\n\n";

        // Advanced cache (skip for recovery)
        $content .= "// Skip advanced cache in recovery mode\n\n";

        // Object cache (MUST be before wp_not_installed and language functions)
        $content .= "wp_start_object_cache();\n\n";

        // Language directory
        $content .= "wp_set_lang_dir();\n\n";

        // Database setup (MUST be before wp_not_installed for database queries)
        $content .= "require_wp_db();\n\n";

        $content .= "\$GLOBALS['table_prefix'] = \$table_prefix;\n";
        $content .= "wp_set_wpdb_vars();\n\n";

        // Include language files (need cache and database functions)
        $content .= "require_once ABSPATH . WPINC . '/l10n.php';\n";
        $content .= "require_once ABSPATH . WPINC . '/class-wp-textdomain-registry.php';\n";
        $content .= "require_once ABSPATH . WPINC . '/class-wp-locale.php';\n";
        $content .= "require_once ABSPATH . WPINC . '/class-wp-locale-switcher.php';\n\n";

        // Installer check (needs database and cache functions)
        $content .= "wp_not_installed();\n\n";

        // Multisite
        $content .= "if (is_multisite()) {\n";
        $content .= "    require ABSPATH . WPINC . '/class-wp-site-query.php';\n";
        $content .= "    require ABSPATH . WPINC . '/class-wp-network-query.php';\n";
        $content .= "    require ABSPATH . WPINC . '/ms-blogs.php';\n";
        $content .= "    require ABSPATH . WPINC . '/ms-settings.php';\n";
        $content .= "} elseif (!defined('MULTISITE')) {\n";
        $content .= "    define('MULTISITE', false);\n";
        $content .= "}\n\n";

        $content .= "register_shutdown_function('shutdown_action_hook');\n\n";

        // Shortinit check
        $content .= "if (SHORTINIT) {\n";
        $content .= "    return false;\n";
        $content .= "}\n\n";

        // Continue with standard initialization
        $content .= "// Load active plugins (but suppress in recovery mode)\n";
        $content .= "add_filter('option_active_plugins', '__return_empty_array');\n";
        $content .= "add_filter('option_active_sitewide_plugins', '__return_empty_array');\n\n";

        $content .= "foreach (wp_get_active_and_valid_plugins() as \$plugin) {\n";
        $content .= "    wp_register_plugin_realpath(\$plugin);\n";
        $content .= "    \$plugin_data = get_plugin_data(\$plugin, false, false);\n";
        $content .= "    \$textdomain = \$plugin_data['TextDomain'];\n";
        $content .= "    if (\$textdomain) {\n";
        $content .= "        if (\$plugin_data['DomainPath']) {\n";
        $content .= "            \$GLOBALS['wp_textdomain_registry']->set_custom_path(\$textdomain, dirname(\$plugin) . \$plugin_data['DomainPath']);\n";
        $content .= "        } else {\n";
        $content .= "            \$GLOBALS['wp_textdomain_registry']->set_custom_path(\$textdomain, dirname(\$plugin));\n";
        $content .= "        }\n";
        $content .= "    }\n";
        $content .= "    include_once \$plugin;\n";
        $content .= "}\n\n";

        // 6. Setup path interception immediately
        $content .= "// Setup path interception for recovery mode\n";
        $content .= "add_filter('pre_option_upload_path', function(\$path) {\n";
        $content .= "    return str_replace(ABSPATH . 'wp-content/uploads', '{$site_root}wp-content/uploads', \$path);\n";
        $content .= "});\n";
        $content .= "add_filter('upload_dir', function(\$upload_dir) {\n";
        $content .= "    if (isset(\$upload_dir['basedir'])) {\n";
        $content .= "        \$upload_dir['basedir'] = str_replace(ABSPATH . 'wp-content/uploads', '{$site_root}wp-content/uploads', \$upload_dir['basedir']);\n";
        $content .= "    }\n";
        $content .= "    return \$upload_dir;\n";
        $content .= "});\n";
        $content .= "add_filter('content_url', function(\$url) {\n";
        $content .= "    return str_replace(ABSPATH, '{$site_root}', \$url);\n";
        $content .= "});\n";
        $content .= "add_filter('plugins_url', function(\$url) {\n";
        $content .= "    return str_replace(ABSPATH, '{$site_root}', \$url);\n";
        $content .= "});\n\n";

        // 7. Safety measures
        $content .= "// Safety measures for recovery mode\n";
        $content .= "if (!defined('DISALLOW_FILE_MODS')) {\n";
        $content .= "    define('DISALLOW_FILE_MODS', true);\n";
        $content .= "}\n";
        $content .= "if (!defined('DISALLOW_FILE_EDIT')) {\n";
        $content .= "    define('DISALLOW_FILE_EDIT', true);\n";
        $content .= "}\n\n";

        // Final success message
        $content .= "// Recovery environment initialized successfully\n";

        return $content;
    }

    /**
     * Define constants for site paths (after WordPress loads)
     * This allows scanning operations to target the original infected site
     */
    private function defineSitePaths() {
        // Calculate site root directory (where Clean Sweep is installed)
        $site_dir = dirname(dirname(dirname(__DIR__)));

        // DEBUG: Log path resolution details
        clean_sweep_log_message("🔍 DEBUG: defineSitePaths() called", 'error');
        clean_sweep_log_message("🔍 DEBUG: __DIR__ = " . __DIR__, 'error');
        clean_sweep_log_message("🔍 DEBUG: Calculated site_dir = " . $site_dir, 'error');
        clean_sweep_log_message("🔍 DEBUG: Looking for wp-config.php at: " . $site_dir . '/wp-config.php', 'error');

        // Check if wp-config.php exists at the calculated location
        $wp_config_path = $site_dir . '/wp-config.php';
        if (file_exists($wp_config_path)) {
            clean_sweep_log_message("✅ DEBUG: wp-config.php FOUND at: " . $wp_config_path, 'error');

            // Define site path constants
            if (!defined('SITE_ABSPATH')) {
                define('SITE_ABSPATH', $site_dir . '/');
                clean_sweep_log_message("📍 DEBUG: Defined SITE_ABSPATH = " . SITE_ABSPATH, 'error');
            }

            if (!defined('SITE_WP_CONTENT_DIR')) {
                define('SITE_WP_CONTENT_DIR', $site_dir . '/wp-content/');
                clean_sweep_log_message("📍 DEBUG: Defined SITE_WP_CONTENT_DIR = " . SITE_WP_CONTENT_DIR, 'error');
            }

            if (!defined('SITE_WP_PLUGIN_DIR')) {
                define('SITE_WP_PLUGIN_DIR', $site_dir . '/wp-content/plugins/');
                clean_sweep_log_message("📍 DEBUG: Defined SITE_WP_PLUGIN_DIR = " . SITE_WP_PLUGIN_DIR, 'error');
            }

            if (!defined('SITE_WP_UPLOAD_DIR')) {
                define('SITE_WP_UPLOAD_DIR', $site_dir . '/wp-content/uploads/');
                clean_sweep_log_message("📍 DEBUG: Defined SITE_WP_UPLOAD_DIR = " . SITE_WP_UPLOAD_DIR, 'error');
            }

            clean_sweep_log_message("✅ SUCCESS: All SITE_ constants defined successfully", 'error');
        } else {
            clean_sweep_log_message("❌ FAILURE: wp-config.php NOT found at: " . $wp_config_path, 'error');
            clean_sweep_log_message("❌ FAILURE: Directory exists: " . (is_dir($site_dir) ? 'YES' : 'NO'), 'error');
            clean_sweep_log_message("❌ FAILURE: Directory readable: " . (is_readable($site_dir) ? 'YES' : 'NO'), 'error');

            // Try alternative locations
            $alternatives = [
                dirname(dirname(__DIR__)) . '/wp-config.php', // Go up 2 levels
                dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-config.php', // Go up 4 levels
                dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/wp-config.php', // Go up 5 levels
            ];

            foreach ($alternatives as $alt_path) {
                if (file_exists($alt_path)) {
                    clean_sweep_log_message("🔍 DEBUG: wp-config.php found at alternative location: " . $alt_path, 'error');
                    break;
                }
            }

            clean_sweep_log_message("🚨 CRITICAL: SITE_ constants NOT defined - path translation will FAIL", 'error');
        }
    }

    /**
     * Override WordPress constants to point to original site for scanning operations
     * This ensures that WP_CONTENT_DIR and other constants point to the infected site
     */
    private function overrideWordPressConstants() {
        clean_sweep_log_message("🔄 DEBUG: overrideWordPressConstants() called", 'error');

        if (defined('ORIGINAL_WP_CONTENT_DIR')) {
            // Override WordPress constants to point to original site BEFORE WordPress defines them
            if (!defined('WP_CONTENT_DIR')) {
                define('WP_CONTENT_DIR', ORIGINAL_WP_CONTENT_DIR);
                clean_sweep_log_message("✅ DEBUG: Successfully overrode WP_CONTENT_DIR = " . WP_CONTENT_DIR, 'error');
            } else {
                clean_sweep_log_message("❌ DEBUG: WP_CONTENT_DIR already defined: " . WP_CONTENT_DIR, 'error');
            }

            if (!defined('WP_PLUGIN_DIR')) {
                define('WP_PLUGIN_DIR', ORIGINAL_WP_PLUGIN_DIR);
                clean_sweep_log_message("✅ DEBUG: Successfully overrode WP_PLUGIN_DIR = " . WP_PLUGIN_DIR, 'error');
            } else {
                clean_sweep_log_message("❌ DEBUG: WP_PLUGIN_DIR already defined: " . WP_PLUGIN_DIR, 'error');
            }

            clean_sweep_log_message("🔄 Successfully overrode WordPress constants for recovery mode BEFORE WordPress loads", 'info');
        } else {
            clean_sweep_log_message("❌ Cannot override WordPress constants - ORIGINAL_WP_CONTENT_DIR not defined", 'error');
        }
    }

    /**
     * Setup comprehensive path interception for recovery mode
     * Hooks into WordPress functions to automatically translate paths
     */
    private function setupPathInterception() {
        // Hook into WordPress path functions to intercept and translate paths
        add_filter('pre_option_upload_path', [$this, 'interceptUploadPath']);
        add_filter('pre_option_upload_url_path', [$this, 'interceptUploadUrlPath']);
        add_filter('upload_dir', [$this, 'interceptUploadDir']);
        add_filter('theme_root', [$this, 'interceptThemeRoot']);
        add_filter('stylesheet_directory', [$this, 'interceptStylesheetDirectory']);
        add_filter('template_directory', [$this, 'interceptTemplateDirectory']);
        add_filter('plugins_url', [$this, 'interceptPluginsUrl'], 10, 3);
        add_filter('theme_root_uri', [$this, 'interceptThemeRootUri']);

        // Hook into filesystem operations
        add_filter('filesystem_method_file', [$this, 'interceptFilesystemPath']);
        add_filter('filesystem_method_dir', [$this, 'interceptFilesystemPath']);

        clean_sweep_log_message("🛡️ Activated comprehensive path interception for recovery mode", 'info');
    }

    /**
     * Intercept upload path option
     */
    public function interceptUploadPath($path) {
        return defined('SITE_WP_UPLOAD_DIR') ?
            str_replace(ABSPATH . 'wp-content/uploads', SITE_WP_UPLOAD_DIR, $path) : $path;
    }

    /**
     * Intercept upload URL path option
     */
    public function interceptUploadUrlPath($path) {
        // For URLs, we can't easily translate, so return as-is
        // The path operations are what matter for scanning
        return $path;
    }

    /**
     * Intercept upload directory array
     */
    public function interceptUploadDir($upload_dir) {
        if (defined('SITE_WP_UPLOAD_DIR') && isset($upload_dir['basedir'])) {
            $upload_dir['basedir'] = clean_sweep_translate_path($upload_dir['basedir']);
        }
        return $upload_dir;
    }

    /**
     * Intercept theme root path
     */
    public function interceptThemeRoot($path) {
        return clean_sweep_translate_path($path);
    }

    /**
     * Intercept stylesheet directory
     */
    public function interceptStylesheetDirectory($path) {
        return clean_sweep_translate_path($path);
    }

    /**
     * Intercept template directory
     */
    public function interceptTemplateDirectory($path) {
        return clean_sweep_translate_path($path);
    }

    /**
     * Intercept plugins URL (though URLs are less critical for file operations)
     */
    public function interceptPluginsUrl($url, $path, $plugin) {
        // For URLs, return as-is since we're primarily concerned with file paths
        return $url;
    }

    /**
     * Intercept theme root URI
     */
    public function interceptThemeRootUri($uri) {
        // For URIs, return as-is
        return $uri;
    }

    /**
     * Intercept filesystem paths
     */
    public function interceptFilesystemPath($path) {
        return clean_sweep_translate_path($path);
    }

    /**
     * Load site's wp-config.php for additional constants
     */
    private function loadSiteConfig() {
        $site_config_paths = [
            dirname(dirname(dirname(__DIR__))) . '/wp-config.php',
            dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-config.php'
        ];

        foreach ($site_config_paths as $path) {
            if (file_exists($path)) {
                // Include but prevent redefinition of DB constants
                include_once $path;
                break;
            }
        }
    }

    /**
     * Generate integrity hash for verification
     */
    private function generateIntegrityHash() {
        $hashes = $this->calculateDirectoryHash($this->fresh_dir);
        file_put_contents($this->integrity_file, json_encode($hashes, JSON_PRETTY_PRINT));
    }

    /**
     * Verify integrity of fresh environment
     *
     * @return bool True if integrity check passes
     */
    private function verifyIntegrity() {
        if (!file_exists($this->integrity_file)) {
            return true; // No hash file means no verification needed
        }

        $stored_hashes = json_decode(file_get_contents($this->integrity_file), true);
        $current_hashes = $this->calculateDirectoryHash($this->fresh_dir);

        return $stored_hashes === $current_hashes;
    }

    /**
     * Calculate SHA256 hashes for all files in directory
     *
     * @param string $dir Directory path
     * @return array File hashes
     */
    private function calculateDirectoryHash($dir) {
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative_path = str_replace($dir . '/', '', $file->getPathname());
                // Skip integrity file itself and marker file
                if ($relative_path !== '.integrity-hash' && $relative_path !== '.clean-sweep-setup') {
                    $hashes[$relative_path] = hash_file('sha256', $file->getPathname());
                }
            }
        }

        return $hashes;
    }

    /**
     * Set secure permissions on fresh environment
     *
     * @param string $dir Directory to protect
     */
    private function setSecurePermissions($dir) {
        // Set directory permissions to 750
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                chmod($item->getPathname(), 0750);
            } else {
                chmod($item->getPathname(), 0640);
            }
        }
    }

    /**
     * Generate a secure random password using PHP native functions
     *
     * @param int $length Password length
     * @return string Secure random password
     */
    private function generateSecurePassword($length = 64) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_[]{}<>~`+=,.;:/?|';
        $password = '';

        // Use openssl_random_pseudo_bytes if available, otherwise random_bytes
        if (function_exists('openssl_random_pseudo_bytes')) {
            $randomBytes = openssl_random_pseudo_bytes($length);
        } elseif (function_exists('random_bytes')) {
            $randomBytes = random_bytes($length);
        } else {
            // Fallback to mt_rand (less secure)
            for ($i = 0; $i < $length; $i++) {
                $password .= $chars[mt_rand(0, strlen($chars) - 1)];
            }
            return $password;
        }

        // Convert random bytes to password characters
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[ord($randomBytes[$i]) % strlen($chars)];
        }

        return $password;
    }

    /**
     * Move contents from one directory to another and clean up source
     *
     * @param string $from Source directory
     * @param string $to Target directory
     */
    private function moveDirectoryContents($from, $to) {
        $from = rtrim($from, '/');
        $to = rtrim($to, '/');

        // Use a more robust approach - copy everything recursively, then remove source
        $this->recursiveCopy($from, $to);

        // Remove the source directory completely
        $this->recursiveDelete($from);
    }

    /**
     * Recursively copy directory contents
     *
     * @param string $src Source directory
     * @param string $dst Destination directory
     */
    private function recursiveCopy($src, $dst) {
        $dir = opendir($src);
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                $srcPath = $src . '/' . $file;
                $dstPath = $dst . '/' . $file;

                if (is_dir($srcPath)) {
                    $this->recursiveCopy($srcPath, $dstPath);
                } else {
                    copy($srcPath, $dstPath);
                }
            }
        }

        closedir($dir);
    }

    /**
     * Recursively delete directory and contents
     *
     * @param string $dir Directory to delete
     */
    private function recursiveDelete($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
