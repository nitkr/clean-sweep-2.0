// Clean Sweep - Core Utilities
// Core utility functions for Clean Sweep

let autoScrollEnabled = true;

function updateProgress(current, total, status) {
    const progressBar = document.getElementById("progress-fill");
    const progressText = document.getElementById("progress-text");
    const statusIndicator = document.getElementById("status-indicator");

    if (statusIndicator) {
        statusIndicator.textContent = status;
        const isComplete = (total > 0 && current >= total) || status.toLowerCase().includes("complete");
        statusIndicator.className = "status-indicator " + (isComplete ? "status-completed" : "status-processing");
    }

    if (total > 0) {
        const percentage = Math.round((current / total) * 100);
        if (progressBar) progressBar.style.width = percentage + "%";
        if (progressText) progressText.textContent = current + "/" + total + " (" + percentage + "%)";
    } else {
        if (progressBar) progressBar.style.width = "0%";
        if (progressText) progressText.textContent = status;
    }

    // Auto-scroll to follow progress
    if (autoScrollEnabled) {
        scrollToBottom();
    }
}

function scrollToBottom() {
    window.scrollTo({
        top: document.body.scrollHeight,
        behavior: "smooth"
    });
}

function scrollToResults() {
    const resultsSection = document.querySelector("h2");
    if (resultsSection && resultsSection.textContent.includes("Final Reinstallation Results")) {
        resultsSection.scrollIntoView({
            behavior: "smooth",
            block: "start"
        });
    }
}

// Auto-scroll when new content is added
const observer = new MutationObserver(function(mutations) {
    if (autoScrollEnabled) {
        // Small delay to ensure content is rendered
        setTimeout(scrollToBottom, 100);
    }

    // Check if results section has loaded
    mutations.forEach(function(mutation) {
        mutation.addedNodes.forEach(function(node) {
            if (node.nodeType === Node.ELEMENT_NODE) {
                if (node.querySelector && node.querySelector("h2") &&
                    node.querySelector("h2").textContent.includes("Final Reinstallation Results")) {
                    setTimeout(scrollToResults, 500);
                } else if (node.tagName === "H2" &&
                         node.textContent.includes("Final Reinstallation Results")) {
                    setTimeout(scrollToResults, 500);
                }
            }
        });
    });
});

// Start observing when page loads
document.addEventListener("DOMContentLoaded", function() {
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
});

// Disable auto-scroll if user manually scrolls up
let scrollTimeout;
window.addEventListener("scroll", function() {
    clearTimeout(scrollTimeout);
    const currentScroll = window.pageYOffset;
    const maxScroll = document.body.scrollHeight - window.innerHeight;

    // If user scrolled up (not at bottom), disable auto-scroll temporarily
    if (currentScroll < maxScroll - 100) {
        autoScrollEnabled = false;
        scrollTimeout = setTimeout(function() {
            autoScrollEnabled = true;
        }, 3000); // Re-enable after 3 seconds of no scrolling
    }
});

// Copy to clipboard functionality
function copyToClipboard(text, buttonElement) {
    // Store original background for proper reset
    const originalBackground = buttonElement.style.backgroundColor || window.getComputedStyle(buttonElement).backgroundColor;

    navigator.clipboard.writeText(text).then(function() {
        // Show success feedback
        const originalText = buttonElement.textContent;
        buttonElement.textContent = "OK";
        buttonElement.style.background = "#28a745";

        // Reset after 2 seconds
        setTimeout(function() {
            buttonElement.textContent = originalText;
            buttonElement.style.backgroundColor = originalBackground;
        }, 2000);
    }).catch(function(err) {
        console.error("Failed to copy: ", err);
        // Fallback for older browsers
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand("copy");
            const originalText = buttonElement.textContent;
            buttonElement.textContent = "Copied";
            buttonElement.style.background = "#28a745";
            setTimeout(function() {
                buttonElement.textContent = originalText;
                buttonElement.style.backgroundColor = originalBackground;
            }, 2000);
        } catch (fallbackErr) {
            buttonElement.textContent = "Failed to copy";
            buttonElement.style.background = "#dc3545";
            setTimeout(function() {
                buttonElement.textContent = "Copy";
                buttonElement.style.backgroundColor = originalBackground;
            }, 2000);
        }
        document.body.removeChild(textArea);
    });
}

