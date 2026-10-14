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

## AI scan

AI scan sends file excerpts to the provider you choose (SpaceXAI, OpenAI, or OpenRouter). Clean Sweep cannot check whether that provider stores the text.

- Database row text is not sent. Rows are signature-checked on this server.
- Secret files stay on this server. That includes `wp-config.php`, `.env`, keys, SQL dumps, and common backup-plugin folders. They are sent only if you turn on the full pass, or press **Send this secret** for one file after the run.
- A path you add under **Also skip these paths** is never sent, including during a full pass. The signature pass still reads it.
- Password-shaped values in code that is sent are replaced with `[withheld]` unless the full pass is on.
- The API key is stored in `backups/ai/config.json` inside the toolkit. **Remove Clean Sweep** deletes that folder from the server. The provider may already have the excerpts from the run.

Drop it next to `wp-config.php`, use it until the site is clean, then **Remove Clean Sweep**.
