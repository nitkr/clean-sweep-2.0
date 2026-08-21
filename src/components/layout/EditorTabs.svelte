<script>
  import { app } from '../../lib/stores/app.js';
  import { files } from '../../lib/stores/files.js';
  import { scanning } from '../../lib/stores/scanning.js';

  // Open tabs state
  let openTabs = $state([
    { id: 'welcome', label: 'Welcome', type: 'tab' }
  ]);

  let activeTabId = $state('welcome');

  // Add tab when file/threat is selected
  $effect(() => {
    // If a file is selected, add it as a tab
    if ($files.selectedFile) {
      const existingTab = openTabs.find(t => t.id === $files.selectedFile.path);
      if (!existingTab) {
        openTabs = [...openTabs, {
          id: $files.selectedFile.path,
          label: $files.selectedFile.name,
          type: 'file'
        }];
      }
      activeTabId = $files.selectedFile.path;
    }
  });

  $effect(() => {
    // If a threat is selected from scanner
    if ($scanning.selectedThreat) {
      const existingTab = openTabs.find(t => t.id === 'scanner-' + $scanning.selectedThreat.file);
      if (!existingTab) {
        openTabs = [...openTabs, {
          id: 'scanner-' + $scanning.selectedThreat.file,
          label: $scanning.selectedThreat.file,
          type: 'threat'
        }];
      }
      activeTabId = 'scanner-' + $scanning.selectedThreat.file;
    }
  });

  function selectTab(tabId) {
    activeTabId = tabId;

    // If it's a file tab, trigger selection in store
    const tab = openTabs.find(t => t.id === tabId);
    if (tab?.type === 'file') {
      files.selectFile({ name: tab.label, path: tab.id, type: 'file' });
    }
  }

  function closeTab(tabId, event) {
    event.stopPropagation();

    // Don't close if it's the last tab
    if (openTabs.length <= 1) return;

    // Remove tab from openTabs
    openTabs = openTabs.filter(t => t.id !== tabId);

    // If closing active tab, switch to another
    if (activeTabId === tabId) {
      activeTabId = openTabs[openTabs.length - 1].id;
    }
  }

  function getTabClass(tabId) {
    if (tabId === activeTabId) {
      return 'bg-app text-ink border-t-2 border-t-primary';
    }
    return 'text-muted hover:bg-elevated hover:text-ink';
  }
</script>

<!-- Editor Tab Bar - VS Code style tabs at top of center content -->
<div class="h-9 flex items-center bg-elevated border-b border-line overflow-x-auto">
  <!-- Tabs -->
  <div class="flex items-center h-full">
    {#each openTabs as tab}
      <button
        onclick={() => selectTab(tab.id)}
        class="h-full px-4 text-xs font-medium flex items-center gap-2 border-r border-line transition-colors {getTabClass(tab.id)}"
      >
        <!-- File type icon -->
        {#if tab.label.endsWith('.php')}
          <span class="text-orange-700 dark:text-orange-400">PHP</span>
        {:else if tab.label.endsWith('.js')}
          <span class="text-yellow-700 dark:text-yellow-400">JS</span>
        {:else if tab.label.endsWith('.json')}
          <span class="text-cyan-700 dark:text-cyan-400">JSON</span>
        {:else if tab.label.endsWith('.css')}
          <span class="text-blue-700 dark:text-blue-400">CSS</span>
        {:else}
          <svg class="w-3.5 h-3.5 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
        {/if}

        <span class="max-w-[120px] truncate">{tab.label}</span>

        <!-- Close button (don't show on welcome tab) -->
        {#if tab.id !== 'welcome'}
          <span
            onclick={(e) => closeTab(tab.id, e)}
            onkeydown={(e) => e.key === 'Enter' && closeTab(tab.id, e)}
            class="ml-1 w-4 h-4 flex items-center justify-center rounded hover:bg-elevated text-muted hover:text-ink cursor-pointer"
            role="button"
            tabindex="0"
            title="Close tab"
          >
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </span>
        {/if}
      </button>
    {/each}
  </div>

  <!-- Spacer -->
  <div class="flex-1"></div>

  <!-- Tab actions (breadcrumb-style path shown on right) -->
  {#if openTabs.length > 0}
    {@const activeTab = openTabs.find(t => t.id === activeTabId)}
    {#if activeTab?.path}
      <div class="px-3 text-xs text-faint truncate max-w-[200px] hidden md:block">
        {activeTab.path}
      </div>
    {/if}
  {/if}
</div>