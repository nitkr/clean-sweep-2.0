// Clean Sweep - Plugin Reinstallation
// AJAX functionality for plugin reinstallation with progress tracking

// Global variables for plugin data and progress tracking
let repoPluginsJson = null;
let reinstallProgressInterval = null;
let reinstallProgressFile = null;

// Helper function to handle confirm dialog and button passing
function confirmPluginReinstallation(button) {
    // Show backup choice dialog during progress (after confirmation)
    showBackupChoiceDuringProgress(button);
}

// Enhanced version that passes existing analysis data to avoid re-analysis
function startPluginReinstallationWithAnalysis(buttonElement) {
    if (!buttonElement) {
        console.error('Button element is null');
        return;
    }

    // Store analysis data globally for backup choice functions
    window.currentAnalysisDataJson = buttonElement.getAttribute('data-analysis');
    window.currentRepoPluginsJson = buttonElement.getAttribute('data-plugins');

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

    // Show initial progress
    const progressText = document.getElementById("plugin-progress-text");
    const statusIndicator = document.getElementById("plugin-status-indicator");
    if (progressText) progressText.textContent = "Initializing plugin re-installation...";
    if (statusIndicator) {
        statusIndicator.className = "status-indicator status-processing";
        statusIndicator.textContent = "Initializing";
    }

    // Show backup choice dialog after initialization
    setTimeout(() => {
        showBackupChoiceDialogWithAnalysis();
    }, 1500);
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

function processPluginBatch(repoPluginsJson, batchStart, batchSize, createBackup = true) {
    // Submit the request via AJAX for this batch
    const formData = new FormData();
    formData.append('action', 'reinstall_plugins');
    formData.append('repo_plugins', repoPluginsJson);
    formData.append('progress_file', reinstallProgressFile);
    formData.append('batch_start', batchStart);
    formData.append('batch_size', batchSize);
    formData.append('create_backup', createBackup ? '1' : '0');

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

function processPluginBatchWithAnalysis(repoPluginsJson, analysisDataJson, batchStart, batchSize, createBackup = true) {
    // Submit the request via AJAX for this batch with existing analysis data
    const formData = new FormData();
    formData.append('action', 'reinstall_plugins');
    formData.append('repo_plugins', repoPluginsJson);
    formData.append('existing_analysis', analysisDataJson);
    formData.append('progress_file', reinstallProgressFile);
    formData.append('batch_start', batchStart);
    formData.append('batch_size', batchSize);
    formData.append('create_backup', createBackup ? '1' : '0');

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
                // Process next batch with analysis data
                console.log(`Processed batch ${batchStart}-${batchStart + batchSize - 1}, continuing with next batch...`);
                setTimeout(() => {
                    processPluginBatchWithAnalysis(repoPluginsJson, analysisDataJson, batchInfo.next_batch_start, batchSize);
                }, 1000); // Small delay between batches
            } else if (batchInfo.processing_complete) {
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
            } else {
                // Fallback: All batches completed - stop polling and show results
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

function showBackupChoiceDuringProgress(button) {
    // Store plugin data globally
    repoPluginsJson = button.getAttribute('data-plugins');

    // Start the reinstallation process with analysis data to avoid re-analysis
    startPluginReinstallationWithAnalysis(button);
}

function startPluginReinstallationWithBackupChoice(buttonElement) {
    if (!buttonElement) {
        console.error('Button element is null');
        return;
    }

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

    // Show initial progress
    const progressText = document.getElementById("plugin-progress-text");
    const statusIndicator = document.getElementById("plugin-status-indicator");
    if (progressText) progressText.textContent = "Initializing plugin re-installation...";
    if (statusIndicator) {
        statusIndicator.className = "status-indicator status-processing";
        statusIndicator.textContent = "Initializing";
    }

    // Show backup choice dialog after initialization
    setTimeout(() => {
        showBackupChoiceDialog();
    }, 1500);
}

function showBackupChoiceDialog() {
    // Parse plugins to count them
    let plugins = [];
    try {
        plugins = JSON.parse(repoPluginsJson) || [];
    } catch (e) {
        console.error('Failed to parse plugins JSON');
        plugins = [];
    }

    // Create backup choice dialog
    const backupDialog = document.createElement('div');
    backupDialog.id = 'backup-choice-dialog';
    backupDialog.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.8);
        z-index: 10001;
        display: flex;
        justify-content: center;
        align-items: center;
    `;

    backupDialog.innerHTML = `
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
            <h3 style="margin: 0 0 20px 0; color: #2c3e50; text-align: center;">🛡️ Backup Choice</h3>
            <div style="margin-bottom: 20px; font-size: 14px; line-height: 1.6;">
                <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 15px;">
                    <h4 style="margin: 0 0 10px 0; color: #2c3e50;">📦 About to Reinstall ${Object.keys(plugins).length} Plugins</h4>
                    <p style="margin: 0; color: #6c757d;">WordPress.org plugins from official repository + WPMU DEV premium plugins from secured network</p>
                </div>
                <div style="background: #fff3cd; padding: 15px; border-radius: 6px; border: 1px solid #ffeaa7;">
                    <h4 style="margin: 0 0 10px 0; color: #856404;">⚠️ Backup Recommendation</h4>
                    <p style="margin: 0; color: #856404;">Creating a backup is <strong>highly recommended</strong> before proceeding with plugin reinstallation. This ensures you can restore your current setup if needed.</p>
                </div>
            </div>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button onclick="chooseBackupOption(true)" style="background: #28a745; color: white; border: none; padding: 12px 25px; border-radius: 4px; cursor: pointer; font-weight: 600;">✅ Create Backup & Continue</button>
                <button onclick="chooseBackupOption(false)" style="background: #ffc107; color: #212529; border: none; padding: 12px 25px; border-radius: 4px; cursor: pointer; font-weight: 600;">⚠️ Skip Backup & Continue</button>
                <button onclick="cancelBackupChoice()" style="background: #6c757d; color: white; border: none; padding: 12px 25px; border-radius: 4px; cursor: pointer; font-weight: 600;">❌ Cancel</button>
            </div>
        </div>
    `;

    document.body.appendChild(backupDialog);
}

function showBackupChoiceDialogWithAnalysis() {
    // Parse plugins to count them from analysis data
    let plugins = [];
    try {
        const analysisData = JSON.parse(window.currentRepoPluginsJson) || {};
        plugins = analysisData;
    } catch (e) {
        console.error('Failed to parse analysis plugins JSON');
        plugins = [];
    }

    // Create backup choice dialog
    const backupDialog = document.createElement('div');
    backupDialog.id = 'backup-choice-dialog';
    backupDialog.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.8);
        z-index: 10001;
        display: flex;
        justify-content: center;
        align-items: center;
    `;

    backupDialog.innerHTML = `
        <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
            <h3 style="margin: 0 0 20px 0; color: #2c3e50; text-align: center;">🛡️ Backup Choice</h3>
            <div style="margin-bottom: 20px; font-size: 14px; line-height: 1.6;">
                <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 15px;">
                    <h4 style="margin: 0 0 10px 0; color: #2c3e50;">📦 About to Reinstall ${Object.keys(plugins).length} Plugins</h4>
                    <p style="margin: 0; color: #6c757d;">WordPress.org plugins from official repository + WPMU DEV premium plugins from secured network</p>
                </div>
                <div style="background: #fff3cd; padding: 15px; border-radius: 6px; border: 1px solid #ffeaa7;">
                    <h4 style="margin: 0 0 10px 0; color: #856404;">⚠️ Backup Recommendation</h4>
                    <p style="margin: 0; color: #856404;">Creating a backup is <strong>highly recommended</strong> before proceeding with plugin reinstallation. This ensures you can restore your current setup if needed.</p>
                </div>
            </div>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button onclick="chooseBackupOptionWithAnalysis(true)" style="background: #28a745; color: white; border: none; padding: 12px 25px; border-radius: 4px; cursor: pointer; font-weight: 600;">✅ Create Backup & Continue</button>
                <button onclick="chooseBackupOptionWithAnalysis(false)" style="background: #ffc107; color: #212529; border: none; padding: 12px 25px; border-radius: 4px; cursor: pointer; font-weight: 600;">⚠️ Skip Backup & Continue</button>
                <button onclick="cancelBackupChoice()" style="background: #6c757d; color: white; border: none; padding: 12px 25px; border-radius: 4px; cursor: pointer; font-weight: 600;">❌ Cancel</button>
            </div>
        </div>
    `;

    document.body.appendChild(backupDialog);
}

function chooseBackupOption(createBackup) {
    // Remove backup dialog
    const backupDialog = document.getElementById('backup-choice-dialog');
    if (backupDialog) {
        backupDialog.remove();
    }

    // Update progress to show backup choice
    const progressText = document.getElementById("plugin-progress-text");
    if (progressText) {
        progressText.textContent = createBackup ? "Creating backup before proceeding..." : "Skipping backup as requested...";
    }

    // Start the actual batch processing (uses global repoPluginsJson)
    processPluginBatch(repoPluginsJson, 0, 5, createBackup);

    // Start progress polling
    setTimeout(() => {
        reinstallProgressInterval = setInterval(pollReinstallProgress, 2000);
    }, 500);
}

function chooseBackupOptionWithAnalysis(createBackup) {
    // Remove backup dialog
    const backupDialog = document.getElementById('backup-choice-dialog');
    if (backupDialog) {
        backupDialog.remove();
    }

    // Update progress to show backup choice
    const progressText = document.getElementById("plugin-progress-text");
    if (progressText) {
        progressText.textContent = createBackup ? "Creating backup before proceeding..." : "Skipping backup as requested...";
    }

    // Start the actual batch processing with analysis data
    processPluginBatchWithAnalysis(window.currentRepoPluginsJson, window.currentAnalysisDataJson, 0, 5, createBackup);

    // Start progress polling
    setTimeout(() => {
        reinstallProgressInterval = setInterval(pollReinstallProgress, 2000);
    }, 500);
}

function cancelBackupChoice() {
    // Remove backup dialog
    const backupDialog = document.getElementById('backup-choice-dialog');
    if (backupDialog) {
        backupDialog.remove();
    }

    // Reset the UI - show the analysis content again
    const pluginsTab = document.getElementById('plugins-tab');
    const progressContainer = document.getElementById("plugin-progress-container");

    if (pluginsTab) {
        // Show all children again
        const children = Array.from(pluginsTab.children);
        children.forEach(child => {
            if (child.id !== 'plugin-progress-container') {
                child.style.display = '';
            }
        });
    }

    if (progressContainer) {
        progressContainer.style.display = "none";
    }
}

function updateReinstallProgress(data) {
    const statusIndicator = document.getElementById("plugin-status-indicator");
    const progressFill = document.getElementById("plugin-progress-fill");
    const progressText = document.getElementById("plugin-progress-text");
    const progressDetails = document.getElementById("plugin-progress-details");

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