let pendingReinstallationData = null;

function confirmPluginReinstallation(button) {
    const pluginsData = JSON.parse(button.getAttribute('data-plugins'));
    const createBackup = document.getElementById('create-backup-checkbox').checked;

    // Store the data for later use
    pendingReinstallationData = {
        plugins: pluginsData,
        createBackup: createBackup,
        button: button
    };

    // Populate confirmation dialog
    const confirmationDetails = document.getElementById('confirmation-details');
    const wordpressOrgCount = Object.keys(pluginsData).filter(slug => pluginsData[slug] && !pluginsData[slug].wdp_id).length;
    const wpmuDevCount = Object.keys(pluginsData).filter(slug => pluginsData[slug] && pluginsData[slug].wdp_id).length;

    confirmationDetails.innerHTML = `
        <div style="background:#f8f9fa;padding:15px;border-radius:6px;margin-bottom:15px;">
            <h4 style="margin:0 0 10px 0;color:#2c3e50;">📦 Reinstallation Summary</h4>
            <ul style="margin:0;padding-left:20px;">
                <li><strong>${wordpressOrgCount}</strong> plugins from WordPress.org repository</li>
                <li><strong>${wpmuDevCount}</strong> plugins from WPMU DEV secured network</li>
            </ul>
        </div>
        <div style="background:#fff3cd;padding:15px;border-radius:6px;border:1px solid #ffeaa7;">
            <h4 style="margin:0 0 10px 0;color:#856404;">⚠️ Important Warning</h4>
            <p style="margin:0;color:#856404;">This action will replace all selected plugins with fresh versions from their official sources. This process cannot be undone.</p>
            <p style="margin:5px 0 0 0;color:#856404;"><strong>Next Step:</strong> You will be asked whether to create a backup before proceeding.</p>
        </div>
    `;

    // Show confirmation dialog
    document.getElementById('backup-confirmation-dialog').style.display = 'flex';
}

function proceedWithReinstallation() {
    if (!pendingReinstallationData) return;

    const { plugins, button } = pendingReinstallationData;

    // Hide confirmation dialog
    document.getElementById('backup-confirmation-dialog').style.display = 'none';

    // Start the progress and show backup choice dialog
    startPluginReinstallationWithBackupChoice(button, plugins);

    // Clear pending data
    pendingReinstallationData = null;
}

function cancelReinstallation() {
    document.getElementById('backup-confirmation-dialog').style.display = 'none';
    pendingReinstallationData = null;
}

function startPluginReinstallationWithBackupChoice(button, plugins) {
    const progressContainer = document.getElementById("plugin-progress-container");
    const progressFill = document.getElementById("plugin-progress-fill");
    const progressText = document.getElementById("plugin-progress-text");
    const statusIndicator = document.getElementById("plugin-status-indicator");
    const progressDetails = document.getElementById("plugin-progress-details");

    // Update UI
    button.disabled = true;
    button.textContent = "Starting...";
    progressContainer.style.display = "block";

    // Show initial progress
    if (progressFill) progressFill.style.width = "10%";
    if (progressText) progressText.textContent = "Initializing plugin re-installation...";
    if (statusIndicator) {
        statusIndicator.className = "status-indicator status-processing";
        statusIndicator.textContent = "Initializing";
    }

    // Show backup choice dialog after a short delay
    setTimeout(() => {
        showBackupChoiceDialog(button, plugins);
    }, 1500);
}

