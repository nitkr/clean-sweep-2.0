<p>
  <strong>Docs</strong>
  · <a href="README.md">Overview</a>
  · <a href="start.md">Start</a>
  · <a href="guide.md">Guide</a>
  · <a href="safety.md">Safety</a>
  · <a href="troubleshooting.md">Troubleshooting</a>
  · <a href="develop.md">Develop</a>
</p>

# Documentation

Clean Sweep is a drop-in WordPress recovery toolkit: copy the folder onto a site, work in the browser, then delete it.

This folder is the manual. The [root README](../README.md) is the short landing page (install in five steps, then jump here).

## Use it

<table>
  <tr>
    <td width="50%" valign="top">
      <h3><a href="start.md">Quick start</a></h3>
      <p>Put the folder next to <code>wp-config.php</code>, open it in the browser, keep Scanner open on long runs.</p>
    </td>
    <td width="50%" valign="top">
      <h3><a href="guide.md">Using Clean Sweep</a></h3>
      <p>Dashboard cards, sidebar tools, suggested cleanup path, and what each tool is for.</p>
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <h3><a href="safety.md">Safety</a></h3>
      <p>Backup first, how reinstalls behave, and why you must remove Clean Sweep when the site is stable.</p>
    </td>
    <td width="50%" valign="top">
      <h3><a href="troubleshooting.md">Troubleshooting</a></h3>
      <p>WordPress not found, scans that pause, reinstall failures, recovery mode, logs and backups.</p>
    </td>
  </tr>
</table>

## Change it

<table>
  <tr>
    <td width="50%" valign="top">
      <h3><a href="develop.md">Source tree &amp; UI build</a></h3>
      <p>What ships in the zip, how to rebuild <code>assets/dist/</code>, naming conventions.</p>
    </td>
    <td width="50%" valign="top">
      <h3><a href="../features/security/signatures/README.md">Signature packs</a></h3>
      <p>Authoring and sealing <code>current.csig</code>. Not needed to run a downloaded zip.</p>
    </td>
  </tr>
</table>

## What this is not

Clean Sweep is **not** a security plugin you leave installed. Findings are for inspection. File hits open in the editor. Package reinstall is the main cleanup action. There is no one-click quarantine of a single finding.
