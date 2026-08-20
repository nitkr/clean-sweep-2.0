<script>
  import { onMount } from 'svelte';
  import { core } from '../../lib/stores/core.js';
  import ProgressBar from '../common/ProgressBar.svelte';
  import { Button } from 'bits-ui';

  // Local variables for radio button binding (to avoid e.set errors)
  let selectedVersionLocal = $state('latest');
  let createBackupLocal = $state(true);

  // Sync local variables with store when store updates using effects
  $effect(() => {
    if ($core.selectedVersion) selectedVersionLocal = $core.selectedVersion;
  });

  $effect(() => {
    if ($core.createBackup !== undefined) createBackupLocal = $core.createBackup;
  });

  // Fetch version options on mount
  onMount(() => {
    core.fetchVersionOptions();
  });

  function handleReinstall() {
    core.startCoreReinstall();
  }

  function handleCancelBackup() {
    core.cancelBackupSelection();
  }

  function handleBackupChoice(createBackup) {
    core.proceedWithBackupChoice(createBackup);
  }

  function selectVersion(version) {
    selectedVersionLocal = version;
    core.setVersion(version);
  }

  function selectBackup(createBackup) {
    createBackupLocal = createBackup;
    core.setBackupPreference(createBackup);
  }

  function handleProceedWithBackup() {
    core.setBackupPreference(createBackupLocal);
    core.proceedWithReinstall();
  }

  // Stats derived from store
  let currentVer = $derived($core.currentVersion || 'Unknown');
  let latestVer = $derived($core.latestVersion || 'Latest');
  let isScanning = $derived($core.coreScanning);
  let versionsLoading = $derived(!!$core.loadingVersions);
  let resolvedVersion = $derived(
    (selectedVersionLocal && selectedVersionLocal !== 'latest')
      ? selectedVersionLocal
      : ($core.selectedVersion && $core.selectedVersion !== 'latest'
          ? $core.selectedVersion
          : ($core.latestVersion || $core.currentVersion || ''))
  );
  let versionReady = $derived(!versionsLoading && !!resolvedVersion);
  let showBackupStep = $derived(
    !!$core.showBackupSelection && versionReady && !isScanning
  );
</script>

