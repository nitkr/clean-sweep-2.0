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

    // Stop any existing polling to prevent conflicts
    if (reinstallProgressInterval) {
        clearInterval(reinstallProgressInterval);
        reinstallProgressInterval = null;
        console.log('🛑 Stopped existing polling to prevent conflicts');
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

    // Show progress container at top (create if missing)
    let progressContainer = document.getElementById("plugin-progress-container");

    // Create progress container if it doesn't exist (HTML update timing issue)
    if (!progressContainer) {
        console.log('Creating missing progress container');
        progressContainer = document.createElement('div');
        progressContainer.id = 'plugin-progress-container';
        progressContainer.style.display = 'none';
        progressContainer.style.margin = '20px 0';
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
    progressContainer.style.display = "block";
    if (pluginsTab) {
        pluginsTab.insertBefore(progressContainer, pluginsTab.firstChild);
    }

    // NEW: Check for backup choice first before starting batch processing
    checkBackupChoiceAndStart(repoPluginsJson);
}

// NEW: Check for backup choice before starting batch processing
function checkBackupChoiceAndStart(repoPluginsJson) {
    console.log('🔍 Checking for backup choice before starting reinstallation');

    // Make initial AJAX call to check if backup choice is needed
    const formData = new FormData();
    formData.append('action', 'reinstall_plugins');
    formData.append('repo_plugins', repoPluginsJson);
    formData.append('progress_file', reinstallProgressFile);
    formData.append('batch_start', '0'); // First batch
    formData.append('batch_size', '5');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text.trim());
            console.log('📋 Initial response data:', data);
            return data;
        } catch (e) {
            const preview = text.substring(0, 500).replace(/\s+/g, ' ').trim();
            throw new Error('Failed to parse JSON. Content: ' + preview + (text.length > 500 ? '...' : ''));
        }
    })
    .then(data => {
        // Check if backup choice UI was returned (backend returns either disk_check or backup_choice)
        if (data.disk_check || data.backup_choice) {
            console.log('✅ Backup choice required - showing UI');
            // Show backup choice UI and wait for user decision
            updateReinstallProgress(data);
        } else if (data.results) {
            console.log('✅ No backup choice needed - starting batch processing');
            // No backup choice needed, proceed with normal batch processing
            handleBatchResponse(data, repoPluginsJson);
        } else {
            console.error('❌ Unexpected response format:', data);
            showError('Unexpected response from server');
        }
    })
    .catch(error => {
        console.error('🚨 Error in backup choice check:', error);
        showError('Failed to check backup requirements: ' + error.message);
    });
}

// Helper function to handle batch processing responses
function handleBatchResponse(data, repoPluginsJson) {
    if (data.success) {
        // Check if there are more batches to process
        const batchInfo = data.batch_info || {};
        if (batchInfo.has_more_batches && batchInfo.next_batch_start !== null) {
            // Process next batch
            console.log(`Processed initial batch, continuing with batch starting at ${batchInfo.next_batch_start}`);
            setTimeout(() => {
                processPluginBatch(repoPluginsJson, batchInfo.next_batch_start, 5);
            }, 1000);
        } else {
            // All batches completed - show results
            clearInterval(reinstallProgressInterval);
            reinstallProgressInterval = null;

            if (data.html) {
                switchTab('plugins');
                const pluginsTab = document.getElementById('plugins-tab');
                if (pluginsTab) {
                    pluginsTab.innerHTML = data.html;
                }
                const progressContainer = document.getElementById("plugin-progress-container");
                if (progressContainer) {
                    progressContainer.style.display = "none";
                }
            }
        }
    } else if (data.error) {
        clearInterval(reinstallProgressInterval);
        reinstallProgressInterval = null;
        showError('Error: ' + data.error);
    }
}

