// Clean Sweep - Plugin Reinstallation
// AJAX functionality for plugin reinstallation with progress tracking

// Plugin reinstallation with AJAX progress tracking
let reinstallProgressInterval = null;
let reinstallProgressFile = null;

// Helper function to handle confirm dialog and button passing
function confirmPluginReinstallation(button) {
    if (confirm('Are you sure you want to proceed with re-installing the plugins? This will create a backup and replace all WordPress.org plugins with their latest versions.')) {
        startPluginReinstallation(button);
    }
}

function startPluginReinstallation(buttonElement) {
    if (!buttonElement) {
        console.error('Button element is null');
        return;
    }

    // Get plugin data from the button's data attribute
    const repoPluginsJson = buttonElement.getAttribute('data-plugins');

    // Generate unique progress file name
    reinstallProgressFile = 'reinstall_progress_' + Date.now() + '.progress';

    // Hide analysis content and button
    const pluginsTab = document.getElementById('plugins-tab');
    if (pluginsTab) {
        // Remove all children except the progress container
        const children = Array.from(pluginsTab.children);
        children.forEach(child => {
            if (child.id !== 'plugin-progress-container') {
                child.style.display = 'none';
            }
        });
    }

    // Show progress container at top
    const progressContainer = document.getElementById("plugin-progress-container");
    if (progressContainer) {
        progressContainer.style.display = "block";
        if (pluginsTab) {
            pluginsTab.insertBefore(progressContainer, pluginsTab.firstChild);
        }
    } else {
        console.error('Progress container not found');
    }

    // Start batch processing first, then start polling with a delay to allow file creation
    processPluginBatch(repoPluginsJson, 0, 5);

    // Start progress polling after a small delay to ensure file is created
    setTimeout(() => {
        reinstallProgressInterval = setInterval(pollReinstallProgress, 2000);
    }, 500);
}

function processPluginBatch(repoPluginsJson, batchStart, batchSize) {
    // Submit the request via AJAX for this batch
    const formData = new FormData();
    formData.append('action', 'reinstall_plugins');
    formData.append('repo_plugins', repoPluginsJson);
    formData.append('progress_file', reinstallProgressFile);
    formData.append('batch_start', batchStart);
    formData.append('batch_size', batchSize);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text.trim());
            return data;
        } catch (e) {
            const preview = text.substring(0, 500).replace(/\s+/g, ' ').trim();
            throw new Error('Failed to parse JSON. Content: ' + preview + (text.length > 500 ? '...' : ''));
        }
    })
    .then(data => {
        if (data.success) {
            // Check if there are more batches to process
            const batchInfo = data.batch_info || {};
            if (batchInfo.has_more_batches && batchInfo.next_batch_start !== null) {
                // Process next batch
                console.log(`Processed batch ${batchStart}-${batchStart + batchSize - 1}, continuing with next batch...`);
                setTimeout(() => {
                    processPluginBatch(repoPluginsJson, batchInfo.next_batch_start, batchSize);
                }, 1000); // Small delay between batches
            } else {
                // All batches completed - stop polling and show results
                clearInterval(reinstallProgressInterval);
                reinstallProgressInterval = null;

                // Show final results
                if (data.html) {
                    // Switch to plugins tab (in case we're not already there)
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
                }
            }
        } else if (data.error) {
            // Error occurred - stop processing
            clearInterval(reinstallProgressInterval);
            reinstallProgressInterval = null;

            const detailsEl = document.getElementById("plugin-progress-details");
            const statusEl = document.getElementById("plugin-status-indicator");
            if (detailsEl) detailsEl.innerHTML = '<div style="color:#dc3545;">Error: ' + data.error + '</div>';
            if (statusEl) {
                statusEl.textContent = "Error";
                statusEl.className = "status-indicator status-completed";
            }
        }
    })
    .catch(error => {
        clearInterval(reinstallProgressInterval);
        reinstallProgressInterval = null;
        const detailsEl = document.getElementById("plugin-progress-details");
        const statusEl = document.getElementById("plugin-status-indicator");
        if (detailsEl) detailsEl.innerHTML = '<div style="color:#dc3545;">Error: ' + error.message + '</div>';
        if (statusEl) {
            statusEl.textContent = "Error";
            statusEl.className = "status-indicator status-completed";
        }
    });
}

