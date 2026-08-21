<script>
  import { plugins } from '../../lib/stores/plugins.js';
  import { Button } from 'bits-ui';
  import ReinstallSummary from '../common/ReinstallSummary.svelte';
  
  /**
   * PluginList Component - Reusable plugin list display
   * 
   * Features:
   * - Displays WordPress.org, WPMU DEV, and Custom plugins
   * - Includes Last Updated and Plugin Page columns for WP.org
   * - Includes Description column for WPMU DEV
   * - Selection controls for bulk operations
   * - Extensible via slots/props for future customization
   */
  
  // Props for extensibility
  let { 
    showHeader = true,
    showStats = true,
    showReinstallButton = true,
    onReinstallClick = () => plugins.showReinstallDialog()
  } = $props();
  
  // Handle reinstall button click
  function handleReinstallClick() {
    onReinstallClick();
  }
  
  // Handle backup confirmation
  async function handleConfirmReinstall(createBackup) {
    await plugins.reinstallAllPlugins(createBackup);
  }

  function checksumLabel(plugin) {
    const st = plugin?.checksum_status;
    if (!st) return '—';
    if (st === 'match') return 'Matches .org';
    if (st === 'modified') {
      const n = plugin.checksum_findings || 0;
      return n ? `Modified (${n})` : 'Modified';
    }
    if (st === 'unavailable') return 'Not on .org';
    return '—';
  }
</script>

