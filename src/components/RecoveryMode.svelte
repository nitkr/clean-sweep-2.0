<script>
  import { onMount } from 'svelte';
  import { Button } from 'bits-ui';
  import { API_CONFIG } from '../config/api';
  import ThemeToggle from './common/ThemeToggle.svelte';

  let { issues = [] } = $props();

  let isLoading = $state(false);
  let progress = $state(0);
  let statusMessage = $state('Preparing…');
  let status = $state('idle'); // idle | running | complete | error
  let errorMessage = $state('');
  let planHint = $state('');
  let fileInput = $state(/** @type {HTMLInputElement | null} */ (null));
  let pollInterval = null;

  const ISSUE_COPY = {
    missing_fresh_directory: 'Secure recovery environment is missing',
    missing_canary: 'Recovery environment setup is incomplete',
    wp_settings_corrupt: 'Recovery core files are missing or incomplete',
    site_http_500: 'Site is returning HTTP 500 errors',
    wp_load_corrupt: 'Site bootstrap files look corrupted',
  };

  let issueLines = $derived(
    (issues || []).map((id) => ISSUE_COPY[id] || String(id).replace(/_/g, ' '))
  );

  /** Keep progress/status copy free of product-engine brand names on this screen. */
  function sanitizeRecoveryCopy(text) {
    if (!text || typeof text !== 'string') return '';
    return text
      .replace(/\bWordPress\b/gi, 'recovery')
      .replace(/\bwordpress\.org\b/gi, 'the download server')
      .replace(/\bWP\b/g, 'recovery')
      .replace(/\s+/g, ' ')
      .trim();
  }

  onMount(() => {
    startRecovery();
    return () => {
      if (pollInterval) clearInterval(pollInterval);
    };
  });

  function getStatusClasses(s) {
    if (s === 'complete') {
      return 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-300 border border-emerald-500/30';
    }
    if (s === 'error') {
      return 'bg-red-500/15 text-red-800 dark:text-red-300 border border-red-500/30';
    }
    if (s === 'running') {
      return 'bg-amber-500/15 text-amber-900 dark:text-amber-200 border border-amber-500/30';
    }
    return 'bg-elevated text-muted border border-line';
  }

  function parseJsonLenient(text) {
    if (!text || typeof text !== 'string') return null;
    try {
      return JSON.parse(text);
    } catch {
      const start = text.indexOf('{');
      const end = text.lastIndexOf('}');
      if (start >= 0 && end > start) {
        try {
          return JSON.parse(text.slice(start, end + 1));
        } catch {
          return null;
        }
      }
      return null;
    }
  }

  function stopPolling() {
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = null;
    }
  }

  function startPolling() {
    stopPolling();
    pollInterval = setInterval(async () => {
      try {
        const response = await fetch(`logs/recovery_setup.progress?t=${Date.now()}`);
        if (response.status === 404) return;

        const data = parseJsonLenient(await response.text());
        if (!data) return;

        progress = Number(data.progress) || 0;
        statusMessage = sanitizeRecoveryCopy(data.message) || 'Processing…';

        if (data.status === 'complete') {
          stopPolling();
          status = 'complete';
          statusMessage = 'Complete!';
          progress = 100;
          planHint = 'Recovery environment ready';
          setTimeout(() => {
            window.location.reload();
          }, 900);
        } else if (data.status === 'error') {
          stopPolling();
          status = 'error';
          statusMessage = 'Failed';
          errorMessage = sanitizeRecoveryCopy(data.details || data.message) || 'Setup failed';
          isLoading = false;
          planHint = 'Automatic setup could not finish';
        } else if (data.step) {
          const stepHints = {
            resolve: 'Selecting a compatible recovery build…',
            download: 'Downloading recovery components…',
            extract: 'Extracting recovery components…',
            configure: 'Configuring recovery environment…',
            upload: 'Applying uploaded package…',
            done: 'Finishing setup…',
          };
          planHint = stepHints[data.step] || 'Initializing recovery mode…';
        }
      } catch (error) {
        console.error('Polling error:', error);
      }
    }, 1000);
  }

  async function startRecovery() {
    isLoading = true;
    progress = 5;
    status = 'running';
    statusMessage = 'Initializing recovery mode…';
    errorMessage = '';
    planHint = 'Preparing Clean Sweep…';

    startPolling();

    try {
      const formData = new FormData();
      formData.append('action', 'start_fresh_setup');
      formData.append('progress_file', 'recovery_setup');

      const response = await fetch(API_CONFIG.endpoints.bootstrap, {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      const data = parseJsonLenient(await response.text());
      if (data && data.success === false && status !== 'complete') {
        status = 'error';
        statusMessage = 'Failed';
        errorMessage = sanitizeRecoveryCopy(data.error || data.message) || 'Setup failed';
        isLoading = false;
        planHint = 'Automatic setup could not finish';
        stopPolling();
      }
    } catch {
      // Download can outlive the HTTP request. Progress polling decides success/failure.
      if (status === 'running') {
        statusMessage = 'Still setting up… waiting for progress';
      }
    }
  }

  async function handleZipSelected(event) {
    const input = event.currentTarget;
    const file = input?.files?.[0];
    if (!file) return;

    isLoading = true;
    progress = 10;
    status = 'running';
    statusMessage = 'Uploading recovery package…';
    errorMessage = '';
    planHint = 'Using uploaded package';

    startPolling();

    try {
      const formData = new FormData();
      formData.append('action', 'upload_wordpress_zip');
      formData.append('progress_file', 'recovery_setup');
      formData.append('recovery_zip', file);

      const response = await fetch(API_CONFIG.endpoints.bootstrap, {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      const data = parseJsonLenient(await response.text());
      if (data && data.success === false && status !== 'complete') {
        status = 'error';
        statusMessage = 'Failed';
        errorMessage = sanitizeRecoveryCopy(data.error || data.message) || 'Upload failed';
        isLoading = false;
        stopPolling();
      }
    } catch {
      if (status === 'running') {
        statusMessage = 'Upload started… waiting for progress';
      }
    } finally {
      if (input) input.value = '';
    }
  }
</script>

<div class="min-h-screen bg-app text-ink flex flex-col">
  <header class="h-14 border-b border-line flex items-center justify-between px-4 sm:px-6">
    <div class="flex items-center gap-2.5 min-w-0">
      <div class="w-8 h-8 rounded-lg logo-grad flex items-center justify-center flex-shrink-0">
        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
          />
        </svg>
      </div>
      <div class="min-w-0">
        <div class="text-sm font-semibold text-ink truncate">Clean Sweep</div>
        <div class="text-[10px] text-faint truncate">Recovery mode</div>
      </div>
    </div>
    <ThemeToggle />
  </header>

  <main class="flex-1 flex items-start justify-center p-4 sm:p-8">
    <div class="w-full max-w-xl">
      <div class="bg-panel border border-line rounded-xl p-6 shadow-sm">
        <div class="text-center mb-6">
          <div class="inline-flex items-center justify-center w-14 h-14 rounded-xl logo-grad mb-4">
            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
              />
            </svg>
          </div>
          <h1 class="text-xl font-bold text-ink mb-1">Setting up Clean Sweep</h1>
          <p class="text-sm text-muted leading-relaxed">
            Initializing a secure recovery environment so Clean Sweep can run safely on this site.
          </p>
        </div>

        {#if issueLines.length}
          <div class="mb-5 p-3 rounded-lg border border-line bg-app">
            <p class="text-[11px] uppercase tracking-wide text-faint mb-2">Why this is needed</p>
            <ul class="space-y-1.5">
              {#each issueLines as line}
                <li class="text-sm text-ink flex gap-2">
                  <span class="text-amber-600 dark:text-amber-400 shrink-0">•</span>
                  <span>{line}</span>
                </li>
              {/each}
            </ul>
          </div>
        {/if}

        <div class="flex items-center justify-between gap-3 mb-3">
          <h2 class="text-sm font-semibold text-ink">Setup progress</h2>
          <span class="px-2.5 py-1 text-xs rounded-full {getStatusClasses(status)}">
            {status === 'idle' ? 'Ready' : statusMessage}
          </span>
        </div>

        <div class="h-2.5 bg-elevated border border-line rounded-full overflow-hidden mb-2">
          <div
            class="h-full transition-all duration-300 bg-gradient-to-r from-blue-500 to-emerald-500"
            style="width: {progress}%"
          ></div>
        </div>
        <div class="flex items-center justify-between text-xs text-muted mb-5">
          <span>{planHint || 'Initializing recovery mode…'}</span>
          <span class="font-mono text-ink">{progress}%</span>
        </div>

        {#if status === 'error'}
          <div class="p-4 rounded-lg bg-red-500/10 border border-red-500/25">
            <p class="text-sm font-medium text-red-800 dark:text-red-300">
              {errorMessage || 'Automatic setup could not finish.'}
            </p>
            <p class="text-xs text-muted mt-2">
              You can retry, or upload a recovery package ZIP if the download is blocked on this host.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
              <Button.Root
                onclick={startRecovery}
                disabled={isLoading}
                class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-md
                  bg-primary hover:bg-primary/80 text-primary-foreground
                  disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                Retry setup
              </Button.Root>
              <Button.Root
                onclick={() => fileInput?.click()}
                disabled={isLoading}
                class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-md
                  bg-elevated hover:bg-hover text-ink border border-line
                  disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                Upload recovery package
              </Button.Root>
            </div>
          </div>
        {/if}

        <input
          bind:this={fileInput}
          type="file"
          accept=".zip,application/zip"
          class="hidden"
          onchange={handleZipSelected}
        />
      </div>

      <p class="text-center text-[11px] text-faint mt-4">
        Site content and database stay in place while recovery mode is prepared
      </p>
    </div>
  </main>
</div>
