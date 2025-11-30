/**
 * Clean Sweep - AJAX Functionality v2.0 Enhanced
 * AJAX functions for plugin analysis, core reinstallation, and malware scanning
 * Enhanced with shared hosting timeout prevention
 */

// Global constants for shared hosting optimization
const SHARED_HOSTING_TRIES = 3;           // Retry failed requests 3 times
const SHARED_HOSTING_TIMEOUT = 30000;     // 30 second timeout (shared hosting limits)
const SHARED_HOSTING_HEARTBEAT = 2000;    // Check progress every 2 seconds
const SHARED_HOSTING_RETRY_DELAY = 3000;  // Wait 3 seconds between retries

// Detection for shared hosting environments
const IS_SHARED_HOSTING = window.location.hostname.includes('godaddy') ||
                         window.location.hostname.includes('hostgator') ||
                         window.location.hostname.includes('bluehost') ||
                         window.location.hostname.includes('siteground') ||
                         window.location.hostname.includes('cpanel') ||
                         window.location.hostname.includes('plesk');

// Enhanced fetch with timeout detection and retry logic
async function fetchWithTimeout(url, options = {}, retries = SHARED_HOSTING_TRIES) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort('timeout'), SHARED_HOSTING_TIMEOUT);

    // Enhanced retry logic for shared hosting timeouts
    for (let attempt = 1; attempt <= retries; attempt++) {
        try {
            const response = await fetch(url, {
                ...options,
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            // Check for shared hosting timeout pages
            if (!response.ok) {
                const text = await response.text();

                // Detect shared hosting 504 timeout page
                if (text.includes('HTTP 504') && text.includes('Gateway Timeout') ||
                    text.includes('Connection Timeout') ||
                    text.includes('Backend or gateway connection timeout')) {

                    if (attempt < retries) {
                        console.log(`Shared hosting timeout detected (attempt ${attempt}/${retries}). Retrying in ${SHARED_HOSTING_RETRY_DELAY/1000}s...`);
                        await new Promise(resolve => setTimeout(resolve, SHARED_HOSTING_RETRY_DELAY));
                        continue;
                    }

                    // Final attempt failed
                    if (IS_SHARED_HOSTING) {
                        throw new Error('Shared hosting has strict timeout limits (25-30 seconds). This operation may be too intensive for shared hosting. Consider using smaller batch sizes or upgrading to VPS hosting.');
                    } else {
                        throw new Error(`Server timeout after ${retries} attempts. The operation may be taking too long.`);
                    }
                }

                // Handle other HTTP errors
                if (text.includes('Server returned HTML instead of JSON')) {
                    if (IS_SHARED_HOSTING) {
                        throw new Error('Shared hosting timeout detected. Try reducing the operation scope or contact your hosting provider about PHP execution limits.');
                    }
                    throw new Error('Server error: Request timed out. The operation may be too resource-intensive.');
                }

                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            return response;

        } catch (error) {
            clearTimeout(timeoutId);

            if (error.name === 'AbortError' && error.message === 'timeout') {
                if (attempt < retries) {
                    console.log(`Request timeout (attempt ${attempt}/${retries}). Retrying in ${SHARED_HOSTING_RETRY_DELAY/1000}s...`);
                    await new Promise(resolve => setTimeout(resolve, SHARED_HOSTING_RETRY_DELAY));
                    continue;
                }

                if (IS_SHARED_HOSTING) {
                    throw new Error('Request timed out - shared hosting has strict 30-second limits. Try breaking the operation into smaller batches.');
                }
                throw new Error('Request timed out. The server may be overloaded or the operation is too resource-intensive.');
            }

            // Re-throw other errors after retries are exhausted
            if (attempt === retries) {
                throw error;
            }
        }
    }
}

// Plugin analysis with AJAX progress tracking
let pluginProgressInterval = null;
let pluginProgressFile = null;

function startPluginAnalysis() {
    // Use consistent progress file name for caching analysis results
    pluginProgressFile = 'plugin_analysis_session.progress';

    // Show progress container and hide the button
    document.getElementById("plugin-progress-container").style.display = "block";
    document.querySelector("[onclick='startPluginAnalysis()']").style.display = "none";

    // Start progress polling after a small delay to ensure file is created
    setTimeout(() => {
        pluginProgressInterval = setInterval(pollPluginProgress, 2000);
    }, 500);

    // Submit the request via AJAX
    const formData = new FormData();
    formData.append('action', 'analyze_plugins');
    formData.append('progress_file', pluginProgressFile);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Check if response is JSON
        const contentType = response.headers.get("content-type");
        if (contentType && contentType.indexOf("application/json") !== -1) {
            return response.json();
        } else {
            // Not JSON, probably an error page
            return response.text().then(text => {
                // Show more of the error content to help debug
                const preview = text.substring(0, 500).replace(/\s+/g, ' ').trim();
                throw new Error('Server returned HTML instead of JSON. Error content: ' + preview + (text.length > 500 ? '...' : ''));
            });
        }
    })
    .then(data => {
        // Process completed - stop polling
        clearInterval(pluginProgressInterval);
        pluginProgressInterval = null;

        // Handle new OOP architecture response format
        if (data.success && (data.wp_org_plugins || data.wpmu_dev_plugins || data.non_repo_plugins || data.suspicious_files)) {
            console.log('✅ Analysis completed - updating UI with new format');

            // Generate HTML for analysis results (mimic the PHP display logic)
            const html = generateAnalysisResultsHTML(data);

            // Switch to plugins tab
            switchTab('plugins');

            // Update the plugins tab content with the generated HTML
            const pluginsTab = document.getElementById('plugins-tab');
            if (pluginsTab) {
                pluginsTab.innerHTML = html;
            }

            // Hide the progress container
            const progressContainer = document.getElementById("plugin-progress-container");
            if (progressContainer) {
                progressContainer.style.display = "none";
            }

        } else if (data.success && data.html) {
            // Fallback for old format
            console.log('✅ Analysis completed - using legacy HTML format');

            // Switch to plugins tab
            switchTab('plugins');

            // Update the plugins tab content with the rendered HTML
            const pluginsTab = document.getElementById('plugins-tab');
            if (pluginsTab) {
                pluginsTab.innerHTML = data.html;
            }

            // Hide the progress container
            const progressContainer = document.getElementById("plugin-progress-container");
            if (progressContainer) {
                progressContainer.style.display = "none";
            }
        } else if (data.error) {
            document.getElementById("plugin-progress-details").innerHTML = '<div style="color:#dc3545;">Error: ' + data.error + '</div>';
            document.getElementById("plugin-status-indicator").textContent = "Error";
            document.getElementById("plugin-status-indicator").className = "status-indicator status-completed";
        } else {
            document.getElementById("plugin-progress-details").innerHTML = '<div style="color:#dc3545;">Error: Failed to analyze plugins - unexpected response format</div>';
            document.getElementById("plugin-status-indicator").textContent = "Error";
            document.getElementById("plugin-status-indicator").className = "status-indicator status-completed";
        }
    })
    .catch(error => {
        clearInterval(pluginProgressInterval);
        pluginProgressInterval = null;
        document.getElementById("plugin-progress-details").innerHTML = '<div style="color:#dc3545;">Error: ' + error.message + '</div>';
        document.getElementById("plugin-status-indicator").textContent = "Error";
        document.getElementById("plugin-status-indicator").className = "status-indicator status-completed";
    });
}

