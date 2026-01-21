// Clean Sweep - Plugin Reinstallation
// AJAX functionality for plugin reinstallation with progress tracking

// Global variables for plugin data and progress tracking
let repoPluginsJson = null;
let reinstallProgressInterval = null;
let reinstallProgressFile = null;

// Selective plugin reinstallation functions
function selectAllWpOrg() {
    const checkboxes = document.querySelectorAll('.wp-org-plugin-checkbox');
    checkboxes.forEach(cb => cb.checked = true);
    updateSelectedCount();
}

function selectNoneWpOrg() {
    const checkboxes = document.querySelectorAll('.wp-org-plugin-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    updateSelectedCount();
}

function toggleAllWpOrg(checked) {
    const checkboxes = document.querySelectorAll('.wp-org-plugin-checkbox');
    checkboxes.forEach(cb => cb.checked = checked);
    updateSelectedCount();
}

function selectAllWpmuDev() {
    const checkboxes = document.querySelectorAll('.wpmu-dev-plugin-checkbox');
    checkboxes.forEach(cb => cb.checked = true);
    updateSelectedCount();
}

function selectNoneWpmuDev() {
    const checkboxes = document.querySelectorAll('.wpmu-dev-plugin-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    updateSelectedCount();
}

function toggleAllWpmuDev(checked) {
    const checkboxes = document.querySelectorAll('.wpmu-dev-plugin-checkbox');
    checkboxes.forEach(cb => cb.checked = checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const wpOrgChecked = document.querySelectorAll('.wp-org-plugin-checkbox:checked').length;
    const wpmuDevChecked = document.querySelectorAll('.wpmu-dev-plugin-checkbox:checked').length;
    const totalSelected = wpOrgChecked + wpmuDevChecked;

    const countElement = document.getElementById('selected-count');
    if (countElement) {
        countElement.textContent = totalSelected;
    }

    // Update master checkboxes based on individual selections
    const wpOrgMaster = document.getElementById('wp-org-select-all');
    const wpmuDevMaster = document.getElementById('wpmu-dev-select-all');

    if (wpOrgMaster) {
        const totalWpOrg = document.querySelectorAll('.wp-org-plugin-checkbox').length;
        wpOrgMaster.checked = (wpOrgChecked === totalWpOrg && totalWpOrg > 0);
        wpOrgMaster.indeterminate = (wpOrgChecked > 0 && wpOrgChecked < totalWpOrg);
    }

    if (wpmuDevMaster) {
        const totalWpmuDev = document.querySelectorAll('.wpmu-dev-plugin-checkbox').length;
        wpmuDevMaster.checked = (wpmuDevChecked === totalWpmuDev && totalWpmuDev > 0);
        wpmuDevMaster.indeterminate = (wpmuDevChecked > 0 && wpmuDevChecked < totalWpmuDev);
    }
}

// Helper function to handle confirm dialog and button passing
function confirmPluginReinstallation(button) {
    // Collect selected plugins before showing backup choice
    const selectedPlugins = collectSelectedPlugins(button);

    if (Object.keys(selectedPlugins).length === 0) {
        alert('Please select at least one plugin to reinstall.');
        return;
    }

    // Show backup choice dialog during progress (after confirmation), passing selectedPlugins directly
    showBackupChoiceDuringProgress(button, selectedPlugins);
}

// Collect selected plugins from checkboxes using button's data-analysis attribute
function collectSelectedPlugins(button) {
    const selectedPlugins = {};

    // Get analysis data from button's data-analysis attribute
    const analysisDataJson = button.getAttribute('data-analysis');
    if (!analysisDataJson) {
        console.error('No analysis data found in button');
        return selectedPlugins;
    }

    let analysisData;
    try {
        analysisData = JSON.parse(analysisDataJson);
    } catch (e) {
        console.error('Failed to parse analysis data:', e);
        return selectedPlugins;
    }

    // Collect WordPress.org plugins
    const wpOrgCheckboxes = document.querySelectorAll('.wp-org-plugin-checkbox:checked');
    wpOrgCheckboxes.forEach(cb => {
        const slug = cb.getAttribute('data-slug');
        if (slug && analysisData.wp_org_plugins && analysisData.wp_org_plugins[slug]) {
            selectedPlugins[slug] = analysisData.wp_org_plugins[slug];
        }
    });

    // Collect WPMU DEV plugins
    const wpmuDevCheckboxes = document.querySelectorAll('.wpmu-dev-plugin-checkbox:checked');
    wpmuDevCheckboxes.forEach(cb => {
        const slug = cb.getAttribute('data-slug');
        if (slug && analysisData.wpmu_dev_plugins && analysisData.wpmu_dev_plugins[slug]) {
            selectedPlugins[slug] = analysisData.wpmu_dev_plugins[slug];
        }
    });

    return selectedPlugins;
}

// Enhanced version that passes existing analysis data to avoid re-analysis
function startPluginReinstallationWithAnalysis(buttonElement) {
    if (!buttonElement) {
        console.error('Button element is null');
        return;
    }

    // Store analysis data globally for backup choice functions
    // Note: window.currentRepoPluginsJson is already set correctly by showBackupChoiceDuringProgress()
    window.currentAnalysisDataJson = buttonElement.getAttribute('data-analysis');

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
    formData.append('existing_analysis', analysisDataJson); // Pass analysis data with EVERY batch
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

function showBackupChoiceDuringProgress(button, selectedPlugins) {
    // Store selected plugins globally and as JSON
    const selectedPluginsJson = JSON.stringify(selectedPlugins);
    window.currentRepoPluginsJson = selectedPluginsJson;

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

function establishCoreBaseline() {
    const statusElement = document.getElementById('baseline-status');
    const button = event.target;

    // Disable button and show loading
    button.disabled = true;
    button.textContent = '🔄 Establishing...';
    if (statusElement) {
        statusElement.textContent = 'Creating cryptographic baseline...';
        statusElement.style.color = '#666';
    }

    // Send AJAX request to establish baseline
    const formData = new FormData();
    formData.append('action', 'establish_core_baseline');

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
            throw new Error('Invalid response from server');
        }
    })
    .then(data => {
        // Re-enable button
        button.disabled = false;
        button.textContent = '🔐 Establish Core Baseline';

        if (data.success) {
            if (statusElement) {
                statusElement.textContent = '✅ Baseline established successfully!';
                statusElement.style.color = '#28a745';
            }
            // Refresh the page after a short delay to show updated status
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            if (statusElement) {
                statusElement.textContent = '❌ Failed to establish baseline: ' + (data.error || 'Unknown error');
                statusElement.style.color = '#dc3545';
            }
        }
    })
    .catch(error => {
        // Re-enable button
        button.disabled = false;
        button.textContent = '🔐 Establish Core Baseline';

        if (statusElement) {
            statusElement.textContent = '❌ Error: ' + error.message;
            statusElement.style.color = '#dc3545';
        }
    });
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

// ============================================================================
// INTEGRITY BASELINE MANAGEMENT FUNCTIONS
// ============================================================================

function establishBaseline() {
    const statusDiv = document.getElementById('baseline-status-messages');
    if (statusDiv) {
        statusDiv.innerHTML = '<div style="color:#007bff;">🔄 Establishing baseline... Please wait while we scan and hash all monitored files. This may take longer for large sites.</div>';
    }

    const formData = new FormData();
    formData.append('action', 'establish_core_baseline');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text.trim());
            if (statusDiv) {
                if (data.success) {
                    statusDiv.innerHTML = '<div style="color:#28a745;">✅ ' + data.message + '</div>';
                    // Update baseline status display dynamically
                    updateBaselineStatusAfterEstablish();
                } else {
                    statusDiv.innerHTML = '<div style="color:#dc3545;">❌ ' + (data.error || 'Failed to establish baseline') + '</div>';
                }
            }
        } catch (e) {
            if (statusDiv) {
                statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Error: ' + e.message + '</div>';
            }
        }
    })
    .catch(error => {
        if (statusDiv) {
            statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Network error: ' + error.message + '</div>';
        }
    });
}

