<script>
  import { cleanup } from '../../lib/stores/cleanup.js';
  import { integrity } from '../../lib/stores/integrity.js';
  import { onMount } from 'svelte';
  import { Button } from 'bits-ui';

  onMount(() => {
    integrity.loadBaselineInfo();
  });

  // Derived state
  let cleaningUp = $derived($cleanup.cleaningUp);
  let cleanupComplete = $derived($cleanup.cleanupComplete);
  let error = $derived($cleanup.error);
  let confirmOpen = $state(false);
  /** When no snapshot yet, user must opt in to delete without one */
  let skipSnapshotAck = $state(false);

  let visit = $derived($integrity.visitStatus || $integrity.baselineInfo || {});
  // Same gate as cleanup.php: export of this visit, or an explicit skip.
  // Import/compare is last visit's file — it does not pin this visit.
  let snapshotReady = $derived(
    !!(visit.snapshot_downloaded || visit.snapshot_skipped)
  );
  let snapshotCompared = $derived(!!visit.snapshot_imported && !snapshotReady);
  let snapshotLabel = $derived(
    visit.snapshot_downloaded
      ? 'Snapshot downloaded'
      : visit.snapshot_skipped
        ? 'Snapshot skipped'
        : visit.snapshot_imported
          ? 'Compared last snapshot — this visit not exported'
          : 'No snapshot yet'
  );

  function requestRemove() {
    if (cleaningUp || cleanupComplete) return;
    skipSnapshotAck = false;
    confirmOpen = true;
  }

  function cancelRemove() {
    confirmOpen = false;
    skipSnapshotAck = false;
  }

  async function confirmRemove() {
    if (!snapshotReady && !skipSnapshotAck) return;
    confirmOpen = false;
    const skip = !snapshotReady && skipSnapshotAck;
    const ok = await cleanup.startCleanup({ skipSnapshot: skip });
    if (!ok) {
      integrity.loadBaselineInfo();
    }
  }
</script>

