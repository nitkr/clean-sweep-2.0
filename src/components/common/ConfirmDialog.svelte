<script>
  /**
   * In-app confirm / alert modal (replaces window.confirm / alert).
   */
  /** @type {boolean} */
  export let open = false;
  /** @type {string} */
  export let title = 'Confirm';
  /** @type {string} */
  export let message = '';
  /** @type {string} */
  export let confirmLabel = 'Confirm';
  /** @type {string} */
  export let cancelLabel = 'Cancel';
  /** @type {'danger' | 'primary' | 'neutral'} */
  export let variant = 'primary';
  /** When true, only one button (alert style) */
  export let alertOnly = false;

  /** @type {(() => void) | undefined} */
  export let onConfirm = undefined;
  /** @type {(() => void) | undefined} */
  export let onCancel = undefined;

  function confirm() {
    const fn = onConfirm;
    open = false;
    fn?.();
  }

  function cancel() {
    const fn = onCancel;
    open = false;
    fn?.();
  }

  function backdrop(e) {
    if (e.target === e.currentTarget) cancel();
  }
</script>

{#if open}
  <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
  <div
    class="fixed inset-0 z-[90] bg-black/50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="cs-confirm-title"
    onkeydown={(e) => e.key === 'Escape' && cancel()}
    onclick={backdrop}
  >
    <div class="max-w-md w-full bg-panel border border-line rounded-xl p-5 shadow-xl">
      <h2 id="cs-confirm-title" class="text-base font-semibold text-ink mb-2">{title}</h2>
      {#if message}
        <p class="text-sm text-muted leading-relaxed mb-4 whitespace-pre-wrap">{message}</p>
      {/if}
      <div class="flex justify-end gap-2 flex-wrap">
        {#if !alertOnly}
          <button
            type="button"
            class="px-3 py-1.5 text-xs font-medium rounded-md border border-line text-muted hover:text-ink hover:bg-hover transition-colors"
            onclick={cancel}
          >
            {cancelLabel}
          </button>
        {/if}
        <button
          type="button"
          class="px-3 py-1.5 text-xs font-medium rounded-md border transition-colors
            {variant === 'danger'
              ? 'bg-red-500/90 hover:bg-red-500 text-white border-red-600/40'
              : variant === 'neutral'
                ? 'bg-elevated hover:bg-hover text-ink border-line'
                : 'bg-violet-500 hover:bg-violet-600 text-white border-violet-600/30'}"
          onclick={confirm}
        >
          {confirmLabel}
        </button>
      </div>
    </div>
  </div>
{/if}
