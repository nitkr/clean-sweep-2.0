// Copy threat details to clipboard
function copyThreatDetails(button) {
    const pattern = button.getAttribute("data-pattern") || "";
    const file = button.getAttribute("data-file") || "";
    const section = button.getAttribute("data-section") || "";

    // Get threat details from the parent threat item
    const threatItem = button.closest(".threat-item");
    let threatDetails = "";

    if (threatItem) {
        // Extract threat information
        const category = threatItem.querySelector(".threat-header strong")?.textContent?.trim() || "";
        const patternText = threatItem.querySelector(".threat-pattern")?.textContent?.trim() || "";
        const detailsText = threatItem.querySelector(".threat-details")?.textContent?.trim() || "";

        // Format the details
        threatDetails = `Clean Sweep - Malware Threat Details
=====================================

Category: ${category}
Section: ${section}
Pattern: ${patternText}
Details: ${detailsText}`;

        if (file) {
            threatDetails += `\nFile: ${file}`;
        }
    } else {
        // Fallback format
        threatDetails = `Clean Sweep - Malware Threat Details
=====================================

Section: ${section}
Pattern: ${pattern}`;

        if (file) {
            threatDetails += `\nFile: ${file}`;
        }
    }

    // Copy to clipboard using modern clipboard API with fallback
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(threatDetails).then(function() {
            showCopyFeedback(button, "✅ Copied!", "#28a745");
        }).catch(function(err) {
            // Fall back to execCommand
            fallbackCopy(button, threatDetails);
        });
    } else {
        // Use fallback method immediately
        fallbackCopy(button, threatDetails);
    }
}

// Fallback copy method for older browsers
function fallbackCopy(button, text) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.left = "-9999px";
    textArea.style.opacity = "0";
    document.body.appendChild(textArea);
    textArea.select();

    try {
        const successful = document.execCommand("copy");
        if (successful) {
            showCopyFeedback(button, "✅ Copied!", "#28a745");
        } else {
            showCopyFeedback(button, "❌ Copy Failed", "#dc3545");
        }
    } catch (err) {
        showCopyFeedback(button, "❌ Copy Failed", "#dc3545");
    }

    document.body.removeChild(textArea);
}

// Show temporary visual feedback for copy action
function showCopyFeedback(button, message, color) {
    const originalText = button.textContent;
    const originalBackground = button.style.backgroundColor || "#6c757d";

    button.textContent = message;
    button.style.backgroundColor = color;

    // Reset button after 2 seconds
    setTimeout(() => {
        button.textContent = originalText;
        button.style.backgroundColor = originalBackground;
    }, 2000);
}

/**
 * Clean Sweep - UI Interactions
 * UI interaction functions for Clean Sweep
 */

// Threat timeline expand/collapse functionality
function toggleRiskLevel(riskLevel) {
    const content = document.getElementById("timeline-" + riskLevel);
    const header = content ? content.previousElementSibling : null;

    if (content) {
        if (content.style.display === "none") {
            content.style.display = "block";
            if (header) {
                const arrow = header.querySelector("span:first-child") || header.querySelector("span");
                if (arrow && arrow.style.float === "right") arrow.textContent = "▼";
            }
        } else {
            content.style.display = "none";
            if (header) {
                const arrow = header.querySelector("span:first-child") || header.querySelector("span");
                if (arrow && arrow.style.float === "right") arrow.textContent = "▶";
            }
        }
    } else {
        console.error('Timeline content not found for risk level:', riskLevel);
    }
}

// Toggle folder input on Files Only selection
function toggleFolderInput() {
    const folderInput = document.getElementById('folder-input-container');
    const filesRadio = document.querySelector('input[name="scan_type"][value="files"]');
    if (folderInput && filesRadio.checked) {
        folderInput.style.display = 'block';
    } else {
        folderInput.style.display = 'none';
    }
}

