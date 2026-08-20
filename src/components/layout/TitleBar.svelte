<script>
  import { theme } from '../../lib/theme.js';
  import { core } from '../../lib/stores/core.js';

  /** @type {{ activeTabName?: string, subtitle?: string }} */
  let { activeTabName = 'Dashboard', subtitle = '' } = $props();
</script>

<!-- Web-app header: page title + utilities (no OS window chrome) -->
<header
  class="h-10 flex items-center bg-elevated border-b border-line flex-shrink-0 select-none theme-surface"
>
  <!-- Left: page title only (brand lives in sidebar) -->
  <div class="flex items-center h-full px-4 gap-2 min-w-0">
    <span class="text-sm font-semibold text-ink truncate">{activeTabName}</span>
    {#if subtitle}
      <span class="text-faint text-xs hidden sm:inline">·</span>
      <span class="text-xs text-muted truncate hidden sm:inline">{subtitle}</span>
    {/if}
  </div>

  <div class="flex-1"></div>

  <!-- Right: context + theme (real utilities only) -->
  <div class="flex items-center h-full">
    {#if $core.currentVersion}
      <span class="cs-chip mr-2 hidden md:inline-flex">
        WP <strong class="chip-value">{$core.currentVersion}</strong>
      </span>
    {/if}

    <button
      type="button"
      onclick={() => theme.toggle()}
      class="h-full px-3.5 flex items-center justify-center text-muted hover:bg-hover hover:text-ink transition-colors"
      title={$theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
      aria-label={$theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
    >
      {#if $theme === 'dark'}
        <svg class="w-4 h-4 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5"
            d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"
          />
        </svg>
      {:else}
        <svg class="w-4 h-4 text-ink" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5"
            d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"
          />
        </svg>
      {/if}
    </button>
  </div>
</header>
