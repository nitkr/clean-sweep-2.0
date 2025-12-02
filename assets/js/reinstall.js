// Clean Sweep - Plugin Reinstallation
// AJAX functionality for plugin reinstallation with progress tracking

// Plugin reinstallation with AJAX progress tracking
let reinstallProgressInterval = null;
let reinstallProgressFile = null;

// Helper function to handle confirm dialog and button passing
// NOTE: This is legacy code - new workflow uses modal dialog in core.js
function confirmPluginReinstallation(button) {
    // This should not be called anymore - the new workflow in core.js handles this
    console.warn('Legacy confirmPluginReinstallation called - should use core.js workflow');
    return false;
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
