/**
 * Clean Sweep - Plugin Reinstallation
 * AJAX functionality for plugin reinstallation with unified progress tracking
 */

// Global progress objects
let reinstallProgressUI = null;
let reinstallProgressPoller = null;
let reinstallProgressFile = null;
let repoPluginsJson = null;

/**
 * Confirm plugin reinstallation and start the process
 */
function confirmPluginReinstallation(button) {
    if (confirm('Are you sure you want to proceed with re-installing the plugins? This will create a backup and replace all WordPress.org plugins with their latest versions.')) {
        startPluginReinstallation(button);
    }
}

/**
 * Main entry point for plugin reinstallation
 */
function startPluginReinstallation(buttonElement) {
    if (!buttonElement) {
        console.error('Button element is null');
        return;
    }



    // Store plugin data
    repoPluginsJson = buttonElement.getAttribute('data-plugins');
    reinstallProgressFile = 'plugin_reinstall_session.progress';

    // Hide analysis content and show progress container
    const pluginsTab = document.getElementById('plugins-tab');
    if (pluginsTab) {
        // Hide all children except progress container
        const children = Array.from(pluginsTab.children);
        children.forEach(child => {
            if (child.id !== 'plugin-progress-container') {
                child.style.display = 'none';
            }
        });
    }

    // Create progress container if missing
    let progressContainer = document.getElementById("plugin-progress-container");
    if (!progressContainer) {
        progressContainer = document.createElement('div');
        progressContainer.id = 'plugin-progress-container';
        progressContainer.style.cssText = 'display:none; margin:20px 0;';
        progressContainer.innerHTML = `
            <div class="progress-container">
                <h3><span id="plugin-status-indicator" class="status-indicator status-processing">Processing</span> Plugin Operation Progress</h3>
                <div class="progress-bar"><div id="plugin-progress-fill" class="progress-fill" style="width:0%"></div></div>
                <div id="plugin-progress-text" class="progress-text">Initializing...</div>
            </div>
            <div id="plugin-progress-details" style="background:#f8f9fa;border:1px solid #dee2e6;padding:15px;border-radius:4px;margin:10px 0;"></div>
        `;

        // Add to plugins tab
        if (pluginsTab) {
            pluginsTab.appendChild(progressContainer);
        }
    }

    // Show progress container
    progressContainer.style.display = 'block';
    if (pluginsTab) {
        pluginsTab.insertBefore(progressContainer, pluginsTab.firstChild);
    }

    // Initialize unified progress UI and poller
    reinstallProgressUI = new CleanSweep_ProgressUI('plugin-progress-container');
    reinstallProgressPoller = new CleanSweep_ProgressPoller(reinstallProgressFile, 
        (data) => reinstallProgressUI.updateProgress(data),
        handleReinstallCompletion,
        500 // Fast polling for responsive UI
    );

    // Start polling immediately
    reinstallProgressPoller.startPolling();

    // Kick off the process by checking backup choice
    checkBackupChoiceAndStart();
}

/**
 * Check for backup choice and start the process
 */
function checkBackupChoiceAndStart() {


    const formData = new FormData();
    formData.append('action', 'reinstall_plugins');
    formData.append('repo_plugins', repoPluginsJson);
    formData.append('progress_file', reinstallProgressFile);
    formData.append('batch_start', '0');
    formData.append('batch_size', '5');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => JSON.parse(text.trim()))
    .then(data => {
        // Check if this is a backup choice response
        if (data.disk_check) {

            // Stop polling since we're handling the response directly
            reinstallProgressPoller.stopPolling();

            // Display backup choice UI - the HTML should be in the response
            const progressDetails = document.getElementById('plugin-progress-details');
            if (progressDetails) {
                // The backup choice HTML should be part of the response
                // For now, create a simple backup choice UI
                const backupHtml = `
                    <div style="color:#856404;background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:10px 0;">
                        <h4 style="margin-top:0;">💾 Plugin Reinstallation - Backup Options</h4>
                        <p style="margin-bottom:10px;"><strong>Estimated backup size: ${data.disk_check.backup_size_mb || 'Unknown'}MB</strong></p>
                        <div style="background:#f8f9fa;padding:10px;border-radius:3px;margin:10px 0;">
                            <p style="margin:5px 0;"><strong>Available Space:</strong> ${data.disk_check.available_mb || 'Unknown'}MB</p>
                        </div>
                        <div style="margin-top:15px;">
                            <button onclick="proceedPluginReinstallWithBackup()" style="background:#28a745;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;margin-right:10px;">
                                Create Backup (~${data.disk_check.backup_size_mb || 'Unknown'}MB)
                            </button>
                            <button onclick="proceedPluginReinstallWithoutBackup()" style="background:#ffc107;color:black;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;margin-right:10px;">
                                Skip Backup (Faster)
                            </button>
                            <button onclick="cancelPluginReinstall()" style="background:#dc3545;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;">
                                Cancel
                            </button>
                        </div>
                    </div>
                `;
                progressDetails.innerHTML = backupHtml;

                // Update progress indicator
                const statusIndicator = document.getElementById('plugin-status-indicator');
                if (statusIndicator) {
                    statusIndicator.textContent = 'Ready';
                    statusIndicator.className = 'status-indicator status-paused';
                }

                const progressText = document.getElementById('plugin-progress-text');
                if (progressText) {
                    progressText.textContent = 'Choose backup option';
                }
            }

            return; // Don't continue with polling
        }

        // For other responses, let the poller handle progress updates
        // Progress updates will be handled by poller
        // No need to update UI here - poller handles it
    })
    .catch(error => {
        console.error('Error checking backup:', error);
        reinstallProgressUI.updateProgress({
            status: 'error',
            message: 'Failed to initialize operation: ' + error.message
        });
    });
}

