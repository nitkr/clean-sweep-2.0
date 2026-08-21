<script>
  import { integrity } from '../../lib/stores/integrity.js';
  import { onMount } from 'svelte';
  import { Button } from 'bits-ui';
  import ConfirmDialog from '../common/ConfirmDialog.svelte';

  onMount(() => {
    integrity.loadBaselineInfo();
  });

  let status = $derived($integrity.visitStatus || $integrity.baselineInfo || {});
  let secret = $derived($integrity.lastSecret);
  let error = $derived($integrity.error);
  let importing = $derived(!!$integrity.importing);
  let showDiag = $state(false);
  let showHowItWorks = $state(false);
  let diagFilter = $state('all');
  let importSecret = $state('');
  let importText = $state('');
  let importFile = $state(null);
  let liveWatchBusy = $derived(!!$integrity.liveWatchBusy);

  /** Snapshot compare list expanders */
  let showAllTamper = $state(false);
  let showAllDrift = $state(false);
  let showAllSuspects = $state(false);

  /** In-app dialogs (no window.confirm / alert) */
  let dialog = $state({
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    variant: 'primary',
    alertOnly: false,
    onConfirm: undefined,
  });

  function openDialog(opts) {
    dialog = {
      open: true,
      title: opts.title || 'Confirm',
      message: opts.message || '',
      confirmLabel: opts.confirmLabel || 'Confirm',
      cancelLabel: opts.cancelLabel || 'Cancel',
      variant: opts.variant || 'primary',
      alertOnly: !!opts.alertOnly,
      onConfirm: opts.onConfirm,
    };
  }

  function closeDialog() {
    dialog = { ...dialog, open: false, onConfirm: undefined };
  }

  $effect(() => {
    try {
      showDiag = sessionStorage.getItem('cs_visit_diag') === '1';
    } catch (_) { /* ignore */ }
  });

  function toggleDiag() {
    showDiag = !showDiag;
    try {
      sessionStorage.setItem('cs_visit_diag', showDiag ? '1' : '0');
    } catch (_) { /* ignore */ }
  }

  function chip(ok, label) {
    return { ok, label };
  }

  let watchTotal = $derived(
    (status.watch_counts?.extra_php || 0)
    + (status.watch_counts?.uploads || 0)
    + (status.watch_counts?.site_owned || 0)
    + (status.watch_counts?.wp_content || 0)
  );

  let chips = $derived([
    chip(status?.toolkit?.ok !== false && status?.toolkit?.kind !== 'extra' && status?.toolkit?.kind !== 'patched', 'Clean Sweep'),
    chip(!!status.core_sealed, status.core_sealed ? `Core sealed (${status.core_file_count || 0} files)` : 'Core not sealed'),
    chip((status.packages_sealed_count || 0) > 0, (status.packages_sealed_count || 0) > 0
      ? `${status.packages_sealed_count} package(s) sealed`
      : 'Plugins/themes not sealed'),
    chip(watchTotal > 0, watchTotal > 0
      ? `Census ${watchTotal} extra files`
      : 'Extra-file census not recorded yet'),
    chip(!!status.live_watch_enabled && !!status.live_watch_agent, status.live_watch_enabled
      ? (status.live_watch_agent
          ? `Live watch on (${status.live_watch_paths || 0} paths)`
          : 'Live watch enabled (agent missing)')
      : 'Live watch off'),
    chip(!!status.snapshot_downloaded || !!status.snapshot_imported, status.snapshot_imported
      ? 'Snapshot imported'
      : (status.snapshot_downloaded ? 'Snapshot downloaded' : 'No snapshot yet')),
    chip(!!status.visit_watch && !status.journal_tamper, status.journal_tamper
      ? 'Visit journal tampered'
      : (status.visit_watch ? 'Visit journal active' : 'Visit journal starting')),
  ]);

  function eventText(ev) {
    if (ev?.text) return ev.text;
    const code = ev?.code || '';
    const d = (ev?.detail || '').trim();
    if (code.startsWith('sealed:theme:')) {
      return `Trusted theme "${code.slice('sealed:theme:'.length)}" after reinstall${d ? ` (${d} hashed)` : ''}`;
    }
    if (code.startsWith('sealed:plugin:')) {
      return `Trusted plugin "${code.slice('sealed:plugin:'.length)}" after reinstall${d ? ` (${d} hashed)` : ''}`;
    }
    const map = {
      'self-check:ok': 'Clean Sweep files match the copy that shipped in the zip',
      'self-check:extra': `Unexpected file inside Clean Sweep${d ? `: ${d}` : ''}. Reinstall/export paused`,
      'self-check:patched': `A shipped Clean Sweep file no longer matches the zip${d ? `: ${d}` : ''}`,
      'sealed:core': `Trusted WordPress core after reinstall${d ? ` (${d} hashed)` : ''}`,
      'action:seal_core': `Core reinstall recorded${d ? ` (${d})` : ''}`,
      'census:site-owned': `Watching site-owned files (wp-config, drop-ins, root extras)${d ? `: ${d}` : ''}`,
      'census:extra-php': `Watching PHP files in plugins and themes${d ? `: ${d}` : ''}`,
      'census:uploads': `Watching uploads (PHP, configs, PHP-in-images)${d ? `: ${d} files` : ''}`,
      'census:wp-content': `Watching other wp-content files (cache, upgrade, loose PHP)${d ? `: ${d}` : ''}`,
      'census:options': `Recorded WordPress options for later compare${d ? `: ${d}` : ''}`,
      'snapshot:downloaded': `Snapshot downloaded. Save the one-time secret${d ? `. ${d}` : ''}`,
      'snapshot:imported': `Imported last snapshot and compared this site${d ? `. ${d}` : ''}`,
      'media:all': 'Uploads watch now includes all media files (large)',
      'media:suspects': 'Uploads watch is PHP, configs, and PHP-in-images only',
      'unexpected:created': `New file since last watch${d ? `: ${d}` : ''}`,
      'unexpected:modified': `File content changed since last watch${d ? `: ${d}` : ''}`,
      'unexpected:deleted': `Watched file is gone${d ? `: ${d}` : ''}`,
      'unexpected:option': `WordPress option changed${d ? `: ${d}` : ''}`,
      'watch:enabled': `Live file watch enabled${d ? ` (${d})` : ''}`,
      'watch:rebased': `Live watch pinned to the live site${d ? `. ${d}` : ''}. Recovery core/fresh is not watched`,
      'watch:disabled': 'Live file watch disabled; must-use agent removed',
      'watch:operation': `Live watch: expected site writes from Clean Sweep${d ? ` (${d})` : ''}`,
      'watch:modified': `Live watch: file changed${d ? `: ${d}` : ''}`,
      'watch:created': `Live watch: new high-value file${d ? `: ${d}` : ''}`,
      'watch:already_present': `Live watch: file was already on disk${d ? `: ${d}` : ''}`,
      'watch:deleted': `Live watch: high-value file removed${d ? `: ${d}` : ''}`,
    };
    if (map[code]) return map[code];
    if (code.startsWith('unexpected:')) return `Unexpected change${d ? `: ${d}` : ''}`;
    return d ? `${code}: ${d}` : code;
  }

  function isProblemEvent(ev) {
    return /tamper|unexpected|patched|extra|error|drift|modified|created|deleted|gone/i.test(ev?.code || ev?.text || '');
  }

  function bucketLabel(bucket) {
    if (bucket === 'uploads') return 'uploads';
    if (bucket === 'extra_php') return 'plugin/theme PHP';
    if (bucket === 'site_owned') return 'site-owned';
    if (bucket === 'wp_content') return 'wp-content';
    return bucket || '';
  }

  async function onImportFile(ev) {
    const file = ev.target?.files?.[0];
    if (!file) return;
    importFile = file;
    importText = file.name;
  }

  async function doImport(confirmLegacy = false) {
    const payload = importFile || importText;
    if (!payload) return;
    const r = await integrity.importSnapshot(payload, importSecret, !!confirmLegacy);
    if (r && r.needsLegacy && !confirmLegacy) {
      openDialog({
        title: 'Legacy snapshot',
        message:
          'This file is a legacy unsigned baseline (no HMAC secret). Importing it is less trustworthy than a modern snapshot. Continue anyway?',
        confirmLabel: 'Import anyway',
        variant: 'danger',
        onConfirm: () => {
          integrity.importSnapshot(payload, importSecret, true);
        },
      });
    }
  }

  async function toggleMedia(ev) {
    await integrity.setIncludeAllMedia(ev.target.checked);
  }

  async function toggleLiveWatch() {
    if (status.live_watch_enabled) {
      openDialog({
        title: 'Disable live file watch?',
        message:
          'This removes the must-use agent (00-clean-sweep-visit-watch.php). High-value files will no longer be re-hashed on normal WordPress requests. You can enable it again later.',
        confirmLabel: 'Disable live watch',
        variant: 'danger',
        onConfirm: () => {
          integrity.disableLiveWatch();
        },
      });
      return;
    }
    await integrity.enableLiveWatch();
  }

  function rowTypeBadge(type) {
    const t = (type || '').toLowerCase();
    if (t === 'created' || t === 'new') return 'bg-sky-500/15 text-sky-800 dark:text-sky-300 border-sky-500/30';
    if (t === 'deleted' || t === 'gone') return 'bg-zinc-500/15 text-muted border-line';
    if (t === 'modified' || t === 'changed' || t === 'tamper') {
      return 'bg-amber-500/15 text-amber-900 dark:text-amber-200 border-amber-500/30';
    }
    return 'bg-elevated text-muted border-line';
  }

  async function runLiveWatchTick() {
    await integrity.liveWatchTick();
  }

  function formatWatchTime(t) {
    if (!t) return '';
    try {
      return new Date(t * 1000).toLocaleString();
    } catch (_) {
      return String(t);
    }
  }

  function watchOpLabel(op) {
    if (op === 'plugin_reinstall') return 'plugin reinstall';
    if (op === 'theme_reinstall') return 'theme reinstall';
    if (op === 'core_reinstall') return 'core reinstall';
    if (op === 'scan') return 'scan';
    return op || 'Clean Sweep';
  }

  function watchSortKey(ev) {
    return Number(ev?.last_seen || ev?.t || 0);
  }

  function formatWatchDay(t) {
    if (!t) return '';
    try {
      return new Date(t * 1000).toLocaleDateString();
    } catch (_) {
      return String(t);
    }
  }

  function isWatchGuard(ev) {
    return ev?.noise === 'directory_guard' || ev?.path === 'directory guards';
  }

  function watchKindLabel(kind) {
    if (kind === 'already_present') return 'already present';
    return kind || 'change';
  }

  function watchScriptLabel(ev) {
    if (ev?.request?.actor === 'clean_sweep') return 'during Clean Sweep';
    const s = ev?.request?.script;
    if (!s) return '';
    if (s.startsWith('clean-sweep/')) return `via ${s}`;
    if (s.includes('clean-sweep/')) {
      const i = s.indexOf('clean-sweep/');
      return `via ${s.slice(i)}`;
    }
    return `via ${s}`;
  }

  let liveWatchAll = $derived(
    Array.isArray(status.live_watch_events) ? [...status.live_watch_events].sort((a, b) => watchSortKey(b) - watchSortKey(a)) : []
  );
  let liveWatchExpected = $derived(liveWatchAll.filter((ev) => !!ev?.expected));
  let liveWatchSignal = $derived(
    liveWatchAll.filter((ev) => !ev?.expected && ev?.kind !== 'already_present' && !isWatchGuard(ev))
  );
  let liveWatchAlready = $derived(
    liveWatchAll.filter((ev) => !ev?.expected && ev?.kind === 'already_present' && !isWatchGuard(ev))
  );
  let liveWatchGuards = $derived(liveWatchAll.filter((ev) => !ev?.expected && isWatchGuard(ev)));
  let liveWatchGuardCount = $derived(
    liveWatchGuards.reduce((n, ev) => n + Number(ev.collapsed || ev.count || 1), 0)
  );
