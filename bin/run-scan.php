#!/usr/bin/env php
<?php
/**
 * Clean Sweep - Standalone Scan Runner (CleanSweep_Scanner v2)
 *
 * Cron-friendly CLI driver. Drains a scan in one long-running
 * invocation, bypassing the loopback mechanism entirely.
 *
 * Usage:
 *   php /path/to/clean-sweep/bin/run-scan.php <scan_id> [profile_id] [options_json]
 *
 * Cron example (run every 5 minutes):
 *   * * * * * cd /path/to/site && php bin/run-scan.php bg_xxxxx >> /var/log/clean-sweep-scan.log 2>&1
 *
 * @since CleanSweep_Scanner v2
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line');
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php run-scan.php <scan_id> [profile_id] [options_json]\n");
    exit(1);
}

$scan_id = $argv[1];
$profile_id = $argv[2] ?? 'standard';
$options_json = $argv[3] ?? '{}';
$options = json_decode($options_json, true) ?: [];

// Find WordPress root
$wp_root = find_wordpress_root(__DIR__);
if (!$wp_root) {
    fwrite(STDERR, "Error: wp-load.php not found\n");
    exit(1);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', $wp_root);
}

require_once $wp_root . 'wp-load.php';

if (!function_exists('get_bloginfo')) {
    fwrite(STDERR, "Error: WordPress failed to load\n");
    exit(1);
}

ini_set('memory_limit', '512M');
ini_set('max_execution_time', 3600);

if (!defined('CLEAN_SWEEP_ROOT')) {
    define('CLEAN_SWEEP_ROOT', dirname(__DIR__) . '/');
}
if (!defined('CLEAN_SWEEP_PROGRESS_DIR')) {
    define('CLEAN_SWEEP_PROGRESS_DIR', $wp_root . 'wp-content/uploads/clean-sweep-progress/');
}
if (!defined('CLEAN_SWEEP_LOGS_DIR')) {
    define('CLEAN_SWEEP_LOGS_DIR', $wp_root . 'wp-content/uploads/clean-sweep-logs/');
}
if (!defined('CLEAN_SWEEP_TEMP_DIR')) {
    define('CLEAN_SWEEP_TEMP_DIR', $wp_root . 'wp-content/uploads/clean-sweep-temp/');
}
foreach ([CLEAN_SWEEP_PROGRESS_DIR, CLEAN_SWEEP_LOGS_DIR, CLEAN_SWEEP_TEMP_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', $wp_root . 'wp-content/');
}

require_once CLEAN_SWEEP_ROOT . 'features/security/scan/Scanner.php';

$log = function($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    echo $line;
    @file_put_contents(CLEAN_SWEEP_PROGRESS_DIR . 'scan_runner.log', $line, FILE_APPEND);
};

$log("CLI runner: scan_id={$scan_id}, profile={$profile_id}");

try {
    $scanner = CleanSweep_Scanner::create($scan_id, $profile_id);
    $status = $scanner->status($scan_id);
    if ($status['status'] === 'not_found') {
        $log("Error: scan {$scan_id} not found");
        exit(1);
    }
    if (in_array($status['status'], ['completed', 'cancelled', 'failed'], true)) {
        $log("Scan {$scan_id} already in terminal state: {$status['status']}");
        exit(0);
    }

    // On CLI, the time budget is effectively unlimited, but the host's
    // recommendedTimeBudget() is conservative. Override to 30 min per pass.
    $result = $scanner->drain($scan_id);
    $log("Drain: " . json_encode($result));
    exit(0);
} catch (Exception $e) {
    $log("Exception: " . $e->getMessage());
    exit(1);
}

function find_wordpress_root($start_dir) {
    $cur = realpath($start_dir);
    for ($i = 0; $i < 10; $i++) {
        if (file_exists($cur . '/wp-load.php')) return trailingslashit($cur);
        $parent = dirname($cur);
        if ($parent === $cur) break;
        $cur = $parent;
    }
    return false;
}
