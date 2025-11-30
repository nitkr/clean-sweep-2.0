<?php
/**
 * Clean Sweep - Configuration and Security
 *
 * Contains constants, error reporting settings, and security checks
 * for the Clean Sweep WordPress malware cleanup toolkit.
 *
 * @author Nithin K R
 */

// Define constants
define('CLEAN_SWEEP_VERSION', '2.0');
define('BACKUP_DIR', 'backups');
define('LOG_FILE', 'clean-sweep-log-' . date('Y-m-d-H-i-s') . '.txt');
define('LOGS_DIR', __DIR__ . '/logs/');
define('TEMP_DIR', __DIR__ . '/backups/temp/'); // Internal temporary files
define('PROGRESS_DIR', __DIR__ . '/logs/'); // Progress files (web-accessible in logs directory)

/**
 * Detect shared hosting environments (3rd party hosting providers)
 * These typically have strict execution time and memory limits
 *
 * @return bool True if detected shared hosting
 */
function is_shared_hosting() {
    // Check for common shared hosting indicators

    // 1. PHP execution time limit <= 30 seconds (typical shared hosting)
    $current_time_limit = ini_get('max_execution_time');
    if ($current_time_limit && $current_time_limit <= 30) {
        return true;
    }

    // 2. Memory limit <= 128M (typical shared hosting)
    $memory_limit = ini_get('memory_limit');
    if ($memory_limit) {
        $memory_bytes = 0;
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            if ($matches[2] == 'M') {
                $memory_bytes = $matches[1] * 1048576; // nnnM -> nnn MB
            } else if ($matches[2] == 'K') {
                $memory_bytes = $matches[1] * 1024; // nnnK -> nnn KB
            }
        }
        if ($memory_bytes > 0 && $memory_bytes <= 134217728) { // <= 128M
            return true;
        }
    }

    // 3. Disabled privileged functions (typical shared hosting security)
    if (!function_exists('exec') && !function_exists('shell_exec') && !function_exists('system')) {
        return true;
    }

    // 4. Specific hosting provider detection
    if (isset($_SERVER['HTTP_HOST'])) {
        $host = strtolower($_SERVER['HTTP_HOST']);
        $shared_hosting_domains = [
            '.godaddy.com', 'godaddy', 'hostgator', 'bluehost', 'siteground',
            'hostmonster', 'a2hosting', 'dreamhost', 'inmotionhosting', 'arvixe'
        ];
        foreach ($shared_hosting_domains as $domain) {
            if (strpos($host, $domain) !== false) {
                return true;
            }
        }
    }

    // 5. Check for environment-specific settings
    if (isset($_SERVER['SERVER_SOFTWARE'])) {
        $server_software = strtolower($_SERVER['SERVER_SOFTWARE']);
        if (strpos($server_software, 'shared') !== false ||
            strpos($server_software, 'cpanel') !== false ||
            strpos($server_software, 'plesk') !== false) {
            return true;
        }
    }

    return false; // Default to dedicated/VPS
}

// Hosting environment detection for timeout prevention
define('HOSTING_SHARED_LIMITS', is_shared_hosting());
define('MAX_EXECUTION_TIME', HOSTING_SHARED_LIMITS ? 25 : 60); // 25s for shared, 60s for dedicated
define('BATCH_SIZE_SHARED', HOSTING_SHARED_LIMITS ? 3 : 5); // Smaller batches for shared hosting
define('PROGRESS_HEARTBEAT_INTERVAL', 2); // Progress updates every 2 seconds

// Create temp directory if it doesn't exist
if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0755, true);
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security check - only allow access from specific IPs or with authentication
// Uncomment and modify as needed
/*
$allowed_ips = ['127.0.0.1', '::1', 'YOUR_IP_HERE'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    die('Access denied. This script can only be run from authorized locations.');
}
*/
