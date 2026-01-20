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
    // Generate unique progress file name
    pluginProgressFile = 'plugin_progress_' + Date.now() + '.progress';

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

        // Show results
        if (data.success && data.html) {
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
            document.getElementById("plugin-progress-details").innerHTML = '<div style="color:#dc3545;">Error: Failed to analyze plugins</div>';
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

    // Generate unique progress file name
    coreProgressFile = 'core_progress_' + Date.now() + '.progress';

    // Show progress container and hide the button
    document.getElementById("core-progress-container").style.display = "block";
    document.querySelector("[onclick='startCoreReinstall()']").style.display = "none";

    // DO NOT start polling here - wait for backup choice first
    // Progress polling will start in proceedCoreReinstallWithBackup() or proceedCoreReinstallWithoutBackup()

    // First, check disk space and get backup choice
    const formData = new FormData();
    formData.append('action', 'reinstall_core');
    formData.append('wp_version', version);
    formData.append('progress_file', coreProgressFile);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            // Disk space check response will be handled by updateCoreProgress
            // Polling will start after user makes backup choice
        } else {
            document.getElementById("core-progress-details").innerHTML = '<div style="color:#dc3545;">Error: Failed to start core reinstallation</div>';
            document.getElementById("core-status-indicator").textContent = "Error";
            document.getElementById("core-status-indicator").className = "status-indicator status-completed";
        }
    })
    .catch(error => {
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
    if (data.status === 'disk_space_warning' || data.status === 'disk_space_error' || data.status === 'backup_choice' || (data.disk_check && data.disk_check.show_choice)) {
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

            // Check if disk space information is estimated/unavailable
            const diskSpaceUnavailable = diskCheck.available_mb && diskCheck.available_mb < 200 && diskCheck.total_mb === null;

            progressDetails.innerHTML = `
                <div style="color:#856404;background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:10px 0;">
                    <h4 style="margin-top:0;">💾 Core Reinstallation - Backup Options</h4>
                    <p style="margin-bottom:10px;"><strong>Estimated backup size: ${diskCheck.backup_size_mb}MB</strong></p>
                    <div style="background:#f8f9fa;padding:10px;border-radius:3px;margin:10px 0;">
                        <p style="margin:5px 0;"><strong>Backup Size:</strong> ${diskCheck.backup_size_mb}MB</p>
                        <p style="margin:5px 0;"><strong>With Buffer:</strong> ${diskCheck.required_mb}MB</p>
                        <p style="margin:5px 0;"><strong>Available Space:</strong> ${diskCheck.available_mb}MB ${diskSpaceUnavailable ? '(estimated)' : ''}</p>
                        ${diskCheck.shortfall_mb ? `<p style="margin:5px 0;color:#dc3545;"><strong>Shortfall:</strong> ${diskCheck.shortfall_mb}MB</p>` : ''}
                    </div>
                    ${diskSpaceUnavailable ? `
                        <div style="background:#ffe6e6;border:1px solid #dc3545;padding:10px;border-radius:4px;margin:10px 0;">
                            <p style="color:#721c24;margin:0;font-size:14px;">
                                <strong>ℹ️ Disk Space Monitoring Unavailable:</strong> Your hosting provider has disabled disk space functions for security. Using estimated values. Backup creation is still recommended for safety.
                            </p>
                        </div>
                    ` : ''}
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
                        <button onclick="proceedCoreReinstallWithBackup()" ${isInsufficient ? 'disabled style="background:#6c757d;color:#ffffff;border:none;padding:8px 16px;border-radius:4px;cursor:not-allowed;opacity:0.5;margin-right:10px;" title="Cannot create backup - insufficient disk space"' : 'style="background:#28a745;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;margin-right:10px;"'}>
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

// Manual refresh of plugin analysis
function refreshPluginAnalysis() {
    if (!confirm("This will force a fresh analysis of all plugins, ignoring any cached results. Continue?")) {
        return;
    }

    // Generate unique progress file name for refresh
    const refreshProgressFile = 'plugin_refresh_' + Date.now() + '.progress';

    // Show loading state on the refresh button
    const refreshButton = event.target;
    const originalText = refreshButton.textContent;
    refreshButton.textContent = '🔄 Refreshing...';
    refreshButton.disabled = true;

    // Submit the refresh request
    const formData = new FormData();
    formData.append('action', 'analyze_plugins');
    formData.append('progress_file', refreshProgressFile);

    // Force refresh by adding a parameter
    fetch(window.location.href + '?force_refresh=1&t=' + Date.now(), {
        method: 'POST',
        body: formData
    })
    .then(response => {
        const contentType = response.headers.get("content-type");
        if (contentType && contentType.indexOf("application/json") !== -1) {
            return response.json();
        } else {
            return response.text().then(text => {
                const preview = text.substring(0, 500).replace(/\s+/g, ' ').trim();
                throw new Error('Server returned HTML instead of JSON. Error content: ' + preview + (text.length > 500 ? '...' : ''));
            });
        }
    })
    .then(data => {
        if (data.success && data.html) {
            // Update the plugins tab content with fresh analysis
            const pluginsTab = document.getElementById('plugins-tab');
            if (pluginsTab) {
                pluginsTab.innerHTML = data.html;
            }

            // Show success message briefly
            refreshButton.textContent = '✅ Refreshed!';
            setTimeout(() => {
                refreshButton.textContent = originalText;
                refreshButton.disabled = false;
            }, 2000);
        } else {
            throw new Error(data.error || 'Refresh failed');
        }
    })
    .catch(error => {
        refreshButton.textContent = '❌ Error';
        refreshButton.disabled = false;
        setTimeout(() => {
            refreshButton.textContent = originalText;
            refreshButton.disabled = false;
        }, 3000);
        console.error('Refresh error:', error);
        alert('Refresh failed: ' + error.message);
    });
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
