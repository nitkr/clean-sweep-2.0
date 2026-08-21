<script>
  // Right panel with Events, Info, and AI Assistant accordions
  import { app } from '../../lib/stores/app.js';
  import { events } from '../../lib/stores/events.js';
  import { Accordion } from 'bits-ui';
  
  let openValue = $state('events');
  
  // Helper to format timestamp
  function formatTime(isoString) {
    const date = new Date(isoString);
    return date.toLocaleTimeString('en-US', { 
      hour: '2-digit', 
      minute: '2-digit',
      second: '2-digit',
      hour12: false 
    });
  }
  
  // Helper to get icon for event status
  function getStatusIcon(status) {
    switch(status) {
      case 'success': return '✓';
      case 'error': return '✗';
      case 'warning': return '⚠';
      default: return '●';
    }
  }
  
  // Helper to get color class for status
  function getStatusColor(status) {
    switch(status) {
      case 'success': return 'text-green-700 dark:text-green-400';
      case 'error': return 'text-red-700 dark:text-red-400';
      case 'warning': return 'text-yellow-700 dark:text-yellow-400';
      default: return 'text-blue-700 dark:text-blue-400';
    }
  }
  
  // Helper to get action display name
  function getActionLabel(action) {
    const labels = {
      'analyze_start': 'ANALYZING',
      'analyze_complete': 'ANALYZED',
      'analyze_error': 'ERROR',
      'reinstall_start': 'REINSTALL',
      'reinstall_progress': 'REINSTALL',
      'reinstall_complete': 'DONE',
      'reinstall_error': 'ERROR',
      'backup_start': 'BACKUP',
      'backup_complete': 'BACKUP',
      'backup_skip': 'SKIPPED',
      'selection_change': 'SELECT'
    };
    return labels[action] || action.toUpperCase().slice(0, 8);
  }
  
  // Get category color
  function getCategoryColor(category) {
    switch(category) {
      case 'PLUGINS': return 'text-purple-700 dark:text-purple-400';
      case 'THEMES': return 'text-cyan-700 dark:text-cyan-400';
      case 'CORE': return 'text-amber-700 dark:text-amber-400';
      default: return 'text-muted';
    }
  }
</script>

<Accordion.Root type="single" bind:value={openValue} class="w-full">
  <!-- Events Accordion -->
  <Accordion.Item value="events" class="border-b border-sidebar-border">
    <Accordion.Header>
      <Accordion.Trigger 
        class="w-full h-8 flex items-center justify-between px-3 text-xs font-medium text-muted hover:text-ink hover:bg-panel-hover"
      >
        <span class="flex items-center gap-1.5">
          <svg 
            class="w-3 h-3 transform transition-transform duration-200" 
            class:rotate-180={openValue === 'events'}
            fill="currentColor" 
            viewBox="0 0 20 20"
          >
            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
          </svg>
          Events
          {#if $events.length > 0}
            <span class="ml-1 px-1.5 py-0.5 bg-primary/20 text-primary rounded text-[10px]">
              {$events.length}
            </span>
          {/if}
        </span>
      </Accordion.Trigger>
    </Accordion.Header>
    
    <Accordion.Content 
      class="overflow-y-auto transition-all duration-300"
      style="max-height: 500px;"
    >
      {#if $events.length === 0}
        <div class="p-3 text-xs text-muted text-center">
          No events yet. Start an operation to see logs.
        </div>
      {:else}
        <div class="p-2 font-mono text-xs space-y-0.5">
          {#each $events as event (event.id)}
            <div class="py-1 min-w-0 {event.status === 'success' ? 'text-faint' : 'text-ink'}">
              <!-- First line: metadata -->
              <div class="flex items-center gap-2">
                <span class="text-faint shrink-0 w-16">{formatTime(event.timestamp)}</span>
                <span class={getCategoryColor(event.category) + ' shrink-0 w-14'}>{event.category}</span>
                <span class={getStatusColor(event.status) + ' shrink-0 w-10 font-medium'}>{getActionLabel(event.action)}</span>
                <span class={getStatusColor(event.status) + ' ml-auto'}>{getStatusIcon(event.status)}</span>
              </div>
              <!-- Second line: message -->
              <div class="text-muted mt-0.5 pl-1">
                {event.message}
              </div>
            </div>
          {/each}
        </div>
      {/if}
    </Accordion.Content>
  </Accordion.Item>

  <!-- Info Accordion -->
  <Accordion.Item value="info" class="border-b border-sidebar-border">
    <Accordion.Header>
      <Accordion.Trigger 
        class="w-full h-8 flex items-center justify-between px-3 text-xs font-medium text-muted hover:text-ink hover:bg-panel-hover"
      >
        <span class="flex items-center gap-1.5">
          <svg 
            class="w-3 h-3 transform transition-transform duration-200" 
            class:rotate-180={openValue === 'info'}
            fill="currentColor" 
            viewBox="0 0 20 20"
          >
            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
          </svg>
          Info
        </span>
      </Accordion.Trigger>
    </Accordion.Header>
    
    <Accordion.Content 
      class="overflow-hidden"
    >
      <div class="p-3 text-xs space-y-2">
        <div class="flex justify-between">
          <span class="text-muted">Status</span>
          <span class="text-green-700 dark:text-green-400 font-medium">Ready</span>
        </div>
        <div class="flex justify-between">
          <span class="text-muted">Last Scan</span>
          <span class="text-ink">-</span>
        </div>
        <div class="flex justify-between">
          <span class="text-muted">Events</span>
          <span class="text-ink">{$events.length} logged</span>
        </div>
      </div>
    </Accordion.Content>
  </Accordion.Item>

  <!-- AI Assistant (Disabled) -->
  <Accordion.Item value="ai" class="opacity-50" disabled>
    <Accordion.Header>
      <Accordion.Trigger 
        class="w-full h-8 flex items-center justify-between px-3 text-xs font-medium text-faint cursor-not-allowed"
        disabled
      >
        <span class="flex items-center gap-1.5">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
          </svg>
          AI Assistant
        </span>
        <span>🔒</span>
      </Accordion.Trigger>
    </Accordion.Header>
  </Accordion.Item>
</Accordion.Root>

<style>
  @keyframes scanPulse {
    0%, 100% { opacity: 0.5; }
    50% { opacity: 1; }
  }
  .scan-dot {
    animation: scanPulse 1.5s ease-in-out infinite;
  }
</style>
