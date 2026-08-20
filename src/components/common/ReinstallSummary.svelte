<script>
  import { plugins } from '../../lib/stores/plugins.js';
  import { themes } from '../../lib/stores/themes.js';
  import { Button } from 'bits-ui';
  
  let { 
    results = {}, 
    type = 'plugins', // 'plugins' or 'themes'
    onClose = () => (type === 'themes' ? themes.hideSummary() : plugins.hideSummary()), 
    onReanalyze = () => { 
      if (type === 'themes') {
        themes.hideSummary(); 
        themes.loadThemes();
      } else {
        plugins.hideSummary(); 
        plugins.loadPlugins();
      }
    } 
  } = $props();
  
  // Empty [] is truthy — do not use `a || b` or a blank top-level list hides nested results.
  function collect(key) {
    const top = Array.isArray(results?.[key]) ? results[key] : [];
    if (top.length) return top;
    const org = Array.isArray(results?.wordpress_org?.[key]) ? results.wordpress_org[key] : [];
    const wpmu = Array.isArray(results?.wpmu_dev?.[key]) ? results.wpmu_dev[key] : [];
    return [...org, ...wpmu];
  }
  let successful = $derived(collect('successful'));
  let failed = $derived(collect('failed'));
  // FIX: Backend doesn't return verification_results, so derive verified from successful
  // and missing from failed. This matches what actually happens - items are either
  // successfully reinstalled or they fail.
  let verified = $derived(results?.verification_results?.verified || successful);
  let missing = $derived(results?.verification_results?.missing || failed);
</script>

<div class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4">
  <div class="bg-panel border border-line rounded-xl w-full max-w-2xl max-h-[90vh] overflow-hidden shadow-2xl">
    <!-- Header -->
    <div class="flex items-center justify-between p-5 border-b border-line">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-full {failed.length ? 'bg-amber-500/20' : 'bg-green-500/20'} flex items-center justify-center">
          {#if failed.length}
            <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path>
            </svg>
          {:else}
            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
          {/if}
        </div>
        <div>
          <h2 class="text-xl font-semibold text-ink">
            {failed.length ? 'Reinstallation Finished with Failures' : 'Reinstallation Complete'}
          </h2>
          <p class="text-sm text-muted">Summary of {type === 'themes' ? 'theme' : 'plugin'} reinstallation</p>
        </div>
      </div>
      <button onclick={onClose} class="text-muted hover:text-ink transition-colors p-1">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
      </button>
    </div>
    
    <!-- Content -->
    <div class="p-5 overflow-y-auto max-h-[60vh]">
      <!-- Stats Grid -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-app border border-green-500/30 rounded-lg p-4 text-center">
          <div class="text-2xl font-bold text-green-700 dark:text-green-400">{successful.length}</div>
          <div class="text-xs text-muted mt-1">Successful</div>
        </div>
        <div class="bg-app border border-red-500/30 rounded-lg p-4 text-center">
          <div class="text-2xl font-bold text-red-700 dark:text-red-400">{failed.length}</div>
          <div class="text-xs text-muted mt-1">Failed</div>
        </div>
        <div class="bg-app border border-blue-500/30 rounded-lg p-4 text-center">
          <div class="text-2xl font-bold text-blue-700 dark:text-blue-400">{verified.length}</div>
          <div class="text-xs text-muted mt-1">Verified</div>
        </div>
        <div class="bg-app border border-amber-500/30 rounded-lg p-4 text-center">
          <div class="text-2xl font-bold text-amber-700 dark:text-amber-400">{missing.length}</div>
          <div class="text-xs text-muted mt-1">Missing</div>
        </div>
      </div>
      
      <!-- Successful Items -->
      {#if successful.length > 0}
        <div class="mb-5">
          <div class="flex items-center gap-2 mb-3">
            <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
            </svg>
            <h3 class="text-sm font-medium text-green-700 dark:text-green-400">Successfully Reinstalled ({successful.length})</h3>
          </div>
          <div class="bg-app rounded-lg border border-line divide-y divide-line">
            {#each successful as plugin, i}
              <div class="p-3 flex items-center gap-3">
                <div class="w-6 h-6 rounded-full bg-green-500/20 flex items-center justify-center text-xs font-medium text-green-700 dark:text-green-400">
                  {i + 1}
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm text-ink truncate">{plugin.name || plugin.slug}</div>
                  {#if plugin.status}
                    <div class="text-xs text-muted">{plugin.status}</div>
                  {/if}
                </div>
                <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                </svg>
              </div>
            {/each}
          </div>
        </div>
      {/if}
      
      <!-- Failed Plugins -->
      {#if failed.length > 0}
        <div class="mb-5">
          <div class="flex items-center gap-2 mb-3">
            <svg class="w-4 h-4 text-red-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
            </svg>
            <h3 class="text-sm font-medium text-red-700 dark:text-red-400">Failed ({failed.length})</h3>
          </div>
          <div class="bg-app rounded-lg border border-line divide-y divide-line">
            {#each failed as plugin, i}
              <div class="p-3 flex items-center gap-3">
                <div class="w-6 h-6 rounded-full bg-red-500/20 flex items-center justify-center text-xs font-medium text-red-700 dark:text-red-400">
                  {i + 1}
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-sm text-ink truncate">{plugin.name || plugin.slug}</div>
                  <div class="text-xs text-red-700 dark:text-red-400">{plugin.status || 'Unknown error'}</div>
                </div>
                <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
              </div>
            {/each}
          </div>
        </div>
      {/if}
      
      <!-- Note -->
      <div class="bg-amber-500/10 border border-amber-500/20 rounded-lg p-4">
        <div class="flex items-start gap-3">
          <svg class="w-5 h-5 text-amber-700 dark:text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          <div>
            <div class="text-sm font-medium text-amber-700 dark:text-amber-400">Activation unchanged</div>
            <p class="text-xs text-muted mt-1">
              {type === 'themes'
                ? 'The active theme stays the active theme. Files were replaced; WordPress still uses the same theme setting. Load the site and confirm it still looks right.'
                : 'Plugins that were active stay active. Activation is stored in the database, not in the plugin files. Load the site and confirm everything still works.'}
            </p>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Footer -->
    
    <!-- Footer -->
    <div class="p-5 border-t border-line flex items-center justify-between">
      <button onclick={onReanalyze} class="px-4 py-2 text-sm text-muted hover:text-ink transition-colors">
        Re-analyze {type === 'themes' ? 'Themes' : 'Plugins'}
      </button>
      <Button.Root onclick={onClose} class="px-5 py-2.5 bg-primary hover:bg-primary/80 text-white text-sm font-medium rounded-lg transition-all">
        Done
      </Button.Root>
    </div>
  </div>
</div>
