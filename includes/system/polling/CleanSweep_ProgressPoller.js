/**
 * Clean Sweep - Progress Poller
 *
 * JavaScript class for polling progress updates from long-running operations.
 * Handles real-time progress display and completion detection.
 *
 * @author Nithin K R
 */

class CleanSweep_ProgressPoller {

    /**
     * Constructor
     *
     * @param {string} progressFile - Progress file name (without .progress extension)
     * @param {function} updateCallback - Callback for progress updates
     * @param {function} completeCallback - Callback for completion
     * @param {number} intervalMs - Polling interval in milliseconds
     */
    constructor(progressFile, updateCallback = null, completeCallback = null, intervalMs = 2000) {
        this.progressFile = progressFile;
        this.updateCallback = updateCallback;
        this.completeCallback = completeCallback;
        this.intervalMs = intervalMs;
        this.intervalId = null;
        this.isPolling = false;
        this.lastProgress = null;

        console.log('CleanSweep_ProgressPoller initialized for file:', progressFile);
    }

    /**
     * Start polling for progress updates
     */
    startPolling() {
        if (this.isPolling) {
            console.warn('ProgressPoller: Already polling, ignoring start request');
            return;
        }

        console.log('CleanSweep_ProgressPoller: Starting polling every', this.intervalMs, 'ms');
        this.isPolling = true;

        this.intervalId = setInterval(() => {
            this.poll();
        }, this.intervalMs);

        // Poll immediately
        this.poll();
    }

    /**
     * Stop polling
     */
    stopPolling() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
        this.isPolling = false;
        console.log('CleanSweep_ProgressPoller: Stopped polling');
    }

    /**
     * Poll for progress update
     */
    async poll() {
        if (!this.progressFile) {
            console.error('ProgressPoller: No progress file specified');
            return;
        }

        try {
            const response = await fetch(`logs/${this.progressFile}?t=${Date.now()}`);
            console.log('ProgressPoller: Fetch response status:', response.status);

            if (response.status === 404) {
                // Progress file doesn't exist yet - silently continue
                console.log('ProgressPoller: Progress file not found, waiting...');
                return;
            }

            if (!response.ok) {
                console.warn('ProgressPoller: HTTP error:', response.status);
                return;
            }

            const text = await response.text();
            console.log('ProgressPoller: Raw response:', text.substring(0, 200) + (text.length > 200 ? '...' : ''));

            const data = JSON.parse(text);
            console.log('ProgressPoller: Parsed data:', data);

            // Check if progress has actually changed
            const progressKey = JSON.stringify(data);
            if (this.lastProgress === progressKey) {
                console.log('ProgressPoller: No progress change, skipping update');
                return;
            }
            this.lastProgress = progressKey;

            // Handle completion
            if (data.status === 'complete' || data.status === 'error') {
                console.log('ProgressPoller: Operation completed with status:', data.status);
                this.stopPolling();

                if (this.completeCallback && typeof this.completeCallback === 'function') {
                    this.completeCallback(data);
                }
                return;
            }

            // Handle progress updates
            if (this.updateCallback && typeof this.updateCallback === 'function') {
                this.updateCallback(data);
            }

        } catch (error) {
            console.error('ProgressPoller: Error polling progress:', error);
            // Don't stop polling on network errors, just log and continue
        }
    }

    /**
     * Set update callback
     *
     * @param {function} callback
     */
    setUpdateCallback(callback) {
        if (typeof callback === 'function') {
            this.updateCallback = callback;
        }
    }

    /**
     * Set completion callback
     *
     * @param {function} callback
     */
    setCompleteCallback(callback) {
        if (typeof callback === 'function') {
            this.completeCallback = callback;
        }
    }

    /**
     * Set polling interval
     *
     * @param {number} intervalMs
     */
    setInterval(intervalMs) {
        this.intervalMs = Math.max(500, intervalMs); // Minimum 500ms

        // Restart polling with new interval if currently polling
        if (this.isPolling) {
            this.stopPolling();
            this.startPolling();
        }
    }

    /**
     * Set progress file
     *
     * @param {string} progressFile
     */
    setProgressFile(progressFile) {
        this.progressFile = progressFile;
        console.log('ProgressPoller: Progress file changed to:', progressFile);
    }

    /**
     * Get polling status
     *
     * @returns {boolean}
     */
    isActive() {
        return this.isPolling;
    }

    /**
     * Force immediate poll
     */
    forcePoll() {
        if (this.isPolling) {
            this.poll();
        }
    }

    /**
     * Get debug information
     *
     * @returns {object}
     */
    getDebugInfo() {
        return {
            progressFile: this.progressFile,
            isPolling: this.isPolling,
            intervalMs: this.intervalMs,
            hasUpdateCallback: typeof this.updateCallback === 'function',
            hasCompleteCallback: typeof this.completeCallback === 'function',
            lastProgress: this.lastProgress
        };
    }
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CleanSweep_ProgressPoller;
}
