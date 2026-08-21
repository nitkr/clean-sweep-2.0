<script>
  import { upload, UPLOAD_DEST_OPTIONS, UPLOAD_PATH_CHIPS, UPLOAD_REINSTALL_CARDS } from '../../lib/stores/upload.js';
  import { Button } from 'bits-ui';
  import { isFeatureEnabled } from '../../config/features.ts';

  let dragOver = $derived($upload.dragOver);
  let uploadQueue = $derived($upload.uploadQueue);
  let uploading = $derived($upload.uploading);
  let installing = $derived($upload.installing);
  let uploadProgress = $derived($upload.uploadProgress);
  let installProgress = $derived($upload.installProgress);
  let installMessage = $derived($upload.installMessage);
  let uploadResult = $derived($upload.uploadResult);
  let destination = $derived($upload.destination);
  let customRel = $derived($upload.customRel);
  let confirmOpen = $derived($upload.confirmOpen);
  let confirmRoot = $derived($upload.confirmRoot);
  let browseOpen = $derived($upload.browseOpen);
  let browsePath = $derived($upload.browsePath);
  let browseEntries = $derived($upload.browseEntries);
  let browseLoading = $derived($upload.browseLoading);
  let busy = $derived(uploading || installing);

  let customPathOn = $derived(isFeatureEnabled('uploadCustomPath'));
  let inspectOn = $derived(isFeatureEnabled('uploadInspect'));
  let backupOn = $derived(isFeatureEnabled('uploadPackageBackup'));
  let extractOpen = $derived($upload.extractOpen);
  let createBackup = $derived($upload.createBackup);
  let mixedBatch = $derived($upload.mixedBatch);
  let batchResult = $derived($upload.batchResult);

  let destLabel = $derived(destination ? upload.destLabel(destination, customRel) : '');
  let destValid = $derived(upload.destIsValid($upload));
  let atRoot = $derived(upload.writesAtRoot($upload));
  let extractMode = $derived(upload.isExtractMode($upload));
  let smartPackage = $derived(inspectOn && upload.isSmartPackageQueue(uploadQueue));
  let mixedSmart = $derived(smartPackage && mixedBatch && !extractMode);
  let isPackage = $derived(upload.isPackageDest(destination) || (smartPackage && !extractMode));
  let kindBanner = $derived(inspectOn ? upload.packageBanner(uploadQueue, extractMode) : '');
  let kindBannerBlocking = $derived(
    !!kindBanner && kindBanner.includes('separately from path extracts')
  );
  let batchSummary = $derived(
    smartPackage ? upload.smartBatchSummary(uploadQueue) : ''
  );
  let canReview = $derived(
    destValid && uploadQueue.length > 0 && !busy && !kindBannerBlocking
  );
  let backupEligible = $derived(backupOn && upload.backupEligibleBatch($upload));
  let backupBlocked = $derived(backupOn ? upload.backupBlockedReason($upload) : '');
  let canConfirm = $derived(
    (isPackage || destValid)
      && !busy
      && !kindBannerBlocking
      && (isPackage || !atRoot || confirmRoot)
  );
  let showSuccessActions = $derived(!!(uploadResult || batchResult));
  let reviewLabel = $derived(
    !destValid
      ? 'Choose a destination'
      : (isPackage ? 'Review & reinstall' : 'Review & extract')
  );
  let confirmCta = $derived(
    mixedSmart
      ? 'Reinstall mixed batch'
      : (isPackage ? `Reinstall to ${destLabel}` : `Extract to ${destLabel}`)
  );
  let pathPlaceholder = $derived(
    destination === 'root'
      ? 'WordPress root'
      : destination === 'uploads'
        ? 'Media / uploads'
        : destination === 'wp-content'
          ? 'wp-content'
          : 'Site-relative path, e.g. wp-content/uploads/restore'
  );

  function itemName(item) {
    return item?.file?.name || item?.name || '';
  }

  function itemSize(item) {
    return item?.file?.size ?? item?.size ?? 0;
  }

  function kindLabel(kind) {
    if (kind === 'plugin') return 'Plugin';
    if (kind === 'theme') return 'Theme';
    if (kind === 'unknown') return 'Files';
    return 'Pending';
  }

  function parentBrowsePath(path) {
    const clean = String(path || '').replace(/\/+$/, '');
    if (!clean) return '';
    const i = clean.lastIndexOf('/');
    return i === -1 ? '' : clean.slice(0, i);
  }