function exportBaseline() {
    const statusDiv = document.getElementById('baseline-status-messages');
    if (statusDiv) {
        statusDiv.innerHTML = '<div style="color:#007bff;">📤 Exporting baseline...</div>';
    }

    const formData = new FormData();
    formData.append('action', 'export_baseline');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text.trim());
            if (statusDiv) {
                if (data.success) {
                    // Create download link
                    const blob = new Blob([data.data], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = data.filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);

                    statusDiv.innerHTML = '<div style="color:#28a745;">✅ Baseline exported successfully as ' + data.filename + '</div>';
                } else {
                    statusDiv.innerHTML = '<div style="color:#dc3545;">❌ ' + (data.error || 'Failed to export baseline') + '</div>';
                }
            }
        } catch (e) {
            if (statusDiv) {
                statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Error: ' + e.message + '</div>';
            }
        }
    })
    .catch(error => {
        if (statusDiv) {
            statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Network error: ' + error.message + '</div>';
        }
    });
}

function importBaseline() {
    const fileInput = document.getElementById('import-baseline-file');
    const statusDiv = document.getElementById('baseline-status-messages');

    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        if (statusDiv) {
            statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Please select a baseline file to import</div>';
        }
        return;
    }

    const file = fileInput.files[0];
    if (statusDiv) {
        statusDiv.innerHTML = '<div style="color:#007bff;">📥 Importing and verifying baseline...</div>';
    }

    const formData = new FormData();
    formData.append('action', 'import_baseline');
    formData.append('baseline_file', file);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text.trim());
            if (statusDiv) {
                if (data.success) {
                    statusDiv.innerHTML = '<div style="color:#28a745;">✅ ' + data.message + '</div>';
                    if (data.metadata) {
                        statusDiv.innerHTML += '<div style="margin-top:10px;font-size:14px;color:#6c757d;">';
                        statusDiv.innerHTML += '<strong>Imported from:</strong> ' + (data.metadata.site_domain || 'Unknown') + '<br>';
                        statusDiv.innerHTML += '<strong>WordPress version:</strong> ' + (data.metadata.wp_version || 'Unknown') + '<br>';
                        statusDiv.innerHTML += '<strong>Exported:</strong> ' + (data.metadata.exported_at ? new Date(data.metadata.exported_at * 1000).toLocaleString() : 'Unknown');
                        statusDiv.innerHTML += '</div>';
                    }

                    // 🔄 AUTOMATICALLY TRIGGER COMPARISON AFTER SUCCESSFUL IMPORT
                    statusDiv.innerHTML += '<div style="margin-top:10px;color:#007bff;">🔍 Running comparison...</div>';
                    setTimeout(() => {
                        compareBaselines(); // Auto-compare after import
                    }, 1000);

                    // Update baseline status display dynamically
                    updateBaselineStatusAfterImport();
                } else {
                    statusDiv.innerHTML = '<div style="color:#dc3545;">❌ ' + (data.error || 'Failed to import baseline') + '</div>';
                }
            }
        } catch (e) {
            if (statusDiv) {
                statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Error: ' + e.message + '</div>';
            }
        }
    })
    .catch(error => {
        if (statusDiv) {
            statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Network error: ' + error.message + '</div>';
        }
    });
}

