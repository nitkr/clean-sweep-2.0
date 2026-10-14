import { writable } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { scanning } from './scanning.js';

function emptySignature() {
  return {
    status: '',
    phase: '',
    files: 0,
    dbRows: 0,
    matches: 0,
    lastFile: '',
    readingFile: '',
    lastDbTable: '',
    lastDbId: '',
    lastDbKey: '',
    packageNote: '',
    idleSince: 0,
    resuming: false,
  };
}

function emptySections() {
  return { undecided: false, safe: false };
}

const initial = {
  loaded: false,
  provider: '',
  hasKey: false,
  keyHint: '',
  model: '',
  tested: false,
  requestLogs: true,
  fullPass: false,
  prefixes: [],
  models: [],
  message: '',
  error: '',
  aiStatus: 'idle',
  phase: 'idle',
  done: 0,
  total: 0,
  logCount: 0,
  logs: '',
  filesSentAfter: 0,
  notice: '',
  detail: '',
  usage: null,
  waiting: 0,
  waitingPaths: [],
  followup: { step: 0, index: 0, total: 0, path: '' },
  running: false,
  log: [],
  report: null,
  openedKey: '',
  sections: emptySections(),
  signature: emptySignature(),
};

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function createAiScan() {
  const { subscribe, update } = writable({ ...initial });
  let stopLoop = false;
  let scanId = '';
  let loopToken = 0;

  function currentState() {
    let snap = null;
    const unsub = subscribe((value) => { snap = value; });
    unsub();
    return snap;
  }

  function applyConfig(data) {
    update((s) => ({
      ...s,
      loaded: true,
      provider: data.provider || '',
      hasKey: !!data.has_key,
      keyHint: data.key_hint || '',
      model: data.model || '',
      tested: !!data.tested,
      requestLogs: data.request_logs !== false,
      fullPass: !!data.full_pass,
      prefixes: Array.isArray(data.prefixes) ? data.prefixes : s.prefixes,
    }));
  }

  function applyStatus(data) {
    update((s) => ({
      ...s,
      aiStatus: data.ai_status || 'idle',
      phase: data.phase || 'idle',
      done: data.done || 0,
      total: data.total || 0,
      logCount: data.log_count || 0,
      logs: data.logs || '',
      filesSentAfter: data.files_sent_after || 0,
      error: data.error || '',
      notice: data.notice || '',
      detail: data.detail || '',
      usage: data.usage && typeof data.usage === 'object' ? data.usage : null,
      waiting: Number(data.waiting || 0),
      waitingPaths: Array.isArray(data.waiting_paths) ? data.waiting_paths : [],
      followup: data.followup && typeof data.followup === 'object'
        ? {
          step: Number(data.followup.step || 0),
          index: Number(data.followup.index || 0),
          total: Number(data.followup.total || 0),
          path: String(data.followup.path || ''),
        }
        : { step: 0, index: 0, total: 0, path: '' },
    }));
    if (data.scan_id) scanId = data.scan_id;
  }

  function followupLine(followup) {
    const names = [
      'Checking WordPress drop-in files',
      'Checking .htaccess and PHP settings',
      'Checking scheduled tasks',
      'Checking administrator accounts',
      'Checking other code in malware folders',
      'Checking files this malware includes or writes',
    ];
    const step = Number(followup?.step || 0);
    const name = names[step] || 'Running follow-up checks';
    const total = Number(followup?.total || 0);
    const index = Number(followup?.index || 0);
    if (total < 1) return name;
    const current = Math.min(total, index < total ? index + 1 : index);
    return `${name}, ${current} of ${total}`;
  }

  function phaseLabel(phase, done, total, followup) {
    if (phase === 'signature') return `Signature scan, ${done} files checked`;
    if (phase === 'queue') return 'Choosing files for AI';
    if (phase === 'review') return `AI review, ${done} of ${total} files`;
    if (phase === 'logs') return 'Reading request logs';
    if (phase === 'followup') return followupLine(followup);
    if (phase === 'report') return 'Writing the report';
    return 'Working';
  }

  return {
    subscribe,
    phaseLabel,
    async load() {
      const cfg = await adapters.ai.getConfig();
      if (cfg?.success && cfg.data) applyConfig(cfg.data);
      const st = await adapters.ai.status();
      if (st?.success && st.data) {
        applyStatus(st.data);
        if (st.data.ai_status === 'running') {
          stopLoop = false;
          update((s) => ({ ...s, running: true }));
          this.loop();
        } else if (st.data.ai_status === 'completed' || st.data.ai_status === 'error' || st.data.ai_status === 'paused') {
          await this.loadReport();
          await this.refreshLog();
        }
      }
    },
    async saveConfig({ provider, key, model }) {
      const resp = await adapters.ai.saveConfig({ provider, key, model });
      if (!resp?.success) {
        update((s) => ({ ...s, error: resp?.error || 'Could not save.', tested: false }));
        return false;
      }
      applyConfig(resp.data);
      update((s) => ({ ...s, error: '', models: model ? s.models : [] }));
      return true;
    },
    async loadModels() {
      const resp = await adapters.ai.listModels();
      if (!resp?.success) {
        update((s) => ({ ...s, error: resp?.error || 'Could not load models.' }));
        return;
      }
      update((s) => ({ ...s, models: resp.data?.models || [], error: '' }));
    },
    async test() {
      const resp = await adapters.ai.test();
      if (!resp?.success) {
        const detail = resp?.error || 'The provider did not return a completion.';
        const error = detail.indexOf('Test failed') === 0 ? detail : `Test failed. ${detail}`;
        update((s) => ({ ...s, tested: false, error }));
        return false;
      }
      applyConfig(resp.data);
      update((s) => ({ ...s, error: '' }));
      return true;
    },
    async savePolicy(patch) {
      const resp = await adapters.ai.savePolicy(patch);
      if (!resp?.success) {
        update((s) => ({ ...s, error: resp?.error || 'Could not save that choice.' }));
        return false;
      }
      applyConfig(resp.data);
      update((s) => ({ ...s, error: '' }));
      return true;
    },
    async start() {
      stopLoop = false;
      const existing = await adapters.ai.status();
      if (existing?.success && existing.data?.ai_status === 'running' && existing.data?.scan_id) {
        scanId = existing.data.scan_id;
        applyStatus(existing.data);
        update((s) => ({
          ...s,
          running: true,
          error: '',
          report: null,
          log: [],
          openedKey: '',
          sections: emptySections(),
          signature: emptySignature(),
        }));
        this.loop();
        return;
      }
      update((s) => ({
        ...s,
        running: true,
        error: '',
        report: null,
        log: [],
        openedKey: '',
        sections: emptySections(),
        signature: emptySignature(),
      }));
      const latest = await adapters.malware.getLatestScan(172800);
      const active = latest?.data?.scan;
      if (active && ['running', 'paused', 'pending', 'initializing'].includes(active.status)) {
        update((s) => ({
          ...s,
          running: false,
          error: 'A signature scan is already running on the Scanner page. Finish or cancel it there first.',
        }));
        return;
      }
      const started = await adapters.malware.start('standard', {
        initiated_by: 'ai_scan',
        fresh_scan: true,
      });
      if (!started?.success || !started.data?.scan_id) {
        update((s) => ({ ...s, running: false, error: started?.error || 'The signature pass did not start.' }));
        return;
      }
      scanId = started.data.scan_id;
      const begun = await adapters.ai.begin(scanId);
      if (!begun?.success) {
        update((s) => ({ ...s, running: false, error: begun?.error || 'The AI pass did not start.' }));
        return;
      }
      applyStatus(begun.data);
      this.loop();
    },
    async resume() {
      stopLoop = false;
      update((s) => ({ ...s, running: true, error: '' }));
      const resp = await adapters.ai.resume();
      if (!resp?.success) {
        update((s) => ({ ...s, running: false, error: resp?.error || 'Could not continue this scan.' }));
        return;
      }
      applyStatus(resp.data || {});
      if (resp.data?.ai_status !== 'running') {
        update((s) => ({ ...s, running: false }));
        return;
      }
      this.loop();
    },
    async loop() {
      const token = ++loopToken;
      let lastStamp = '';
      let lastChange = Date.now();
      let progressSeen = '';
      const mine = () => token === loopToken && !stopLoop;
      while (mine()) {
        const current = currentState();
        if (current.phase === 'signature' && scanId) {
          const mal = await adapters.malware.getStatus(scanId);
          if (!mine()) break;
          const data = mal?.data || {};
          const st = data.status;
          const stamp = [
            st || '',
            data.counters?.files_scanned ?? 0,
            data.phase || '',
            data.last_updated || '',
          ].join('|');
          if (stamp !== lastStamp) {
            lastStamp = stamp;
            lastChange = Date.now();
          }
          const files = Number(data.counters?.files_scanned || 0);
          const dbRows = Number(data.counters?.db_rows_scanned || 0);
          const matches = Number(data.counters?.malware_threats || 0);
          const readingFile = data.reading_file || '';
          const lastFile = data.last_file_path || '';
          const progressKey = [
            files,
            dbRows,
            matches,
            readingFile,
            lastFile,
            data.phase || '',
            data.package_checksum_note || '',
            data.last_db_table || '',
            data.last_db_id || '',
          ].join('|');
          let idleSince = current.signature?.idleSince || Date.now();
          let resuming = !!current.signature?.resuming;
          if (progressKey !== progressSeen) {
            progressSeen = progressKey;
            idleSince = Date.now();
            resuming = false;
          }
          const stale = st === 'running' && Date.now() - lastChange >= 20000;
          const willResume = ['paused', 'pending', 'initializing'].includes(st) || stale;
          if (willResume) resuming = true;
          update((s) => ({
            ...s,
            signature: {
              status: st || '',
              phase: data.phase || '',
              files,
              dbRows,
              matches,
              lastFile,
              readingFile,
              lastDbTable: data.last_db_table || '',
              lastDbId: data.last_db_id ?? '',
              lastDbKey: data.last_db_key || '',
              packageNote: data.package_checksum_note || '',
              idleSince,
              resuming,
            },
          }));
          if (willResume) {
            await adapters.malware.resume(scanId);
            if (!mine()) break;
          }
          if (st && !['completed', 'failed', 'cancelled'].includes(st)) {
            const step = await adapters.ai.start(scanId);
            if (!mine()) break;
            if (!step?.success) {
              update((s) => ({ ...s, running: false, error: step?.error || 'The AI pass stopped.' }));
              return;
            }
            if (step.data?.busy) {
              await wait(1000);
              continue;
            }
            applyStatus(step.data);
            if (['error', 'cancelled', 'paused'].includes(step.data?.ai_status)) {
              update((s) => ({ ...s, running: false }));
              if (step.data.ai_status === 'paused' || step.data.ai_status === 'error') {
                await this.loadReport();
              }
              await this.refreshLog();
              return;
            }
            if ((step.data?.phase || '') !== 'signature') {
              update((s) => ({ ...s, signature: emptySignature() }));
            }
            await this.refreshLog();
            await wait(1500);
            continue;
          }
        }
        const step = await adapters.ai.start(scanId);
        if (!mine()) break;
        if (!step?.success) {
          update((s) => ({ ...s, running: false, error: step?.error || 'The AI pass stopped.' }));
          return;
        }
        if (step.data?.busy) {
          await wait(1000);
          continue;
        }
        applyStatus(step.data);
        if (['completed', 'error', 'cancelled', 'paused'].includes(step.data?.ai_status)) {
          update((s) => ({ ...s, running: false }));
          if (step.data.ai_status === 'completed' || step.data.ai_status === 'error' || step.data.ai_status === 'paused') {
            await this.loadReport();
          }
          await this.refreshLog();
          return;
        }
        await this.refreshLog();
      }
      if (token === loopToken) {
        update((s) => ({ ...s, running: false }));
      }
    },
    async cancel() {
      stopLoop = true;
      if (scanId) {
        await adapters.malware.cancel(scanId).catch(() => {});
      }
      const resp = await adapters.ai.cancel();
      if (resp?.success) applyStatus(resp.data);
      update((s) => ({ ...s, running: false, error: 'Cancelled. No report.' }));
    },
    async refreshLog() {
      const resp = await adapters.ai.getLog(0, 500);
      if (resp?.success) {
        update((s) => ({
          ...s,
          log: resp.data?.rows || [],
          logCount: resp.data?.total ?? s.logCount,
        }));
      }
    },
    async loadReport() {
      const resp = await adapters.ai.getReport();
      if (resp?.success) {
        update((s) => ({ ...s, report: resp.data }));
      }
    },
    async keep(path) {
      const resp = await adapters.ai.keep(path);
      if (resp?.success && resp.data?.scan_id) {
        update((s) => ({ ...s, report: resp.data }));
      }
    },
    async sendFile(path) {
      const resp = await adapters.ai.sendFile(path);
      if (!resp?.success) {
        update((s) => ({ ...s, error: resp?.error || 'Could not send that file.' }));
        return;
      }
      update((s) => ({ ...s, report: resp.data, error: '' }));
      await this.refreshLog();
    },
    setSection(name, open) {
      update((s) => ({
        ...s,
        sections: { ...s.sections, [name]: !!open },
      }));
    },
    openPath(row) {
      const path = row.path || row.file || '';
      update((s) => ({ ...s, openedKey: path }));
      scanning.selectThreat({
        source: 'file',
        file: row.file || row.path,
        path: row.path,
        line_number: row.line_number || null,
        match: row.summary || row.match || '',
        pattern: row.pattern || '',
        signature_id: row.pattern || 'ai',
      });
    },
    openDatabase(row) {
      update((s) => ({
        ...s,
        openedKey: `db:${row.table || ''}:${row.row_id ?? ''}:${row.column || ''}`,
      }));
      scanning.selectThreat({
        source: 'database',
        table: row.table,
        row_id: row.row_id,
        column: row.column,
        pattern: row.pattern,
        match: row.pattern,
      });
    },
  };
}

export const aiScan = createAiScan();
