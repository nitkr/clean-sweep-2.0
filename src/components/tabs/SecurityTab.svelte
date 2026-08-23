<script>
  /**
   * Scanner hub — landing with two tool cards, then drill-in detail views.
   * Malware and vulnerability checks stay separate (no coupled checkbox).
   */
  import { scanning, riskCounts } from '../../lib/stores/scanning.js';
  import { integrity } from '../../lib/stores/integrity.js';
  import { vulnerabilities, vulnCounts } from '../../lib/stores/vulnerabilities.js';
  import { adapters } from '../../lib/adapter-registry.ts';
  import { errors } from '../../lib/errors.js';
  import ScanProgressCard from '../common/ScanProgressCard.svelte';
  import VulnResultsList from '../common/VulnResultsList.svelte';
  import MalwareResultsList from '../common/MalwareResultsList.svelte';
  import ConfirmDialog from '../common/ConfirmDialog.svelte';
  import { Button } from 'bits-ui';

  /** @type {'hub' | 'malware' | 'vulnerabilities'} */
  let activeView = $state('hub');

  /** Standard = recommended investigation default; Quick for shared/limited hosts */
  let selectedProfile = $state('standard');
  /** Deep-only scope: full | files | database | paths */
  let deepScope = $state('full');
  let scanFolder = $state('');
  let confirmFreshScan = $state(false);
  let lastProgressAt = $state(Date.now());
  let nowTick = $state(Date.now());
  /** Remember last malware finish time for hub card status */
  let lastMalwareFinishedAt = $state(null);
  /**
   * Editor view unmounts this component. On remount, restore the drill-in
   * that had results / was running so "Close" does not look like results vanished.
   */
  let restoredAfterMount = $state(false);
  /** True once the user touches profile/scope/path — skip store→local overwrite. */
  let pickersTouched = $state(false);

  const deepScopeOptions = [
    { id: 'full', label: 'Full scan', desc: 'Files + database (default Deep)' },
    { id: 'files', label: 'Files only', desc: 'Skip database' },
    { id: 'database', label: 'Database only', desc: 'Skip filesystem walk' },
    { id: 'paths', label: 'Specific paths', desc: 'Any path under the WordPress site; DB off' },
  ];

  /**
   * Restore local picker state from the store once rehydrate has settled.
   * Avoids the race where SecurityTab mounts before rehydrateFromServer finishes.
   * Do not continuously re-apply — that would fight the user changing profile
   * after viewing results.
   */
  function applyStoreToLocalPickers() {
    if ($scanning.profileId && ['quick', 'standard', 'deep', 'custom'].includes($scanning.profileId)) {
      selectedProfile = $scanning.profileId;
    }
    if ($scanning.deepScope) {
      deepScope = $scanning.deepScope;
    }
    if ($scanning.folderPathDisplay || $scanning.scanFolder) {
      scanFolder = $scanning.folderPathDisplay || $scanning.scanFolder || '';
    }
    if ($scanning.scanning || $scanning.results) {
      activeView = 'malware';
    } else if ($vulnerabilities.scanning) {
      activeView = 'vulnerabilities';
    }
  }

  $effect(() => {
    if (restoredAfterMount) return;
    // Prefer waiting for rehydrate; if it never runs, sync once we have scan state.
    if ($scanning.rehydrating) return;
    if (!$scanning.rehydrateAttempted && !$scanning.scanId && !$scanning.results && !$scanning.scanning) {
      return;
    }
    restoredAfterMount = true;
    // Do not clobber edits made while rehydrate was still in flight.
    if (!pickersTouched) {
      applyStoreToLocalPickers();
    } else if ($scanning.scanning || $scanning.results) {
      activeView = 'malware';
    }
  });

  // Auto-enter the right tool when a scan is already running (e.g. navigated away)
  $effect(() => {
    if ($scanning.scanning && activeView === 'hub') {
      activeView = 'malware';
    }
  });
  $effect(() => {
    if ($vulnerabilities.scanning && activeView === 'hub') {
      activeView = 'vulnerabilities';
    }
  });

  // Stamp last malware finish for hub badge
  $effect(() => {
    if ($scanning.results && !$scanning.scanning) {
      lastMalwareFinishedAt = Date.now();
    }
  });

  // Tick for idle display; stuck detection uses last_activity_at from store
  // (updated only when counters / queue change — not when % is flat).
  $effect(() => {
    if (!$scanning.scanning) return;
    const id = setInterval(() => {
      nowTick = Date.now();
    }, 2000);
    return () => clearInterval(id);
  });

  let scanIdleMs = $derived.by(() => {
    if (!$scanning.scanning) return null;
    const at = $scanning.liveProgress?.last_activity_at || lastProgressAt || Date.now();
    return Math.max(0, nowTick - at);
  });

  let scopeLabelShort = $derived.by(() => {
    if (selectedProfile !== 'deep' && $scanning.profileId !== 'deep') return '';
    const scope = $scanning.deepScope || deepScope || 'full';
    if (scope === 'files') return 'Files only';
    if (scope === 'database') return 'Database only';
    if (scope === 'paths') {
      const path = $scanning.folderPathDisplay || scanFolder || 'paths';
      return path;
    }
    return 'Full';
  });

  let profileLabel = $derived.by(() => {
    const base =
      selectedProfile === 'quick' || $scanning.profileId === 'quick'
        ? 'Quick Scan'
        : selectedProfile === 'deep' || $scanning.profileId === 'deep'
          ? 'Deep Scan'
          : 'Standard Scan';
    if ((selectedProfile === 'deep' || $scanning.profileId === 'deep') && scopeLabelShort && scopeLabelShort !== 'Full') {
      return `Deep · ${scopeLabelShort}`;
    }
    return base;
  });

  function pickLiveOrFinal(live, final) {
    if (live !== undefined && live !== null) return live;
    if (final !== undefined && final !== null) return final;
    return 0;
  }

  // Prefer live → peak (for DB phase) → final results
  let totalFilesScanned = $derived.by(() => {
    const live = $scanning.liveProgress?.files_scanned ?? 0;
    const peak = $scanning.liveProgress?.peak_files_scanned ?? 0;
    const final =
      $scanning.results?.files?.total_files_scanned ??
      $scanning.results?.summary?.files_scanned ??
      0;
    return Math.max(live, peak, final || 0);
  });
  let filesSkippedUnchanged = $derived(
    Number($scanning.results?.summary?.files_skipped_unchanged)
      || Number($scanning.liveProgress?.files_skipped_unchanged)
      || Number($scanning.results?.file_carry?.files_skipped_unchanged)
      || 0
  );
  let filesCarried = $derived(
    Number($scanning.results?.summary?.carried_forward)
      || Number($scanning.results?.file_carry?.carried)
      || ($scanning.results?.threats || []).filter((t) => t?.carried_forward).length
      || 0
  );
  let filesCarriedFrom = $derived(
    $scanning.results?.summary?.carried_from_profile
      || $scanning.results?.file_carry?.from_profile
      || null
  );
  let filesPhaseComplete = $derived.by(() => {
    const phase = ($scanning.liveProgress?.phase || '').toLowerCase();
    return (
      phase === 'database' ||
      phase === 'db' ||
      phase === 'analysis' ||
      phase === 'complete' ||
      phase === 'finalization'
    );
  });
  let totalRecordsScanned = $derived(pickLiveOrFinal(
    $scanning.liveProgress?.db_rows_scanned,
    $scanning.results?.summary?.db_rows_scanned ?? $scanning.results?.summary?.total_scanned
  ));
  let totalThreats = $derived(pickLiveOrFinal(
    $scanning.liveProgress?.threats_found,
    $scanning.results?.summary?.total_threats
  ));
  let liveMalwareCount = $derived(Number($scanning.liveProgress?.malware_threats) || 0);
  let liveIntegrityCount = $derived(
    Number($scanning.liveProgress?.integrity_violations) ||
      Number($scanning.integrityViolations) ||
      0
  );
  let previewMalwareList = $derived($scanning.previewThreats || []);
  let previewIntegrityList = $derived($scanning.previewIntegrityThreats || []);
  let previewAll = $derived(previewIntegrityList.concat(previewMalwareList));
  /** List is source of truth once any cards exist; live counter only before the first page. */
  let signatureDisplayCount = $derived(
    previewMalwareList.length > 0 ? previewMalwareList.length : liveMalwareCount
  );
  let showPreviewPanel = $derived(
    !!$scanning.scanning ||
      (!!$scanning.previewPartial &&
        !$scanning.results &&
        (previewAll.length > 0 ||
          !!$scanning.previewReportFailed ||
          !!$scanning.previewStoppedReason))
  );
  let malwareThreatCount = $derived(
    $scanning.results?.summary?.malware_threats
      ?? ($scanning.results?.malware_threats?.length)
      ?? ($scanning.results?.threats?.filter?.(
        (t) =>
          t?.source !== 'integrity' &&
          !t?.checksum &&
          !t?.integrity &&
          t?.pattern !== 'package_divergent' &&
          t?.pattern !== 'package_extras_rollup'
      ).length)
      ?? 0
  );
  let integrityThreatCount = $derived(
    $scanning.results
      ? Math.max(
          Number($scanning.results?.summary?.integrity_violations) || 0,
          Number($scanning.results?.integrity_violations_list?.length) || 0,
          ($scanning.results?.threats || []).filter((t) => t?.checksum || t?.source === 'integrity').length
        )
      : Math.max(
          Number($scanning.integrityViolations) || 0,
          liveIntegrityCount
        )
  );

  function scrollToResults(id) {
    if (typeof document === 'undefined') return;
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  let scanDurationLabel = $derived.by(() => {
    const ms = $scanning.scanDuration || 0;
    if (!ms || ms < 0) return '—';
    const totalSec = Math.round(ms / 1000);
    if (totalSec < 60) return `${(ms / 1000).toFixed(1)}s`;
    const m = Math.floor(totalSec / 60);
    const s = totalSec % 60;
    return `${m}m ${String(s).padStart(2, '0')}s`;
  });

  // Hub card status badges
  let malwareCardStatus = $derived.by(() => {
    if ($scanning.scanning) {
      const phase = ($scanning.liveProgress?.phase || '').toLowerCase();
      const st =
        $scanning.progressStatus === 'paused'
          ? 'Continuing…'
          : phase === 'database' || phase === 'db'
            ? 'DB scan'
            : 'Scanning';
      const db = $scanning.liveProgress?.db_rows_scanned || 0;
      const files = totalFilesScanned || 0;
      const found = signatureDisplayCount;
      const detail = found > 0
        ? `${found} found so far`
        : db > 0
          ? `${db.toLocaleString()} rows`
          : files > 0
            ? `${files.toLocaleString()} files`
            : '';
      return {
        label: detail ? `${st} · ${detail}` : st,
        color: found > 0 ? 'red' : 'emerald',
        live: true,
      };
    }
    if ($scanning.results) {
      const threats = malwareThreatCount || 0;
      return {
        label: threats > 0 ? `Last: ${threats} threat${threats === 1 ? '' : 's'}` : 'Last: clean',
        color: threats > 0 ? 'red' : 'emerald',
        live: false,
      };
    }
    return { label: 'Idle', color: 'zinc', live: false };
  });

  let vulnCardStatus = $derived.by(() => {
    if ($vulnerabilities.scanning) {
      return { label: 'Checking…', color: 'orange', live: true };
    }
    if ($vulnerabilities.results) {
      const n = $vulnCounts.total || 0;
      return {
        label: n > 0 ? `${n} known issue${n === 1 ? '' : 's'}` : 'Last: none found',
        color: n > 0 ? 'orange' : 'emerald',
        live: false,
      };
    }
    return { label: 'Idle', color: 'zinc', live: false };
  });

  function formatRelative(ts) {
    if (!ts) return null;
    const sec = Math.floor((Date.now() - ts) / 1000);
    if (sec < 60) return 'just now';
    if (sec < 3600) return `${Math.floor(sec / 60)}m ago`;
    if (sec < 86400) return `${Math.floor(sec / 3600)}h ago`;
    return `${Math.floor(sec / 86400)}d ago`;
  }

  function badgeClass(color) {
    switch (color) {
      case 'emerald': return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400 border-emerald-500/30';
      case 'orange': return 'bg-orange-500/15 text-orange-700 dark:text-orange-300 border-orange-500/30';
      case 'red': return 'bg-red-500/15 text-red-700 dark:text-red-400 border-red-500/30';
      case 'amber': return 'bg-amber-500/15 text-amber-800 dark:text-amber-300 border-amber-500/30';
      default: return 'bg-zinc-500/15 text-muted border-zinc-500/30';
    }
  }

  function openView(view) {
    activeView = view;
  }

  function goHub() {
    // Don't hide an active scan — stay on the tool
    if ($scanning.scanning && activeView === 'malware') return;
    if ($vulnerabilities.scanning && activeView === 'vulnerabilities') return;
    activeView = 'hub';
  }

  function handleThreatClick(threat) {
    scanning.selectThreat(threat);
  }

  function handleVulnClick(vuln) {
    const current = $vulnerabilities.selectedVulnerability;
    if (current && current.uuid === vuln.uuid) {
      vulnerabilities.selectVulnerability(null);
    } else {
      vulnerabilities.selectVulnerability(vuln);
    }
  }

  function selectProfile(profile) {
    pickersTouched = true;
    selectedProfile = profile;
    scanning.setProfileId(profile);
    if (profile !== 'deep') {
      deepScope = 'full';
      scanFolder = '';
      scanning.setDeepScope('full');
      scanning.setScanFolder('');
    }
  }

  function selectDeepScope(scope) {
    if ($scanning.scanning) return;
    pickersTouched = true;
    deepScope = scope;
    scanning.setDeepScope(scope);
    if (scope !== 'paths') {
      scanFolder = '';
      scanning.setScanFolder('');
    }
  }

  function handleScanFolderChange(e) {
    pickersTouched = true;
    scanFolder = e.target.value;
    scanning.setScanFolder(scanFolder);
  }

  function startScan(forceResume = false, opts = {}) {
    if (forceResume && typeof forceResume === 'object' && forceResume.type) {
      forceResume = false;
    }
    if (!forceResume && selectedProfile === 'deep' && deepScope === 'paths' && !String(scanFolder || '').trim()) {
      errors.add({
        message: 'Enter a path under the WordPress site (e.g. wp-admin/, wp-includes/, wp-content/plugins/).',
        code: 'INVALID_FOLDER_PATH',
      });
      return;
    }
    activeView = 'malware';
    scanning.setProfileId(selectedProfile);
    scanning.setIncludeVulnerabilities(false);
    if (selectedProfile === 'deep') {
      scanning.setDeepScope(deepScope);
      scanning.setScanFolder(deepScope === 'paths' ? scanFolder : '');
    } else {
      scanning.setDeepScope('full');
      scanning.setScanFolder('');
    }
    lastProgressAt = Date.now();
    scanning.startScan(forceResume, opts);
  }

  function startFreshScan() {
    confirmFreshScan = false;
    startScan(false, { freshScan: true });
  }

  function startVulnScan() {
    activeView = 'vulnerabilities';
    vulnerabilities.startScan();
  }

  async function resumeScan() {
    const scanId = $scanning.scanId;
    if (!scanId) return;
    lastProgressAt = Date.now();
    try {
      await adapters.malware.resume(scanId);
    } catch (err) {
      console.error('[SecurityTab] resume failed:', err);
    }
  }

  function copyVulnDetails(vuln) {
    let text = `Vulnerability: ${vuln.short_title || vuln.name || 'N/A'}\n`;
    text += `Component: ${vuln.target_type}, ${vuln.target_name} ${vuln.target_version}\n`;
    text += `Risk: ${vuln.risk_level}\n`;
    if (vuln.affected_version) text += `Affected: ${vuln.affected_version}\n`;
    if (vuln.fixed_version) text += `Fixed in: ≥ ${vuln.fixed_version}\n`;
    if (vuln.remediation) text += `Action: ${vuln.remediation}\n`;
    if (vuln.description) text += `\nSummary:\n${vuln.description}\n`;
    if (vuln.primary_cves?.length) {
      text += `\nCVEs:\n`;
      vuln.primary_cves.forEach((c) => {
        text += `- ${c.id}${c.link ? ` ${c.link}` : ''}\n`;
      });
    }
    navigator.clipboard.writeText(text).catch(() => {});
  }

  /** Client-side group fallback if API returns only a flat list */
  function buildVulnGroups(list) {
    if (!list?.length) return [];
    const map = new Map();
    const rank = { critical: 4, high: 3, medium: 2, low: 1, info: 0 };
    for (const item of list) {
      const key = item.group_key
        || `${item.target_type}|${item.target_slug || item.target_name}|${item.target_version}`;
      if (!map.has(key)) {
        map.set(key, {
          group_key: key,
          target_type: item.target_type,
          target_name: item.target_name,
          target_version: item.target_version,
          target_slug: item.target_slug,
          package_link: item.package_link,
          issue_count: 0,
          highest_risk: 'info',
          best_fixed_version: null,
          vulnerabilities: [],
        });
      }
      const g = map.get(key);
      g.vulnerabilities.push(item);
      g.issue_count++;
      if ((rank[item.risk_level] || 0) > (rank[g.highest_risk] || 0)) {
        g.highest_risk = item.risk_level;
      }
      if (item.fixed_version) g.best_fixed_version = item.fixed_version;
    }
    return Array.from(map.values());
  }

  let vulnGroups = $derived(
    $vulnerabilities.results?.groups?.length
      ? $vulnerabilities.results.groups
      : buildVulnGroups($vulnerabilities.results?.vulnerabilities || [])
  );

  let profileOptions = $derived.by(() => {
    const restricted = !!($scanning.restrictedHost || $scanning.environmentAdvisory);
    return [
      {
        id: 'standard',
        label: 'Standard Scan',
        icon: '🛡️',
        desc: 'Recommended default',
        details: restricted
          ? 'plugins/themes/mu-plugins + uploads (by depth); no vendor/backups. On restricted hosts this may take longer with auto-pauses. Keep the tab open.'
          : 'plugins/themes/mu-plugins + uploads (by depth); no vendor/backups. Best default for cleaning or investigating a site.',
        time: restricted ? 'Often 10 to 40+ min on this host' : 'Often several minutes',
        recommended: true,
      },
      {
        id: 'quick',
        label: 'Quick Scan',
        icon: '⚡',
        desc: 'Light / shared hosting',
        details:
          'plugins/themes/mu-plugins + shallow uploads. Skips vendor and backups. Smaller batches and early pauses. Incomplete by design.',
        time: 'Shorter steps. Keep the tab open.',
        recommended: false,
        sharedHost: true,
      },
      {
        id: 'deep',
        label: 'Deep Scan',
        icon: '🔬',
        desc: 'Broadest scope',
        details:
          'Also includes vendor and backup trees; deepest walk. Use for reinfection or high malware volume. More false positives are possible.',
        time: 'Long run. Expect many pauses on shared hosts.',
        recommended: false,
      },
    ];
  });

</script>

<div class="h-full overflow-y-auto">
  <div class="p-6 max-w-5xl mx-auto">
    <!-- ═══════════ HUB LANDING ═══════════ -->
    {#if activeView === 'hub'}
      <div class="mb-8">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center">
            <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
          </div>
          <div>
            <h1 class="text-xl font-bold text-ink">Scanner</h1>
            <p class="text-sm text-muted">Choose a check to run on your WordPress site</p>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- Malware card -->
        <button
          type="button"
          onclick={() => openView('malware')}
          class="group text-left p-6 rounded-xl border border-line bg-panel
            hover:border-emerald-500/40 hover:bg-hover/25 transition-all cursor-pointer"
        >
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl bg-emerald-500/10 flex items-center justify-center flex-shrink-0 group-hover:bg-emerald-500/20 transition-colors">
              <svg class="w-6 h-6 text-emerald-700 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
              </svg>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between gap-2 mb-1">
                <h3 class="text-base font-semibold text-ink group-hover:text-emerald-700 dark:text-emerald-300 transition-colors">
                  Malware scan
                </h3>
                <svg class="w-4 h-4 text-muted group-hover:text-emerald-700 dark:group-hover:text-emerald-400 transition-colors flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
              </div>
              <p class="text-xs text-muted mb-3 leading-relaxed">
                On-server scan of files and database for malware signatures. Quick, Standard, or Deep profiles with auto-resume.
              </p>
              <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-2 py-1 text-[10px] font-medium rounded border {badgeClass(malwareCardStatus.color)}">
                  {#if malwareCardStatus.live}
                    <span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>
                  {/if}
                  {malwareCardStatus.label}
                </span>
                {#if lastMalwareFinishedAt && !$scanning.scanning}
                  <span class="text-[10px] text-faint">{formatRelative(lastMalwareFinishedAt)}</span>
                {/if}
              </div>
            </div>
          </div>
        </button>

        <!-- Vulnerabilities card -->
        <button
          type="button"
          onclick={() => openView('vulnerabilities')}
          class="group text-left p-6 rounded-xl border border-line bg-panel
            hover:border-orange-500/40 hover:bg-hover/25 transition-all cursor-pointer"
        >
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl bg-orange-500/10 flex items-center justify-center flex-shrink-0 group-hover:bg-orange-500/20 transition-colors">
              <svg class="w-6 h-6 text-orange-700 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
              </svg>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between gap-2 mb-1">
                <h3 class="text-base font-semibold text-ink group-hover:text-orange-700 dark:text-orange-300 transition-colors">
                  Vulnerability check
                </h3>
                <svg class="w-4 h-4 text-muted group-hover:text-orange-700 dark:group-hover:text-orange-400 transition-colors flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
              </div>
              <p class="text-xs text-muted mb-3 leading-relaxed">
                Network lookup of known CVEs for WordPress core, plugins, and themes. Light, separate from malware scan.
              </p>
              <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-2 py-1 text-[10px] font-medium rounded border {badgeClass(vulnCardStatus.color)}">
                  {#if vulnCardStatus.live}
                    <span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>
                  {/if}
                  {vulnCardStatus.label}
                </span>
                {#if $vulnerabilities.lastScannedAt && !$vulnerabilities.scanning}
                  <span class="text-[10px] text-faint">{formatRelative($vulnerabilities.lastScannedAt)}</span>
                {/if}
              </div>
            </div>
          </div>
        </button>
      </div>

      <p class="mt-6 text-[11px] text-faint text-center">
        Tip: run malware scan for infection hunting; use vulnerability check after updates or as a lighter audit.
      </p>

    <!-- ═══════════ DETAIL: MALWARE ═══════════ -->
    {:else if activeView === 'malware'}
      <!-- Sub-nav -->
      <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-2">
          <button
            type="button"
            onclick={goHub}
            disabled={$scanning.scanning}
            class="text-xs text-muted hover:text-ink disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex items-center gap-1"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Scanner
          </button>
          <span class="text-faint">/</span>
          <span class="text-xs text-ink font-medium">Malware scan</span>
        </div>
        <div class="flex items-center gap-1 p-0.5 rounded-lg bg-panel border border-line">
          <button
            type="button"
            class="px-3 py-1.5 text-xs rounded-md bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/20"
          >
            Malware
          </button>
          <button
            type="button"
            onclick={() => openView('vulnerabilities')}
            disabled={$scanning.scanning}
            class="px-3 py-1.5 text-xs rounded-md text-muted hover:text-ink disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          >
            Vulnerabilities
          </button>
        </div>
      </div>

      <div class="mb-6">
        <h1 class="text-xl font-bold text-ink">Malware scan</h1>
        <p class="text-sm text-muted mt-1">On-server files and database signature scan</p>
      </div>

      {#if $scanning.resultsRestoredBanner}
        <div class="mb-4 p-3 rounded-lg border border-sky-500/30 bg-sky-500/10 text-xs text-sky-900 dark:text-sky-100 flex items-start justify-between gap-3">
          <p class="leading-relaxed min-w-0">{$scanning.resultsRestoredBanner}</p>
          <div class="flex items-center gap-2 flex-shrink-0">
            {#if !$scanning.scanning && $scanning.results}
              <button
                type="button"
                class="text-[11px] font-medium text-sky-900 dark:text-sky-100 hover:underline"
                onclick={() => scanning.dismissResults()}
              >Clear results</button>
            {/if}
            <button
              type="button"
              class="text-[11px] text-sky-800 dark:text-sky-200 hover:underline"
              onclick={() => scanning.clearRestoredBanner()}
            >Dismiss</button>
          </div>
        </div>
      {/if}

      <!-- Live stats strip -->
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
        <div class="p-3 bg-panel border border-line rounded-xl">
          <div class="text-[10px] text-muted mb-0.5">Files</div>
          <div class="text-lg font-bold text-ink">{totalFilesScanned > 0 ? totalFilesScanned.toLocaleString() : '—'}</div>
          {#if filesSkippedUnchanged > 0}
            <div class="text-[10px] text-faint mt-0.5">{filesSkippedUnchanged.toLocaleString()} unchanged</div>
          {/if}
        </div>
        <div class="p-3 bg-panel border border-line rounded-xl">
          <div class="text-[10px] text-muted mb-0.5">
            {$scanning.scanning && signatureDisplayCount === 0 && liveIntegrityCount > 0
              ? 'Integrity'
              : 'Signatures'}
          </div>
          <div class="text-lg font-bold {(signatureDisplayCount || malwareThreatCount || liveIntegrityCount) > 0 ? 'text-red-700 dark:text-red-400' : 'text-ink'}">
            {$scanning.scanning
              ? (signatureDisplayCount || liveIntegrityCount || '—')
              : (malwareThreatCount || '—')}
          </div>
          {#if $scanning.scanning && (signatureDisplayCount > 0 || liveIntegrityCount > 0)}
            <div class="text-[10px] text-faint mt-0.5">
              {#if signatureDisplayCount > 0 && liveIntegrityCount > 0}
                {signatureDisplayCount} loaded · {liveIntegrityCount} integrity
              {:else if liveIntegrityCount > 0}
                {liveIntegrityCount} verification finding{liveIntegrityCount === 1 ? '' : 's'}
              {:else}
                so far
              {/if}
            </div>
          {/if}
        </div>
        <div class="p-3 bg-panel border border-line rounded-xl col-span-2 sm:col-span-1">
          <div class="text-[10px] text-muted mb-0.5">Status</div>
          <div class="text-sm font-medium text-ink">
            {$scanning.scanning
              ? ($scanning.progressStatus === 'paused'
                  ? 'Continuing automatically…'
                  : (($scanning.deepScope || deepScope) === 'database'
                      || $scanning.liveProgress?.phase === 'database'
                      || $scanning.liveProgress?.phase === 'db'
                      ? 'Checking database…'
                      : 'Scanning…'))
              : ($scanning.results ? 'Complete' : 'Ready')}
          </div>
        </div>
      </div>

      {#if !$scanning.scanning}
      <!-- Profile + run -->
      <div class="bg-panel border border-line rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-line">
          <h2 class="text-sm font-semibold text-ink">Scan profile</h2>
        </div>
        <div class="p-5">
          {#if $scanning.environmentAdvisory || $scanning.restrictedHost}
            <div class="mb-4 p-3 rounded-lg border border-amber-500/30 bg-amber-500/10 text-xs text-amber-900 dark:text-amber-200/90 leading-relaxed">
              <p class="font-medium text-amber-950 dark:text-amber-100 mb-1">Restricted hosting detected</p>
              <p>
                {$scanning.environmentAdvisory ||
                  'This host may pause the scan every few minutes. Keep this tab open. It continues automatically.'}
              </p>
              <p class="mt-1.5">
                <button type="button" class="underline font-medium text-amber-950 dark:text-amber-100" onclick={() => selectProfile('standard')}>Standard</button>
                remains the recommended investigation scan (auto-resume). Use
                <button type="button" class="underline font-medium text-amber-950 dark:text-amber-100" onclick={() => selectProfile('quick')}>Quick</button>
                only for a lighter first pass (shallow uploads; no vendor/backups).
              </p>
            </div>
          {:else}
            <div class="mb-4 p-3 rounded-lg border border-line bg-app text-xs text-muted leading-relaxed">
              This scan works in short steps and can pause on shared hosting. Leave this tab open so it can keep going. The percentage is a rough guide; files, DB rows, and steps left are what actually moved.
            </div>
          {/if}
          {#if $scanning.integrityNote || $scanning.scanning || $scanning.results}
            <div class="mb-4 p-3 rounded-lg border border-sky-500/20 bg-sky-500/5 text-xs text-sky-800/80 dark:text-sky-200/80">
              {#if $scanning.hasIntegrityBaseline}
                <span class="text-emerald-700 dark:text-emerald-400 font-medium">Integrity baseline active.</span>
                {$scanning.integrityNote || 'Post-reinstall baseline will be checked during the scan.'}
              {:else}
                <span class="text-muted font-medium">No integrity baseline.</span>
                {$scanning.integrityNote || 'No trusted baseline is available (run Core Reinstall first for reinfection detection).'}
              {/if}
            </div>
          {/if}

          <div class="mb-6">
            <h3 class="text-xs font-semibold text-muted uppercase tracking-wider mb-3">Select profile</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              {#each profileOptions as option}
                {@const isSelected = selectedProfile === option.id}
                <button
                  type="button"
                  onclick={() => selectProfile(option.id)}
                  disabled={$scanning.scanning}
                  class="relative p-4 rounded-lg border text-left transition-all duration-200 cursor-pointer
                    {isSelected
                      ? option.id === 'deep'
                        ? 'bg-amber-500/10 border-amber-500/50 ring-2 ring-amber-500/30'
                        : 'bg-primary/10 border-primary/50 ring-2 ring-primary/30'
                      : 'bg-app border-line hover:border-primary/50 hover:bg-hover'}"
                >
                  <div class="flex items-start gap-3">
                    <span class="text-xl">{option.icon}</span>
                    <div class="flex-1">
                      <div class="text-sm font-medium text-ink flex items-center gap-2 flex-wrap">
                        {option.label}
                        {#if option.recommended}
                          <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30">Recommended</span>
                        {/if}
                        {#if option.sharedHost || option.id === 'quick'}
                          <span class="text-[10px] px-1.5 py-0.5 rounded bg-sky-500/10 text-sky-700 dark:text-sky-300/90 border border-sky-500/25">Shared hosts</span>
                        {/if}
                      </div>
                      <div class="text-xs text-muted">{option.desc}</div>
                      <div class="text-xs text-faint mt-1">{option.details}</div>
                      {#if option.time}
                        <div class="text-xs {isSelected ? 'text-amber-700 dark:text-amber-400' : 'text-primary/70'} mt-1">{option.time}</div>
                      {/if}
                    </div>
                  </div>
                </button>
              {/each}
            </div>
          </div>

          {#if selectedProfile === 'deep'}
            <div class="mb-6 p-4 bg-app rounded-lg border border-amber-500/25 space-y-3">
              <div>
                <h3 class="text-xs font-semibold text-muted uppercase tracking-wider">Scope</h3>
                <p class="text-[11px] text-faint mt-1 leading-relaxed">
                  Rare advanced options for Deep only. Quick and Standard always run files + database within their profile.
                </p>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                {#each deepScopeOptions as opt}
                  {@const scopeSelected = deepScope === opt.id}
                  <button
                    type="button"
                    onclick={() => selectDeepScope(opt.id)}
                    disabled={$scanning.scanning}
                    class="text-left p-3 rounded-md border transition-all disabled:opacity-50 disabled:cursor-not-allowed
                      {scopeSelected
                        ? 'bg-amber-500/10 border-amber-500/40 ring-1 ring-amber-500/25'
                        : 'bg-panel border-line hover:border-amber-500/30'}"
                  >
                    <div class="text-sm font-medium text-ink">{opt.label}</div>
                    <div class="text-[11px] text-muted mt-0.5">{opt.desc}</div>
                  </button>
                {/each}
              </div>
              {#if deepScope === 'paths'}
                <div>
                  <label class="block text-xs text-muted mb-2" for="deep-scan-path">Path under the WordPress site</label>
                  <input
                    id="deep-scan-path"
                    type="text"
                    value={scanFolder}
                    oninput={handleScanFolderChange}
                    placeholder="e.g. wp-admin/, wp-includes/, wp-content/plugins/my-plugin/"
                    disabled={$scanning.scanning}
                    class="w-full px-3 py-2 bg-elevated border border-line rounded-md text-sm text-ink placeholder-zinc-600 focus:outline-none focus:border-primary/50 disabled:opacity-50"
                  />
                  <p class="text-[10px] text-faint mt-1.5">
                    Any folder or file under the site root (not only wp-content). Relative to the WordPress install, or an absolute path. Database is skipped unless you use the API <code class="text-ink/80">include_db</code> flag.
                  </p>
                </div>
              {/if}
            </div>
          {/if}

          <div class="flex items-center gap-3 flex-wrap">
            <Button.Root
              variant="primary"
              onclick={() => startScan(false)}
              disabled={$scanning.scanning}
              class="inline-flex items-center gap-2 px-6 py-2.5
                bg-primary hover:bg-primary/80 text-primary-foreground text-sm font-medium
                border border-primary/30 rounded-md shadow-sm
                disabled:opacity-50 disabled:cursor-not-allowed
                transition-all duration-200 cursor-pointer"
            >
              {#if $scanning.scanning}
                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span>Scanning…</span>
              {:else}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <span>Start {profileLabel}</span>
              {/if}
            </Button.Root>
            {#if !$scanning.scanning}
              {#if !(selectedProfile === 'deep' && deepScope === 'database')}
                <button
                  type="button"
                  onclick={() => { confirmFreshScan = true; }}
                  class="text-xs text-muted hover:text-ink underline-offset-2 hover:underline"
                >
                  Scan all files again
                </button>
              {/if}
              <button
                type="button"
                onclick={() => openView('vulnerabilities')}
                class="text-xs text-muted hover:text-orange-700 dark:text-orange-300 transition-colors"
              >
                Or check vulnerabilities →
              </button>
            {/if}
          </div>
        </div>
      </div>
      {/if}

      {#if $scanning.scanning}
        <ScanProgressCard
          percent={$scanning.progressPercent}
          progressSource={$scanning.progressSource || ''}
          profileLabel={profileLabel}
          status={$scanning.progressStatus}
          phase={$scanning.liveProgress?.phase || 'files'}
          message={$scanning.progressMessage}
          filesScanned={totalFilesScanned}
          filesComplete={filesPhaseComplete}
          dbRowsScanned={totalRecordsScanned}
          threatsFound={totalThreats}
          malwareFound={signatureDisplayCount}
          integrityFound={liveIntegrityCount}
          dbSkipped={($scanning.profileId === 'deep' || selectedProfile === 'deep') && (($scanning.deepScope || deepScope) === 'files' || ($scanning.deepScope || deepScope) === 'paths')}
          filesSkipped={($scanning.profileId === 'deep' || selectedProfile === 'deep') && ($scanning.deepScope || deepScope) === 'database'}
          pending={$scanning.workQueueStats?.pending ?? $scanning.pendingWorkCount ?? 0}
          completed={$scanning.workQueueStats?.completed ?? 0}
          inProgress={$scanning.workQueueStats?.in_progress ?? $scanning.workQueueStats?.running ?? 0}
          failed={$scanning.workQueueStats?.failed ?? 0}
          idleMs={scanIdleMs}
          canContinue={!!$scanning.scanId}
          restrictedHost={!!$scanning.restrictedHost}
          pauseReason={$scanning.pauseReason}
          lastFilePath={$scanning.liveProgress?.last_file_path}
          lastDbTable={$scanning.liveProgress?.last_db_table}
          lastDbId={$scanning.liveProgress?.last_db_id}
          checksumNote={$scanning.checksumNote}
          checksumChecked={$scanning.checksumChecked || 0}
          checksumVersion={$scanning.checksumVersion}
          packageChecksumNote={$scanning.liveProgress?.package_checksum_note}
          on:continue={resumeScan}
          on:cancel={() => scanning.cancelScan()}
        />
      {/if}

      {#if showPreviewPanel}
        {@const previewChip = $scanning.scanning
          ? { text: 'Preview · scan still running', class: 'bg-sky-500/10 text-sky-800 dark:text-sky-300 border-sky-500/30' }
          : $scanning.previewReportFailed
            ? { text: 'Preview · full report failed', class: 'bg-amber-500/10 text-amber-900 dark:text-amber-200 border-amber-500/30' }
            : $scanning.previewStoppedReason === 'cancelled'
              ? { text: 'Preview · scan cancelled', class: 'bg-zinc-500/10 text-muted border-line' }
              : $scanning.previewStoppedReason === 'failed' || $scanning.previewStoppedReason === 'lost'
                ? { text: 'Preview · scan did not finish', class: 'bg-amber-500/10 text-amber-900 dark:text-amber-200 border-amber-500/30' }
                : { text: 'Preview · incomplete', class: 'bg-sky-500/10 text-sky-800 dark:text-sky-300 border-sky-500/30' }}
        <div class="space-y-3 mb-6">
          <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
              <div class="flex items-center gap-2 flex-wrap">
                <h2 class="text-sm font-semibold text-ink">Findings so far</h2>
                <span class="text-[10px] px-2 py-0.5 rounded-full border font-medium {previewChip.class}">
                  {previewChip.text}
                </span>
              </div>
              <p class="text-[11px] text-muted mt-1 leading-relaxed">
                {#if $scanning.previewReportFailed}
                  The scan finished but the full report could not be loaded. You can still inspect the findings below.
                {:else if signatureDisplayCount > 0}
                  {signatureDisplayCount} signature match{signatureDisplayCount === 1 ? '' : 'es'}
                  {#if $scanning.previewCapped}
                    (first {previewMalwareList.length}; more when the scan finishes)
                  {/if}
                  {#if liveIntegrityCount > 0}
                    · {liveIntegrityCount} integrity
                    {#if previewIntegrityList.length && previewIntegrityList.length < liveIntegrityCount}
                      (showing {previewIntegrityList.length})
                    {/if}
                  {/if}
                {:else if liveIntegrityCount > 0}
                  {liveIntegrityCount} verification finding{liveIntegrityCount === 1 ? '' : 's'} so far.
                  {#if previewIntegrityList.length}
                    Showing {previewIntegrityList.length} to inspect now.
                  {:else}
                    A sample appears here as soon as it is written.
                  {/if}
                {:else if $scanning.scanning && filesSkippedUnchanged > 0}
                  Unchanged files are not re-scanned. Previous file matches are applied when this scan finishes.
                {:else if $scanning.scanning}
                  No signature matches yet. This is not a clean bill of health — the scan is still running.
                {:else}
                  No signature matches were collected before the scan stopped.
                {/if}
              </p>
            </div>
          </div>

          {#if $scanning.previewError && !previewAll.length}
            <div class="p-3 rounded-lg border border-amber-500/30 bg-amber-500/10 text-xs text-amber-900 dark:text-amber-200">
              {$scanning.previewError}
            </div>
          {/if}

          {#if ($scanning.previewLoading || (signatureDisplayCount > 0 && !previewAll.length && $scanning.scanning && !$scanning.previewError)) && !previewAll.length}
            <div class="p-3 rounded-xl border border-line bg-panel text-xs text-muted">
              Loading findings…
            </div>
          {:else if previewAll.length}
            {#if $scanning.previewCapped}
              <p class="text-[11px] text-faint">
                Showing the first {previewMalwareList.length} signature matches. The rest appear when the scan finishes.
              </p>
            {/if}
            {#if previewIntegrityList.length && liveIntegrityCount > previewIntegrityList.length}
              <p class="text-[11px] text-faint">
                Integrity sample: {previewIntegrityList.length} of {liveIntegrityCount}. Full verification list when the scan finishes.
              </p>
            {/if}
            <MalwareResultsList
              threats={previewAll}
              selectedThreatId={$scanning.selectedThreat?.id || null}
              onSelect={handleThreatClick}
              preview={true}
              hasIntegrityBaseline={false}
              likelySource={null}
            />
          {/if}
        </div>
      {/if}

      {#if $scanning.results && !$scanning.scanning}
        <div class="space-y-4">
          <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
            <button type="button" onclick={() => scrollToResults('results-files')} class="bg-panel border border-line rounded-xl p-4 text-center hover:border-primary/40 transition-colors">
              <div class="text-2xl font-bold text-primary">{totalFilesScanned.toLocaleString()}</div>
              <div class="text-xs text-muted">Files</div>
              {#if filesSkippedUnchanged > 0}
                <div class="text-[10px] text-faint mt-1">{filesSkippedUnchanged.toLocaleString()} unchanged</div>
              {/if}
            </button>
            <button type="button" onclick={() => scrollToResults('results-database')} class="bg-panel border border-line rounded-xl p-4 text-center hover:border-amber-500/40 transition-colors">
              <div class="text-2xl font-bold text-amber-700 dark:text-amber-400">{totalRecordsScanned.toLocaleString()}</div>
              <div class="text-xs text-muted">DB rows</div>
            </button>
            <button type="button" onclick={() => scrollToResults('results-files')} class="bg-panel border border-line rounded-xl p-4 text-center hover:border-red-500/40 transition-colors">
              <div class="text-2xl font-bold text-red-700 dark:text-red-400">{malwareThreatCount}</div>
              <div class="text-xs text-muted">Malware</div>
            </button>
            <button type="button" onclick={() => scrollToResults('results-checksum')} class="bg-panel border border-line rounded-xl p-4 text-center hover:border-violet-500/40 transition-colors">
              <div class="text-2xl font-bold text-violet-700 dark:text-violet-400">{integrityThreatCount}</div>
              <div class="text-xs text-muted">Integrity</div>
            </button>
            <div class="bg-panel border border-line rounded-xl p-4 text-center">
              <div class="text-2xl font-bold text-green-700 dark:text-green-400">{scanDurationLabel}</div>
              <div class="text-xs text-muted">Duration</div>
            </div>
          </div>

          <div class="p-3 rounded-lg border border-line bg-panel text-xs text-muted">
            Scan complete
            · <span class="text-ink">{$scanning.resultsScanLabel || profileLabel}</span>
            {#if $scanning.resultsScope === 'files' || $scanning.resultsScope === 'paths'}
              · <span class="text-faint">DB skipped</span>
            {:else if $scanning.resultsScope === 'database'}
              · <span class="text-faint">Files skipped</span>
            {/if}
            · {totalFilesScanned.toLocaleString()} files scanned
            {#if filesSkippedUnchanged > 0}
              · <span class="text-faint">{filesSkippedUnchanged.toLocaleString()} unchanged (not re-scanned)</span>
            {/if}
            · {totalRecordsScanned.toLocaleString()} DB rows
            · {scanDurationLabel}
            · <span class={malwareThreatCount > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300'}>
              {malwareThreatCount} signature match{malwareThreatCount === 1 ? '' : 'es'}
            </span>
            {#if filesCarried > 0}
              · <span class="text-ink">{filesCarried} carried from last {filesCarriedFrom === 'deep' ? 'Deep' : filesCarriedFrom === 'standard' ? 'Standard' : filesCarriedFrom === 'quick' ? 'Quick' : 'scan'} (unchanged)</span>
            {/if}
            {#if integrityThreatCount > 0}
              · <span class="text-violet-800 dark:text-violet-300">{integrityThreatCount} integrity</span>
            {/if}
          </div>
          {#if filesCarried > 0 || filesSkippedUnchanged > 0}
            <div class="p-3 rounded-lg border border-sky-500/25 bg-sky-500/5 text-xs text-muted leading-relaxed">
              Unchanged files were not signature-scanned again (same hash as the last
              {filesCarriedFrom === 'deep' ? ' Deep' : filesCarriedFrom === 'standard' ? ' Standard' : ''}
              scan).
              {#if filesCarried > 0}
                {filesCarried} file match{filesCarried === 1 ? '' : 'es'} from that scan {filesCarried === 1 ? 'is' : 'are'} still listed.
              {:else}
                No file signature hits were on those unchanged paths.
              {/if}
            </div>
          {/if}

          <div class="p-3 rounded-lg border border-orange-500/20 bg-orange-500/5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <p class="text-xs text-muted">
              Next: check core, plugins, and themes for <span class="text-orange-700 dark:text-orange-300">known CVEs</span> (separate, lighter).
            </p>
            <button
              type="button"
              onclick={() => openView('vulnerabilities')}
              class="text-xs font-medium text-orange-700 dark:text-orange-300 hover:text-orange-800 dark:hover:text-orange-200 whitespace-nowrap"
            >
              Open vulnerability check →
            </button>
          </div>

          {#if $riskCounts.critical > 0 || $riskCounts.warning > 0 || $riskCounts.info > 0}
            <div class="flex flex-wrap gap-2">
              {#if $riskCounts.critical > 0}
                <span class="px-3 py-1.5 rounded text-xs font-semibold bg-red-500/20 text-red-700 dark:text-red-400 border border-red-500/30">
                  CRITICAL ({$riskCounts.critical})
                </span>
              {/if}
              {#if $riskCounts.warning > 0}
                <span class="px-3 py-1.5 rounded text-xs font-semibold bg-orange-500/20 text-orange-700 dark:text-orange-400 border border-orange-500/30">
                  HIGH ({$riskCounts.warning})
                </span>
              {/if}
              {#if $riskCounts.info > 0}
                <span class="px-3 py-1.5 rounded text-xs font-semibold bg-blue-500/20 text-blue-700 dark:text-blue-400 border border-blue-500/30">
                  MEDIUM/LOW ({$riskCounts.info})
                </span>
              {/if}
            </div>
          {/if}

          <MalwareResultsList
            threats={$scanning.results?.threats || []}
            selectedThreatId={$scanning.selectedThreat?.id || null}
            onSelect={handleThreatClick}
            checksumNote={$scanning.checksumNote}
            checksumChecked={$scanning.checksumChecked || 0}
            checksumFindings={$scanning.checksumFindings || 0}
            checksumVersion={$scanning.checksumVersion}
            hasIntegrityBaseline={!!$scanning.hasIntegrityBaseline}
            likelySource={($scanning.likelySource?.reinfection || $scanning.likelySource?.core_changed || $scanning.likelySource?.core_files?.length)
              ? $scanning.likelySource
              : null}
          />
        </div>
      {/if}

    <!-- ═══════════ DETAIL: VULNERABILITIES ═══════════ -->
    {:else if activeView === 'vulnerabilities'}
      <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-2">
          <button
            type="button"
            onclick={goHub}
            disabled={$vulnerabilities.scanning}
            class="text-xs text-muted hover:text-ink disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex items-center gap-1"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Scanner
          </button>
          <span class="text-faint">/</span>
          <span class="text-xs text-ink font-medium">Vulnerability check</span>
        </div>
        <div class="flex items-center gap-1 p-0.5 rounded-lg bg-panel border border-line">
          <button
            type="button"
            onclick={() => openView('malware')}
            disabled={$vulnerabilities.scanning}
            class="px-3 py-1.5 text-xs rounded-md text-muted hover:text-ink disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
          >
            Malware
          </button>
          <button
            type="button"
            class="px-3 py-1.5 text-xs rounded-md bg-orange-500/15 text-orange-700 dark:text-orange-300 border border-orange-500/20"
          >
            Vulnerabilities
          </button>
        </div>
      </div>

      <div class="mb-6">
        <h1 class="text-xl font-bold text-ink">Vulnerability check</h1>
        <p class="text-sm text-muted mt-1">Known CVEs for core, plugins, and themes</p>
      </div>

      <div class="bg-panel border border-line rounded-xl overflow-hidden mb-6">
        <div class="p-5">
          <p class="text-xs text-muted mb-4">
            Uses the WPVulnerability database. Light network check, independent of the malware work queue.
          </p>
          <div class="flex items-center gap-3 flex-wrap">
            <Button.Root
              onclick={startVulnScan}
              disabled={$vulnerabilities.scanning || $scanning.scanning}
              class="inline-flex items-center gap-2 px-4 py-2
                bg-orange-500/15 hover:bg-orange-500/25
                text-orange-800 dark:text-orange-200 text-sm font-medium
                border border-orange-500/30 rounded-md
                disabled:opacity-50 disabled:cursor-not-allowed
                transition-all duration-200 cursor-pointer"
            >
              {#if $vulnerabilities.scanning}
                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span>Checking…</span>
              {:else}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                <span>Run vulnerability check</span>
              {/if}
            </Button.Root>
            {#if $vulnerabilities.lastScannedAt}
              <span class="text-[11px] text-faint">
                Last run: {new Date($vulnerabilities.lastScannedAt).toLocaleString()}
              </span>
            {/if}
            {#if !$vulnerabilities.scanning}
              <button
                type="button"
                onclick={() => openView('malware')}
                class="text-xs text-muted hover:text-emerald-700 dark:text-emerald-300 transition-colors"
              >
                Or run malware scan →
              </button>
            {/if}
          </div>

          {#if $vulnerabilities.error}
            <div class="mt-3 p-3 rounded-lg border border-red-500/30 bg-red-500/10 text-xs text-red-700 dark:text-red-300">
              {$vulnerabilities.error}
            </div>
          {/if}

          {#if $vulnerabilities.scanning}
            <div class="mt-4">
              <div class="h-1.5 bg-elevated rounded-full overflow-hidden">
                <div class="h-full w-1/3 bg-orange-400/80 rounded-full animate-pulse"></div>
              </div>
              <p class="text-[11px] text-muted mt-2">Querying known vulnerabilities for core, plugins, and themes…</p>
            </div>
          {/if}
        </div>
      </div>

      {#if $vulnerabilities.results && !$vulnerabilities.scanning}
        <div class="space-y-4">
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-panel border border-line rounded-xl p-4 text-center">
              <div class="text-2xl font-bold text-orange-700 dark:text-orange-400">{$vulnCounts.total}</div>
              <div class="text-xs text-muted">Total</div>
            </div>
            <div class="bg-panel border border-line rounded-xl p-4 text-center">
              <div class="text-2xl font-bold text-red-700 dark:text-red-400">{$vulnCounts.critical}</div>
              <div class="text-xs text-muted">Critical</div>
            </div>
            <div class="bg-panel border border-line rounded-xl p-4 text-center">
              <div class="text-2xl font-bold text-amber-700 dark:text-amber-400">{$vulnCounts.high}</div>
              <div class="text-xs text-muted">High</div>
            </div>
            <div class="bg-panel border border-line rounded-xl p-4 text-center">
              <div class="text-2xl font-bold text-yellow-700/90 dark:text-yellow-400/90">{$vulnCounts.medium + $vulnCounts.low}</div>
              <div class="text-xs text-muted">Medium / Low</div>
            </div>
          </div>

          <p class="text-[11px] text-muted">
            Grouped by component. Open <span class="text-muted">Details</span> for the full write-up inside Clean Sweep. External links are optional.
          </p>

          <VulnResultsList
            groups={vulnGroups}
            selectedUuid={$vulnerabilities.selectedVulnerability?.uuid ?? null}
            onSelect={handleVulnClick}
            onCopy={copyVulnDetails}
          />
        </div>
      {/if}
    {/if}
  </div>
</div>

{#if confirmFreshScan}
  <ConfirmDialog
    open={true}
    title="Scan all files again?"
    message={"This ignores the saved file-hash cache and re-checks every file in this scan’s scope. It takes longer than a normal Start. Previous reports are kept."}
    confirmLabel="Scan all files again"
    cancelLabel="Cancel"
    variant="primary"
    onConfirm={startFreshScan}
    onCancel={() => { confirmFreshScan = false; }}
  />
{/if}
