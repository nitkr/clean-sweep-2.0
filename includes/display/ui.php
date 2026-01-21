<?php
/**
 * Clean Sweep - UI Display Functions
 *
 * Functions for displaying tabbed interface and general UI components
 */

/**
 * Display tabbed interface with all toolkit modules
 */
function clean_sweep_display_toolkit_interface($plugin_results = null, $malware_results = null) {
    if (!defined('WP_CLI') || !WP_CLI) {
        // Handle malware results directly if provided
        if ($malware_results !== null) {
            clean_sweep_display_malware_scan_results($malware_results);
            return;
        }

        // Determine which tab should be active based on available results
        $active_tab = $plugin_results !== null ? 'plugins' : 'core';
        $core_active = $active_tab === 'core' ? ' active' : '';
        $plugins_active = $active_tab === 'plugins' ? ' active' : '';
        $upload_active = '';
        $cleanup_active = '';

        echo '<div class="tab-container">';
        echo '<div class="tab-buttons">';
        echo '<button class="tab-button' . $core_active . '" onclick="switchTab(\'core\')">🛡️ Core Files</button>';
        echo '<button class="tab-button' . $plugins_active . '" onclick="switchTab(\'plugins\')">📦 Plugins</button>';
        echo '<button class="tab-button' . $upload_active . '" onclick="switchTab(\'upload\')">📁 Upload & Extract</button>';
        echo '<button class="tab-button" onclick="switchTab(\'security\')">🔒 Security</button>';
        echo '<button class="tab-button' . $cleanup_active . '" onclick="switchTab(\'cleanup\')">🗑️ Cleanup</button>';
        echo '</div>';

        // Core Files Tab
        echo '<div id="core-tab" class="tab-content' . $core_active . '">';
        echo '<h3>🛡️ WordPress Core File Re-installation</h3>';
        echo '<p>Re-install clean WordPress core files. The <code>wp-admin</code> and <code>wp-includes</code> directories will be completely replaced with fresh copies. In the root directory, only official WordPress files will be replaced while preserving your <code>wp-config.php</code>, <code>.htaccess</code>, and <code>wp-content</code> directory.</p>';

        // Version selector - automatically detect latest version
        $version_options = clean_sweep_get_wordpress_version_options();
        if (empty($version_options)) {
            $version_options = ['6.8.3']; // Fallback version if API fails
        }
        echo '<div style="margin:20px 0;">';
        echo '<label for="wp-version" style="font-weight:bold;margin-right:10px;">Select WordPress Version:</label>';
        echo '<select id="wp-version" class="version-select">';
        foreach ($version_options as $version) {
            $selected = ($version === ($version_options[0] ?? '6.8.3')) ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($version) . '"' . $selected . '>' . htmlspecialchars($version) . '</option>';
        }
        echo '</select>';
        echo '<p style="margin:5px 0;font-size:12px;color:#666;">Latest WordPress version is automatically detected and selected.</p>';
        echo '</div>';

        // Safety information
        echo '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:20px 0;">';
        echo '<h4>⚠️ Safety Information</h4>';
        echo '<ul style="margin:10px 0;padding-left:20px;">';
        echo '<li><strong>Preserved:</strong> wp-config.php and /wp-content folder</li>';
        echo '<li><strong>Replaced:</strong> All core WordPress files (wp-admin/, wp-includes/, root files)</li>';
        echo '<li><strong>Database:</strong> Remains unchanged - no database modifications</li>';
        echo '<li><strong>Backup:</strong> Create a full site backup before proceeding</li>';
        echo '</ul>';
        echo '</div>';

        echo '<div style="text-align:center;margin:30px 0;">';
        echo '<button onclick="startCoreReinstall()" style="background:#28a745;color:white;border:none;padding:15px 30px;font-size:18px;font-weight:bold;border-radius:4px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.2);">';
        echo '🛡️ Re-install Core Files';
        echo '</button>';
        echo '</div>';

        // Progress display area
        echo '<div id="core-progress-container" style="display:none;margin:20px 0;">';
        echo '<div class="progress-container">';
        echo '<h3><span id="core-status-indicator" class="status-indicator status-processing">Preparing</span> WordPress Core Re-installation Progress</h3>';
        echo '<div class="progress-bar"><div id="core-progress-fill" class="progress-fill" style="width:0%"></div></div>';
        echo '<div id="core-progress-text" class="progress-text">Initializing...</div>';
        echo '</div>';
        echo '<div id="core-progress-details" style="background:#f8f9fa;border:1px solid #dee2e6;padding:15px;border-radius:4px;margin:10px 0;"></div>';
        echo '</div>';
        echo '</div>';

        // Plugins Tab
        echo '<div id="plugins-tab" class="tab-content' . $plugins_active . '">';

        if ($plugin_results) {
            $repo_count = count($plugin_results['repo_plugins']);
            $skipped_count = count($plugin_results['skipped']);

            echo '<h3>📦 Plugin Analysis Complete</h3>';

            // Stats overview
            echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
            echo '<h4>📊 Analysis Summary</h4>';
            echo '<div>';
            echo '<div class="stats-box" style="background:#d1ecf1;border-color:#bee5eb;"><div class="stats-number" style="color:#0c5460;">' . $repo_count . '</div><div class="stats-label">WordPress.org Plugins</div></div>';
            echo '<div class="stats-box" style="background:#f8d7da;border-color:#f5c6cb;"><div class="stats-number" style="color:#721c24;">' . $skipped_count . '</div><div class="stats-label">Non-Repository</div></div>';
            echo '</div>';
            echo '<p><strong>What will happen:</strong> ' . $repo_count . ' WordPress.org plugins will be re-installed with their latest versions from the official repository.</p>';
            echo '</div>';

            // Plugin lists
            if (!empty($plugin_results['repo_plugins'])) {
                echo '<h4>📦 Plugins to be Re-installed (' . $repo_count . ') <button onclick="copyPluginList(\'reinstall\')" style="background:#007bff;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
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
                foreach ($plugin_results['repo_plugins'] as $slug => $plugin_data) {
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

            // Start button
            echo '<div style="text-align:center;margin:30px 0;">';
            echo '<form method="post" onsubmit="return confirm(\'Are you sure you want to proceed with re-installing ' . $repo_count . ' plugins? This will create a backup and replace all WordPress.org plugins with their latest versions.\');">';
            echo '<input type="hidden" name="action" value="reinstall_plugins">';
            echo '<input type="hidden" name="repo_plugins" value="' . htmlspecialchars(json_encode($plugin_results['repo_plugins'])) . '">';
            echo '<button type="submit" style="background:#dc3545;color:white;border:none;padding:15px 30px;font-size:18px;font-weight:bold;border-radius:4px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.2);">';
            echo '🚀 Start Re-installation Process (' . $repo_count . ' plugins)';
            echo '</button>';
            echo '</form>';
            echo '<p style="margin-top:10px;color:#666;font-size:14px;">This action will download and install the latest versions from WordPress.org</p>';
            echo '</div>';
        } else {
            echo '<h3>📦 Plugin Re-installation</h3>';
            echo '<p>Analyze your installed plugins and re-install clean versions from WordPress.org.</p>';

            echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
            echo '<h4>🔍 How it works:</h4>';
            echo '<ul style="margin:10px 0;padding-left:20px;">';
            echo '<li>Scans all installed plugins</li>';
            echo '<li>Identifies WordPress.org repository plugins</li>';
            echo '<li>Creates backup of current plugins</li>';
            echo '<li>Downloads and installs latest versions</li>';
            echo '<li>Verifies successful installation</li>';
            echo '</ul>';
            echo '</div>';

            echo '<div style="text-align:center;margin:30px 0;">';
            echo '<button onclick="startPluginAnalysis()" style="background:#007bff;color:white;border:none;padding:15px 30px;font-size:18px;font-weight:bold;border-radius:4px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.2);">';
            echo '🔍 Analyze Plugins';
            echo '</button>';
            echo '<p style="margin-top:10px;color:#666;font-size:14px;">Click to start analyzing your plugins</p>';
            echo '</div>';

            // Progress display area
            echo '<div id="plugin-progress-container" style="display:none;margin:20px 0;">';
            echo '<div class="progress-container">';
            echo '<h3><span id="plugin-status-indicator" class="status-indicator status-processing">Analyzing</span> Plugin Analysis Progress</h3>';
            echo '<div class="progress-bar"><div id="plugin-progress-fill" class="progress-fill" style="width:0%"></div></div>';
            echo '<div id="plugin-progress-text" class="progress-text">Analyzing installed plugins...</div>';
            echo '</div>';
            echo '<div id="plugin-progress-details" style="background:#f8f9fa;border:1px solid #dee2e6;padding:15px;border-radius:4px;margin:10px 0;"></div>';
            echo '</div>';
        }

        echo '</div>';

        // Upload & Extract Tab
        echo '<div id="upload-tab" class="tab-content">';
        echo '<h3>📁 Upload & Extract Files</h3>';
        echo '<p>Upload multiple ZIP files and extract them to specific locations on your WordPress site.</p>';

        echo '<form method="post" enctype="multipart/form-data" onsubmit="return validateMultipleUpload()">';
        echo '<input type="hidden" name="action" value="extract_zip">';

        // File input
        echo '<input type="file" id="zip-upload" name="zip_files[]" accept=".zip" multiple onchange="handleMultipleFileUpload(this.files)" style="display:none;">';

        // Upload area
        echo '<div class="upload-area" id="upload-area">';
        echo '<p>📁 Drag & drop ZIP files here, or click to browse</p>';
        echo '<button type="button" class="upload-button" onclick="document.getElementById(\'zip-upload\').click()">Choose ZIP Files</button>';
        echo '<p style="margin:10px 0;font-size:12px;color:#666;">Select multiple files at once for batch processing</p>';
        echo '</div>';

        // File queue display
        echo '<div id="file-queue" class="file-queue" style="display:none;">';
        echo '<h4>📋 Selected Files (<span id="file-count">0</span>)</h4>';
        echo '<div id="file-list" class="file-list"></div>';
        echo '<div style="margin-top:10px;text-align:right;">';
        echo '<button type="button" class="upload-button" onclick="clearFileQueue()" style="background:#6c757d;">Clear All</button>';
        echo '</div>';
        echo '</div>';

        // Destination selector
        echo '<div style="margin:20px 0;">';
        echo '<label for="extract-path" style="font-weight:bold;display:block;margin-bottom:5px;">Extract to:</label>';
        echo '<select name="extract_path" id="extract-path" style="width:100%;padding:10px;border:1px solid #dee2e6;border-radius:4px;font-size:14px;">';
        echo '<option value="wp-content/plugins">📦 wp-content/plugins (Plugin)</option>';
        echo '<option value="wp-content/themes">🎨 wp-content/themes (Theme)</option>';
        echo '<option value="wp-content/uploads">🖼️ wp-content/uploads (Media/Files)</option>';
        echo '<option value="wp-content">📁 wp-content (General)</option>';
        echo '<option value=".">🏠 WordPress Root</option>';
        echo '</select>';
        echo '</div>';

        // Malware removal pre-extraction section
        echo '<div class="malware-removal-section" style="background:#ffe6e6;border:2px solid #dc3545;padding:20px;border-radius:8px;margin:20px 0;box-shadow:0 2px 8px rgba(220,53,69,0.1);">';

        echo '<div style="display:flex;align-items:center;margin-bottom:15px;">';
        echo '<span style="font-size:24px;margin-right:10px;">🛡️</span>';
        echo '<div>';
        echo '<h4 style="margin:0 0 5px 0;color:#721c24;font-size:16px;font-weight:600;">Optional: Pre-Extraction Malware Removal</h4>';
        echo '<p style="margin:0;color:#721c24;font-size:13px;">Delete specific files/folders before ZIP extraction for comprehensive cleanup</p>';
        echo '</div>';
        echo '</div>';

        echo '<div style="margin:15px 0;">';
        echo '<label style="display:flex;align-items:center;cursor:pointer;font-weight:500;">';
        echo '<input type="checkbox" name="enable_malware_removal" value="1" onchange="toggleMalwareRemoval(this)" style="margin-right:10px;width:18px;height:18px;">';
        echo '<span>🗑️ Enable pre-extraction cleanup (advanced malware removal)</span>';
        echo '</label>';
        echo '</div>';

        echo '<div id="malware-targets" style="display:none;margin-top:20px;padding:20px;background:#ffffff;border:2px solid #f8d7da;border-radius:6px;">';

        echo '<div style="display:flex;align-items:center;margin-bottom:15px;">';
        echo '<span style="font-size:20px;margin-right:8px;">🎯</span>';
        echo '<h5 style="margin:0;color:#721c24;font-size:14px;font-weight:600;">Target Paths for Deletion</h5>';
        echo '</div>';

        echo '<div style="margin-bottom:15px;padding:12px;background:#f8f9fa;border-left:4px solid #ffc107;border-radius:4px;">';
        echo '<p style="margin:0;font-size:13px;color:#856404;"><strong>📝 Instructions:</strong> Enter relative paths from the extraction directory. One path per line. Only files/folders within the selected extraction path can be deleted.</p>';
        echo '</div>';

        echo '<textarea name="delete_paths" placeholder="Example for plugin replacement:
defender-security/malware.php
defender-security/backdoor.php
defender-security/hacked-config.php

Example for theme cleanup:
twentytwentyfour-child/evil-script.php
twentytwentyfour-child/suspicious-admin.php" style="width:100%;height:120px;font-family:monospace;font-size:12px;padding:12px;border:2px solid #dee2e6;border-radius:4px;resize:vertical;"></textarea>';

        echo '<div style="margin-top:15px;padding:15px;background:#ffe6e6;border:2px solid #dc3545;border-radius:6px;">';
        echo '<div style="display:flex;align-items:center;margin-bottom:10px;">';
        echo '<span style="font-size:18px;margin-right:8px;">⚠️</span>';
        echo '<h6 style="margin:0;color:#721c24;font-size:14px;font-weight:600;">DANGER ZONE - Critical Safety Warnings</h6>';
        echo '</div>';
        echo '<ul style="margin:0;padding-left:20px;font-size:12px;color:#721c24;line-height:1.5;">';
        echo '<li><strong>🗑️ IRREVERSIBLE:</strong> Listed files/folders will be permanently deleted before extraction</li>';
        echo '<li><strong>🔒 PATH VALIDATION:</strong> Only paths within the extraction directory are processed - others are ignored</li>';
        echo '<li><strong>✅ DOUBLE-CHECK:</strong> Verify all paths are confirmed malware or unwanted files</li>';
        echo '<li><strong>🚫 NO RECOVERY:</strong> Deleted items cannot be restored - ensure backups exist</li>';
        echo '<li><strong>🛡️ SECURITY FIRST:</strong> Paths attempting to access parent directories are automatically blocked</li>';
        echo '</ul>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Safety notice and installer information
        echo '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:20px 0;">';
        echo '<h4>⚠️ Extraction Safety & Smart Installation</h4>';

        echo '<div style="margin:10px 0;padding:10px;background:#e9ecef;border-radius:4px;">';
        echo '<h5 style="margin:0 0 8px 0;color:#495057;">🔧 Intelligent Installation</h5>';
        echo '<ul style="margin:0;padding-left:20px;font-size:13px;">';
        echo '<li><strong>📦 Plugin Directory:</strong> Uses WordPress Plugin Upgrader (safe replacement, activation, dependency checks)</li>';
        echo '<li><strong>🎨 Theme Directory:</strong> Uses WordPress Theme Upgrader (safe replacement, activation)</li>';
        echo '<li><strong>🔄 Existing Packages:</strong> Automatically detects and replaces existing plugins/themes with backups</li>';
        echo '<li><strong>🛡️ Safety:</strong> WordPress handles all security, activation, and rollback scenarios</li>';
        echo '</ul>';
        echo '</div>';

        echo '<ul style="margin:10px 0;padding-left:20px;">';
        echo '<li>Files will be extracted to the selected directory</li>';
        echo '<li>Existing files with the same name will be overwritten</li>';
        echo '<li>Other extraction paths use standard ZIP extraction</li>';
        echo '<li>Plugin/theme files should be extracted to their respective directories for best results</li>';
        echo '</ul>';
        echo '</div>';

        echo '<div style="text-align:center;margin:30px 0;">';
        echo '<button type="submit" style="background:#17a2b8;color:white;border:none;padding:15px 30px;font-size:18px;font-weight:bold;border-radius:4px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.2);">';
        echo '📁 Extract ZIP Files';
        echo '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';

        // Security Tab (Malware Scan + Baseline Management)
        echo '<div id="security-tab" class="tab-content">';
        echo '<h3>🔒 Security Monitoring & Integrity</h3>';
        echo '<p>Comprehensive security tools: malware scanning and integrity baseline management for advanced threat detection.</p>';

        // MALWARE SCANNING SECTION
        echo '<div class="security-malware-section" style="background:#f8f9fa;border:1px solid #dee2e6;padding:25px;border-radius:8px;margin:20px 0;">';
        echo '<h4 style="margin:0 0 20px 0;color:#495057;">🔍 Malware Database & File Scanner</h4>';

        echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
        echo '<h5>🔍 Scan Options</h5>';
        echo '<div style="margin:15px 0;">';
        echo '<h6>🕵️ File Scanning Depth</h6>';
        echo '<div style="margin-left:25px; margin-bottom:25px;">';
        echo '<label><input type="checkbox" id="level-scan-toggle" name="level_scan" value="1"><strong>Deep scan (3 levels deep) - increases scan time and uses more server resources</strong></label><br>';
        echo '<small style="display:block;color:#856404;margin:5px 0 0 20px;">⚠️ <strong>Performance warning:</strong> Scans 3 directory levels instead of 2 (default). Takes significantly longer and uses more CPU/memory resources. Only enable if you need comprehensive file analysis.</small>';
        echo '</div>';
        echo '<h6>⚡ Quick Scan Family</h6>';
        echo '<div style="margin-left:25px; margin-bottom:15px;">';
        echo '<label><input type="radio" name="scan_type" value="all" checked> Complete Scan (30-60 sec - Database + Files)</label><br>';
        echo '<label style="margin-left:20px; display:block;"><input type="radio" name="scan_type" value="database"> Database Only</label><br>';
        echo '<label style="margin-left:20px; display:block;"><input type="radio" name="scan_type" value="files" onchange="toggleFolderInput()"> Files Only</label><br>';
        echo '<div id="folder-input-container" style="margin-left:40px; display:none;">';
        echo '<label>Scan specific file/folder: <input type="text" id="scan_folder" name="scan_folder" placeholder=".htaccess or wp-content/uploads/" style="width:250px;"></label>';
        echo '<small style="display:block;color:#666;margin-top:2px;">Leave empty to scan entire wp-content directory. Supports any path within WordPress root.</small>';
        echo '</div>';
        echo '</div>';
        echo '<h6>🕵️ Deep Scan Family</h6>';
        echo '<div style="margin-left:25px;">';
        echo '<label><input type="radio" name="scan_type" value="deep" style="color:#dc3545;"> Deep Scan (2-5 min - Advanced Investigation)</label><br>';
        echo '</div>';
        echo '</div>';
        echo '<div id="deep-scan-options" style="background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:10px 0;display:none;">';
        echo '<h6>🔥 Advanced Deep Scan Capabilities</h6>';
        echo '<p>This comprehensive scan includes all standard features plus advanced detection methods:</p>';
        echo '<ul style="margin:10px 0;padding-left:20px;">';
        echo '<li><strong>🎯 Enhanced Pattern Matching:</strong> Standard and obfuscated malware signatures</li>';
        echo '<li><strong>🏗️ Complete Metadata Analysis:</strong> All post, user, and comment metadata</li>';
        echo '<li><strong>🕵️ Multi-Encoding Chain Detection:</strong> Decodes base64→gzip→rot13 obfuscated malware</li>';
        echo '<li><strong>💾 Large Content Analysis:</strong> Scans >1MB options and serialized data</li>';
        echo '</ul>';
        echo '<div style="margin-top:15px;padding:10px;background:#fff3cd;border:1px solid #ffeaa7;border-radius:3px;">';
        echo '<h6>⚡ Optional Advanced Checks</h6>';
        echo '<label><input type="checkbox" name="check_mysql_persistence" value="1"> 🔍 Include MySQL Server Persistence Check</label><br>';
        echo '<small style="color:#856404;margin-left:20px;">Rare requirement - scans for database triggers/events. May need elevated permissions.</small>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div style="background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:20px 0;">';
        echo '<h5>⚠️ Scan Information</h5>';
        echo '<ul style="margin:10px 0;padding-left:20px;">';
        echo '<li>Database scan checks post content, comments, and options for known malware patterns</li>';
        echo '<li>File scan examines .php and .js files while skipping cache and upload directories</li>';
        echo '<li>This is a detection-only scan - no automatic changes are made</li>';
        echo '<li>Large sites may take several minutes to scan completely</li>';
        echo '</ul>';
        echo '</div>';

        echo '<div style="text-align:center;margin:20px 0;">';
        echo '<button onclick="startMalwareScan()" style="background:#dc3545;color:white;border:none;padding:12px 25px;font-size:16px;font-weight:bold;border-radius:4px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.2);">';
        echo '🔍 Start Malware Scan';
        echo '</button>';
        echo '</div>';

        // Progress display area
        echo '<div id="malware-progress-container" style="display:none;margin:20px 0;">';
        echo '<div class="progress-container">';
        echo '<h4><span id="malware-status-indicator" class="status-indicator status-processing">Scanning</span> Malware Scan Progress</h4>';
        echo '<div class="progress-bar"><div id="malware-progress-fill" class="progress-fill" style="width:0%"></div></div>';
        echo '<div id="malware-progress-text" class="progress-text">Initializing...</div>';
        echo '</div>';
        echo '<div id="malware-progress-details" style="background:#f8f9fa;border:1px solid #dee2e6;padding:15px;border-radius:4px;margin:10px 0;"></div>';
        echo '</div>';

        echo '</div>'; // End malware section

        // INTEGRITY BASELINE MANAGEMENT SECTION
        echo '<div class="integrity-baseline-section" style="background:#f8f9fa;border:1px solid #dee2e6;padding:25px;border-radius:8px;margin:30px 0;">';
        echo '<h4 style="margin:0 0 20px 0;color:#495057;">🔐 Integrity Baseline Management</h4>';
        echo '<p style="margin:0 0 20px 0;color:#6c757d;font-size:14px;">Advanced monitoring for sites with persistent reinfection. Creates comprehensive file integrity baselines for forensic analysis.</p>';

        // Get comprehensive mode setting from session
        $comprehensive_enabled = isset($_SESSION['clean_sweep_comprehensive_baseline']) && $_SESSION['clean_sweep_comprehensive_baseline'];

        // Comprehensive mode toggle
        echo '<div style="margin-bottom:25px;padding:15px;border-radius:6px;background:#e7f3ff;border:1px solid #b8daff;">';
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">';
        echo '<input type="checkbox" id="enable-comprehensive-baseline" onchange="toggleComprehensiveMode(this.checked)" style="width:18px;height:18px;" ' . ($comprehensive_enabled ? 'checked' : '') . '>';
        echo '<strong style="color:#0c5460;">Enable Comprehensive Integrity Monitoring</strong>';
        echo '</div>';
        echo '<p style="margin:0;font-size:13px;color:#0c5460;">Monitor all WordPress files (core + plugins + themes + uploads) for changes. Use only for sites with persistent reinfection. Requires sufficient disk space and significantly increases processing time.</p>';
        echo '</div>';

        // Baseline status
        $baseline_exists = function_exists('clean_sweep_get_core_baseline') && clean_sweep_get_core_baseline() !== null;
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

        // Warning about comprehensive mode
        echo '<div style="margin-top:15px;padding:12px;background:#fff3cd;border:1px solid #ffeaa7;border-radius:4px;">';
        echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
        echo '<span style="font-size:16px;">⚠️</span>';
        echo '<strong style="color:#856404;">Comprehensive Mode Warning</strong>';
        echo '</div>';
        echo '<p style="margin:0;font-size:13px;color:#856404;">When comprehensive monitoring is enabled, the baseline will monitor <strong>thousands of files</strong> across your entire WordPress installation. This provides maximum security but requires significant disk space and processing time. Only enable for sites with persistent reinfection issues.</p>';
        echo '</div>';

        // Status messages container
        echo '<div id="baseline-status-messages" style="margin-top:15px;"></div>';

        echo '</div>';

        echo '</div>';

        // Cleanup Tab
        echo '<div id="cleanup-tab" class="tab-content">';
        echo '<h3>🗑️ Clean Sweep Cleanup</h3>';
        echo '<p>Remove all Clean Sweep files and directories from your server once you\'re done using the toolkit.</p>';

        echo '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
        echo '<h4>🗑️ What will be deleted:</h4>';
        echo '<ul style="margin:10px 0;padding-left:20px;">';
        echo '<li><strong>Main files:</strong> clean-sweep.php, config.php, utils.php, wordpress-api.php, ui.php, display.php</li>';
        echo '<li><strong>Feature modules:</strong> features/ directory and all PHP files within</li>';
        echo '<li><strong>Backups:</strong> backups/ directory and all backup files</li>';
        echo '<li><strong>Logs:</strong> logs/ directory and all log files</li>';
        echo '<li><strong>Assets:</strong> assets/ directory</li>';
        echo '</ul>';
        echo '<p><strong>What will remain:</strong> Your WordPress installation and all other files will be completely untouched.</p>';
        echo '</div>';

        // Safety warnings
        echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:15px;border-radius:4px;margin:20px 0;">';
        echo '<h4>⚠️ Important Safety Information</h4>';
        echo '<ul style="margin:10px 0;padding-left:20px;">';
        echo '<li>This action will permanently delete the Clean Sweep toolkit</li>';
        echo '<li>The cleanup process cannot be undone</li>';
        echo '<li>Make sure you have completed all necessary cleanup tasks before proceeding</li>';
        echo '<li>If you need Clean Sweep again, you can re-upload it from the original source</li>';
        echo '<li>This will not affect your WordPress site or any other files</li>';
        echo '</ul>';
        echo '</div>';

        echo '<div style="text-align:center;margin:30px 0;">';
        echo '<form method="post" onsubmit="return confirm(\'Are you absolutely sure you want to delete Clean Sweep? This will permanently remove all toolkit files and directories from your server. This action cannot be undone.\');">';
        echo '<input type="hidden" name="action" value="cleanup">';
        echo '<button type="submit" style="background:#dc3545;color:white;border:none;padding:15px 30px;font-size:18px;font-weight:bold;border-radius:4px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.2);">';
        echo '🗑️ Delete Clean Sweep';
        echo '</button>';
        echo '</form>';
        echo '<p style="margin-top:10px;color:#666;font-size:14px;">This will remove the entire Clean Sweep toolkit from your server</p>';
        echo '</div>';
        echo '</div>';

        echo '</div>';

        // Load required CSS file (JS already loaded by assets/script.js in header)
        echo '<link rel="stylesheet" href="assets/css/style.css">';

        // Add CSS for tab interface
        echo '<style>
            .tab-container { margin: 20px 0; }
            .tab-buttons { display: flex; border-bottom: 1px solid #dee2e6; margin-bottom: 20px; }
            .tab-button { background: none; border: none; padding: 12px 20px; cursor: pointer; border-bottom: 3px solid transparent; font-size: 14px; font-weight: 500; }
            .tab-button:hover { background: #f8f9fa; }
            .tab-button.active { border-bottom-color: #007bff; color: #007bff; background: #f8f9fa; }
            .tab-content { display: none; }
            .tab-content.active { display: block; }
            .stats-box { display: inline-block; background: #e9ecef; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; margin: 10px; text-align: center; min-width: 80px; }
            .stats-number { font-size: 24px; font-weight: bold; display: block; }
            .stats-label { font-size: 12px; color: #6c757d; margin-top: 5px; }
            .progress-container { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 20px; margin: 20px 0; }
            .progress-bar { background: #e9ecef; border-radius: 10px; height: 12px; margin: 10px 0; overflow: hidden; }
            .progress-fill { background: #007bff; height: 100%; transition: width 0.3s ease; }
            .progress-text { font-weight: bold; margin: 10px 0; }
            .status-indicator.status-processing::before { content: "⏳ "; }
            .status-indicator.status-completed::before { content: "✅ "; }
        </style>';

        // Auto-refresh recovery_token timestamp on page loads (cache-busting enhancement)
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            // Auto-refresh recovery_token timestamp to prevent caching
            const url = new URL(window.location.href);
            if (url.searchParams.has("recovery_token")) {
                // Update timestamp to current time for cache-busting
                url.searchParams.set("recovery_token", Date.now());
                // Update URL without causing page reload
                window.history.replaceState({}, "", url.toString());
            }
        });
        </script>';
    } else {
        // CLI output
        if ($plugin_results) {
            $repo_count = count($plugin_results['repo_plugins']);
            $skipped_count = count($plugin_results['skipped']);

            echo "\n🔍 PLUGIN ANALYSIS COMPLETE\n";
            echo str_repeat("=", 50) . "\n";
            echo "📦 WordPress.org Plugins: $repo_count\n";
            echo "⏭️ Non-Repository Plugins: $skipped_count\n";
            echo str_repeat("=", 50) . "\n";

            if (!empty($plugin_results['repo_plugins'])) {
                echo "\n📦 PLUGINS TO BE RE-INSTALLED:\n";
                foreach ($plugin_results['repo_plugins'] as $slug => $name) {
                    echo "  • $name ($slug)\n";
                }
            }

            if (!empty($plugin_results['skipped'])) {
                echo "\n⏭️ PLUGINS TO BE SKIPPED:\n";
                foreach ($plugin_results['skipped'] as $plugin) {
                    echo "  • {$plugin['name']} ({$plugin['reason']})\n";
                }
            }

            echo "\n⚠️  SAFETY NOTES:\n";
            echo "  • A complete backup will be created automatically\n";
            echo "  • All plugins will be deactivated after re-installation\n";
            echo "  • Ensure database backups exist before proceeding\n";

            echo "\n🚀 To proceed with re-installation, run this script with POST data or use the web interface.\n";
        } else {
            echo "\n🧹 CLEAN SWEEP - WORDPRESS MALWARE CLEANUP TOOLKIT\n";
            echo str_repeat("=", 60) . "\n";
            echo "Available operations:\n";
            echo "  • Core file re-installation\n";
            echo "  • Plugin re-installation\n";
            echo "  • File upload and extraction\n";
            echo "\nUse the web interface for full functionality.\n";
        }
    }
}