function pollPluginProgress() {
    if (!pluginProgressFile) return;

    // Progress files are stored in logs directory for web-accessibility
    fetch('logs/' + pluginProgressFile + '?t=' + Date.now())
        .then(response => {
            if (response.status === 404) {
                // Progress file doesn't exist yet - silently continue polling
                console.log('Waiting for progress file creation...');
                return null;
            }
            if (response.ok) {
                return response.text();
            }
            return null;
        })
        .then(text => {
            if (text) {
                try {
                    const data = JSON.parse(text);
                    updatePluginProgress(data);
                } catch (e) {
                    // JSON parsing failed, file might not be complete yet
                }
            }
        })
        .catch(error => {
            // Only log real network errors, not expected 404s
            if (!error.message.includes('404')) {
                console.error('Progress polling network error:', error);
            }
        });
}

function updatePluginProgress(data) {
    const statusIndicator = document.getElementById("plugin-status-indicator");
    const progressFill = document.getElementById("plugin-progress-fill");
    const progressText = document.getElementById("plugin-progress-text");
    const progressDetails = document.getElementById("plugin-progress-details");

    if (data.status) {
        statusIndicator.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
    }

    if (data.progress !== undefined) {
        progressFill.style.width = data.progress + "%";
    }

    if (data.message) {
        progressText.textContent = data.message;
    }

    if (data.details) {
        progressDetails.innerHTML = data.details;
    }

    // Update status indicator class
    if (data.status === 'complete' || data.status === 'error') {
        statusIndicator.className = "status-indicator status-completed";
        // Stop polling when complete
        clearInterval(pluginProgressInterval);
        pluginProgressInterval = null;
    }
}