// Tab switching functionality
function switchTab(tabName) {
    // Hide all tab contents
    const tabContents = document.querySelectorAll(".tab-content");
    tabContents.forEach(function(content) {
        content.classList.remove("active");
    });

    // Remove active class from all tab buttons
    const tabButtons = document.querySelectorAll(".tab-button");
    tabButtons.forEach(function(button) {
        button.classList.remove("active");
    });

    // Show selected tab content
    const selectedTab = document.getElementById(tabName + "-tab");
    if (selectedTab) {
        selectedTab.classList.add("active");
    }

    // Add active class to selected tab button
    const selectedButton = document.querySelector("[onclick=\"switchTab(\'" + tabName + "\')\"]");
    if (selectedButton) {
        selectedButton.classList.add("active");
    }
}

// Initialize scan type change handlers
document.addEventListener('DOMContentLoaded', function() {
    // Show/hide deep scan options when scan type changes
    const scanRadios = document.querySelectorAll('input[name="scan_type"]');
    scanRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            const deepOptions = document.getElementById('deep-scan-options');
            if (deepOptions) {
                deepOptions.style.display = (this.value === 'deep') ? 'block' : 'none';
            }
        });
    });

    // Initialize with current selection (in case deep was pre-selected)
    const initialDeepRadio = document.querySelector('input[name="scan_type"][value="deep"]');
    const deepOptions = document.getElementById('deep-scan-options');
    if (initialDeepRadio && initialDeepRadio.checked && deepOptions) {
        deepOptions.style.display = 'block';
    }
});

// Malware scanning with AJAX progress tracking
let malwareProgressInterval = null;
let malwareProgressFile = null;

function startMalwareScan() {
    // Get selected scan type
    const scanRadios = document.querySelectorAll('input[name="scan_type"]');
    let scanType = 'all'; // default to complete scan

    scanRadios.forEach(function(radio) {
        if (radio.checked) {
            scanType = radio.value;
        }
    });

    // Get folder path if Files Only is selected
    let scanFolder = '';
    if (scanType === 'files') {
        scanFolder = document.getElementById('scan_folder').value.trim();
    }

    if (!confirm('Are you sure you want to start the malware scan? This may take several minutes depending on your site size.')) {
        return;
    }

    // Generate unique progress file name
    malwareProgressFile = 'malware_scan_' + Date.now() + '.progress';

    // Show progress container and hide the button
    document.getElementById('malware-progress-container').style.display = 'block';
    document.querySelector("[onclick='startMalwareScan()']").style.display = 'none';

    // Update initial status
    document.getElementById('malware-status-indicator').textContent = 'Starting Scan...';
    document.getElementById('malware-status-indicator').className = 'status-indicator status-processing';
    document.getElementById('malware-progress-text').textContent = 'Initializing malware scanner...';
    document.getElementById('malware-progress-fill').style.width = '0%';

    // Start progress polling after a small delay to ensure file is created
    setTimeout(() => {
        malwareProgressInterval = setInterval(pollMalwareProgress, 2000);
    }, 500);

    // Submit the request via AJAX
    const formData = new FormData();
    formData.append('action', 'scan_malware');
    formData.append('scan_type', scanType);
    formData.append('progress_file', malwareProgressFile);
    if (scanFolder) {
        formData.append('scan_folder', scanFolder);
    }

    // Include level scan checkbox value (crucial fix!)
    const levelScanCheckbox = document.getElementById('level-scan-toggle');
    if (levelScanCheckbox) {
        // Use '1' or '0' for consistency with other form data
        formData.append('level_scan', levelScanCheckbox.checked ? '1' : '0');
    }

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Check if response is JSON
        const contentType = response.headers.get("content-type");
        if (contentType && contentType.indexOf("application/json") !== -1) {
            return response.json();
        } else {
            // Not JSON, probably an error page
            return response.text().then(text => {
                const preview = text.substring(0, 500).replace(/\s+/g, ' ').trim();
                throw new Error('Server returned HTML instead of JSON. Error content: ' + preview + (text.length > 500 ? '...' : ''));
            });
        }
    })
    .then(data => {


        // Process completed - stop polling
        clearInterval(malwareProgressInterval);
        malwareProgressInterval = null;

        // Show results
        if (data.success && data.html) {
            // Update the security tab content with the rendered HTML
            const securityTab = document.getElementById('security-tab');
            if (securityTab) {
                securityTab.innerHTML = data.html;
            }

            // Hide the progress container
            const progressContainer = document.getElementById('malware-progress-container');
            if (progressContainer) {
                progressContainer.style.display = 'none';
            }
        } else if (data.error) {
            document.getElementById('malware-progress-details').innerHTML = '<div style="color:#dc3545;">Error: ' + data.error + '</div>';
            document.getElementById('malware-status-indicator').textContent = 'Error';
            document.getElementById('malware-status-indicator').className = 'status-indicator status-completed';
        } else {
            document.getElementById('malware-progress-details').innerHTML = '<div style="color:#dc3545;">Error: Failed to scan malware</div>';
            document.getElementById('malware-status-indicator').textContent = 'Error';
            document.getElementById('malware-status-indicator').className = 'status-indicator status-completed';
        }
    })
    .catch(error => {
        clearInterval(malwareProgressInterval);
        malwareProgressInterval = null;
        document.getElementById('malware-progress-details').innerHTML = '<div style="color:#dc3545;">Error: ' + error.message + '</div>';
        document.getElementById('malware-status-indicator').textContent = 'Error';
        document.getElementById('malware-status-indicator').className = 'status-indicator status-completed';
    });
}

