<script>
  import { toolkit } from '../../lib/stores/toolkit.js';
  import { integrity } from '../../lib/stores/integrity.js';
  import { Button } from 'bits-ui';

  let kind = $derived($toolkit.kind);
  let extras = $derived($toolkit.extras || []);
  let patched = $derived($toolkit.patched || []);
  let show = $derived(kind === 'extra' || kind === 'patched' || kind === 'no_manifest');
  let finding = $state(false);
  let hits = $state(null);
  let findError = $state('');

  async function findElsewhere() {
    const first = extras[0];
    if (!first?.path) return;
    finding = true;
    findError = '';
    hits = null;
    try {
      const base = first.path.split('/').pop();
      hits = await integrity.findElsewhere(base, first.hash || '');
    } catch (e) {
      findError = e.message || 'Find failed';
    }
    finding = false;
  }
</script>

{#if show}
  <div class="fixed inset-0 z-[80] bg-black/50 flex items-center justify-center p-4">
    <div class="max-w-lg w-full bg-panel border border-red-500/40 rounded-xl p-5 shadow-xl">
      {#if kind === 'patched'}
        <h2 class="text-base font-semibold text-ink mb-2">Clean Sweep files were modified</h2>
        <p class="text-sm text-muted mb-3">
          A shipped Clean Sweep file no longer matches what was uploaded. Results and snapshots from this session are untrusted.
          Delete the <code class="text-xs">clean-sweep/</code> folder and upload the original zip.
        </p>
        <ul class="text-xs font-mono text-ink mb-4 max-h-32 overflow-y-auto space-y-1">
          {#each patched as p}
            <li>{p.path}</li>
          {/each}
        </ul>
      {:else if kind === 'extra'}
        <h2 class="text-base font-semibold text-ink mb-2">Unexpected file inside Clean Sweep</h2>
        <p class="text-sm text-muted mb-3">
          Something wrote into the Clean Sweep folder (often the same dropper that hits every directory).
          Reinstall and export are paused. Use this file as a fingerprint, then re-upload Clean Sweep.
        </p>
        <ul class="text-xs font-mono text-ink mb-4 max-h-32 overflow-y-auto space-y-1">
          {#each extras as e}
            <li>{e.path}</li>
          {/each}
        </ul>
        <Button.Root
          onclick={findElsewhere}
          disabled={finding}
          class="mb-3 px-3 py-1.5 text-xs bg-violet-500 text-white rounded-md cursor-pointer disabled:opacity-50"
        >
          {finding ? 'Searching…' : 'Find where else this file appears'}
        </Button.Root>
        {#if findError}
          <p class="text-xs text-red-600 mb-2">{findError}</p>
        {/if}
        {#if hits}
          <p class="text-[11px] text-muted mb-1">{hits.count || 0} match(es) on the site</p>
          <ul class="text-[11px] font-mono text-ink mb-3 max-h-28 overflow-y-auto space-y-0.5">
            {#each hits.hits || [] as h}
              <li>{h.path}</li>
            {/each}
          </ul>
        {/if}
      {:else}
        <h2 class="text-base font-semibold text-ink mb-2">Clean Sweep file list missing</h2>
        <p class="text-sm text-muted mb-4">
          Self-check could not load the shipped file list. Extra files in this folder will not be detected until the manifest is present.
        </p>
      {/if}
      <div class="flex justify-end gap-2">
        <Button.Root
          onclick={() => toolkit.dismiss()}
          class="px-3 py-1.5 text-xs text-muted hover:text-ink rounded-md cursor-pointer"
        >
          Dismiss
        </Button.Root>
      </div>
    </div>
  </div>
{/if}
