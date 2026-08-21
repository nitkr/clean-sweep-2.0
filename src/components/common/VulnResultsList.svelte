<script>
  /**
   * Grouped vulnerability results with in-app detail expand.
   * External advisory links are secondary; primary content stays in Clean Sweep.
   */
  export let groups = [];
  /** @type {string|null} */
  export let selectedUuid = null;
  /** @type {(vuln: any) => void} */
  export let onSelect = () => {};
  /** @type {(vuln: any) => void} */
  export let onCopy = () => {};

  /** Which component groups are expanded — start all collapsed; user opens what they need */
  let openGroups = {};

  // When a new result set arrives (different keys), reset expansion so we don't
  // carry open state from a previous scan into a fresh list.
  let lastGroupSignature = '';
  $: {
    const sig = (groups || []).map((g) => g.group_key).join('|');
    if (sig !== lastGroupSignature) {
      lastGroupSignature = sig;
      openGroups = {};
    }
  }

  function toggleGroup(key) {
    openGroups = { ...openGroups, [key]: !openGroups[key] };
  }

  function riskClass(level) {
    switch ((level || '').toLowerCase()) {
      case 'critical': return 'text-red-700 dark:text-red-400';
      case 'high': return 'text-orange-700 dark:text-orange-400';
      case 'medium': return 'text-yellow-700 dark:text-yellow-400';
      case 'low':
      case 'info': return 'text-blue-700 dark:text-blue-400';
      default: return 'text-muted';
    }
  }

  function riskBorder(level) {
    switch ((level || '').toLowerCase()) {
      case 'critical': return 'border-red-500/40';
      case 'high': return 'border-orange-500/40';
      case 'medium': return 'border-yellow-500/40';
      default: return 'border-blue-500/30';
    }
  }

  function typeLabel(t) {
    if (t === 'plugin') return 'Plugin';
    if (t === 'theme') return 'Theme';
    if (t === 'core') return 'Core';
    return t || 'Component';
  }
</script>

