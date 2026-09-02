<script>
  /**
   * Malware scan progress card — activity-first UX.
   * Primary: phase + live counters + steps left.
   * Secondary: progress bar (queue/phase %).
   * "Stuck" only when counters/queue are idle — not when % is flat.
   */
  import { createEventDispatcher } from 'svelte';

  /** @type {number} */
  export let percent = 0;
  /** @type {'queue'|'phase'|string} */
  export let progressSource = '';
  /** @type {string} */
  export let profileLabel = 'Scan';
  /** @type {'running'|'paused'|'completed'|'failed'|'cancelled'|string} */
  export let status = 'running';
  /** @type {string} */
  export let phase = 'files';
  /** @type {string} */
  export let message = '';
  /** When true, DB was intentionally skipped (files / paths scope) */
  export let dbSkipped = false;
  /** When true, filesystem was intentionally skipped (database scope) */
  export let filesSkipped = false;
  /** @type {number} Live or peak files scanned */
  export let filesScanned = 0;
  /** @type {boolean} True when file phase is done and we're showing a peak total */
  export let filesComplete = false;
  /** @type {number} */
  export let dbRowsScanned = 0;
  /** @type {number} */
  export let threatsFound = 0;
  /** @type {number} Signature matches (excludes integrity) */
  export let malwareFound = 0;
  /** @type {number} */
  export let integrityFound = 0;
  /** @type {number} */
  export let pending = 0;
  /** @type {number} */
  export let completed = 0;
  /** @type {number} */
  export let inProgress = 0;
  /** @type {number} */
  export let failed = 0;
  /**
   * ms since last real activity (counters / queue changed).
   * Null when not scanning.
   * @type {number|null}
   */
  export let idleMs = null;
  /** @type {boolean} */
  export let canContinue = true;
  /** @type {boolean} */
  export let restrictedHost = false;
  /** @type {string|null} */
  export let pauseReason = null;
  /** @type {boolean} */
  export let showTechnical = true;
  /** @type {string|null} */
  export let lastFilePath = null;
  /** @type {string|null} */
  export let lastDbTable = null;
  /** @type {number|string|null} */
  export let lastDbId = null;
  /** @type {string|null} */
  export let lastDbKey = null;
  /** @type {number|null} */
  export let lastDbBytes = null;
  /** @type {string|null} */
  export let lastDbMode = null;
  /** @type {number} */
  export let dbRowsEstimate = 0;
  /** @type {string|null} */
  export let checksumNote = null;
  /** @type {number} */
  export let checksumChecked = 0;
  /** @type {string|null} */
  export let checksumVersion = null;
  /** @type {string|null} */
  export let packageChecksumNote = null;
  /** In-flight work unit from status.queue.current_unit */
  export let currentUnit = null;
  /** Unix seconds of last drain activity */
  export let lastDrainActivityAt = 0;

  const dispatch = createEventDispatcher();

  /** True idle: no counter/queue movement */
  const STUCK_MS = 90000;
  /** Softer “taking a while” after this when still active-looking */
  const SLOW_MS = 45000;

  $: isPaused = status === 'paused';
  // Any non-terminal status keeps the card visible (including not_found /
  // acknowledged / empty while the parent still has scanning=true).
  $: isTerminal =
    status === 'completed' ||
    status === 'failed' ||
    status === 'cancelled' ||
    status === 'idle' ||
    status === 'error';
  $: isRunning =
    status === 'running' ||
    status === 'acknowledged' ||
    status === 'pending' ||
    status === 'initializing' ||
    status === 'not_found' ||
    !status ||
    (!isPaused && !isTerminal);
  $: isActive = isPaused || isRunning;
  $: queueBusy = (inProgress || 0) > 0;
  $: drainFresh =
    lastDrainActivityAt > 0 && idleMs != null && idleMs < STUCK_MS;
  $: isStuck = !queueBusy && !drainFresh && idleMs != null && idleMs >= STUCK_MS;
  $: isSlow = !isStuck && !queueBusy && idleMs != null && idleMs >= SLOW_MS;
  $: clamped = Math.min(100, Math.max(0, Math.round(percent || 0)));
  // Queue formula matches "steps left". Phase is only used before the queue
  // is big enough; do not present that as a confident percent.
  $: discovering = progressSource !== 'queue' && !isTerminal && (pending || 0) + (completed || 0) < 8;
  $: percentLabel = discovering ? '…' : `${clamped}%`;
  $: estimateHint = discovering
    ? 'discovering work'
    : progressSource === 'queue'
      ? 'of remaining work'
      : 'estimate';
  $: barWidth = discovering ? Math.max(4, Math.min(12, clamped || 4)) : clamped;

  $: phaseKey = (phase || '').toLowerCase();
  $: unitType = (currentUnit && currentUnit.type) || '';

  $: phaseLabel = (() => {
    if (phaseKey === 'files' || phaseKey === 'file') return 'Scanning files';
    if (phaseKey === 'database' || phaseKey === 'db') return 'Checking database';
    if (phaseKey === 'integrity') return 'Checking core and package checksums';
    if (phaseKey === 'visit_census' || phaseKey === 'census') return 'Visit census';
    if (phaseKey === 'complete' || phaseKey === 'finalization' || phaseKey === 'finalize') {
      return 'Finishing up';
    }
    if (phaseKey === 'initializing') return 'Preparing…';
    if (phaseKey === 'analysis' || phaseKey === 'anomaly_detection') return 'Analyzing…';
    return 'Working…';
  })();

  $: statusChip = (() => {
    if (isStuck) {
      return {
        text: 'No activity',
        class: 'bg-amber-500/15 text-amber-800 dark:text-amber-300 border-amber-500/40',
      };
    }
    if (isPaused) {
      return {
        text: 'Continuing automatically…',
        class: 'bg-sky-500/15 text-sky-700 dark:text-sky-300 border-sky-500/30',
      };
    }
    if (isSlow) {
      return {
        text: 'Still working',
        class: 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-300 border-emerald-500/25',
      };
    }
    return {
      text: 'Scanning',
      class: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-500/30',
    };
  })();

  $: barColor = isStuck
    ? 'linear-gradient(90deg, #f59e0b, #fbbf24)'
    : isPaused
      ? 'linear-gradient(90deg, var(--primary), #38bdf8)'
      : 'linear-gradient(90deg, var(--primary), #10b981)';

  $: stepsHint = (() => {
    const rem = pending || 0;
    const active = inProgress || 0;
    if (rem > 0) return `~${rem.toLocaleString()} steps left`;
    if (active > 0) return `${active} step${active === 1 ? '' : 's'} in progress`;
    return null;
  })();

  $: checksumish =
    unitType === 'core_checksum' ||
    unitType === 'package_checksum' ||
    (!unitType && phaseKey === 'integrity');
  $: dbish =
    unitType === 'db_table_segment' ||
    unitType === 'db_site_discovery' ||
    (!unitType && (phaseKey === 'database' || phaseKey === 'db' || filesSkipped));
  $: censusish =
    unitType === 'visit_census' ||
    (!unitType && (phaseKey === 'visit_census' || phaseKey === 'census'));
  // File counts belong to file-batch work. Census / discovery / checksums
  // must not inherit a leftover files_scanned into the headline.
  $: filesish =
    !checksumish &&
    !dbish &&
    !censusish &&
    unitType !== 'file_discovery' &&
    unitType !== 'root_config' &&
    unitType !== 'finalization' &&
    unitType !== 'analysis' &&
    unitType !== 'integrity_check';

  $: filesFlatHint =
    isActive &&
    !discovering &&
    !censusish &&
    filesish === false &&
    (pending || 0) > 8 &&
    (filesScanned || 0) > 0 &&
    (filesScanned || 0) < 80;

  $: lastActivityLabel = (() => {
    if (idleMs == null) return null;
    if (idleMs < 5000) return 'Last activity just now';
    if (idleMs < 60000) return `Last activity ${Math.round(idleMs / 1000)}s ago`;
    return `Last activity ${Math.round(idleMs / 60000)}m ago`;
  })();

  $: filesLabel = filesComplete && filesScanned > 0 ? 'Files (done)' : 'Files';
  $: filesDisplay =
    filesScanned > 0 ? filesScanned.toLocaleString() : '—';
  $: technicalMessage = (() => {
    const m = String(message || '').trim();
    if (!m) return '';
    const low = m.toLowerCase();
    if (low === phaseKey || low === unitType || low === 'visit_census' || low === 'census') {
      return '';
    }
    return m;
  })();

  let detailsOpen = false;

  function onContinue() {
    dispatch('continue');
  }
  function onCancel() {
    dispatch('cancel');
  }

  function relativePath(file) {
    if (!file) return '';
    let p = String(file).replace(/\\/g, '/');
    const markers = ['/wp-content/', '/wp-includes/', '/wp-admin/', 'wp-content/', 'wp-includes/', 'wp-admin/'];
    for (const m of markers) {
      const i = p.indexOf(m);
      if (i >= 0) {
        const start = m.startsWith('/') ? i + 1 : i;
        return p.slice(start);
      }
    }
    const base = p.split('/').pop();
    if (p.startsWith('/') && p.length > 56) {
      const parts = p.split('/').filter(Boolean);
      return parts.length > 3 ? parts.slice(-3).join('/') : p;
    }
    return base || p;
  }

  function formatDbBytes(n) {
    const v = Number(n);
    if (!v || v < 0) return '';
    if (v < 1024) return `${v} B`;
    if (v < 1048576) return `${Math.round(v / 1024)} KB`;
    return `${(v / 1048576).toFixed(1)} MB`;
  }

  function shortTable(name) {
    if (!name) return '';
    const n = String(name);
    // wp_4_options → options; wp_options → options
    if (n.endsWith('_options')) return n.replace(/^.*_options$/, 'options');
    if (n.endsWith('_posts')) return 'posts';
    if (n.endsWith('_postmeta')) return 'postmeta';
    if (n.endsWith('_comments')) return 'comments';
    if (n.endsWith('_commentmeta')) return 'commentmeta';
    if (n.endsWith('_users')) return 'users';
    if (n.endsWith('_usermeta')) return 'usermeta';
    if (n.endsWith('_sitemeta')) return 'sitemeta';
    if (n.endsWith('_terms')) return 'terms';
    if (n.endsWith('_term_taxonomy')) return 'term_taxonomy';
    if (n.endsWith('_termmeta')) return 'termmeta';
    const cut = n.lastIndexOf('_');
    return cut >= 0 ? n.slice(cut + 1) : n;
  }

  $: currentCheck = (() => {
    if (unitType === 'core_checksum') return 'Checking WordPress core files';
    if (unitType === 'package_checksum') {
      return packageChecksumNote || 'Checking plugin and theme checksums';
    }
    if (unitType === 'file_discovery') {
      const p = relativePath(currentUnit?.base_dir || lastFilePath);
      return p ? `Finding files · ${p}` : 'Finding files to scan';
    }
    if (unitType === 'root_config') return 'Checking root config files';
    if (unitType === 'visit_census' || (!unitType && censusish)) {
      const raw = String((currentUnit && currentUnit.phase) || '').toLowerCase();
      const censusPhase = {
        site_owned: 'site-owned files',
        extra_php: 'extra PHP',
        wp_content: 'wp-content',
        uploads: 'uploads',
        options: 'options',
      }[raw] || (raw ? raw.replace(/_/g, ' ') : '');
      return censusPhase ? `Visit census · ${censusPhase}` : 'Visit census';
    }
    if (unitType === 'finalization') return 'Finishing up';
    if (unitType === 'db_site_discovery') return 'Finding site database tables';
    if (
      unitType === 'db_table_segment' ||
      (!unitType && (phaseKey === 'database' || phaseKey === 'db' || filesSkipped))
    ) {
      const t = shortTable((currentUnit && currentUnit.table) || lastDbTable);
      if (!t) return 'Checking database';
      const id = lastDbId ? ` #${lastDbId}` : '';
      const key = lastDbKey ? ` ${lastDbKey}` : '';
      const size = formatDbBytes(lastDbBytes);
      const mode = lastDbMode && lastDbMode !== 'full' ? ` ${lastDbMode}` : '';
      const sizeMode = size || mode ? ` (${[size, mode.trim()].filter(Boolean).join(', ')})` : '';
      return `Database · ${t}${id}${key}${sizeMode}`;
    }
    if (!unitType && phaseKey === 'integrity') {
      return packageChecksumNote || 'Checking core and package checksums';
    }
    if (lastFilePath) {
      const rel = relativePath(lastFilePath);
      const low = rel.toLowerCase();
      let kind = 'File';
      if (low.includes('plugins/')) kind = 'Plugin file';
      else if (low.includes('themes/')) kind = 'Theme file';
      else if (low.includes('uploads/')) kind = 'Uploads file';
      else if (low.includes('mu-plugins/')) kind = 'Must-use plugin';
      return `${kind} · ${rel}`;
    }
    if ((phaseKey === 'files' || phaseKey === 'file' || !phaseKey) && filesScanned > 0) {
      return `Scanning files · ${Number(filesScanned).toLocaleString()} scanned`;
    }
    return phaseLabel;
  })();

  /** Primary status line — activity first, not a flat % story */
  $: primaryLine = (() => {
    if (pauseReason === 'gateway_timeout' || pauseReason === 'network_error') {
      return 'Host timed out answering (504). Scan progress is still on the server. Retrying automatically. You can also press Continue now.';
    }
    if (isStuck) {
      return 'No new activity for a while. You can wait, or continue now.';
    }
    if (isPaused) {
      return restrictedHost
        ? 'Slice saved. Next step starts automatically. Keep this tab open.'
        : 'Slice saved. Continuing automatically. Keep this tab open.';
    }

    let lead = currentCheck;
    if (filesSkipped && (phaseKey === 'files' || phaseKey === 'file' || !phaseKey) && !unitType) {
      lead = 'Checking database';
    } else if (dbSkipped && (phaseKey === 'database' || phaseKey === 'db') && !unitType) {
      lead = 'Scanning files';
    }
    const parts = [lead];
    if (dbish && dbRowsScanned > 0) {
      const est = Number(dbRowsEstimate) || 0;
      parts.push(
        est > 0
          ? `${dbRowsScanned.toLocaleString()} / ~${est.toLocaleString()} rows`
          : `${dbRowsScanned.toLocaleString()} rows scanned`
      );
    } else if (filesish && filesScanned > 0) {
      parts.push(`${filesScanned.toLocaleString()} files`);
    }
    if (dbSkipped) parts.push('DB skipped');
    if (filesSkipped) parts.push('Files skipped');
    if (malwareFound > 0) {
      parts.push(`${malwareFound} signature match${malwareFound === 1 ? '' : 'es'}`);
    } else if (integrityFound > 0) {
      parts.push(`${integrityFound} integrity finding${integrityFound === 1 ? '' : 's'}`);
    } else if (threatsFound > 0) {
      parts.push(`${threatsFound} finding${threatsFound === 1 ? '' : 's'}`);
    }
    if (stepsHint) parts.push(stepsHint);
    return parts.join(' · ');
  })();

  function formatPauseReason(reason) {
    if (!reason) return null;
    if (
      reason === 'time_budget' ||
      reason === 'time_limit' ||
      reason === 'time_budget_exceeded' ||
      reason === 'nothing_claimable' ||
      reason === 'in_flight_busy' ||
      reason === 'deliberate_pause' ||
      reason === 'scan_paused'
    ) {
      return 'Saved this slice. Next step starts automatically.';
    }
    if (reason === 'gateway_timeout' || reason === 'network_error') {
      return 'Host gateway timed out (504). Progress is kept. Retrying automatically.';
    }
    return String(reason).replace(/_/g, ' ');
  }
