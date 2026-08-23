<p align="center">
  <a href="README.md">Docs</a>
  ·
  <a href="start.md">Start</a>
  ·
  <a href="guide.md">Guide</a>
  ·
  <strong>Safety</strong>
  ·
  <a href="troubleshooting.md">Troubleshooting</a>
  ·
  <a href="develop.md">Develop</a>
</p>

# Safety

> [!WARNING]
> Take a full site and database backup before you start. Prefer a staging copy when you can.

> [!IMPORTANT]
> **Remove Clean Sweep when you are done.** Leaving it on a production site is a risk. It is not a plugin you leave installed.

- Reinstalls back up first, then replace. Already-active plugins and themes stay active. New uploaded packages stay inactive.
- Change admin passwords and review users after cleanup.

Drop it next to `wp-config.php`, use it until the site is clean, then **Remove Clean Sweep**.
