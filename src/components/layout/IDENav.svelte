<script>
  import { app } from '../../lib/stores/app.js';
  
  const tabs = [
    { 
      id: 'core', 
      label: 'Core', 
      icon: 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'
    },
    { 
      id: 'extensions', 
      label: 'Extensions', 
      icon: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'
    },
    { 
      id: 'scanner', 
      label: 'Scanner', 
      icon: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'
    },
    { 
      id: 'security', 
      label: 'Security', 
      icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'
    },
    { 
      id: 'upload', 
      label: 'Upload', 
      icon: 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12'
    },
    { 
      id: 'cleanup', 
      label: 'Remove Clean Sweep', 
      icon: 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16'
    }
  ];
  
  function handleTabClick(tabId) {
    // Map IDE nav IDs to internal app tab IDs
    const tabMapping = {
      'core': 'core',
      'extensions': 'plugins',
      'scanner': 'scanner',
      'security': 'security',
      'upload': 'upload',
      'cleanup': 'cleanup'
    };
    app.setActiveTab(tabMapping[tabId] || tabId);
  }
  
  function getIdeTab(appTab) {
    const mapping = {
      'core': 'core',
      'plugins': 'extensions',
      'scanner': 'scanner',
      'security': 'security',
      'upload': 'upload',
      'cleanup': 'cleanup'
    };
    return mapping[appTab] || appTab;
  }
  
  $: currentIdeTab = getIdeTab($app.activeTab);
  
  function getTabClass(tabId) {
    const isActive = currentIdeTab === tabId;
    return isActive 
      ? 'text-accent bg-accent/10 border-l-2 border-accent' 
      : 'text-muted hover:text-ink hover:bg-panel-hover';
  }
</script>

{#each tabs as tab}
  <button 
    on:click={() => handleTabClick(tab.id)}
    class="w-full h-9 flex items-center gap-2 px-3 text-sm font-medium rounded-md transition-colors {getTabClass(tab.id)}"
  >
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d={tab.icon}/>
    </svg>
    {tab.label}
  </button>
{/each}