{#if !groups?.length}
  <div class="p-4 rounded-xl border border-emerald-500/20 bg-emerald-500/5 text-sm text-emerald-700 dark:text-emerald-300/90">
    No known vulnerabilities found for the installed core, plugins, and themes.
  </div>
{:else}
  <div class="space-y-3">
    {#each groups as group (group.group_key)}
      {@const isOpen = !!openGroups[group.group_key]}
      <div class="rounded-xl border border-line bg-panel overflow-hidden">
        <!-- Group header -->
        <button
          type="button"
          class="w-full flex items-start gap-3 p-4 text-left hover:bg-hover transition-colors"
          onclick={() => toggleGroup(group.group_key)}
        >
          <span class="text-muted mt-0.5 text-xs">{isOpen ? '▾' : '▸'}</span>
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-sm font-semibold text-ink">{group.target_name}</span>
              <span class="text-xs font-mono text-muted">{group.target_version}</span>
              <span class="text-[10px] uppercase tracking-wide text-muted px-1.5 py-0.5 rounded bg-elevated">
                {typeLabel(group.target_type)}
              </span>
            </div>
            <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
              <span class="font-medium {riskClass(group.highest_risk)}">
                {group.issue_count} issue{group.issue_count === 1 ? '' : 's'}
              </span>
              {#if group.best_fixed_version}
                <span class="text-emerald-700/90 dark:text-emerald-400/90">
                  Update to ≥ {group.best_fixed_version}
                </span>
              {/if}
              {#if group.target_slug}
                <span class="text-faint font-mono">{group.target_slug}</span>
              {/if}
            </div>
          </div>
        </button>

        {#if isOpen}
          <div class="border-t border-line px-3 pb-3 space-y-2 pt-2">
            {#each group.vulnerabilities as vuln (vuln.uuid)}
              {@const selected = selectedUuid === vuln.uuid}
              <div
                class="rounded-lg border {riskBorder(vuln.risk_level)} bg-app overflow-hidden
                  {selected ? 'ring-1 ring-primary/50' : ''}"
              >
                <!-- Collapsed row -->
                <button
                  type="button"
                  class="w-full p-3 text-left hover:bg-hover transition-colors"
                  onclick={() => onSelect(vuln)}
                >
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[10px] font-semibold uppercase {riskClass(vuln.risk_level)}">
                          {vuln.risk_level}
                        </span>
                        {#if vuln.cvss_score != null && vuln.cvss_score > 0}
                          <span class="text-[10px] font-mono text-muted">CVSS {vuln.cvss_score}</span>
                        {/if}
                        {#if vuln.unfixed}
                          <span class="text-[10px] px-1.5 py-0.5 rounded bg-red-500/15 text-red-700 dark:text-red-300 border border-red-500/30">
                            Unfixed
                          </span>
                        {/if}
                      </div>
                      <p class="text-sm text-ink mt-1 leading-snug">
                        {vuln.short_title || vuln.name}
                      </p>
                      <p class="text-[11px] text-muted mt-1">
                        You have <span class="text-ink font-mono">{group.target_version}</span>
                        {#if vuln.affected_version}
                          · Affected: <span class="text-muted">{vuln.affected_version}</span>
                        {/if}
                        {#if vuln.fixed_version && !vuln.unfixed}
                          · Fix: <span class="text-emerald-700/90 dark:text-emerald-400/90 font-mono">≥ {vuln.fixed_version}</span>
                        {/if}
                      </p>
                      <!-- Primary CVE chips only -->
                      {#if vuln.primary_cves?.length}
                        <div class="mt-2 flex flex-wrap gap-1">
                          {#each vuln.primary_cves.slice(0, 4) as cve (cve.id)}
                            <span class="px-1.5 py-0.5 text-[10px] rounded bg-sky-500/10 text-sky-700 dark:text-sky-300 border border-sky-500/25 font-mono">
                              {cve.id}
                            </span>
                          {/each}
                          {#if vuln.other_advisories?.length}
                            <span class="px-1.5 py-0.5 text-[10px] rounded bg-elevated text-muted">
                              +{vuln.other_advisories.length} advisory
                            </span>
                          {/if}
                        </div>
                      {:else if vuln.other_advisories?.length}
                        <div class="mt-2 text-[10px] text-muted">
                          {vuln.other_advisories.length} advisory source{vuln.other_advisories.length === 1 ? '' : 's'} (expand for details)
                        </div>
                      {/if}
                    </div>
                    <span class="text-[11px] text-primary flex-shrink-0 pt-0.5">
                      {selected ? 'Hide' : 'Details'}
                    </span>
                  </div>
                </button>

                <!-- Expanded in-app detail -->
                {#if selected}
                  <div class="px-3 pb-3 border-t border-line pt-3 space-y-3">
                    <!-- Summary -->
                    <div>
                      <h5 class="text-[10px] font-semibold uppercase tracking-wider text-muted mb-1">Summary</h5>
                      {#if vuln.description}
                        <p class="text-sm text-ink leading-relaxed whitespace-pre-wrap">{vuln.description}</p>
                      {:else}
                        <p class="text-sm text-muted italic">No long description provided by the vulnerability feed. See advisories below.</p>
                      {/if}
                    </div>

                    <!-- Your site -->
                    <div class="rounded-lg bg-panel border border-line p-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                      <div>
                        <div class="text-muted mb-0.5">Installed</div>
                        <div class="text-ink font-mono">{group.target_version || '—'}</div>
                      </div>
                      <div>
                        <div class="text-muted mb-0.5">Affected range</div>
                        <div class="text-ink">{vuln.affected_version || 'See advisory'}</div>
                      </div>
                      <div class="sm:col-span-2">
                        <div class="text-muted mb-0.5">Recommended action</div>
                        <div class="text-emerald-700 dark:text-emerald-300/90">{vuln.remediation}</div>
                      </div>
                    </div>

                    <!-- Severity extras -->
                    {#if vuln.cvss_score || vuln.cwes?.length}
                      <div class="text-xs space-y-1">
                        {#if vuln.cvss_score}
                          <div class="text-muted">
                            CVSS: <span class="font-mono text-ink">{vuln.cvss_score}</span>
                            {#if vuln.cvss_vector}
                              <span class="text-faint ml-1">({vuln.cvss_vector})</span>
                            {/if}
                          </div>
                        {/if}
                        {#if vuln.cwes?.length}
                          <div class="text-muted">
                            CWE:
                            {#each vuln.cwes as cwe, i}
                              <span class="text-orange-700 dark:text-orange-300/90">{cwe.id}{cwe.name ? `: ${cwe.name}` : ''}</span>{i < vuln.cwes.length - 1 ? '; ' : ''}
                            {/each}
                          </div>
                        {/if}
                      </div>
                    {/if}

                    <!-- Advisories: text first, links secondary -->
                    {#if vuln.source?.length}
                      <div>
                        <h5 class="text-[10px] font-semibold uppercase tracking-wider text-muted mb-2">Advisories</h5>
                        <div class="space-y-2">
                          {#each [...(vuln.primary_cves || []), ...(vuln.other_advisories || [])] as adv (adv.id + (adv.link || ''))}
                            <div class="rounded-md border border-line bg-panel p-2.5">
                              <div class="flex flex-wrap items-center gap-2 mb-1">
                                {#if adv.kind === 'cve' || adv.kind === 'euvd'}
                                  <span class="text-[11px] font-mono text-sky-700 dark:text-sky-300">{adv.id}</span>
                                {:else if adv.kind === 'hash'}
                                  <span class="text-[10px] text-muted">Advisory record</span>
                                {:else if adv.id}
                                  <span class="text-[11px] text-muted font-mono truncate max-w-[12rem]">{adv.id}</span>
                                {/if}
                                {#if adv.date}
                                  <span class="text-[10px] text-faint">{adv.date}</span>
                                {/if}
                              </div>
                              {#if adv.name}
                                <p class="text-xs text-ink mb-1">{adv.name}</p>
                              {/if}
                              {#if adv.description && adv.description !== vuln.description}
                                <p class="text-xs text-muted leading-relaxed">{adv.description}</p>
                              {/if}
                              {#if adv.link}
                                <a
                                  href={adv.link}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  class="inline-flex items-center gap-1 mt-1.5 text-[11px] text-muted hover:text-primary transition-colors"
                                  onclick={(e) => e.stopPropagation()}
                                >
                                  Open full advisory
                                  <span aria-hidden="true">↗</span>
                                </a>
                              {/if}
                            </div>
                          {/each}
                        </div>
                      </div>
                    {/if}

                    <!-- Actions -->
                    <div class="flex flex-wrap items-center gap-2 pt-1">
                      <button
                        type="button"
                        class="px-2.5 py-1 text-[11px] rounded-md bg-elevated text-ink hover:bg-elevated hover:text-ink transition-colors"
                        onclick={(e) => { e.stopPropagation(); onCopy(vuln); }}
                      >
                        Copy report
                      </button>
                      {#if group.package_link}
                        <a
                          href={group.package_link}
                          target="_blank"
                          rel="noopener noreferrer"
                          class="px-2.5 py-1 text-[11px] rounded-md border border-line text-muted hover:text-ink hover:border-zinc-500 transition-colors"
                          onclick={(e) => e.stopPropagation()}
                        >
                          WordPress.org page ↗
                        </a>
                      {/if}
                      {#if vuln.primary_cves?.[0]?.link}
                        <a
                          href={vuln.primary_cves[0].link}
                          target="_blank"
                          rel="noopener noreferrer"
                          class="px-2.5 py-1 text-[11px] rounded-md border border-sky-500/30 text-sky-700 dark:text-sky-300/90 hover:bg-sky-500/10 transition-colors"
                          onclick={(e) => e.stopPropagation()}
                        >
                          {vuln.primary_cves[0].id} ↗
                        </a>
                      {/if}
                    </div>
                  </div>
                {/if}
              </div>
            {/each}
          </div>
        {/if}
      </div>
    {/each}
  </div>
{/if}
