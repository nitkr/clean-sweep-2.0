<script>
  import { errors, ErrorLevel } from '../../lib/errors.js';
  import { fly, fade } from 'svelte/transition';
  
  function dismiss(id) {
    errors.remove(id);
  }
  
  function getLevelClasses(level) {
    switch (level) {
      case ErrorLevel.CRITICAL:
        return 'bg-red-900/90 border-red-500 text-red-100';
      case ErrorLevel.ERROR:
        return 'bg-red-900/90 border-red-600 text-red-100';
      case ErrorLevel.WARNING:
        return 'bg-yellow-900/90 border-yellow-600 text-yellow-100';
      case ErrorLevel.INFO:
        return 'bg-blue-900/90 border-blue-600 text-blue-100';
      default:
        return 'bg-panel border-line text-ink';
    }
  }
</script>

{#if $errors.length > 0}
  <div class="fixed top-4 right-4 z-50 space-y-2 max-w-md">
    {#each $errors as error (error.id)}
      <div 
        in:fly={{ x: 100, duration: 300 }}
        out:fade
        class="p-4 rounded-lg shadow-lg border-l-4 {getLevelClasses(error.level)}"
      >
        <div class="flex items-start gap-3">
          <span class="text-xl">
            {#if error.level === ErrorLevel.CRITICAL || error.level === ErrorLevel.ERROR}
              ❌
            {:else if error.level === ErrorLevel.WARNING}
              ⚠️
            {:else}
              ℹ️
            {/if}
          </span>
          <div class="flex-1">
            <p class="font-medium">{error.message}</p>
            {#if error.details}
              <p class="text-sm opacity-75 mt-1">{error.details}</p>
            {/if}
            <code class="text-xs mt-2 block opacity-50">{error.code}</code>
          </div>
          <button 
            on:click={() => dismiss(error.id)}
            class="text-current opacity-50 hover:opacity-100"
          >✕</button>
        </div>
      </div>
    {/each}
  </div>
{/if}
