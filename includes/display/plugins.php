<?php
/**
 * Clean Sweep - Plugin Display Functions
 *
 * Functions for displaying plugin analysis and re-installation results
 */

/**
 * Display merged final results combining reinstall and verification
 */
function clean_sweep_display_final_results($reinstall_results, $verification_results) {
    $success_count = count($reinstall_results['successful']);
    $fail_count = count($reinstall_results['failed']);
    $verified_count = count($verification_results['verified']);
    $missing_count = count($verification_results['missing']);
    $corrupted_count = count($verification_results['corrupted']);

    if (!defined('WP_CLI') || !WP_CLI) {
        echo '<h2>📊 Final Reinstallation Results</h2>';
        echo '<p style="background:#e7f3ff;border:1px solid #b8daff;padding:15px;border-radius:4px;margin:20px 0;">';
        echo 'Reinstallation complete. Below is a combined summary of the process and verification of installed plugins.';
        echo '</p>';

        // Combined stats with simplified verification
        echo '<div>';
        echo '<div class="stats-box" style="background:#d4edda;border-color:#c3e6cb;"><div class="stats-number" style="color:#155724;">' . $success_count . '</div><div class="stats-label">Successfully Reinstalled & Verified</div></div>';
        echo '<div class="stats-box" style="background:#f8d7da;border-color:#f5c6cb;"><div class="stats-number" style="color:#721c24;">' . $fail_count . '</div><div class="stats-label">Failed</div></div>';
        echo '<div class="stats-box" style="background:#d1ecf1;border-color:#bee5eb;"><div class="stats-number" style="color:#0c5460;">' . (isset($reinstall_results['skipped']) ? count($reinstall_results['skipped']) : 0) . '</div><div class="stats-label">Skipped</div></div>';
        echo '</div>';

        // Combined table
        echo '<table class="summary-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Plugin Name</th>';
        echo '<th>Reinstall Status</th>';
        echo '<th>Verification Status</th>';
        echo '<th>Details</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        // Process verified plugins (assume success if verified)
        foreach ($verification_results['verified'] as $plugin) {
            $reinstall_status = '✅ Success';
            $reinstall_class = 'plugin-success';
            $details = 'Downloaded from WordPress.org official repository';

            // Check if this plugin was skipped
            $skipped = false;
            if (isset($reinstall_results['skipped'])) {
                foreach ($reinstall_results['skipped'] as $skipped_plugin) {
                    if ($skipped_plugin['slug'] === $plugin['slug']) {
                        $skipped = true;
                        $reinstall_status = '⏭️ Skipped';
                        $reinstall_class = 'plugin-info';
                        $details = $skipped_plugin['reason'] ?? 'Preserved (not available for reinstallation)';
                        break;
                    }
                }
            }

            // Check if in failed WordPress.org results
            $failed_wordpress_org = false;
            if (!$skipped) {
                foreach ($reinstall_results['failed'] as $f) {
                    if ($f['slug'] === $plugin['slug']) {
                        $failed_wordpress_org = true;
                        break;
                    }
                }
            }

            // Check if this plugin was handled by WPMU DEV instead (successfully)
            $handled_by_wpmudev = false;
            if (!$skipped && isset($reinstall_results['wpmudev'])) {
                foreach ($reinstall_results['wpmudev']['wpmudev_plugins'] as $wpmudp) {
                    // WPMU DEV plugins use 'filename' field, not 'slug'
                    $wpmudev_identifier = $wpmudp['filename'] ?? $wpmudp['slug'] ?? '';
                    if ($wpmudev_identifier === $plugin['slug'] &&
                        isset($wpmudp['installed']) && $wpmudp['installed']) {
                        $handled_by_wpmudev = true;
                        break;
                    }
                }
            }

            // Check if this is a WPMU DEV plugin based on verification status
            $is_wpmu_dev_plugin = isset($plugin['status']) && strpos($plugin['status'], 'WPMU DEV') !== false;

            if ($skipped) {
                // Already set above
            } elseif ($handled_by_wpmudev || $is_wpmu_dev_plugin) {
                // Show as WPMU DEV success
                $reinstall_status = '✅ WPMU DEV';
                $reinstall_class = 'plugin-success';
                $details = 'Downloaded from WPMU DEV Premium secured network';
            } elseif ($failed_wordpress_org) {
                // Actual WordPress.org failure
                $reinstall_status = '❌ Failed';
                $reinstall_class = 'plugin-error';
                $details = 'WordPress.org reinstall failed - check logs';
            }

            echo '<tr class="' . $reinstall_class . '">';
            echo '<td>' . htmlspecialchars($plugin['name']) . '</td>';
            echo '<td><span style="' . ($skipped ? 'color:#17a2b8;' : ($handled_by_wpmudev || $is_wpmu_dev_plugin ? 'color:#7c3aed;' : 'color:#28a745;')) . 'font-weight:bold;">' . $reinstall_status . '</span></td>';
            echo '<td><span style="color:#28a745;font-weight:bold;">✅ Verified</span></td>';
            echo '<td>' . htmlspecialchars($details) . '</td>';
            echo '</tr>';
        }

        // Process missing
        foreach ($verification_results['missing'] as $plugin) {
            $reinstall_status = '✅ Success';
            $reinstall_class = 'plugin-success';
            $failed = false;
            foreach ($reinstall_results['failed'] as $f) {
                if ($f['slug'] === $plugin['slug']) {
                    $failed = true;
                    $reinstall_status = '❌ Failed';
                    $reinstall_class = 'plugin-error';
                    break;
                }
            }
            echo '<tr class="plugin-error ' . $reinstall_class . '">';
            echo '<td>' . htmlspecialchars($plugin['name']) . '</td>';
            echo '<td><span style="' . ($failed ? 'color:#dc3545;' : 'color:#28a745;') . 'font-weight:bold;">' . $reinstall_status . '</span></td>';
            echo '<td><span style="color:#dc3545;font-weight:bold;">❌ Missing</span></td>';
            echo '<td>Not found - check logs</td>';
            echo '</tr>';
        }

        // Process corrupted
        foreach ($verification_results['corrupted'] as $plugin) {
            $reinstall_status = '✅ Success';
            $reinstall_class = 'plugin-success';
            $failed = false;
            foreach ($reinstall_results['failed'] as $f) {
                if ($f['slug'] === $plugin['slug']) {
                    $failed = true;
                    $reinstall_status = '❌ Failed';
                    $reinstall_class = 'plugin-error';
                    break;
                }
            }
            echo '<tr class="plugin-warning ' . $reinstall_class . '">';
            echo '<td>' . htmlspecialchars($plugin['name']) . '</td>';
            echo '<td><span style="' . ($failed ? 'color:#dc3545;' : 'color:#28a745;') . 'font-weight:bold;">' . $reinstall_status . '</span></td>';
            echo '<td><span style="color:#ffc107;font-weight:bold;">⚠️ Corrupted</span></td>';
            echo '<td>Incomplete - manual fix needed</td>';
            echo '</tr>';
        }

        // Add any failed that weren't in verification (rare)
        foreach ($reinstall_results['failed'] as $plugin) {
            $found = false;
            foreach (array_merge($verification_results['verified'], $verification_results['missing'], $verification_results['corrupted']) as $v) {
                if ($v['slug'] === $plugin['slug']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                echo '<tr class="plugin-error">';
                echo '<td>' . htmlspecialchars($plugin['name']) . '</td>';
                echo '<td><span style="color:#dc3545;font-weight:bold;">❌ Failed</span></td>';
                echo '<td><span style="color:#dc3545;font-weight:bold;">❌ Not Verified</span></td>';
                echo '<td>Reinstall failed - check logs</td>';
                echo '</tr>';
            }
        }

        echo '</tbody>';
        echo '</table>';

        // Combined summary message
        $all_good = ($fail_count === 0 && $missing_count === 0 && $corrupted_count === 0);
        if ($all_good) {
            echo '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:15px;border-radius:4px;margin:20px 0;color:#155724;">';
            echo '<h3>🎉 Reinstallation and Verification Complete!</h3>';
            echo '<p>All ' . $success_count . ' plugins were successfully re-installed and verified.</p>';
            echo '</div>';
        } else {
            echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:15px;border-radius:4px;margin:20px 0;color:#721c24;">';
            echo '<h3>⚠️ Issues Detected</h3>';
            echo '<p>' . ($fail_count + $missing_count + $corrupted_count) . ' plugins had issues during reinstall or verification. Review the table and logs above.</p>';
            echo '</div>';
        }

    } else {
        // CLI merged output
        echo "\n📊 FINAL REINSTALLATION RESULTS\n";
        echo str_repeat("=", 50) . "\n";
        echo "✅ Reinstalled Successfully: $success_count\n";
        echo "✅ Verified: $verified_count\n";
        echo "❌ Failed/Missing: " . ($fail_count + $missing_count) . "\n";
        echo "⚠️  Corrupted: $corrupted_count\n";
        echo str_repeat("=", 50) . "\n";

        if (!empty($reinstall_results['successful'])) {
            echo "\n✅ SUCCESSFULLY RE-INSTALLED & VERIFIED:\n";
            foreach ($verification_results['verified'] as $plugin) {
                echo "  • {$plugin['name']} - Verified\n";
            }
        }

        if (!empty($reinstall_results['failed']) || !empty($verification_results['missing']) || !empty($verification_results['corrupted'])) {
            echo "\n⚠️  PLUGINS WITH ISSUES:\n";
            // Failed
            foreach ($reinstall_results['failed'] as $plugin) {
                echo "  • {$plugin['name']} - Reinstall Failed\n";
            }
            // Missing
            foreach ($verification_results['missing'] as $plugin) {
                echo "  • {$plugin['name']} - Missing after install\n";
            }
            // Corrupted
            foreach ($verification_results['corrupted'] as $plugin) {
                echo "  • {$plugin['name']} - Corrupted installation\n";
            }
        }

        if ($fail_count === 0 && $missing_count === 0 && $corrupted_count === 0) {
            echo "\n🎉 ALL PLUGINS SUCCESSFULLY RE-INSTALLED AND VERIFIED!\n";
        } else {
            echo "\n⚠️  ISSUES DETECTED - CHECK LOGS FOR DETAILS\n";
        }
    }
}

/**
 * Display only the plugins tab content (for AJAX responses)
 */
function clean_sweep_display_plugins_tab_content($plugin_results) {
    if ($plugin_results) {
        // Extract all available data from advanced analysis
        $wp_org_plugins = $plugin_results['wp_org_plugins'] ?? [];
        $wpmu_dev_plugins = $plugin_results['wpmu_dev_plugins'] ?? [];
        $non_repo_plugins = $plugin_results['non_repo_plugins'] ?? [];
        $suspicious_files = $plugin_results['suspicious_files'] ?? [];
        $copy_lists = $plugin_results['copy_lists'] ?? [];
        $totals = $plugin_results['totals'] ?? [];

        // Backward compatibility for old 'skipped' format
        $skipped = $plugin_results['skipped'] ?? [];
        if (empty($skipped) && !empty($non_repo_plugins)) {
            // Convert new format to old format for compatibility
            foreach ($non_repo_plugins as $plugin_file => $plugin_data) {
                $slug = $plugin_data['slug'] ?? $plugin_file;
                $skipped[$slug] = [
                    'name' => $plugin_data['name'] ?? $plugin_file,
                    'reason' => $plugin_data['reason'] ?? 'Non-repository plugin'
                ];
            }
        }

        $repo_count = count($wp_org_plugins);
        $wpmudev_count = count($wpmu_dev_plugins);
        $non_repo_count = count($non_repo_plugins);
        $suspicious_count = count($suspicious_files);
        $skipped_count = count($skipped);
        $total_to_reinstall = $repo_count + $wpmudev_count;

        $cached_indicator = '';
        if (isset($plugin_results['cached_at']) && isset($plugin_results['cache_expires'])) {
            $time_remaining = $plugin_results['cache_expires'] - time();
            if ($time_remaining > 0) {
                $minutes_remaining = round($time_remaining / 60);
                $cached_indicator = " <span style='background:#17a2b8;color:white;padding:2px 6px;border-radius:10px;font-size:11px;font-weight:normal;'>CACHED ({$minutes_remaining}min left)</span>";
            }
        }

        echo '<div class="analysis-header">';
        echo '<h3>🔍 Advanced Plugin Analysis Complete' . $cached_indicator . '</h3>';
        echo '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">';
        echo '<p style="margin:0; text-align:center; flex:1;">Comprehensive security analysis with suspicious file detection and detailed categorization</p>';
        echo '<button onclick="refreshPluginAnalysis()" style="background:#17a2b8;color:white;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:12px; flex-shrink:0;">🔄 Refresh Analysis</button>';
        echo '</div>';
        echo '</div>';

        // Enhanced Stats overview with all categories
        echo '<div class="enhanced-stats-container">';
        echo '<div class="enhanced-stats-box wordpress-org"><div class="enhanced-stats-number">' . $repo_count . '</div><div class="enhanced-stats-label">WordPress.org Plugins</div></div>';
        echo '<div class="enhanced-stats-box wpmu-dev"><div class="enhanced-stats-number">' . $wpmudev_count . '</div><div class="enhanced-stats-label">WPMU DEV Plugins</div></div>';
        echo '<div class="enhanced-stats-box non-repo"><div class="enhanced-stats-number">' . $non_repo_count . '</div><div class="enhanced-stats-label">Non-Repository</div></div>';
        echo '<div class="enhanced-stats-box suspicious"><div class="enhanced-stats-number">' . $suspicious_count . '</div><div class="enhanced-stats-label">Suspicious Files</div></div>';
        echo '</div>';

        // Security Analysis Summary
        echo '<div class="security-warning">';
        echo '<h4>🔒 Security Analysis Results</h4>';
        echo '<p>' . ($suspicious_count > 0 ? '<strong>⚠️ ALERT:</strong> ' . $suspicious_count . ' suspicious files detected in plugins directory! These will be automatically removed before plugin reinstallation for security.' : '<strong>✅ SECURE:</strong> No suspicious files found in plugins directory.') . '</p>';
        echo '<p><strong>Reinstallation Plan:</strong> ' . $total_to_reinstall . ' plugins will be re-installed (' . $repo_count . ' from WordPress.org, ' . $wpmudev_count . ' from WPMU DEV). ' . $non_repo_count . ' non-repository plugins will be preserved.</p>';
        if ($suspicious_count > 0) {
            echo '<p><strong>🛡️ Security Action:</strong> Suspicious files will be automatically removed before installing fresh plugins to prevent reinfection.</p>';
        }
        echo '</div>';

        // Plugin lists
        if (!empty($plugin_results['wp_org_plugins'])) {
            echo '<h4>📦 WordPress.org Plugins to be Re-installed (' . $repo_count . ') <button onclick="copyPluginList(\'reinstall\')" style="background:#007bff;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
            echo '<div style="background:white;padding:15px;border-radius:4px;border:1px solid #dee2e6;margin:10px 0;max-height:400px;overflow-y:auto;">';
            echo '<div style="margin-bottom:10px;"><button onclick="selectAllWpOrg()" style="background:#28a745;color:white;border:none;padding:6px 12px;border-radius:3px;cursor:pointer;font-size:12px;margin-right:5px;">Select All</button><button onclick="selectNoneWpOrg()" style="background:#dc3545;color:white;border:none;padding:6px 12px;border-radius:3px;cursor:pointer;font-size:12px;">Select None</button></div>';
            echo '<table class="plugin-analysis-table" style="width:100%;border-collapse:collapse;">';
            echo '<thead>';
            echo '<tr style="background:#f8f9fa;border-bottom:2px solid #dee2e6;">';
            echo '<th style="padding:10px;text-align:left;border-right:1px solid #dee2e6;width:40px;"><input type="checkbox" id="wp-org-select-all" onchange="toggleAllWpOrg(this.checked)"></th>';
            echo '<th style="padding:10px;text-align:left;border-right:1px solid #dee2e6;">Plugin Name</th>';
            echo '<th style="padding:10px;text-align:left;border-right:1px solid #dee2e6;">Current Version</th>';
            echo '<th style="padding:10px;text-align:left;border-right:1px solid #dee2e6;">Last Updated</th>';
            echo '<th style="padding:10px;text-align:left;">Plugin Page</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            foreach ($plugin_results['wp_org_plugins'] as $slug => $plugin_data) {
                $name = $plugin_data['name'] ?? $slug;
                $version = $plugin_data['version'] ?? 'Unknown';
                $last_updated = $plugin_data['last_updated'] ?? null;
                $plugin_url = $plugin_data['plugin_url'] ?? "https://wordpress.org/plugins/$slug/";

                // Format last updated as relative time
                $relative_time = clean_sweep_format_relative_time($last_updated);

                echo '<tr style="border-bottom:1px solid #dee2e6;">';
                echo '<td style="padding:10px;border-right:1px solid #dee2e6;text-align:center;"><input type="checkbox" class="wp-org-plugin-checkbox" data-slug="' . htmlspecialchars($slug) . '" checked onchange="updateSelectedCount()"></td>';
                echo '<td style="padding:10px;border-right:1px solid #dee2e6;"><strong>' . htmlspecialchars($name) . '</strong><br><small style="color:#666;">(' . $slug . ')</small></td>';
                echo '<td style="padding:10px;border-right:1px solid #dee2e6;">' . htmlspecialchars($version) . '</td>';
                echo '<td style="padding:10px;border-right:1px solid #dee2e6;">' . htmlspecialchars($relative_time) . '</td>';
                echo '<td style="padding:10px;"><a href="' . htmlspecialchars($plugin_url) . '" target="_blank" style="color:#007bff;text-decoration:none;">View Plugin →</a></td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }

        // Check WPMU DEV authentication status and show warning if needed
        $wpmu_dev_available = isset($plugin_results['wpmu_dev_available']) ? $plugin_results['wpmu_dev_available'] : true;

        // WPMU DEV plugins section (WPMU DEV Dashboard already filtered out during analysis)
        if (!empty($plugin_results['wpmu_dev_plugins'])) {
            $wpmu_count = count($plugin_results['wpmu_dev_plugins']);
            $total_to_reinstall = $repo_count + $wpmu_count;

            // Show warning if WPMU DEV is not authenticated
            if (!$wpmu_dev_available) {
                echo '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:15px 0;color:#856404;">';
                echo '<h4 style="margin:0 0 10px 0;color:#856404;">⚠️ WPMU DEV Dashboard Not Connected</h4>';
                echo '<p style="margin:0;font-size:14px;">Your site is not connected to the WPMU DEV Hub. WPMU DEV premium plugins listed below <strong>cannot be automatically reinstalled</strong> because authentication is required.</p>';
                echo '<p style="margin:5px 0 0 0;font-size:13px;"><strong>To fix this:</strong> Go to <em>WPMU DEV → Settings → API</em> and connect your site to the WPMU DEV Hub, then re-run the plugin analysis.</p>';
                echo '</div>';
            }

            echo '<h4>💎 WPMU DEV Premium Plugins to be Re-installed (' . $wpmu_count . ') <button onclick="copyPluginList(\'wpmudev\')" style="background:#7c3aed;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
            echo '<div style="background:#f8f9ff;padding:15px;border-radius:4px;border:1px solid #c3b1e1;margin:10px 0;max-height:400px;overflow-y:auto;">';
            echo '<div style="margin-bottom:10px;"><button onclick="selectAllWpmuDev()" style="background:#28a745;color:white;border:none;padding:6px 12px;border-radius:3px;cursor:pointer;font-size:12px;margin-right:5px;">Select All</button><button onclick="selectNoneWpmuDev()" style="background:#dc3545;color:white;border:none;padding:6px 12px;border-radius:3px;cursor:pointer;font-size:12px;">Select None</button></div>';

            echo '<table class="plugin-analysis-table" style="width:100%;border-collapse:collapse;">';
            echo '<thead>';
            echo '<tr style="background:#f0efff;border-bottom:2px solid #c3b1e1;">';
            echo '<th style="padding:10px;text-align:left;border-right:1px solid #c3b1e1;width:40px;"><input type="checkbox" id="wpmu-dev-select-all" onchange="toggleAllWpmuDev(this.checked)"></th>';
            echo '<th style="padding:10px;text-align:left;border-right:1px solid #c3b1e1;">Plugin Name</th>';
            echo '<th style="padding:10px;text-align:left;border-right:1px solid #c3b1e1;">Current Version</th>';
            echo '<th style="padding:10px;text-align:left;">Description</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            foreach ($plugin_results['wpmu_dev_plugins'] as $slug => $plugin_data) {
                $name = $plugin_data['name'] ?? $slug;
                $version = $plugin_data['version'] ?? 'Unknown';
                $description = $plugin_data['description'] ?? '';

                echo '<tr style="border-bottom:1px solid #c3b1e1;">';
                echo '<td style="padding:10px;border-right:1px solid #c3b1e1;text-align:center;"><input type="checkbox" class="wpmu-dev-plugin-checkbox" data-slug="' . htmlspecialchars($slug) . '" checked onchange="updateSelectedCount()"></td>';
                echo '<td style="padding:10px;border-right:1px solid #c3b1e1;"><strong>' . htmlspecialchars($name) . '</strong><br><small style="color:#666;">(' . $slug . ')</small></td>';
                echo '<td style="padding:10px;border-right:1px solid #c3b1e1;">' . htmlspecialchars($version) . '</td>';
                echo '<td style="padding:10px;">' . htmlspecialchars(substr($description, 0, 150)) . (strlen($description) > 150 ? '...' : '') . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '<div style="margin-top:10px;padding:10px;background:#e8e2f8;border-radius:4px;font-size:13px;color:#5a4fc7;">';
            echo '<strong>WPMU DEV Security:</strong> These premium plugins will be updated from WPMU DEV\'s secured API network, ensuring tamper-resistant installations.';
            echo '</div>';
            echo '</div>';
        }

        // Non-repository plugins section
        if (!empty($non_repo_plugins)) {
            echo '<h4>📋 Non-Repository Plugins (' . $non_repo_count . ') <button onclick="copyPluginList(\'nonrepo\')" style="background:#17a2b8;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
            echo '<div style="background:#d1ecf1;padding:15px;border-radius:4px;border:1px solid #bee5eb;margin:10px 0;max-height:200px;overflow-y:auto;">';
            echo '<table class="plugin-analysis-table" style="width:100%;border-collapse:collapse;">';
            echo '<thead>';
            echo '<tr style="background:#bee5eb;border-bottom:1px solid #17a2b8;">';
            echo '<th style="padding:8px;text-align:left;">Plugin Name</th>';
            echo '<th style="padding:8px;text-align:left;">Reason</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            foreach ($non_repo_plugins as $plugin_file => $plugin_data) {
                $name = $plugin_data['name'] ?? $plugin_file;
                $reason = $plugin_data['reason'] ?? 'Not found in repositories';
                echo '<tr style="border-bottom:1px solid #bee5eb;">';
                echo '<td style="padding:8px;"><strong>' . htmlspecialchars($name) . '</strong></td>';
                echo '<td style="padding:8px;">' . htmlspecialchars($reason) . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '<div style="margin-top:10px;padding:8px;background:#bee5eb;border-radius:4px;font-size:12px;color:#0c5460;">';
            echo '<strong>Note:</strong> These plugins are not available in WordPress.org or WPMU DEV repositories and will be preserved as-is.';
            echo '</div>';
            echo '</div>';
        }

        // Suspicious files section - SECURITY FEATURE
        if (!empty($suspicious_files)) {
            echo '<h4>🚨 Suspicious Files Detected (' . $suspicious_count . ') <button onclick="copyPluginList(\'suspicious\')" style="background:#dc3545;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
            echo '<div style="background:#f8d7da;border:2px solid #dc3545;padding:15px;border-radius:4px;margin:10px 0;max-height:300px;overflow-y:auto;">';
            echo '<div style="margin-bottom:10px;padding:10px;background:#f5c6cb;border-radius:4px;color:#721c24;font-weight:bold;">';
            echo '⚠️ SECURITY WARNING: Suspicious files detected in plugins directory! These may be malware or unauthorized modifications.';
            echo '</div>';
            echo '<table class="plugin-analysis-table" style="width:100%;border-collapse:collapse;">';
            echo '<thead>';
            echo '<tr style="background:#dc3545;color:white;border-bottom:2px solid #bd2130;">';
            echo '<th style="padding:10px;text-align:left;border-right:1px solid #bd2130;">File/Folder Name</th>';
            echo '<th style="padding:10px;text-align:left;border-right:1px solid #bd2130;">Type</th>';
            echo '<th style="padding:10px;text-align:left;border-right:1px solid #bd2130;">Size</th>';
            echo '<th style="padding:10px;text-align:left;">Last Modified</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            foreach ($suspicious_files as $file) {
                $type = $file['is_directory'] ? 'Directory' : 'File';
                $size_display = $file['is_directory'] ?
                    ($file['file_count'] . ' files') :
                    $file['size_mb'] . ' MB';
                $last_modified = date('Y-m-d H:i', $file['last_modified']);

                echo '<tr style="border-bottom:1px solid #dc3545;background:#f8d7da;">';
                echo '<td style="padding:10px;border-right:1px solid #dc3545;"><strong>' . htmlspecialchars($file['name']) . '</strong></td>';
                echo '<td style="padding:10px;border-right:1px solid #dc3545;">' . $type . '</td>';
                echo '<td style="padding:10px;border-right:1px solid #dc3545;">' . $size_display . '</td>';
                echo '<td style="padding:10px;">' . $last_modified . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '<div style="margin-top:10px;padding:10px;background:#f5c6cb;border-radius:4px;font-size:12px;color:#721c24;">';
            echo '<strong>Recommendation:</strong> Review these suspicious files before proceeding. They may contain malware or unauthorized code. Consider backing up your site and consulting security experts if unsure.';
            echo '</div>';
            echo '</div>';
        }

        // Legacy skipped section (for backward compatibility)
        if (!empty($plugin_results['skipped']) && empty($non_repo_plugins)) {
            echo '<h4>⏭️ Plugins to be Skipped (' . $skipped_count . ') <button onclick="copyPluginList(\'skipped\')" style="background:#6c757d;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
            echo '<div style="background:#fff3cd;padding:15px;border-radius:4px;border:1px solid #ffeaa7;margin:10px 0;max-height:150px;overflow-y:auto;">';
            echo '<ul style="margin:0;padding-left:20px;">';
            foreach ($plugin_results['skipped'] as $plugin) {
                echo '<li style="margin:5px 0;"><strong>' . htmlspecialchars($plugin['name']) . '</strong> - ' . htmlspecialchars($plugin['reason']) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        // Core Integrity Baseline Section
        echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:15px;border-radius:4px;margin:20px 0;">';
        echo '<h4>🛡️ Core Integrity Baseline</h4>';
        echo '<p>Establish a cryptographic baseline of your WordPress core files to detect future reinfection attempts. This creates SHA256 hashes of critical core files for ongoing integrity monitoring.</p>';
        echo '<div style="margin:10px 0;">';
        echo '<button onclick="establishCoreBaseline()" style="background:#17a2b8;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-weight:bold;">🔐 Establish Core Baseline</button>';
        echo '<span id="baseline-status" style="margin-left:10px;font-size:12px;color:#666;"></span>';
        echo '</div>';
        echo '<p style="font-size:12px;color:#666;margin:5px 0;"><em>Recommended after core reinstallation or when you\'re confident your core files are clean.</em></p>';
        echo '</div>';

        // Safety warnings (backup choice now handled during progress)
        echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:15px;border-radius:4px;margin:20px 0;">';
        echo '<h4>⚠️ Important Safety Information</h4>';
        echo '<ul style="margin:10px 0;padding-left:20px;">';
        echo '<li>You will be asked whether to create a backup before proceeding</li>';
        echo '<li>Hello Dolly (demo plugin) will be automatically removed if present</li>';
        echo '<li><strong>WPMU DEV Dashboard:</strong> Will be automatically preserved (core dashboard plugin cannot be reinstalled)</li>';
        echo '<li>This process cannot be undone - review the list above carefully</li>';
        echo '<li>Ensure you have database backups before proceeding</li>';
        echo '</ul>';
        echo '</div>';



        // Start button with AJAX and confirmation
        echo '<div style="text-align:center;margin:30px 0;">';
        // Combine all plugins that will be reinstalled (WPMU DEV Dashboard already filtered out during analysis)
        $all_plugins_to_reinstall = array_merge(
            $plugin_results['wp_org_plugins'] ?? [],
            $plugin_results['wpmu_dev_plugins'] ?? []
        );
        $actual_reinstall_count = count($all_plugins_to_reinstall);

        echo '<button id="reinstall-button" onclick="confirmPluginReinstallation(this)" data-plugins="' . htmlspecialchars(json_encode($all_plugins_to_reinstall)) . '" data-analysis="' . htmlspecialchars(json_encode($plugin_results)) . '" style="background:#dc3545;color:white;border:none;padding:15px 30px;font-size:18px;font-weight:bold;border-radius:4px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.2);">';
        echo '🚀 Start Selective Plugin Re-installation (<span id="selected-count">' . $actual_reinstall_count . '</span> selected)';
        echo '</button>';
        echo '<p style="margin-top:10px;color:#666;font-size:14px;">Select which plugins to reinstall from the lists above. WordPress.org plugins from official repository + WPMU DEV premium plugins from secured network</p>';
        echo '</div>';



        // Store analysis data for JavaScript to use during reinstall
        echo '<script>';
        echo 'window.currentPluginAnalysis = ' . json_encode($plugin_results) . ';';
        echo '</script>';

        // Progress display area (always present for AJAX operations)
        echo '<div id="plugin-progress-container" style="display:none;margin:20px 0;">';
        echo '<div class="progress-container">';
        echo '<h3><span id="plugin-status-indicator" class="status-indicator status-processing">Processing</span> Plugin Operation Progress</h3>';
        echo '<div class="progress-bar"><div id="plugin-progress-fill" class="progress-fill" style="width:0%"></div></div>';
        echo '<div id="plugin-progress-text" class="progress-text">Initializing...</div>';
        echo '</div>';
        echo '<div id="plugin-progress-details" style="background:#f8f9fa;border:1px solid #dee2e6;padding:15px;border-radius:4px;margin:10px 0;"></div>';
        echo '</div>';
    } else {
        echo '<h3>📦 Plugin Analysis Failed</h3>';
        echo '<p>No plugin analysis results available. Please return to the plugins tab and perform analysis first.</p>';
    }
}
