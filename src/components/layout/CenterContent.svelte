<script>
  import { app } from '../../lib/stores/app.js';
  import { files, toSiteRelativePath, threatToSiteRelativePath } from '../../lib/stores/files.js';
  import { scanning } from '../../lib/stores/scanning.js';
  import { adapters } from '../../lib/adapter-registry.ts';
  import ConfirmDialog from '../common/ConfirmDialog.svelte';

  console.log('🔍 [CenterContent] Component loaded, activeTab:', $app.activeTab);

  let noticeDialog = $state({ open: false, title: '', message: '' });

  // Import all tab components
  import Dashboard from '../Dashboard.svelte';
  import CoreTab from '../tabs/CoreTab.svelte';
  import ExtensionsTab from '../tabs/ExtensionsTab.svelte';
  import SecurityTab from '../tabs/SecurityTab.svelte';
  import IntegrityTab from '../tabs/IntegrityTab.svelte';
  import UsersTab from '../tabs/UsersTab.svelte';
  import CronTab from '../tabs/CronTab.svelte';
  import UploadTab from '../tabs/UploadTab.svelte';
  import CleanupTab from '../tabs/CleanupTab.svelte';

  // Single-pane code viewer (current file only — no original/diff)
  let showEditor = $state(false);
  let selectedFile = $state(null);
  let currentCode = $state([]);
  let infectedLine = $state(-1);
  /** @type {Array<{line:number,start:number,end:number}>} */
  let matchRanges = $state([]);
  let matchSnippet = $state('');
  let matchLocated = $state(false);
  /** @type {'match' | 'diff' | 'extra'} */
  let highlightKind = $state('match');
  let editorError = $state(null);
  /** @type {'file' | 'database' | null} */
  let editorMode = $state(null);

  // Guards so $effect never re-loads / re-writes the same content in a loop
  let lastThreatKey = $state('');
  let lastAppliedContentKey = $state('');
  let lastExplorerContentKey = $state('');
  let lastOfficialKey = $state('');

  // Reference to code panel for scrolling
  let currentCodePanel = $state(null);

  function pathsMatch(a, b) {
    const ra = toSiteRelativePath(a || '');
    const rb = toSiteRelativePath(b || '');
    if (!ra || !rb) return false;
    return ra === rb || ra.endsWith(rb) || rb.endsWith(ra);
  }

  function threatKey(t) {
    if (!t) return '';
    return [
      t.id || '',
      t.source || '',
      t.file || t.path || '',
      t.line_number || '',
      t.table || '',
      t.row_id || '',
      t.column || '',
    ].join('|');
  }

  function detectSuspiciousLine(lines, threat) {
    if (threat?.line_number) {
      return Number(threat.line_number) || -1;
    }
    const needle = String(threat?.matched_content || threat?.match || '').split(/\r?\n/)[0].slice(0, 80);
    if (needle) {
      const idx = lines.findIndex((line) => line.includes(needle));
      if (idx >= 0) return idx + 1;
    }
    const auto = lines.findIndex((line) =>
      line.includes('eval(') ||
      line.includes('base64_decode') ||
      line.includes('shell_exec') ||
      line.includes('system(') ||
      (line.includes('preg_replace') && line.includes('/e')) ||
      line.includes('assert(')
    );
    return auto >= 0 ? auto + 1 : -1;
  }

  function needlesFromThreat(threat) {
    const out = [];
    for (const raw of [threat?.matched_content, threat?.match]) {
      if (!raw) continue;
      const s = String(raw);
      if (s) out.push(s);
      const first = s.split(/\r?\n/)[0];
      if (first && first !== s) out.push(first);
      if (s.length > 32) out.push(s.slice(0, 80));
    }
    return [...new Set(out)].filter((n) => n && n.length >= 3).sort((a, b) => b.length - a.length);
  }

  function locateMatch(text, threat) {
    if (!text || !threat) return null;
    for (const needle of needlesFromThreat(threat)) {
      const tries = [needle, needle.replace(/\r\n/g, '\n')];
      for (const n of tries) {
        let i = text.indexOf(n);
        if (i < 0) i = text.toLowerCase().indexOf(n.toLowerCase());
        if (i >= 0) return { start: i, end: i + n.length, needle: n };
      }
    }
    return null;
  }

  function rangesForMatch(text, start, end) {
    const lines = String(text).split('\n');
    const ranges = [];
    let pos = 0;
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      const lineStart = pos;
      const lineEnd = pos + line.length;
      if (end > lineStart && start < lineEnd) {
        ranges.push({
          line: i + 1,
          start: Math.max(0, start - lineStart),
          end: Math.min(line.length, end - lineStart),
        });
      }
      pos = lineEnd + 1;
    }
    return ranges;
  }

  function rangeOnLine(lineNo) {
    return matchRanges.find((r) => r.line === lineNo) || null;
  }

  function isChecksumThreat(t) {
    if (!t) return false;
    return !!(
      t.checksum
      || t.pattern === 'checksum_mismatch'
      || t.pattern === 'unexpected_core_php'
      || t.pattern === 'unexpected_package_php'
    );
  }

  function isExtraChecksum(t) {
    return t?.pattern === 'unexpected_core_php'
      || t?.pattern === 'unexpected_package_php'
      || t?.type === 'extra';
  }

  function applyChecksumHighlights(currentLines, officialText, extra, version) {
    highlightKind = extra ? 'extra' : 'diff';
    if (extra) {
      matchRanges = currentLines.slice(0, 400).map((line, i) => ({
        line: i + 1,
        start: 0,
        end: String(line || '').length,
      }));
      infectedLine = 1;
      matchSnippet = 'This file is not in the official wordpress.org package.';
      matchLocated = true;
      if (infectedLine > 0) app.setScrollToLine(infectedLine);
      return;
    }
    const bag = new Map();
    for (const raw of String(officialText).split('\n')) {
      const n = raw.replace(/\r$/, '');
      bag.set(n, (bag.get(n) || 0) + 1);
    }
    const nums = [];
    currentLines.forEach((raw, i) => {
      const n = String(raw).replace(/\r$/, '');
      const left = bag.get(n) || 0;
      if (left > 0) bag.set(n, left - 1);
      else nums.push(i + 1);
    });
    const shown = nums.slice(0, 400);
    matchRanges = shown.map((line) => ({
      line,
      start: 0,
      end: String(currentLines[line - 1] || '').length,
    }));
    infectedLine = shown[0] || -1;
    const ver = version ? ` (${version})` : '';
    if (nums.length === 0) {
      matchSnippet = `Hashes differ from wordpress.org${ver}, but every line also appears in the official file (often line endings).`;
      matchLocated = false;
    } else {
      matchSnippet = `${nums.length} line${nums.length === 1 ? '' : 's'} differ from the official wordpress.org file${ver}${nums.length > 400 ? ' (showing first 400)' : ''}.`;
      matchLocated = true;
    }
    if (infectedLine > 0) app.setScrollToLine(infectedLine);
  }

  function applyMatchHighlight(text, threat) {
    highlightKind = 'match';
    matchSnippet = String(threat?.matched_content || threat?.match || '').replace(/\s+/g, ' ').slice(0, 160);
    const loc = locateMatch(text, threat);
    if (loc) {
      matchRanges = rangesForMatch(text, loc.start, loc.end);
      infectedLine = threat?.line_number ? Number(threat.line_number) : (matchRanges[0]?.line ?? -1);
      if (matchRanges.length && !matchRanges.some((r) => r.line === infectedLine)) {
        infectedLine = matchRanges[0].line;
      }
      matchLocated = true;
      if (infectedLine > 0) app.setScrollToLine(infectedLine);
      return;
    }
    matchRanges = [];
    infectedLine = detectSuspiciousLine(String(text).split('\n'), threat);
    matchLocated = infectedLine > 0;
    if (infectedLine > 0) app.setScrollToLine(infectedLine);
  }

  function displayPath(file) {
    if (!file) return '';
    if (file.source === 'database') {
      return `Database · ${file.table || '?'} #${file.row_id ?? '?'} (${file.column || '?'})`;
    }
    return threatToSiteRelativePath(file) || toSiteRelativePath(file.path || file.file || '') || file.path || file.file || '';
  }

  function contextHint(file) {
    if (!file) return null;
    if (file.source === 'database') {
      return 'Database content. Clean via DB tools if malicious. This is not a file reinstall.';
    }
    const rel = (threatToSiteRelativePath(file) || toSiteRelativePath(file.path || file.file || '') || '').toLowerCase();
    if (rel.includes('wp-content/uploads/') || rel.startsWith('uploads/')) {
      return 'Upload path. No package original. Inspect and delete if malicious.';
    }
    if (rel.includes('wp-content/plugins/')) {
      return 'Plugin file. If infected, prefer Plugin reinstall over hand-editing.';
    }
    if (rel.includes('wp-content/themes/')) {
      return 'Theme file. If infected, prefer Theme reinstall over hand-editing.';
    }
    if (
      rel.includes('wp-admin/')
      || rel.includes('wp-includes/')
      || /^(wp-load|wp-settings|wp-login|wp-config-sample|xmlrpc|index)\.php$/.test(rel)
    ) {
      return 'Core path. If infected, prefer Core reinstall over hand-editing.';
    }
    return null;
  }

  function applyFileContent(content, threat = null) {
    const text = content?.content ?? '';
    const lines = String(text).split('\n');
    currentCode = lines;
    editorError = null;
    editorMode = 'file';
    if (threat && isChecksumThreat(threat)) {
      if (isExtraChecksum(threat)) {
        applyChecksumHighlights(lines, '', true);
      } else {
        highlightKind = 'diff';
        matchRanges = [];
        matchLocated = false;
        infectedLine = -1;
        matchSnippet = 'Comparing to the official wordpress.org file…';
      }
    } else if (threat) {
      highlightKind = 'match';
      applyMatchHighlight(text, threat);
    } else {
      matchRanges = [];
      matchSnippet = '';
      matchLocated = false;
      highlightKind = 'match';
      infectedLine = -1;
    }
    lastAppliedContentKey = `${content?.path || ''}|${lines.length}|${threatKey(threat)}`;
  }

  // Explorer: open file when selected (not while viewing a malware threat)
  $effect(() => {
    if ($scanning.selectedThreat) return;
    if (!$files.selectedFile || !$files.currentContent?.content) return;

    const key = `${$files.selectedFile.path}|${$files.currentContent.path}|${$files.currentContent.lineCount || 0}`;
    if (key === lastExplorerContentKey) return;
    lastExplorerContentKey = key;

    showEditor = true;
    editorMode = 'file';
    selectedFile = $files.selectedFile;
    applyFileContent($files.currentContent, null);
  });

  // Scanner: when a threat is selected, open editor and load the file once
  $effect(() => {
    const onScanner =
      $app.activeTab === 'scanner' || $app.activeTab === 'security';
    const threat = $scanning.selectedThreat;
    if (!onScanner || !threat) return;

    const key = threatKey(threat);
    showEditor = true;
    editorError = null;

    if (threat.source === 'database') {
      editorMode = 'database';
      const pathLabel = `DB ${threat.table || ''} #${threat.row_id || ''} (${threat.column || ''})`;
      selectedFile = {
        name: pathLabel,
        path: pathLabel,
        ...threat,
      };
      if (key !== lastThreatKey) {
        lastThreatKey = key;
        lastAppliedContentKey = '';
      }
      const dbContent = $scanning.selectedDbContent?.content;
      if (dbContent != null && dbContent !== '') {
        const contentKey = `db|${key}|${String(dbContent).length}`;
        if (contentKey !== lastAppliedContentKey) {
          const text = String(dbContent);
          currentCode = text.split('\n');
          applyMatchHighlight(text, threat);
          lastAppliedContentKey = contentKey;
        }
      } else if (key === lastThreatKey && !dbContent) {
        currentCode = ['// Loading database content…'];
        matchRanges = [];
        matchLocated = false;
      }
      return;
    }

    editorMode = 'file';
    const relPath = threatToSiteRelativePath(threat);
    const displayPath = relPath || threat.path || threat.file || '';
    selectedFile = {
      name: displayPath.split('/').pop() || 'file',
      path: displayPath,
      risk_level: threat.risk_level || threat.threat_level,
      infected: true,
      ...threat,
      path: displayPath,
    };

    if (key === lastThreatKey) return;
    lastThreatKey = key;
    lastAppliedContentKey = '';

    if (!relPath) {
      editorError = 'This finding has no file path to open.';
      currentCode = ['// No file path on this threat'];
      return;
    }

    currentCode = [`// Loading ${relPath}…`];
    infectedLine = threat.line_number ? Number(threat.line_number) : -1;

    files.loadFile(relPath, threat.line_number || null);
  });

  // Apply file content once it arrives for the active threat
  $effect(() => {
    const threat = $scanning.selectedThreat;
    if (!threat || threat.source === 'database') return;
    const content = $files.currentContent;
    if (!content?.content) return;

    const threatPath = threatToSiteRelativePath(threat) || threat.path || threat.file || '';
    if (content.path && threatPath && !pathsMatch(content.path, threatPath)) {
      return;
    }

    const applyKey = `${content.path}|${content.lineCount || content.content.length}|${threatKey(threat)}`;
    if (applyKey === lastAppliedContentKey) return;

    showEditor = true;
    applyFileContent(content, threat);
    if (threat.line_number && !isChecksumThreat(threat)) {
      app.setScrollToLine(Number(threat.line_number));
    }
  });

  // Checksum: fetch official file and mark lines that are not in it
  $effect(() => {
    const threat = $scanning.selectedThreat;
    if (!threat || threat.source === 'database' || !isChecksumThreat(threat) || isExtraChecksum(threat)) {
      return;
    }
    const content = $files.currentContent;
    if (!content?.content) return;
    const threatPath = threatToSiteRelativePath(threat) || threat.path || threat.file || '';
    if (content.path && threatPath && !pathsMatch(content.path, threatPath)) return;

    const key = `${threatKey(threat)}|${content.path}|${content.content.length}`;
    if (key === lastOfficialKey) return;
    lastOfficialKey = key;

    const rel = threatPath;
    adapters.files.getOriginalContent(rel, {
      package_type: threat.package_type || '',
      package_slug: threat.package_slug || '',
      version: threat.package_version || threat.version || '',
    }).then((res) => {
      if (threatKey($scanning.selectedThreat) !== threatKey(threat)) return;
      const lines = String(content.content).split('\n');
      if (res.success && res.data?.content != null) {
        applyChecksumHighlights(lines, res.data.content, false, res.data.version);
      } else {
        highlightKind = 'diff';
        matchRanges = [];
        matchLocated = false;
        matchSnippet = res.error || 'Could not download the official file to compare.';
      }
    }).catch(() => {
      if (threatKey($scanning.selectedThreat) !== threatKey(threat)) return;
      matchSnippet = 'Could not download the official file to compare.';
      matchLocated = false;
    });
  });

  // Surface load errors without leaving a blank editor
  $effect(() => {
    if (!$scanning.selectedThreat) return;
    if ($files.loadingContent) return;
    if ($files.currentContent?.content) return;
    if (!$files.error) return;
    if (!$files.selectedFile) return;

    const msg = $files.error;
    if (editorError === msg && currentCode.length > 1) return;
    editorError = msg;
    const p = $files.selectedFile.path || '';
    currentCode = [`// Failed to load file: ${p}`, `// ${msg}`];
  });

  // Hide editor when leaving Scanner without an explorer file
  $effect(() => {
    const onScanner =
      $app.activeTab === 'scanner' || $app.activeTab === 'security';
    if (!$files.selectedFile && !$scanning.selectedThreat && !onScanner) {
      showEditor = false;
      selectedFile = null;
    }
  });

  // Scroll to the match once content is painted
  $effect(() => {
    const lineToScroll = $app.scrollToLine;
    if (lineToScroll && currentCodePanel && showEditor) {
      const panel = currentCodePanel;
      requestAnimationFrame(() => {
        const hit = panel.querySelector('[data-match-line]');
        if (hit) {
          hit.scrollIntoView({ block: 'center', behavior: 'smooth' });
        } else {
          panel.scrollTo({
            top: Math.max(0, (lineToScroll - 3) * 24),
            behavior: 'smooth',
          });
        }
      });
      app.clearScrollToLine();
    }
  });

  async function handleSave() {
    if (editorMode === 'database') {
      noticeDialog = {
        open: true,
        title: 'Read-only',
        message: 'Database content is read-only in this viewer.',
      };
      return;
    }
    if (selectedFile?.path && $files.currentContent?.content) {
      const result = await files.saveContent(
        toSiteRelativePath(selectedFile.path),
        $files.currentContent.content
      );
      if (result) {
        noticeDialog = {
          open: true,
          title: 'File saved',
          message: result.backup_path
            ? `Backup created at: ${result.backup_path}`
            : 'File saved successfully.',
        };
      }
    }
  }

  function closeEditor() {
    showEditor = false;
    selectedFile = null;
    editorError = null;
    editorMode = null;
    lastThreatKey = '';
    lastAppliedContentKey = '';
    lastExplorerContentKey = '';
    lastOfficialKey = '';
    currentCode = [];
    infectedLine = -1;
    matchRanges = [];
    matchSnippet = '';
    matchLocated = false;
    highlightKind = 'match';
    files.clearSelection();
    scanning.selectThreat(null);
  }

  let isThreatView = $derived(!!$scanning.selectedThreat);
  let headerPath = $derived(displayPath(selectedFile));
  let hint = $derived(contextHint(selectedFile));
  let severity = $derived(
    (selectedFile?.risk_level || selectedFile?.threat_level || '').toLowerCase()
  );
