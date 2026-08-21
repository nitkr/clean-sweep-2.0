<script>
  import { theme } from '../../lib/theme.js';
  import { app } from '../../lib/stores/app.js';

  import TitleBar from './TitleBar.svelte';
  import CenterContent from './CenterContent.svelte';

  // Grouped navigation (matches redesign mock)
  const navGroups = [
    {
      label: 'Overview',
      items: [
        {
          id: 'dashboard',
          label: 'Dashboard',
          icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
        },
      ],
    },
    {
      label: 'Scan & fix',
      items: [
        {
          id: 'scanner',
          label: 'Scanner',
          icon: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
        },
        {
          id: 'security',
          label: 'Security',
          icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
        },
      ],
    },
    {
      label: 'Maintenance',
      items: [
        {
          id: 'core',
          label: 'Core Files',
          icon: 'M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1v-2zM14 13a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z',
        },
        {
          id: 'plugins',
          label: 'Extensions',
          icon: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
        },
        {
          id: 'upload',
          label: 'Upload',
          icon: 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',
        },
      ],
    },
    {
      label: 'Site admin',
      items: [
        {
          id: 'users',
          label: 'Users',
          icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
        },
        {
          id: 'cron',
          label: 'Cron',
          icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        },
      ],
    },
    {
      // Last on purpose: only after the site is recovered
      label: 'When done',
      items: [
        {
          id: 'cleanup',
          label: 'Remove Clean Sweep',
          icon: 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
        },
      ],
    },
  ];

  const allNavItems = navGroups.flatMap((g) => g.items);

  let collapsed = $state(false);

  function navigateTo(tabId) {
    app.setActiveTab(tabId);
  }

  function isActive(tabId) {
    return $app.activeTab === tabId;
  }

  let tabName = $derived(allNavItems.find((n) => n.id === $app.activeTab)?.label || 'Dashboard');

  let tabSubtitle = $derived.by(() => {
    const map = {
      dashboard: 'WordPress cleanup cockpit',
      scanner: 'Malware signatures · files & database',
      security: 'Visit watch & snapshot',
      core: 'Reinstall WordPress core',
      plugins: 'Plugins & themes',
      upload: 'Reinstall plugin/theme ZIPs · extract to a path',
      users: 'Accounts & access',
      cron: 'Scheduled tasks',
      cleanup: 'Delete Clean Sweep from the server',
    };
    return map[$app.activeTab] || '';
  });
</script>

<svelte:window
  onkeydown={(e) => {
    if (e.ctrlKey && e.key === 'b') {
      collapsed = !collapsed;
    }
  }}
/>

<div class="flex h-screen bg-app text-ink font-sans antialiased overflow-hidden theme-surface">
  <!-- Left Navigation Sidebar — brand lives only here -->
  <aside
    class="flex-shrink-0 flex flex-col bg-panel border-r border-line h-full transition-all duration-300 theme-surface {collapsed
      ? 'w-16'
      : 'w-56'}"
  >
    <!-- Logo/Brand -->
    <div class="h-14 flex items-center px-4 border-b border-line {collapsed ? 'justify-center' : ''}">
      {#if !collapsed}
        <div class="flex items-center gap-2.5 min-w-0">
          <div class="w-8 h-8 rounded-lg logo-grad flex items-center justify-center flex-shrink-0">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
              />
            </svg>
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-ink truncate">Clean Sweep</div>
            <div class="text-[10px] text-faint truncate">WordPress cleanup cockpit</div>
          </div>
        </div>
      {:else}
        <div class="w-8 h-8 rounded-lg logo-grad flex items-center justify-center">
          <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
            />
          </svg>
        </div>
      {/if}
    </div>

    <!-- Navigation -->
    <nav class="flex-1 py-2 px-2 overflow-y-auto scroll-thin">
      {#each navGroups as group}
        {#if !collapsed}
          <div
            class="px-3 pt-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-faint first:pt-1"
          >
            {group.label}
          </div>
        {:else}
          <div class="h-2 first:h-0"></div>
        {/if}

        <div class="space-y-0.5">
          {#each group.items as item}
            <button
              type="button"
              onclick={() => navigateTo(item.id)}
              class="w-full flex items-center gap-3 h-11 rounded-lg transition-all duration-200 group relative
                {collapsed ? 'justify-center px-0' : 'px-3'}
                {isActive(item.id)
                  ? 'bg-primary/10 text-ink'
                  : 'text-muted hover:bg-hover hover:text-ink'}"
              title={item.label}
            >
              {#if isActive(item.id) && !collapsed}
                <div
                  class="absolute left-0 top-1/2 -translate-y-1/2 w-[3px] h-6 bg-primary rounded-r"
                ></div>
              {/if}

              <svg
                class="w-5 h-5 flex-shrink-0 {isActive(item.id) ? 'text-primary' : ''}"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" d={item.icon} />
              </svg>

              {#if !collapsed}
                <span class="text-sm font-medium truncate">{item.label}</span>
                {#if isActive(item.id)}
                  <svg
                    class="w-4 h-4 ml-auto text-primary"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                  </svg>
                {/if}
              {/if}
            </button>
          {/each}
        </div>
      {/each}
    </nav>

    <!-- Collapse -->
    <div class="py-3 px-2 border-t border-line">
      <button
        type="button"
        onclick={() => (collapsed = !collapsed)}
        class="w-full flex items-center gap-3 h-11 rounded-lg text-muted hover:bg-hover hover:text-ink transition-all
          {collapsed ? 'justify-center px-0' : 'px-3'}"
        title={collapsed ? 'Expand sidebar' : 'Collapse sidebar (Ctrl+B)'}
      >
        <svg
          class="w-5 h-5 flex-shrink-0 transition-transform duration-300 {collapsed
            ? 'rotate-180'
            : ''}"
          fill="none"
          stroke="currentColor"
          stroke-width="1.5"
          viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
        </svg>
        {#if !collapsed}
          <span class="text-sm">Collapse</span>
        {/if}
      </button>
    </div>
  </aside>

  <!-- Main Content Area -->
  <div class="flex-1 flex flex-col min-w-0 h-full">
    <TitleBar activeTabName={tabName} subtitle={tabSubtitle} />

    <main class="flex-1 overflow-hidden bg-app theme-surface">
      <CenterContent />
    </main>

    <footer
      class="h-6 flex items-center px-3 bg-elevated border-t border-line text-[11px] text-muted select-none flex-shrink-0 theme-surface"
    >
      <div class="flex items-center gap-4">
        <span class="flex items-center gap-1.5">
          <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
          Ready
        </span>
      </div>
      <div class="flex-1"></div>
      <div class="flex items-center gap-4">
        <span class="text-faint hidden sm:inline">
          Theme: {$theme === 'dark' ? 'Dark' : 'Light'}
        </span>
        <span>Clean Sweep v2.0</span>
      </div>
    </footer>
  </div>
</div>
