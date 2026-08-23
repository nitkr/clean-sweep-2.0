<p align="center">
  <a href="README.md">Docs</a>
  ·
  <strong>Start</strong>
  ·
  <a href="guide.md">Guide</a>
  ·
  <a href="safety.md">Safety</a>
  ·
  <a href="troubleshooting.md">Troubleshooting</a>
  ·
  <a href="develop.md">Develop</a>
</p>

# Quick start

The GitHub zip is a run tree: `assets/dist/` is already built. You do not need `npm` or `node_modules` on the site.

## Install

From this repository (**Code → Download ZIP**) or a clone:

1. Put the folder in the WordPress root, next to `wp-config.php`. Name it `clean-sweep/` (or keep the extracted folder name, as long as it sits in the root).
2. Open `https://yoursite.com/clean-sweep/` or `clean-sweep.php`. Use **`index.php`**, not the static `index.html`.
3. Start from the **dashboard**, or open Scanner / Users / Cron from the sidebar.
4. Keep the **Scanner** tab open during long scans so auto-resume can continue.
5. When finished, use **Remove Clean Sweep**.

> [!WARNING]
> Read [Safety](safety.md) before you start on a live site. Backup first.

## Requirements

- PHP 8.0 or newer
- WordPress 6.0 or newer
- Write access to the WordPress install
- Network access for WordPress.org (and WPMU DEV, if you reinstall those plugins)
- ZipArchive (or WordPress unzip) for package work

Shared hosting is supported. Quick and Standard use smaller batches and checkpoints. Deep scans on restricted hosts will pause often; keep the Scanner tab open so the run can continue.

## After it is open

Empty dashboard cards are normal: **Not run yet** means that tool has no last result to show. Malware and vulnerability checks restore the last run after a refresh.

Next: [Using Clean Sweep](guide.md).