<div class="bg-panel rounded-lg p-5 mb-6 border border-line">
  <!-- Security Alert -->
  {#if ($plugins.pluginResults.likely_fake || 0) > 0}
    <div class="mb-5 p-3.5 bg-orange-500/10 border border-orange-500/25 rounded-md">
      <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-orange-700 dark:text-orange-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
        </svg>
        <div>
          <div class="text-sm font-semibold text-orange-800 dark:text-orange-300">Likely fake packages</div>
          <div class="text-xs text-muted mt-1">
            {$plugins.pluginResults.likely_fake} plugin{$plugins.pluginResults.likely_fake === 1 ? '' : 's'} look like decoys or impersonate a WordPress.org slug.
            They are listed separately — not selected for reinstall, and not treated as orphans.
          </div>
        </div>
      </div>
    </div>
  {/if}

  {#if $plugins.pluginResults.suspicious > 0}
    <div class="mb-5 p-3.5 bg-red-500/10 border border-red-500/20 rounded-md">
      <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-red-700 dark:text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
        </svg>
        <div>
          <div class="text-sm font-semibold text-red-700 dark:text-red-400">Security Alert</div>
          <div class="text-xs text-muted mt-1">
            Found {$plugins.pluginResults.suspicious} orphan file(s)/folder(s) in the plugins directory (not part of any known plugin).
            High-severity items are checked below and will be <span class="text-red-700 dark:text-red-400 font-medium">permanently deleted</span> on reinstall unless you uncheck them.
          </div>
        </div>
      </div>
    </div>
  {/if}
  
  <!-- Stats Grid -->
  {#if showStats}
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
      <div class="bg-app border border-line rounded-lg p-3 text-center">
        <div class="text-xl font-bold text-ink">{$plugins.pluginResults.total || 0}</div>
        <div class="text-xs text-muted">Total</div>
      </div>
      <div class="bg-app border border-line rounded-lg p-3 text-center">
        <div class="text-xl font-bold text-green-700 dark:text-green-400">{$plugins.pluginResults.wp_org}</div>
        <div class="text-xs text-muted">WP.org</div>
      </div>
      <div class="bg-app border border-line rounded-lg p-3 text-center">
        <div class="text-xl font-bold text-blue-700 dark:text-blue-400">{$plugins.pluginResults.wpmu_dev}</div>
        <div class="text-xs text-muted">WPMU DEV</div>
      </div>
      <div class="bg-app border border-line rounded-lg p-3 text-center">
        <div class="text-xl font-bold text-amber-700 dark:text-amber-400">{$plugins.pluginResults.custom}</div>
        <div class="text-xs text-muted">Non-repo</div>
      </div>
      <div class="bg-app border border-orange-500/30 rounded-lg p-3 text-center">
        <div class="text-xl font-bold text-orange-700 dark:text-orange-400">{$plugins.pluginResults.likely_fake || 0}</div>
        <div class="text-xs text-muted">Likely fake</div>
      </div>
      <div class="bg-app border border-red-500/30 rounded-lg p-3 text-center">
        <div class="text-xl font-bold text-red-700 dark:text-red-400">{$plugins.pluginResults.suspicious}</div>
        <div class="text-xs text-muted">Orphans</div>
      </div>
    </div>
  {/if}
  
  <!-- WPMU DEV Authentication Warning -->
  {#if $plugins.pluginResults.wpmu_dev_available === false && $plugins.pluginResults.wpmu_dev > 0}
    <div class="bg-amber-500/10 border border-amber-500/30 rounded-lg p-4 mb-6">
      <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-amber-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <div>
          <h4 class="text-amber-500 font-semibold mb-1">⚠️ WPMU DEV Dashboard Not Connected</h4>
          <p class="text-sm text-muted mb-2">
            Your site is not connected to the WPMU DEV Hub. <strong class="text-amber-700 dark:text-amber-400">{$plugins.pluginResults.wpmu_dev} WPMU DEV premium plugins cannot be automatically reinstalled</strong> because authentication is required.
          </p>
          <p class="text-xs text-muted">
            Note: WPMU DEV plugins will be skipped during reinstallation. You can manually reinstall them after connecting to the Hub.
          </p>
        </div>
      </div>
    </div>
  {/if}
  
  <!-- WP.org Selection Controls & Copy List -->
  <div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
      <button 
        onclick={() => plugins.selectAllWpOrg()}
        class="px-3 py-1.5 bg-elevated hover:bg-elevated text-muted hover:text-ink text-xs rounded transition-colors"
      >
        Select All WP.org
      </button>
      <button 
        onclick={() => plugins.selectNoneWpOrg()}
        class="px-3 py-1.5 bg-elevated hover:bg-elevated text-muted hover:text-ink text-xs rounded transition-colors"
      >
        None
      </button>
    </div>
    <div class="flex items-center gap-2">
      <span class="text-xs text-primary font-medium">
        {$plugins.selectedWpOrg.length + $plugins.selectedWpmuDev.length + $plugins.selectedSuspicious.length} selected
      </span>
      <button 
        onclick={() => plugins.copyPluginList()}
        class="px-3 py-1.5 bg-elevated hover:bg-elevated text-muted hover:text-ink text-xs rounded transition-colors flex items-center gap-1"
      >
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
        </svg>
        Copy List
      </button>
    </div>
  </div>
  
  <!-- WP.org Plugins Table -->
  {#if $plugins.wpOrgPlugins.length > 0}
    <div class="mb-6">
      <h3 class="text-sm font-semibold text-ink mb-3 flex items-center gap-2">
        <svg class="w-4 h-4 text-green-700 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        WordPress.org Plugins ({$plugins.wpOrgPlugins.length})
      </h3>
      <div class="bg-app border border-line rounded-lg overflow-hidden">
        <div class="max-h-64 overflow-y-auto">
          <table class="w-full text-sm">
            <thead class="bg-elevated sticky top-0">
              <tr>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted w-8">
                  <input 
                    type="checkbox" 
                    checked={$plugins.selectedWpOrg.length === $plugins.wpOrgPlugins.length}
                    onchange={() => $plugins.selectedWpOrg.length === $plugins.wpOrgPlugins.length ? plugins.selectNoneWpOrg() : plugins.selectAllWpOrg()}
                    class="rounded bg-elevated border-line"
                  >
                </th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted">Plugin</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted w-20">Version</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted w-28">.org files</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted w-28">Last Updated</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted w-24">Plugin Page</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              {#each $plugins.wpOrgPlugins as plugin}
                <tr class="hover:bg-elevated/50">
                  <td class="px-3 py-2">
                    <input 
                      type="checkbox" 
                      checked={$plugins.selectedWpOrg.includes(plugin.slug)}
                      onchange={() => plugins.toggleWpOrgPlugin(plugin.slug)}
                      class="rounded bg-elevated border-line"
                    >
                  </td>
                  <td class="px-3 py-2 text-ink">{plugin.name}</td>
                  <td class="px-3 py-2 text-muted">{plugin.version}</td>
                  <td class="px-3 py-2 text-xs">{checksumLabel(plugin)}</td>
                  <td class="px-3 py-2 text-muted text-xs">{plugin.last_updated || 'N/A'}</td>
                  <td class="px-3 py-2">
                    {#if plugin.plugin_url}
                      <a 
                        href={plugin.plugin_url} 
                        target="_blank" 
                        rel="noopener noreferrer"
                        class="text-primary hover:text-primary/80 text-xs"
                      >
                        View →
                      </a>
                    {:else}
                      <span class="text-faint text-xs">N/A</span>
                    {/if}
                  </td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  {/if}
  
  <!-- WPMU DEV Selection Controls -->
  <div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
      <button 
        onclick={() => plugins.selectAllWpmuDev()}
        class="px-3 py-1.5 bg-elevated hover:bg-elevated text-muted hover:text-ink text-xs rounded transition-colors"
      >
        Select All WPMU
      </button>
      <button 
        onclick={() => plugins.selectNoneWpmuDev()}
        class="px-3 py-1.5 bg-elevated hover:bg-elevated text-muted hover:text-ink text-xs rounded transition-colors"
      >
        None
      </button>
    </div>
  </div>
  
  <!-- WPMU DEV Plugins Table -->
  {#if $plugins.wpmuDevPlugins.length > 0}
    <div class="mb-6">
      <h3 class="text-sm font-semibold text-ink mb-3 flex items-center gap-2">
        <svg class="w-4 h-4 text-blue-700 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        WPMU DEV Plugins ({$plugins.wpmuDevPlugins.length})
      </h3>
      <div class="bg-app border border-line rounded-lg overflow-hidden">
        <div class="max-h-48 overflow-y-auto">
          <table class="w-full text-sm">
            <thead class="bg-elevated sticky top-0">
              <tr>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted w-8">
                  <input 
                    type="checkbox" 
                    checked={$plugins.selectedWpmuDev.length === $plugins.wpmuDevPlugins.length}
                    onchange={() => $plugins.selectedWpmuDev.length === $plugins.wpmuDevPlugins.length ? plugins.selectNoneWpmuDev() : plugins.selectAllWpmuDev()}
                    class="rounded bg-elevated border-line"
                  >
                </th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted">Plugin</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted w-20">Version</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted">Description</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              {#each $plugins.wpmuDevPlugins as plugin}
                <tr class="hover:bg-elevated/50">
                  <td class="px-3 py-2">
                    <input 
                      type="checkbox" 
                      checked={$plugins.selectedWpmuDev.includes(plugin.slug)}
                      onchange={() => plugins.toggleWpmuDevPlugin(plugin.slug)}
                      class="rounded bg-elevated border-line"
                    >
                  </td>
                  <td class="px-3 py-2 text-ink">{plugin.name}</td>
                  <td class="px-3 py-2 text-muted">{plugin.version}</td>
                  <td class="px-3 py-2 text-muted text-xs max-w-xs truncate" title={plugin.description || ''}>
                    {plugin.description || 'No description available'}
                  </td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  {/if}
  
  {#if $plugins.likelyFakePlugins && $plugins.likelyFakePlugins.length > 0}
    <div class="mb-4">
      <h3 class="text-sm font-semibold text-ink mb-3 flex items-center gap-2">
        <svg class="w-4 h-4 text-orange-700 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
        </svg>
        Likely fake / impersonating ({$plugins.likelyFakePlugins.length})
        <span class="text-xs font-normal text-muted">(review only — not auto-deleted)</span>
      </h3>
      <div class="bg-app border border-orange-500/25 rounded-lg overflow-hidden">
        <div class="max-h-48 overflow-y-auto">
          <table class="w-full text-sm">
            <thead class="bg-elevated sticky top-0">
              <tr>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted">Plugin</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted w-20">Version</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted w-24">Kind</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted">Why</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              {#each $plugins.likelyFakePlugins as plugin}
                <tr>
                  <td class="px-3 py-2 text-ink">
                    <div>{plugin.name}</div>
                    <div class="text-[10px] font-mono text-faint">{plugin.slug}</div>
                  </td>
                  <td class="px-3 py-2 text-muted">{plugin.version}</td>
                  <td class="px-3 py-2 text-xs text-orange-800 dark:text-orange-300">
                    {plugin.identity_kind === 'impersonating' ? 'Stolen slug' : 'Decoy'}
                  </td>
                  <td class="px-3 py-2 text-xs text-muted">
                    {(plugin.reasons && plugin.reasons[0]) || plugin.reason || 'Looks like a fake package'}
                  </td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  {/if}

  <!-- Custom/Non-repo Plugins (read-only) -->
  {#if $plugins.customPlugins.length > 0}
    <div class="mb-4">
      <h3 class="text-sm font-semibold text-ink mb-3 flex items-center gap-2">
        <svg class="w-4 h-4 text-amber-700 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
        </svg>
        Custom/Non-repo Plugins ({$plugins.customPlugins.length})
        <span class="text-xs font-normal text-muted">(cannot be reinstalled)</span>
      </h3>
      <div class="bg-app border border-line rounded-lg overflow-hidden">
        <div class="max-h-32 overflow-y-auto">
          <table class="w-full text-sm">
            <thead class="bg-elevated sticky top-0">
              <tr>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted">Plugin</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-muted w-20">Version</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              {#each $plugins.customPlugins as plugin}
                <tr>
                  <td class="px-3 py-2 text-muted">{plugin.name}</td>
                  <td class="px-3 py-2 text-muted">{plugin.version}</td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  {/if}
  
  <!-- Suspicious Files Section -->
  {#if $plugins.suspiciousFiles && $plugins.suspiciousFiles.length > 0}
    <div class="mb-4">
      <div class="flex items-center justify-between mb-3 gap-3">
        <h3 class="text-sm font-semibold text-ink flex items-center gap-2 min-w-0">
          <svg class="w-4 h-4 text-red-700 dark:text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
          </svg>
          <span>Orphan Files/Folders ({$plugins.suspiciousFiles.length})</span>
          <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded bg-red-500/15 text-red-700 dark:text-red-300">
            {$plugins.selectedSuspicious.length} to delete
          </span>
        </h3>
        <div class="flex items-center gap-2 shrink-0">
          <button 
            onclick={() => plugins.selectAllSuspicious()}
            class="px-3 py-1.5 bg-elevated hover:bg-elevated text-muted hover:text-ink text-xs rounded transition-colors"
          >
            Delete all
          </button>
          <button 
            onclick={() => plugins.selectNoneSuspicious()}
            class="px-3 py-1.5 bg-elevated hover:bg-elevated text-muted hover:text-ink text-xs rounded transition-colors"
          >
            Keep all
          </button>
        </div>
      </div>
      <div class="bg-red-500/10 border border-red-500/20 rounded-lg overflow-hidden">
        <div class="max-h-48 overflow-y-auto">
          <table class="w-full text-sm">
            <thead class="bg-red-500/20 sticky top-0">
              <tr>
                <th class="px-3 py-2 text-left text-xs font-medium text-red-700 dark:text-red-300 w-14" title="Checked items are deleted on reinstall">
                  <span class="sr-only">Delete</span>
                  <input 
                    type="checkbox" 
                    checked={$plugins.selectedSuspicious.length === $plugins.suspiciousFiles.length && $plugins.suspiciousFiles.length > 0}
                    onchange={() => $plugins.selectedSuspicious.length === $plugins.suspiciousFiles.length ? plugins.selectNoneSuspicious() : plugins.selectAllSuspicious()}
                    class="rounded bg-elevated border-line"
                    title="Delete all orphans"
                  >
                </th>
                <th class="px-3 py-2 text-left text-xs font-medium text-red-700 dark:text-red-300">File/Folder</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-red-700 dark:text-red-300 w-20">Severity</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-red-700 dark:text-red-300">Reason</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-red-700 dark:text-red-300 w-20">Type</th>
                <th class="px-3 py-2 text-left text-xs font-medium text-red-700 dark:text-red-300 w-20">Size</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-red-500/20">
              {#each $plugins.suspiciousFiles as file}
                {@const severity = (file.severity || 'high').toLowerCase()}
                {@const reason = (file.reasons && file.reasons[0]) || file.reason || (file.category === 'mu_plugin' ? 'Must-use plugin item' : 'Unrecognized')}
                <tr class="hover:bg-red-500/10 transition-colors">
                  <td class="px-3 py-2">
                    <input 
                      type="checkbox" 
                      checked={$plugins.selectedSuspicious.includes(file.name)}
                      onchange={() => plugins.toggleSuspiciousFile(file.name)}
                      class="rounded bg-elevated border-line"
                    >
                  </td>
                  <td class="px-3 py-2">
                    <div class="text-ink font-mono text-xs break-all">{file.name}</div>
                    {#if file.category}
                      <div class="text-[10px] text-muted mt-0.5">{file.category}{file.parent_slug ? ` · ${file.parent_slug}` : ''}</div>
                    {/if}
                  </td>
                  <td class="px-3 py-2">
                    <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide
                      {severity === 'high' || severity === 'critical'
                        ? 'bg-red-500/20 text-red-700 dark:text-red-300'
                        : severity === 'medium'
                          ? 'bg-orange-500/20 text-orange-700 dark:text-orange-300'
                          : 'bg-yellow-500/15 text-yellow-700 dark:text-yellow-300'}">{severity}</span>
                  </td>
                  <td class="px-3 py-2 text-red-700 dark:text-red-300 text-xs">{reason}</td>
                  <td class="px-3 py-2 text-red-700 dark:text-red-300">{file.is_directory ? 'Directory' : 'File'}</td>
                  <td class="px-3 py-2 text-red-700 dark:text-red-300">{file.is_directory ? ((file.file_count ?? 0) + ' files') : ((file.size_mb ?? 0) + ' MB')}</td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
      </div>
      <p class="text-xs text-red-800/80 dark:text-red-200/80 mt-2">
        Checked = permanently deleted on reinstall. High-severity orphans are checked by default — uncheck to keep. Known plugins are reinstalled from their sections.
      </p>
    </div>
  {/if}
  
  <!-- Reinstall Button -->
  {#if showReinstallButton && !$plugins.pluginReinstalling}
    <div class="flex items-center gap-4 mb-5">
      <Button.Root 
        variant="primary"
        size="default"
        onclick={handleReinstallClick}
        disabled={$plugins.selectedWpOrg.length + $plugins.selectedWpmuDev.length === 0}
        class="inline-flex items-center gap-2 px-4 py-2.5
        bg-primary hover:bg-primary/80
        text-primary-foreground text-sm font-medium
        border border-primary/30 rounded-md
        shadow-sm hover:shadow-md
        active:scale-[0.98] active:shadow-none
        focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0a0a0c]
        disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-primary
        transition-all duration-200 cursor-pointer"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
        </svg>
        <span>Reinstall Selected ({$plugins.selectedWpOrg.length + $plugins.selectedWpmuDev.length})</span>
      </Button.Root>
      {#if $plugins.selectedSuspicious.length > 0}
        <span class="text-xs text-red-700 dark:text-red-400">
          Also deletes {$plugins.selectedSuspicious.length} orphan{$plugins.selectedSuspicious.length === 1 ? '' : 's'}
        </span>
      {:else if $plugins.suspiciousFiles.length > 0}
        <span class="text-xs text-muted">Orphans will be kept</span>
      {/if}
    </div>
  {/if}
  
  <!-- Progress Bar (during reinstall) -->
  {#if $plugins.pluginReinstalling}
    {@const rp = $plugins.reinstallProgress}
    {@const current = rp?.current ?? 0}
    {@const total = rp?.total || 0}
    {@const pct = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0}
    <div class="mb-5">
      <div class="bg-app border border-line rounded-lg p-4">
        <div class="flex items-center justify-between gap-3 mb-2">
          <span class="text-sm text-ink truncate">
            {#if $plugins.backupInProgress}
              Creating backup...
            {:else if rp?.phase === 'cleanup'}
              Cleaning orphan files...
            {:else if rp?.plugin}
              Reinstalling {rp.plugin}
            {:else}
              Reinstalling plugins...
            {/if}
          </span>
          <span class="text-sm text-muted tabular-nums shrink-0">
            {current} / {total}
          </span>
        </div>
        <div class="w-full bg-elevated rounded-full h-2">
          <div 
            class="bg-primary h-2 rounded-full transition-all duration-300" 
            style="width: {pct}%"
          ></div>
        </div>
      </div>
    </div>
  {/if}
  
  <!-- Summary Modal (after reinstall complete) -->
  {#if $plugins.showSummary && $plugins.reinstallResults}
    <ReinstallSummary 
      results={$plugins.reinstallResults}
      onClose={() => plugins.hideSummary()}
      onReanalyze={() => { plugins.hideSummary(); plugins.loadPlugins(); }}
    />
  {/if}
</div>

<!-- Backup Dialog Modal -->
{#if $plugins.showBackupDialog}
  <div class="fixed inset-0 bg-black/60 flex items-center justify-center z-50">
    <div class="bg-panel border border-line rounded-lg p-6 w-full max-w-md shadow-xl">
      <h3 class="text-lg font-semibold text-ink mb-2">Confirm Reinstallation</h3>
      <p class="text-sm text-muted {$plugins.suspiciousFiles.length > 0 ? 'mb-3' : 'mb-5'}">
        You are about to reinstall {$plugins.selectedWpOrg.length + $plugins.selectedWpmuDev.length} plugin(s).
      </p>
      {#if $plugins.selectedSuspicious.length > 0}
        <p class="text-sm text-red-700 dark:text-red-300 font-medium mb-5">
          Permanently deletes {$plugins.selectedSuspicious.length} orphan file{$plugins.selectedSuspicious.length === 1 ? '' : 's'}/folder{$plugins.selectedSuspicious.length === 1 ? '' : 's'}. Unchecked orphans are kept.
        </p>
      {:else if $plugins.suspiciousFiles.length > 0}
        <p class="text-sm text-muted mb-5">
          No orphans checked — they will be kept.
        </p>
      {/if}
      
      <div class="space-y-3 mb-6">
        <label class="flex items-start gap-3 p-4 bg-app rounded-md border border-primary/30 cursor-pointer hover:border-primary/50 transition-colors">
          <input 
            type="radio" 
            name="backup" 
            value="backup"
            checked
            class="mt-0.5 accent-primary"
          >
          <div>
            <div class="text-sm font-medium text-ink">Create Backup (Recommended)</div>
            <div class="text-xs text-muted mt-1">
              A backup of your plugins will be created before reinstallation.
            </div>
          </div>
        </label>
        
        <label class="flex items-start gap-3 p-4 bg-app rounded-md border border-line cursor-pointer hover:border-line transition-colors">
          <input 
            type="radio" 
            name="backup" 
            value="skip"
            class="mt-0.5 accent-primary"
          >
          <div>
            <div class="text-sm font-medium text-ink">Skip Backup</div>
            <div class="text-xs text-muted mt-1">
              Proceed without creating a backup. Not recommended.
            </div>
          </div>
        </label>
      </div>
      
      <div class="flex items-center gap-3">
        <button 
          onclick={() => {
            const selected = document.querySelector('input[name="backup"]:checked')?.value;
            handleConfirmReinstall(selected === 'backup');
          }}
          disabled={$plugins.pluginReinstalling}
          class="flex-1 px-4 py-2.5 bg-primary hover:bg-primary/80 disabled:opacity-50 text-white text-sm font-medium rounded-md transition-all"
        >
          {#if $plugins.pluginReinstalling}
            Processing...
          {:else}
            Confirm & Reinstall
          {/if}
        </button>
        <button 
          onclick={() => { plugins.hideReinstallDialog(); }}
          disabled={$plugins.pluginReinstalling}
          class="px-4 py-2.5 bg-elevated hover:bg-elevated text-ink text-sm font-medium rounded-md transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  </div>
{/if}
