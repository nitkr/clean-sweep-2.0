<script>
  /**
   * Cleanup cockpit — status of real tools + suggested path + launchers.
   * No composite health score.
   */
  import { onMount } from 'svelte';
  import { app } from '../lib/stores/app.js';
  import { scanning } from '../lib/stores/scanning.js';
  import { vulnerabilities, vulnCounts } from '../lib/stores/vulnerabilities.js';
  import { usersAudit } from '../lib/stores/users.js';
  import { cronAudit } from '../lib/stores/cron.js';
  import { integrity } from '../lib/stores/integrity.js';
  import { core } from '../lib/stores/core.js';
  import { plugins } from '../lib/stores/plugins.js';
  import { themes } from '../lib/stores/themes.js';
  import { upload } from '../lib/stores/upload.js';
  import { APP } from '../config/constants.ts';

  onMount(() => {
    // Light context only — no fake scans
    core.fetchVersionOptions?.().catch(() => {});
    integrity.loadBaselineInfo?.().catch(() => {});
  });

  function go(tab) {
    app.setActiveTab(tab);
  }

  function formatRelative(ts) {
    if (!ts) return null;
    const sec = Math.floor((Date.now() - ts) / 1000);
    if (sec < 60) return 'just now';
    if (sec < 3600) return `${Math.floor(sec / 60)}m ago`;
    if (sec < 86400) return `${Math.floor(sec / 3600)}h ago`;
    return `${Math.floor(sec / 86400)}d ago`;
  }


  // ── Malware card ──────────────────────────────────────────
  let malwareCard = $derived.by(() => {
    if ($scanning.scanning) {
      const pct = Math.round($scanning.progressPercent || 0);
      const paused = $scanning.progressStatus === 'paused';
      return {
        status: paused ? 'running' : 'running',
        label: paused ? `Continuing… ${pct}%` : `Scanning ${pct}%`,
        detail: $scanning.progressMessage || 'In progress',
        color: 'emerald',
        live: true,
      };
    }
    if ($scanning.results) {
      const malware =
        $scanning.results?.summary?.malware_threats ??
        $scanning.results?.malware_threats?.length ??
        0;
      const integrity =
        $scanning.results?.summary?.integrity_violations ??
        $scanning.results?.integrity_violations_list?.length ??
        0;
      const n = malware + integrity;
      let label = 'Last scan clean';
      if (malware > 0 && integrity > 0) {
        label = `${malware} signature · ${integrity} integrity`;
      } else if (malware > 0) {
        label = `${malware} signature match${malware === 1 ? '' : 'es'}`;
      } else if (integrity > 0) {
        label = `${integrity} integrity finding${integrity === 1 ? '' : 's'}`;
      }
      return {
        status: n > 0 ? 'issues' : 'clean',
        label,
        detail: $scanning.scanDuration
          ? `${(Math.round($scanning.scanDuration / 1000))}s · open Scanner`
          : 'Open Scanner to review',
        color: malware > 0 ? 'red' : integrity > 0 ? 'orange' : 'emerald',
        live: false,
      };
    }
    return {
      status: 'idle',
      label: 'Not run yet',
      detail: 'Malware scan of files & database',
      color: 'zinc',
      live: false,
    };
  });

  // ── Vulns ─────────────────────────────────────────────────
  let vulnCard = $derived.by(() => {
    if ($vulnerabilities.scanning) {
      return { status: 'running', label: 'Checking…', detail: 'Known CVEs', color: 'orange', live: true };
    }
    if ($vulnerabilities.results) {
      const n = $vulnCounts.total || 0;
      return {
        status: n > 0 ? 'issues' : 'clean',
        label: n > 0 ? `${n} known issue${n === 1 ? '' : 's'}` : 'No known CVEs found',
        detail: formatRelative($vulnerabilities.lastScannedAt) || 'Last check',
        color: n > 0 ? 'orange' : 'emerald',
        live: false,
      };
    }
    return { status: 'idle', label: 'Not run yet', detail: 'Core / plugin / theme CVEs', color: 'zinc', live: false };
  });

  // ── Users ─────────────────────────────────────────────────
  let usersCard = $derived.by(() => {
    if ($usersAudit.auditing) {
      return { status: 'running', label: 'Auditing…', detail: 'Users & access', color: 'violet', live: true };
    }
    if ($usersAudit.results) {
      const s = $usersAudit.results.summary || {};
      const crit = s.critical || 0;
      const warn = s.warning || 0;
      const admins = s.administrators || 0;
      if (crit + warn > 0) {
        return {
          status: 'issues',
          label: `${crit} critical · ${warn} warning`,
          detail: `${s.total_users ?? '—'} users · ${admins} admin${admins === 1 ? '' : 's'}`,
          color: crit > 0 ? 'red' : 'orange',
          live: false,
        };
      }
      return {
        status: 'clean',
        label: `${admins} administrator${admins === 1 ? '' : 's'}`,
        detail: formatRelative($usersAudit.lastAuditedAt) || 'No major issues',
        color: 'emerald',
        live: false,
      };
    }
    return { status: 'idle', label: 'Not run yet', detail: 'Admins, app passwords, sessions', color: 'zinc', live: false };
  });

  // ── Cron ──────────────────────────────────────────────────
  let cronCard = $derived.by(() => {
    if ($cronAudit.auditing) {
      return { status: 'running', label: 'Auditing…', detail: 'Scheduled tasks', color: 'amber', live: true };
    }
    if ($cronAudit.results) {
      const s = $cronAudit.results.summary || {};
      const crit = s.critical || 0;
      const warn = s.warning || 0;
      const events = s.wp_cron_events ?? 0;
      if (crit + warn > 0) {
        return {
          status: 'issues',
          label: `${crit} critical · ${warn} warning`,
          detail: `${events} WP-Cron event${events === 1 ? '' : 's'}`,
          color: crit > 0 ? 'red' : 'orange',
          live: false,
        };
      }
      return {
        status: 'clean',
        label: `${events} scheduled event${events === 1 ? '' : 's'}`,
        detail: formatRelative($cronAudit.lastAuditedAt) || 'No major issues',
        color: 'emerald',
        live: false,
      };
    }
    return { status: 'idle', label: 'Not run yet', detail: 'WP-Cron & Action Scheduler', color: 'zinc', live: false };
  });

  // ── Integrity ─────────────────────────────────────────────
  let integrityCard = $derived.by(() => {
    if ($integrity.checking) {
      return { status: 'running', label: 'Checking…', detail: 'File integrity', color: 'violet', live: true };
    }
    if ($scanning.integrityViolations > 0) {
      return {
        status: 'issues',
        label: `${$scanning.integrityViolations} baseline mismatch${$scanning.integrityViolations === 1 ? '' : 'es'}`,
        detail: 'From last malware scan',
        color: 'violet',
        live: false,
      };
    }
    if ($integrity.baselineExists) {
      return {
        status: 'clean',
        label: 'Baseline active',
        detail: $integrity.baselineInfo?.mode || 'Post-reinstall baseline',
        color: 'emerald',
        live: false,
      };
    }
    return {
      status: 'idle',
      label: 'No baseline',
      detail: 'Set after Core Reinstall',
      color: 'zinc',
      live: false,
    };
  });

  function statusDot(color) {
    switch (color) {
      case 'emerald': return 'bg-emerald-500';
      case 'red': return 'bg-red-500';
      case 'orange': return 'bg-orange-500';
      case 'amber': return 'bg-amber-500';
      case 'violet': return 'bg-violet-500';
      default: return 'bg-zinc-500';
    }
  }

  // Cleanup path ticks from real store state
  let visit = $derived($integrity.visitStatus || $integrity.baselineInfo || {});
  let coreSealed = $derived(!!(visit.core_sealed || $integrity.baselineMode === 'core' || $integrity.baselineExists));
  let liveWatchOn = $derived(!!(visit.live_watch_enabled && visit.live_watch_agent));
  let packagesReplaced = $derived(
    !!$core.reinstallSuccess
      || !!$plugins.reinstallResults
      || !!$themes.reinstallResults
      || !!$upload.uploadResult?.reinstalled
      || !!(visit.packages_sealed_count > 0)
  );

  let cleanupSteps = $derived.by(() => {
    const steps = [
      {
        id: 'malware',
        title: 'Scan for malware',
        desc: 'Files and database. Review hits on the same screen. Start with uploads and shells. Soft matches are often false positives.',
        tab: 'scanner',
        done: !!$scanning.results && !$scanning.scanning,
        active: $scanning.scanning,
        issue: false,
        icon: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
      },
      {
        id: 'livewatch',
        title: 'Enable live file watch early',
        desc: 'Catch reinfection while you audit and reinstall. Expect noisy events during intentional replaces.',
        tab: 'security',
        done: liveWatchOn,
        active: $integrity.liveWatchBusy,
        issue: !!$scanning.results && !liveWatchOn,
        soft: true,
        badge: liveWatchOn ? 'watch on' : null,
        icon: 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z',
      },
      {
        id: 'users',
        title: 'Audit users & access',
        desc: 'Rogue admins, app passwords, sessions',
        tab: 'users',
        done: !!$usersAudit.results,
        active: $usersAudit.auditing,
        issue: ($usersAudit.results?.summary?.critical || 0) > 0,
        icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
      },
      {
        id: 'cron',
        title: 'Audit cron & persistence',
        desc: 'WP-Cron / Action Scheduler backdoors',
        tab: 'cron',
        done: !!$cronAudit.results,
        active: $cronAudit.auditing,
        issue: ($cronAudit.results?.summary?.critical || 0) > 0,
        icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
      },
      {
        id: 'vulns',
        title: 'Check known vulnerabilities',
        desc: 'CVEs that inform what to replace next',
        tab: 'scanner',
        done: !!$vulnerabilities.results,
        active: $vulnerabilities.scanning,
        issue: ($vulnCounts.total || 0) > 0,
        soft: true,
        icon: 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
      },
      {
        id: 'replace',
        title: 'Replace compromised packages',
        desc: 'Core, Extensions, and Upload. Reinstall or upload clean ZIPs.',
        tab: packagesReplaced ? 'security' : 'plugins',
        done: packagesReplaced || coreSealed,
        active: !!$core.coreScanning || !!$plugins.pluginReinstalling || !!$themes.themeReinstalling || !!$upload.installing,
        issue: false,
        soft: !(packagesReplaced || coreSealed),
        icon: 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
      },
      {
        id: 'integrity',
        title: 'Seal integrity after cleanup',
        desc: 'Seal trusted trees; re-enable live watch if you want a clean post-fix baseline',
        tab: 'security',
        done: coreSealed,
        active: $integrity.checking || $integrity.establishing || $integrity.liveWatchBusy,
        issue: false,
        badge: liveWatchOn ? 'watch on' : null,
        icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
      },
      {
        id: 'remove',
        title: 'Remove Clean Sweep',
        desc: 'Delete the toolkit when the site is stable',
        tab: 'cleanup',
        done: false,
        active: false,
        issue: false,
        soft: true,
        icon: 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
      },
    ];

    const nextIdx = steps.findIndex((s) => !s.done && !s.soft);
    return steps.map((s, i) => ({
      ...s,
      current: i === (nextIdx >= 0 ? nextIdx : steps.findIndex((x) => !x.done)),
    }));
  });

  let pathProgress = $derived.by(() => {
    const done = cleanupSteps.filter((s) => s.done).length;
    const total = cleanupSteps.length;
    const pct = total ? Math.round((done / total) * 100) : 0;
    return { done, total, pct };
  });

  const tools = [
    { id: 'scanner', label: 'Scanner', desc: 'Malware + vulnerability checks', color: 'emerald', icon: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z' },
    { id: 'users', label: 'Users', desc: 'Access & identity audit', color: 'violet', icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z' },
    { id: 'cron', label: 'Cron', desc: 'Scheduled task audit', color: 'amber', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' },
    { id: 'core', label: 'Core', desc: 'Reinstall WordPress core', color: 'sky', icon: 'M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1v-2zM14 13a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z' },
    { id: 'plugins', label: 'Plugins & themes', desc: 'Analyze and reinstall', color: 'purple', icon: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10' },
    { id: 'security', label: 'Security', desc: 'Visit watch & snapshot', color: 'violet', icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z' },
    { id: 'upload', label: 'Upload', desc: 'Upload clean packages', color: 'cyan', icon: 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12' },
    // Last: self-removal after recovery
    { id: 'cleanup', label: 'Remove Clean Sweep', desc: 'Delete Clean Sweep when done', color: 'zinc', icon: 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16' },
  ];

  function toolColor(c) {
    const map = {
      emerald: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 group-hover:bg-emerald-500/20',
      violet: 'bg-violet-500/10 text-violet-700 dark:text-violet-400 group-hover:bg-violet-500/20',
      amber: 'bg-amber-500/10 text-amber-700 dark:text-amber-400 group-hover:bg-amber-500/20',
      sky: 'bg-sky-500/10 text-sky-700 dark:text-sky-400 group-hover:bg-sky-500/20',
      purple: 'bg-purple-500/10 text-purple-700 dark:text-purple-400 group-hover:bg-purple-500/20',
      cyan: 'bg-cyan-500/10 text-cyan-700 dark:text-cyan-400 group-hover:bg-cyan-500/20',
      zinc: 'bg-zinc-500/10 text-muted group-hover:bg-zinc-500/20',
    };
    return map[c] || map.zinc;
  }
</script>

<div class="h-full overflow-y-auto bg-app">
  <!-- Header -->
  <section class="relative px-6 md:px-8 py-8 border-b border-line">
    <div class="absolute inset-0 bg-gradient-to-br from-primary/5 via-transparent to-emerald-500/5 pointer-events-none"></div>
    <div class="relative max-w-6xl mx-auto">
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        <div>
          <div class="flex items-center gap-3 mb-2">
            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-primary to-emerald-600 flex items-center justify-center shadow-lg shadow-primary/20">
              <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
            </div>
            <div>
              <h1 class="text-xl font-bold text-ink">Clean Sweep</h1>
              <p class="text-sm text-muted">WordPress cleanup cockpit</p>
            </div>
          </div>
          <p class="text-xs text-muted max-w-xl leading-relaxed mt-2">
            Clean Sweep is a recovery toolkit: scan, turn on live watch early, fix and replace,
            seal when clean, then remove it when the site is stable.
          </p>
        </div>
        <div class="flex flex-wrap gap-2 text-[11px]">
          <span class="px-2.5 py-1 rounded-md border border-line bg-panel text-muted">
            Clean Sweep <span class="text-ink font-mono">{APP.version}</span>
          </span>
          <span class="px-2.5 py-1 rounded-md border border-line bg-panel text-muted">
            WordPress
            <span class="text-ink font-mono">
              {$core.currentVersion || ($core.loadingVersions ? '…' : '—')}
            </span>
          </span>
          {#if $scanning.restrictedHost || $scanning.environmentAdvisory}
            <span class="px-2.5 py-1 rounded-md border border-amber-500/30 bg-amber-500/10 text-amber-800 dark:text-amber-200/90">
              Restricted host. Prefer Quick scans and keep this tab open.
            </span>
          {/if}
        </div>
      </div>
    </div>
  </section>

  <div class="max-w-6xl mx-auto px-6 md:px-8 py-6 space-y-8">
    <!-- Active work -->
    {#if $scanning.scanning}
      <button
        type="button"
        onclick={() => go('scanner')}
        class="w-full text-left p-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/15 transition-colors"
      >
        <div class="flex items-center justify-between gap-3">
          <div class="flex items-center gap-3 min-w-0">
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse flex-shrink-0"></span>
            <div class="min-w-0">
              <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">
                Malware scan {$scanning.progressStatus === 'paused' ? 'continuing' : 'running'}
                · {Math.round($scanning.progressPercent || 0)}%
              </p>
              <p class="text-xs text-emerald-800/80 dark:text-emerald-200/60 truncate">{$scanning.progressMessage || 'Open Scanner for details'}</p>
            </div>
          </div>
          <span class="text-xs text-emerald-700 dark:text-emerald-300 flex-shrink-0">Open Scanner →</span>
        </div>
      </button>
    {/if}

    <!-- Last results -->
    <section>
      <h2 class="text-xs font-semibold uppercase tracking-wider text-muted mb-3">Last results</h2>
      {#if ($integrity.visitStatus?.likely_fake?.count || 0) > 0}
        <button
          type="button"
          onclick={() => go('plugins')}
          class="w-full text-left mb-3 p-3 rounded-xl border border-orange-500/30 bg-orange-500/10 hover:bg-orange-500/15 transition-colors"
        >
          <p class="text-sm font-medium text-orange-900 dark:text-orange-200">
            {$integrity.visitStatus.likely_fake.count} likely fake plugin/theme package{$integrity.visitStatus.likely_fake.count === 1 ? '' : 's'}
          </p>
          <p class="text-[11px] text-orange-900/70 dark:text-orange-200/60 mt-0.5">
            Decoy or stolen WordPress.org slug. Open Plugins &amp; themes to review. Not auto-deleted.
          </p>
        </button>
      {/if}
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <button type="button" onclick={() => go('scanner')} class="text-left p-4 rounded-xl border border-line bg-panel hover:border-emerald-500/40 transition-colors">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-medium text-muted">Malware</span>
            <span class="w-2 h-2 rounded-full {statusDot(malwareCard.color)} {malwareCard.live ? 'animate-pulse' : ''}"></span>
          </div>
          <p class="text-sm font-semibold text-ink leading-snug">{malwareCard.label}</p>
          <p class="text-[11px] text-muted mt-1">{malwareCard.detail}</p>
        </button>

        <button type="button" onclick={() => go('scanner')} class="text-left p-4 rounded-xl border border-line bg-panel hover:border-orange-500/40 transition-colors">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-medium text-muted">Vulnerabilities</span>
            <span class="w-2 h-2 rounded-full {statusDot(vulnCard.color)} {vulnCard.live ? 'animate-pulse' : ''}"></span>
          </div>
          <p class="text-sm font-semibold text-ink leading-snug">{vulnCard.label}</p>
          <p class="text-[11px] text-muted mt-1">{vulnCard.detail}</p>
        </button>

        <button type="button" onclick={() => go('users')} class="text-left p-4 rounded-xl border border-line bg-panel hover:border-violet-500/40 transition-colors">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-medium text-muted">Users</span>
            <span class="w-2 h-2 rounded-full {statusDot(usersCard.color)} {usersCard.live ? 'animate-pulse' : ''}"></span>
          </div>
          <p class="text-sm font-semibold text-ink leading-snug">{usersCard.label}</p>
          <p class="text-[11px] text-muted mt-1">{usersCard.detail}</p>
        </button>

        <button type="button" onclick={() => go('cron')} class="text-left p-4 rounded-xl border border-line bg-panel hover:border-amber-500/40 transition-colors">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-medium text-muted">Cron</span>
            <span class="w-2 h-2 rounded-full {statusDot(cronCard.color)} {cronCard.live ? 'animate-pulse' : ''}"></span>
          </div>
          <p class="text-sm font-semibold text-ink leading-snug">{cronCard.label}</p>
          <p class="text-[11px] text-muted mt-1">{cronCard.detail}</p>
        </button>

        <button type="button" onclick={() => go('security')} class="text-left p-4 rounded-xl border border-line bg-panel hover:border-violet-500/40 transition-colors">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-medium text-muted">Security</span>
            <span class="w-2 h-2 rounded-full {statusDot(integrityCard.color)} {integrityCard.live ? 'animate-pulse' : ''}"></span>
          </div>
          <p class="text-sm font-semibold text-ink leading-snug">{integrityCard.label}</p>
          <p class="text-[11px] text-muted mt-1">{integrityCard.detail}</p>
        </button>
      </div>
    </section>

    <!-- Cleanup path -->
    <section class="grid grid-cols-1 lg:grid-cols-5 gap-6">
      <div class="lg:col-span-3 bg-panel border border-line rounded-2xl overflow-hidden shadow-sm">
        <div class="relative px-5 py-4 border-b border-line bg-gradient-to-r from-primary/10 via-emerald-500/5 to-transparent">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <h2 class="text-sm font-semibold text-ink">Suggested cleanup path</h2>
              <p class="text-[11px] text-muted mt-0.5">
                Typical order for a compromised site. Skip steps that don’t apply.
              </p>
            </div>
            <div class="text-right flex-shrink-0">
              <p class="text-[10px] uppercase tracking-wide text-faint">Progress</p>
              <p class="text-sm font-semibold text-ink tabular-nums">
                {pathProgress.done}<span class="text-muted font-normal">/{pathProgress.total}</span>
              </p>
            </div>
          </div>
          <div class="mt-3 h-1.5 rounded-full bg-elevated border border-line overflow-hidden">
            <div
              class="h-full rounded-full bg-gradient-to-r from-primary to-emerald-500 transition-all duration-500"
              style="width: {pathProgress.pct}%"
            ></div>
          </div>
        </div>

        <ol class="relative">
          {#each cleanupSteps as step, i}
            <li class="relative">
              {#if i < cleanupSteps.length - 1}
                <span
                  class="pointer-events-none absolute left-[1.85rem] top-11 bottom-0 w-px
                    {step.done ? 'bg-emerald-500/40' : 'bg-line'}"
                  aria-hidden="true"
                ></span>
              {/if}
              <button
                type="button"
                onclick={() => go(step.tab)}
                class="group w-full flex items-start gap-3.5 px-5 py-3.5 text-left transition-colors
                  {step.current
                    ? 'bg-primary/[0.06] hover:bg-primary/10'
                    : 'hover:bg-hover'}
                  {step.current ? 'ring-1 ring-inset ring-primary/20' : ''}"
              >
                <span
                  class="relative z-[1] mt-0.5 w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0 border shadow-sm
                    {step.done
                      ? 'bg-emerald-500/15 border-emerald-500/35 text-emerald-700 dark:text-emerald-400'
                      : step.active || step.current
                        ? 'bg-primary/15 border-primary/35 text-primary'
                        : step.issue
                          ? 'bg-red-500/10 border-red-500/30 text-red-700 dark:text-red-300'
                          : 'bg-elevated border-line text-muted'}"
                >
                  {#if step.done}
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                    </svg>
                  {:else if step.icon}
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" d={step.icon}/>
                    </svg>
                  {:else}
                    <span class="text-[11px] font-mono font-semibold">{i + 1}</span>
                  {/if}
                </span>
                <div class="min-w-0 flex-1 pt-0.5">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[10px] font-mono text-faint">{String(i + 1).padStart(2, '0')}</span>
                    <span class="text-sm font-medium text-ink">{step.title}</span>
                    {#if step.active}
                      <span class="text-[10px] px-1.5 py-0.5 rounded-md bg-primary/15 text-primary border border-primary/25">live</span>
                    {:else if step.current}
                      <span class="text-[10px] px-1.5 py-0.5 rounded-md bg-sky-500/15 text-sky-800 dark:text-sky-300 border border-sky-500/25">next</span>
                    {:else if step.issue}
                      <span class="text-[10px] px-1.5 py-0.5 rounded-md bg-red-500/10 text-red-700 dark:text-red-300 border border-red-500/25">needs attention</span>
                    {:else if step.done}
                      <span class="text-[10px] px-1.5 py-0.5 rounded-md bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/25">done</span>
                    {:else if step.soft}
                      <span class="text-[10px] px-1.5 py-0.5 rounded-md bg-elevated text-faint border border-line">as needed</span>
                    {/if}
                    {#if step.badge}
                      <span class="text-[10px] px-1.5 py-0.5 rounded-md bg-violet-500/15 text-violet-800 dark:text-violet-300 border border-violet-500/25">{step.badge}</span>
                    {/if}
                  </div>
                  <p class="text-[11px] text-muted mt-1 leading-relaxed">{step.desc}</p>
                </div>
                <span class="text-faint text-xs mt-2 group-hover:text-ink">→</span>
              </button>
            </li>
          {/each}
        </ol>
      </div>

      <!-- Quick start CTA -->
      <div class="lg:col-span-2 space-y-3">
        <div class="p-5 rounded-2xl border border-emerald-500/25 bg-gradient-to-br from-emerald-500/15 via-emerald-500/5 to-transparent shadow-sm">
          <div class="flex items-center gap-2 mb-2">
            <span class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 flex items-center justify-center">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
              </svg>
            </span>
            <h3 class="text-sm font-semibold text-ink">Start here</h3>
          </div>
          <p class="text-xs text-muted mb-4 leading-relaxed">
            Scan and review findings first, then turn on live file watch so reinfection during reinstalls is visible.
            Audit users and cron, replace packages, seal integrity, then remove Clean Sweep when the site is stable.
          </p>
          <button
            type="button"
            onclick={() => go('scanner')}
            class="w-full px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium transition-colors shadow-sm shadow-emerald-600/20"
          >
            Open Scanner
          </button>
          <button
            type="button"
            onclick={() => go(cleanupSteps.find((s) => s.current)?.tab || 'scanner')}
            class="w-full mt-2 px-4 py-2 rounded-xl border border-line bg-panel/80 hover:bg-hover text-ink text-xs font-medium transition-colors"
          >
            Continue suggested path →
          </button>
        </div>
        <div class="p-4 rounded-2xl border border-line bg-panel text-[11px] text-muted leading-relaxed">
          <span class="text-ink font-medium">Empty cards are normal.</span>
          “Not run yet” means there is no last result to show. Malware and vulnerability checks restore the last run after a refresh.
        </div>
      </div>
    </section>

    <!-- Tool launcher -->
    <section>
      <h2 class="text-xs font-semibold uppercase tracking-wider text-muted mb-3">Tools</h2>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        {#each tools as tool}
          <button
            type="button"
            onclick={() => go(tool.id)}
            class="group text-left p-4 rounded-xl border border-line bg-panel hover:border-primary/35 transition-all"
          >
            <div class="w-10 h-10 rounded-lg flex items-center justify-center mb-3 transition-colors {toolColor(tool.color)}">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d={tool.icon}/>
              </svg>
            </div>
            <p class="text-sm font-medium text-ink group-hover:text-primary transition-colors">{tool.label}</p>
            <p class="text-[11px] text-muted mt-0.5 leading-snug">{tool.desc}</p>
          </button>
        {/each}
      </div>
    </section>
  </div>
</div>
