<script>
  import { onMount, tick } from 'svelte';
  import { aiScan } from '../../lib/stores/aiScan.js';
  import { folderReviewSentence, groupsOf, infectionTimeLabel, logsNote, nestLogGroup, outcomeNote, placeOf, requestSummary, signatureProgressLine, splitFindings, spreadSummary, tokenFacts } from '../../lib/aiReportView.js';
  import { saveAiReportPdf } from '../../lib/aiReportPdf.js';

  let prefix = $state('');
  let logOpen = $state(false);
  let openLog = $state({});
  let confirmPass = $state(false);
  let confirmSend = $state('');
  let openUndecided = $state({});
  let openSafe = $state({});
  let clearedOpen = $state(false);
  let loaded = $state(false);
  let editing = $state(false);
  let showConnect = $state(false);
  let provider = $state('');
  let key = $state('');
  let model = $state('');
  let busy = $state(false);
  let busyAction = $state('');

  const providers = ['SpaceXAI', 'OpenAI', 'OpenRouter'];
  const pressable = 'cursor-pointer select-none transition active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
  const primaryButton = `${pressable} rounded bg-primary font-medium text-primary-foreground hover:bg-primary/90`;
  const lineButton = `${pressable} rounded border border-line bg-app text-ink hover:bg-hover`;
  const actionButton = `${lineButton} shrink-0 px-2 py-1 text-xs`;
  const fieldClass = 'mt-1 w-full cursor-pointer rounded border border-line bg-app px-2 py-1.5 disabled:cursor-not-allowed';

  onMount(async () => {
    await aiScan.load();
    let snap = null;
    const unsub = aiScan.subscribe((value) => { snap = value; });
    unsub();
    provider = snap?.provider || '';
    model = snap?.model || '';
    editing = !snap?.tested;
    loaded = true;
  });

  $effect(() => {
    if (!loaded) return;
    if ($aiScan.running) {
      editing = false;
      return;
    }
    if (!$aiScan.tested) editing = true;
    if ($aiScan.hasKey) showConnect = true;
  });

  function openSetup() {
    provider = $aiScan.provider || '';
    model = $aiScan.model || '';
    key = '';
    editing = true;
  }

  function setupStatus() {
    if ($aiScan.error) return $aiScan.error;
    if (!$aiScan.hasKey) return 'No key saved yet.';
    if (!$aiScan.model || !$aiScan.tested) return 'Key saved. Choose a model and test it.';
    return 'Ready.';
  }

  function savedLine() {
    const parts = [];
    if ($aiScan.provider) parts.push($aiScan.provider);
    if ($aiScan.model) parts.push($aiScan.model);
    if ($aiScan.keyHint) parts.push(`key ends in ${$aiScan.keyHint}`);
    return parts.join(' · ');
  }

  async function saveProvider() {
    if (!providers.includes(provider)) {
      provider = $aiScan.provider || '';
      return;
    }
    busy = true;
    busyAction = 'provider';
    try {
      await aiScan.saveConfig({ provider, model: '' });
      key = '';
      model = '';
    } finally {
      busy = false;
      busyAction = '';
    }
  }

  async function saveKey() {
    busy = true;
    busyAction = 'key';
    try {
      await aiScan.saveConfig({ provider, key, model: model || undefined });
      if (key) key = '';
    } finally {
      busy = false;
      busyAction = '';
    }
  }

  async function loadModelList() {
    busy = true;
    busyAction = 'models';
    try {
      await aiScan.loadModels();
    } finally {
      busy = false;
      busyAction = '';
    }
  }

  async function chooseModel(event) {
    model = event.target.value;
    busy = true;
    busyAction = 'model';
    try {
      await aiScan.saveConfig({ provider, model });
    } finally {
      busy = false;
      busyAction = '';
    }
  }

  async function runTest() {
    busy = true;
    busyAction = 'test';
    try {
      const ok = await aiScan.test();
      if (ok) editing = false;
    } finally {
      busy = false;
      busyAction = '';
    }
  }

  function phaseLine() {
    const phase = $aiScan.phase;
    const done = $aiScan.done;
    const total = $aiScan.total;
    if (phase === 'signature') return `Signature scan, ${done} files checked`;
    if (phase === 'queue') return 'Choosing files for AI';
    if (phase === 'review') return `AI review, ${done} of ${total} files`;
    if (phase === 'logs') return 'Reading request logs';
    if (phase === 'followup') return 'Running follow-up checks';
    if (phase === 'report') return 'Writing the report';
    return 'Working';
  }

  function stopLine() {
    const error = String($aiScan.error || '').trim();
    if (error) return error;
    if ($aiScan.aiStatus === 'paused') return 'The AI review paused. The provider did not answer in time.';
    return 'The AI review stopped.';
  }

  function money(amount) {
    const n = Number(amount);
    if (!Number.isFinite(n) || n < 0) return '';
    const digits = n >= 0.01 || n === 0 ? 2 : 4;
    return `$${n.toFixed(digits)}`;
  }

  function usageText(usage) {
    if (!usage) return '';
    const parts = [];
    if (usage.model) parts.push(usage.model);
    const requests = Number(usage.requests || 0);
    if (requests > 0) parts.push(`${requests.toLocaleString()} ${requests === 1 ? 'request' : 'requests'}`);
    const price = money(usage.cost);
    if (price) parts.push(price);
    return parts.join(' · ');
  }

  async function addPrefix() {
    if (!prefix.trim()) return;
    await aiScan.savePolicy({ add_prefix: prefix.trim() });
    prefix = '';
  }

  function onSkipList(path) {
    return ($aiScan.prefixes || []).some((item) => item === path);
  }

  async function skipNextScan(path) {
    await aiScan.savePolicy({ add_prefix: path });
  }

  async function includeNextScan(path) {
    await aiScan.savePolicy({ remove_prefix: path });
  }

  function downloadPdf() {
    const current = $aiScan.report;
    if (!current) return;
    saveAiReportPdf(current);
  }

  function scrollToResults(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function openCard(id, section) {
    if (section === 'undecided' || section === 'safe') {
      aiScan.setSection(section, true);
    }
    if (section === 'cleared') clearedOpen = true;
    await tick();
    scrollToResults(id);
  }

  function dbKey(row) {
    return `db:${row.table || ''}:${row.row_id ?? ''}:${row.column || ''}`;
  }

  let clock = $state(Date.now());
  let stepSince = $state(0);
  let stepMark = '';
  $effect(() => {
    if (!$aiScan.running) return;
    const timer = setInterval(() => { clock = Date.now(); }, 1000);
    return () => clearInterval(timer);
  });
  $effect(() => {
    if (!$aiScan.running) {
      stepSince = 0;
      stepMark = '';
      return;
    }
    const mark = `${$aiScan.phase}|${$aiScan.done}|${$aiScan.notice || ''}`;
    if (mark !== stepMark) {
      stepMark = mark;
      stepSince = Date.now();
    }
  });

  let revealedKey = '';

  function toggleGroup(tone, key) {
    if (tone === 'safe') {
      openSafe = { ...openSafe, [key]: !openSafe[key] };
    } else {
      openUndecided = { ...openUndecided, [key]: !openUndecided[key] };
    }
  }

  function toggleLog(key) {
    openLog = { ...openLog, [key]: !openLog[key] };
  }

  let signatureIdle = $derived(
    $aiScan.signature.idleSince > 0 ? Math.max(0, clock - $aiScan.signature.idleSince) : 0
  );
  let providerWait = $derived(
    $aiScan.running && $aiScan.phase === 'review' && stepSince > 0
      ? Math.max(0, Math.floor((clock - stepSince) / 1000))
      : 0
  );
  let usageLine = $derived(usageText($aiScan.usage));
  let reviewPercent = $derived(
    $aiScan.total > 0 ? Math.min(100, ($aiScan.done / $aiScan.total) * 100) : 0
  );
  const continuablePhases = ['queue', 'review', 'logs', 'followup', 'report'];
  let canContinue = $derived(
    !$aiScan.running
    && (
      $aiScan.aiStatus === 'paused'
      || (
        $aiScan.aiStatus === 'error'
        && continuablePhases.includes($aiScan.phase)
        && $aiScan.done < $aiScan.total
      )
    )
  );
  let waitingMore = $derived(
    Math.max(0, Number($aiScan.waiting || 0) - ($aiScan.waitingPaths || []).length)
  );
  let signatureLine = $derived(signatureProgressLine($aiScan.signature, signatureIdle));
  let report = $derived($aiScan.report);
  let spreadLines = $derived(spreadSummary(report && report.spread));
  let findings = $derived(splitFindings(report));
  let requestLines = $derived(requestSummary(findings.dangerous));
  let undecidedOpen = $derived(!!$aiScan.sections.undecided);
  let safeOpen = $derived(!!$aiScan.sections.safe);
  let undecidedGroups = $derived(groupsOf(findings.undecided));
  let safeGroups = $derived(groupsOf(findings.safe));
  let logTree = $derived(groupsOf($aiScan.log).map((group) => nestLogGroup(group)));
  let empty = $derived(
    report
    && $aiScan.aiStatus === 'completed'
    && findings.dangerous.length === 0
    && findings.undecided.length === 0
    && findings.safe.length === 0
    && (report.database || []).length === 0
  );

  // The editor unmounts this tab. Reopen the section and group for the row
  // that was open, once per visit, so a later collapse stays collapsed.
  $effect(() => {
    const key = $aiScan.openedKey;
    if (!key || key === revealedKey) return;
    revealedKey = key;
    if (key.startsWith('db:')) return;
    if (findings.undecided.some((row) => row.path === key)) {
      if ($aiScan.sections.undecided !== true) aiScan.setSection('undecided', true);
      const place = placeOf(key);
      if (place?.key && !openUndecided[place.key]) {
        openUndecided = { ...openUndecided, [place.key]: true };
      }
      return;
    }
    if (findings.safe.some((row) => row.path === key)) {
      if (!$aiScan.sections.safe) aiScan.setSection('safe', true);
      const place = placeOf(key);
      if (place?.key && !openSafe[place.key]) {
        openSafe = { ...openSafe, [place.key]: true };
      }
    }
  });
</script>

<div class="min-w-0 p-6 max-w-5xl mx-auto space-y-6 text-ink">
  <header class="space-y-1">
    <h1 class="flex flex-wrap items-center gap-2 text-xl font-bold text-ink">
      <span class="whitespace-nowrap">AI scan</span>
      {#if loaded}
        <span class="rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary">Beta</span>
      {/if}
    </h1>
    {#if loaded && !editing}
      <p class="text-sm text-muted">One start runs the signature scan, then the AI review. This scan does not delete or edit files.</p>
    {/if}
  </header>

  {#if !loaded}
    <p class="text-sm text-muted">Loading.</p>
  {:else if editing && !showConnect}
    <section class="overflow-hidden rounded-xl border border-line bg-panel">
      <div class="h-1 bg-primary"></div>
      <div class="px-5 py-8 sm:px-8 sm:py-10">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-primary">Sensitive by default</p>
        <h2 class="mt-3 max-w-xl text-2xl font-semibold tracking-tight text-ink sm:text-[1.75rem] sm:leading-tight">Scan the files for malware with your AI model</h2>
        <p class="mt-4 max-w-xl text-sm leading-relaxed text-muted">Clean Sweep runs the signature scan on this server, then sends the selected files to your model. The scan does not delete or edit files. Nothing from the site is sent until a scan is started.</p>
        <div class="mt-8 grid gap-3 sm:grid-cols-3">
          <div class="rounded-lg border border-line bg-app px-4 py-4">
            <p class="text-[11px] font-semibold text-primary">01</p>
            <p class="mt-2 text-sm font-medium text-ink">Secrets stay here</p>
            <p class="mt-1.5 text-xs leading-relaxed text-muted">wp-config.php, .env, key files, SQL dumps, and backup folders are checked on this server and are not sent. Database text is not sent.</p>
          </div>
          <div class="rounded-lg border border-line bg-app px-4 py-4">
            <p class="text-[11px] font-semibold text-primary">02</p>
            <p class="mt-2 text-sm font-medium text-ink">Passwords are masked</p>
            <p class="mt-1.5 text-xs leading-relaxed text-muted">In the code that is sent, password-shaped values are replaced with [withheld] before the request is built.</p>
          </div>
          <div class="rounded-lg border border-line bg-app px-4 py-4">
            <p class="text-[11px] font-semibold text-primary">03</p>
            <p class="mt-2 text-sm font-medium text-ink">The cautious choices are already on</p>
            <p class="mt-1.5 text-xs leading-relaxed text-muted">Request logs send the method, path, status, and time, with addresses and cookies removed. The full pass is off. A path you skip is never sent.</p>
          </div>
        </div>
        <button type="button" class="{primaryButton} mt-8 px-4 py-2 text-sm" onclick={() => (showConnect = true)}>Connect a provider</button>
      </div>
    </section>
  {:else if editing}
    <section class="rounded-lg border border-line bg-panel p-4 space-y-3">
      <div>
        <h2 class="text-sm font-semibold">Connect a provider</h2>
        <p class="mt-1 text-xs leading-relaxed text-muted">Paste an API key for SpaceXAI, OpenAI, or OpenRouter. Test asks the model for one short reply. It does not scan the site.</p>
      </div>
      <label class="block text-sm">
        Provider
        <select class={fieldClass} bind:value={provider} onchange={saveProvider} disabled={$aiScan.running || busy}>
          <option value="">Select a provider</option>
          {#each providers as name}
            <option value={name}>{name}</option>
          {/each}
        </select>
      </label>
      <label class="block text-sm">
        API key
        <input class="mt-1 w-full rounded border border-line bg-app px-2 py-1.5" type="password" bind:value={key} placeholder={$aiScan.hasKey ? 'Key saved' : 'Paste the API key'} autocomplete="off" />
      </label>
      <div class="flex items-center gap-2 text-xs text-muted">
        {#if $aiScan.keyHint}<span>Saved key ends in {$aiScan.keyHint}</span>{/if}
        <button type="button" class="{lineButton} px-2.5 py-1 text-xs" onclick={saveKey} disabled={busy || $aiScan.running || !providers.includes(provider)}>{busyAction === 'key' ? 'Saving…' : 'Save key'}</button>
        <button type="button" class="{lineButton} px-2.5 py-1 text-xs" onclick={loadModelList} disabled={!$aiScan.hasKey || busy}>{busyAction === 'models' ? 'Loading…' : 'Load models'}</button>
      </div>
      <label class="block text-sm">
        Model
        <select class={fieldClass} value={model} onchange={chooseModel} disabled={!$aiScan.hasKey || $aiScan.running || busy}>
          <option value="">Choose a model</option>
          {#each $aiScan.models as id}
            <option value={id}>{id}</option>
          {/each}
          {#if model && !$aiScan.models.includes(model)}
            <option value={model}>{model}</option>
          {/if}
        </select>
      </label>
      <button type="button" class="{primaryButton} px-3 py-1.5 text-sm" onclick={runTest} disabled={!$aiScan.model || busy || $aiScan.running}>{busyAction === 'test' ? 'Testing…' : 'Test'}</button>
      <p class="text-sm">{setupStatus()}</p>
    </section>
    {#if !$aiScan.hasKey}
      <button type="button" class="{lineButton} px-3 py-1.5 text-sm" onclick={() => (showConnect = false)}>About this scan</button>
    {/if}
    {#if $aiScan.tested && !$aiScan.running}
      <button type="button" class="{lineButton} px-3 py-1.5 text-sm" onclick={() => (editing = false)}>Back to the scan</button>
    {/if}
  {:else}
  {#if !$aiScan.running}
    <div class="grid gap-2 sm:grid-cols-3">
      <div class="rounded-lg border border-line bg-panel px-3 py-2.5">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-primary">Held here</p>
        <p class="mt-1 text-xs leading-relaxed text-muted">Secrets and database text stay on this server.</p>
      </div>
      <div class="rounded-lg border border-line bg-panel px-3 py-2.5">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-primary">Masked</p>
        <p class="mt-1 text-xs leading-relaxed text-muted">Password-shaped values in sent code become [withheld].</p>
      </div>
      <div class="rounded-lg border border-line bg-panel px-3 py-2.5">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-primary">Off until you turn it on</p>
        <p class="mt-1 text-xs leading-relaxed text-muted">The full pass is off. A skipped path is never sent.</p>
      </div>
    </div>
  {/if}

  <section class="rounded-lg border border-line bg-panel p-4 space-y-2 text-sm">
    <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
      <p class="min-w-0 break-words">{savedLine()}</p>
      {#if !$aiScan.running}
        <button type="button" class="{actionButton} self-start" onclick={openSetup}>Change</button>
      {/if}
    </div>
    {#if !$aiScan.running && ($aiScan.aiStatus === 'error' || $aiScan.aiStatus === 'paused')}
      <p class="text-sm text-amber-800 dark:text-amber-200">{stopLine()}</p>
      {#if $aiScan.detail}
        <p class="text-xs text-muted">{$aiScan.detail}</p>
      {/if}
    {:else if $aiScan.error}
      <p class="text-sm text-amber-800 dark:text-amber-200">{$aiScan.error}</p>
    {/if}
  </section>

  <section class="rounded-lg border border-line bg-panel p-4 space-y-3">
    <h2 class="text-sm font-semibold">What this run sends to {$aiScan.provider || 'the provider'}</h2>
    <div class="text-sm space-y-1">
      <div class="font-medium">Site code <span class="text-muted font-normal">Checked on this server · Sent</span></div>
      <p class="text-muted">Plugins, themes, must-use plugins, and PHP or JavaScript in uploads. Password-shaped values are replaced with [withheld].</p>
    </div>
    <div class="text-sm space-y-1">
      <div class="font-medium">Secret files <span class="text-muted font-normal">Checked on this server · Not sent</span></div>
      <p class="text-muted">wp-config.php, .env, key files, SQL dumps, and backup folders stay here unless you turn on the full pass or press Send this secret on that file.</p>
    </div>
    <div class="text-sm space-y-1">
      <div class="font-medium">Database <span class="text-muted font-normal">Checked on this server · Not sent</span></div>
      <p class="text-muted">Database: signature check on this server. AI review of database text is not in this version.</p>
    </div>
    <label class="flex cursor-pointer items-center gap-2 text-sm">
      <input type="checkbox" class="cursor-pointer disabled:cursor-not-allowed" checked={$aiScan.requestLogs} onchange={(e) => aiScan.savePolicy({ request_logs: e.currentTarget.checked })} disabled={$aiScan.running} />
      Request logs. Method, path, status, and time are sent. Addresses and cookies are removed.
    </label>
    <div class="text-sm space-y-2">
      <div class="font-medium">Also skip these paths</div>
      <div class="flex gap-2">
        <input class="min-w-0 flex-1 rounded border border-line bg-app px-2 py-1.5" bind:value={prefix} placeholder="wp-content/plugins/my-private-plugin/" />
        <button type="button" class="{lineButton} px-2.5 py-1 text-sm" onclick={addPrefix}>Add</button>
      </div>
      {#each $aiScan.prefixes as item}
        <div class="flex items-center justify-between text-xs">
          <span>{item}</span>
          <button type="button" class={actionButton} onclick={() => includeNextScan(item)}>Include on next scan</button>
        </div>
      {/each}
    </div>
    <div class="border-t border-line pt-3 text-sm space-y-2">
      <label class="flex cursor-pointer items-center gap-2">
        <input type="checkbox" class="cursor-pointer disabled:cursor-not-allowed" checked={$aiScan.fullPass} onchange={(e) => { if (e.currentTarget.checked) { e.currentTarget.checked = false; confirmPass = true; } else aiScan.savePolicy({ full_pass: false }); }} disabled={$aiScan.running} />
        Send the whole site, including secrets
      </label>
      {#if $aiScan.fullPass}
        <p class="text-amber-700">{#if $aiScan.running}Full pass is on. This scan includes secrets for {$aiScan.provider || 'the provider'}.{:else}Full pass is on. Secrets will be sent to {$aiScan.provider || 'the provider'} when you press Start.{/if}</p>
      {/if}
    </div>
  </section>

  {#if confirmPass}
    <section class="rounded-lg border border-amber-600 bg-panel p-4 space-y-3 text-sm">
      <h2 class="font-semibold">Send secrets to {$aiScan.provider || 'the provider'}?</h2>
      <p>This includes wp-config.php and its copies, .env, key files, SQL dumps, backup folders, and customer files. Query strings in request logs are included. Database text stays on this server. Clean Sweep cannot check whether {$aiScan.provider || 'the provider'} stores the text. Your skip list still applies.</p>
      <div class="flex gap-2">
        <button type="button" class="{primaryButton} px-3 py-1.5 text-sm" onclick={() => (confirmPass = false)}>Don't send secrets</button>
        <button type="button" class="{lineButton} px-3 py-1.5 text-sm" onclick={() => { confirmPass = false; aiScan.savePolicy({ full_pass: true }); }}>Send secrets</button>
      </div>
    </section>
  {/if}

  {#if !$aiScan.running}
    <div class="space-y-2">
      <div class="flex flex-wrap items-center gap-2">
        {#if canContinue}
          <button type="button" class="{primaryButton} px-4 py-2 text-sm" disabled={!$aiScan.tested} onclick={() => aiScan.resume()}>Continue this scan</button>
          <button type="button" class="{lineButton} px-4 py-2 text-sm" disabled={!$aiScan.tested} onclick={() => aiScan.start()}>Start over</button>
        {:else}
          <button type="button" class="{primaryButton} px-4 py-2 text-sm" disabled={!$aiScan.tested} onclick={() => aiScan.start()}>Start</button>
        {/if}
      </div>
      {#if canContinue}
        <p class="text-xs text-muted">Start over drops the judged files and runs the signature pass again.</p>
      {/if}
    </div>
  {/if}

  {#if canContinue && ($aiScan.waitingPaths || []).length}
    <section class="rounded-lg border border-line bg-panel p-4 space-y-2 text-sm">
      <h2 class="text-sm font-semibold">Not judged yet</h2>
      <ul class="space-y-1">
        {#each $aiScan.waitingPaths as path (path)}
          <li class="break-all font-mono text-xs text-ink">{path}</li>
        {/each}
      </ul>
      {#if waitingMore > 0}
        <p class="text-xs text-muted">and {waitingMore} more</p>
      {/if}
    </section>
  {/if}

  {#if $aiScan.running}
    <div class="rounded-lg border px-3 py-3 text-sm space-y-3 {($aiScan.phase === 'signature' && signatureIdle >= 90000) || ($aiScan.phase === 'review' && providerWait >= 20) ? 'border-amber-500/60 bg-amber-500/5' : 'border-line'}">
      <div class="flex items-start justify-between gap-3">
        <p class="min-w-0 break-words">{$aiScan.phase === 'signature' ? signatureLine : phaseLine()}</p>
        <button type="button" class="{lineButton} shrink-0 px-2.5 py-1 text-sm" onclick={() => aiScan.cancel()}>Cancel</button>
      </div>
      {#if $aiScan.notice}
        <p class="text-xs text-muted">{$aiScan.notice}</p>
      {/if}
      {#if $aiScan.phase === 'review' && providerWait > 2}
        <p class="text-xs {providerWait >= 20 ? 'text-amber-800 dark:text-amber-200' : 'text-muted'}">Waiting on the provider, {providerWait}s</p>
      {/if}
      {#if usageLine}
        <p class="text-xs text-muted">{usageLine}</p>
      {/if}
      {#if $aiScan.phase === 'signature'}
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
          <div class="rounded-lg border border-line bg-panel p-3 text-center">
            <div class="text-lg font-bold text-ink">{$aiScan.signature.files.toLocaleString()}</div>
            <div class="text-[11px] text-muted">Files checked</div>
          </div>
          <div class="rounded-lg border border-line bg-panel p-3 text-center">
            <div class="text-lg font-bold text-ink">{$aiScan.signature.dbRows.toLocaleString()}</div>
            <div class="text-[11px] text-muted">Database rows</div>
          </div>
          <div class="rounded-lg border border-line bg-panel p-3 text-center">
            <div class="text-lg font-bold text-ink">{$aiScan.signature.matches.toLocaleString()}</div>
            <div class="text-[11px] text-muted">Signature matches</div>
          </div>
        </div>
      {:else if $aiScan.phase === 'review' && $aiScan.total > 0}
        <div class="grid grid-cols-2 gap-2">
          <div class="rounded-lg border border-line bg-panel p-3 text-center">
            <div class="text-lg font-bold text-ink">{$aiScan.done.toLocaleString()}</div>
            <div class="text-[11px] text-muted">Files judged</div>
          </div>
          <div class="rounded-lg border border-line bg-panel p-3 text-center">
            <div class="text-lg font-bold text-ink">{Math.max(0, $aiScan.total - $aiScan.done).toLocaleString()}</div>
            <div class="text-[11px] text-muted">Still waiting</div>
          </div>
        </div>
        <div class="h-1.5 overflow-hidden rounded bg-app" role="progressbar" aria-valuenow={$aiScan.done} aria-valuemin="0" aria-valuemax={$aiScan.total}>
          <div class="h-full bg-primary" style="width: {reviewPercent}%"></div>
        </div>
      {/if}
    </div>
  {/if}

  <section class="rounded-xl border border-line bg-panel overflow-hidden">
    <button type="button" class="w-full flex items-center justify-between gap-3 p-3.5 text-left cursor-pointer hover:bg-hover active:bg-hover transition-colors" aria-expanded={logOpen} onclick={() => (logOpen = !logOpen)}>
      <span class="min-w-0">
        <span class="block text-sm font-medium text-ink">Files the AI review handled</span>
        <span class="mt-0.5 block text-xs text-muted">{$aiScan.logCount} {$aiScan.logCount === 1 ? 'file' : 'files'}</span>
      </span>
      <span class="rounded border border-line bg-app px-2 py-1 text-xs text-ink">{logOpen ? 'Hide' : 'Show'}</span>
    </button>
    {#if logOpen}
      <div class="border-t border-line px-3 py-3 space-y-2">
        <p class="text-xs text-muted">Grouped by plugin, theme, or folder. Open a group to see its files. This is not the malware list. Skip on next scan leaves that file out of the next run. Include on next scan puts it back. Send this secret sends that one held file now.</p>
        {#if $aiScan.logCount > $aiScan.log.length}
          <p class="text-xs text-muted">Showing {$aiScan.log.length} of {$aiScan.logCount}.</p>
        {/if}
        {#if logTree.length === 0}
          <p class="text-xs text-muted">No files yet.</p>
        {:else}
          <div class="space-y-2">
            {#each logTree as group (group.key)}
              {@render logCard(group)}
            {/each}
          </div>
        {/if}
      </div>
    {/if}
  </section>

  {#if confirmSend}
    <section class="rounded-lg border border-amber-600 bg-panel p-4 space-y-3 text-sm">
      <h2 class="font-semibold">Send this secret to {$aiScan.provider || 'the provider'}?</h2>
      <p class="font-mono text-xs break-all">{confirmSend}</p>
      <p>This file was held on this server because it can contain passwords or keys. Sending it does not turn on the full pass. Your skip list still applies. Clean Sweep cannot check whether {$aiScan.provider || 'the provider'} stores the text.</p>
      <div class="flex gap-2">
        <button type="button" class="{primaryButton} px-3 py-1.5 text-sm" onclick={() => (confirmSend = '')}>Keep it on this server</button>
        <button type="button" class="{lineButton} px-3 py-1.5 text-sm" onclick={() => { const path = confirmSend; confirmSend = ''; aiScan.sendFile(path); }}>Send this secret</button>
      </div>
    </section>
  {/if}

  {#if report && !$aiScan.running && ($aiScan.aiStatus === 'completed' || $aiScan.aiStatus === 'error' || $aiScan.aiStatus === 'paused')}
    <section class="space-y-4">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="text-sm font-semibold text-ink">Scan results</h2>
        <button type="button" class={actionButton} onclick={downloadPdf}>Download PDF</button>
      </div>
      {#if $aiScan.aiStatus === 'error' && !canContinue}
        <p class="text-sm text-amber-800 dark:text-amber-200">{stopLine()}</p>
      {/if}

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-4">
        {#if findings.dangerous.length}
          <button type="button" onclick={() => scrollToResults('ai-results-dangerous')} class="min-w-0 cursor-pointer bg-panel border border-line rounded-xl p-4 text-center transition hover:border-red-500/40 active:scale-[0.99]">
            <div class="text-lg font-bold sm:text-2xl text-red-700 dark:text-red-400">{findings.dangerous.length.toLocaleString()}</div>
            <div class="text-xs text-muted">Dangerous</div>
          </button>
        {:else}
          <div class="min-w-0 bg-panel border border-line rounded-xl p-4 text-center">
            <div class="text-lg font-bold sm:text-2xl text-ink">0</div>
            <div class="text-xs text-muted">Dangerous</div>
          </div>
        {/if}
        {#if findings.undecided.length}
          <button type="button" onclick={() => openCard('ai-results-undecided', 'undecided')} class="min-w-0 cursor-pointer bg-panel border border-line rounded-xl p-4 text-center transition hover:border-amber-500/40 active:scale-[0.99]">
            <div class="text-lg font-bold sm:text-2xl text-amber-700 dark:text-amber-400">{findings.undecided.length.toLocaleString()}</div>
            <div class="text-xs text-muted">Not decided</div>
          </button>
        {:else}
          <div class="min-w-0 bg-panel border border-line rounded-xl p-4 text-center">
            <div class="text-lg font-bold sm:text-2xl text-ink">0</div>
            <div class="text-xs text-muted">Not decided</div>
          </div>
        {/if}
        {#if findings.safe.length}
          <button type="button" onclick={() => openCard('ai-results-safe', 'safe')} class="min-w-0 cursor-pointer bg-panel border border-line rounded-xl p-4 text-center transition hover:border-emerald-500/40 active:scale-[0.99]">
            <div class="text-lg font-bold sm:text-2xl text-green-700 dark:text-green-400">{findings.safe.length.toLocaleString()}</div>
            <div class="text-xs text-muted">Judged safe</div>
          </button>
        {:else}
          <div class="min-w-0 bg-panel border border-line rounded-xl p-4 text-center">
            <div class="text-lg font-bold sm:text-2xl text-ink">0</div>
            <div class="text-xs text-muted">Judged safe</div>
          </div>
        {/if}
        {#if (report.cleared || []).length}
          <button type="button" onclick={() => openCard('ai-results-cleared', 'cleared')} class="min-w-0 cursor-pointer bg-panel border border-line rounded-xl p-4 text-center transition hover:border-primary/40 active:scale-[0.99]">
            <div class="text-lg font-bold sm:text-2xl text-ink">{(report.summary?.cleared ?? report.cleared.length).toLocaleString()}</div>
            <div class="text-xs text-muted">Cleared hits</div>
          </button>
        {:else}
          <div class="min-w-0 bg-panel border border-line rounded-xl p-4 text-center">
            <div class="text-lg font-bold sm:text-2xl text-ink">{(report.summary?.cleared ?? 0).toLocaleString()}</div>
            <div class="text-xs text-muted">Cleared hits</div>
          </div>
        {/if}
      </div>

      <div class="p-3 rounded-lg border border-line bg-panel text-xs text-muted leading-relaxed space-y-1">
        {#if report.checksum_line}
          <p>{report.checksum_line}</p>
        {/if}
        {#each report.nested_lines || [] as line}
          <p>{line}</p>
        {/each}
        {#each report.encode_lines || [] as line}
          <p class="text-amber-800 dark:text-amber-200">{line}</p>
        {/each}
        <p>Database signature matches: {report.summary?.database_signature ?? 0}. Not sent to AI. Not sent: {report.summary?.not_sent ?? 0}.</p>
        {#if logsNote(report.summary)}
          <p>{logsNote(report.summary)}</p>
        {/if}
        {#if report.summary?.full_pass}
          <p class="text-ink">Full pass. Secrets were sent to {report.summary.provider}.</p>
        {/if}
        {#if report.summary?.files_sent_after}
          <p>{report.summary.files_sent_after === 1 ? '1 file sent after the report.' : `${report.summary.files_sent_after} files sent after the report.`}</p>
        {/if}
        {#if empty}
          <p class="text-emerald-700 dark:text-emerald-300">AI review finished. Nothing further to report.</p>
        {/if}
      </div>

      {#if findings.dangerous.length}
        <section id="ai-results-dangerous" class="space-y-2 scroll-mt-4">
          <div>
            <h3 class="text-sm font-semibold text-ink">Dangerous</h3>
            <p class="text-[11px] text-muted mt-0.5">{findings.dangerous.length} file{findings.dangerous.length === 1 ? '' : 's'} the model called malware.</p>
          </div>
          <div class="space-y-2">
            {#each findings.dangerous as row}
              <div class="rounded-lg border bg-app overflow-hidden {$aiScan.openedKey === row.path ? 'border-primary ring-1 ring-primary/40' : 'border-red-500/40'}">
                <button type="button" class="w-full p-3 text-left cursor-pointer hover:bg-hover active:bg-hover transition-colors" onclick={() => aiScan.openPath(row)}>
                  <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                      <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded border bg-red-500/15 border-red-500/30 text-red-700 dark:text-red-400">malware</span>
                      <p class="mt-1 text-[11px] font-mono text-ink break-all">{row.path}</p>
                      {#if row.summary}<p class="mt-1 text-xs text-ink">{row.summary}</p>{/if}
                      {#each (report.spread || []).filter((item) => item.from === row.path) as item}
                        <p class="mt-1 text-xs text-muted">{item.text}</p>
                      {/each}
                      {#if row.pattern}
                        <p class="mt-1.5 text-[11px] font-mono text-muted bg-panel border border-line rounded px-2 py-1.5 break-all">{row.pattern}{row.match ? ` · ${row.match}` : ''}</p>
                      {/if}
                      <p class="text-[11px] text-muted mt-1">{infectionTimeLabel(row)}{row.first_request_at ? ` First request ${row.first_request_at}.` : ''}</p>
                    </div>
                    {@render openChip('Open in editor')}
                  </div>
                </button>
              </div>
            {/each}
          </div>
        </section>
      {/if}

      {#if findings.undecided.length}
        <section id="ai-results-undecided" class="scroll-mt-4">
          <div class="rounded-xl border border-line bg-panel overflow-hidden">
            <button
              type="button"
              class="w-full flex items-start gap-3 p-3.5 text-left cursor-pointer hover:bg-hover active:bg-hover transition-colors"
              aria-expanded={undecidedOpen}
              onclick={() => aiScan.setSection('undecided', !undecidedOpen)}
            >
              <span class="text-muted mt-0.5 text-xs w-3">{undecidedOpen ? '▾' : '▸'}</span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-ink">Not decided</span>
                <span class="mt-0.5 block text-xs text-muted">{findings.undecided.length} {findings.undecided.length === 1 ? 'file' : 'files'}. These are not labeled malware.</span>
              </span>
            </button>
            {#if undecidedOpen}
              <div class="border-t border-line px-3 py-3">
                {@render verdictGroups(undecidedGroups, openUndecided, 'undecided')}
              </div>
            {/if}
          </div>
        </section>
      {/if}

      {#if findings.safe.length}
        <section id="ai-results-safe" class="scroll-mt-4">
          <div class="rounded-xl border border-line bg-panel overflow-hidden">
            <button
              type="button"
              class="w-full flex items-start gap-3 p-3.5 text-left cursor-pointer hover:bg-hover active:bg-hover transition-colors"
              aria-expanded={safeOpen}
              onclick={() => aiScan.setSection('safe', !safeOpen)}
            >
              <span class="text-muted mt-0.5 text-xs w-3">{safeOpen ? '▾' : '▸'}</span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-ink">Judged safe</span>
                <span class="mt-0.5 block text-xs text-muted">{findings.safe.length} {findings.safe.length === 1 ? 'file' : 'files'} the model called ordinary, with no signature hit.</span>
              </span>
            </button>
            {#if safeOpen}
              <div class="border-t border-line px-3 py-3">
                {@render verdictGroups(safeGroups, openSafe, 'safe')}
              </div>
            {/if}
          </div>
        </section>
      {/if}

      {#if (report.database || []).length}
        <section id="ai-results-database" class="space-y-2 scroll-mt-4">
          <div>
            <h3 class="text-sm font-semibold text-ink">Database</h3>
            <p class="text-[11px] text-muted mt-0.5">{report.database.length} signature match{report.database.length === 1 ? '' : 'es'}. Not sent to AI.</p>
          </div>
          <div class="space-y-2">
            {#each report.database as row}
              <div class="rounded-lg border bg-app overflow-hidden {$aiScan.openedKey === dbKey(row) ? 'border-primary ring-1 ring-primary/40' : 'border-amber-500/40'}">
                <button type="button" class="w-full p-3 text-left cursor-pointer hover:bg-hover active:bg-hover transition-colors" onclick={() => aiScan.openDatabase(row)}>
                  <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                      <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded border bg-amber-500/15 border-amber-500/30 text-amber-800 dark:text-amber-200">database</span>
                      <p class="mt-1 text-[11px] font-mono text-ink break-all">{row.table} {row.column}</p>
                      {#if row.label}<p class="mt-1 text-xs text-muted">{row.label}</p>{/if}
                    </div>
                    {@render openChip('View row')}
                  </div>
                </button>
              </div>
            {/each}
          </div>
        </section>
      {/if}

      {#if (report.not_sent || []).length}
        <section class="space-y-2">
          <div>
            <h3 class="text-sm font-semibold text-ink">Not sent to AI</h3>
            <p class="text-[11px] text-muted mt-0.5">{report.not_sent.length} file{report.not_sent.length === 1 ? '' : 's'} kept on this server. This is not a clean bill of health.</p>
          </div>
          <div class="rounded-xl border border-line bg-panel overflow-hidden divide-y divide-line">
            {#each report.not_sent as row}
              <div class="px-3 py-2 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <span class="min-w-0 font-mono text-[11px] text-ink break-all">{row.path}</span>
                <span class="flex flex-wrap items-center gap-2 text-xs text-muted">
                  {row.outcome}
                  {#if row.outcome === 'Not sent — secret file'}
                    <button type="button" class={actionButton} onclick={() => (confirmSend = row.path)}>Send this secret</button>
                  {/if}
                </span>
              </div>
            {/each}
          </div>
        </section>
      {/if}

      {#if (report.cleared || []).length}
        <section id="ai-results-cleared" class="space-y-2 scroll-mt-4">
          <div class="rounded-xl border border-line bg-panel overflow-hidden">
            <button
              type="button"
              class="w-full flex items-start gap-3 p-3.5 text-left cursor-pointer hover:bg-hover active:bg-hover transition-colors"
              aria-expanded={clearedOpen}
              onclick={() => (clearedOpen = !clearedOpen)}
            >
              <span class="text-muted mt-0.5 text-xs w-3">{clearedOpen ? '▾' : '▸'}</span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-ink">Cleared hits</span>
                <span class="mt-0.5 block text-xs text-muted">{report.cleared.length} {report.cleared.length === 1 ? 'file' : 'files'}</span>
              </span>
            </button>
            {#if clearedOpen}
              <ul class="border-t border-line divide-y divide-line">
                {#each report.cleared as row}
                  <li class="px-3 py-2 flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <span class="font-mono text-[11px] text-ink break-all">{row.path}</span>
                      {#if row.summary}<p class="text-xs text-muted mt-1">{row.summary}</p>{/if}
                    </div>
                    <button type="button" class={actionButton} onclick={() => aiScan.keep(row.path)}>Put back on the list</button>
                  </li>
                {/each}
              </ul>
            {/if}
          </div>
        </section>
      {/if}

      {#if (report.kept || []).length}
        <section class="space-y-2">
          <div>
            <h3 class="text-sm font-semibold text-ink">Put back on the list</h3>
            <p class="text-[11px] text-muted mt-0.5">{report.kept.length} file{report.kept.length === 1 ? '' : 's'} restored to the list.</p>
          </div>
          <div class="space-y-2">
            {#each report.kept as row}
              <div class="rounded-lg border bg-app overflow-hidden {$aiScan.openedKey === row.path ? 'border-primary ring-1 ring-primary/40' : 'border-line'}">
                <button type="button" class="w-full p-3 text-left cursor-pointer hover:bg-hover active:bg-hover transition-colors" onclick={() => aiScan.openPath(row)}>
                  <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                      <p class="text-[11px] font-mono text-ink break-all">{row.path}</p>
                      {#if row.summary}<p class="mt-1 text-xs text-muted">{row.summary}</p>{/if}
                    </div>
                    {@render openChip('Open in editor')}
                  </div>
                </button>
              </div>
            {/each}
          </div>
        </section>
      {/if}

      {#if (report.admins || []).length}
        <section class="space-y-2">
          <div>
            <h3 class="text-sm font-semibold text-ink">Administrator accounts</h3>
          </div>
          <ul class="rounded-xl border border-line bg-panel divide-y divide-line text-sm">
            {#each report.admins as row}
              <li class="px-3 py-2">{row.login} registered {row.registered}</li>
            {/each}
          </ul>
        </section>
      {/if}

      {#if (report.not_checked || []).length}
        <section class="space-y-2">
          <div>
            <h3 class="text-sm font-semibold text-ink">Not checked</h3>
          </div>
          <ul class="rounded-xl border border-line bg-panel divide-y divide-line text-sm">
            {#each report.not_checked as row}
              <li class="px-3 py-2 break-all"><span class="font-mono text-[11px]">{row.path}</span> <span class="text-muted">{row.reason}</span></li>
            {/each}
          </ul>
        </section>
      {/if}

      {#if (report.signature_checksum || []).length}
        <section class="space-y-2">
          <div>
            <h3 class="text-sm font-semibold text-ink">Signature and checksum results</h3>
            <p class="text-[11px] text-muted mt-0.5">{report.signature_checksum.length} finding{report.signature_checksum.length === 1 ? '' : 's'} from this run's signature pass.</p>
          </div>
          <ul class="rounded-xl border border-line bg-panel divide-y divide-line">
            {#each report.signature_checksum as row}
              <li class="px-3 py-2 break-all"><span class="font-mono text-[11px] text-ink">{row.path}</span> <span class="text-xs text-muted">{row.pattern}</span></li>
            {/each}
          </ul>
        </section>
      {/if}

      <div class="space-y-3 border-t border-line pt-4">
        <h2 class="text-sm font-semibold text-ink">Infection summary</h2>
        <div class="rounded-xl border p-4 space-y-3 {findings.dangerous.length ? 'border-red-500/40 bg-red-500/5' : findings.undecided.length ? 'border-amber-500/30 bg-amber-500/5' : 'border-emerald-500/30 bg-emerald-500/5'}">
          <h3 class="text-sm font-semibold text-ink">What this run found</h3>
          <p class="text-sm leading-relaxed">
            {#if findings.dangerous.length === 0}
              {report.narrative || 'No file in this report was judged to be malware.'}
            {:else}
              {findings.dangerous.length === 1 ? '1 dangerous file.' : `${findings.dangerous.length} dangerous files.`}
              Each sentence says what that code is supposed to do.
              {folderReviewSentence(report.folders)}
            {/if}
          </p>
          {#if findings.dangerous.length}
            <ul class="space-y-2">
              {#each (report.does?.length ? report.does : findings.dangerous) as row}
                <li class="text-sm break-words">
                  <span class="text-[10px] font-semibold uppercase tracking-wide text-red-700 dark:text-red-300">Dangerous</span>
                  <span class="mt-0.5 block font-mono text-xs break-all">{row.path}</span>
                  <span class="block">{row.summary}</span>
                </li>
              {/each}
            </ul>
          {/if}
          {#if (report.folders || []).length}
            <ul class="space-y-1 text-sm">
              {#each report.folders as folder}
                <li class="break-all">{folder.text}</li>
              {/each}
            </ul>
          {/if}
          {#if requestLines.length}
            <ul class="space-y-1 text-sm">
              {#each requestLines as line}
                <li class="break-all">{line}</li>
              {/each}
            </ul>
          {/if}
          {#if spreadLines.length}
            <ul class="space-y-1 text-sm">
              {#each spreadLines as line}
                <li class="break-all">{line}</li>
              {/each}
            </ul>
          {/if}
        </div>
      </div>

      <div class="rounded-xl border border-line bg-panel p-4 space-y-1">
        <h2 class="text-sm font-semibold text-ink">Tokens</h2>
        {#each tokenFacts(report) as fact}
          {#if fact.label}
            <p class="text-sm"><span class="text-muted">{fact.label}:</span> {fact.value}</p>
          {:else}
            <p class="text-sm text-muted">{fact.value}</p>
          {/if}
        {/each}
      </div>

      <p class="text-xs text-muted">Dangerous means the model called the file malware and said what that code does. Not decided means the excerpt was not enough to clear the file, or the model reply was not the expected JSON. A provider refusal stops the scan and shows the provider's reason above. Judged safe means the model called the file ordinary, or found no executable code, and there was no signature hit. A cleared row is a signature hit the model called ordinary code. Put back on the list shows that file again. A file that was not sent is not a clean bill of health.</p>
    </section>
  {/if}
  {/if}
</div>

{#snippet openChip(label)}
  <span class="{actionButton} pointer-events-none self-start">{label}</span>
{/snippet}

{#snippet verdictGroups(groups, openMap, tone)}
  <div class="space-y-2">
    {#each groups as group (group.key)}
      <div class="rounded-xl border border-line bg-panel overflow-hidden">
        <button
          type="button"
          class="w-full flex items-start gap-3 p-3.5 text-left cursor-pointer hover:bg-hover active:bg-hover transition-colors"
          aria-expanded={!!openMap[group.key]}
          onclick={() => toggleGroup(tone, group.key)}
        >
          <span class="text-muted mt-0.5 text-xs w-3">{openMap[group.key] ? '▾' : '▸'}</span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-medium text-ink truncate">{group.title}</span>
            <span class="mt-0.5 block text-xs text-muted">{group.rows.length} {group.rows.length === 1 ? 'file' : 'files'}</span>
          </span>
        </button>
        {#if openMap[group.key]}
          <div class="border-t border-line px-3 pb-3 space-y-2 pt-2">
            {#each group.rows as row}
              <div class="rounded-lg border bg-app overflow-hidden {$aiScan.openedKey === row.path ? 'border-primary ring-1 ring-primary/40' : 'border-line'}">
                <button type="button" class="w-full p-3 text-left cursor-pointer hover:bg-hover active:bg-hover transition-colors" onclick={() => aiScan.openPath(row)}>
                  <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                      <p class="text-[11px] font-mono text-ink break-all">{row.path}</p>
                      {#if row.summary}<p class="mt-1 text-xs text-ink">{row.summary}</p>{/if}
                      {#if row.pattern}
                        <p class="mt-1.5 text-[11px] font-mono text-muted bg-panel border border-line rounded px-2 py-1.5 break-all">{row.pattern}{row.match ? ` · ${row.match}` : ''}</p>
                      {/if}
                    </div>
                    {@render openChip('Open in editor')}
                  </div>
                </button>
              </div>
            {/each}
          </div>
        {/if}
      </div>
    {/each}
  </div>
{/snippet}

{#snippet logCard(node)}
  {@const note = outcomeNote(node.rows)}
  {@const open = !!openLog[node.key]}
  <div class="rounded-xl border border-line overflow-hidden {node.folders ? 'bg-panel' : 'bg-app'}">
    <button
      type="button"
      class="w-full flex items-start gap-3 p-3 text-left cursor-pointer hover:bg-hover active:bg-hover transition-colors"
      aria-expanded={open}
      onclick={() => toggleLog(node.key)}
    >
      <span class="text-muted mt-0.5 text-xs w-3">{open ? '▾' : '▸'}</span>
      <span class="min-w-0 flex-1">
        <span class="block text-sm font-medium text-ink break-all">{node.title}</span>
        <span class="mt-0.5 block text-xs text-muted">{node.rows.length} {node.rows.length === 1 ? 'file' : 'files'}{note ? ` · ${note}` : ''}</span>
      </span>
    </button>
    {#if open}
      <div class="border-t border-line px-3 pb-3 pt-2 space-y-2">
        {#if node.loose?.length}
          <ul class="rounded-lg border border-line bg-app divide-y divide-line">
            {#each node.loose as row (row.path)}
              {@render logFile(row)}
            {/each}
          </ul>
        {/if}
        {#if node.folders?.length}
          {#each node.folders as folder (folder.key)}
            {@render logCard(folder)}
          {/each}
        {:else if !node.loose}
          <ul class="rounded-lg border border-line divide-y divide-line">
            {#each node.rows as row (row.path)}
              {@render logFile(row)}
            {/each}
          </ul>
        {/if}
      </div>
    {/if}
  </div>
{/snippet}

{#snippet logFile(row)}
  <li class="px-3 py-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    <div class="min-w-0">
      <div class="font-mono text-[11px] text-ink break-all" title={row.path}>{row.label || row.path}</div>
      <div class="text-xs {String(row.outcome || '').startsWith('Not sent') ? 'text-amber-800 dark:text-amber-200' : 'text-muted'}">{row.outcome}{row.verdict ? ` · ${row.verdict}` : ''}</div>
    </div>
    {#if row.outcome === 'Not sent — secret file' && $aiScan.aiStatus === 'completed'}
      <button type="button" class={actionButton} onclick={() => (confirmSend = row.path)}>Send this secret</button>
    {:else if (row.outcome === 'Sent' || row.outcome === 'Sent, values masked') && onSkipList(row.path)}
      <div class="flex items-center gap-2">
        <span class="text-xs text-muted">Left out of the next scan</span>
        <button type="button" class={actionButton} onclick={() => includeNextScan(row.path)}>Include on next scan</button>
      </div>
    {:else if row.outcome === 'Sent' || row.outcome === 'Sent, values masked'}
      <button type="button" class={actionButton} onclick={() => skipNextScan(row.path)}>Skip on next scan</button>
    {/if}
  </li>
{/snippet}