function compareBaselines() {
    const statusDiv = document.getElementById('baseline-status-messages');
    if (statusDiv) {
        statusDiv.innerHTML = '<div style="color:#007bff;">🔍 Comparing current state with baseline...</div>';
    }

    const formData = new FormData();
    formData.append('action', 'compare_baselines');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text.trim());
            if (statusDiv) {
                if (data.success) {
                    let message = '<div style="color:#28a745;">✅ Comparison complete</div>';
                    message += '<div style="margin-top:10px;font-size:14px;">';
                    message += '<strong>Integrity violations found:</strong> ' + data.total_violations + '<br>';

                    if (data.violations && data.violations.length > 0) {
                        message += '<div style="margin-top:15px;max-height:300px;overflow-y:auto;border:1px solid #dee2e6;border-radius:4px;padding:10px;background:#f8f9fa;">';

                        // Group violations by severity
                        const critical = data.violations.filter(v => (v.severity || 'warning') === 'critical');
                        const warnings = data.violations.filter(v => (v.severity || 'warning') === 'warning');
                        const info = data.violations.filter(v => (v.severity || 'warning') === 'info');

                        // Display violations by severity
                        [critical, warnings, info].forEach(function(violationGroup, index) {
                            const severityNames = ['CRITICAL', 'WARNING', 'INFO'];
                            const severityColors = ['#dc3545', '#ffc107', '#17a2b8'];
                            const severityName = severityNames[index];
                            const severityColor = severityColors[index];

                            if (violationGroup.length > 0) {
                                message += '<div style="margin-bottom:15px;">';
                                message += '<div style="font-weight:bold;color:' + severityColor + ';margin-bottom:8px;border-bottom:1px solid ' + severityColor + ';padding-bottom:4px;">';
                                message += severityName + ' Violations (' + violationGroup.length + ')';
                                message += '</div>';

                                violationGroup.forEach(function(violation) {
                                    message += '<div style="background:white;border:1px solid #e9ecef;border-radius:4px;padding:8px;margin:4px 0;">';
                                    message += '<div style="font-weight:600;color:#495057;margin-bottom:4px;">📄 ' + (violation.file || 'Unknown file') + '</div>';

                                    if (violation.description) {
                                        message += '<div style="color:#6c757d;font-size:13px;margin-bottom:4px;">' + violation.description + '</div>';
                                    }

                                    if (violation.path) {
                                        message += '<div style="font-family:monospace;font-size:12px;color:#6c757d;background:#f8f9fa;padding:4px;border-radius:3px;">' + violation.path + '</div>';
                                    }

                                    if (violation.details) {
                                        message += '<div style="font-size:12px;color:#6c757d;margin-top:4px;"><strong>Details:</strong> ' + violation.details + '</div>';
                                    }

                                    message += '</div>';
                                });
                                message += '</div>';
                            }
                        });

                        // Show truncation notice if needed
                        if (data.violations.length > 10) {
                            message += '<div style="text-align:center;padding:8px;color:#6c757d;border-top:1px solid #dee2e6;margin-top:10px;">';
                            message += 'Showing first 10 violations. Run malware scan for complete analysis.';
                            message += '</div>';
                        }

                        message += '</div>';
                    } else {
                        message += '<div style="color:#28a745;font-weight:500;margin-top:8px;">✓ No integrity violations detected</div>';
                        message += '<div style="color:#6c757d;font-size:13px;margin-top:4px;">All monitored files match their baseline hashes.</div>';
                    }

                    message += '</div>';
                    statusDiv.innerHTML = message;
                } else {
                    statusDiv.innerHTML = '<div style="color:#dc3545;">❌ ' + (data.error || 'Comparison failed') + '</div>';
                }
            }
        } catch (e) {
            if (statusDiv) {
                statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Error: ' + e.message + '</div>';
            }
        }
    })
    .catch(error => {
        if (statusDiv) {
            statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Network error: ' + error.message + '</div>';
        }
    });
}