function pollMalwareProgress() {
    if (!malwareProgressFile) return;

    // Progress files are stored in logs directory for web-accessibility
    fetch('logs/' + malwareProgressFile + '?t=' + Date.now())
        .then(response => {
            if (response.ok) {
                return response.text();
            }
            return null;
        })
        .then(text => {
            if (text) {
                try {
                    const data = JSON.parse(text);
                    updateMalwareProgress(data);
                } catch (e) {
                    // JSON parsing failed, file might not be complete yet
                }
            }
        })
        .catch(error => {
            // Progress file might not exist yet or network error, ignore
        });
}

function updateMalwareProgress(data) {
    const statusIndicator = document.getElementById('malware-status-indicator');
    const progressFill = document.getElementById('malware-progress-fill');
    const progressText = document.getElementById('malware-progress-text');
    const progressDetails = document.getElementById('malware-progress-details');

    if (data.status) {
        statusIndicator.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
    }

    if (data.progress !== undefined) {
        progressFill.style.width = data.progress + '%';
    }

    if (data.message) {
        progressText.textContent = data.message;
    }

    if (data.details) {
        progressDetails.innerHTML = data.details;
    }

    // Update status indicator class
    if (data.status === 'complete' || data.status === 'error') {
        statusIndicator.className = 'status-indicator status-completed';
        // Stop polling when complete
        clearInterval(malwareProgressInterval);
        malwareProgressInterval = null;
    }
}

// Threat result pagination for large scans with modern UI
let currentLoading = false;

// Load next page of threats
function loadNextThreatPage(requestId, page, perPage) {
    if (currentLoading) return;

    currentLoading = true;
    const btn = document.querySelector('button[onclick*="loadNextThreatPage"]');
    if (!btn) {
        console.error('Load next page button not found');
        currentLoading = false;
        return;
    }

    const originalText = btn.textContent;
    btn.textContent = '⏳ Loading Page ' + page + '...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'load_more_threats');
    formData.append('request_id', requestId);
    formData.append('page', page);
    formData.append('per_page', perPage);
    formData.append('progress_file', 'pagination_' + Date.now() + '.progress');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        const contentType = response.headers.get("content-type");
        if (contentType && contentType.indexOf("application/json") !== -1) {
            return response.json();
        } else {
            return response.text().then(text => {
                const preview = text.substring(0, 500).replace(/\s+/g, ' ').trim();
                throw new Error('Server returned HTML instead of JSON. Error content: ' + preview);
            });
        }
    })
    .then(data => {
        if (data.success) {
            // Insert new threats into the threat categories structure
            insertThreatsIntoCategories(data.html, requestId, page);

            // Update pagination UI
            updatePaginationUI(data, page, perPage, requestId);
        } else {
            showPaginationError('Error loading next page: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Pagination error:', error);
        showPaginationError('Error: ' + error.message);
        // Restore button on error
        btn.textContent = '❌ Error - Click to retry';
        setTimeout(() => {
            btn.textContent = originalText;
            btn.disabled = false;
        }, 3000);
    })
    .finally(() => {
        currentLoading = false;
    });
}

