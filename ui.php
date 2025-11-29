<?php
/**
 * Clean Sweep - UI Components
 *
 * Contains all HTML, CSS, and JavaScript output functions
 * for the Clean Sweep web interface.
 *
 * @author Nithin K R
 */

/**
 * Output HTML header for browser execution
 */
function clean_sweep_output_html_header() {
    if (!defined('WP_CLI') || !WP_CLI) {
        echo '<!DOCTYPE html><html><head><title>Clean Sweep - WordPress Malware Cleanup Toolkit</title>';
        echo '<link rel="stylesheet" href="assets/css/style.css">';
        echo '<script src="assets/script.js"></script>';
        echo '<script src="includes/system/polling/CleanSweep_ProgressPoller.js"></script>';
        echo '<script src="includes/system/polling/CleanSweep_ProgressUI.js"></script>';
        echo '</head><body><h1>🧹 Clean Sweep v ' . CLEAN_SWEEP_VERSION . '</h1>';
    }
}



/**
 * Output HTML footer for browser execution
 */
function clean_sweep_output_html_footer() {
    if (!defined('WP_CLI') || !WP_CLI) {
        // Only show completion message if re-installation was actually performed
        if (isset($_POST['action']) && $_POST['action'] === 'reinstall_plugins') {
            echo '<hr><p><strong>Process completed.</strong> Check the log file for details: <code>' . LOGS_DIR . LOG_FILE . '</code></p>';
            echo '<p><strong>Backup location:</strong> <code>' . __DIR__ . '/' . BACKUP_DIR . '</code></p>';
            echo '<p><em>Remember to re-activate your plugins through the WordPress admin panel.</em></p>';
        }
        echo '</body></html>';
    }
}