</script>

<div class="h-full overflow-y-auto">
  <div class="p-6 max-w-3xl mx-auto">
    <div class="mb-5">
      <h1 class="text-xl font-bold text-ink">Security</h1>
      <p class="text-sm text-ink mt-1.5 leading-snug">
        Core reinstall is not a full cleanup. This tab watches for new changes and lets you compare this visit to the last one.
      </p>
      <p class="text-xs text-muted mt-1">
        While Clean Sweep is open it also watches this toolkit folder for unexpected edits.
      </p>
    </div>

    <!-- Live status chips -->
    <div class="flex flex-wrap gap-2 mb-5">
      {#each chips as c}
        <div
          class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-[11px] font-medium
            {c.ok
            ? 'bg-emerald-500/10 border-emerald-500/25 text-emerald-800 dark:text-emerald-300'
            : 'bg-app border-line text-muted'}"
        >
          <span class="w-1.5 h-1.5 rounded-full flex-shrink-0 {c.ok ? 'bg-emerald-500' : 'bg-zinc-400'}"></span>
          {c.label}
        </div>
      {/each}
    </div>

    {#if !status.core_sealed && !status.snapshot_imported}
      <p class="text-xs text-muted mb-4 rounded-lg border border-line bg-app px-3 py-2">
        First visit is normal. Run Scan and fix what you find first. This page fills in as you reinstall core, upload packages, and save a snapshot.
      </p>
    {/if}

    <!-- Playbook -->
    <div class="bg-panel border border-line rounded-xl p-5 mb-4">
      <h2 class="text-xs font-semibold uppercase tracking-wide text-muted mb-3">Recommended order</h2>
      <ol class="space-y-3 text-sm text-ink">
        <li class="flex gap-3">
          <span class="flex-shrink-0 w-6 h-6 rounded-md bg-violet-500/15 text-violet-800 dark:text-violet-300 text-xs font-bold flex items-center justify-center border border-violet-500/25">1</span>
          <span class="leading-snug pt-0.5">
            <strong class="font-semibold">Reinstall core</strong>
            (and reinstall or Upload any plugins/themes you trust). Only then does Clean Sweep treat those trees as sealed.
          </span>
        </li>
        <li class="flex gap-3">
          <span class="flex-shrink-0 w-6 h-6 rounded-md bg-violet-500/15 text-violet-800 dark:text-violet-300 text-xs font-bold flex items-center justify-center border border-violet-500/25">2</span>
          <span class="leading-snug pt-0.5">
            <strong class="font-semibold">Download a snapshot</strong>
            before you remove Clean Sweep. Keep the one-time secret with the file.
          </span>
        </li>
        <li class="flex gap-3">
          <span class="flex-shrink-0 w-6 h-6 rounded-md bg-violet-500/15 text-violet-800 dark:text-violet-300 text-xs font-bold flex items-center justify-center border border-violet-500/25">3</span>
          <span class="leading-snug pt-0.5">
            <strong class="font-semibold">Next visit:</strong>
            import that snapshot first to see what changed. Run Scan if something looks wrong or sealed files moved.
          </span>
        </li>
      </ol>

      <!-- Callout -->
      <div class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-xs text-amber-950 dark:text-amber-100/90 space-y-1">
        <p class="font-semibold text-amber-900 dark:text-amber-200">Unchanged since last visit is not the same as safe.</p>
        <p class="text-amber-900/80 dark:text-amber-100/75 leading-snug">
          <strong class="font-medium text-ink dark:text-amber-50">Scan</strong> looks for malware signatures.
          <strong class="font-medium text-ink dark:text-amber-50">This tab</strong> looks for change and for breaks in what you already sealed.
        </p>
      </div>

      <!-- Accordion: how it works -->
      <div class="mt-4 border-t border-line pt-3">
        <button
          type="button"
          class="w-full flex items-center justify-between gap-2 text-left text-xs font-semibold text-ink hover:text-violet-700 dark:hover:text-violet-300 transition-colors"
          onclick={() => (showHowItWorks = !showHowItWorks)}
          aria-expanded={showHowItWorks}
        >
          <span>How sealed vs watched works</span>
          <span class="text-muted font-normal">{showHowItWorks ? 'Hide' : 'Show'}</span>
        </button>
        {#if showHowItWorks}
          <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <div class="rounded-lg border border-line bg-app p-3 space-y-1.5">
              <p class="font-semibold text-ink">Sealed</p>
              <p class="text-muted leading-snug">
                Core after reinstall, plus plugins or themes you reinstalled or uploaded through Clean Sweep.
                A change here is a hard problem: something overwrote a tree you already trusted.
              </p>
            </div>
            <div class="rounded-lg border border-line bg-app p-3 space-y-1.5">
              <p class="font-semibold text-ink">Watched</p>
              <p class="text-muted leading-snug">
                Other PHP and config we hash for drift (plugins, themes, uploads PHP, cache or upgrade drop-ins, wp-config, and similar).
                New or changed is a finding. A match only means nothing changed since last visit.
              </p>
            </div>
            <div class="sm:col-span-2 rounded-lg border border-line bg-app p-3 space-y-1.5">
              <p class="font-semibold text-ink">Why plugins stay untrusted after core reinstall</p>
              <p class="text-muted leading-snug">
                Leftover malware in plugins, themes, or must-use plugins can rewrite core again.
                Only seal those packages when you reinstall or Upload them through Clean Sweep.
              </p>
            </div>
          </div>
        {/if}
      </div>
    </div>

    {#if status.not_sealed}
      <p class="text-xs text-amber-700 dark:text-amber-400 mb-4">{status.not_sealed}</p>
    {/if}

    <!-- Phase 3: opt-in live watch agent -->
    <div class="bg-panel border border-line rounded-xl p-5 mb-4 text-sm space-y-3">
      <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="min-w-0">
          <h2 class="text-sm font-semibold text-ink">Live file watch</h2>
          <p class="text-xs text-muted mt-1 leading-relaxed">
            Optional always-on agent (must-use plugin) that re-hashes high-value paths on normal WordPress
            requests: core bootstrap, pre-boot config, drop-ins, must-use plugins, active plugin mains, and
            sealed files. Catches reinfection and new droppers between toolkit visits. Not limited to one malware recipe.
          </p>
        </div>
        <button
          type="button"
          disabled={liveWatchBusy}
          onclick={toggleLiveWatch}
          class="shrink-0 px-3 py-1.5 text-xs font-medium rounded-md border transition-colors disabled:opacity-50
            {status.live_watch_enabled
              ? 'bg-amber-500/15 text-amber-900 dark:text-amber-200 border-amber-500/35 hover:bg-amber-500/25'
              : 'bg-violet-500/15 text-violet-900 dark:text-violet-200 border-violet-500/35 hover:bg-violet-500/25'}"
        >
          {#if liveWatchBusy}
            Working…
          {:else if status.live_watch_enabled}
            Disable live watch
          {:else}
            Enable live watch
          {/if}
        </button>
      </div>
      {#if status.live_watch_enabled}
        <div class="text-[11px] text-muted space-y-1">
          <p>
            Agent: <span class="font-mono text-ink">{status.live_watch_agent ? 'installed' : 'missing. Re-enable it.'}</span>
            · Paths: <span class="text-ink">{status.live_watch_paths || 0}</span>
            {#if status.live_watch_last_tick}
              · Last tick: {formatWatchTime(status.live_watch_last_tick)}
            {/if}
          </p>
          <p class="text-faint">
            Watches the live WordPress site (wp-config, drop-ins, mu-plugins, active plugin/theme files).
            The recovery copy inside Clean Sweep (core/fresh) is ignored.
            “Created” means the file’s disk time is after live watch started. Older files are listed as already on disk.
            Plugin, theme, and core reinstalls are tagged expected. Restricted hosts: file reads/hashes only; fails open if something is blocked.
          </p>
          <button
            type="button"
            class="text-[11px] text-sky-700 dark:text-sky-300 hover:underline"
            onclick={runLiveWatchTick}
          >
            Run watch check now
          </button>
        </div>
        {#if liveWatchAll.length}
          <div class="border-t border-line pt-3 space-y-3">
            {#if liveWatchSignal.length}
              <div>
                <p class="text-[10px] uppercase tracking-wide text-faint mb-1.5">Unexpected live changes</p>
                <ul class="text-[11px] space-y-1.5 max-h-48 overflow-y-auto">
                  {#each liveWatchSignal.slice(0, 15) as ev}
                    <li class="font-mono text-ink break-all leading-snug">
                      <span class="text-faint">noticed {formatWatchTime(ev.first_seen || ev.t)}</span>
                      · <span class="text-amber-800 dark:text-amber-300">{watchKindLabel(ev.kind)}</span>
                      · {ev.path}
                      {#if ev.mtime}
                        <span class="text-faint"> · on disk since {formatWatchDay(ev.mtime)}</span>
                      {/if}
                      {#if (ev.count || 1) > 1}
                        <span class="text-faint"> · {ev.count}× last {formatWatchTime(ev.last_seen)}</span>
                      {/if}
                      {#if ev.request?.user_id}
                        <span class="text-faint"> · user {ev.request.user_id}</span>
                      {/if}
                      {#if watchScriptLabel(ev)}
                        <span class="text-faint"> · {watchScriptLabel(ev)}</span>
                      {/if}
                      {#if ev.request?.ip}
                        <span class="text-faint"> · {ev.request.ip}</span>
                      {/if}
                    </li>
                  {/each}
                </ul>
              </div>
            {/if}
            {#if liveWatchAlready.length}
              <div>
                <p class="text-[10px] uppercase tracking-wide text-faint mb-1.5">Already on disk</p>
                <ul class="text-[11px] space-y-1 max-h-32 overflow-y-auto">
                  {#each liveWatchAlready.slice(0, 8) as ev}
                    <li class="font-mono text-muted break-all">
                      {ev.path}
                      {#if ev.mtime}
                        <span class="text-faint"> · on disk since {formatWatchDay(ev.mtime)}</span>
                      {/if}
                      <span class="text-faint"> · noticed {formatWatchTime(ev.first_seen || ev.t)}</span>
                    </li>
                  {/each}
                </ul>
              </div>
            {/if}
            {#if liveWatchGuardCount}
              <p class="text-[11px] text-faint">
                {liveWatchGuardCount} directory guard{liveWatchGuardCount === 1 ? '' : 's'} (empty index.php / deny-from-all .htaccess) already on disk. Not treated as new files.
              </p>
            {/if}
            {#if liveWatchExpected.length}
              <div>
                <p class="text-[10px] uppercase tracking-wide text-faint mb-1.5">During Clean Sweep work</p>
                <ul class="text-[11px] space-y-1 max-h-28 overflow-y-auto">
                  {#each liveWatchExpected.slice(0, 6) as ev}
                    <li class="font-mono text-muted break-all">
                      <span class="text-faint">{formatWatchTime(ev.first_seen || ev.t)}</span>
                      · {watchKindLabel(ev.kind)}
                      · {ev.path}
                      <span class="text-faint"> · expected {watchOpLabel(ev.expected?.op)}</span>
                    </li>
                  {/each}
                </ul>
              </div>
            {/if}
            {#if !liveWatchSignal.length && (liveWatchAlready.length || liveWatchGuardCount || liveWatchExpected.length)}
              <p class="text-[11px] text-faint">
                No new or changed files since live watch started.
                {#if liveWatchExpected.length}
                  Listed writes match a Clean Sweep reinstall in progress.
                {/if}
              </p>
            {/if}
          </div>
        {:else}
          <p class="text-[11px] text-faint border-t border-line pt-2">
            No live changes recorded yet. After enabling, browse the site or wait for cron; then refresh this tab.
          </p>
        {/if}
      {:else}
        <p class="text-[11px] text-faint">
          Off by default. Enable after you seal core so Clean Sweep can notice sealed or high-risk files changing
          even when the toolkit is closed. Removing Clean Sweep (Cleanup tab) also removes this agent automatically.
        </p>
      {/if}
    </div>

    {#if status.canary_mismatch?.length}
      <p class="text-xs text-red-600 mb-4">Client canary mismatch: {status.canary_mismatch.join(', ')} changed since this browser last saw them.</p>
    {/if}

    {#if status.snapshot_imported || status.last_compare}
      {@const cmp = status.last_compare || {}}
      {@const tamper = cmp.tamper || []}
      {@const updates = cmp.updates || []}
      {@const drift = cmp.drift || {}}
      {@const driftChanged = drift.changed || []}
      {@const driftNew = drift.new || []}
      {@const driftGone = drift.gone || []}
      {@const driftHits = driftChanged.length + driftNew.length + driftGone.length}
      {@const source = cmp.likely_source || status.likely_source}
      {@const compared = cmp.compared ?? status.core_file_count ?? 0}
      {@const matched = cmp.matched ?? 0}
      {@const sealedClean = cmp.sealed_clean === true || (tamper.length === 0 && !(cmp.persistence?.new_admins?.length) && !(cmp.persistence?.new_cron?.length))}
      {@const overallOk = sealedClean && driftHits === 0 && !(cmp.persistence?.new_admins?.length) && !(cmp.persistence?.new_cron?.length)}
      {@const driftRows = [...driftChanged, ...driftNew, ...driftGone]}
      {@const tamperLimit = showAllTamper ? tamper.length : 12}
      {@const driftLimit = showAllDrift ? driftRows.length : 12}
      {@const suspectList = (source?.candidates || []).filter((c) => c.path && c.path !== source?.writer?.path)}
      {@const suspectLimit = showAllSuspects ? suspectList.length : 4}

      <section class="bg-panel border border-line rounded-xl overflow-hidden mb-6 text-sm">
        <!-- Header -->
        <div class="px-5 py-4 border-b border-line flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <h2 class="text-sm font-semibold text-ink">Snapshot comparison</h2>
              <span
                class="text-[10px] px-2 py-0.5 rounded-full border font-medium
                  {overallOk
                    ? 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-300 border-emerald-500/30'
                    : sealedClean
                      ? 'bg-amber-500/15 text-amber-900 dark:text-amber-200 border-amber-500/30'
                      : 'bg-red-500/15 text-red-800 dark:text-red-300 border-red-500/30'}"
              >
                {overallOk ? 'No sealed or watched drift' : sealedClean ? 'Watched drift only' : 'Sealed drift detected'}
              </span>
            </div>
            {#if cmp.exported_at || cmp.host}
              <p class="text-[11px] text-muted mt-1">
                Baseline snapshot
                {#if cmp.exported_at}
                  · {new Date(cmp.exported_at * 1000).toLocaleString()}
                {/if}
                {#if cmp.host}
                  · <span class="font-mono">{cmp.host}</span>
                {/if}
              </p>
            {/if}
          </div>
        </div>

        <!-- Summary metrics -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-line border-b border-line">
          <div class="bg-panel px-4 py-3">
            <p class="text-[10px] uppercase tracking-wide text-faint">Sealed compared</p>
            <p class="text-lg font-semibold text-ink tabular-nums">{compared || '—'}</p>
            <p class="text-[10px] text-muted">{matched} still match</p>
          </div>
          <div class="bg-panel px-4 py-3">
            <p class="text-[10px] uppercase tracking-wide text-faint">Sealed problems</p>
            <p class="text-lg font-semibold tabular-nums {tamper.length ? 'text-red-700 dark:text-red-400' : 'text-ink'}">{tamper.length}</p>
            <p class="text-[10px] text-muted">do not match snapshot / .org</p>
          </div>
          <div class="bg-panel px-4 py-3">
            <p class="text-[10px] uppercase tracking-wide text-faint">Watched drift</p>
            <p class="text-lg font-semibold tabular-nums {driftHits ? 'text-amber-800 dark:text-amber-300' : 'text-ink'}">{driftHits}</p>
            <p class="text-[10px] text-muted">{driftChanged.length} ch · {driftNew.length} new · {driftGone.length} gone</p>
          </div>
          <div class="bg-panel px-4 py-3">
            <p class="text-[10px] uppercase tracking-wide text-faint">Official updates</p>
            <p class="text-lg font-semibold text-ink tabular-nums">{updates.length}</p>
            <p class="text-[10px] text-muted">match wordpress.org now</p>
          </div>
        </div>

        <div class="p-5 space-y-5">
          <!-- Sealed -->
          <div>
            <div class="flex items-center gap-2 mb-2">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">Sealed trees</h3>
              <span class="text-[10px] text-faint">Trusted after reinstall / upload</span>
            </div>
            {#if sealedClean}
              <div class="rounded-lg border border-emerald-500/25 bg-emerald-500/5 px-3 py-2.5 text-xs text-emerald-800 dark:text-emerald-300">
                No sealed files changed. Compared {compared} file{compared === 1 ? '' : 's'}
                {#if matched} ({matched} still match){/if}.
              </div>
            {:else}
              {#if updates.length}
                <p class="text-[11px] text-muted mb-2">
                  {updates.length} file(s) now match wordpress.org checksums. Treated as official updates, not infection.
                </p>
              {/if}
              {#if tamper.length}
                <p class="text-xs text-red-700 dark:text-red-400 font-medium mb-2">
                  {tamper.length} sealed path{tamper.length === 1 ? '' : 's'} no longer match the snapshot or wordpress.org.
                </p>
                <ul class="rounded-lg border border-line bg-app divide-y divide-line max-h-52 overflow-y-auto">
                  {#each tamper.slice(0, tamperLimit) as row}
                    <li class="px-3 py-2 flex items-start gap-2 text-[11px]">
                      <span class="mt-0.5 shrink-0 text-[10px] px-1.5 py-0.5 rounded border {rowTypeBadge(row.type || 'tamper')}">
                        {row.type || 'changed'}
                      </span>
                      <span class="font-mono text-ink break-all min-w-0">{row.file || row.path}</span>
                    </li>
                  {/each}
                </ul>
                {#if tamper.length > 12}
                  <button
                    type="button"
                    class="mt-1.5 text-[11px] text-sky-700 dark:text-sky-300 hover:underline"
                    onclick={() => (showAllTamper = !showAllTamper)}
                  >
                    {showAllTamper ? 'Show fewer' : `Show all ${tamper.length}`}
                  </button>
                {/if}
              {/if}
              {#if cmp.persistence?.new_admins?.length}
                <p class="text-xs text-amber-800 dark:text-amber-300 mt-2">
                  New admin account(s): <span class="font-mono">{cmp.persistence.new_admins.join(', ')}</span>
                </p>
              {/if}
              {#if cmp.persistence?.new_cron?.length}
                <p class="text-xs text-amber-800 dark:text-amber-300 mt-1">
                  New cron hook(s): <span class="font-mono">{cmp.persistence.new_cron.slice(0, 8).join(', ')}</span>
                  {#if cmp.persistence.new_cron.length > 8}
                    <span class="text-muted"> +{cmp.persistence.new_cron.length - 8}</span>
                  {/if}
                </p>
              {/if}
            {/if}
          </div>

          <!-- Watched -->
          <div class="border-t border-line pt-4">
            <div class="flex items-center gap-2 mb-2">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">Watched drift</h3>
              <span class="text-[10px] text-faint">Not a clean bill of health by itself</span>
            </div>
            {#if driftHits === 0}
              <div class="rounded-lg border border-line bg-app px-3 py-2.5 text-xs text-muted">
                No new or changed watched files since the snapshot.
                {#if drift.watched}
                  Compared {drift.watched} plugin/theme/uploads/site-owned hash(es).
                {/if}
              </div>
            {:else}
              <p class="text-xs text-amber-800 dark:text-amber-300 mb-2">
                {driftChanged.length} changed · {driftNew.length} new · {driftGone.length} gone since the snapshot.
              </p>
              <ul class="rounded-lg border border-line bg-app divide-y divide-line max-h-56 overflow-y-auto">
                {#each driftRows.slice(0, driftLimit) as row}
                  <li class="px-3 py-2 flex items-start gap-2 text-[11px]">
                    <span class="mt-0.5 shrink-0 text-[10px] px-1.5 py-0.5 rounded border {rowTypeBadge(row.type)}">
                      {row.type || 'change'}
                    </span>
                    <div class="min-w-0">
                      <p class="font-mono text-ink break-all">{row.path}</p>
                      {#if row.bucket}
                        <p class="text-[10px] text-faint">{bucketLabel(row.bucket)}</p>
                      {/if}
                    </div>
                  </li>
                {/each}
              </ul>
              {#if driftHits > 12}
                <button
                  type="button"
                  class="mt-1.5 text-[11px] text-sky-700 dark:text-sky-300 hover:underline"
                  onclick={() => (showAllDrift = !showAllDrift)}
                >
                  {showAllDrift ? 'Show fewer' : `Show all ${driftHits}`}
                </button>
              {/if}
            {/if}
          </div>

          <!-- Attribution -->
          {#if driftHits > 0 || tamper.length > 0 || source}
            <div class="border-t border-line pt-4 space-y-3">
              <div class="flex items-center gap-2 flex-wrap">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">Likely source</h3>
                {#if source?.confidence && source.confidence !== 'none'}
                  <span
                    class="text-[10px] px-2 py-0.5 rounded-full border font-medium
                      {source.confidence === 'high'
                        ? 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-300 border-emerald-500/30'
                        : source.confidence === 'medium'
                          ? 'bg-amber-500/15 text-amber-900 dark:text-amber-200 border-amber-500/30'
                          : 'bg-elevated text-muted border-line'}"
                  >
                    {source.confidence} confidence
                  </span>
                {/if}
              </div>

              {#if source?.writer && !source.writer_is_payload}
                <div class="rounded-lg border border-violet-500/25 bg-violet-500/5 p-3 space-y-2">
                  <p class="text-xs text-ink leading-snug">{source.summary}</p>
                  <div>
                    <p class="text-[10px] uppercase tracking-wide text-faint">Writer</p>
                    <p class="text-[11px] font-mono text-ink break-all mt-0.5">{source.writer.path}</p>
                    {#if source.writer.why}
                      <p class="text-[11px] text-muted mt-1">{source.writer.why}</p>
                    {/if}
                    {#if source.writer.evidence?.length}
                      <div class="flex flex-wrap gap-1 mt-2">
                        {#each source.writer.evidence as tag}
                          <span class="text-[10px] px-1.5 py-0.5 rounded border border-line bg-app text-muted font-mono">{tag}</span>
                        {/each}
                      </div>
                    {/if}
                  </div>
                  {#if source.entry}
                    <div class="border-t border-violet-500/20 pt-2">
                      <p class="text-[10px] uppercase tracking-wide text-faint">
                        Persistence / entry
                        {#if source.entry.slug}
                          · {source.entry.slug}
                        {/if}
                        {#if source.entry.cve}
                          · {source.entry.cve}
                        {/if}
                      </p>
                      {#if source.entry.why}
                        <p class="text-[11px] text-muted mt-0.5">{source.entry.why}</p>
                      {/if}
                    </div>
                  {/if}
                </div>

                {#if suspectList.length}
                  <div>
                    <p class="text-[10px] uppercase tracking-wide text-faint mb-1.5">Other suspects</p>
                    <ul class="rounded-lg border border-line bg-app divide-y divide-line">
                      {#each suspectList.slice(0, suspectLimit) as c}
                        <li class="px-3 py-2 text-[11px]">
                          <p class="font-mono text-ink break-all">{c.path}</p>
                          {#if c.evidence?.length}
                            <div class="flex flex-wrap gap-1 mt-1">
                              {#each c.evidence as tag}
                                <span class="text-[10px] px-1.5 py-0.5 rounded border border-line text-faint font-mono">{tag}</span>
                              {/each}
                            </div>
                          {/if}
                          {#if c.why}
                            <p class="text-[10px] text-muted mt-0.5">{c.why}</p>
                          {/if}
                        </li>
                      {/each}
                    </ul>
                    {#if suspectList.length > 4}
                      <button
                        type="button"
                        class="mt-1.5 text-[11px] text-sky-700 dark:text-sky-300 hover:underline"
                        onclick={() => (showAllSuspects = !showAllSuspects)}
                      >
                        {showAllSuspects ? 'Show fewer' : `Show all ${suspectList.length}`}
                      </button>
                    {/if}
                  </div>
                {/if}
              {:else}
                <div class="rounded-lg border border-line bg-app px-3 py-2.5 space-y-1">
                  <p class="text-xs text-muted">
                    {source?.summary || 'Changed files are listed above. No strong writer link yet.'}
                  </p>
                  <p class="text-[11px] text-faint">
                    A writer is named only with hard evidence (schedule callback file, always-on loader, live watch,
                    pre-boot abuse, content targeting core, etc.). Package map extras alone do not count.
                  </p>
                </div>
              {/if}
            </div>
          {/if}

          <p class="text-[11px] text-faint border-t border-line pt-3">
            Unchanged watched files only mean nothing changed since the snapshot, not that the site is clean.
            Use <span class="text-ink font-medium">Scan</span> for malware signatures in files and the database.
          </p>
        </div>
      </section>
    {/if}

    <!-- Action cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
      <div class="rounded-xl border border-line bg-panel p-3.5">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">Watch</p>
        <p class="text-xs text-ink mt-1 leading-snug">
          Runs while this page is open. Flags unexpected changes on the site and inside Clean Sweep.
        </p>
      </div>
      <div class="rounded-xl border border-line bg-panel p-3.5">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">Snapshot</p>
        <p class="text-xs text-ink mt-1 leading-snug">
          Download before you leave. Next visit, import it to compare sealed and watched files quickly.
        </p>
      </div>
      <div class="rounded-xl border border-line bg-panel p-3.5">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">Scan</p>
        <p class="text-xs text-ink mt-1 leading-snug">
          Separate tool: malware signatures in plugins, themes, uploads, and the database.
        </p>
      </div>
    </div>

    <label class="flex items-start gap-2 mb-4 text-xs text-muted cursor-pointer">
      <input type="checkbox" checked={!!status.include_all_media} onchange={toggleMedia} class="mt-0.5" />
      <span>Also include all media in uploads when recording a snapshot (large). Uncheck to limit to PHP, config, and PHP-in-images.</span>
    </label>

    {#if status.capabilities?.summary}
      <p class="text-[11px] text-muted mb-4">Host: {status.capabilities.summary}</p>
    {/if}

    <div class="flex flex-wrap gap-3 mb-4">
      <Button.Root
        onclick={() => integrity.exportSnapshot()}
        class="px-4 py-2 bg-violet-500 hover:bg-violet-600 text-white text-sm rounded-md cursor-pointer"
      >
        Download snapshot
      </Button.Root>
      <label class="px-4 py-2 text-sm border border-line rounded-md cursor-pointer hover:bg-hover">
        Use last snapshot
        <input type="file" accept=".json,application/json" class="hidden" onchange={onImportFile} />
      </label>
    </div>

    {#if importText}
      <div class="mb-4 space-y-2">
        <input
          type="text"
          bind:value={importSecret}
          placeholder="Snapshot secret (from last download)"
          class="w-full px-3 py-2 text-sm bg-app border border-line rounded-md text-ink"
        />
        <Button.Root
          onclick={() => doImport()}
          disabled={importing}
          class="px-3 py-1.5 text-sm bg-violet-500 text-white rounded-md cursor-pointer disabled:opacity-50"
        >
          {importing ? 'Comparing…' : 'Import & compare'}
        </Button.Root>
      </div>
    {/if}

    {#if secret}
      <div class="bg-amber-500/10 border border-amber-500/30 rounded-lg p-3 mb-4 text-xs">
        <div class="font-medium text-amber-800 dark:text-amber-300 mb-1">Save this secret. It is shown once.</div>
        <code class="break-all text-ink">{secret}</code>
        <p class="text-muted mt-1">You will need it to import this snapshot on a later visit.</p>
      </div>
    {/if}

    {#if error}
      <p class="text-xs text-red-600 mb-4">{error}</p>
    {/if}

    <button type="button" onclick={toggleDiag} class="text-xs text-muted hover:text-ink">
      {showDiag ? 'Hide' : 'Show'} diagnostics (for verifying the visit engine)
    </button>

    {#if showDiag}
      <div class="mt-3 bg-app border border-line rounded-lg p-3 max-h-96 overflow-y-auto">
        <div class="flex gap-2 mb-2">
          <button type="button" class="text-[10px] {diagFilter === 'all' ? 'text-ink' : 'text-muted'}" onclick={() => (diagFilter = 'all')}>All</button>
          <button type="button" class="text-[10px] {diagFilter === 'problems' ? 'text-ink' : 'text-muted'}" onclick={() => (diagFilter = 'problems')}>Problems only</button>
        </div>
        {#if (status.events || []).length === 0}
          <p class="text-[11px] text-muted">No events yet.</p>
        {:else}
          <ul class="space-y-1.5">
            {#each [...(status.events || [])].reverse().filter((ev) => diagFilter === 'all' || isProblemEvent(ev)) as ev}
              <li class="text-[11px] text-ink leading-snug">
                <span class="text-muted font-mono">{ev.t ? new Date(ev.t * 1000).toLocaleTimeString() : ''}</span>
                {eventText(ev)}
              </li>
            {/each}
          </ul>
        {/if}
      </div>
    {/if}
  </div>
</div>

{#if dialog.open}
  <ConfirmDialog
    open={true}
    title={dialog.title}
    message={dialog.message}
    confirmLabel={dialog.confirmLabel}
    cancelLabel={dialog.cancelLabel}
    variant={dialog.variant}
    alertOnly={dialog.alertOnly}
    onConfirm={() => {
      const fn = dialog.onConfirm;
      closeDialog();
      fn?.();
    }}
    onCancel={closeDialog}
  />
{/if}