{#if cleanupComplete}
  <div class="h-full overflow-y-auto flex items-center justify-center p-6">
    <div class="max-w-lg w-full text-center space-y-4">
      <div class="mx-auto w-14 h-14 rounded-2xl bg-emerald-500/15 border border-emerald-500/25 flex items-center justify-center">
        <svg class="w-7 h-7 text-emerald-700 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
      </div>
      <h1 class="text-2xl font-bold text-ink">Clean Sweep is no longer on this server</h1>
      <p class="text-sm text-ink leading-relaxed">
        The toolkit folder, live-watch agent, and Clean Sweep visit data have been deleted.
        Your WordPress site was not changed.
      </p>
      <p class="text-sm text-muted leading-relaxed">
        Close this browser tab. This page cannot talk to Clean Sweep anymore.
        To use it again later, upload the zip to this site.
      </p>
    </div>
  </div>
{:else if cleaningUp}
  <div class="h-full overflow-y-auto flex items-center justify-center p-6">
    <div class="max-w-md w-full text-center space-y-4">
      <div class="mx-auto w-12 h-12 rounded-2xl bg-red-500/15 border border-red-500/25 flex items-center justify-center">
        <svg class="w-6 h-6 text-red-700 dark:text-red-400 animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
      </div>
      <h1 class="text-xl font-bold text-ink">Removing Clean Sweep</h1>
      <p class="text-sm text-muted">Deleting the toolkit and leftover agents from this server. Stay on this page until it finishes.</p>
    </div>
  </div>
{:else}
<div class="h-full overflow-y-auto">
  <div class="p-6 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
      <div class="flex items-center gap-3 mb-2">
        <div class="w-10 h-10 rounded-xl bg-red-500/10 flex items-center justify-center">
          <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
        </div>
        <div>
          <h1 class="text-xl font-bold text-ink">Remove Clean Sweep</h1>
          <p class="text-sm text-muted">Delete Clean Sweep from the server when recovery is finished, including the live-watch must-use agent, visit data, options, and scan cron leftovers. Download a snapshot of this visit first if you want to compare next time. Comparing an older snapshot is not the same as exporting this one.</p>
        </div>
      </div>
    </div>

    <div class="mb-6 p-4 bg-panel border border-line rounded-xl flex flex-wrap items-center gap-3">
      <span
        class="text-[11px] font-medium px-2 py-1 rounded-full border
          {snapshotReady
            ? 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-300 border-emerald-500/30'
            : 'bg-amber-500/10 text-amber-900 dark:text-amber-200 border-amber-500/30'}"
      >
        {snapshotLabel}
      </span>
      <Button.Root
        onclick={() => integrity.exportSnapshot()}
        class="px-3 py-1.5 text-sm bg-violet-500 text-white rounded-md cursor-pointer"
      >
        Download snapshot
      </Button.Root>
      {#if !snapshotReady}
      <button
        type="button"
        onclick={() => integrity.skipSnapshot()}
        class="text-xs text-muted hover:text-ink"
      >
        Skip. I will not compare later
      </button>
      {/if}
      {#if $integrity.lastSecret}
        <span class="text-[11px] text-amber-700 dark:text-amber-400">Save the secret shown on the Security tab.</span>
      {/if}
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-4 gap-4 mb-6">
      <button
        onclick={requestRemove}
        class="col-span-2 p-4 bg-gradient-to-r from-red-500/10 to-red-600/5 border border-red-500/20 rounded-xl hover:border-red-500/40 transition-all text-left group"
        disabled={cleaningUp}
      >
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-ink mb-1">Remove Clean Sweep</p>
            <p class="text-xs text-muted">Uninstall toolkit + site residuals</p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-red-500/20 flex items-center justify-center group-hover:bg-red-500/30 transition-colors">
            {#if cleaningUp}
              <svg class="w-6 h-6 text-red-700 dark:text-red-400 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            {:else}
              <svg class="w-6 h-6 text-red-700 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
            {/if}
          </div>
        </div>
      </button>

      <div class="p-4 bg-panel border border-line rounded-xl">
        <div class="flex items-center gap-2 mb-2">
          <span class="w-2.5 h-2.5 rounded-full {cleanupComplete ? 'bg-emerald-500' : 'bg-zinc-500'}"></span>
          <span class="text-xs text-muted">Status</span>
        </div>
        <p class="text-xl font-bold text-ink">{cleanupComplete ? 'Done' : 'Ready'}</p>
      </div>

      <div class="p-4 bg-panel border border-line rounded-xl">
        <div class="flex items-center gap-2 mb-2">
          <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
          <span class="text-xs text-muted">Irreversible</span>
        </div>
        <p class="text-xl font-bold text-ink">Yes</p>
      </div>
    </div>

    <!-- Cleanup Section Card -->
    <div class="bg-panel border border-line rounded-xl overflow-hidden mb-6">
      <div class="px-5 py-4 border-b border-line">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg bg-red-500/10 flex items-center justify-center">
            <svg class="w-4 h-4 text-red-700 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
          </div>
          <h2 class="text-sm font-semibold text-ink">Remove Clean Sweep</h2>
        </div>
      </div>

      <div class="p-5">
        <!-- Warning Boxes -->
        <div class="space-y-2.5 mb-5">
          <div class="p-3 bg-amber-500/10 border border-amber-500/20 rounded-lg">
            <div class="flex items-center gap-2 text-amber-800 dark:text-amber-400 text-sm">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
              </svg>
              <span>Your WordPress site will remain intact</span>
            </div>
          </div>
          <div class="p-3 bg-red-500/10 border border-red-500/20 rounded-lg">
            <div class="flex items-center gap-2 text-red-700 dark:text-red-400 text-sm">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <span>All Clean Sweep data will be permanently deleted</span>
            </div>
          </div>
        </div>

        <!-- Cleanup Button -->
        <Button.Root
          variant="destructive"
          size="default"
          onclick={requestRemove}
          disabled={cleaningUp}
          class="inline-flex items-center gap-2 px-4 py-2.5
            bg-red-600/90 hover:bg-red-600
            text-white text-sm font-medium
            border border-red-500/50 rounded-md
            shadow-sm hover:shadow-md
            active:scale-[0.98] active:shadow-none
            focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500/50 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0a0a0c]
            disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-red-600/90
            transition-all duration-200 cursor-pointer"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
          <span>Remove Clean Sweep</span>
        </Button.Root>

        <!-- Error -->
        {#if error}
          <div class="mt-4 p-4 bg-red-500/10 border border-red-500/20 rounded-xl">
            <div class="flex items-center gap-3">
              <svg class="w-5 h-5 text-red-700 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <p class="text-red-700 dark:text-red-400 text-sm">{error}</p>
            </div>
          </div>
        {/if}
      </div>
    </div>
  </div>
</div>
{/if}

{#if confirmOpen}
  <div class="fixed inset-0 z-[80] bg-black/50 flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-panel border border-red-500/40 rounded-xl p-5 shadow-xl">
      <h2 class="text-base font-semibold text-ink mb-2">Remove Clean Sweep?</h2>
      <p class="text-sm text-muted mb-3">
        This permanently deletes the Clean Sweep folder from this server. Your WordPress site stays in place. This cannot be undone.
      </p>
      <div class="p-3 bg-red-500/10 border border-red-500/20 rounded-lg mb-4">
        <p class="text-sm text-red-700 dark:text-red-400">All Clean Sweep data will be permanently deleted.</p>
      </div>
      {#if !snapshotReady}
        <div class="p-3 bg-amber-500/10 border border-amber-500/25 rounded-lg mb-4 space-y-2">
          <p class="text-sm text-amber-900 dark:text-amber-200">
            {#if snapshotCompared}
              You compared a previous snapshot. That does not export this visit. Download one above to compare next time, or confirm you will not.
            {:else}
              No snapshot of this visit yet. Download one above if you want to compare next time, or confirm you will not.
            {/if}
          </p>
          <label class="flex items-start gap-2 text-sm text-ink cursor-pointer">
            <input type="checkbox" bind:checked={skipSnapshotAck} class="mt-0.5" />
            <span>Delete without a snapshot. I will not compare this visit later.</span>
          </label>
        </div>
      {:else}
        <p class="text-xs text-muted mb-4">Snapshot status: {snapshotLabel}</p>
      {/if}
      <div class="flex justify-end gap-2">
        <Button.Root
          onclick={cancelRemove}
          class="px-3 py-1.5 text-sm text-muted hover:text-ink rounded-md cursor-pointer"
        >
          Cancel
        </Button.Root>
        <Button.Root
          onclick={confirmRemove}
          disabled={!snapshotReady && !skipSnapshotAck}
          class="px-4 py-1.5 text-sm bg-red-600 hover:bg-red-600 text-white rounded-md cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Delete Clean Sweep
        </Button.Root>
      </div>
    </div>
  </div>
{/if}