function showBackupChoiceDialog(button, plugins) {
    const progressDetails = document.getElementById("plugin-progress-details");

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
                <button onclick="chooseBackup(true, this)" style="background: #28a745; color: white; border: none; padding: 12px 25px; border-radius: 4px; cursor: pointer; font-weight: 600;">✅ Create Backup & Continue</button>
                <button onclick="chooseBackup(false, this)" style="background: #ffc107; color: #212529; border: none; padding: 12px 25px; border-radius: 4px; cursor: pointer; font-weight: 600;">⚠️ Skip Backup & Continue</button>
                <button onclick="cancelBackupChoice()" style="background: #6c757d; color: white; border: none; padding: 12px 25px; border-radius: 4px; cursor: pointer; font-weight: 600;">❌ Cancel</button>
            </div>
        </div>
    `;

    document.body.appendChild(backupDialog);

    // Store plugins data for later use
    backupDialog.dataset.plugins = JSON.stringify(plugins);
    backupDialog.dataset.buttonId = button.id || 'reinstall-button';
}

function chooseBackup(createBackup, buttonElement) {
    const backupDialog = document.getElementById('backup-choice-dialog');
    const plugins = JSON.parse(backupDialog.dataset.plugins);
    const buttonId = backupDialog.dataset.buttonId;
    const originalButton = document.getElementById(buttonId) || document.querySelector('button[onclick*="confirmPluginReinstallation"]');

    // Remove backup dialog
    backupDialog.remove();

    // Update progress to show backup choice
    const progressText = document.getElementById("plugin-progress-text");
    if (progressText) {
        progressText.textContent = createBackup ? "Creating backup before proceeding..." : "Skipping backup as requested...";
    }

    // Start the actual reinstallation process
    startPluginReinstallation(originalButton, plugins, createBackup);
}

function cancelBackupChoice() {
    const backupDialog = document.getElementById('backup-choice-dialog');
    const buttonId = backupDialog.dataset.buttonId;
    const originalButton = document.getElementById(buttonId) || document.querySelector('button[onclick*="confirmPluginReinstallation"]');

    // Remove backup dialog
    backupDialog.remove();

    // Reset button
    const progressContainer = document.getElementById("plugin-progress-container");
    if (originalButton) {
        originalButton.disabled = false;
        originalButton.textContent = "🚀 Start Complete Ecosystem Re-installation";
    }
    if (progressContainer) {
        progressContainer.style.display = "none";
    }
}

function copyPluginList(type) {
    let pluginNames = [];
    const button = event.target;

    // Find all h3 and h4 elements
    const headings = document.querySelectorAll("h3, h4");

    if (type === "reinstall") {
        // Find the heading that contains "WordPress.org Plugins to be Re-installed"
        let targetHeading = null;
        headings.forEach(function(heading) {
            if (heading.textContent.includes("WordPress.org Plugins to be Re-installed")) {
                targetHeading = heading;
            }
        });

        if (targetHeading) {
            // Find the next div sibling (table container) and specifically get strong elements from table cells only
            const nextDiv = targetHeading.nextElementSibling;
            if (nextDiv && nextDiv.tagName === "DIV") {
                // Get only strong elements that are direct children of td elements in this specific section
                const table = nextDiv.querySelector("table");
                if (table) {
                    const tableRows = table.querySelectorAll("tbody tr");
                    tableRows.forEach(function(row) {
                        const firstTd = row.querySelector("td:first-child");
                        if (firstTd) {
                            const strongElement = firstTd.querySelector("strong");
                            if (strongElement) {
                                const fullText = strongElement.textContent.trim();
                                const pluginName = fullText.split(' (')[0]; // Remove slug part
                                pluginNames.push(pluginName);
                            }
                        }
                    });
                }
            }
        }
    } else if (type === "wpmudev") {
        // Find the heading that contains "WPMU DEV Premium Plugins to be Re-installed"
        let targetHeading = null;
        headings.forEach(function(heading) {
            if (heading.textContent.includes("WPMU DEV Premium Plugins to be Re-installed")) {
                targetHeading = heading;
            }
        });

        if (targetHeading) {
            // Find the next div sibling (table container) and specifically get strong elements from table cells only
            const nextDiv = targetHeading.nextElementSibling;
            if (nextDiv && nextDiv.tagName === "DIV") {
                // Get only strong elements that are direct children of td elements in the table, exclude security notice
                const table = nextDiv.querySelector("table");
                if (table) {
                    const tableRows = table.querySelectorAll("tbody tr");
                    tableRows.forEach(function(row) {
                        const firstTd = row.querySelector("td:first-child");
                        if (firstTd) {
                            const strongElement = firstTd.querySelector("strong");
                            if (strongElement) {
                                const fullText = strongElement.textContent.trim();
                                const pluginName = fullText.split(' (')[0]; // Remove slug part
                                pluginNames.push(pluginName);
                            }
                        }
                    });
                }
            }
        }
    } else if (type === "nonrepo") {
        // Find the heading that contains "Non-Repository Plugins"
        let targetHeading = null;
        headings.forEach(function(heading) {
            if (heading.textContent.includes("Non-Repository Plugins")) {
                targetHeading = heading;
            }
        });

        if (targetHeading) {
            // Find the next div sibling (table container) and get strong elements from table cells
            const nextDiv = targetHeading.nextElementSibling;
            if (nextDiv && nextDiv.tagName === "DIV") {
                const table = nextDiv.querySelector("table");
                if (table) {
                    const tableRows = table.querySelectorAll("tbody tr");
                    tableRows.forEach(function(row) {
                        const firstTd = row.querySelector("td:first-child");
                        if (firstTd) {
                            const strongElement = firstTd.querySelector("strong");
                            if (strongElement) {
                                const pluginName = strongElement.textContent.trim();
                                pluginNames.push(pluginName);
                            }
                        }
                    });
                }
            }
        }
    } else if (type === "suspicious") {
        // Find the heading that contains "Suspicious Files Detected"
        let targetHeading = null;
        headings.forEach(function(heading) {
            if (heading.textContent.includes("Suspicious Files Detected")) {
                targetHeading = heading;
            }
        });

        if (targetHeading) {
            // Find the next div sibling (table container) and extract file names
            const nextDiv = targetHeading.nextElementSibling;
            if (nextDiv && nextDiv.tagName === "DIV") {
                const table = nextDiv.querySelector("table");
                if (table) {
                    const tableRows = table.querySelectorAll("tbody tr");
                    tableRows.forEach(function(row) {
                        const firstTd = row.querySelector("td:first-child");
                        if (firstTd) {
                            const strongElement = firstTd.querySelector("strong");
                            if (strongElement) {
                                const fileName = strongElement.textContent.trim();
                                pluginNames.push(fileName);
                            }
                        }
                    });
                }
            }
        }
    } else if (type === "skipped") {
        // Find the heading that contains "Plugins to be Skipped" (could be h3 or h4)
        let targetHeading = null;
        headings.forEach(function(heading) {
            if (heading.textContent.includes("Plugins to be Skipped")) {
                targetHeading = heading;
            }
        });

        if (targetHeading) {
            // Find the next div sibling and then the ul inside it (skipped uses ul/li structure)
            const nextDiv = targetHeading.nextElementSibling;
            if (nextDiv && nextDiv.tagName === "DIV") {
                const listItems = nextDiv.querySelectorAll("ul li strong");
                listItems.forEach(function(item) {
                    // For skipped plugins, just get the plugin name (before the " - " reason)
                    const fullText = item.textContent.trim();
                    const pluginName = fullText.split(' - ')[0]; // Remove reason part
                    pluginNames.push(pluginName);
                });
            }
        }
    }

    const textToCopy = pluginNames.join("\n");
    copyToClipboard(textToCopy, button);
}
