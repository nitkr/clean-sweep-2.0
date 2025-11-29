/**
 * Clean Sweep - Progress UI
 *
 * JavaScript class for managing progress UI updates during long-running operations.
 * Handles progress bars, status messages, and completion states.
 *
 * @author Nithin K R
 */

class CleanSweep_ProgressUI {

    /**
     * Constructor
     *
     * @param {string} containerId - ID of the progress container element
     */
    constructor(containerId = 'plugin-progress-container') {
        this.containerId = containerId;
        this.container = null;
        this.elements = {};

        console.log('CleanSweep_ProgressUI initialized for container:', containerId);
        this.initializeElements();
    }

    /**
     * Initialize UI elements
     */
    initializeElements() {
        this.container = document.getElementById(this.containerId);
        if (!this.container) {
            console.warn('ProgressUI: Container element not found:', this.containerId);
            return;
        }

        // Cache element references
        this.elements = {
            statusIndicator: document.getElementById('plugin-status-indicator'),
            progressFill: document.getElementById('plugin-progress-fill'),
            progressText: document.getElementById('plugin-progress-text'),
            progressDetails: document.getElementById('plugin-progress-details')
        };

        console.log('ProgressUI: Elements initialized');
    }

    /**
     * Update progress display
     *
     * @param {object} data - Progress data from server
     */
    updateProgress(data) {
        console.log('ProgressUI: Updating progress with data:', data);

        if (!this.container) {
            console.warn('ProgressUI: Container not available, cannot update');
            return;
        }

        try {
            // Update status indicator
            this.updateStatusIndicator(data.status);

            // Update progress bar
            this.updateProgressBar(data.progress);

            // Update text messages
            this.updateProgressText(data.message, data.details);

            // Handle special cases
            this.handleSpecialCases(data);

        } catch (error) {
            console.error('ProgressUI: Error updating progress:', error);
        }
    }

    /**
     * Update status indicator
     *
     * @param {string} status
     */
    updateStatusIndicator(status) {
        const indicator = this.elements.statusIndicator;
        if (!indicator) return;

        // Capitalize first letter
        const displayStatus = status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Processing';

        indicator.textContent = displayStatus;

        // Update CSS class
        indicator.className = 'status-indicator';

        switch (status) {
            case 'complete':
            case 'success':
                indicator.classList.add('status-completed');
                break;
            case 'error':
                indicator.classList.add('status-error');
                break;
            case 'warning':
                indicator.classList.add('status-warning');
                break;
            case 'starting':
                indicator.classList.add('status-starting');
                break;
            default:
                indicator.classList.add('status-processing');
        }
    }

    /**
     * Update progress bar
     *
     * @param {number} progress - Progress percentage (0-100)
     */
    updateProgressBar(progress) {
        const fill = this.elements.progressFill;
        if (!fill) return;

        const percentage = Math.max(0, Math.min(100, progress || 0));
        fill.style.width = percentage + '%';

        console.log('ProgressUI: Updated progress bar to', percentage + '%');
    }

    /**
     * Update progress text and details
     *
     * @param {string} message - Main progress message
     * @param {string} details - Additional details
     */
    updateProgressText(message, details) {
        const textEl = this.elements.progressText;
        const detailsEl = this.elements.progressDetails;

        if (textEl && message) {
            textEl.textContent = message;
        }

        if (detailsEl) {
            if (details) {
                // Handle different detail formats
                if (typeof details === 'string') {
                    detailsEl.innerHTML = '<div>' + details + '</div>';
                } else if (Array.isArray(details)) {
                    detailsEl.innerHTML = details.map(item => '<div>' + item + '</div>').join('');
                } else if (typeof details === 'object') {
                    // Handle structured details
                    const detailHtml = Object.entries(details)
                        .map(([key, value]) => `<div><strong>${key}:</strong> ${value}</div>`)
                        .join('');
                    detailsEl.innerHTML = detailHtml;
                }
            } else {
                detailsEl.innerHTML = '';
            }
        }
    }

