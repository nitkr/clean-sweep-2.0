<?php
/**
 * Clean Sweep - Display Module Main Entry
 *
 * Main entry point for display functionality - includes all display modules.
 * This file serves as the central hub that loads all display modules.
 */

// Include all display modules in dependency order
require_once __DIR__ . '/utils.php';     // Utility functions and data processing
require_once __DIR__ . '/malware.php';   // Malware scan display functions
require_once __DIR__ . '/plugins.php';   // Plugin display functions
require_once __DIR__ . '/ui.php';        // General UI and tabbed interface (contains display_toolkit_interface)

// Module Loading Summary:
// ├── utils.php     - Risk assessment, categorization, data extraction
// ├── malware.php   - Malware scan results and threat displays
// ├── plugins.php   - Plugin analysis and installation results
// └── ui.php        - Main tabbed interface and display_toolkit_interface()
//
// All functions are now available when main display.php includes this file.
// Note: This ensures all display functions are loaded in the global scope.

/**
 * Backward compatibility wrapper for display_malware_scan_results()
 * Routes to the new timeline-based malware display system
 */
function clean_sweep_display_malware_scan_results($scan_results) {
    clean_sweep_display_malware_scan_results_real($scan_results);
}

/**
 * Real implementation of malware scan result display
 */
function clean_sweep_display_malware_scan_results_real($scan_results) {
    if ($scan_results && isset($scan_results['summary'])) {
        $summary = $scan_results['summary'];
        $total_threats = $summary['total_threats'] ?? 0;

        echo '<h3>🔍 Malware Scan Results - ' . $total_threats . ' Threats Found</h3>';

        // Show mbstring warning if applicable
        $mbstring_available = function_exists('mb_check_encoding') &&
                             function_exists('mb_convert_encoding') &&
                             function_exists('mb_strpos');

        if (!$mbstring_available) {
            echo '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:12px;border-radius:6px;margin:0 0 15px 0;">';
            echo '<h5 style="margin:0 0 8px 0;color:#856404;">⚠️ PHP Extension Notice</h5>';
            echo '<p style="margin:0;font-size:14px;color:#856404;">';
            echo 'Your server has the <strong>mbstring</strong> PHP extension disabled. ';
            echo 'This may cause encoding issues with special characters in scan results. ';
            echo 'Ask your hosting provider to enable the <code style="background:#e9ecef;padding:2px 4px;border-radius:3px;">mbstring</code> extension ';
            echo 'for optimal results.';
            echo '</p>';
            echo '</div>';
        }

        // Enhanced summary with risk breakdown
        $risk_counts = clean_sweep_count_threats_by_risk($scan_results);
        $shown_threats = isset($scan_results['truncated']) && $scan_results['truncated']
            ? clean_sweep_count_displayed_threats($scan_results)
            : $total_threats;

        echo '<div style="background:#f8f9fa;border:1px solid #dee2e6;padding:20px;border-radius:8px;margin:20px 0;box-shadow:0 2px 4px rgba(0,0,0,0.1);">';

        echo '<div style="display:flex;flex-wrap:wrap;gap:15px;margin-bottom:20px;">';
        echo '<div class="stats-box" style="background:#d1ecf1;border-color:#bee5eb;flex:1;min-width:120px;"><div class="stats-number" style="color:#0c5460;">' . ($summary['total_scanned'] ?? 0) . '</div><div class="stats-label">Records Scanned</div></div>';
        echo '<div class="stats-box" style="background:#d1ecf1;border-color:#bee5eb;flex:1;min-width:120px;"><div class="stats-number" style="color:#0c5460;">' . ($scan_results['files']['total_files_scanned'] ?? 0) . '</div><div class="stats-label">Files Scanned</div></div>';
        if (isset($scan_results['files']['scan_path'])) {
            // Use ORIGINAL_ABSPATH in recovery mode for correct path display
            $base_path = defined('ORIGINAL_ABSPATH') ? ORIGINAL_ABSPATH : ABSPATH;
            $scan_path_display = str_replace($base_path, '', $scan_results['files']['scan_path']);
            echo '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:10px;border-radius:4px;margin:15px 0;font-size:14px;">';
            echo '<strong>🔍 Scanned Directory:</strong> <code style="background:#f8f9fa;padding:2px 6px;border-radius:3px;">' . htmlspecialchars($scan_path_display) . '</code>';
            echo '</div>';
        }
        echo '<div class="stats-box" style="background:#f8d7da;border-color:#f5c6cb;flex:1;min-width:120px;"><div class="stats-number" style="color:#721c24;">' . $total_threats . '</div><div class="stats-label">Threats Found</div></div>';
        echo '</div>';

        // Risk level breakdown
        if (!empty($risk_counts)) {
            echo '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:15px;">';
            foreach (['critical' => ['🔴', 'CRITICAL'], 'warning' => ['🟡', 'WARNING'], 'info' => ['ℹ️', 'INFO']] as $level => $info) {
                $count = $risk_counts[$level] ?? 0;
                if ($count > 0) {
                    $color = $level === 'critical' ? '#dc3545' : ($level === 'warning' ? '#ffc107' : '#17a2b8');
                    echo '<span style="background:' . $color . ';color:white;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:bold;">';
                    echo $info[0] . ' ' . $count . ' ' . $info[1];
                    echo '</span>';
                }
            }
            echo '</div>';
        }

        echo '</div>';

        // Enhanced pagination controls (moved to top for better UX)
        if (isset($scan_results['truncated']) && $scan_results['truncated']) {
            $total_available = $scan_results['full_count'] ?? $total_threats;
            $pages_needed = ceil($total_available / 50);
            $current_page = 1;

            echo '<div class="threat-pagination-header" style="background:#e7f0ff;border:1px solid #b3d9ff;padding:20px;border-radius:8px;margin:20px 0;text-align:center;">';
            echo '<h4 style="margin:0 0 15px 0;color:#084c7d;">📄 Threat Results Navigation</h4>';

            echo '<div style="display:flex;justify-content:center;align-items:center;gap:15px;margin-bottom:15px;flex-wrap:wrap;">';
            echo '<button class="pagination-btn" onclick="loadNextThreatPage(\'' . htmlspecialchars($scan_results['request_id']) . '\', ' . ($current_page + 1) . ', 50)" style="background:#007bff;color:white;border:none;padding:12px 20px;font-size:14px;border-radius:6px;cursor:pointer;min-width:140px;">';
            echo '▶️ Load Next Page';
            echo '</button>';

            echo '<button class="pagination-btn" onclick="loadAllRemainingThreats(\'' . htmlspecialchars($scan_results['request_id']) . '\')" style="background:#28a745;color:white;border:none;padding:12px 20px;font-size:14px;border-radius:6px;cursor:pointer;min-width:140px;">';
            echo '📥 Load All Remaining';
            echo '</button>';
            echo '</div>';

            echo '<div style="font-size:14px;color:#495057;margin-bottom:10px;">';
            echo '<strong>Page ' . $current_page . ' of ' . $pages_needed . '</strong> • ';
            echo '<strong>' . $shown_threats . '</strong> threats shown of <strong>' . $total_available . '</strong> total';
            echo '</div>';

            echo '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:10px;border-radius:4px;font-size:13px;color:#856404;">';
            echo '💡 <strong>Tip:</strong> Use "Load All Remaining" for complete results or navigate page-by-page for better performance.';
            echo '</div>';
            echo '</div>';
        }

        // Use the new timeline display system
        clean_sweep_display_categorized_threats($scan_results);

        // Success/clean result
        if ($total_threats === 0) {
            echo '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:20px;border-radius:8px;margin:20px 0;color:#155724;text-align:center;">';
            echo '<h4>✅ Scan Complete - No Threats Found!</h4>';
            echo '<p>Great news! Your WordPress installation appears to be clean of known malware signatures.</p>';
            echo '<p style="font-size:14px;color:#7d9472;margin:10px 0;"><em>Remember: This scan detects known patterns. Keep WordPress updated for maximum security.</em></p>';
            echo '</div>';
        }



        // INTEGRITY BASELINE MANAGEMENT SECTION
        echo '<div class="integrity-baseline-section" style="background:#f8f9fa;border:1px solid #dee2e6;padding:25px;border-radius:8px;margin:30px 0;">';
        echo '<h3 style="margin:0 0 20px 0;color:#495057;">🔐 Integrity Baseline Management</h3>';

        // Baseline status
        $baseline_exists = clean_sweep_get_core_baseline() !== null;
        echo '<div style="margin-bottom:20px;padding:15px;border-radius:6px;background:white;border:1px solid #dee2e6;">';
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">';
        echo '<div style="width:12px;height:12px;border-radius:50%;background:' . ($baseline_exists ? '#28a745' : '#dc3545') . ';"></div>';
        echo '<strong>Baseline Status:</strong> ' . ($baseline_exists ? 'Established' : 'Not Established');
        echo '</div>';

        if ($baseline_exists) {
            $baseline = clean_sweep_get_core_baseline();
            $established = isset($baseline['established_at']) ? date('Y-m-d H:i:s', $baseline['established_at']) : 'Unknown';
            $wp_version = $baseline['wp_version'] ?? 'Unknown';
            echo '<div style="font-size:14px;color:#6c757d;">';
            echo '<strong>Established:</strong> ' . $established . ' • ';
            echo '<strong>WordPress Version:</strong> ' . $wp_version;
            echo '</div>';
        }
        echo '</div>';

        // Baseline actions
        echo '<div style="display:flex;flex-wrap:wrap;gap:15px;">';
        echo '<button onclick="establishBaseline()" style="background:#28a745;color:white;border:none;padding:12px 20px;border-radius:6px;cursor:pointer;font-weight:500;min-width:160px;">';
        echo '📋 Establish Baseline';
        echo '</button>';

        echo '<button onclick="exportBaseline()" style="background:#007bff;color:white;border:none;padding:12px 20px;border-radius:6px;cursor:pointer;font-weight:500;min-width:160px;">';
        echo '📤 Export Baseline';
        echo '</button>';

        echo '<div style="display:flex;align-items:center;gap:10px;">';
        echo '<input type="file" id="import-baseline-file" accept=".json" style="flex:1;min-width:200px;padding:8px;border:1px solid #dee2e6;border-radius:4px;">';
        echo '<button onclick="importBaseline()" style="background:#6c757d;color:white;border:none;padding:12px 20px;border-radius:6px;cursor:pointer;font-weight:500;">';
        echo '📥 Import & Compare';
        echo '</button>';
        echo '</div>';
        echo '</div>';

        // Status messages container
        echo '<div id="baseline-status-messages" style="margin-top:15px;"></div>';

        echo '</div>';

        echo '<div id="additional-threats-container" style="margin-top:20px;"></div>'; // Container for loaded threats

    } else {
        echo '<h3>🔍 Malware Scan Failed</h3>';
        echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:15px;border-radius:4px;margin:20px 0;color:#721c24;">';
        echo '<p>Scan could not be completed. Check logs for details.</p>';
        echo '</div>';
    }
}
?>
