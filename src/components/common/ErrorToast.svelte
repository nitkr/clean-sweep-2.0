<script>
  import { errors, ErrorLevel } from '../../lib/errors.js';
  import { fly, fade } from 'svelte/transition';

  function dismiss(id) {
    errors.remove(id);
  }

  function getLevelClasses(level) {
    switch (level) {
      case ErrorLevel.CRITICAL:
      case ErrorLevel.ERROR:
        return 'bg-panel border-red-500/50 text-ink';
      case ErrorLevel.WARNING:
        return 'bg-panel border-amber-500/50 text-ink';
      case ErrorLevel.INFO:
        return 'bg-panel border-sky-500/50 text-ink';
      default:
        return 'bg-panel border-line text-ink';
    }
  }

  function iconClass(level) {
    switch (level) {
      case ErrorLevel.CRITICAL:
      case ErrorLevel.ERROR:
        return 'text-red-700 dark:text-red-400';
      case ErrorLevel.WARNING:
        return 'text-amber-800 dark:text-amber-300';
      default:
        return 'text-sky-700 dark:text-sky-300';
    }
  }

  function showCode(code) {
    const c = String(code || '').trim();
    return c !== '' && c !== 'UNKNOWN';
  }
</script>

{#if $errors.length > 0}
  <div class="fixed top-4 right-4 z-50 space-y-2 max-w-md" role="region" aria-label="Notifications">
    {#each $errors as error (error.id)}
      <div
        in:fly={{ x: 100, duration: 300 }}
        out:fade
        class="p-4 rounded-lg shadow-lg border {getLevelClasses(error.level)}"
        role="alert"
      >
        <div class="flex items-start gap-3">
          <span class="mt-0.5 flex-shrink-0 {iconClass(error.level)}" aria-hidden="true">
            {#if error.level === ErrorLevel.CRITICAL || error.level === ErrorLevel.ERROR}
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            {:else if error.level === ErrorLevel.WARNING}
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
              </svg>
            {:else}
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            {/if}
          </span>
          <div class="flex-1 min-w-0">
            <p class="font-medium text-sm leading-snug">{error.message}</p>
            {#if error.details}
              <p class="text-sm text-muted mt-1">{error.details}</p>
            {/if}
            {#if showCode(error.code)}
              <p class="text-[10px] text-faint mt-1.5 font-mono">{error.code}</p>
            {/if}
          </div>
          <button
            type="button"
            onclick={() => dismiss(error.id)}
            class="flex-shrink-0 p-0.5 rounded text-muted hover:text-ink hover:bg-hover transition-colors"
            aria-label="Dismiss"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
      </div>
    {/each}
  </div>
{/if}