/**
 * Handle reinstallation completion
 */
function handleReinstallCompletion(data) {
    // Check if this is an intermediate batch completion (more batches needed)
    if (data.batch_info && data.batch_info.has_more_batches) {
        // Don't stop polling - continue monitoring progress
        // Automatically trigger next batch
        const nextBatchStart = (data.batch_info.batch_start || 0) + (data.batch_info.batch_size || 5);

        const formData = new FormData();
        formData.append('action', 'reinstall_plugins');
        formData.append('progress_file', reinstallProgressFile);
        formData.append('batch_start', nextBatchStart.toString());
        formData.append('batch_size', (data.batch_info.batch_size || 5).toString());

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => JSON.parse(text.trim()))
        .then(batchData => {
            // Continue polling - don't show results yet
        })
        .catch(error => {
            console.error('Error starting next batch:', error);
            // Stop polling and show error
            reinstallProgressPoller.stopPolling();
            reinstallProgressUI.updateProgress({
                status: 'error',
                message: 'Failed to continue batch processing: ' + error.message
            });
        });

        return; // Don't show results or stop polling yet
    }

    // This is the final completion - show results
    reinstallProgressPoller.stopPolling();

    // Show results if available
    if (data.results && data.html) {
        switchTab('plugins');
        const pluginsTab = document.getElementById('plugins-tab');
        if (pluginsTab) {
            pluginsTab.innerHTML = data.html;
        } else {
            console.error('plugins-tab element not found');
        }
    } else {
        console.warn('Missing results or html in final completion data');
    }

    // Hide progress container after delay
    setTimeout(() => {
        const progressContainer = document.getElementById('plugin-progress-container');
        if (progressContainer) {
            progressContainer.style.display = 'none';
        }
    }, 3000);
}

/**
 * Proceed with backup
 */
function proceedPluginReinstallWithBackup() {
    console.log('💾 Proceeding with backup creation...');

    // Update UI to show we're proceeding
    const progressDetails = document.getElementById('plugin-progress-details');
    if (progressDetails) {
        progressDetails.innerHTML = '<div style="color:#28a745;">⏳ Proceeding with plugin reinstallation with backup...</div>';
    }

    const progressText = document.getElementById('plugin-progress-text');
    if (progressText) {
        progressText.textContent = 'Creating backup and proceeding';
    }

    const formData = new FormData();
    formData.append('action', 'reinstall_plugins');
    formData.append('progress_file', reinstallProgressFile);
    formData.append('create_backup', '1');
    formData.append('batch_start', '0');
    formData.append('batch_size', '5');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            // Restart polling to track actual reinstallation progress
            reinstallProgressPoller.startPolling();
            console.log('Backup request sent, polling restarted');
        } else {
            console.error('Backup request failed');
        }
    })
    .catch(error => {
        console.error('Error sending backup request:', error);
    });
}

/**
 * Proceed without backup
 */
function proceedPluginReinstallWithoutBackup() {
    console.log('⚡ Proceeding without backup...');

    // Update UI to show we're proceeding
    const progressDetails = document.getElementById('plugin-progress-details');
    if (progressDetails) {
        progressDetails.innerHTML = '<div style="color:#28a745;">⏳ Proceeding with plugin reinstallation without backup...</div>';
    }

    const progressText = document.getElementById('plugin-progress-text');
    if (progressText) {
        progressText.textContent = 'Continuing without backup';
    }

    const formData = new FormData();
    formData.append('action', 'reinstall_plugins');
    formData.append('progress_file', reinstallProgressFile);
    formData.append('proceed_without_backup', '1');
    formData.append('batch_start', '0');
    formData.append('batch_size', '5');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            // Restart polling to track actual reinstallation progress
            reinstallProgressPoller.startPolling();
            console.log('No-backup request sent, polling restarted');
        } else {
            console.error('No-backup request failed');
        }
    })
    .catch(error => {
        console.error('Error sending no-backup request:', error);
    });
}

/**
 * Cancel operation
 */
function cancelPluginReinstall() {
    reinstallProgressPoller.stopPolling();
    reinstallProgressUI.updateProgress({
        status: 'error',
        message: 'Operation cancelled by user'
    });
}
