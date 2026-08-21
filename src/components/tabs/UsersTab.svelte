<script>
  import { usersAudit, usersList } from '../../lib/stores/users.js';
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

  function issueSev(issue) {
    return typeof issue === 'object' ? (issue.severity || 'info') : 'info';
  }

  let dialog = $state({
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirm',
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
      variant: opts.variant || 'primary',
      alertOnly: !!opts.alertOnly,
      onConfirm: opts.onConfirm,
    };
  }

  async function runAudit() {
    await usersAudit.runAudit();
  }

  async function onDemote(user) {
    if (user.is_current_user) {
      openDialog({
        title: 'Cannot demote',
        message: 'You cannot demote the account currently running Clean Sweep.',
        confirmLabel: 'OK',
        alertOnly: true,
        variant: 'neutral',
      });
      return;
    }
    openDialog({
      title: 'Demote user?',
      message: `Demote "${user.username}" to subscriber? They will lose admin capabilities.`,
      confirmLabel: 'Demote to subscriber',
      variant: 'danger',
      onConfirm: () => usersAudit.demoteUser(user.id),
    });
  }

  async function onDelete(user) {
    if (user.is_current_user) {
      openDialog({
        title: 'Cannot delete',
        message: 'You cannot delete the account currently running Clean Sweep.',
        confirmLabel: 'OK',
        alertOnly: true,
        variant: 'neutral',
      });
      return;
    }
    openDialog({
      title: 'Delete user?',
      message: `Delete user "${user.username}"? Content reassignment may be needed for authors. This cannot be undone.`,
      confirmLabel: 'Delete user',
      variant: 'danger',
      onConfirm: () => usersAudit.deleteUser(user.id),
    });
  }

  async function onRevokeApp(user) {
    openDialog({
      title: 'Revoke application passwords?',
      message: `Revoke all application passwords for "${user.username}"?`,
      confirmLabel: 'Revoke passwords',
      variant: 'danger',
      onConfirm: () => usersAudit.revokeAppPasswords(user.id),
    });
  }

  async function onDestroySessions(user) {
    openDialog({
      title: 'Destroy sessions?',
      message: `Destroy all sessions for "${user.username}"? They will need to log in again.`,
      confirmLabel: 'Destroy sessions',
      variant: 'danger',
      onConfirm: () => usersAudit.destroySessions(user.id),
    });
  }

  let summary = $derived($usersAudit.results?.summary || {});
  let superAdmins = $derived($usersAudit.results?.super_admins || []);
  let sensitiveHooks = $derived($usersAudit.results?.sensitive_hooks || []);
  let siteFindings = $derived($usersAudit.results?.site_findings || []);
</script>

