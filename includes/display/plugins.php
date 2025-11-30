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

    if (!defined('WP_CLI') || !WP_CLI) {
        echo '<h2>📊 Final Reinstallation Results</h2>';
        echo '<p style="background:#e7f3ff;border:1px solid #b8daff;padding:15px;border-radius:4px;margin:20px 0;">';
        echo 'Reinstallation complete. All plugins have been processed for re-installation from their official sources.';
        echo '</p>';

        // Installation stats only
        echo '<div>';
        echo '<div class="stats-box" style="background:#d4edda;border-color:#c3e6cb;"><div class="stats-number" style="color:#155724;">' . $success_count . '</div><div class="stats-label">Reinstalled Successfully</div></div>';
        echo '<div class="stats-box" style="background:#f8d7da;border-color:#f5c6cb;"><div class="stats-number" style="color:#721c24;">' . $fail_count . '</div><div class="stats-label">Failed</div></div>';
        echo '</div>';

        // Plugin results table
        echo '<table class="summary-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Plugin Name</th>';
        echo '<th>Reinstall Status</th>';
        echo '<th>Source</th>';
        echo '<th>Details</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        // Process successful plugins
        foreach ($reinstall_results['successful'] as $plugin) {
            $source = 'WordPress.org';
            $source_color = '#007bff';
            $details = 'Successfully reinstalled from WordPress.org repository';

            // Check if this was a WPMU DEV plugin
            if (isset($plugin['source']) && $plugin['source'] === 'wpmu_dev') {
                $source = 'WPMU DEV';
                $source_color = '#7c3aed';
                $details = 'Successfully reinstalled from WPMU DEV Premium network';
            }

            echo '<tr class="plugin-success">';
            echo '<td>' . htmlspecialchars($plugin['name']) . '</td>';
            echo '<td><span style="color:#28a745;font-weight:bold;">✅ Success</span></td>';
            echo '<td><span style="color:' . $source_color . ';font-weight:bold;">' . $source . '</span></td>';
            echo '<td>' . htmlspecialchars($details) . '</td>';
            echo '</tr>';
        }

        // Process failed plugins
        foreach ($reinstall_results['failed'] as $plugin) {
            $source = 'WordPress.org';
            $source_color = '#007bff';
            $details = 'Reinstallation failed - check logs for details';

            // Check if this was a WPMU DEV plugin
            if (isset($plugin['source']) && $plugin['source'] === 'wpmu_dev') {
                $source = 'WPMU DEV';
                $source_color = '#7c3aed';
            }

            echo '<tr class="plugin-error">';
            echo '<td>' . htmlspecialchars($plugin['name']) . '</td>';
            echo '<td><span style="color:#dc3545;font-weight:bold;">❌ Failed</span></td>';
            echo '<td><span style="color:' . $source_color . ';font-weight:bold;">' . $source . '</span></td>';
            echo '<td>' . htmlspecialchars($details) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        // Summary message
        if ($fail_count === 0) {
            echo '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:15px;border-radius:4px;margin:20px 0;color:#155724;">';
            echo '<h3>🎉 Reinstallation Complete!</h3>';
            echo '<p>All ' . $success_count . ' plugins were successfully re-installed from their official sources.</p>';
            echo '</div>';
        } else {
            echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:15px;border-radius:4px;margin:20px 0;color:#721c24;">';
            echo '<h3>⚠️ Issues Detected</h3>';
            echo '<p>' . $fail_count . ' plugins failed to reinstall. Review the table and logs above for details.</p>';
            echo '</div>';
        }

    } else {
        // CLI output
        echo "\n📊 FINAL REINSTALLATION RESULTS\n";
        echo str_repeat("=", 50) . "\n";
        echo "✅ Reinstalled Successfully: $success_count\n";
        echo "❌ Failed: $fail_count\n";
        echo str_repeat("=", 50) . "\n";

        if (!empty($reinstall_results['successful'])) {
            echo "\n✅ SUCCESSFULLY RE-INSTALLED:\n";
            foreach ($reinstall_results['successful'] as $plugin) {
                $source = isset($plugin['source']) && $plugin['source'] === 'wpmu_dev' ? 'WPMU DEV' : 'WordPress.org';
                echo "  • {$plugin['name']} - {$source}\n";
            }
        }

        if (!empty($reinstall_results['failed'])) {
            echo "\n❌ FAILED TO REINSTALL:\n";
            foreach ($reinstall_results['failed'] as $plugin) {
                echo "  • {$plugin['name']} - Check logs for details\n";
            }
        }

        if ($fail_count === 0) {
            echo "\n🎉 ALL PLUGINS SUCCESSFULLY RE-INSTALLED!\n";
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
        $repo_count = count($plugin_results['wp_org_plugins'] ?? []);
        $wpmudev_count = count($plugin_results['wpmu_dev_plugins'] ?? []);
        $skipped_count = count($plugin_results['non_repo_plugins'] ?? []);
        $total_to_reinstall = $repo_count + $wpmudev_count;

        echo '<h3>📦 Plugin Analysis Complete</h3>';

        // Stats overview
        echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
        echo '<h4>📊 Analysis Summary</h4>';
        echo '<div>';
        echo '<div class="stats-box" style="background:#d1ecf1;border-color:#bee5eb;"><div class="stats-number" style="color:#0c5460;">' . $repo_count . '</div><div class="stats-label">WordPress.org Plugins</div></div>';
        echo '<div class="stats-box" style="background:#ffd700;border-color:#ffed4e;"><div class="stats-number" style="color:#000000;">' . $wpmudev_count . '</div><div class="stats-label">WPMU DEV Plugins</div></div>';
        echo '<div class="stats-box" style="background:#f8d7da;border-color:#f5c6cb;"><div class="stats-number" style="color:#721c24;">' . $skipped_count . '</div><div class="stats-label">Non-Repository (Skipped)</div></div>';
        echo '</div>';
        echo '<p><strong>What will happen:</strong> ' . $total_to_reinstall . ' plugins will be re-installed (' . $repo_count . ' from WordPress.org repository, ' . $wpmudev_count . ' from WPMU DEV\'s secured network). ' . $skipped_count . ' non-repository plugins will be preserved.</p>';
        echo '</div>';

        // Plugin lists
        if (!empty($plugin_results['wp_org_plugins'])) {
            echo '<h4>📦 WordPress.org Plugins to be Re-installed (' . $repo_count . ') <button onclick="copyPluginList(\'reinstall\')" style="background:#007bff;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
            echo '<div style="background:white;padding:15px;border-radius:4px;border:1px solid #dee2e6;margin:10px 0;max-height:400px;overflow-y:auto;">';
            echo '<table class="plugin-analysis-table" style="width:100%;border-collapse:collapse;">';
            echo '<thead>';
            echo '<tr style="background:#f8f9fa;border-bottom:2px solid #dee2e6;">';
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

        // WPMU DEV plugins section
        if (!empty($plugin_results['wpmu_dev_plugins'])) {
            echo '<h4>💎 WPMU DEV Premium Plugins to be Re-installed (' . $wpmudev_count . ') <button onclick="copyPluginList(\'wpmudev\')" style="background:#7c3aed;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
            echo '<div style="background:#f8f9ff;padding:15px;border-radius:4px;border:1px solid #c3b1e1;margin:10px 0;max-height:400px;overflow-y:auto;">';
            echo '<table class="plugin-analysis-table" style="width:100%;border-collapse:collapse;">';
            echo '<thead>';
            echo '<tr style="background:#f0efff;border-bottom:2px solid #c3b1e1;">';
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

        if (!empty($plugin_results['skipped'])) {
            echo '<h4>⏭️ Plugins to be Skipped (' . $skipped_count . ') <button onclick="copyPluginList(\'skipped\')" style="background:#6c757d;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
            echo '<div style="background:#fff3cd;padding:15px;border-radius:4px;border:1px solid #ffeaa7;margin:10px 0;max-height:150px;overflow-y:auto;">';
            echo '<ul style="margin:0;padding-left:20px;">';
            foreach ($plugin_results['skipped'] as $plugin) {
                echo '<li style="margin:5px 0;"><strong>' . htmlspecialchars($plugin['name']) . '</strong> - ' . htmlspecialchars($plugin['reason']) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        // Safety warnings
        echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:15px;border-radius:4px;margin:20px 0;">';
        echo '<h4>⚠️ Important Safety Information</h4>';
        echo '<ul style="margin:10px 0;padding-left:20px;">';
        echo '<li>A complete backup of your current plugins will be created automatically</li>';
        echo '<li>Hello Dolly (demo plugin) will be automatically removed if present</li>';
        echo '<li>This process cannot be undone - review the list above carefully</li>';
        echo '<li>Ensure you have database backups before proceeding</li>';
        echo '</ul>';
        echo '</div>';

        // Start button with AJAX
        echo '<div style="text-align:center;margin:30px 0;">';
        // Combine all plugins that will be reinstalled
        $all_plugins_to_reinstall = array_merge(
            $plugin_results['wp_org_plugins'] ?? [],
            $plugin_results['wpmu_dev_plugins'] ?? []
        );
        echo '<button onclick="confirmPluginReinstallation(this)" data-plugins="' . htmlspecialchars(json_encode($all_plugins_to_reinstall)) . '" style="background:#dc3545;color:white;border:none;padding:15px 30px;font-size:18px;font-weight:bold;border-radius:4px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.2);">';
        echo '🚀 Start Complete Ecosystem Re-installation (' . $total_to_reinstall . ' plugins)';
        echo '</button>';
        echo '<p style="margin-top:10px;color:#666;font-size:14px;">WordPress.org plugins from official repository + WPMU DEV premium plugins from secured network</p>';
        echo '</div>';

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