    /**
     * Handle special progress cases (backup choice, warnings, etc.)
     *
     * @param {object} data - Progress data
     */
    handleSpecialCases(data) {
        // Handle backup choice UI
        if (data.disk_check || data.backup_choice) {
            this.showBackupChoiceUI(data);
            return;
        }

        // Handle warnings
        if (data.status === 'warning') {
            this.showWarning(data);
            return;
        }

        // Handle errors
        if (data.status === 'error') {
            this.showError(data);
            return;
        }

        // Handle completion
        if (data.status === 'complete') {
            this.showCompletion(data);
            return;
        }
    }

    /**
     * Show backup choice UI
     *
     * @param {object} data - Backup choice data
     */
    showBackupChoiceUI(data) {
        const detailsEl = this.elements.progressDetails;
        if (!detailsEl) return;

        const diskCheck = data.disk_check || data.backup_choice;
        const isInsufficient = diskCheck.space_status === 'insufficient';

        detailsEl.innerHTML = `
            <div style="color:#856404;background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:10px 0;">
                <h4 style="margin-top:0;">💾 Backup Options</h4>
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

    /**
     * Show warning message
     *
     * @param {object} data - Warning data
     */
    showWarning(data) {
        const detailsEl = this.elements.progressDetails;
        if (!detailsEl) return;

        detailsEl.innerHTML = `
            <div style="color:#856404;background:#fff3cd;border:1px solid #ffeaa7;padding:15px;border-radius:4px;margin:10px 0;">
                <strong>⚠️ Warning:</strong> ${data.warning || data.message}
            </div>
        `;
    }

    /**
     * Show error message
     *
     * @param {object} data - Error data
     */
    showError(data) {
        const detailsEl = this.elements.progressDetails;
        if (!detailsEl) return;

        detailsEl.innerHTML = `
            <div style="color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;padding:15px;border-radius:4px;margin:10px 0;">
                <strong>❌ Error:</strong> ${data.error || data.message}
            </div>
        `;
    }

    /**
     * Show completion message
     *
     * @param {object} data - Completion data
     */
    showCompletion(data) {
        const detailsEl = this.elements.progressDetails;
        if (!detailsEl) return;

        let completionHtml = '<div style="color:#155724;background:#d4edda;border:1px solid #c3e6cb;padding:15px;border-radius:4px;margin:10px 0;">';

        if (data.results && data.results.summary) {
            const summary = data.results.summary;
            completionHtml += `
                <h4 style="margin-top:0;">✅ Operation Completed Successfully!</h4>
                <div style="background:#f8f9fa;padding:10px;border-radius:3px;margin:10px 0;">
                    <p style="margin:5px 0;"><strong>Successful:</strong> ${summary.total_successful || 0}</p>
                    <p style="margin:5px 0;"><strong>Failed:</strong> ${summary.total_failed || 0}</p>
                    <p style="margin:5px 0;"><strong>Total Processed:</strong> ${summary.total_processed || 0}</p>
                </div>
            `;
        } else {
            completionHtml += '<strong>✅ Operation Completed Successfully!</strong>';
        }

        completionHtml += '</div>';
        detailsEl.innerHTML = completionHtml;
    }

    /**
     * Hide progress container
     */
    hide() {
        if (this.container) {
            this.container.style.display = 'none';
        }
    }

    /**
     * Show progress container
     */
    show() {
        if (this.container) {
            this.container.style.display = 'block';
        }
    }

    /**
     * Set container element
     *
     * @param {string} containerId
     */
    setContainer(containerId) {
        this.containerId = containerId;
        this.initializeElements();
    }

    /**
     * Reset progress display
     */
    reset() {
        this.updateStatusIndicator('processing');
        this.updateProgressBar(0);
        this.updateProgressText('Initializing...', '');
    }

    /**
     * Get debug information
     *
     * @returns {object}
     */
    getDebugInfo() {
        return {
            containerId: this.containerId,
            hasContainer: !!this.container,
            elements: Object.keys(this.elements).reduce((acc, key) => {
                acc[key] = !!this.elements[key];
                return acc;
            }, {})
        };
    }
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CleanSweep_ProgressUI;
}