<div class="h-full overflow-y-auto">
  <div class="p-6 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
      <div class="flex items-center gap-3 mb-2">
        <div class="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center">
          <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1v-2zM14 13a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/>
          </svg>
        </div>
        <div>
          <h1 class="text-xl font-bold text-ink">WordPress Core</h1>
          <p class="text-sm text-muted">Reinstall WordPress core files</p>
        </div>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-4 gap-4 mb-6">
      <button
        onclick={handleReinstall}
        class="col-span-2 p-4 bg-gradient-to-r from-blue-500/10 to-blue-600/5 border border-blue-500/20 rounded-xl hover:border-blue-500/40 transition-all text-left group disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:border-blue-500/20"
        disabled={isScanning || !versionReady}
        title={!versionReady ? 'Wait for WordPress versions to load' : 'Continue with the selected version'}
      >
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-ink mb-1">Core Reinstallation</p>
            <p class="text-xs text-muted">
              {#if versionsLoading}
                Loading versions…
              {:else if !resolvedVersion}
                Waiting for a WordPress version
              {:else}
                Continue with WordPress {resolvedVersion}
              {/if}
            </p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-blue-500/20 flex items-center justify-center group-hover:bg-blue-500/30 transition-colors">
            {#if isScanning || versionsLoading}
              <svg class="w-6 h-6 text-blue-700 dark:text-blue-400 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            {:else}
              <svg class="w-6 h-6 text-blue-700 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
              </svg>
            {/if}
          </div>
        </div>
      </button>

      <div class="p-4 bg-panel border border-line rounded-xl">
        <div class="flex items-center gap-2 mb-2">
          <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
          <span class="text-xs text-muted">Current</span>
        </div>
        <p class="text-xl font-bold text-ink">{versionsLoading ? '…' : currentVer}</p>
      </div>

      <div class="p-4 bg-panel border border-line rounded-xl">
        <div class="flex items-center gap-2 mb-2">
          <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
          <span class="text-xs text-muted">Latest</span>
        </div>
        <p class="text-xl font-bold text-ink">{versionsLoading ? '…' : latestVer}</p>
      </div>
    </div>

    <!-- Version Selection Card -->
    <div class="bg-panel border border-line rounded-xl overflow-hidden mb-6">
      <div class="px-5 py-4 border-b border-line">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center">
            <svg class="w-4 h-4 text-blue-700 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
          </div>
          <h2 class="text-sm font-semibold text-ink">Select Version to Install</h2>
        </div>
      </div>

      <div class="p-5">
        {#if versionsLoading}
          <!-- Loading State -->
          <div class="flex items-center justify-center p-8 bg-app rounded-md border border-line">
            <svg class="w-6 h-6 animate-spin text-blue-500 mr-3" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span class="text-muted">Loading available versions…</span>
          </div>
        {:else if !resolvedVersion}
          <div class="p-4 bg-app rounded-md border border-line text-sm text-muted">
            No WordPress versions were returned. Refresh this tab and try again.
          </div>
        {:else}
          <div class="grid grid-cols-2 gap-3">
            <!-- Latest version option -->
            <label class="flex items-center gap-3 p-3.5 bg-app rounded-md border border-line cursor-pointer hover:border-blue-500/50 hover:bg-hover transition-all" class:border-blue-500={selectedVersionLocal === ($core.latestVersion || 'latest') || resolvedVersion === $core.latestVersion}>
              <input
                type="radio"
                name="core-version"
                checked={selectedVersionLocal === ($core.latestVersion || 'latest') || resolvedVersion === $core.latestVersion}
                value={$core.latestVersion || 'latest'}
                class="accent-blue-500"
                disabled={isScanning}
                onchange={() => selectVersion($core.latestVersion || 'latest')}
              >
              <div>
                <div class="text-sm font-medium text-ink">
                  {$core.latestVersion ? `WordPress ${$core.latestVersion}` : 'Latest Version'}
                </div>
                <div class="text-xs text-muted">Latest</div>
              </div>
            </label>

            <!-- Current version option - only show if different from Latest -->
            {#if $core.currentVersion && $core.currentVersion !== $core.latestVersion}
              <label class="flex items-center gap-3 p-3.5 bg-app rounded-md border border-line cursor-pointer hover:border-blue-500/50 hover:bg-hover transition-all" class:border-blue-500={selectedVersionLocal === $core.currentVersion}>
                <input
                  type="radio"
                  name="core-version"
                  checked={selectedVersionLocal === $core.currentVersion}
                  value={$core.currentVersion}
                  class="accent-blue-500"
                  disabled={isScanning}
                  onchange={() => selectVersion($core.currentVersion)}
                >
                <div>
                  <div class="text-sm font-medium text-ink">
                    WordPress {$core.currentVersion}
                  </div>
                  <div class="text-xs text-muted">Current</div>
                </div>
              </label>
            {/if}

            <!-- Previous versions from API -->
            {#if $core.availableVersions && $core.availableVersions.length > 0}
              {#each $core.availableVersions as version}
                <label class="flex items-center gap-3 p-3.5 bg-app rounded-md border border-line cursor-pointer hover:border-blue-500/50 hover:bg-hover transition-all" class:border-blue-500={selectedVersionLocal === version}>
                  <input
                    type="radio"
                    name="core-version"
                    checked={selectedVersionLocal === version}
                    value={version}
                    class="accent-blue-500"
                    disabled={isScanning}
                    onchange={() => selectVersion(version)}
                  >
                  <div>
                    <div class="text-sm font-medium text-ink">WordPress {version}</div>
                    <div class="text-xs text-muted">Previous</div>
                  </div>
                </label>
              {/each}
            {/if}
          </div>
          <div class="mt-4 flex flex-wrap items-center gap-3">
            <Button.Root
              variant="primary"
              size="default"
              onclick={handleReinstall}
              disabled={isScanning || !versionReady}
              class="inline-flex items-center gap-2 px-4 py-2.5
                bg-blue-500 hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed
                text-white text-sm font-medium
                border border-blue-600/30 rounded-md
                shadow-sm hover:shadow-md
                active:scale-[0.98] active:shadow-none
                focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0a0a0c]
                transition-all duration-200 cursor-pointer"
            >
              Continue with WordPress {resolvedVersion}
            </Button.Root>
            <p class="text-xs text-muted">
              Select a version first. Backup options appear after you continue.
            </p>
          </div>
        {/if}
      </div>
    </div>

    <!-- Backup Selection (only after versions loaded and user continues) -->
    {#if showBackupStep}
      <div class="bg-panel border border-blue-500/30 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-line">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center">
              <svg class="w-4 h-4 text-blue-700 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
              </svg>
            </div>
            <h2 class="text-sm font-semibold text-ink">
              Install WordPress {resolvedVersion}
            </h2>
          </div>
        </div>

        <div class="p-5 space-y-3">
          <label class="flex items-center gap-3 p-4 bg-app rounded-md border border-line cursor-pointer hover:border-blue-500/50 hover:bg-hover transition-all" class:border-blue-500={createBackupLocal === true}>
            <input
              type="radio"
              name="core-backup"
              checked={createBackupLocal === true}
              class="accent-blue-500"
              onchange={() => selectBackup(true)}
            >
            <div>
              <div class="text-sm font-medium text-ink">Create Backup</div>
              <div class="text-xs text-muted">Create a backup ZIP before reinstalling (recommended)</div>
            </div>
          </label>

          <label class="flex items-center gap-3 p-4 bg-app rounded-md border border-line cursor-pointer hover:border-blue-500/50 hover:bg-hover transition-all" class:border-blue-500={createBackupLocal === false}>
            <input
              type="radio"
              name="core-backup"
              checked={createBackupLocal === false}
              class="accent-blue-500"
              onchange={() => selectBackup(false)}
            >
            <div>
              <div class="text-sm font-medium text-ink">Skip Backup</div>
              <div class="text-xs text-muted">Proceed without creating a backup (faster but riskier)</div>
            </div>
          </label>
        </div>

        <!-- Action Buttons -->
        <div class="px-5 pb-5 flex gap-3">
          <Button.Root
            variant="primary"
            size="default"
            onclick={handleProceedWithBackup}
            class="inline-flex items-center gap-2 px-4 py-2.5
              bg-blue-500 hover:bg-blue-600
              text-white text-sm font-medium
              border border-blue-600/30 rounded-md
              shadow-sm hover:shadow-md
              active:scale-[0.98] active:shadow-none
              focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0a0a0c]
              transition-all duration-200 cursor-pointer"
          >
            <span>Confirm & Reinstall</span>
          </Button.Root>

          <Button.Root
            variant="secondary"
            size="default"
            onclick={handleCancelBackup}
            class="inline-flex items-center gap-2 px-4 py-2.5
              bg-elevated hover:bg-elevated
              text-ink text-sm font-medium
              border border-line rounded-md
              shadow-sm hover:shadow-md
              active:scale-[0.98] active:shadow-none
              focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#3f3f46]/50 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0a0a0c]
              transition-all duration-200 cursor-pointer"
          >
            <span>Cancel</span>
          </Button.Root>
        </div>
      </div>
    {/if}

    <!-- Progress -->
    {#if $core.coreScanning}
      <div class="mb-6">
        <ProgressBar
          percent={$core.coreProgress}
          message={$core.coreProgressMessage}
        />
      </div>
    {/if}

    {#if $core.reinstallSuccess}
      <div class="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-xl">
        <div class="flex items-start gap-3">
          <svg class="w-5 h-5 text-emerald-700 dark:text-emerald-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <div>
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">Core reinstall complete</p>
            <p class="text-xs text-muted mt-1">{$core.reinstallSuccess}</p>
            <p class="text-xs text-amber-700 dark:text-amber-400 mt-2">Core is sealed. Plugins, themes, and must-use plugins are not. Leftover malware there can rewrite core.</p>
          </div>
        </div>
      </div>
    {/if}

    <!-- Error -->
    {#if $core.error}
      <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 rounded-xl">
        <div class="flex items-center gap-3">
          <svg class="w-5 h-5 text-red-700 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <p class="text-red-700 dark:text-red-400 text-sm">{$core.error}</p>
        </div>
      </div>
    {/if}

    <!-- Info Box -->
    <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4">
      <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-blue-700 dark:text-blue-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <div>
          <div class="text-sm font-medium text-blue-700 dark:text-blue-400">What this does</div>
          <p class="text-xs text-muted mt-1">
            Core reinstallation will replace all WordPress core files while preserving your wp-config.php and wp-content folder.
            This can fix corrupted files and restore WordPress to a clean state.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Backup Choice Modal (shown when API returns backup choice) -->
{#if $core.showBackupChoice && $core.diskCheck}
  <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="bg-panel border border-line rounded-lg p-6 max-w-md w-full mx-4">
      <h3 class="text-lg font-semibold text-ink mb-4">💾 Backup Options</h3>

      <div class="mb-4 p-3 bg-app rounded-md border border-line">
        <p class="text-sm text-muted mb-2"><strong class="text-ink">Backup Size:</strong> {$core.diskCheck.backup_size_mb || 'N/A'}MB</p>
        <p class="text-sm text-muted mb-2"><strong class="text-ink">Required:</strong> {$core.diskCheck.required_mb || 'N/A'}MB</p>
        <p class="text-sm text-muted"><strong class="text-ink">Available:</strong> {$core.diskCheck.available_mb || 'N/A'}MB</p>

        {#if $core.diskCheck.space_status === 'insufficient'}
          <p class="text-sm text-red-700 dark:text-red-400 mt-2">⚠️ Insufficient disk space for backup</p>
        {/if}
      </div>

      <div class="flex gap-3">
        <Button.Root
          variant="primary"
          onclick={() => handleBackupChoice(true)}
          class="flex-1 px-4 py-2 bg-primary hover:bg-primary/80 text-primary-foreground rounded-md"
        >
          Create Backup
        </Button.Root>

        <Button.Root
          variant="outline"
          onclick={() => handleBackupChoice(false)}
          class="flex-1 px-4 py-2 border border-line hover:bg-elevated text-ink rounded-md"
        >
          Skip Backup
        </Button.Root>
      </div>
    </div>
  </div>
{/if}

<style>
  input[type="radio"] {
    accent-color: var(--color-primary);
  }
</style>