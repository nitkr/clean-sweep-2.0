<script>
  import { onMount } from 'svelte';
  import { plugins } from '../../lib/stores/plugins.js';
  import { themes } from '../../lib/stores/themes.js';
  import { app } from '../../lib/stores/app.js';
  import ProgressBar from '../common/ProgressBar.svelte';
  import ReinstallSummary from '../common/ReinstallSummary.svelte';
  import { Button } from 'bits-ui';

  // Import modular plugin list component
  import PluginList from './PluginList.svelte';

  // Track which section is active: 'initial' | 'plugins' | 'themes'
  let activeSection = $state('initial');

  // Stats derived from stores
  let pluginsLoading = $derived($plugins.pluginsLoading);
  let themesLoading = $derived($themes.themesLoading);
  let totalPlugins = $derived($plugins.plugins?.length || 0);
  let totalThemes = $derived($themes.themes?.length || 0);

  // Handle analyze button click for plugins
  function handleAnalyzePlugins() {
    activeSection = 'plugins';
    plugins.loadPlugins();
  }

  // Handle analyze button click for themes
  function handleAnalyzeThemes() {
    activeSection = 'themes';
    themes.loadThemes();
  }

  // Upload success "Analyze Extensions" sets a one-shot flag. Opening this tab alone does not analyze.
  onMount(() => {
    const kind = app.consumeExtensionsAnalyze();
    if (kind === 'themes') {
      handleAnalyzeThemes();
    } else if (kind === 'plugins') {
      handleAnalyzePlugins();
    }
  });

  // Handle reinstall button click for plugins
  function handleReinstallPlugins() {
    plugins.showReinstallDialog();
  }

  // Handle reinstall button click for themes
  function handleReinstallThemes() {
    themes.showReinstallDialog();
  }

  // Handle backup confirmation for plugins
  async function handleConfirmReinstallPlugins(createBackup) {
    await plugins.reinstallAllPlugins(createBackup);
  }

  // Handle backup confirmation for themes
  async function handleConfirmReinstallThemes(createBackup) {
    await themes.reinstallAllThemes(createBackup);
  }

  // Go back to initial card view
  function goBack() {
    activeSection = 'initial';
  }

  function themeChecksumLabel(theme) {
    const st = theme?.checksum_status;
    if (!st) return '—';
    if (st === 'match') return 'Matches .org';
    if (st === 'modified') return theme.checksum_findings ? `Modified (${theme.checksum_findings})` : 'Modified';
    if (st === 'unavailable') return 'Not on .org';
    return '—';
  }
</script>