// Core reinstallation with AJAX progress tracking
let coreProgressInterval = null;
let coreProgressFile = null;

function startCoreReinstall() {
    const version = document.getElementById("wp-version").value;
    if (!confirm("Are you sure you want to re-install WordPress core files? This will replace all core files while preserving wp-config.php and /wp-content. Make sure you have a backup!")) {
        return;
    }

    // Generate unique progress file name
    coreProgressFile = 'core_progress_' + Date.now() + '.progress';

    // Show progress container and hide the button
    document.getElementById("core-progress-container").style.display = "block";
    document.querySelector("[onclick='startCoreReinstall()']").style.display = "none";

    // Start progress polling
    coreProgressInterval = setInterval(pollCoreProgress, 2000);

    // Submit the request via AJAX
    const formData = new FormData();
    formData.append('action', 'reinstall_core');
    formData.append('wp_version', version);
    formData.append('progress_file', coreProgressFile);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Process completed - stop polling
        clearInterval(coreProgressInterval);
        
        // Poll for final status
        setTimeout(() => {
            pollCoreProgress();
        }, 500);
    })
    .catch(error => {
        clearInterval(coreProgressInterval);
        document.getElementById("core-progress-details").innerHTML = '<div style="color:#dc3545;">Error: ' + error.message + '</div>';
        document.getElementById("core-status-indicator").textContent = "Error";
        document.getElementById("core-status-indicator").className = "status-indicator status-completed";
    });
}

function pollCoreProgress() {
    if (!coreProgressFile) return;

    // Progress files are stored in logs directory for web-accessibility
    fetch('logs/' + coreProgressFile + '?t=' + Date.now())
        .then(response => {
            if (response.status === 404) {
                // Progress file doesn't exist yet - silently continue polling
                console.log('Waiting for progress file creation...');
                return null;
            }
            if (response.ok) {
                return response.text();
            }
            return null;
        })
        .then(text => {
            if (text) {
                try {
                    const data = JSON.parse(text);
                    updateCoreProgress(data);
                } catch (e) {
                    // JSON parsing failed, file might not be complete yet
                }
            }
        })
        .catch(error => {
            // Only log real network errors, not expected 404s
            if (!error.message.includes('404')) {
                console.error('Progress polling network error:', error);
            }
        });
}