</script>

<!-- Main Content Area -->
<div class="h-full overflow-hidden">
  {#if !showEditor}
    <!-- Tab Content -->
    <div class="h-full overflow-auto">
      {#if $app.activeTab === 'dashboard'}
        <Dashboard />
      {:else if $app.activeTab === 'core'}
        <CoreTab />
      {:else if $app.activeTab === 'plugins'}
        <ExtensionsTab />
      {:else if $app.activeTab === 'scanner'}
        <SecurityTab />
      {:else if $app.activeTab === 'security'}
        <IntegrityTab />
      {:else if $app.activeTab === 'users'}
        <UsersTab />
      {:else if $app.activeTab === 'cron'}
        <CronTab />
      {:else if $app.activeTab === 'upload'}
        <UploadTab />
      {:else if $app.activeTab === 'cleanup'}
        <CleanupTab />
      {:else}
        <Dashboard />
      {/if}
    </div>
  {:else}
    <!-- Single-pane file viewer (current content only) -->
    <div class="h-full flex flex-col">
      <!-- Header -->
      <div class="min-h-9 flex flex-wrap items-center px-4 py-2 bg-elevated border-b border-line gap-3 flex-shrink-0">
        <div class="flex items-center gap-2 flex-1 min-w-0">
          {#if severity === 'critical' || selectedFile?.infected}
            <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse flex-shrink-0"></span>
          {:else if severity === 'high' || severity === 'warning'}
            <span class="w-2 h-2 rounded-full bg-orange-500 flex-shrink-0"></span>
          {:else if isThreatView}
            <span class="w-2 h-2 rounded-full bg-yellow-500 flex-shrink-0"></span>
          {:else}
            <span class="w-2 h-2 rounded-full bg-blue-500 flex-shrink-0"></span>
          {/if}
          <div class="min-w-0">
            <div class="text-xs text-ink font-medium truncate">
              {selectedFile?.name || 'Viewing file'}
            </div>
            {#if headerPath && headerPath !== selectedFile?.name}
              <div class="text-[10px] text-muted font-mono truncate" title={headerPath}>
                {headerPath}
              </div>
            {/if}
          </div>
          {#if severity}
            <span class="text-[10px] uppercase font-semibold px-1.5 py-0.5 rounded border flex-shrink-0
              {severity === 'critical' ? 'text-red-700 dark:text-red-400 border-red-500/30 bg-red-500/10'
                : severity === 'high' || severity === 'warning' ? 'text-orange-700 dark:text-orange-300 border-orange-500/30 bg-orange-500/10'
                : severity === 'medium' ? 'text-yellow-700 dark:text-yellow-300 border-yellow-500/30 bg-yellow-500/10'
                : 'text-blue-700 dark:text-blue-300 border-blue-500/30 bg-blue-500/10'}">
              {severity}
            </span>
          {/if}
          {#if infectedLine > 0}
            <span class="text-[10px] font-mono text-muted flex-shrink-0">line {infectedLine}</span>
          {/if}
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
          <button
            type="button"
            onclick={closeEditor}
            class="text-xs text-muted hover:text-ink flex items-center gap-1"
            title={isThreatView ? ($scanning.scanning ? 'Back to scan' : 'Back to scan results') : 'Close file'}
          >
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12"/>
            </svg>
            {isThreatView ? ($scanning.scanning ? 'Back to scan' : 'Back to results') : 'Close'}
          </button>
          {#if editorMode === 'file' && !isThreatView}
            <button
              type="button"
              onclick={handleSave}
              class="text-xs text-muted hover:text-ink flex items-center gap-1"
              title="Save changes to disk"
            >
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
              </svg>
              Save
            </button>
          {/if}
        </div>
      </div>

      {#if editorError}
        <div class="px-4 py-2 bg-red-500/10 border-b border-red-500/30 text-xs text-red-700 dark:text-red-300">
          {editorError}
        </div>
      {/if}

      {#if hint}
        <div class="px-4 py-1.5 bg-panel border-b border-line text-[11px] text-muted">
          {hint}
        </div>
      {/if}

      {#if isThreatView && matchSnippet}
        <div class="px-4 py-1.5 border-b text-[11px] {highlightKind === 'match'
          ? 'bg-red-50 dark:bg-red-950/30 border-red-500/20 text-red-900 dark:text-red-200'
          : 'bg-violet-50 dark:bg-violet-950/30 border-violet-500/20 text-violet-950 dark:text-violet-100'}">
          <span class="font-semibold">
            {#if highlightKind === 'extra'}
              Extra file
            {:else if highlightKind === 'diff'}
              {matchLocated ? 'Changed vs official' : 'Checksum'}
            {:else}
              {matchLocated ? 'Matched' : 'Reported match'}
            {/if}
          </span>
          <span class="font-mono ml-1.5 break-all">{matchSnippet}</span>
          {#if highlightKind === 'match' && !matchLocated}
            <span class="text-muted ml-1.5">Not found as a contiguous span in this row (it may have been unpacked for scanning).</span>
          {/if}
        </div>
      {/if}

      <!-- Single code panel -->
      <div class="flex-1 flex flex-col min-h-0 min-w-0 bg-panel">
        <div class="h-8 flex items-center justify-between px-3 bg-elevated border-b border-line flex-shrink-0">
          <span class="text-xs text-muted flex items-center gap-1.5">
            <svg class="w-3 h-3 text-sky-600 dark:text-sky-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            {editorMode === 'database' ? 'Database content' : 'File content'}
            {#if $files.loadingContent}
              <span class="text-faint">· loading…</span>
            {/if}
          </span>
          {#if currentCode.length > 0 && !String(currentCode[0] || '').startsWith('// Loading')}
            <span class="text-[10px] text-faint font-mono">{currentCode.length} lines</span>
          {/if}
        </div>
        <div class="flex-1 overflow-auto font-mono text-xs leading-6 bg-panel" bind:this={currentCodePanel}>
          <div class="flex min-h-full">
            <!-- Gutter: slightly distinct from code surface -->
            <div class="w-12 flex-shrink-0 text-right pr-3 text-faint bg-elevated border-r border-line pt-3 select-none sticky left-0">
              {#each currentCode as _, i}
                {@const hit = rangeOnLine(i + 1) || i === infectedLine - 1}
                <div
                  class="{editorMode === 'database' ? 'min-h-6' : 'h-6'}
                    {hit && highlightKind === 'match' ? 'text-red-700 dark:text-red-400 font-semibold' : ''}
                    {hit && highlightKind !== 'match' ? 'text-violet-700 dark:text-violet-300 font-semibold' : ''}"
                >{i + 1}</div>
              {/each}
            </div>
            <div class="flex-1 pt-3 pr-4 min-w-0 bg-panel">
              {#each currentCode as line, i}
                {@const range = rangeOnLine(i + 1)}
                {@const hit = !!range || i === infectedLine - 1}
                {@const wrap = editorMode === 'database' || hit}
                {#if hit}
                  <div
                    data-match-line={i + 1}
                    class="{wrap ? 'min-h-6' : 'h-6'} pl-2 -ml-px whitespace-pre-wrap break-all border-l-2
                      {highlightKind === 'match'
                        ? 'text-red-900 dark:text-red-100 bg-red-100 dark:bg-red-950/60 border-red-500'
                        : 'text-violet-950 dark:text-violet-100 bg-violet-100 dark:bg-violet-950/50 border-violet-500'}"
                  >{#if range && range.end > range.start}{line.slice(0, range.start)}<mark class="px-0.5 rounded-sm {highlightKind === 'match' ? 'bg-red-300/80 dark:bg-red-500/50 text-red-950 dark:text-red-50' : 'bg-violet-300/80 dark:bg-violet-500/40 text-violet-950 dark:text-violet-50'}">{line.slice(range.start, range.end) || ' '}</mark>{line.slice(range.end)}{:else}{line || ' '}{/if}</div>
                {:else}
                  <div class="{wrap ? 'min-h-6' : 'h-6'} text-ink whitespace-pre-wrap break-all">{line || ' '}</div>
                {/if}
              {/each}
            </div>
          </div>
        </div>
      </div>
    </div>
  {/if}
</div>

{#if noticeDialog.open}
  <ConfirmDialog
    open={true}
    title={noticeDialog.title}
    message={noticeDialog.message}
    confirmLabel="OK"
    alertOnly={true}
    variant="neutral"
    onConfirm={() => {
      noticeDialog = { open: false, title: '', message: '' };
    }}
    onCancel={() => {
      noticeDialog = { open: false, title: '', message: '' };
    }}
  />
{/if}
