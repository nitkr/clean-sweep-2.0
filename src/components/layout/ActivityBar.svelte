<script>
  import { app } from '../../lib/stores/app.js';

  // Activity bar items with better icons
  const activityItems = [
    {
      id: 'explorer',
      label: 'Explorer',
      icon: 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z'
    },
    {
      id: 'core',
      label: 'Core Files',
      icon: 'M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1v-2zM14 13a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z'
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
      icon: 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4'
    }
  ];

  let activeActivity = $state('explorer');

  // Map IDE nav IDs to internal app tab IDs
  const tabMapping = {
    'core': 'core',
    'extensions': 'plugins',
    'scanner': 'scanner',
    'security': 'security',
    'upload': 'upload',
    'cleanup': 'cleanup'
  };

  function handleActivityClick(activityId) {
    activeActivity = activityId;

    // If clicking a tool tab, switch the app tab
    if (tabMapping[activityId]) {
      app.setActiveTab(tabMapping[activityId]);
    }
  }

  function isActive(itemId) {
    return activeActivity === itemId;
  }
</script>

<!-- Activity Bar - Modern VS Code/Cursor style -->
<aside class="w-11 flex-shrink-0 flex flex-col bg-panel border-r border-line h-full">
  <!-- Activity Icons -->
  <div class="flex flex-col items-center py-3 gap-1">
    {#each activityItems as item}
      <button
        onclick={() => handleActivityClick(item.id)}
        class="relative w-10 h-10 flex items-center justify-center rounded-md transition-all duration-150 group
          {isActive(item.id)
            ? 'text-ink bg-hover'
            : 'text-muted hover:text-ink hover:bg-hover'}"
        title={item.label}
      >
        <!-- Active indicator bar -->
        {#if isActive(item.id)}
          <div class="absolute left-0 top-1/2 -translate-y-1/2 w-[3px] h-5 bg-primary rounded-r"></div>
        {/if}

        <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d={item.icon}/>
        </svg>

        <!-- Tooltip on hover -->
        <div class="absolute left-full ml-2 px-2 py-1 bg-hover text-xs text-ink rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-150 whitespace-nowrap z-50 border border-line">
          {item.label}
        </div>
      </button>
    {/each}
  </div>

  <!-- Spacer -->
  <div class="flex-1"></div>

  <!-- Bottom items -->
  <div class="flex flex-col items-center py-3 gap-1 border-t border-line">
    <!-- Extensions/ marketplace -->
    <button
      class="w-10 h-10 flex items-center justify-center rounded-md text-muted hover:text-ink hover:bg-hover transition-all duration-150 group"
      title="Extensions"
    >
      <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V8a1 1 0 011-1h3a1 1 0 001-1V4z"/>
      </svg>
      <div class="absolute left-full ml-2 px-2 py-1 bg-hover text-xs text-ink rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-150 whitespace-nowrap z-50 border border-line">
        Extensions
      </div>
    </button>

    <!-- Settings -->
    <button
      class="w-10 h-10 flex items-center justify-center rounded-md text-muted hover:text-ink hover:bg-hover transition-all duration-150 group"
      title="Settings"
    >
      <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      <div class="absolute left-full ml-2 px-2 py-1 bg-hover text-xs text-ink rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-150 whitespace-nowrap z-50 border border-line">
        Settings
      </div>
    </button>

    <!-- Account -->
    <button
      class="w-10 h-10 flex items-center justify-center rounded-md text-muted hover:text-ink hover:bg-hover transition-all duration-150 group"
      title="Account"
    >
      <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
      </svg>
      <div class="absolute left-full ml-2 px-2 py-1 bg-hover text-xs text-ink rounded opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-150 whitespace-nowrap z-50 border border-line">
        Account
      </div>
    </button>
  </div>
</aside>