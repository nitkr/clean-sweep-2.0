<script>
  import { debug } from '../../lib/debug.js';
  import { errors } from '../../lib/errors.js';
  import { app } from '../../lib/stores/app.js';
  
  let expanded = false;
</script>

{#if $debug}
  <div class="fixed bottom-4 left-4 z-50">
    <button 
      on:click={() => expanded = !expanded}
      class="bg-yellow-600 hover:bg-yellow-500 text-ink px-3 py-1 rounded text-sm"
    >
      🐛 Debug
    </button>
    
    {#if expanded}
      <div class="mt-2 p-4 bg-slate-900 dark:bg-slate-900 border border-yellow-600 rounded-lg w-80 max-h-64 overflow-auto shadow-xl">
        <div class="flex justify-between items-center mb-3 pb-2 border-b border-yellow-600/30">
          <span class="font-bold text-yellow-400">Debug Panel</span>
          <button 
            on:click={() => expanded = false}
            class="text-slate-400 hover:text-ink"
          >✕</button>
        </div>
        
        <div class="space-y-2 text-xs">
          <div class="flex justify-between">
            <span class="text-slate-400">Errors:</span>
            <span class="text-red-700 dark:text-red-400">{$errors.length}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-slate-400">Active Tab:</span>
            <span class="text-cyan-400">{$app.activeTab}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-slate-400">Loading:</span>
            <span class={$app.isLoading ? 'text-yellow-400' : 'text-green-400'}>
              {$app.isLoading ? 'Yes' : 'No'}
            </span>
          </div>
          <div class="flex justify-between">
            <span class="text-slate-400">Progress:</span>
            <span class="text-cyan-400">{$app.progressPercent}%</span>
          </div>
          
          <div class="pt-2 border-t border-slate-700">
            <button 
              on:click={errors.clear}
              class="w-full py-1 bg-slate-800 hover:bg-slate-700 rounded text-slate-300"
            >
              Clear Errors
            </button>
          </div>
        </div>
      </div>
    {/if}
  </div>
{/if}
