<?php
/**
 * Clean Sweep - UI Components
 *
 * Contains all HTML, CSS, and JavaScript output functions
 * for the Clean Sweep web interface.
 *
 * @author Nithin K R
 */

/**
 * Output HTML header for browser execution
 */
function clean_sweep_output_html_header() {
    if (!defined('WP_CLI') || !WP_CLI) {
        $recovery_mode = defined('CLEAN_SWEEP_RECOVERY_MODE') && CLEAN_SWEEP_RECOVERY_MODE;
        $title_suffix = $recovery_mode ? ' - Recovery Mode' : '';
        $badge_html = ''; // Remove recovery badge for cleaner UI

        echo '<!DOCTYPE html><html><head><title>Clean Sweep - WordPress Malware Cleanup Toolkit' . $title_suffix . '</title>';
        echo '<link rel="stylesheet" href="assets/css/style.css">';
        echo '<script src="assets/script.js"></script>';
        echo '</head><body><h1>🧹 Clean Sweep v ' . CLEAN_SWEEP_VERSION . $badge_html . '</h1>';


    }
}



/**
 * Display recovery mode setup interface using existing Clean Sweep architecture
 */
function clean_sweep_display_recovery_setup_interface($recovery_detection) {
    if (defined('WP_CLI') && WP_CLI) {
        echo "🔄 Recovery mode detected. Issues found:\n";
        foreach ($recovery_detection['issues'] as $issue) {
            echo "  - $issue\n";
        }
        echo "\n🔧 Setting up recovery environment...\n";
        return;
    }

    // Show recovery setup using existing Clean Sweep interface
    echo '<div class="recovery-notice" style="background:#fff3cd;border:1px solid #ffeaa7;padding:20px;border-radius:8px;margin:20px 0;text-align:center;">';
    echo '<h2 style="margin:0 0 15px 0;color:#856404;">🔄 Recovery Mode Setup Required</h2>';
    echo '<p style="margin:0 0 15px 0;font-size:16px;color:#856404;">Clean Sweep needs to initialize a secure environment to continue safely.</p>';

    echo '<div style="background:#ffffff;border:2px solid #ffeaa7;border-radius:6px;padding:15px;margin:15px 0;text-align:left;">';
    echo '<h4 style="margin:0 0 10px 0;color:#721c24;">🚨 Detected Issues:</h4>';
    echo '<ul style="margin:0;padding-left:20px;">';
    foreach ($recovery_detection['issues'] as $issue) {
        switch ($issue) {
            case 'site_http_500':
                echo '<li>🌐 Site returning HTTP 500 errors</li>';
                break;
            case 'wp_settings_corrupt':
                echo '<li>📄 wp-settings.php file corruption detected</li>';
                break;
            case 'wp_load_corrupt':
                echo '<li>📄 wp-load.php file corruption detected</li>';
                break;
            default:
                echo '<li>' . htmlspecialchars($issue) . '</li>';
        }
    }
    echo '</ul>';
    echo '</div>';

    echo '<div style="margin:20px 0;text-align:center;">';
    echo '<button onclick="startRecoverySetup()" id="recovery-setup-btn" style="background:#dc3545;color:white;border:none;padding:15px 30px;font-size:18px;font-weight:bold;border-radius:4px;cursor:pointer;box-shadow:0 2px 4px rgba(0,0,0,0.2);">';
    echo '🔄 Setup Recovery Environment';
    echo '</button>';
    echo '<p style="margin:10px 0 0 0;color:#666;font-size:14px;">This process may take a moment to complete</p>';
    echo '</div>';
    echo '</div>';

    // Progress display area (initially hidden)
    echo '<div id="recovery-progress-container" style="display:none;margin:20px 0;">';
    echo '<div class="progress-container">';
    echo '<h3><span id="recovery-status-indicator" class="status-indicator status-processing">Setting up</span> Recovery Environment Setup Progress</h3>';
    echo '<div class="progress-bar"><div id="recovery-progress-fill" class="progress-fill" style="width:0%"></div></div>';
    echo '<div id="recovery-progress-text" class="progress-text">Initializing...</div>';
    echo '</div>';
    echo '<div id="recovery-progress-details" style="background:#f8f9fa;border:1px solid #dee2e6;padding:15px;border-radius:4px;margin:10px 0;"></div>';
    echo '</div>';

    // JavaScript for recovery setup using existing architecture
    echo '<script>
        function startRecoverySetup(event) {
            // Prevent any form submission or default behavior
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }

            console.log("Starting recovery setup...");
            const btn = document.getElementById("recovery-setup-btn");
            const progressContainer = document.getElementById("recovery-progress-container");
            const notice = document.querySelector(".recovery-notice");

            // Update UI
            btn.disabled = true;
            btn.textContent = "Setting up...";
            progressContainer.style.display = "block";
            notice.style.opacity = "0.5";

            // Start recovery setup using existing Clean Sweep AJAX architecture
            const formData = new FormData();
            formData.append("action", "setup_recovery");
            console.log("Sending AJAX request with action=setup_recovery");

            fetch("", {
                method: "POST",
                body: formData,
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                }
            })
            .then(response => {
                console.log("Response received:", response);
                console.log("Response status:", response.status);
                console.log("Response headers:", [...response.headers.entries()]);

                if (response.status === 403) {
                    throw new Error("Server firewall is blocking the request. Please check your security settings or contact your hosting provider.");
                }
                if (response.status === 500) {
                    throw new Error("Server error occurred. Please check server logs for details.");
                }
                if (!response.ok) {
                    throw new Error(`Server returned ${response.status}: ${response.statusText}`);
                }

                // Get response as text first to debug
                return response.text().then(text => {
                    console.log("Raw response text:", text.substring(0, 200) + "...");
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error("JSON parse error:", e);
                        console.error("Response was not valid JSON:", text);
                        throw new Error("Server returned invalid response (not JSON)");
                    }
                });
            })
            .then(data => {
                console.log("Parsed JSON response:", data);
                if (data.success) {
                    console.log("Setup successful, starting progress polling");
                    // Start polling for progress updates
                    startRecoveryProgressPolling();
                } else {
                    showRecoveryError("Setup failed: " + (data.error || "Unknown error"));
                }
            })
            .catch(error => {
                console.error("AJAX error:", error);
                showRecoveryError("Request failed: " + error.message);
            });
        }

        function startRecoveryProgressPolling() {
            const pollInterval = setInterval(() => {
                fetch("logs/recovery_setup.progress?t=" + Date.now())
                    .then(response => {
                        if (response.status === 404) return null;
                        return response.json();
                    })
                    .then(data => {
                        if (data) {
                            updateRecoveryProgress(data);

                            if (data.status === "complete") {
                                clearInterval(pollInterval);
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1000);
                            } else if (data.status === "error") {
                                clearInterval(pollInterval);
                                showRecoveryError("Setup failed: " + (data.details || "Unknown error"));
                            }
                        }
                    })
                    .catch(error => {
                        console.error("Polling error:", error);
                    });
            }, 1000);
        }

        function updateRecoveryProgress(data) {
            const progressFill = document.getElementById("recovery-progress-fill");
            const progressText = document.getElementById("recovery-progress-text");
            const statusIndicator = document.getElementById("recovery-status-indicator");

            if (progressFill) {
                progressFill.style.width = (data.progress || 0) + "%";
            }

            if (progressText && data.message) {
                progressText.textContent = data.message;
            }

            if (statusIndicator) {
                if (data.status === "complete") {
                    statusIndicator.className = "status-indicator status-completed";
                    statusIndicator.textContent = "Complete";
                } else if (data.status === "error") {
                    statusIndicator.className = "status-indicator status-error";
                    statusIndicator.textContent = "Failed";
                }
            }
        }

        function showRecoveryError(message) {
            const progressDetails = document.getElementById("recovery-progress-details");
            const btn = document.getElementById("recovery-setup-btn");

            if (progressDetails) {
                progressDetails.innerHTML = `<div style="color:#dc3545;font-weight:bold;">❌ ${message}</div>`;
            }

            if (btn) {
                btn.disabled = false;
                btn.textContent = "Retry Setup";
            }
        }
    </script>';
}

/**
 * Output HTML footer for browser execution
 */
function clean_sweep_output_html_footer() {
    if (!defined('WP_CLI') || !WP_CLI) {
        // Only show completion message if re-installation was actually performed
        if (isset($_POST['action']) && $_POST['action'] === 'reinstall_plugins') {
            echo '<hr><p><strong>Process completed.</strong> Check the log file for details: <code>' . LOGS_DIR . LOG_FILE . '</code></p>';
            echo '<p><strong>Backup location:</strong> <code>' . __DIR__ . '/' . BACKUP_DIR . '</code></p>';
            echo '<p><em>Remember to re-activate your plugins through the WordPress admin panel.</em></p>';
        }
        echo '</body></html>';
    }
}