// Load all remaining threats
function loadAllRemainingThreats(requestId) {
    if (currentLoading) return;

    currentLoading = true;
    const btn = document.querySelector('button[onclick*="loadAllRemainingThreats"]');
    if (!btn) {
        console.error('Load all remaining button not found');
        currentLoading = false;
        return;
    }

    const originalText = btn.textContent;
    btn.textContent = '⏳ Loading All Remaining Threats...';
    btn.disabled = true;

    // Start with page 2 and load 100 threats per page until no more
    loadAllRemainingRecursive(requestId, 2, 100);
}

// Recursive function to load all remaining threats
function loadAllRemainingRecursive(requestId, page, perPage) {
    const formData = new FormData();
    formData.append('action', 'load_more_threats');
    formData.append('request_id', requestId);
    formData.append('page', page);
    formData.append('per_page', perPage);
    formData.append('progress_file', 'pagination_' + Date.now() + '.progress');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        const contentType = response.headers.get("content-type");
        if (contentType && contentType.indexOf("application/json") !== -1) {
            return response.json();
        } else {
            return response.text().then(text => {
                const preview = text.substring(0, 500).replace(/\s+/g, ' ').trim();
                throw new Error('Server returned HTML instead of JSON. Error content: ' + preview);
            });
        }
    })
    .then(data => {
        if (data.success) {
            // Insert new threats
            insertThreatsIntoCategories(data.html, requestId);

            if (data.has_more) {
                // Continue loading next page
                loadAllRemainingRecursive(requestId, page + 1, perPage);
            } else {
                // Finished loading all threats
                const btn = document.querySelector('button[onclick*="loadAllRemainingThreats"]');
                if (btn) {
                    btn.textContent = '✅ All Threats Loaded!';
                    btn.disabled = true;
                    btn.style.background = '#28a745';
                }

                // Update pagination info
                updatePaginationUI(data, page, perPage, requestId, true);

                // Show completion message
                const paginationContainer = document.getElementById('threat-pagination');
                if (paginationContainer) {
                    const completionMsg = document.createElement('div');
                    completionMsg.style.cssText = 'background:#d4edda;border:1px solid #c3e6cb;padding:15px;border-radius:8px;margin:15px 0;color:#155724;text-align:center;';
                    completionMsg.innerHTML = '<h5>🎉 All Threats Loaded!</h5><p style="margin:5px 0 0 0;">Complete threat analysis ready for review.</p>';
                    paginationContainer.appendChild(completionMsg);
                }
            }
        } else {
            // Stop loading on error
            console.error('Error loading page', page, ':', data.error);
            const btn = document.querySelector('button[onclick*="loadAllRemainingThreats"]');
            if (btn) {
                btn.textContent = '❌ Loading Stopped';
                btn.disabled = false;
            }
            showPaginationError('Stopped loading at page ' + page + ': ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Pagination error:', error);
        const btn = document.querySelector('button[onclick*="loadAllRemainingThreats"]');
        if (btn) {
            btn.textContent = '❌ Error Loading';
            btn.disabled = false;
        }
        showPaginationError('Error loading page ' + page + ': ' + error.message);
        currentLoading = false;
    });
}