// Helper function to show errors
function showError(message) {
    const detailsEl = document.getElementById("plugin-progress-details");
    const statusEl = document.getElementById("plugin-status-indicator");
    if (detailsEl) detailsEl.innerHTML = '<div style="color:#dc3545;">Error: ' + message + '</div>';
    if (statusEl) {
        statusEl.textContent = "Error";
        statusEl.className = "status-indicator status-completed";
    }
    clearInterval(reinstallProgressInterval);
    reinstallProgressInterval = null;
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

    console.log('🔄 Polling progress file:', reinstallProgressFile);

    // Progress files are stored in logs directory for web-accessibility
    fetch('logs/' + reinstallProgressFile + '?t=' + Date.now())
        .then(response => {
            console.log('📡 Progress file fetch response:', response.status);
            if (response.status === 404) {
                // Progress file doesn't exist yet - silently continue polling
                console.log('⏳ Waiting for progress file creation...');
                return null;
            }
            if (response.ok) {
                return response.text();
            }
            return null;
        })
        .then(text => {
            if (text) {
                console.log('📄 Raw progress response text:', text);
                try {
                    const data = JSON.parse(text);
                    console.log('🔍 Parsed progress data:', data);
                    updateReinstallProgress(data);
                } catch (e) {
                    console.warn('⚠️ JSON parsing failed, file might not be complete yet:', e);
                    // JSON parsing failed, file might not be complete yet
                }
            } else {
                console.log('📄 No text response from progress file');
            }
        })
        .catch(error => {
            // Only log real network errors, not expected 404s
            if (!error.message.includes('404')) {
                console.error('🚨 Progress polling network error:', error);
            }
        });
}

function updateReinstallProgress(data) {
    console.log('🎯 updateReinstallProgress called with data:', data);

    const statusIndicator = document.getElementById("plugin-status-indicator");
    const progressFill = document.getElementById("plugin-progress-fill");
    const progressText = document.getElementById("plugin-progress-text");
    const progressDetails = document.getElementById("plugin-progress-details");

    console.log('🔍 Checking backup choice conditions:');
    console.log('  - data.status === disk_space_warning:', data.status === 'disk_space_warning');
    console.log('  - data.backup_choice exists:', !!data.backup_choice);
    console.log('  - data.disk_check && data.disk_check.show_choice:', !!(data.disk_check && data.disk_check.show_choice));

    // Special handling for disk space warnings and backup choice
    if (data.disk_check || data.backup_choice) {
        console.log('✅ BACKUP CHOICE UI DETECTED - Showing backup choice interface');

        // Use the disk_check data (backend returns either format)
        const diskCheck = data.disk_check || data.backup_choice;

        if (statusIndicator) {
            statusIndicator.textContent = diskCheck && diskCheck.space_status === 'insufficient' ? "Warning" : "Ready";
            statusIndicator.className = "status-indicator status-paused";
        }

        if (progressText) {
            progressText.textContent = diskCheck && diskCheck.space_status === 'insufficient' ?
                "Backup space insufficient" : "Choose backup option";
        }

        if (progressDetails && diskCheck) {
            const isInsufficient = diskCheck.space_status === 'insufficient';

            progressDetails.innerHTML = `
                <div style="color:#856404;background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:10px 0;">
                    <h4 style="margin-top:0;">💾 Plugin Reinstallation - Backup Options</h4>
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
                        <button onclick="proceedPluginReinstallWithBackup()" style="background:#28a745;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;margin-right:10px;">
                            Create Backup (~${diskCheck.backup_size_mb}MB)
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

// Handle proceeding with backup
function proceedPluginReinstallWithBackup() {
    const progressDetails = document.getElementById("plugin-progress-details");
    const progressText = document.getElementById("plugin-progress-text");

    if (progressDetails) {
        progressDetails.innerHTML = '<div style="color:#28a745;">⏳ Proceeding with backup creation...</div>';
    }
    if (progressText) {
        progressText.textContent = "Creating backup and proceeding";
    }

    // Submit request to continue with backup
    const formData = new FormData();
    formData.append('action', 'reinstall_plugins');
    formData.append('progress_file', reinstallProgressFile);
    formData.append('create_backup', '1'); // Explicitly request backup
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
