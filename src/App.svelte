<script>
  import { theme } from './lib/theme.js';
  import { debug } from './lib/debug.js';
  import { scanning } from './lib/stores/scanning.js';
  import { vulnerabilities } from './lib/stores/vulnerabilities.js';
  import { app } from './lib/stores/app.js';
  
  // Import the IDE layout
  import IDELayout from './components/layout/IDELayout.svelte';
  import RecoveryMode from './components/RecoveryMode.svelte';
  
  import ErrorToast from './components/common/ErrorToast.svelte';
  import DebugPanel from './components/common/DebugPanel.svelte';
  import ToolkitSheet from './components/common/ToolkitSheet.svelte';
  
  // Check if in recovery mode (passed from PHP via window object)
  let isRecoveryMode = false;
  let recoveryIssues = [];
  
  // Initialize theme
  theme.init();
  
  debug.log('APP', 'Clean Sweep IDE initialized');
  
  // Check for recovery mode from PHP data
  if (typeof window !== 'undefined' && window.cleanSweepRecovery) {
    isRecoveryMode = window.cleanSweepRecovery.isRecoveryMode;
    recoveryIssues = window.cleanSweepRecovery.issues || [];
  }

  // Reattach malware scan / recent results after refresh (A + C-lite)
  if (!isRecoveryMode && typeof window !== 'undefined') {
    scanning.rehydrateFromServer().then((r) => {
      if (r?.restored) {
        debug.log('APP', 'Malware scan rehydrated', r);
      }
    }).catch((e) => {
      debug.log('APP', 'Scan rehydrate skipped', e?.message || e);
    });
    vulnerabilities.rehydrateFromServer().then((r) => {
      if (r?.restored) {
        debug.log('APP', 'Vulnerability results restored', r);
      }
    }).catch((e) => {
      debug.log('APP', 'Vuln rehydrate skipped', e?.message || e);
    });
  }
</script>

{#if isRecoveryMode}
  <RecoveryMode issues={recoveryIssues} />
{:else if $app.toolkitRemoved}
  <div class="flex h-screen bg-app text-ink font-sans antialiased theme-surface items-center justify-center p-6">
    <div class="max-w-lg w-full text-center space-y-4">
      <div class="mx-auto w-14 h-14 rounded-2xl bg-emerald-500/15 border border-emerald-500/25 flex items-center justify-center">
        <svg class="w-7 h-7 text-emerald-700 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
      </div>
      <h1 class="text-2xl font-bold text-ink">Clean Sweep is no longer on this server</h1>
      <p class="text-sm text-ink leading-relaxed">
        The toolkit folder, live-watch agent, and Clean Sweep visit data have been deleted.
        Your WordPress site was not changed.
      </p>
      <p class="text-sm text-muted leading-relaxed">
        Close this browser tab. This page cannot talk to Clean Sweep anymore.
        To use it again later, upload the zip to this site.
      </p>
    </div>
  </div>
{:else}
  <IDELayout />
  <ToolkitSheet />
  <ErrorToast />
  <DebugPanel />
{/if}