<div class="h-full overflow-y-auto">
  <div class="p-6 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
      <div class="flex items-center gap-3 mb-2">
        <div class="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center">
          <svg class="w-5 h-5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
          </svg>
        </div>
        <div>
          <h1 class="text-xl font-bold text-ink">Extensions</h1>
          <p class="text-sm text-muted">Manage plugins and themes from WordPress.org</p>
        </div>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-4 gap-4 mb-6">
      <button
        onclick={handleAnalyzePlugins}
        class="col-span-2 p-4 bg-gradient-to-r from-purple-500/10 to-purple-600/5 border border-purple-500/20 rounded-xl hover:border-purple-500/40 transition-all text-left group"
        disabled={pluginsLoading}
      >
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-ink mb-1">WordPress Plugins</p>
            <p class="text-xs text-muted">Click to analyze {totalPlugins > 0 ? `(${totalPlugins} installed)` : ''}</p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-purple-500/20 flex items-center justify-center group-hover:bg-purple-500/30 transition-colors">
            {#if pluginsLoading}
              <svg class="w-6 h-6 text-purple-700 dark:text-purple-400 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            {:else}
              <svg class="w-6 h-6 text-purple-700 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
              </svg>
            {/if}
          </div>
        </div>
      </button>

      <button
        onclick={handleAnalyzeThemes}
        class="col-span-2 p-4 bg-gradient-to-r from-cyan-500/10 to-cyan-600/5 border border-cyan-500/20 rounded-xl hover:border-cyan-500/40 transition-all text-left group"
        disabled={themesLoading}
      >
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-ink mb-1">WordPress Themes</p>
            <p class="text-xs text-muted">Click to analyze {totalThemes > 0 ? `(${totalThemes} installed)` : ''}</p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-cyan-500/20 flex items-center justify-center group-hover:bg-cyan-500/30 transition-colors">
            {#if themesLoading}
              <svg class="w-6 h-6 text-cyan-700 dark:text-cyan-400 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            {:else}
              <svg class="w-6 h-6 text-cyan-700 dark:text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
              </svg>
            {/if}
          </div>
        </div>
      </button>
    </div>

    <!-- Back Button when in plugins or themes section -->
    {#if activeSection !== 'initial'}
      <button
        onclick={goBack}
        class="mb-4 px-3 py-1.5 text-xs text-muted hover:text-ink transition-colors flex items-center gap-1"
      >
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
        </svg>
        Back to Overview
      </button>
    {/if}

    <!-- PLUGINS SECTION -->
    {#if activeSection === 'plugins'}
      {#if $plugins.workflowState === 'initial'}
        <div class="bg-panel rounded-lg p-5 mb-6 border border-line">
          <div class="bg-primary/10 border border-primary/20 rounded-lg p-4 mb-5">
            <div class="flex items-start gap-3">
              <svg class="w-5 h-5 text-primary mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              <div>
                <div class="text-sm font-medium text-primary">What this does</div>
                <p class="text-xs text-muted mt-1">
                  Analyze your installed plugins to identify which ones can be reinstalled from WordPress.org or WPMU DEV.
                  This will also list orphan files/folders in the plugins directory that are not part of any known plugin.
                </p>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-4">
            <button
              onclick={() => plugins.loadPlugins()}
              disabled={$plugins.pluginsLoading}
              class="px-5 py-2.5 bg-primary hover:bg-primary/80 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-md transition-all flex items-center gap-2"
            >
              {#if $plugins.pluginsLoading}
                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span>Analyzing...</span>
              {:else}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                <span>Analyze Plugins</span>
              {/if}
            </button>
          </div>
        </div>
      {/if}

      {#if $plugins.workflowState === 'analyzing'}
        <div class="bg-panel rounded-lg p-5 mb-6 border border-line">
          <div class="text-center py-8">
            <div class="inline-block animate-spin rounded-full h-10 w-10 border-b-2 border-primary"></div>
            <p class="text-muted text-sm mt-4">Analyzing plugins...</p>
            <p class="text-faint text-xs mt-1">This may take a few moments</p>
          </div>
        </div>
      {/if}

      {#if $plugins.workflowState === 'analyzed' || $plugins.workflowState === 'reinstalling'}
        <PluginList
          showHeader={false}
          showStats={true}
          showReinstallButton={true}
        />
      {/if}
    {/if}

    <!-- THEMES SECTION -->
    {#if activeSection === 'themes'}
      {#if $themes.workflowState === 'initial'}
        <div class="bg-panel rounded-lg p-5 mb-6 border border-line">
          <div class="bg-cyan-500/10 border border-cyan-500/20 rounded-lg p-4 mb-5">
            <div class="flex items-start gap-3">
              <svg class="w-5 h-5 text-cyan-700 dark:text-cyan-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              <div>
                <div class="text-sm font-medium text-cyan-700 dark:text-cyan-400">What this does</div>
                <p class="text-xs text-muted mt-1">
                  Analyze your installed themes to identify which ones can be reinstalled from WordPress.org.
                  This helps restore clean theme files and fix corrupted installations.
                </p>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-4">
            <button
              onclick={() => themes.loadThemes()}
              disabled={$themes.themesLoading}
              class="px-5 py-2.5 bg-cyan-500 hover:bg-cyan-400 disabled:opacity-50 disabled:cursor-not-allowed text-ink text-sm font-medium rounded-md transition-all flex items-center gap-2"
            >
              {#if $themes.themesLoading}
                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span>Analyzing...</span>
              {:else}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
                <span>Analyze Themes</span>
              {/if}
            </button>
          </div>
        </div>
      {/if}

      {#if $themes.workflowState === 'analyzing'}
        <div class="bg-panel rounded-lg p-5 mb-6 border border-line">
          <div class="text-center py-8">
            <div class="inline-block animate-spin rounded-full h-10 w-10 border-b-2 border-cyan-500"></div>
            <p class="text-muted text-sm mt-4">Analyzing themes...</p>
            <p class="text-faint text-xs mt-1">This may take a few moments</p>
          </div>
        </div>
      {/if}

      {#if $themes.workflowState === 'analyzed' || $themes.workflowState === 'reinstalling'}
        <!-- Security Alert for Suspicious Files -->
        {#if ($themes.themeResults.likely_fake || 0) > 0}
          <div class="mb-5 p-3.5 bg-orange-500/10 border border-orange-500/25 rounded-md">
            <div class="flex items-start gap-3">
              <svg class="w-5 h-5 text-orange-700 dark:text-orange-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
              </svg>
              <div>
                <div class="text-sm font-semibold text-orange-800 dark:text-orange-300">Likely fake packages</div>
                <div class="text-xs text-muted mt-1">
                  {$themes.themeResults.likely_fake} theme{$themes.themeResults.likely_fake === 1 ? '' : 's'} look like decoys or impersonate a WordPress.org slug.
                  They are listed separately and are not selected for reinstall.
                </div>
              </div>
            </div>
          </div>
        {/if}

        {#if $themes.suspiciousFiles.length > 0}
          <div class="mb-5 p-3.5 bg-red-500/10 border border-red-500/20 rounded-md">
            <div class="flex items-start gap-3">
              <svg class="w-5 h-5 text-red-700 dark:text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
              </svg>
              <div>
                <div class="text-sm font-semibold text-red-700 dark:text-red-400">Security Alert</div>
                <div class="text-xs text-muted mt-1">
                  Found {$themes.suspiciousFiles.length} orphan file(s)/folder(s) in the themes directory (not part of any known theme).
                  High-severity items are checked below and will be <span class="text-red-700 dark:text-red-400 font-medium">permanently deleted</span> on reinstall unless you uncheck them.
                </div>
              </div>
            </div>
          </div>
        {/if}

        <div class="bg-panel rounded-lg p-5 mb-6 border border-line">
          <!-- Stats Grid -->
          <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
            <div class="bg-app border border-line rounded-lg p-3 text-center">
              <div class="text-xl font-bold text-ink">{$themes.themeResults.total || 0}</div>
              <div class="text-xs text-muted">Total</div>
            </div>
            <div class="bg-app border border-line rounded-lg p-3 text-center">
              <div class="text-xl font-bold text-green-700 dark:text-green-400">{$themes.themeResults.wp_org}</div>
              <div class="text-xs text-muted">WP.org</div>
            </div>
            <div class="bg-app border border-line rounded-lg p-3 text-center">
              <div class="text-xl font-bold text-amber-700 dark:text-amber-400">{$themes.themeResults.custom}</div>
              <div class="text-xs text-muted">Non-repo</div>
            </div>
            <div class="bg-app border border-orange-500/30 rounded-lg p-3 text-center">
              <div class="text-xl font-bold text-orange-700 dark:text-orange-400">{$themes.themeResults.likely_fake || 0}</div>
              <div class="text-xs text-muted">Likely fake</div>
            </div>
            <div class="bg-app border border-red-500/30 rounded-lg p-3 text-center">
              <div class="text-xl font-bold text-red-700 dark:text-red-400">{$themes.themeResults.suspicious || 0}</div>
              <div class="text-xs text-muted">Orphans</div>
            </div>
            <div class="bg-app border border-line rounded-lg p-3 text-center">
              <div class="text-xl font-bold text-orange-700 dark:text-orange-400">{$themes.themeResults.needing_update}</div>
              <div class="text-xs text-muted">Updates</div>
            </div>
          </div>

          <!-- Selection Controls & Copy List -->
          <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
              <button
                onclick={() => themes.selectAllWpOrg()}
                class="px-3 py-1.5 bg-elevated hover:bg-elevated text-muted hover:text-ink text-xs rounded transition-colors"
              >
                Select All WP.org
              </button>
              <button
                onclick={() => themes.selectNoneWpOrg()}
                class="px-3 py-1.5 bg-elevated hover:bg-elevated text-muted hover:text-ink text-xs rounded transition-colors"
              >
                None
              </button>
            </div>
            <div class="flex items-center gap-2">
              <span class="text-xs text-primary font-medium">
                {$themes.selectedWpOrg.length + $themes.selectedSuspicious.length} selected
              </span>
              <button
                onclick={() => themes.copyThemeList()}
                class="px-3 py-1.5 bg-elevated hover:bg-elevated text-muted hover:text-ink text-xs rounded transition-colors flex items-center gap-1"
              >
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                </svg>
                Copy List
              </button>
            </div>
          </div>

          <!-- WP.org Themes Table -->
          {#if $themes.wpOrgThemes.length > 0}
            <div class="mb-6">
              <h3 class="text-sm font-semibold text-ink mb-3 flex items-center gap-2">
                <svg class="w-4 h-4 text-green-700 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                WordPress.org Themes ({$themes.wpOrgThemes.length})
              </h3>
              <div class="bg-app border border-line rounded-lg overflow-hidden">
                <div class="max-h-64 overflow-y-auto">
                  <table class="w-full text-sm">
                    <thead class="bg-elevated sticky top-0">
                      <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-muted w-8">
                          <input
                            type="checkbox"
                            checked={$themes.selectedWpOrg.length === $themes.wpOrgThemes.length}
                            onchange={() => $themes.selectedWpOrg.length === $themes.wpOrgThemes.length ? themes.selectNoneWpOrg() : themes.selectAllWpOrg()}
                            class="rounded bg-elevated border-line"
                          >
                        </th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-muted">Theme</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-muted w-20">Version</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-muted w-28">.org files</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                      {#each $themes.wpOrgThemes as theme}
                        <tr class="hover:bg-elevated/50">
                          <td class="px-3 py-2">
                            <input
                              type="checkbox"
                              checked={$themes.selectedWpOrg.includes(theme.slug)}
                              onchange={() => themes.toggleWpOrgTheme(theme.slug)}
                              class="rounded bg-elevated border-line"
                            >
                          </td>
                          <td class="px-3 py-2 text-ink">{theme.name}</td>
                          <td class="px-3 py-2 text-muted">{theme.version}</td>
                          <td class="px-3 py-2 text-xs">{themeChecksumLabel(theme)}</td>
                        </tr>
                      {/each}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          {/if}

          {#if $themes.likelyFakeThemes && $themes.likelyFakeThemes.length > 0}
            <div class="mb-4">
              <h3 class="text-sm font-semibold text-ink mb-3 flex items-center gap-2">
                <svg class="w-4 h-4 text-orange-700 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                Likely fake / impersonating ({$themes.likelyFakeThemes.length})
                <span class="text-xs font-normal text-muted">(review only — not auto-deleted)</span>
              </h3>
              <div class="bg-app border border-orange-500/25 rounded-lg overflow-hidden">
                <div class="max-h-48 overflow-y-auto">
                  <table class="w-full text-sm">
                    <thead class="bg-elevated sticky top-0">
                      <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-muted">Theme</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-muted w-20">Version</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-muted w-24">Kind</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-muted">Why</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                      {#each $themes.likelyFakeThemes as theme}
                        <tr>
                          <td class="px-3 py-2 text-ink">
                            <div>{theme.name}</div>
                            <div class="text-[10px] font-mono text-faint">{theme.slug}</div>
                          </td>
                          <td class="px-3 py-2 text-muted">{theme.version}</td>
                          <td class="px-3 py-2 text-xs text-orange-800 dark:text-orange-300">
                            {theme.identity_kind === 'impersonating' ? 'Stolen slug' : 'Decoy'}
                          </td>
                          <td class="px-3 py-2 text-xs text-muted">
                            {(theme.reasons && theme.reasons[0]) || theme.reason || 'Looks like a fake package'}
                          </td>
                        </tr>
                      {/each}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          {/if}

          <!-- Custom/Non-repo Themes -->
          {#if $themes.customThemes.length > 0}
            <div class="mb-4">
              <h3 class="text-sm font-semibold text-ink mb-3 flex items-center gap-2">
                <svg class="w-4 h-4 text-amber-700 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                Custom/Non-repo Themes ({$themes.customThemes.length})
                <span class="text-xs font-normal text-muted">(cannot be reinstalled)</span>
              </h3>
              <div class="bg-app border border-line rounded-lg overflow-hidden">
                <div class="max-h-32 overflow-y-auto">
                  <table class="w-full text-sm">
                    <thead class="bg-elevated sticky top-0">
                      <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-muted">Theme</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-muted w-20">Version</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                      {#each $themes.customThemes as theme}
                        <tr>
                          <td class="px-3 py-2 text-muted">{theme.name}</td>
                          <td class="px-3 py-2 text-muted">{theme.version}</td>
                        </tr>
                      {/each}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          {/if}

          <!-- Suspicious Files Section -->
          {#if $themes.suspiciousFiles && $themes.suspiciousFiles.length > 0}
            <div class="mb-4">
              <div class="flex items-center justify-between mb-3 gap-3">
                <h3 class="text-sm font-semibold text-ink flex items-center gap-2 min-w-0">
                  <svg class="w-4 h-4 text-red-700 dark:text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                  </svg>
                  <span>Orphan Files/Folders ({$themes.suspiciousFiles.length})</span>
                  <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded bg-red-500/15 text-red-700 dark:text-red-300">
                    {$themes.selectedSuspicious.length} to delete
                  </span>
                </h3>
                <div class="flex items-center gap-2 shrink-0">
                  <button
                    onclick={() => themes.selectAllSuspicious()}
                    class="px-3 py-1.5 bg-elevated hover:bg-elevated text-muted hover:text-ink text-xs rounded transition-colors"
                  >
                    Delete all
                  </button>
                  <button
                    onclick={() => themes.selectNoneSuspicious()}
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
                            checked={$themes.selectedSuspicious.length === $themes.suspiciousFiles.length && $themes.suspiciousFiles.length > 0}
                            onchange={() => $themes.selectedSuspicious.length === $themes.suspiciousFiles.length ? themes.selectNoneSuspicious() : themes.selectAllSuspicious()}
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
                      {#each $themes.suspiciousFiles as file}
                        {@const severity = (file.severity || 'high').toLowerCase()}
                        {@const reason = (file.reasons && file.reasons[0]) || file.reason || 'Unrecognized'}
                        <tr class="hover:bg-red-500/10 transition-colors">
                          <td class="px-3 py-2">
                            <input
                              type="checkbox"
                              checked={$themes.selectedSuspicious.includes(file.name)}
                              onchange={() => themes.toggleSuspiciousFile(file.name)}
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
                          <td class="px-3 py-2 text-red-700 dark:text-red-300">{file.is_directory ? ((file.file_count ?? 0) + ' files') : (file.size_bytes != null ? ((file.size_bytes / 1024).toFixed(1) + ' KB') : ((file.size_mb ?? 0) + ' MB'))}</td>
                        </tr>
                      {/each}
                    </tbody>
                  </table>
                </div>
              </div>
              <p class="text-xs text-red-800/80 dark:text-red-200/80 mt-2">
                Checked = permanently deleted on reinstall. High-severity orphans are checked by default — uncheck to keep. Known themes are reinstalled from their sections.
              </p>
            </div>
          {/if}

          <!-- Reinstall Button -->
          {#if !$themes.themeReinstalling}
            <div class="flex items-center gap-4 mt-5">
              <Button.Root
                variant="primary"
                size="default"
                onclick={handleReinstallThemes}
                disabled={themes.getSelectedCount() === 0}
                class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary hover:bg-primary/80 text-primary-foreground text-sm font-medium border border-primary/30 rounded-md shadow-sm hover:shadow-md active:scale-[0.98] active:shadow-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0a0a0c] disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-primary transition-all duration-200 cursor-pointer"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <span>Reinstall Selected ({themes.getSelectedCount()})</span>
              </Button.Root>
              {#if $themes.selectedSuspicious.length > 0}
                <span class="text-xs text-red-700 dark:text-red-400">
                  Also deletes {$themes.selectedSuspicious.length} orphan{$themes.selectedSuspicious.length === 1 ? '' : 's'}
                </span>
              {:else if $themes.suspiciousFiles.length > 0}
                <span class="text-xs text-muted">Orphans will be kept</span>
              {/if}
            </div>
          {/if}

          <!-- Progress Bar -->
          {#if $themes.themeReinstalling}
            {@const rp = $themes.reinstallProgress}
            {@const current = rp?.current ?? 0}
            {@const total = rp?.total || 0}
            {@const pct = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0}
            <div class="mt-5">
              <div class="bg-app border border-line rounded-lg p-4">
                <div class="flex items-center justify-between gap-3 mb-2">
                  <span class="text-sm text-ink truncate">
                    {#if $themes.backupInProgress}
                      Creating backup...
                    {:else if rp?.phase === 'cleanup'}
                      Cleaning orphan files...
                    {:else if rp?.theme}
                      Reinstalling {rp.theme}
                    {:else}
                      Reinstalling themes...
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

          <!-- Reinstall Summary -->
          {#if $themes.showSummary && $themes.reinstallResults}
            <ReinstallSummary
              results={$themes.reinstallResults}
              type="themes"
              onClose={() => themes.hideSummary()}
            />
          {/if}
        </div>

        <!-- Backup Dialog Modal -->
        {#if $themes.showBackupDialog}
          <div class="fixed inset-0 bg-black/60 flex items-center justify-center z-50">
            <div class="bg-panel border border-line rounded-lg p-6 w-full max-w-md shadow-xl">
              <h3 class="text-lg font-semibold text-ink mb-2">Confirm Reinstallation</h3>
              <p class="text-sm text-muted {$themes.suspiciousFiles.length > 0 ? 'mb-3' : 'mb-5'}">
                You are about to reinstall {$themes.selectedWpOrg.length} theme(s).
              </p>
              {#if $themes.selectedSuspicious.length > 0}
                <p class="text-sm text-red-700 dark:text-red-300 font-medium mb-5">
                  Permanently deletes {$themes.selectedSuspicious.length} orphan file{$themes.selectedSuspicious.length === 1 ? '' : 's'}/folder{$themes.selectedSuspicious.length === 1 ? '' : 's'}. Unchecked orphans are kept.
                </p>
              {:else if $themes.suspiciousFiles.length > 0}
                <p class="text-sm text-muted mb-5">
                  No orphans checked — they will be kept.
                </p>
              {/if}

              <div class="space-y-3 mb-6">
                <label class="flex items-start gap-3 p-4 bg-app rounded-md border border-cyan-500/30 cursor-pointer hover:border-cyan-500/50 transition-colors">
                  <input
                    type="radio"
                    name="backup"
                    value="backup"
                    checked
                    class="mt-0.5 accent-cyan-500"
                  >
                  <div>
                    <div class="text-sm font-medium text-ink">Create Backup (Recommended)</div>
                    <div class="text-xs text-muted mt-1">
                      A backup of your themes will be created before reinstallation.
                    </div>
                  </div>
                </label>

                <label class="flex items-start gap-3 p-4 bg-app rounded-md border border-line cursor-pointer hover:border-line transition-colors">
                  <input
                    type="radio"
                    name="backup"
                    value="skip"
                    class="mt-0.5 accent-cyan-500"
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
                    handleConfirmReinstallThemes(selected === 'backup');
                  }}
                  disabled={$themes.themeReinstalling}
                  class="flex-1 px-4 py-2.5 bg-cyan-500 hover:bg-cyan-400 disabled:opacity-50 text-ink text-sm font-medium rounded-md transition-all"
                >
                  {#if $themes.themeReinstalling}
                    Processing...
                  {:else}
                    Confirm & Reinstall
                  {/if}
                </button>
                <button
                  onclick={() => themes.hideReinstallDialog()}
                  disabled={$themes.themeReinstalling}
                  class="px-4 py-2.5 bg-elevated hover:bg-elevated text-ink text-sm font-medium rounded-md transition-colors"
                >
                  Cancel
                </button>
              </div>
            </div>
          </div>
        {/if}
      {/if}
    {/if}
  </div>
</div>