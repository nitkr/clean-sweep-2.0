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

function copyPluginList(type) {
    let pluginNames = [];
    const button = event.target;

    // Find all h3 and h4 elements
    const headings = document.querySelectorAll("h3, h4");

    if (type === "wordpress_org") {
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
    } else if (type === "wpmu_dev_regular" || type === "wpmudev") {
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
    } else if (type === "non_repository") {
        // Find the heading that contains "Non-Repository Plugins"
        let targetHeading = null;
        headings.forEach(function(heading) {
            if (heading.textContent.includes("Non-Repository Plugins")) {
                targetHeading = heading;
            }
        });

        if (targetHeading) {
            // Find the next div sibling and then the ul inside it
            const nextDiv = targetHeading.nextElementSibling;
            if (nextDiv && nextDiv.tagName === "DIV") {
                const listItems = nextDiv.querySelectorAll("ul li strong");
                listItems.forEach(function(item) {
                    // For non-repository plugins, just get the plugin name (before the " - " reason)
                    const fullText = item.textContent.trim();
                    const pluginName = fullText.split(' - ')[0]; // Remove reason part
                    pluginNames.push(pluginName);
                });
            }
        }
    } else if (type === "suspicious") {
        // Find the heading that contains "Suspicious Files & Folders"
        let targetHeading = null;
        headings.forEach(function(heading) {
            if (heading.textContent.includes("Suspicious Files & Folders")) {
                targetHeading = heading;
            }
        });

        if (targetHeading) {
            // Find the next div sibling and then the ul inside it
            const nextDiv = targetHeading.nextElementSibling;
            if (nextDiv && nextDiv.tagName === "DIV") {
                const listItems = nextDiv.querySelectorAll("ul li strong");
                listItems.forEach(function(item) {
                    // For suspicious files, just get the file name (first part before " - ")
                    const fullText = item.textContent.trim();
                    const fileName = fullText.split(' - ')[0]; // Remove type/size part
                    pluginNames.push(fileName);
                });
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