function pollReinstallProgress() {
    if (!reinstallProgressFile) return;

    // Progress files are stored in logs directory for web-accessibility
    fetch('logs/' + reinstallProgressFile + '?t=' + Date.now())
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
                    updateReinstallProgress(data);
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

function updateReinstallProgress(data) {
    const statusIndicator = document.getElementById("plugin-status-indicator");
    const progressFill = document.getElementById("plugin-progress-fill");
    const progressText = document.getElementById("plugin-progress-text");
    const progressDetails = document.getElementById("plugin-progress-details");

    // Special handling for disk space warnings
    if (data.status === 'disk_space_warning') {
        console.log('💾 Detected disk_space_warning status, showing warning UI');
        if (statusIndicator) {
            statusIndicator.textContent = "Warning";
            statusIndicator.className = "status-indicator status-paused";
        }

        if (progressText) {
            progressText.textContent = "Disk space check failed";
        }

        if (progressDetails && data.disk_check) {
            const diskCheck = data.disk_check;
            progressDetails.innerHTML = `
                <div style="color:#856404;background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:10px 0;">
                    <h4 style="margin-top:0;">⚠️ Insufficient Disk Space for Backup</h4>
                    <p style="margin-bottom:10px;"><strong>${diskCheck.message}</strong></p>
                    <div style="background:#f8f9fa;padding:10px;border-radius:3px;margin:10px 0;">
                        <p style="margin:5px 0;"><strong>Backup Size Needed:</strong> ${diskCheck.backup_size_mb}MB + 20% buffer = ${diskCheck.required_mb}MB</p>
                        <p style="margin:5px 0;"><strong>Available Space:</strong> ${diskCheck.available_mb}MB</p>
                        <p style="margin:5px 0;"><strong>Shortfall:</strong> ${diskCheck.shortfall_mb}MB</p>
                    </div>
                    <p style="color:#6c757d;font-size:14px;margin:10px 0;">
                        <strong>⚠️ Risk:</strong> ${diskCheck.warning}
                    </p>
                    <div style="margin-top:15px;">
                        <button onclick="proceedPluginReinstallWithoutBackup()" style="background:#28a745;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;margin-right:10px;">
                            Proceed Without Backup
                        </button>
                        <button onclick="cancelPluginReinstall()" style="background:#dc3545;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            `;
        }

        // Stop polling while waiting for user decision
        clearInterval(reinstallProgressInterval);
        reinstallProgressInterval = null;
        return;
    }

    if (statusIndicator && data.status) {
        statusIndicator.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
    }

    if (progressFill && data.progress !== undefined) {
        progressFill.style.width = data.progress + "%";
    }

    if (progressText && data.message) {
        progressText.textContent = data.message;
    }

    if (progressDetails && data.details) {
        progressDetails.innerHTML = data.details;
    }

    // Update status indicator class
    if (statusIndicator && (data.status === 'complete' || data.status === 'error')) {
        statusIndicator.className = "status-indicator status-completed";
        // Stop polling when complete
        clearInterval(reinstallProgressInterval);
        reinstallProgressInterval = null;
    }
}

// Handle proceeding without backup
function proceedPluginReinstallWithoutBackup() {
    const progressDetails = document.getElementById("plugin-progress-details");
    const progressText = document.getElementById("plugin-progress-text");

    if (progressDetails) {
        progressDetails.innerHTML = '<div style="color:#28a745;">⏳ Proceeding without backup as requested...</div>';
    }
    if (progressText) {
        progressText.textContent = "Continuing without backup";
    }

    // Submit request to continue without backup
    const formData = new FormData();
    formData.append('action', 'reinstall_plugins');
    formData.append('progress_file', reinstallProgressFile);
    formData.append('proceed_without_backup', '1');
    formData.append('batch_start', '0'); // Restart from beginning
    formData.append('batch_size', '5');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text.trim());
            return data;
        } catch (e) {
            const preview = text.substring(0, 500).replace(/\s+/g, ' ').trim();
            throw new Error('Failed to parse JSON. Content: ' + preview + (text.length > 500 ? '...' : ''));
        }
    })
    .then(data => {
        if (data.success) {
            // Resume polling to track progress
            reinstallProgressInterval = setInterval(pollReinstallProgress, 2000);
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

// Handle canceling the operation
function cancelPluginReinstall() {
    const progressDetails = document.getElementById("plugin-progress-details");
    const progressText = document.getElementById("plugin-progress-text");
    const statusIndicator = document.getElementById("plugin-status-indicator");

    if (progressDetails) {
        progressDetails.innerHTML = '<div style="color:#dc3545;">❌ Operation cancelled by user</div>';
    }
    if (progressText) {
        progressText.textContent = "Operation cancelled";
    }
    if (statusIndicator) {
        statusIndicator.textContent = "Cancelled";
        statusIndicator.className = "status-indicator status-completed";
    }

    // Stop polling
    clearInterval(reinstallProgressInterval);
    reinstallProgressInterval = null;
}
