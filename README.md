# Clean Sweep

A drop-in WordPress **malware cleanup toolkit**. Copy the folder onto an infected site, work from the browser, then delete Clean Sweep when the site is stable.

It is a recovery cockpit: scan files and the database, turn on live watch, audit users and cron, replace core / plugins / themes, seal integrity, then remove itself.

Version 2.0.

![Clean Sweep dashboard — last results, suggested cleanup path, and tools](demo.png)

<p align="center"><sub>Dashboard after a visit: last results, suggested cleanup path, Scanner / Security / Core / Extensions / Users / Cron in the sidebar.</sub></p>

---

## Contents

- [What it is](#what-it-is)
- [What it does](#what-it-does)
- [Suggested order](#suggested-order)
- [Quick start](#quick-start)
- [Requirements](#requirements)
- [Using the UI](#using-the-ui)
- [Recovery mode](#recovery-mode)
- [Safety](#safety)
- [Layout](#layout)
- [Frontend build](#frontend-build)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## What it is

Clean Sweep is **not** a security plugin you leave installed. It is a toolkit you drop next to `wp-config.php`, use until the site is clean, then **Remove Clean Sweep**.

Findings are for inspection. File hits open in the editor. Package reinstall is the main cleanup action. There is no one-click quarantine of a single finding.

Empty dashboard cards are normal: “Not run yet” means that tool has no last result to show. Malware and vulnerability checks restore the last run after a refresh.

---

## What it does

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

**Recovery mode.** If WordPress is too broken to load, Clean Sweep can bootstrap a clean environment and still run.

---

## Suggested order

Typical order on a compromised site. Skip steps that do not apply. The dashboard **Suggested cleanup path** follows this list.

1. Scan for malware and review hits (uploads and shells first; soft matches are often false positives).
2. Turn on live file watch so reinfection during reinstalls is visible.
3. Audit users and access.
4. Audit cron and persistence.
5. Check known vulnerabilities if you need CVE context before replacing packages.
6. Replace compromised core, plugins, and themes (or upload a clean ZIP).
7. Seal integrity after cleanup.
8. Remove Clean Sweep when the site is stable.

---

## Quick start

From this repository (Code → Download ZIP), or a clone:

1. Put the folder in the WordPress root, next to `wp-config.php`. Name it `clean-sweep/` (or keep the extracted folder name, as long as it sits in the root).
2. Open `https://yoursite.com/clean-sweep/` or `clean-sweep.php`. Use **`index.php`**, not the static `index.html`.
3. Start from the **dashboard**, or open Scanner / Users / Cron directly from the sidebar.
4. Keep the **Scanner** tab open during long scans so auto-resume can continue.
5. When finished, use **Remove Clean Sweep**.

The GitHub zip is a run tree: `assets/dist/` is already built. You do not need `npm` or `node_modules` to use it on a site.

---

## Requirements

- PHP 8.0 or newer
- WordPress 6.0 or newer
- Write access to the WordPress install
- Network access for WordPress.org (and WPMU DEV, if you reinstall those plugins)
- ZipArchive (or WordPress unzip) for package work

Shared hosting is supported. Quick and Standard use smaller batches and checkpoints. Deep scans on restricted hosts will pause often; keep the Scanner tab open so the run can continue.

---

## Using the UI

The screenshot above is the **dashboard**: last results (malware, vulnerabilities, users, cron, security), a suggested path, and the same tools as the left sidebar.

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

---

## Recovery mode

If core is so broken that Clean Sweep cannot bootstrap (missing `wp-settings.php`, unreadable `wp-config.php`), repair WordPress or restore a backup first. When bootstrap still works, Clean Sweep can run an isolated environment under `core/fresh/`.

---

## Safety

- Take a full site and database backup before you start.
- Prefer a staging copy when you can.
- Reinstalls back up first, then replace. Already-active plugins and themes stay active. New uploaded packages stay inactive.
- Change admin passwords and review users after cleanup.
- **Remove Clean Sweep when you are done.** Leaving it on a production site is a risk.

---

## Layout

```
clean-sweep/
├── clean-sweep.php          # Entry point
├── index.php                # Same as clean-sweep.php (use this, not index.html)
├── config.php
├── utils.php
├── ui.php                   # HTML shell for the built UI
├── wordpress-api.php
├── api/                     # JSON endpoints (malware, users, cron, …)
├── assets/dist/             # Built UI (clean-sweep.js / .css)
├── src/                     # Svelte UI source (for rebuilding the UI)
├── core/                    # Isolated bootstrap / recovery copies
├── features/
│   ├── maintenance/         # Core, plugin, theme reinstall
│   ├── security/            # Scanner, signatures, user/cron audits
│   └── utilities/           # Safe ZIP extract / install
├── includes/system/         # Runtime (visit engine, integrity, filesystem)
├── backups/                 # Operation backups and visit state (runtime)
└── logs/                    # Logs, progress, scan work queue (runtime)
```

PHP classes use the `CleanSweep_` prefix. Functions and constants use `clean_sweep_` / `CLEAN_SWEEP_`.

Runtime needs the sealed signature pack at `features/security/signatures/versions/current.csig`. Signature authoring (not in this zip) is documented in `features/security/signatures/README.md`.

---

## Frontend build

The browser loads `assets/dist/`. You only need this if you change the UI:

```bash
npm install
npm run build
```

`npm run dev` is Vite for local UI work. The PHP app still serves the built files.

---

## Troubleshooting

**Could not find WordPress / wp-load.php**  
The folder must sit in the WordPress root, next to `wp-config.php`.

**Directory not writable**  
The web user needs write access to `wp-content`, plugins, themes, and the Clean Sweep `backups/` and `logs/` folders.

**Scan pauses or the progress bar sits still**  
On shared hosts the scan yields on purpose. Keep the Scanner tab open. Use Resume if it stopped.

**Download or reinstall failed**  
Need outbound HTTPS to WordPress.org (and WPMU DEV if you use those plugins).

**Recovery setup never finishes**  
Check `logs/` and disk space. The fresh environment lives under `core/fresh/` and needs room to unpack WordPress.

Logs: `logs/clean-sweep-log-YYYY-MM-DD-HH-II-SS.txt`  
Operation backups: `backups/`  
Visit state: `backups/cs_visit_*.json`

---

## License

GNU General Public License version 2. See [LICENSE](LICENSE).
