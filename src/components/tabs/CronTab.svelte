<script>
  import { cronAudit, cronEventsView } from '../../lib/stores/cron.js';
  import ConfirmDialog from '../common/ConfirmDialog.svelte';

  function statusDot(status) {
    switch (status) {
      case 'critical': return 'bg-red-500';
      case 'warning': return 'bg-orange-500';
      case 'info': return 'bg-blue-500';
      case 'healthy': return 'bg-emerald-500';
      default: return 'bg-zinc-500';
    }
  }

  function issueText(issue) {
    return typeof issue === 'string' ? issue : (issue?.message || issue?.code || '');
  }

  let dialog = $state({
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirm',
    onConfirm: undefined,
  });

  async function runAudit() {
    await cronAudit.runAudit();
  }

  async function deleteOne(ev) {
    const label = ev.hook || ev.id;
    dialog = {
      open: true,
      title: 'Remove scheduled item?',
      message: `Remove scheduled item "${label}"? This cannot be undone.`,
      confirmLabel: 'Remove',
      onConfirm: () => cronAudit.deleteEvent(ev),
    };
  }

  async function deleteSelected() {
    if (!$cronAudit.selectedKeys.length) return;
    const n = $cronAudit.selectedKeys.length;
    dialog = {
      open: true,
      title: 'Delete selected items?',
      message: `Delete ${n} selected scheduled item(s)? This cannot be undone.`,
      confirmLabel: 'Delete selected',
      onConfirm: () => cronAudit.deleteSelected(),
    };
  }

  let summary = $derived($cronAudit.results?.summary || {});
  let serverCron = $derived($cronAudit.results?.server_crontab || {});
  let transientHooks = $derived($cronAudit.results?.sensitive_hooks || []);
</script>

