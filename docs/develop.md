<p align="center">
  <a href="README.md">Docs</a>
  ·
  <a href="start.md">Start</a>
  ·
  <a href="guide.md">Guide</a>
  ·
  <a href="safety.md">Safety</a>
  ·
  <a href="troubleshooting.md">Troubleshooting</a>
  ·
  <strong>Develop</strong>
</p>

# Source tree and UI build

You only need this if you are changing Clean Sweep. A downloaded zip already includes the built UI.

## Layout

```
clean-sweep/
├── clean-sweep.php          # Entry point
├── index.php                # Same as clean-sweep.php (use this, not index.html)
├── config.php
├── utils.php
├── ui.php                   # HTML shell for the built UI
├── wordpress-api.php
├── api/                     # JSON endpoints (malware, ai, users, cron, …)
├── assets/dist/             # Built UI (clean-sweep.js / .css)
├── src/                     # Svelte UI source (for rebuilding the UI)
├── docs/                    # This manual
├── core/                    # Isolated bootstrap / recovery copies
├── features/
│   ├── ai/                  # AI scan queue, provider client, report
│   ├── maintenance/         # Core, plugin, theme reinstall
│   ├── security/            # Scanner, signatures, user/cron audits
│   └── utilities/           # Safe ZIP extract / install
├── includes/system/         # Runtime (visit engine, integrity, filesystem)
├── backups/                 # Operation backups and visit state (runtime)
└── logs/                    # Logs, progress, scan work queue (runtime)
```

PHP classes use the `CleanSweep_` prefix. Functions and constants use `clean_sweep_` / `CLEAN_SWEEP_`.

Runtime needs the sealed signature pack at `features/security/signatures/versions/current.csig`. Signature authoring is documented in [`features/security/signatures/README.md`](../features/security/signatures/README.md).

AI scan is `features/ai/` plus `api/ai.php`. The page starts a Standard malware scan with `initiated_by` set to `ai_scan`, then calls `api/ai.php` action `start` once per short step. The provider key, skip list, and report are written under `backups/ai/` while the toolkit is in use. They are not part of the zip. The Scanner page ignores runs marked `ai_scan`.

## Frontend build

The browser loads `assets/dist/`. You only need this if you change the UI:

```bash
npm install
npm run build
```

`npm run dev` is Vite for local UI work. The PHP app still serves the built files.
