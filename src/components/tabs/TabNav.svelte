<script>
  import { app } from '../../lib/stores/app.js';
  
  const tabs = [
    { id: 'core', label: 'Core Files' },
    { id: 'extensions', label: 'Extensions' },
    { id: 'security', label: 'Security' },
    { id: 'upload', label: 'Upload' },
    { id: 'cleanup', label: 'Remove Clean Sweep' }
  ];
  
  function handleTabClick(tabId) {
    app.setActiveTab(tabId);
  }
</script>

<nav class="flex gap-1 mb-6 -mx-5 px-5 border-b border-line" role="tablist">
  {#each tabs as tab}
    <button
      onclick={() => handleTabClick(tab.id)}
      role="tab"
      aria-selected={$app.activeTab === tab.id}
      aria-controls="{tab.id}-panel"
      class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors -mb-px"
      class:text-ink={$app.activeTab === tab.id}
      class:border-primary={$app.activeTab === tab.id}
      class:text-muted={$app.activeTab !== tab.id}
      class:border-transparent={$app.activeTab !== tab.id}
      class:hover:text-ink={$app.activeTab !== tab.id}
    >
      {tab.label}
    </button>
  {/each}
</nav>