function updateBaselineStatusAfterEstablish() {
    // Update the baseline status display dynamically
    const statusElements = document.querySelectorAll('.baseline-status');
    statusElements.forEach(element => {
        element.innerHTML = '<span style="color:#28a745;">✅ Baseline Established</span>';
    });

    // Update any baseline info displays
    const infoElements = document.querySelectorAll('.baseline-info');
    infoElements.forEach(element => {
        element.innerHTML = '<div style="font-size:12px;color:#6c757d;margin-top:5px;">Established: ' + new Date().toLocaleString() + '</div>';
    });
}

function updateBaselineStatusAfterImport() {
    // Update the baseline status display dynamically
    const statusElements = document.querySelectorAll('.baseline-status');
    statusElements.forEach(element => {
        element.innerHTML = '<span style="color:#28a745;">✅ Baseline Imported</span>';
    });

    // Update any baseline info displays
    const infoElements = document.querySelectorAll('.baseline-info');
    infoElements.forEach(element => {
        element.innerHTML = '<div style="font-size:12px;color:#6c757d;margin-top:5px;">Imported: ' + new Date().toLocaleString() + '</div>';
    });
}

// ============================================================================
// COMPREHENSIVE BASELINE MODE MANAGEMENT
// ============================================================================

function toggleComprehensiveMode(enabled) {
    const statusDiv = document.getElementById('baseline-status-messages');

    // Show feedback
    if (statusDiv) {
        const modeText = enabled ? 'comprehensive monitoring' : 'core-only monitoring';
        statusDiv.innerHTML = '<div style="color:#007bff;">🔄 Switching to ' + modeText + '...</div>';
    }

    // Save setting via AJAX
    const formData = new FormData();
    formData.append('action', 'save_comprehensive_baseline_setting');
    formData.append('enabled', enabled ? '1' : '0');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text.trim());
            if (statusDiv) {
                if (data.success) {
                    const modeText = enabled ? 'comprehensive monitoring' : 'core-only monitoring';
                    statusDiv.innerHTML = '<div style="color:#28a745;">✅ Switched to ' + modeText + ' mode</div>';
                } else {
                    statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Failed to save setting</div>';
                }
            }
        } catch (e) {
            if (statusDiv) {
                statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Error saving setting</div>';
            }
        }
    })
    .catch(error => {
        if (statusDiv) {
            statusDiv.innerHTML = '<div style="color:#dc3545;">❌ Network error saving setting</div>';
        }
    });
}