</script>

{#if isActive}
  <div
    class="rounded-xl border p-5 mb-6 transition-colors
      {isStuck
        ? 'bg-amber-500/5 border-amber-500/30'
        : 'bg-panel border-line'}"
  >
    <!-- Header: phase + chip; % is secondary -->
    <div class="flex items-start justify-between gap-3 mb-3">
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2 flex-wrap">
          <h3 class="text-sm font-semibold text-ink truncate">{profileLabel}</h3>
          <span class="text-[10px] px-2 py-0.5 rounded-full border font-medium {statusChip.class}">
            {statusChip.text}
          </span>
          {#if restrictedHost}
            <span
              class="text-[10px] px-2 py-0.5 rounded-full border font-medium bg-amber-500/10 text-amber-800 dark:text-amber-300 border-amber-500/30"
            >
              Restricted host
            </span>
          {/if}
        </div>
        <p class="text-sm text-ink mt-1.5 leading-snug">{primaryLine}</p>
        {#if queueBusy && !isPaused}
          <p class="text-[11px] text-muted mt-1">A step is running now.</p>
        {/if}
        {#if lastActivityLabel && !isStuck}
          <p class="text-[11px] text-faint mt-1">{lastActivityLabel}</p>
        {/if}
        {#if isPaused && formatPauseReason(pauseReason)}
          <p class="text-[11px] text-muted mt-0.5">{formatPauseReason(pauseReason)}</p>
        {/if}
      </div>
      <div class="text-right flex-shrink-0 pl-2">
        <div class="text-xs text-faint uppercase tracking-wide">Progress</div>
        <div class="text-base font-mono font-semibold text-muted">{percentLabel}</div>
        <div class="text-[10px] text-faint mt-0.5 max-w-[7rem]">{estimateHint}</div>
      </div>
    </div>

    <!-- Progress bar (supporting) -->
    <div class="h-1.5 bg-elevated rounded-full overflow-hidden mb-1">
      <div
        class="h-full transition-all duration-500 ease-out"
        style="width: {barWidth}%; background: {barColor};"
      ></div>
    </div>
    {#if censusish}
      <p class="text-[11px] text-faint mb-3 leading-snug">
        Cataloging the site — not a malware scan.{#if filesScanned > 0} The file count is from earlier steps.{/if}
      </p>
    {:else if filesFlatHint}
      <p class="text-[11px] text-faint mb-3 leading-snug">
        File count is from this slice. Remaining steps include checksums and database work.
      </p>
    {:else}
      <div class="mb-4"></div>
    {/if}

    <!-- Live counters -->
    <div class="grid grid-cols-3 gap-3 mb-4">
      <div class="rounded-lg bg-app border border-line px-3 py-2 text-center">
        <div class="text-sm font-semibold text-ink tabular-nums">{filesDisplay}</div>
        <div class="text-[10px] text-muted mt-0.5">{filesLabel}</div>
      </div>
      <div class="rounded-lg bg-app border border-line px-3 py-2 text-center">
        <div class="text-sm font-semibold text-ink tabular-nums">
          {dbRowsScanned > 0 ? dbRowsScanned.toLocaleString() : '—'}
        </div>
        <div class="text-[10px] text-muted mt-0.5">DB rows</div>
      </div>
      <div class="rounded-lg bg-app border border-line px-3 py-2 text-center">
        <div
          class="text-sm font-semibold tabular-nums {malwareFound > 0 || threatsFound > 0
            ? 'text-red-700 dark:text-red-400'
            : 'text-ink'}"
        >
          {(malwareFound > 0 ? malwareFound : threatsFound) > 0
            ? (malwareFound > 0 ? malwareFound : threatsFound).toLocaleString()
            : '—'}
        </div>
        <div class="text-[10px] text-muted mt-0.5">
          {#if malwareFound > 0 && integrityFound > 0}
            Signatures · {integrityFound} integrity
          {:else if integrityFound > 0 && malwareFound === 0}
            Integrity
          {:else}
            Signatures
          {/if}
        </div>
      </div>
    </div>

    {#if restrictedHost && isActive}
      <p class="text-[11px] text-muted mb-3 leading-relaxed">
        This host pauses the scan in short slices. Keep this tab open. It continues
        automatically. Prefer <span class="text-ink font-medium">Quick Scan</span> next time for a faster pass.
      </p>
    {/if}

    <!-- Actions -->
    <div class="flex items-center gap-2 flex-wrap">
      <button
        type="button"
        onclick={onCancel}
        class="px-3 py-1.5 text-xs text-muted hover:text-ink hover:bg-elevated rounded-md border border-transparent hover:border-line transition-colors"
      >
        Cancel
      </button>
      <div class="flex-1"></div>
      {#if canContinue && (isPaused || isStuck)}
        <button
          type="button"
          onclick={onContinue}
          class="px-3 py-1.5 text-xs font-medium rounded-md border transition-colors
            {isStuck
              ? 'bg-amber-500/20 hover:bg-amber-500/30 text-amber-800 dark:text-amber-200 border-amber-500/40'
              : 'bg-elevated hover:bg-hover text-ink border-line'}"
        >
          Continue now
        </button>
      {/if}
    </div>

    <!-- Technical details -->
    {#if showTechnical}
      <div class="mt-3 pt-3 border-t border-line">
        <button
          type="button"
          class="text-[11px] text-muted hover:text-ink transition-colors"
          onclick={() => (detailsOpen = !detailsOpen)}
        >
          {detailsOpen ? '▾ Hide technical details' : '▸ Technical details'}
        </button>
        {#if detailsOpen}
          <div class="mt-2 grid grid-cols-4 gap-2 text-center text-[10px]">
            <div class="rounded bg-app border border-line py-1.5">
              <div class="text-ink font-medium">{pending}</div>
              <div class="text-faint">Pending</div>
            </div>
            <div class="rounded bg-app border border-line py-1.5">
              <div class="text-ink font-medium">{inProgress}</div>
              <div class="text-faint">Active</div>
            </div>
            <div class="rounded bg-app border border-line py-1.5">
              <div class="text-emerald-700 dark:text-emerald-400 font-medium">{completed}</div>
              <div class="text-faint">Done</div>
            </div>
            <div class="rounded bg-app border border-line py-1.5">
              <div class="text-ink font-medium">{failed}</div>
              <div class="text-faint">Failed</div>
            </div>
          </div>
          <dl class="mt-2 space-y-1 text-[11px] text-muted">
            <div class="flex gap-2 min-w-0">
              <dt class="text-faint shrink-0 w-24">Current check</dt>
              <dd class="text-ink min-w-0 truncate" title={currentCheck}>{currentCheck}</dd>
            </div>
            <div class="flex gap-2 min-w-0">
              <dt class="text-faint shrink-0 w-24">Last scanned</dt>
              <dd class="font-mono text-ink min-w-0 truncate" title={lastFilePath || ''}>
                {#if lastFilePath}
                  {relativePath(lastFilePath)}
                {:else if filesScanned > 0}
                  <span class="text-muted">(path pending · {Number(filesScanned).toLocaleString()} scanned)</span>
                {:else}
                  —
                {/if}
              </dd>
            </div>
            <div class="flex gap-2 min-w-0">
              <dt class="text-faint shrink-0 w-24">Last DB</dt>
              <dd class="font-mono text-ink min-w-0 truncate" title={lastDbTable || ''}>
                {#if lastDbTable}
                  {shortTable(lastDbTable)}{lastDbId ? ` #${lastDbId}` : ''}{lastDbKey ? ` ${lastDbKey}` : ''}{lastDbBytes ? ` ${formatDbBytes(lastDbBytes)}` : ''}{lastDbMode && lastDbMode !== 'full' ? ` ${lastDbMode}` : ''}
                {:else}
                  —
                {/if}
              </dd>
            </div>
            {#if checksumNote || checksumChecked}
              <div class="flex gap-2 min-w-0">
                <dt class="text-faint shrink-0 w-24">Core checksums</dt>
                <dd class="min-w-0 truncate" title={checksumNote || ''}>
                  {checksumNote || `${checksumChecked} files${checksumVersion ? ` · WP ${checksumVersion}` : ''}`}
                </dd>
              </div>
            {/if}
            {#if packageChecksumNote}
              <div class="flex gap-2 min-w-0">
                <dt class="text-faint shrink-0 w-24">Pkg checksums</dt>
                <dd class="min-w-0 truncate" title={packageChecksumNote}>{packageChecksumNote}</dd>
              </div>
            {/if}
          </dl>
          <p class="mt-2 text-[10px] text-faint">
            Queue-driven scan · progress is an estimate · auto-continue while this tab is open
          </p>
          {#if technicalMessage}
            <p class="mt-1 text-[10px] text-faint font-mono truncate" title={technicalMessage}>{technicalMessage}</p>
          {/if}
        {/if}
      </div>
    {/if}
  </div>
{/if}
