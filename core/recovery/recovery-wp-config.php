<?php
/**
 * Clean Sweep Recovery wp-config.php
 *
 * Auto-generated configuration file for recovery bootstrap.
 * Uses site's database credentials but loads clean wp-settings.php.
 */

// ============================================================================
// AUTO-GENERATED CONFIGURATION - DO NOT EDIT
// ============================================================================

// Parse site's wp-config.php to extract database credentials
// Use same path resolution as local core bootstrap
function clean_sweep_generate_recovery_config() {
    $wp_config_paths = [
        dirname(dirname(__DIR__)) . '/wp-config.php',  // Root wp-config.php
        dirname(dirname(dirname(__DIR__))) . '/wp-config.php'  // Parent directory
    ];

    $site_config_path = null;
    foreach ($wp_config_paths as $config_path) {
        if (file_exists($config_path)) {
            $site_config_path = $config_path;
            break;
        }
    }

    if (!$site_config_path) {
        die('Clean Sweep: Could not find site wp-config.php for recovery configuration.');
    }

    $content = @file_get_contents($site_config_path);
    if ($content === false) {
        die('Clean Sweep: Could not read site wp-config.php.');
    }

    // Extract database configuration
    $config = [];

    // Extract define() statements
    $patterns = [
        'DB_NAME' => '/define\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/i',
        'DB_USER' => '/define\(\s*[\'"]DB_USER[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/i',
        'DB_PASSWORD' => '/define\(\s*[\'"]DB_PASSWORD[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/i',
        'DB_HOST' => '/define\(\s*[\'"]DB_HOST[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/i',
        'DB_CHARSET' => '/define\(\s*[\'"]DB_CHARSET[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/i',
        'DB_COLLATE' => '/define\(\s*[\'"]DB_COLLATE[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/i',
    ];

    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $content, $matches)) {
            $config[$key] = $matches[1];
        }
    }

    // Extract table_prefix
    if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]*)[\'"]\s*;/i', $content, $matches)) {
        $config['table_prefix'] = $matches[1];
    }

    // Set defaults
    $config['DB_NAME'] = $config['DB_NAME'] ?? '';
    $config['DB_USER'] = $config['DB_USER'] ?? '';
    $config['DB_PASSWORD'] = $config['DB_PASSWORD'] ?? '';
    $config['DB_HOST'] = $config['DB_HOST'] ?? 'localhost';
    $config['DB_CHARSET'] = $config['DB_CHARSET'] ?? 'utf8';
    $config['DB_COLLATE'] = $config['DB_COLLATE'] ?? '';
    $config['table_prefix'] = $config['table_prefix'] ?? 'wp_';

    return $config;
}

// Get configuration
$db_config = clean_sweep_generate_recovery_config();

// ============================================================================
// WORDPRESS CONFIGURATION - RECOVERY MODE
// ============================================================================

// ** Database settings - adapted from site config ** //
/** The name of the database for WordPress */
define( 'DB_NAME', $db_config['DB_NAME'] );

/** Database username */
define( 'DB_USER', $db_config['DB_USER'] );

/** Database password */
define( 'DB_PASSWORD', $db_config['DB_PASSWORD'] );

/** Database hostname */
define( 'DB_HOST', $db_config['DB_HOST'] );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', $db_config['DB_CHARSET'] );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', $db_config['DB_COLLATE'] );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'recovery-mode-key-placeholder' );
define( 'SECURE_AUTH_KEY',  'recovery-mode-secure-auth-key-placeholder' );
define( 'LOGGED_IN_KEY',    'recovery-mode-logged-in-key-placeholder' );
define( 'NONCE_KEY',        'recovery-mode-nonce-key-placeholder' );
define( 'AUTH_SALT',        'recovery-mode-auth-salt-placeholder' );
define( 'SECURE_AUTH_SALT', 'recovery-mode-secure-auth-salt-placeholder' );
define( 'LOGGED_IN_SALT',   'recovery-mode-logged-in-salt-placeholder' );
define( 'NONCE_SALT',       'recovery-mode-nonce-salt-placeholder' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = $db_config['table_prefix'];

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );

/* Add any custom values between this line and the "stop editing" line. */

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
    // Use site's WordPress root instead of recovery directory
    // Recovery config provides clean settings but uses site's core files
    define( 'ABSPATH', dirname(dirname(dirname(dirname(__FILE__)))) . '/' );
}

/** Sets up WordPress vars and included files. */
require_once __DIR__ . '/recovery-wp-settings.php';