</script>

<div class="h-full overflow-y-auto">
  <div class="p-6 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
      <div class="flex items-center gap-3 mb-2">
        <div class="w-10 h-10 rounded-xl bg-orange-500/10 flex items-center justify-center">
          <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
          </svg>
        </div>
        <div>
          <h1 class="text-xl font-bold text-ink">Upload</h1>
          <p class="text-sm text-muted">
            Reinstall a premium plugin or theme ZIP (Extensions cannot fetch these), or extract files to a chosen path.
          </p>
        </div>
      </div>
    </div>

    <div class="mb-6">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-ink">Destination</h2>
        <p class="text-xs text-muted">ZIPs are staged first; nothing is written until you confirm.</p>
      </div>
      <div class="grid grid-cols-2 gap-3 mb-3">
        {#each UPLOAD_REINSTALL_CARDS as card}
          <button
            type="button"
            onclick={() => upload.setDestination(card.id)}
            disabled={busy}
            class="p-4 rounded-xl border text-left transition-all {destination === card.id && !extractOpen
              ? 'border-primary bg-primary/10'
              : 'border-line bg-panel hover:border-primary/40'}"
          >
            <p class="text-sm font-medium text-ink">{card.label}</p>
            <p class="text-xs text-muted mt-1">{card.hint}</p>
          </button>
        {/each}
      </div>

      {#if customPathOn}
        <button
          type="button"
          onclick={() => upload.openExtractPath()}
          disabled={busy}
          class="w-full p-4 rounded-xl border text-left transition-all {extractOpen
            ? 'border-primary bg-primary/10'
            : 'border-line bg-panel hover:border-primary/40'}"
        >
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-ink">Extract files to a path</p>
              <p class="text-xs text-muted mt-1">Media, wp-content, WordPress root, or a custom site path.</p>
            </div>
            <svg class="w-4 h-4 text-muted {extractOpen ? 'rotate-180' : ''}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
          </div>
        </button>

        {#if extractOpen}
          <div class="mt-3 p-4 border border-line rounded-xl bg-panel">
            <div class="flex flex-wrap gap-2 mb-3">
              {#each UPLOAD_PATH_CHIPS as chip}
                <button
                  type="button"
                  onclick={() => upload.setDestination(chip.id)}
                  disabled={busy}
                  class="px-3 py-1.5 rounded-lg border text-sm transition-all {destination === chip.id
                    ? 'border-primary bg-primary/10 text-ink'
                    : 'border-line bg-app text-muted hover:border-primary/40 hover:text-ink'}"
                >
                  {chip.label}
                </button>
              {/each}
            </div>

            <div class="flex gap-2">
              <input
                type="text"
                value={destination === 'custom' ? customRel : ''}
                oninput={(e) => upload.setCustomRel(e.currentTarget.value)}
                placeholder={pathPlaceholder}
                disabled={busy}
                class="flex-1 px-3 py-2 text-sm bg-app border border-line rounded-lg text-ink placeholder:text-faint focus:outline-none focus:ring-2 focus:ring-primary/40"
              />
              <button
                type="button"
                onclick={() => upload.openBrowse(destination === 'custom' ? customRel : '')}
                disabled={busy}
                class="px-3 py-2 text-sm font-medium bg-elevated hover:bg-elevated/80 border border-line rounded-lg text-ink disabled:opacity-50"
              >
                Browse
              </button>
            </div>
            {#if atRoot}
              <p class="text-xs text-amber-700 dark:text-amber-400 mt-2">WordPress root writes files at the site root.</p>
            {/if}
          </div>
        {/if}
      {:else}
        <div class="grid grid-cols-2 gap-3">
          {#each UPLOAD_DEST_OPTIONS as opt}
            <button
              type="button"
              onclick={() => upload.setDestination(opt.id)}
              disabled={busy}
              class="p-4 rounded-xl border text-left transition-all {destination === opt.id
                ? 'border-primary bg-primary/10'
                : 'border-line bg-panel hover:border-primary/40'}"
            >
              <p class="text-sm font-medium text-ink">{opt.label}</p>
              <p class="text-xs text-muted mt-1">{opt.hint}</p>
            </button>
          {/each}
        </div>
      {/if}
    </div>

    {#if kindBanner}
      <div class="mb-6 p-3 rounded-xl border {kindBannerBlocking
        ? 'bg-amber-500/10 border-amber-500/20'
        : 'bg-sky-500/10 border-sky-500/20'}">
        <p class="text-sm {kindBannerBlocking
          ? 'text-amber-800 dark:text-amber-300'
          : 'text-sky-800 dark:text-sky-300'}">{kindBanner}</p>
      </div>
    {/if}

    <!-- Stats Cards -->
    <div class="grid grid-cols-4 gap-4 mb-6">
      <button
        onclick={() => document.querySelector('input[type=file]')?.click()}
        class="col-span-2 p-4 bg-gradient-to-r from-orange-500/10 to-orange-600/5 border border-orange-500/20 rounded-xl hover:border-orange-500/40 transition-all text-left group"
        disabled={busy}
      >
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-ink mb-1">Upload Files</p>
            <p class="text-xs text-muted">Click to browse or drag and drop</p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-orange-500/20 flex items-center justify-center group-hover:bg-orange-500/30 transition-colors">
            {#if busy}
              <svg class="w-6 h-6 text-orange-700 dark:text-orange-400 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            {:else}
              <svg class="w-6 h-6 text-orange-700 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
              </svg>
            {/if}
          </div>
        </div>
      </button>

      <div class="p-4 bg-panel border border-line rounded-xl">
        <div class="flex items-center gap-2 mb-2">
          <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
          <span class="text-xs text-muted">Queued</span>
        </div>
        <p class="text-xl font-bold text-ink">{uploadQueue.length}</p>
      </div>

      <div class="p-4 bg-panel border border-line rounded-xl">
        <div class="flex items-center justify-between mb-2">
          <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
          <span class="text-xs text-muted">Status</span>
        </div>
        <p class="text-xl font-bold text-ink">{busy ? 'Active' : 'Ready'}</p>
      </div>
    </div>

    <!-- Upload Area Card -->
    <div class="bg-panel border border-line rounded-xl overflow-hidden mb-6">
      <div class="px-5 py-4 border-b border-line">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg bg-orange-500/10 flex items-center justify-center">
            <svg class="w-4 h-4 text-orange-700 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
            </svg>
          </div>
          <h2 class="text-sm font-semibold text-ink">Drop Zone</h2>
        </div>
      </div>

      <div class="p-5">
        <div
          class="border-2 border-dashed rounded-xl p-8 text-center transition-colors {dragOver && !busy ? 'border-primary bg-primary/10' : 'border-line bg-app/50'} {busy ? 'opacity-60 pointer-events-none' : ''}"
          ondragover={(e) => { e.preventDefault(); if (!busy) upload.setDragOver(true); }}
          ondragleave={() => upload.setDragOver(false)}
          ondrop={(e) => { e.preventDefault(); if (!busy) upload.handleFileDrop(e); }}
          role="region"
          aria-label="File drop zone"
        >
          <svg class="w-10 h-10 mx-auto text-faint mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
          </svg>
          <p class="text-muted mb-2">
            Drag and drop ZIP files here, or
          </p>
          <label class="inline-block">
            <Button.Root
              variant="secondary"
              size="sm"
              disabled={busy}
              onclick={(e) => {
                if (busy) return;
                e.currentTarget.parentElement?.querySelector('input')?.click();
              }}
              class="inline-flex items-center gap-2 px-3 py-1.5
            bg-elevated hover:bg-zinc-600
            text-ink text-sm font-medium
            border border-zinc-600 rounded-md
            shadow-sm hover:shadow-md
            active:scale-[0.98] active:shadow-none
            focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-500/50 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0a0a0c]
            transition-all duration-200 cursor-pointer"
            >
              Browse Files
            </Button.Root>
            <input
              type="file"
              accept=".zip"
              multiple
              class="hidden"
              disabled={busy}
              onchange={(e) => upload.handleFileSelect(e)}
            >
          </label>
          <p class="text-xs text-faint mt-2">Only .zip files are accepted</p>
        </div>

        {#if uploadQueue.length > 0}
          <div class="mt-5">
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-sm font-medium text-ink">
                Files to upload ({uploadQueue.length})
              </h3>
              <button
                onclick={() => upload.clearQueue()}
                disabled={busy}
                class="text-xs text-muted hover:text-ink disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Clear All
              </button>
            </div>

            <div class="space-y-2">
              {#each uploadQueue as item, index}
                <div class="flex items-center justify-between p-3 bg-app rounded-lg border border-line">
                  <div class="min-w-0">
                    <div class="flex items-center gap-3">
                      <svg class="w-5 h-5 text-primary shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                      </svg>
                      <span class="text-sm text-ink truncate">{itemName(item)}</span>
                      <span class="text-xs text-faint">({upload.formatFileSize(itemSize(item))})</span>
                      {#if inspectOn}
                        <span class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-elevated text-muted">
                          {kindLabel(item.inspect?.kind)}
                        </span>
                        {#if extractMode}
                          <span class="text-[10px] text-faint">→ {destLabel}</span>
                        {:else if item.inspect?.kind === 'plugin'}
                          <span class="text-[10px] text-faint">→ plugins/</span>
                        {:else if item.inspect?.kind === 'theme'}
                          <span class="text-[10px] text-faint">→ themes/</span>
                        {/if}
                      {/if}
                    </div>
                    {#if inspectOn && item.inspect}
                      <p class="text-xs text-muted mt-1 ml-8">
                        {item.inspect.name || item.inspect.slug || 'Archive'}
                        {#if item.inspect.version} v{item.inspect.version}{/if}
                        {#if item.inspect.existing?.present}
                          · already installed{item.inspect.existing.installed_version ? ` v${item.inspect.existing.installed_version}` : ''}
                        {:else if item.inspect.kind !== 'unknown'}
                          · new
                        {/if}
                      </p>
                    {/if}
                    {#if item.error}
                      <p class="text-xs text-red-700 dark:text-red-400 mt-1 ml-8">{item.error}</p>
                    {/if}
                  </div>
                  <button
                    onclick={() => upload.removeFromQueue(index)}
                    disabled={busy}
                    class="text-muted hover:text-red-700 dark:text-red-400 disabled:opacity-50 disabled:cursor-not-allowed shrink-0"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                  </button>
                </div>
              {/each}
            </div>

            <Button.Root
              variant="primary"
              size="default"
              onclick={() => upload.reviewAndExtract()}
              disabled={!canReview}
              class="mt-4 w-full inline-flex items-center justify-center gap-2 px-4 py-2.5
            bg-primary hover:bg-primary/80
            text-primary-foreground text-sm font-medium
            border border-primary/30 rounded-md
            shadow-sm hover:shadow-md
            active:scale-[0.98] active:shadow-none
            focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0a0a0c]
            disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-primary
            transition-all duration-200 cursor-pointer"
            >
              {#if busy}
                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span>{installing ? 'Extracting...' : (uploading ? 'Preparing...' : 'Working...')}</span>
              {:else}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <span>{reviewLabel}</span>
              {/if}
            </Button.Root>
          </div>
        {/if}

        {#if busy}
          <div class="mt-5">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm text-muted">{installMessage || (installing ? 'Extracting...' : 'Uploading...')}</span>
              <span class="text-sm font-mono text-primary">{installing ? installProgress : uploadProgress}%</span>
            </div>
            <div class="h-1.5 bg-elevated rounded-full overflow-hidden">
              <div
                class="h-full bg-gradient-to-r from-primary to-primary/80 transition-all duration-300"
                style="width: {installing ? installProgress : uploadProgress}%"
              ></div>
            </div>
          </div>
        {/if}

        {#if uploadResult}
          <div class="mt-5 p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-xl">
            <div class="flex items-center gap-3">
              <svg class="w-5 h-5 text-emerald-700 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              <div>
                <div class="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                  {uploadResult.reinstalled ? 'Reinstall complete' : 'Extract complete'}
                </div>
                <p class="text-xs text-muted">
                  {#if uploadResult.reinstalled}
                    Reinstalled. If it was already active, it stays active.
                    {#if uploadResult.destination_rel}
                      <span class="block mt-1">{uploadResult.destination_rel}{uploadResult.slug ? ` (${uploadResult.slug})` : ''}</span>
                    {/if}
                  {:else}
                    Extracted to {uploadResult.destination_rel || upload.destLabel(uploadResult.destination || destination, customRel)}.
                    New packages stay inactive. Existing ones keep their current active state.
                  {/if}
                </p>
              </div>
            </div>
            {#if showSuccessActions}
              <div class="mt-4 flex flex-wrap gap-2">
                <button
                  type="button"
                  onclick={() => upload.analyzeExtensions()}
                  class="px-3 py-2 text-sm font-medium bg-primary hover:bg-primary/80 text-white rounded-md transition-all"
                >
                  Analyze Extensions
                </button>
                <button
                  type="button"
                  onclick={() => upload.installAnother()}
                  class="px-3 py-2 text-sm font-medium bg-elevated hover:bg-elevated/80 border border-line text-ink rounded-md transition-all"
                >
                  Install another
                </button>
                <button
                  type="button"
                  onclick={() => upload.discardLeftovers()}
                  class="px-3 py-2 text-sm font-medium text-muted hover:text-ink rounded-md transition-all"
                >
                  Discard leftovers
                </button>
              </div>
            {/if}
          </div>
        {/if}

        {#if batchResult?.failed?.length}
          <div class="mt-5 p-4 bg-red-500/10 border border-red-500/20 rounded-xl space-y-2">
            {#each batchResult.failed as fail}
              <div>
                <p class="text-sm font-medium text-red-700 dark:text-red-400">{fail.name}: {fail.error}</p>
                {#if fail.backup_rel && fail.destination_name}
                  <p class="text-xs text-muted mt-1">
                    Manually copy {fail.backup_rel}/ back over {fail.destination_name}.
                  </p>
                {/if}
              </div>
            {/each}
            {#if showSuccessActions && !uploadResult}
              <div class="pt-2 flex flex-wrap gap-2">
                <button
                  type="button"
                  onclick={() => upload.analyzeExtensions()}
                  class="px-3 py-2 text-sm font-medium bg-primary hover:bg-primary/80 text-white rounded-md transition-all"
                >
                  Analyze Extensions
                </button>
                <button
                  type="button"
                  onclick={() => upload.installAnother()}
                  class="px-3 py-2 text-sm font-medium bg-elevated hover:bg-elevated/80 border border-line text-ink rounded-md transition-all"
                >
                  Install another
                </button>
                <button
                  type="button"
                  onclick={() => upload.discardLeftovers()}
                  class="px-3 py-2 text-sm font-medium text-muted hover:text-ink rounded-md transition-all"
                >
                  Discard leftovers
                </button>
              </div>
            {/if}
          </div>
        {/if}
      </div>
    </div>
  </div>
</div>

{#if browseOpen}
  <div class="fixed inset-0 bg-black/60 flex items-center justify-center z-50">
    <div class="bg-panel border border-line rounded-lg p-6 w-full max-w-md shadow-xl">
      <h3 class="text-lg font-semibold text-ink mb-1">Browse folders</h3>
      <p class="text-xs text-muted mb-4 truncate">{browsePath || '/'}</p>
      <div class="max-h-64 overflow-y-auto border border-line rounded-md mb-4 bg-app">
        {#if browsePath}
          <button
            type="button"
            onclick={() => upload.openBrowse(parentBrowsePath(browsePath))}
            class="w-full text-left px-3 py-2 text-sm text-muted hover:bg-elevated"
          >
            ..
          </button>
        {/if}
        {#if browseLoading}
          <p class="px-3 py-4 text-sm text-muted">Loading…</p>
        {:else if browseEntries.length === 0}
          <p class="px-3 py-4 text-sm text-muted">No subfolders</p>
        {:else}
          {#each browseEntries as entry}
            <button
              type="button"
              onclick={() => upload.openBrowse(entry.path)}
              class="w-full text-left px-3 py-2 text-sm text-ink hover:bg-elevated"
            >
              {entry.name}
            </button>
          {/each}
        {/if}
      </div>
      <div class="flex items-center gap-3">
        <button
          type="button"
          onclick={() => upload.useBrowseFolder()}
          class="flex-1 px-4 py-2.5 bg-primary hover:bg-primary/80 text-white text-sm font-medium rounded-md transition-all"
        >
          Use this folder
        </button>
        <button
          type="button"
          onclick={() => upload.closeBrowse()}
          class="px-4 py-2.5 bg-elevated hover:bg-elevated text-ink text-sm font-medium rounded-md transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  </div>
{/if}

{#if confirmOpen}
  <div class="fixed inset-0 bg-black/60 flex items-center justify-center z-50">
    <div class="bg-panel border border-line rounded-lg p-6 w-full max-w-md shadow-xl">
      <h3 class="text-lg font-semibold text-ink mb-2">{isPackage ? 'Confirm reinstall' : 'Confirm extract'}</h3>
      <p class="text-sm text-muted mb-4">
        {#if mixedSmart}
          Install <span class="text-ink font-medium">{batchSummary}</span>.
          Each ZIP goes to its package folder. Existing packages with the same folder will be replaced. If they were already active, they stay active.
        {:else if isPackage}
          Reinstall to <span class="text-ink font-medium">{destLabel}</span>.
          Existing packages with the same folder will be replaced. If they were already active, they stay active.
        {:else}
          Extract files to <span class="text-ink font-medium">{destLabel}</span>.
          Existing files with the same name will be overwritten.
        {/if}
      </p>

      <div class="space-y-2 mb-4 max-h-40 overflow-y-auto">
        {#each uploadQueue as item}
          <div class="p-3 bg-app rounded-md border border-line text-sm">
            <div class="text-ink font-medium truncate">{itemName(item)}</div>
            <div class="text-xs text-muted mt-1">
              {#if extractMode}
                → {destLabel}
              {:else if item.inspect?.kind === 'plugin'}
                → plugins/
              {:else if item.inspect?.kind === 'theme'}
                → themes/
              {/if}
              {#if item.inspect?.slug}
                {item.inspect.kind === 'plugin' || item.inspect.kind === 'theme' ? ' · ' : ''}
                {item.inspect.slug}
                {#if item.inspect.version} · v{item.inspect.version}{/if}
              {:else}
                {kindLabel(item.inspect?.kind)}
              {/if}
              {#if item.inspect?.existing?.present}
                · overwrite existing{item.inspect.existing.installed_version ? ` v${item.inspect.existing.installed_version}` : ''}
              {/if}
            </div>
          </div>
        {/each}
      </div>

      {#if kindBanner && !kindBannerBlocking}
        <p class="text-xs text-sky-700 dark:text-sky-300 mb-4">{kindBanner}</p>
      {:else if kindBannerBlocking}
        <p class="text-xs text-amber-700 dark:text-amber-400 mb-4">{kindBanner}</p>
      {/if}

      {#if backupOn && isPackage && backupEligible}
        <div class="space-y-3 mb-4">
          <label class="flex items-start gap-3 p-4 bg-app rounded-md border {createBackup ? 'border-primary/30' : 'border-line'} cursor-pointer">
            <input
              type="radio"
              name="upload-backup"
              checked={createBackup}
              onchange={() => upload.setCreateBackup(true)}
              class="mt-0.5 accent-primary"
            >
            <div>
              <div class="text-sm font-medium text-ink">Back up the existing folder</div>
              <div class="text-xs text-muted mt-1">Copies the unique top-level directory before overwrite.</div>
            </div>
          </label>
          <label class="flex items-start gap-3 p-4 bg-app rounded-md border {!createBackup ? 'border-primary/30' : 'border-line'} cursor-pointer">
            <input
              type="radio"
              name="upload-backup"
              checked={!createBackup}
              onchange={() => upload.setCreateBackup(false)}
              class="mt-0.5 accent-primary"
            >
            <div>
              <div class="text-sm font-medium text-ink">Skip backup</div>
              <div class="text-xs text-muted mt-1">Replace without copying the existing folder.</div>
            </div>
          </label>
        </div>
      {:else if backupOn && isPackage && backupBlocked}
        <p class="text-xs text-amber-700 dark:text-amber-400 mb-4">{backupBlocked}</p>
      {/if}

      {#if atRoot}
        <label class="flex items-start gap-3 p-4 bg-app rounded-md border border-amber-500/30 mb-4 cursor-pointer">
          <input
            type="checkbox"
            checked={confirmRoot}
            onchange={(e) => upload.setConfirmRoot(e.currentTarget.checked)}
            class="mt-0.5 accent-primary"
          >
          <div>
            <div class="text-sm font-medium text-ink">I understand this writes at the WordPress root.</div>
            <div class="text-xs text-muted mt-1">Files land next to wp-config.php and other core files.</div>
          </div>
        </label>
      {/if}

      <div class="flex items-center gap-3">
        <button
          onclick={() => upload.confirmAndInstall()}
          disabled={!canConfirm}
          class="flex-1 px-4 py-2.5 bg-primary hover:bg-primary/80 disabled:opacity-50 text-white text-sm font-medium rounded-md transition-all"
        >
          {#if installing}
            {isPackage ? 'Reinstalling...' : 'Extracting...'}
          {:else}
            {confirmCta}
          {/if}
        </button>
        <button
          onclick={() => upload.closeConfirm()}
          disabled={installing}
          class="px-4 py-2.5 bg-elevated hover:bg-elevated text-ink text-sm font-medium rounded-md transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  </div>
{/if}
