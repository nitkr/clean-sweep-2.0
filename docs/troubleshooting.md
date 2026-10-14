<p align="center">
  <a href="README.md">Docs</a>
  ·
  <a href="start.md">Start</a>
  ·
  <a href="guide.md">Guide</a>
  ·
  <a href="safety.md">Safety</a>
  ·
  <strong>Troubleshooting</strong>
  ·
  <a href="develop.md">Develop</a>
</p>

# Troubleshooting

**Could not find WordPress / wp-load.php**  
The folder must sit in the WordPress root, next to `wp-config.php`.

**Directory not writable**  
The web user needs write access to `wp-content`, plugins, themes, and the Clean Sweep `backups/` and `logs/` folders.

**Scan pauses or the progress bar sits still**  
On shared hosts the scan yields on purpose. Keep the Scanner tab open. Use Resume if it stopped. For **AI scan**, keep that tab open instead. The browser calls the next short step. Closing it pauses at the last finished batch.

> [!NOTE]
> Restricted hosts pause in short slices. Leave the Scanner tab open so the run can continue. An AI scan uses the same signature engine, then keeps going only while its own tab is open.

**AI scan will not start**  
Save a provider, key, and model, then press **Test**. If a signature scan is already running, paused, or waiting, finish or cancel it on the Scanner page. Only one malware scan runs at a time.

**AI scan says the report was not produced**  
The key is missing, the signature threat file could not be read, or the send queue could not be built. A failure on one batch is stored as **Not decided** and the run continues. A missing key stops the run.

**Download or reinstall failed**  
Need outbound HTTPS to WordPress.org (and WPMU DEV if you use those plugins).

**Recovery setup never finishes**  
Check `logs/` and disk space. The fresh environment lives under `core/fresh/` and needs room to unpack WordPress.

## Recovery mode

If core is so broken that Clean Sweep cannot bootstrap (missing `wp-settings.php`, unreadable `wp-config.php`), repair WordPress or restore a backup first. When bootstrap still works, Clean Sweep can run an isolated environment under `core/fresh/`.

## Where to look

| What | Where |
| --- | --- |
| Logs | `logs/clean-sweep-log-YYYY-MM-DD-HH-II-SS.txt` |
| Operation backups | `backups/` |
| AI key, queue, and report | `backups/ai/` |
| Visit state | `backups/cs_visit_*.json` |