<div class="h-full overflow-y-auto">
  <div class="p-6 max-w-5xl mx-auto">
    <div class="mb-8">
      <div class="flex items-center gap-3 mb-2">
        <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center">
          <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
        <div>
          <h1 class="text-xl font-bold text-ink">Cron & scheduled tasks</h1>
          <p class="text-sm text-muted">WP-Cron, Action Scheduler, callback origins, optional server crontab</p>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
      <button
        type="button"
        onclick={runAudit}
        disabled={$cronAudit.auditing}
        class="col-span-2 p-4 bg-gradient-to-r from-amber-500/10 to-amber-600/5 border border-amber-500/20 rounded-xl hover:border-amber-500/40 transition-all text-left disabled:opacity-50"
      >
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-ink mb-1">
              {$cronAudit.auditing ? 'Auditing…' : 'Run cron audit'}
            </p>
            <p class="text-xs text-muted">
              {$cronAudit.lastAuditedAt
                ? `Last: ${new Date($cronAudit.lastAuditedAt).toLocaleString()}`
                : 'Live events + path scoring'}
            </p>
          </div>
          {#if $cronAudit.auditing}
            <svg class="w-6 h-6 text-amber-700 dark:text-amber-400 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
          {/if}
        </div>
      </button>
      <div class="p-3 bg-panel border border-line rounded-xl">
        <div class="text-[10px] text-muted mb-0.5">WP-Cron</div>
        <div class="text-xl font-bold text-ink">{summary.wp_cron_events ?? '—'}</div>
      </div>
      <div class="p-3 bg-panel border border-line rounded-xl">
        <div class="text-[10px] text-muted mb-0.5">Critical</div>
        <div class="text-xl font-bold text-red-700 dark:text-red-400">{summary.critical ?? '—'}</div>
      </div>
      <div class="p-3 bg-panel border border-line rounded-xl">
        <div class="text-[10px] text-muted mb-0.5">Action Sched.</div>
        <div class="text-xl font-bold text-amber-800 dark:text-amber-300">
          {summary.as_available ? (summary.as_actions ?? 0) : 'n/a'}
        </div>
      </div>
    </div>

    {#if $cronAudit.error}
      <div class="mb-4 p-3 rounded-lg border border-red-500/30 bg-red-500/10 text-xs text-red-700 dark:text-red-300">
        {$cronAudit.error}
      </div>
    {/if}

    {#if $cronAudit.results}
      <div class="flex flex-wrap gap-2 mb-4">
        {#each [
          { id: 'issues', label: 'Issues only' },
          { id: 'all', label: 'All events' },
          { id: 'wp_cron', label: 'WP-Cron' },
          { id: 'action_scheduler', label: 'Action Scheduler' },
        ] as f}
          <button
            type="button"
            onclick={() => cronAudit.setFilter(f.id)}
            class="px-3 py-1.5 text-xs rounded-md border transition-colors
              {$cronAudit.filter === f.id
                ? 'bg-amber-500/15 text-amber-800 dark:text-amber-300 border-amber-500/30'
                : 'text-muted border-line hover:text-ink'}"
          >
            {f.label}
          </button>
        {/each}
      </div>

      <!-- Server crontab -->
      <div class="mb-4 p-3 rounded-xl border border-line bg-panel">
        <h3 class="text-xs font-semibold text-ink mb-1">Server crontab (web user)</h3>
        {#if !serverCron.available}
          <p class="text-[11px] text-muted">{serverCron.note || 'Not available on this host'}</p>
        {:else if serverCron.entries?.length}
          <div class="space-y-1 mt-2">
            {#each serverCron.entries as entry}
              <div class="text-[11px] font-mono rounded px-2 py-1.5
                {entry.status === 'critical'
                  ? 'text-red-800/90 dark:text-red-200/90 bg-red-500/5 border border-red-500/25'
                  : 'text-orange-800/90 dark:text-orange-200/90 bg-orange-500/5 border border-orange-500/20'}">
                {entry.line}
                <div class="mt-0.5 {entry.status === 'critical' ? 'text-red-700/80 dark:text-red-400/80' : 'text-orange-700/80 dark:text-orange-400/80'}">
                  {(entry.reasons || []).join(' · ')}
                </div>
              </div>
            {/each}
          </div>
        {:else}
          <p class="text-[11px] text-muted">
            {serverCron.note || 'No suspicious lines (wget/curl/php → site) found for this user.'}
            {#if serverCron.raw_lines}
              <span class="text-faint">({serverCron.raw_lines} lines)</span>
            {/if}
          </p>
        {/if}
      </div>

      {#if transientHooks.length}
        <div class="mb-4 p-3 rounded-xl border border-orange-500/20 bg-orange-500/5">
          <h3 class="text-xs font-semibold text-orange-700 dark:text-orange-300 mb-2">Transient-related hooks (review paths)</h3>
          {#each transientHooks as h}
            <div class="text-[11px] text-muted mb-1">
              <span class="text-ink font-mono">{h.hook}</span> → {h.callback}
              {#if h.file}<div class="font-mono text-faint truncate">{h.file}</div>{/if}
            </div>
          {/each}
        </div>
      {/if}

      <div class="bg-panel border border-line rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-line flex items-center justify-between">
          <span class="text-xs text-muted">{$cronEventsView.length} event(s)</span>
          <div class="flex items-center gap-2">
            <button type="button" onclick={() => cronAudit.selectSuspicious()} class="text-xs text-muted hover:text-ink">Select issues</button>
            <span class="text-faint">|</span>
            <button type="button" onclick={() => cronAudit.clearSelected()} class="text-xs text-muted hover:text-ink">Clear</button>
          </div>
        </div>

        <div class="divide-y divide-line">
          {#each $cronEventsView as ev (ev.id)}
            {@const expanded = $cronAudit.expandedKey === ev.id}
            {@const selected = $cronAudit.selectedKeys.includes(ev.id)}
            <div class="hover:bg-hover {selected ? 'bg-primary/5' : ''}">
              <div class="px-5 py-3 flex items-start gap-3">
                <input
                  type="checkbox"
                  class="mt-1"
                  checked={selected}
                  onchange={() => cronAudit.toggleSelected(ev.id)}
                />
                <button type="button" class="flex-1 min-w-0 text-left" onclick={() => cronAudit.expand(ev.id)}>
                  <div class="flex items-start gap-2">
                    <span class="w-2.5 h-2.5 rounded-full mt-1.5 flex-shrink-0 {statusDot(ev.status)}"></span>
                    <div class="min-w-0 flex-1">
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium text-ink font-mono truncate">{ev.hook}</span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-elevated text-muted">
                          {ev.source === 'action_scheduler' ? 'AS' : 'WP-Cron'}
                        </span>
                        {#if ev.is_core}
                          <span class="text-[10px] text-emerald-500/80">core</span>
                        {:else if ev.is_trusted}
                          <span class="text-[10px] text-emerald-500/60">trusted</span>
                        {/if}
                      </div>
                      {#if ev.callbacks?.[0]}
                        <p class="text-[11px] text-muted font-mono truncate">
                          {ev.callbacks[0].label}
                          {#if ev.callbacks[0].file}
                            · {ev.callbacks[0].file}
                          {/if}
                        </p>
                        {#if ev.callbacks[0].sniff_reasons?.length}
                          <p class="text-[10px] text-orange-700 dark:text-orange-300 mt-0.5 truncate">
                            sniff: {ev.callbacks[0].sniff_reasons.slice(0, 2).join('; ')}
                          </p>
                        {/if}
                      {:else}
                        <p class="text-[11px] text-faint italic">No registered callback</p>
                      {/if}
                      {#if ev.issues?.length}
                        <div class="flex flex-wrap gap-1 mt-1">
                          {#each ev.issues.slice(0, 3) as issue}
                            <span class="px-1.5 py-0.5 text-[10px] rounded
                              {(typeof issue === 'object' && issue.severity === 'critical')
                                ? 'bg-red-500/10 text-red-700 dark:text-red-400'
                                : (typeof issue === 'object' && (issue.severity === 'warning' || issue.severity === 'high'))
                                  ? 'bg-orange-500/10 text-orange-700 dark:text-orange-300'
                                  : 'bg-elevated text-muted'}">
                              {issueText(issue)}
                            </span>
                          {/each}
                          {#if ev.issues.length > 3}
                            <span class="text-[10px] text-faint">+{ev.issues.length - 3}</span>
                          {/if}
                        </div>
                      {/if}
                    </div>
                    <div class="text-right hidden md:block flex-shrink-0 w-36">
                      <p class="text-[10px] text-muted">{ev.schedule || '—'}</p>
                      <p class="text-[10px] text-muted font-mono">{ev.next_run || '—'}</p>
                    </div>
                    <span class="text-xs text-faint">{expanded ? '▾' : '▸'}</span>
                  </div>
                </button>
              </div>

              {#if expanded}
                <div class="px-5 pb-4 pl-12 space-y-2 border-t border-line pt-3">
                  {#if ev.args_preview}
                    <div>
                      <h4 class="text-[10px] uppercase text-muted mb-0.5">Args</h4>
                      <pre class="text-[10px] font-mono text-muted bg-app border border-line rounded p-2 overflow-x-auto whitespace-pre-wrap break-all">{ev.args_preview}</pre>
                    </div>
                  {/if}
                  {#if ev.callbacks?.length}
                    <div>
                      <h4 class="text-[10px] uppercase text-muted mb-0.5">Callbacks</h4>
                      {#each ev.callbacks as cb}
                        <div class="text-[11px] text-ink font-mono mb-1">
                          {cb.label}
                          <div class="text-faint">{cb.file || '—'} · {cb.origin_kind} · {cb.origin_risk}</div>
                          {#if cb.sniff_reasons?.length}
                            <div class="text-orange-700 dark:text-orange-300 text-[10px]">
                              Content: {cb.sniff_reasons.join('; ')}
                            </div>
                          {/if}
                        </div>
                      {/each}
                    </div>
                  {/if}
                  {#if ev.issues?.length}
                    <div>
                      <h4 class="text-[10px] uppercase text-muted mb-0.5">Why flagged</h4>
                      <ul class="text-[11px] space-y-0.5">
                        {#each ev.issues as issue}
                          <li class="text-muted">· {issueText(issue)}</li>
                        {/each}
                      </ul>
                    </div>
                  {/if}
                  <button
                    type="button"
                    disabled={$cronAudit.acting}
                    onclick={() => deleteOne(ev)}
                    class="px-2.5 py-1 text-[11px] rounded-md bg-red-500/10 text-red-700 dark:text-red-400 border border-red-500/25 hover:bg-red-500/20 disabled:opacity-50"
                  >
                    {ev.source === 'action_scheduler' ? 'Cancel AS action' : 'Unschedule event'}
                  </button>
                </div>
              {/if}
            </div>
          {:else}
            <div class="p-6 text-center text-sm text-muted">
              {#if $cronAudit.filter === 'issues'}
                No suspicious events in this filter. Try “All events”.
              {:else}
                No scheduled events found.
              {/if}
            </div>
          {/each}
        </div>
      </div>

      {#if $cronAudit.selectedKeys.length > 0}
        <div class="fixed bottom-6 left-1/2 -translate-x-1/2 flex items-center gap-3 px-5 py-3 bg-elevated border border-red-500/30 rounded-xl shadow-2xl z-20">
          <span class="text-sm text-ink">{$cronAudit.selectedKeys.length} selected</span>
          <button
            type="button"
            disabled={$cronAudit.acting}
            onclick={deleteSelected}
            class="px-4 py-2 text-sm font-medium bg-red-500/10 text-red-700 dark:text-red-400 rounded-lg hover:bg-red-500/20 disabled:opacity-50"
          >
            Delete selected
          </button>
        </div>
      {/if}
    {:else}
      <div class="py-16 text-center">
        <h3 class="text-sm font-medium text-ink mb-1">No cron audit yet</h3>
        <p class="text-xs text-muted mb-4">Scan live WP-Cron events and Action Scheduler (if present).</p>
        <button
          type="button"
          onclick={runAudit}
          class="px-4 py-2 text-sm font-medium rounded-md bg-amber-600 hover:bg-amber-500 text-white border border-amber-600/50 shadow-sm transition-colors"
        >
          Run cron audit
        </button>
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
    variant="danger"
    onConfirm={() => {
      const fn = dialog.onConfirm;
      dialog = { ...dialog, open: false, onConfirm: undefined };
      fn?.();
    }}
    onCancel={() => {
      dialog = { ...dialog, open: false, onConfirm: undefined };
    }}
  />
{/if}
