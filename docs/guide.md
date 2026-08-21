<p>
  <a href="README.md">Docs</a>
  · <a href="start.md">Start</a>
  · <strong>Guide</strong>
  · <a href="safety.md">Safety</a>
  · <a href="troubleshooting.md">Troubleshooting</a>
  · <a href="develop.md">Develop</a>
</p>

# Using Clean Sweep

The screenshot on the [landing page](../README.md) is the **dashboard**: last results, a suggested cleanup path, and the same tools as the left sidebar.

## Sidebar

| Sidebar | Use it for |
| --- | --- |
| **Dashboard** | Status of last runs and where to go next |
| **Scanner** | Malware scan + vulnerability check |
| **Security** | Live watch, snapshots, seal integrity |
| **Core files** | Reinstall WordPress core |
| **Extensions** | Analyze / reinstall plugins and themes |
| **Upload** | Install a clean ZIP |
| **Users** | Access audit |
| **Cron** | Scheduled-task audit |
| **Remove Clean Sweep** | Delete the toolkit when done |

Scanner results restore after a page refresh (malware via last scan, vulnerabilities for 48 hours). Users and Cron cards are from the current session until you run those tools again.

## Tools

| Area | Tool | What you get |
| --- | --- | --- |
| Scan | **Scanner** | Signature scan of files and the database, WordPress.org checksums, package verification. Profiles: Quick, Standard (default), Deep. Pause / resume on shared hosts. Review hits on the same screen. |
| Scan | **Vulnerabilities** | Separate WPVulnerability.com check for known CVEs. Use it to decide what to replace, not as a malware verdict. Last check restores after refresh. |
| Watch | **Security** | Live file watch (optional must-use agent), snapshots, integrity seal after cleanup. |
| Replace | **Core files** | Reinstall WordPress core. Keeps `wp-config.php` and `wp-content`. |
| Replace | **Extensions** | Analyze plugins/themes. Reinstall from WordPress.org or WPMU DEV (backup first). Flags likely fake / impersonating packages (stolen .org slug or decoy plugin). Already-active packages stay active. |
| Replace | **Upload** | Upload a clean ZIP to plugins, themes, uploads, or a custom path. New uploaded packages stay inactive. |
| Audit | **Users** | Hidden admins, weak hashes, application passwords, sessions. Demote, delete, revoke app passwords, destroy sessions. |
| Audit | **Cron** | WP-Cron and Action Scheduler. Delete events, clear hooks, cancel Action Scheduler jobs. |
| Done | **Remove Clean Sweep** | Deletes the toolkit folder, live-watch agent, visit data, and scan cron leftovers. |

**Recovery mode.** If WordPress is too broken to load, Clean Sweep can bootstrap a clean environment and still run. See [Troubleshooting](troubleshooting.md#recovery-mode).

## Suggested cleanup path

Typical order on a compromised site. Skip steps that do not apply. The dashboard **Suggested cleanup path** follows this list.

1. Scan for malware and review hits (uploads and shells first; soft matches are often false positives).
2. Turn on live file watch so reinfection during reinstalls is visible.
3. Audit users and access.
4. Audit cron and persistence.
5. Check known vulnerabilities if you need CVE context before replacing packages.
6. Replace compromised core, plugins, and themes (or upload a clean ZIP).
7. Seal integrity after cleanup.
8. Remove Clean Sweep when the site is stable.