<div class="h-full overflow-y-auto">
  <div class="p-6 max-w-5xl mx-auto">
    <div class="mb-8">
      <div class="flex items-center gap-3 mb-2">
        <div class="w-10 h-10 rounded-xl bg-violet-500/10 flex items-center justify-center">
          <svg class="w-5 h-5 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
          </svg>
        </div>
        <div>
          <h1 class="text-xl font-bold text-ink">Users & Access Audit</h1>
          <p class="text-sm text-muted">Live WordPress users, app passwords, sessions, and access signals</p>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
      <button
        type="button"
        onclick={runAudit}
        disabled={$usersAudit.auditing}
        class="col-span-2 p-4 bg-gradient-to-r from-violet-500/10 to-violet-600/5 border border-violet-500/20 rounded-xl hover:border-violet-500/40 transition-all text-left disabled:opacity-50"
      >
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-ink mb-1">
              {$usersAudit.auditing ? 'Auditing…' : 'Run user audit'}
            </p>
            <p class="text-xs text-muted">
              {$usersAudit.lastAuditedAt
                ? `Last: ${new Date($usersAudit.lastAuditedAt).toLocaleString()}`
                : 'Admins, IOCs, app passwords, sessions'}
            </p>
          </div>
          {#if $usersAudit.auditing}
            <svg class="w-6 h-6 text-violet-700 dark:text-violet-400 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
          {/if}
        </div>
      </button>
      <div class="p-3 bg-panel border border-line rounded-xl">
        <div class="text-[10px] text-muted mb-0.5">Users</div>
        <div class="text-xl font-bold text-ink">{summary.total_users ?? '—'}</div>
      </div>
      <div class="p-3 bg-panel border border-line rounded-xl">
        <div class="text-[10px] text-muted mb-0.5">Admins</div>
        <div class="text-xl font-bold text-violet-800 dark:text-violet-300">{summary.administrators ?? '—'}</div>
      </div>
      <div class="p-3 bg-panel border border-line rounded-xl">
        <div class="text-[10px] text-muted mb-0.5">Critical</div>
        <div class="text-xl font-bold text-red-700 dark:text-red-400">{summary.critical ?? '—'}</div>
      </div>
      {#if (summary.hidden_from_admin || summary.hidden_admins) > 0}
        <button
          type="button"
          onclick={() => usersAudit.setFilter('hidden')}
          class="p-3 bg-red-500/10 border border-red-500/25 rounded-xl text-left hover:border-red-500/40 transition-colors"
        >
          <div class="text-[10px] text-red-700 dark:text-red-400 mb-0.5">Hidden from WP admin</div>
          <div class="text-xl font-bold text-red-700 dark:text-red-400">{summary.hidden_from_admin || summary.hidden_admins}</div>
        </button>
      {/if}
    </div>

    {#if $usersAudit.error}
      <div class="mb-4 p-3 rounded-lg border border-red-500/30 bg-red-500/10 text-xs text-red-700 dark:text-red-300">
        {$usersAudit.error}
      </div>
    {/if}

    {#if $usersAudit.results}
      <div class="flex flex-wrap gap-2 mb-4">
        {#each [
          { id: 'all', label: 'All' },
          { id: 'issues', label: 'Issues only' },
          { id: 'admins', label: 'Administrators' },
          { id: 'hidden', label: `Hidden${(summary.hidden_from_admin || summary.hidden_admins) ? ` (${summary.hidden_from_admin || summary.hidden_admins})` : ''}` },
        ] as f}
          <button
            type="button"
            onclick={() => usersAudit.setFilter(f.id)}
            class="px-3 py-1.5 text-xs rounded-md border transition-colors
              {$usersAudit.filter === f.id
                ? 'bg-violet-500/15 text-violet-800 dark:text-violet-300 border-violet-500/30'
                : 'text-muted border-line hover:text-ink'}"
          >
            {f.label}
          </button>
        {/each}
        <span class="text-[10px] text-faint self-center ml-auto">
          App passwords: {summary.with_app_passwords ?? 0} · Sessions: {summary.with_sessions ?? 0}
        </span>
      </div>

      {#if (summary.hidden_from_admin || summary.hidden_admins) > 0}
        <button
          type="button"
          onclick={() => usersAudit.setFilter('hidden')}
          class="mb-4 w-full text-left p-3 rounded-xl border border-red-500/30 bg-red-500/10"
        >
          <p class="text-xs font-semibold text-red-800 dark:text-red-300">
            {summary.hidden_from_admin || summary.hidden_admins} account{(summary.hidden_from_admin || summary.hidden_admins) === 1 ? '' : 's'} hidden from the WordPress Users screen
          </p>
          <p class="text-[11px] text-muted mt-0.5">
            They still exist in the database. Hide-user malware often only filters wp-admin → Users, so they look like normal accounts unless flagged here.
          </p>
        </button>
      {/if}

      {#if siteFindings.length}
        <div class="mb-4 p-3 rounded-xl border border-red-500/25 bg-red-500/5">
          <h3 class="text-xs font-semibold text-red-700 dark:text-red-300 mb-2">Site-level access findings</h3>
          <div class="space-y-1.5">
            {#each siteFindings as f}
              <div class="text-xs flex items-start gap-2">
                <span class="w-2 h-2 rounded-full mt-1 flex-shrink-0 {statusDot(f.status)}"></span>
                <div>
                  <span class="text-ink">{f.message}</span>
                  {#if f.detail}
                    <span class="text-muted font-mono ml-1">({f.detail})</span>
                  {/if}
                </div>
              </div>
            {/each}
          </div>
        </div>
      {/if}

      {#if superAdmins.length}
        <div class="mb-4 p-3 rounded-xl border border-amber-500/20 bg-amber-500/5">
          <h3 class="text-xs font-semibold text-amber-800 dark:text-amber-300 mb-2">Multisite super admins</h3>
          <div class="space-y-1">
            {#each superAdmins as sa}
              <div class="text-xs text-ink flex items-center gap-2">
                <span class="w-2 h-2 rounded-full {statusDot(sa.status)}"></span>
                <span class="font-mono">{sa.username}</span>
                {#if sa.orphan}
                  <span class="text-red-700 dark:text-red-400">orphan (no matching user)</span>
                {/if}
              </div>
            {/each}
          </div>
        </div>
      {/if}

      {#if sensitiveHooks.length}
        <div class="mb-4 p-3 rounded-xl border border-red-500/20 bg-red-500/5">
          <h3 class="text-xs font-semibold text-red-700 dark:text-red-300 mb-2">Sensitive auth / hide-user / hide-plugin hooks</h3>
          <div class="space-y-2">
            {#each sensitiveHooks as h}
              <div class="text-xs">
                <span class="text-ink font-mono">{h.hook}</span>
                <span class="text-muted"> → {h.callback}</span>
                {#if h.origin_kind}
                  <span class="text-[10px] ml-1 px-1 py-0.5 rounded bg-elevated text-muted">{h.origin_kind}</span>
                {/if}
                {#if h.file}
                  <div class="text-muted font-mono truncate">{h.file}</div>
                {/if}
              </div>
            {/each}
          </div>
        </div>
      {/if}

      <div class="bg-panel border border-line rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-line flex items-center justify-between">
          <span class="text-xs text-muted">{$usersList.length} user(s)</span>
          <div class="flex items-center gap-2">
            <button type="button" onclick={() => usersAudit.selectIssues()} class="text-xs text-muted hover:text-ink">Select issues</button>
            <span class="text-faint">|</span>
            <button type="button" onclick={() => usersAudit.clearSelected()} class="text-xs text-muted hover:text-ink">Clear</button>
          </div>
        </div>

        <div class="divide-y divide-line">
          {#each $usersList as user (user.id)}
            {@const expanded = $usersAudit.expandedId === user.id}
            <div class="hover:bg-hover {(user.hidden_from_admin || user.hidden_admin) ? 'bg-red-500/5' : ''}">
              <button
                type="button"
                class="w-full px-5 py-3 flex items-start gap-3 text-left"
                onclick={() => usersAudit.expand(user.id)}
              >
                <span class="w-2.5 h-2.5 rounded-full mt-1.5 flex-shrink-0 {statusDot(user.status)}"></span>
                <div class="flex-1 min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-ink">{user.username}</span>
                    {#if user.is_current_user}
                      <span class="text-[10px] px-1.5 py-0.5 rounded bg-sky-500/15 text-sky-700 dark:text-sky-300 border border-sky-500/25">you</span>
                    {/if}
                    {#if user.hidden_admin}
                      <span class="text-[10px] px-1.5 py-0.5 rounded bg-red-500/15 text-red-700 dark:text-red-300 border border-red-500/30 font-medium">hidden admin</span>
                    {:else if user.hidden_from_admin}
                      <span class="text-[10px] px-1.5 py-0.5 rounded bg-red-500/15 text-red-700 dark:text-red-300 border border-red-500/30 font-medium">hidden from WP admin</span>
                    {/if}
                    {#each user.roles || [] as role}
                      <span class="px-1.5 py-0.5 text-[10px] bg-elevated text-muted rounded">{role}</span>
                    {/each}
                    {#if user.app_password_count > 0}
                      <span class="text-[10px] text-amber-800 dark:text-amber-300">app pw ×{user.app_password_count}</span>
                    {/if}
                    {#if user.session_count > 0}
                      <span class="text-[10px] text-muted">sessions ×{user.session_count}</span>
                    {/if}
                  </div>
                  <p class="text-xs text-muted truncate">{user.email}</p>
                  {#if user.issues?.length}
                    <div class="flex flex-wrap gap-1 mt-1.5">
                      {#each user.issues.slice(0, 4) as issue}
                        <span class="px-1.5 py-0.5 text-[10px] rounded
                          {issueSev(issue) === 'critical' ? 'bg-red-500/10 text-red-700 dark:text-red-400' :
                            issueSev(issue) === 'warning' || issueSev(issue) === 'high' ? 'bg-orange-500/10 text-orange-700 dark:text-orange-300' :
                            'bg-elevated text-muted'}">
                          {issueText(issue)}
                        </span>
                      {/each}
                      {#if user.issues.length > 4}
                        <span class="text-[10px] text-faint">+{user.issues.length - 4}</span>
                      {/if}
                    </div>
                  {/if}
                </div>
                <div class="text-right hidden md:block flex-shrink-0">
                  <p class="text-[10px] text-muted">Registered</p>
                  <p class="text-[11px] text-muted font-mono">{user.registered || '—'}</p>
                </div>
                <span class="text-xs text-faint">{expanded ? '▾' : '▸'}</span>
              </button>

              {#if expanded}
                <div class="px-5 pb-4 pl-12 space-y-3 border-t border-line pt-3">
                  {#if user.password_hash_family}
                    <div class="text-xs text-muted">
                      Password hash:
                      <span class="font-mono text-ink">{user.password_hash_family}</span>
                      <span class="text-faint">(algorithm family, not password strength)</span>
                    </div>
                  {/if}
                  {#if user.url}
                    <div class="text-xs text-muted">URL: <span class="font-mono text-ink">{user.url}</span></div>
                  {/if}
                  {#if user.application_passwords?.length}
                    <div>
                      <h4 class="text-[10px] uppercase text-muted mb-1">Application passwords</h4>
                      <ul class="text-xs text-ink space-y-0.5">
                        {#each user.application_passwords as ap}
                          <li class="font-mono">{ap.name}{#if ap.created} · created {new Date(ap.created * 1000).toLocaleDateString()}{/if}</li>
                        {/each}
                      </ul>
                    </div>
                  {/if}
                  {#if user.sessions?.length}
                    <div>
                      <h4 class="text-[10px] uppercase text-muted mb-1">Sessions</h4>
                      <div class="space-y-1 max-h-40 overflow-y-auto">
                        {#each user.sessions as sess}
                          <div class="text-[11px] text-muted font-mono">
                            {sess.ip || 'no-ip'}
                            {#if sess.bot_ua}<span class="text-orange-700 dark:text-orange-300"> bot-ua</span>{/if}
                            {#if sess.blank_ua}<span class="text-orange-700 dark:text-orange-300"> blank-ua</span>{/if}
                            {#if sess.dc_ip_hint}<span class="text-orange-700 dark:text-orange-300"> cloud-ip</span>{/if}
                            <div class="text-faint truncate">{sess.ua || '(empty UA)'}</div>
                          </div>
                        {/each}
                      </div>
                    </div>
                  {/if}
                  {#if user.raw_capabilities?.length && user.hidden_admin}
                    <div>
                      <h4 class="text-[10px] uppercase text-muted mb-1">Raw capabilities meta</h4>
                      <p class="text-[11px] font-mono text-muted break-all">{user.raw_capabilities.slice(0, 20).join(', ')}</p>
                    </div>
                  {/if}
                  <div class="flex flex-wrap gap-2 pt-1">
                    {#if user.app_password_count > 0}
                      <button type="button" disabled={$usersAudit.acting} onclick={() => onRevokeApp(user)}
                        class="px-2.5 py-1 text-[11px] rounded-md bg-amber-500/10 text-amber-800 dark:text-amber-300 border border-amber-500/25 hover:bg-amber-500/20 disabled:opacity-50">
                        Revoke app passwords
                      </button>
                    {/if}
                    {#if user.session_count > 0}
                      <button type="button" disabled={$usersAudit.acting} onclick={() => onDestroySessions(user)}
                        class="px-2.5 py-1 text-[11px] rounded-md bg-sky-500/10 text-sky-700 dark:text-sky-300 border border-sky-500/25 hover:bg-sky-500/20 disabled:opacity-50">
                        Destroy sessions
                      </button>
                    {/if}
                    {#if user.is_administrator && !user.is_current_user}
                      <button type="button" disabled={$usersAudit.acting} onclick={() => onDemote(user)}
                        class="px-2.5 py-1 text-[11px] rounded-md bg-yellow-500/10 text-yellow-700 dark:text-yellow-300 border border-yellow-500/25 hover:bg-yellow-500/20 disabled:opacity-50">
                        Demote to subscriber
                      </button>
                    {/if}
                    {#if !user.is_current_user}
                      <button type="button" disabled={$usersAudit.acting} onclick={() => onDelete(user)}
                        class="px-2.5 py-1 text-[11px] rounded-md bg-red-500/10 text-red-700 dark:text-red-400 border border-red-500/25 hover:bg-red-500/20 disabled:opacity-50">
                        Delete user
                      </button>
                    {/if}
                  </div>
                </div>
              {/if}
            </div>
          {:else}
            <div class="p-6 text-center text-sm text-muted">No users match this filter.</div>
          {/each}
        </div>
      </div>
    {:else}
      <div class="py-16 text-center">
        <h3 class="text-sm font-medium text-ink mb-1">No audit yet</h3>
        <p class="text-xs text-muted mb-4">Run an audit to load live WordPress users and access signals.</p>
        <button
          type="button"
          onclick={runAudit}
          class="px-4 py-2 text-sm font-medium rounded-md bg-violet-600 hover:bg-violet-500 text-white border border-violet-600/50 shadow-sm transition-colors"
        >
          Run user audit
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
    variant={dialog.variant}
    alertOnly={dialog.alertOnly}
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