function updateCoreProgress(data) {
    const statusIndicator = document.getElementById("core-status-indicator");
    const progressFill = document.getElementById("core-progress-fill");
    const progressText = document.getElementById("core-progress-text");
    const progressDetails = document.getElementById("core-progress-details");

    // Special handling for disk space warnings and backup choice
    if (data.status === 'disk_space_warning' || data.status === 'backup_choice' || (data.disk_check && data.disk_check.show_choice)) {
        console.log('💾 Core reinstall: Showing backup choice UI');

        if (statusIndicator) {
            statusIndicator.textContent = data.disk_check && data.disk_check.space_status === 'insufficient' ? "Warning" : "Ready";
            statusIndicator.className = "status-indicator status-paused";
        }

        if (progressText) {
            progressText.textContent = data.disk_check && data.disk_check.space_status === 'insufficient' ?
                "Backup space insufficient" : "Choose backup option";
        }

        if (progressDetails && data.disk_check) {
            const diskCheck = data.disk_check;
            const isInsufficient = diskCheck.space_status === 'insufficient';

            progressDetails.innerHTML = `
                <div style="color:#856404;background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:10px 0;">
                    <h4 style="margin-top:0;">💾 Core Reinstallation - Backup Options</h4>
                    <p style="margin-bottom:10px;"><strong>Estimated backup size: ${diskCheck.backup_size_mb}MB</strong></p>
                    <div style="background:#f8f9fa;padding:10px;border-radius:3px;margin:10px 0;">
                        <p style="margin:5px 0;"><strong>Backup Size:</strong> ${diskCheck.backup_size_mb}MB</p>
                        <p style="margin:5px 0;"><strong>With Buffer:</strong> ${diskCheck.required_mb}MB</p>
                        <p style="margin:5px 0;"><strong>Available Space:</strong> ${diskCheck.available_mb}MB</p>
                        ${diskCheck.shortfall_mb ? `<p style="margin:5px 0;color:#dc3545;"><strong>Shortfall:</strong> ${diskCheck.shortfall_mb}MB</p>` : ''}
                    </div>
                    ${isInsufficient ? `
                        <p style="color:#dc3545;font-size:14px;margin:10px 0;">
                            <strong>⚠️ Warning:</strong> ${diskCheck.warning}
                        </p>
                    ` : `
                        <p style="color:#28a745;font-size:14px;margin:10px 0;">
                            <strong>✅ Status:</strong> ${diskCheck.message}
                        </p>
                    `}
                    <div style="margin-top:15px;">
                        <button onclick="proceedCoreReinstallWithBackup()" style="background:#28a745;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;margin-right:10px;">
                            Create Backup (~${diskCheck.backup_size_mb}MB)
                        </button>
                        <button onclick="proceedCoreReinstallWithoutBackup()" style="background:#ffc107;color:black;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;margin-right:10px;">
                            Skip Backup (Faster)
                        </button>
                        <button onclick="cancelCoreReinstall()" style="background:#dc3545;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            `;
        }

        // Stop polling while waiting for user decision
        clearInterval(coreProgressInterval);
        coreProgressInterval = null;
        return;
    }

    if (data.status) {
        statusIndicator.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
    }

    if (data.progress !== undefined) {
        progressFill.style.width = data.progress + "%";
    }

    if (data.message) {
        progressText.textContent = data.message;
    }

    if (data.details) {
        progressDetails.innerHTML = data.details;
    }

    // Update status indicator class
    if (data.status === 'complete' || data.status === 'error') {
        statusIndicator.className = "status-indicator status-completed";
        // Stop polling when complete
        clearInterval(coreProgressInterval);
        coreProgressInterval = null;
    }
}