// Insert threats into existing timeline structure - FIXED for <li> elements
function insertThreatsIntoCategories(htmlContent, requestId, page = 2) {
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = htmlContent;

    // Find the container for additional threats
    const additionalContainer = document.getElementById('additional-threats-container');
    if (!additionalContainer) {
        console.error('Additional threats container not found');
        return;
    }

    // Get all threat <li> elements from the response
    const threatItems = tempDiv.querySelectorAll('li');
    if (threatItems.length === 0) {
        console.error('No threat items found in HTML response');
        return;
    }

    // Create a wrapper for the new threats
    const newThreats_wrapper = document.createElement('div');
    newThreats_wrapper.style.cssText = 'background:#f8f9fa;border:1px solid #dee2e6;padding:20px;border-radius:8px;margin:30px 0;';
    newThreats_wrapper.innerHTML = `
        <h4 style="margin:0 0 20px 0;color:#495057;">📄 Additional Threats (Page ${page})</h4>
        <ul style="list-style:none;padding:0;margin:0;">
            ${Array.from(threatItems).map(li => li.outerHTML).join('')}
        </ul>
    `;

    // Append to the additional threats container
    additionalContainer.appendChild(newThreats_wrapper);

    // Update the "loaded X of Y" counter if present
    const paginationContainer = document.getElementById('threat-pagination');
    if (paginationContainer) {
        const statElement = Array.from(paginationContainer.querySelectorAll('strong')).find(el =>
            el.textContent.match(/^\d+$/)
        );
        if (statElement) {
            const newTotal = parseInt(statElement.textContent) + threatItems.length;
            statElement.textContent = newTotal;
        }
    }

    console.log(`✅ Appended ${threatItems.length} threat items to additional container`);
}

// Find existing threat presenter by risk level
function findExistingPresenter(riskLevel) {
    // Enhanced mapping to find the correct grid for each risk level
    const riskLevelHeaders = {
        'critical': ['🔴 Critical', 'critical'],
        'warning': ['🟡 Warning', 'warning'],
        'info': ['ℹ️ Info', 'info']
    };

    const presenters = document.querySelectorAll('.threat-grid');
    for (let presenter of presenters) {
        const header = presenter.previousElementSibling;
        if (header) {
            const headerText = header.textContent.toLowerCase();
            if (riskLevelHeaders[riskLevel]?.some(keyword =>
                headerText.includes(keyword.toLowerCase())
            )) {
                return presenter;
            }
        }
    }
    return null;
}

// Update pagination UI after loading
function updatePaginationUI(data, currentPage, perPage, requestId, allLoaded = false) {
    const paginationContainer = document.getElementById('threat-pagination');
    if (!paginationContainer) return;

    // Update page counter
    const pageInfo = paginationContainer.querySelector('div strong:first-child');
    if (pageInfo && data.total_loaded) {
        pageInfo.textContent = data.total_loaded;
    }

    // Update next page button
    const nextBtn = document.querySelector('button[onclick*="loadNextThreatPage"]');
    if (nextBtn) {
        if (allLoaded || !data.has_more) {
            nextBtn.style.display = 'none';
        } else {
            nextBtn.textContent = '▶️ Load Next Page';
            nextBtn.disabled = false;
        }
    }
}

// Show pagination error
function showPaginationError(message) {
    const paginationContainer = document.getElementById('threat-pagination');
    if (!paginationContainer) return;

    const errorDiv = document.createElement('div');
    errorDiv.style.cssText = 'background:#f8d7da;border:1px solid #f5c6cb;padding:15px;border-radius:8px;margin:15px 0;color:#721c24;text-align:center;';
    errorDiv.innerHTML = '<strong>❌ Error Loading Threats</strong><br>' + message;

    paginationContainer.appendChild(errorDiv);

    // Auto-hide error after 10 seconds
    setTimeout(() => {
        if (errorDiv.parentNode) {
            errorDiv.parentNode.removeChild(errorDiv);
        }
    }, 10000);
}
