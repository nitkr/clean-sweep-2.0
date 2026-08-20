<script>
  import FileTree from './FileTree.svelte';

  let { activePanel = $bindable('explorer') } = $props();

  // Section headers for the sidebar
  const sections = [
    { id: 'explorer', label: 'Explorer', icon: 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z' },
    { id: 'openEditors', label: 'Open Editors', icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' }
  ];

  let openFiles = $state([
    { name: 'wp-config.php', path: 'wp-config.php' },
    { name: 'functions.php', path: 'wp-content/themes/twentytwentyfour/functions.php' }
  ]);
</script>

<!-- Sidebar Panel - shows content based on activeActivity -->
<aside class="w-56 flex-shrink-0 flex flex-col bg-app overflow-hidden h-full">
  <!-- Sidebar Tabs -->
  <div class="flex items-center h-9 border-b border-line bg-panel">
    {#each sections as section}
      <button
        onclick={() => activePanel = section.id}
        class="h-full px-4 text-[11px] font-medium uppercase tracking-wide transition-colors border-b-2 -mb-px
          {activePanel === section.id
            ? 'text-ink border-primary'
            : 'text-muted border-transparent hover:text-ink hover:border-zinc-600'}"
      >
        {section.label}
      </button>
    {/each}
  </div>

  <!-- Scrollable Content -->
  <div class="flex-1 overflow-hidden">
    {#if activePanel === 'explorer'}
      <!-- Explorer Section -->
      <div class="h-full">
        <FileTree />
      </div>
    {:else}
      <!-- Open Editors Section -->
      <div class="py-1">
        {#each openFiles as file}
          <button class="w-full h-8 flex items-center gap-2 px-3 text-xs text-muted hover:bg-hover hover:text-ink transition-colors">
            <svg class="w-4 h-4 text-rose-700/70 dark:text-rose-400/70" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <span class="truncate">{file.name}</span>
          </button>
        {/each}

        {#if openFiles.length === 0}
          <div class="px-3 py-4 text-xs text-faint text-center">
            No open editors
          </div>
        {/if}
      </div>
    {/if}
  </div>
</aside>