<script>
  export let percent = 0;
  export let message = '';
  export let status = 'running'; // idle, running, complete, error
  export let isContinuation = false; // Phase 2.1 - scan is a continuation after pause
</script>

<div class="w-full">
  {#if message}
    <div class="flex items-center justify-between mb-2">
      <span class="text-sm text-muted flex items-center gap-1.5">
        {message}
        {#if isContinuation}
          <span class="text-amber-700 dark:text-amber-400" title="Continuing a paused scan">↻</span>
        {/if}
      </span>
      <span class="text-sm font-mono text-primary">{percent}%</span>
    </div>
  {/if}

  <div class="h-1.5 bg-elevated rounded-full overflow-hidden">
    <div
      class="h-full transition-all duration-500"
      style="width: {percent}%; background: linear-gradient(90deg, {isContinuation ? '#f59e0b' : 'var(--primary)'}, {isContinuation ? '#f59e0b' : 'var(--primary)'});"
    ></div>
  </div>
</div>
