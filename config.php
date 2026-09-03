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
define('CLEAN_SWEEP_ROOT', __DIR__ . '/'); // Absolute path to clean-sweep root
define('CLEAN_SWEEP_BACKUP_DIR', 'backups');
define('CLEAN_SWEEP_LOG_FILE', 'clean-sweep-log-' . date('Y-m-d-H-i-s') . '.txt');
define('CLEAN_SWEEP_LOGS_DIR', __DIR__ . '/logs/');
define('CLEAN_SWEEP_TEMP_DIR', __DIR__ . '/backups/temp/'); // Internal temporary files
define('CLEAN_SWEEP_PROGRESS_DIR', __DIR__ . '/logs/'); // Progress files (web-accessible in logs directory)

/**
 * Detect shared hosting environments (3rd party hosting providers)
 * These typically have strict execution time and memory limits
 *
 * Detection criteria (in order of weight):
 *   - PHP max_execution_time <= 30s (very strong signal)
 *   - Memory limit <= 256M
 *   - Disabled exec/shell_exec/system (cage hosting)
 *   - Well-known shared hosting provider hostname
 *   - Server software mentions "shared"/"cpanel"/"plesk"
 *
 * @return bool True if detected shared hosting
 */
function clean_sweep_is_shared_hosting() {
    // 1. PHP execution time limit <= 30 seconds (typical shared hosting)
    $current_time_limit = ini_get('max_execution_time');
    if ($current_time_limit && $current_time_limit <= 30) {
        return true;
    }

    // 2. Memory limit <= 256M (raised from 128M to reduce false positives
    //    on small VPS plans that have 256M but are otherwise fine).
    $memory_limit = ini_get('memory_limit');
    $memory_bytes = 0;
    if ($memory_limit) {
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            if ($matches[2] == 'M') {
                $memory_bytes = $matches[1] * 1048576; // nnnM -> nnn MB
            } else if ($matches[2] == 'K') {
                $memory_bytes = $matches[1] * 1024; // nnnK -> nnn KB
            } else if ($matches[2] == 'G') {
                $memory_bytes = $matches[1] * 1073741824; // nnnG -> nnn GB
            }
        }
        if ($memory_bytes > 0 && $memory_bytes <= 268435456) { // <= 256M
            return true;
        }
    }

    // 3. Disabled privileged functions (cage hosting) — require at least
    //    ONE of exec/shell_exec to be missing AND the others, to avoid
    //    false positives from local dev environments.
    $exec_count = 0;
    foreach (['exec', 'shell_exec', 'system', 'passthru', 'proc_open'] as $fn) {
        if (function_exists($fn)) {
            $exec_count++;
        }
    }
    if ($exec_count === 0) {
        return true;
    }

    // 4. Specific hosting provider detection (most reliable when matched)
    if (isset($_SERVER['HTTP_HOST'])) {
        $host = strtolower($_SERVER['HTTP_HOST']);
        $shared_hosting_domains = [
            '.godaddy.com', 'godaddy', 'hostgator', 'bluehost', 'siteground',
            'hostmonster', 'a2hosting', 'dreamhost', 'inmotionhosting', 'arvixe',
            'ipage', 'fatcow', 'web.com', 'network solutions', 'dreamhost.com',
        ];
        foreach ($shared_hosting_domains as $domain) {
            if (strpos($host, $domain) !== false) {
                return true;
            }
        }
    }

    // 5. Check for environment-specific server software
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
define('CLEAN_SWEEP_HOSTING_SHARED_LIMITS', clean_sweep_is_shared_hosting());
define('CLEAN_SWEEP_MAX_EXECUTION_TIME', CLEAN_SWEEP_HOSTING_SHARED_LIMITS ? 25 : 60); // 25s for shared, 60s for dedicated
define('CLEAN_SWEEP_BATCH_SIZE_SHARED', CLEAN_SWEEP_HOSTING_SHARED_LIMITS ? 3 : 5); // Smaller batches for shared hosting
define('CLEAN_SWEEP_PROGRESS_HEARTBEAT_INTERVAL', 2); // Progress updates every 2 seconds

// backups/temp is created when a download or upload needs it (clean_sweep_ensure_writable_dir).

// Log all errors. Do not print them on HTTP (PHP warning HTML corrupts JSON APIs).
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');

// Security check - only allow access from specific IPs or with authentication
// Uncomment and modify as needed
/*
$allowed_ips = ['127.0.0.1', '::1', 'YOUR_IP_HERE'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
    die('Access denied. This script can only be run from authorized locations.');
}
*/