// Handle proceeding with core reinstallation with backup
function proceedCoreReinstallWithBackup() {
    const progressDetails = document.getElementById("core-progress-details");
    const progressText = document.getElementById("core-progress-text");

    if (progressDetails) {
        progressDetails.innerHTML = '<div style="color:#28a745;">⏳ Proceeding with core reinstallation with backup...</div>';
    }
    if (progressText) {
        progressText.textContent = "Creating backup and proceeding";
    }

    // Submit request to continue with backup
    const formData = new FormData();
    formData.append('action', 'reinstall_core');
    formData.append('wp_version', document.getElementById("wp-version").value);
    formData.append('progress_file', coreProgressFile);
    formData.append('create_backup', '1'); // Explicitly request backup

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            // Resume polling to track progress
            coreProgressInterval = setInterval(pollCoreProgress, 2000);
        } else {
            if (progressDetails) {
                progressDetails.innerHTML = '<div style="color:#dc3545;">Error: Failed to proceed with backup</div>';
            }
        }
    })
    .catch(error => {
        if (progressDetails) {
            progressDetails.innerHTML = '<div style="color:#dc3545;">Error: ' + error.message + '</div>';
        }
    });
}

// Handle proceeding with core reinstallation without backup
function proceedCoreReinstallWithoutBackup() {
    const progressDetails = document.getElementById("core-progress-details");
    const progressText = document.getElementById("core-progress-text");

    if (progressDetails) {
        progressDetails.innerHTML = '<div style="color:#28a745;">⏳ Proceeding with core reinstallation without backup as requested...</div>';
    }
    if (progressText) {
        progressText.textContent = "Continuing without backup";
    }

    // Submit request to continue without backup
    const formData = new FormData();
    formData.append('action', 'reinstall_core');
    formData.append('wp_version', document.getElementById("wp-version").value);
    formData.append('progress_file', coreProgressFile);
    formData.append('proceed_without_backup', '1');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            // Resume polling to track progress
            coreProgressInterval = setInterval(pollCoreProgress, 2000);
        } else {
            if (progressDetails) {
                progressDetails.innerHTML = '<div style="color:#dc3545;">Error: Failed to proceed without backup</div>';
            }
        }
    })
    .catch(error => {
        if (progressDetails) {
            progressDetails.innerHTML = '<div style="color:#dc3545;">Error: ' + error.message + '</div>';
        }
    });
}

// Handle canceling core reinstallation
function cancelCoreReinstall() {
    const progressDetails = document.getElementById("core-progress-details");
    const progressText = document.getElementById("core-progress-text");
    const statusIndicator = document.getElementById("core-status-indicator");

    if (progressDetails) {
        progressDetails.innerHTML = '<div style="color:#dc3545;">❌ Core reinstallation cancelled by user</div>';
    }
    if (progressText) {
        progressText.textContent = "Operation cancelled";
    }
    if (statusIndicator) {
        statusIndicator.textContent = "Cancelled";
        statusIndicator.className = "status-indicator status-completed";
    }

    // Stop polling
    clearInterval(coreProgressInterval);
    coreProgressInterval = null;
}

// Generate HTML for analysis results (mimics PHP display logic)
function generateAnalysisResultsHTML(data) {
    let html = '';

    // Analysis summary
    const totals = data.totals || {};
    html += '<h3>📦 Plugin Analysis Complete</h3>';

    html += '<div style="background:#e7f3ff;border:1px solid #b8daff;padding:20px;border-radius:4px;margin:20px 0;">';
    html += '<h4>📊 Analysis Summary</h4>';
    html += '<div>';
    html += '<div class="stats-box" style="background:#d1ecf1;border-color:#bee5eb;"><div class="stats-number" style="color:#0c5460;">' + (totals.wordpress_org || 0) + '</div><div class="stats-label">WordPress.org Plugins</div></div>';
    html += '<div class="stats-box" style="background:#d4edda;border-color:#c3e6cb;"><div class="stats-number" style="color:#155724;">' + (totals.wpmu_dev || 0) + '</div><div class="stats-label">WPMU DEV Plugins</div></div>';
    html += '<div class="stats-box" style="background:#f8d7da;border-color:#f5c6cb;"><div class="stats-number" style="color:#721c24;">' + (totals.non_repository || 0) + '</div><div class="stats-label">Non-Repository</div></div>';
    if (totals.suspicious > 0) {
        html += '<div class="stats-box" style="background:#fff3cd;border-color:#ffeaa7;"><div class="stats-number" style="color:#856404;">' + totals.suspicious + '</div><div class="stats-label">Suspicious Files</div></div>';
    }
    html += '</div>';
    html += '<p><strong>What will happen:</strong> ' + (totals.wordpress_org || 0) + ' WordPress.org plugins and ' + (totals.wpmu_dev || 0) + ' WPMU DEV plugins will be re-installed with their latest versions from official repositories.</p>';
    html += '</div>';

    // Plugin lists
    const allPlugins = [];

    // WordPress.org plugins (ACTIONABLE - will be reinstalled)
    if (data.wp_org_plugins && Object.keys(data.wp_org_plugins).length > 0) {
        html += '<h4 style="color:#28a745;">📦 WordPress.org Plugins to be Re-installed (' + Object.keys(data.wp_org_plugins).length + ') <button onclick="copyPluginList(\'wordpress_org\')" style="background:#007bff;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
        html += '<div style="background:#d4edda;padding:15px;border-radius:4px;border:2px solid #28a745;margin:10px 0;max-height:400px;overflow-y:auto;">';
        html += '<div style="margin-bottom:10px;color:#155724;"><strong>✅ Will be automatically reinstalled with latest versions from WordPress.org</strong></div>';
        html += generatePluginTable(data.wp_org_plugins, 'wordpress_org');
        html += '</div>';
        Object.assign(allPlugins, data.wp_org_plugins);
    }

    // WPMU DEV plugins (ACTIONABLE - will be reinstalled, except dashboard)
    if (data.wpmu_dev_plugins && Object.keys(data.wpmu_dev_plugins).length > 0) {
        // Separate dashboard from other plugins
        const dashboardPlugins = {};
        const regularWpmuDevPlugins = {};

        for (const [slug, plugin] of Object.entries(data.wpmu_dev_plugins)) {
            const pid = plugin.wdp_id || plugin.pid || null;
            if (parseInt(pid) === 119) {
                dashboardPlugins[slug] = plugin;
            } else {
                regularWpmuDevPlugins[slug] = plugin;
            }
        }

        const regularCount = Object.keys(regularWpmuDevPlugins).length;
        const dashboardCount = Object.keys(dashboardPlugins).length;
        const totalWpmuDev = Object.keys(data.wpmu_dev_plugins).length;

        if (regularCount > 0) {
            html += '<h4 style="color:#7c3aed;">💎 WPMU DEV Premium Plugins to be Re-installed (' + regularCount + ' of ' + totalWpmuDev + ' detected) <button onclick="copyPluginList(\'wpmu_dev_regular\')" style="background:#7c3aed;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
            html += '<div style="background:#e9d5ff;padding:15px;border-radius:4px;border:2px solid #7c3aed;margin:10px 0;max-height:400px;overflow-y:auto;">';
            html += '<div style="margin-bottom:10px;color:#4c2889;"><strong>✅ Will be automatically reinstalled with latest versions from WPMU DEV</strong></div>';
            html += generatePluginTable(regularWpmuDevPlugins, 'wpmu_dev');
            html += '</div>';
            Object.assign(allPlugins, regularWpmuDevPlugins);
        }

        if (dashboardCount > 0) {
            html += '<h4 style="color:#6c757d;">🔄 WPMU DEV Dashboard (' + dashboardCount + ')</h4>';
            html += '<div style="background:#f8f9fa;padding:15px;border-radius:4px;border:2px solid #6c757d;margin:10px 0;max-height:200px;overflow-y:auto;">';
            html += '<div style="margin-bottom:10px;color:#495057;"><strong>⏭️ Will be skipped - required for WPMU DEV network operation</strong></div>';
            html += generatePluginTable(dashboardPlugins, 'wpmu_dev_dashboard');
            html += '</div>';
        }
    }

    // Non-repository plugins (INFORMATIONAL - will NOT be reinstalled)
    if (data.non_repo_plugins && Object.keys(data.non_repo_plugins).length > 0) {
        html += '<h4 style="color:#6c757d;">🚫 Non-Repository Plugins (' + Object.keys(data.non_repo_plugins).length + ') <button onclick="copyPluginList(\'non_repository\')" style="background:#6c757d;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
        html += '<div style="background:#f8f9fa;padding:15px;border-radius:4px;border:2px solid #6c757d;margin:10px 0;max-height:150px;overflow-y:auto;">';
        html += '<div style="margin-bottom:10px;color:#495057;"><strong>ℹ️ Informational only - these custom plugins will not be modified</strong></div>';
        html += '<ul style="margin:0;padding-left:20px;">';
        for (const [slug, plugin] of Object.entries(data.non_repo_plugins)) {
            html += '<li style="margin:5px 0;"><strong>' + (plugin.name || slug) + '</strong> - ' + (plugin.reason || 'Custom plugin') + '</li>';
        }
        html += '</ul>';
        html += '</div>';
    }

    // Suspicious files (INFORMATIONAL - will NOT be reinstalled)
    if (data.suspicious_files && data.suspicious_files.length > 0) {
        html += '<h4 style="color:#dc3545;">⚠️ Suspicious Files & Folders (' + data.suspicious_files.length + ') <button onclick="copyPluginList(\'suspicious\')" style="background:#dc3545;color:white;border:none;padding:4px 8px;border-radius:3px;cursor:pointer;font-size:12px;">Copy</button></h4>';
        html += '<div style="background:#f8d7da;padding:15px;border-radius:4px;border:2px solid #dc3545;margin:10px 0;max-height:200px;overflow-y:auto;">';
        html += '<div style="margin-bottom:10px;color:#721c24;"><strong>⚠️ Security Warning:</strong> These files/folders don\'t belong to any recognized plugins and may be malware or unauthorized content. <strong>They will be automatically deleted during reinstallation.</strong> Please verify these are not legitimate files before proceeding.</div>';
        html += '<ul style="margin:0;padding-left:20px;">';
        for (const file of data.suspicious_files) {
            const type = file.is_directory ? 'Directory' : 'File';
            const size = file.size_mb + ' MB';
            const count = file.is_directory ? ' (' + file.file_count + ' files)' : '';
            html += '<li style="margin:5px 0;"><strong>' + file.name + '</strong> - ' + type + ' - ' + size + count + '</li>';
        }
        html += '</ul>';
        html += '</div>';
    }

    // Safety warnings
    html += '<div style="background:#f8d7da;border:1px solid #f5c6cb;padding:15px;border-radius:4px;margin:20px 0;">';
    html += '<h4>⚠️ Important Safety Information</h4>';
    html += '<ul style="margin:10px 0;padding-left:20px;">';
    html += '<li>A complete backup of your current plugins will be created automatically</li>';
    html += '<li>Hello Dolly (demo plugin) will be automatically removed if present</li>';
    html += '<li>This process cannot be undone - review the list above carefully</li>';
    html += '<li>Ensure you have database backups before proceeding</li>';
    if (data.suspicious_files && data.suspicious_files.length > 0) {
        html += '<li><strong>Suspicious files will be deleted</strong> - ensure they are not legitimate before proceeding</li>';
    }
    html += '</ul>';
    html += '</div>';

    // Re-install button - exclude dashboard from count
    const wordpressOrgCount = totals.wordpress_org || 0;
    const wpmuDevTotal = totals.wpmu_dev || 0;
    const dashboardCount = data.wpmu_dev_plugins ? Object.values(data.wpmu_dev_plugins).filter(p => parseInt(p.wdp_id || p.pid) === 119).length : 0;
    const wpmuDevToReinstall = wpmuDevTotal - dashboardCount;
    const totalToReinstall = wordpressOrgCount + wpmuDevToReinstall;

    html += '<div style="text-align:center;margin:30px 0;">';
    const escapedPluginsData = JSON.stringify(allPlugins).replace(/"/g, '"');
    html += '<button onclick="startPluginReinstallation(this)" data-plugins="' + escapedPluginsData + '" style="background:#dc3545;color:white;border:none;padding:15px 30px;font-size:18px;font-weight:bold;border-radius:4px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.2);">';
    html += '🚀 Start Complete Ecosystem Re-installation (' + totalToReinstall + ' plugins)';
    html += '</button>';
    html += '<p style="margin-top:10px;color:#666;font-size:14px;">This action will download and install the latest versions from official repositories</p>';
    html += '</div>';

    return html;
}

// Generate plugin table HTML
function generatePluginTable(plugins, type) {
    let html = '<table class="plugin-analysis-table" style="width:100%;border-collapse:collapse;">';
    html += '<thead>';
    html += '<tr style="background:#f8f9fa;border-bottom:2px solid #dee2e6;">';
    html += '<th style="padding:10px;text-align:left;border-right:1px solid #dee2e6;">Plugin Name</th>';
    html += '<th style="padding:10px;text-align:left;border-right:1px solid #dee2e6;">Current Version</th>';
    if (type === 'wordpress_org') {
        html += '<th style="padding:10px;text-align:left;border-right:1px solid #dee2e6;">Last Updated</th>';
        html += '<th style="padding:10px;text-align:left;">Plugin Page</th>';
    }
    html += '</tr>';
    html += '</thead>';
    html += '<tbody>';

    for (const [slug, plugin] of Object.entries(plugins)) {
        const name = plugin.name || slug;
        const version = plugin.version || 'Unknown';
        const bgColor = type === 'wpmu_dev' ? '#f8f9ff' : 'white';

        html += '<tr style="border-bottom:1px solid #dee2e6;background:' + bgColor + ';">';
        html += '<td style="padding:10px;border-right:1px solid #dee2e6;"><strong>' + name + '</strong><br><small style="color:#666;">(' + slug + ')</small></td>';
        html += '<td style="padding:10px;border-right:1px solid #dee2e6;">' + version + '</td>';

        if (type === 'wordpress_org') {
            const lastUpdated = plugin.last_updated ? formatRelativeTime(plugin.last_updated) : 'Unknown';
            const pluginUrl = plugin.plugin_url || 'https://wordpress.org/plugins/' + slug;
            html += '<td style="padding:10px;border-right:1px solid #dee2e6;">' + lastUpdated + '</td>';
            html += '<td style="padding:10px;"><a href="' + pluginUrl + '" target="_blank" style="color:#007bff;text-decoration:none;">View Plugin →</a></td>';
        }

        html += '</tr>';
    }

    html += '</tbody>';
    html += '</table>';
    return html;
}

// Format relative time (simplified version)
function formatRelativeTime(timestamp) {
    if (!timestamp) return 'Unknown';

    const now = new Date();
    const date = new Date(timestamp * 1000);
    const diffMs = now - date;
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 30) return diffDays + ' days ago';
    if (diffDays < 365) return Math.floor(diffDays / 30) + ' months ago';
    return Math.floor(diffDays / 365) + ' years ago';
}

// Legacy core reinstallation function (kept for compatibility)
function reinstallCore() {
    const version = document.getElementById("wp-version").value;
    if (confirm("Are you sure you want to re-install WordPress core files? This will replace all core files while preserving wp-config.php and /wp-content. Make sure you have a backup!")) {
        // Create form and submit
        const form = document.createElement("form");
        form.method = "post";
        form.style.display = "none";

        const actionInput = document.createElement("input");
        actionInput.type = "hidden";
        actionInput.name = "action";
        actionInput.value = "reinstall_core";
        form.appendChild(actionInput);

        const versionInput = document.createElement("input");
        versionInput.type = "hidden";
        versionInput.name = "wp_version";
        versionInput.value = version;
        form.appendChild(versionInput);

        document.body.appendChild(form);
        form.submit();
    }
}
