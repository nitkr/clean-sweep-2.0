/**
 * Scanning Store
 * State and methods for malware scanning functionality
 * 
 * Uses the modular adapter architecture for API calls
 */

import { writable, derived } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { app } from './app.js';
import { errors } from '../errors.js';
import { debug } from '../debug.js';
import { files, threatToSiteRelativePath } from './files.js';
import { session } from './session.js';
import {
  loadScanPointer,
  saveScanPointer,
  clearScanPointer,
  isCompletedWithinTtl,
  formatScanAge,
  COMPLETED_TTL_SECONDS,
} from '../scanSession.js';

function createScanningStore() {
  const { subscribe, set, update } = writable({
    scanning: false,
    progressPercent: 0,
    progressMessage: 'Ready',
    progressStatus: 'idle',
    progressDetails: '',
    progressFile: null,
    results: null,
    /** Banner when results restored after refresh */
    resultsRestoredBanner: null,
    /** Unix seconds when restored/completed scan finished */
    resultsFinishedAt: null,
    error: null,
    scanDuration: 0,
    startTime: null,
    /** True after rehydrate attempted (success or not) */
    rehydrateAttempted: false,
    rehydrating: false,
    expandedThreats: [],
    scanType: 'all', // legacy unused by SecurityTab; do not send as scan_phase
    deepScan: false, // legacy toggle unused by SecurityTab
    scanFolder: '', // Path string for Deep · Specific paths
    /** Deep-only scope: full | files | database | paths */
    deepScope: 'full',
    /** Display path from server (folder_path_display) for labels after remount */
    folderPathDisplay: null,
    /**
     * Frozen label for the results strip (profile · scope) so changing the
     * profile picker after completion does not rewrite "Scan complete · …".
     * @type {string|null}
     */
    resultsScanLabel: null,
    /** Frozen Deep scope for results badges: full|files|database|paths|null */
    resultsScope: null,
    includeVulnerabilities: false, // Include vulnerability scan
    selectedThreat: null,
    selectedVulnerability: null,
    selectedDbContent: null, // Database content for threat viewing
    // Profile-based scanning
    profileId: 'standard', // Standard = recommended for investigation; Quick for shared hosts
    // Phase 2.1 - Resume / Continuation state
    isContinuation: false,
    continuationCount: 0,
    pauseReason: null,
    hasResumeData: false,
    loopbackKickCount: 0,
    executionContext: null, // 'web_sync' | 'web_fastcgi' | 'web_loopback' | 'cli_cron' | 'wp_cron'
    // Option C: Work queue visibility
    pendingWorkCount: 0, // Total pending + in-progress work units
    hasPendingWork: false, // Whether queue has pending work
    workQueueStats: null, // Raw stats from work queue
    // Live counters from the polling response. Populated on every poll
    // so the UI can show "live" file/row counts while the scan is running,
    // instead of being stuck on the previous completed scan's results
    // (or 0) until the new scan finishes.
    liveProgress: {
      files_scanned: 0,
      files_visited: 0,
      files_skipped_unchanged: 0,
      db_rows_scanned: 0,
      threats_found: 0,
      malware_threats: 0,
      integrity_violations: 0,
      items_processed: 0,
      phase: 'files',
      last_updated: 0,
      // Peak file count so DB phase still shows "Files N (done)" not "—"
      peak_files_scanned: 0,
      // Fingerprint of last real activity (counters / queue) for stuck detection
      activity_key: '',
      last_activity_at: 0,
      last_file_path: null,
      last_db_table: null,
      last_db_id: null,
      package_checksum_note: null,
    },
    /**
     * Mid-scan findings preview (malware-first). Not the completed report.
     * Populated while scanning so the user can inspect hits without waiting.
     */
    previewThreats: [],
    previewIntegrityThreats: [],
    previewPartial: false,
    previewLoading: false,
    previewError: null,
    previewTotal: 0,
    previewMalwareTotal: 0,
    previewIntegrityTotal: 0,
    previewCapped: false,
    previewAfterLine: 0,
    previewEof: false,
    previewCaughtUpAt: 0,
    previewIntegrityAttempted: false,
    previewReportFailed: false,
    previewStoppedReason: null,
    // Last displayed %; queue-based progress is allowed to drop when discovery grows
    progressHighWater: 0,
    /** 'queue' | 'phase' | '' — server says which progress formula it used */
    progressSource: '',
    // Phase 2: integrity baseline + host advisory (from start ack / status)
    hasIntegrityBaseline: false,
    likelySource: null,
    integrityNote: null,
    environmentAdvisory: null,
    restrictedHost: false,
    integrityViolations: 0,
    checksumNote: null,
    checksumChecked: 0,
    checksumFindings: 0,
    checksumVersion: null,
    // Phase 3.5: Current scan_id for API calls
    scanId: null,
  });
  
  let pollCancel = null;
  /** @type {number} epoch ms of last auto-resume nudge (client-side kick) */
  let lastAutoResumeAt = 0;
  /** @type {boolean} true while a resume request is in flight */
  let autoResumeInFlight = false;
  /** Min ms between client auto-resume nudges while status stays paused */
  let autoResumeIntervalMs = 8000;
  const AUTO_RESUME_INTERVAL_MS_MIN = 8000;
  const AUTO_RESUME_INTERVAL_MS_MAX = 45000;
  /** Consecutive host/gateway failures (504 etc.) */
  let hostErrorStreak = 0;
  /**
   * Consecutive true not_found status polls while we still think a scan is live.
   * Transient checkpoint races should not wipe the progress card; only give up
   * after several consecutive misses (~15s at 3s poll).
   */
  let notFoundStreak = 0;
  const NOT_FOUND_GIVE_UP = 5;
  /** Last time counters/queue changed (for stale-running auto-resume) */
  let lastCounterActivityAt = 0;
  let lastCounterActivityKey = '';
  /** Mid-scan preview fetch (invalidated on complete / new scan) */
  const PREVIEW_PAGE_SIZE = 50;
  const PREVIEW_CAP = 100;
  const PREVIEW_INTEGRITY_CAP = 15;
  const PREVIEW_FETCH_MIN_MS = 2500;
  const PREVIEW_MAX_PAGES = 8;
  let previewFetchGen = 0;
  let previewFetchInFlight = false;
  let lastPreviewFetchAt = 0;

  function getStateSnapshot() {
    let cur = null;
    const unsub = subscribe((state) => {
      cur = state;
    });
    if (typeof unsub === 'function') unsub();
    return cur;
  }

  function persistPointer(partial) {
    const cur = getStateSnapshot();
    const scanId = partial.scan_id || cur?.scanId;
    if (!scanId) return;
    saveScanPointer({
      scan_id: scanId,
      profile_id: partial.profile_id || cur?.profileId || 'standard',
      started_at:
        partial.started_at ||
        (cur?.startTime ? Math.floor(cur.startTime / 1000) : Math.floor(Date.now() / 1000)),
      last_status: partial.last_status || cur?.progressStatus || 'running',
      finished_at: partial.finished_at ?? cur?.resultsFinishedAt ?? null,
    });
  }

  function isIntegrityThreat(t) {
    if (!t) return false;
    return (
      t.source === 'integrity' ||
      !!t.checksum ||
      !!t.integrity ||
      t.pattern === 'checksum_mismatch' ||
      t.pattern === 'unexpected_core_php' ||
      t.pattern === 'unexpected_package_php' ||
      t.pattern === 'package_divergent' ||
      t.pattern === 'package_extras_rollup'
    );
  }

  function emptyPreviewState() {
    return {
      previewThreats: [],
      previewIntegrityThreats: [],
      previewPartial: false,
      previewLoading: false,
      previewError: null,
      previewTotal: 0,
      previewMalwareTotal: 0,
      previewIntegrityTotal: 0,
      previewCapped: false,
      previewAfterLine: 0,
      previewEof: false,
      previewCaughtUpAt: 0,
      previewIntegrityAttempted: false,
      previewReportFailed: false,
      previewStoppedReason: null,
    };
  }

  function malwareCountFromCounters(counters = {}) {
    if (typeof counters.malware_threats === 'number') {
      return Math.max(0, counters.malware_threats);
    }
    const total = Number(counters.threats_found) || 0;
    const integrity = Number(counters.integrity_violations) || 0;
    return Math.max(0, total - integrity);
  }

  function stableFallbackId(t) {
    const key = [
      t.file || t.path || '',
      t.pattern || t.signature_id || '',
      t.line_number ?? '',
      t.table || '',
      t.row_id ?? '',
      t.column || '',
      t.source || '',
    ].join('|');
    let h = 2166136261;
    for (let i = 0; i < key.length; i++) {
      h ^= key.charCodeAt(i);
      h = Math.imul(h, 16777619);
    }
    return 't_' + (h >>> 0).toString(16);
  }

  function normalizeThreatList(allThreats) {
    if (!Array.isArray(allThreats)) return [];
    const seenIds = new Set();
    return allThreats.filter((t) => {
      if (!t) return false;
      if (!t.id || typeof t.id !== 'string' || t.id.length < 8) {
        t.id = stableFallbackId(t);
      }
      if (!t.risk_level && t.threat_level) t.risk_level = t.threat_level;
      if (seenIds.has(t.id)) return false;
      seenIds.add(t.id);
      return true;
    });
  }

  function countMalwarePreview(list) {
    if (!Array.isArray(list) || list.length === 0) return 0;
    return list.filter((t) => !isIntegrityThreat(t)).length;
  }

  function paintPreviewInfections(s) {
    const all = []
      .concat(s.previewThreats || [])
      .concat(s.previewIntegrityThreats || []);
    files.updateInfectedFiles(all);
  }

  function invalidatePreviewFetch() {
    previewFetchGen++;
    previewFetchInFlight = false;
  }

  /**
   * Incremental malware-first preview via JSONL line cursor (not unique-row offset).
   */
  async function loadPreviewThreats(scanId) {
    if (!scanId || previewFetchInFlight) return;
    const gen = ++previewFetchGen;
    previewFetchInFlight = true;
    lastPreviewFetchAt = Date.now();
    update((s) => ({
      ...s,
      previewLoading: true,
      previewError: null,
      previewPartial: true,
    }));

    try {
      let pages = 0;
      while (pages < PREVIEW_MAX_PAGES) {
        pages++;
        const snap = getStateSnapshot();
        if (!snap?.scanning || snap.scanId !== scanId) break;
        const have = countMalwarePreview(snap.previewThreats);
        if (have >= PREVIEW_CAP) {
          update((s) => ({
            ...s,
            previewCapped: true,
            previewLoading: false,
            previewMalwareTotal: Math.max(have, s.previewMalwareTotal || 0),
          }));
          break;
        }

        const after = Number(snap.previewAfterLine) || 0;
        const limit = Math.min(PREVIEW_PAGE_SIZE, PREVIEW_CAP - have);
        const resp = await adapters.malware.getThreats(scanId, 0, limit, {
          source: 'malware',
          after_line: after,
        });
        if (gen !== previewFetchGen) return;
        if (!resp?.success || !resp.data) {
          update((s) => ({
            ...s,
            previewLoading: false,
            previewError: 'Could not load findings yet.',
          }));
          break;
        }

        const batch = normalizeThreatList(resp.data.threats || []);
        const nextLine =
          typeof resp.data.next_line === 'number' ? resp.data.next_line : after;
        const eof = !!resp.data.eof;
        const cursorMoved = nextLine > after;

        let added = 0;
        let nextList = [];
        update((s) => {
          if (!s.scanning || s.scanId !== scanId) {
            return { ...s, previewLoading: false };
          }
          const existing = s.previewThreats || [];
          const seen = new Set(existing.map((t) => t.id));
          const fresh = batch.filter((t) => t.id && !seen.has(t.id));
          added = fresh.length;
          nextList = existing.concat(fresh).slice(0, PREVIEW_CAP);
          const malwareHave = countMalwarePreview(nextList);
          const liveMalware = s.liveProgress?.malware_threats || 0;
          return {
            ...s,
            previewThreats: nextList,
            previewPartial: true,
            previewLoading: false,
            previewError: null,
            previewAfterLine: nextLine,
            previewEof: eof,
            previewTotal: malwareHave,
            previewMalwareTotal: Math.max(malwareHave, liveMalware, s.previewMalwareTotal || 0),
            previewCapped: malwareHave >= PREVIEW_CAP && (liveMalware > PREVIEW_CAP || !eof),
            previewCaughtUpAt: eof
              ? Math.max(liveMalware, malwareHave, s.previewCaughtUpAt || 0)
              : s.previewCaughtUpAt,
          };
        });

        const afterSnap = getStateSnapshot();
        if (afterSnap) paintPreviewInfections(afterSnap);

        if (!afterSnap?.scanning || afterSnap.scanId !== scanId) break;
        if (countMalwarePreview(afterSnap.previewThreats) >= PREVIEW_CAP) break;
        if (eof && added === 0) break;
        if (!cursorMoved && added === 0) break;
        if (eof) break;
      }

      const snapAfter = getStateSnapshot();
      const needIntegrity =
        snapAfter?.scanning &&
        snapAfter.scanId === scanId &&
        !snapAfter.previewIntegrityAttempted &&
        (Number(snapAfter.liveProgress?.integrity_violations) || 0) > 0 &&
        !(snapAfter.previewIntegrityThreats?.length);

      if (needIntegrity && gen === previewFetchGen) {
        const ireq = await adapters.malware.getThreats(scanId, 0, PREVIEW_INTEGRITY_CAP, {
          source: 'integrity',
          after_line: 0,
        });
        if (gen !== previewFetchGen) return;
        const ibatch = normalizeThreatList(ireq?.success ? (ireq.data?.threats || []) : []);
        update((s) => {
          if (!s.scanning || s.scanId !== scanId) {
            return { ...s, previewIntegrityAttempted: true };
          }
          const liveInt = Number(s.liveProgress?.integrity_violations) || ibatch.length;
          return {
            ...s,
            previewIntegrityThreats: ibatch.slice(0, PREVIEW_INTEGRITY_CAP),
            previewIntegrityTotal: Math.max(liveInt, ibatch.length),
            previewIntegrityAttempted: true,
            previewPartial: true,
          };
        });
        const painted = getStateSnapshot();
        if (painted) paintPreviewInfections(painted);
      }
    } catch (err) {
      if (gen !== previewFetchGen) return;
      console.warn('[SCANNING] Preview threats failed:', err);
      update((s) => ({
        ...s,
        previewLoading: false,
        previewError: err?.message || 'Could not load findings yet.',
      }));
    } finally {
      if (gen === previewFetchGen) {
        previewFetchInFlight = false;
        update((s) => (s.previewLoading ? { ...s, previewLoading: false } : s));
      }
    }
  }

  function maybeFetchPreview(scanId, counters, statusName) {
    if (!scanId) return;
    if (
      statusName === 'completed' ||
      statusName === 'cancelled' ||
      statusName === 'failed'
    ) {
      return;
    }
    const snap = getStateSnapshot();
    if (!snap?.scanning || snap.scanId !== scanId) return;

    const malwareHave = countMalwarePreview(snap.previewThreats);
    const malwareN = malwareCountFromCounters(counters);
    const integrityN = Number(counters.integrity_violations) || 0;
    const integrityHave = snap.previewIntegrityThreats?.length || 0;

    if (malwareHave >= PREVIEW_CAP) {
      const shouldCap = malwareN > PREVIEW_CAP || snap.previewCapped;
      if (shouldCap && !snap.previewCapped) {
        update((s) => ({
          ...s,
          previewCapped: true,
          previewMalwareTotal: Math.max(malwareN, s.previewMalwareTotal || 0, malwareHave),
        }));
      }
    }

    const caughtUp =
      !!snap.previewEof && malwareN <= (Number(snap.previewCaughtUpAt) || malwareHave);
    const needMalware =
      malwareHave < PREVIEW_CAP &&
      !caughtUp &&
      (malwareN > malwareHave || (malwareHave > 0 && snap.previewEof === false));
    const needIntegrity =
      integrityN > 0 && integrityHave === 0 && !snap.previewIntegrityAttempted;

    if (!needMalware && !needIntegrity) return;
    if (previewFetchInFlight) return;
    const now = Date.now();
    if ((malwareHave > 0 || integrityHave > 0) && now - lastPreviewFetchAt < PREVIEW_FETCH_MIN_MS) {
      return;
    }
    loadPreviewThreats(scanId);
  }

  /**
   * Load all threats for a completed scan (paginate; malware-first order on server).
   * Avoids the old hard cap of 2000 integrity-first rows hiding signature hits.
   */
  async function loadAllThreats(scanId) {
    const pageSize = 2000;
    let offset = 0;
    let all = [];
    let meta = {
      malware_threats: null,
      integrity_violations: null,
      total_all: null,
    };
    // Prefer malware-first so early pages are signature hits even if we stop early.
    for (let page = 0; page < 50; page++) {
      const resp = await adapters.malware.getThreats(scanId, offset, pageSize, {
        prefer: 'malware',
        source: 'all',
      });
      if (!resp?.success || !resp.data) {
        break;
      }
      const batch = resp.data.threats || [];
      if (meta.malware_threats === null && typeof resp.data.malware_threats === 'number') {
        meta.malware_threats = resp.data.malware_threats;
      }
      if (meta.integrity_violations === null && typeof resp.data.integrity_violations === 'number') {
        meta.integrity_violations = resp.data.integrity_violations;
      }
      if (meta.total_all === null && typeof resp.data.total_all === 'number') {
        meta.total_all = resp.data.total_all;
      }
      all = all.concat(batch);
      const total = typeof resp.data.total === 'number' ? resp.data.total : null;
      if (batch.length < pageSize) {
        break;
      }
      offset += batch.length;
      if (total !== null && offset >= total) {
        break;
      }
    }
    return { threats: all, meta };
  }

  function applyThreatResults(scanId, allThreats, counters = {}, finishedAtOverride = null, startedAtOverride = null, extras = {}) {
    const malwareThreats = allThreats.filter((t) => !isIntegrityThreat(t));
    const integrityThreats = allThreats.filter((t) => isIntegrityThreat(t));
    const finishedAt = finishedAtOverride || Math.floor(Date.now() / 1000);
    const threats = normalizeThreatList(allThreats);
    const carriedN = threats.filter((t) => t.carried_forward).length;
    const malwareCount =
      typeof counters.malware_threats === 'number'
        ? counters.malware_threats
        : malwareThreats.length;
    const integrityCount = Math.max(
      integrityThreats.length,
      Number(counters.integrity_violations) || 0
    );
    const fileCarry = extras.file_carry && typeof extras.file_carry === 'object'
      ? extras.file_carry
      : null;
    const results = {
      threats,
      malware_threats: malwareThreats,
      integrity_violations_list: integrityThreats,
      file_carry: fileCarry,
      summary: {
        total_threats: threats.length,
        malware_threats: malwareCount,
        integrity_violations: integrityCount,
        files_scanned: counters.files_scanned ?? 0,
        files_visited: counters.files_visited ?? 0,
        files_skipped_unchanged: counters.files_skipped_unchanged ?? 0,
        db_rows_scanned: counters.db_rows_scanned ?? 0,
        threats_found: counters.threats_found ?? threats.length,
        carried_forward: fileCarry?.carried ?? carriedN,
        carried_from_profile: fileCarry?.from_profile ?? null,
      },
    };
    if (threats.length > 0) {
      files.updateInfectedFiles(threats);
    }
    update((s) => {
      const endSec = finishedAt || Math.floor(Date.now() / 1000);
      const startSec = startedAtOverride
        || (s.startTime ? Math.floor(s.startTime / 1000) : null);
      let duration = s.scanDuration || 0;
      if (startSec && endSec >= startSec) {
        duration = (endSec - startSec) * 1000;
      } else if (s.startTime) {
        duration = Math.max(0, Date.now() - s.startTime);
      }
      const scope = s.deepScope || 'full';
      const profileId = s.profileId || 'standard';
      let label =
        profileId === 'quick'
          ? 'Quick Scan'
          : profileId === 'deep'
            ? 'Deep Scan'
            : 'Standard Scan';
      if (profileId === 'deep' && scope !== 'full') {
        if (scope === 'files') label = 'Deep · Files only';
        else if (scope === 'database') label = 'Deep · Database only';
        else if (scope === 'paths') {
          label = `Deep · ${s.folderPathDisplay || s.scanFolder || 'paths'}`;
        }
      }
      return {
        ...s,
        results,
        scanning: false,
        progressStatus: 'completed',
        isContinuation: false,
        resultsFinishedAt: finishedAt,
        resultsScanLabel: label,
        resultsScope: profileId === 'deep' ? scope : null,
        startTime: startSec ? startSec * 1000 : s.startTime,
        scanDuration: duration,
        ...emptyPreviewState(),
      };
    });
    persistPointer({
      scan_id: scanId,
      last_status: 'completed',
      finished_at: finishedAt,
    });
  }

  /**
   * Attach status polling for an active scan_id.
   */
  function attachStatusPoll(scanId) {
    if (typeof pollCancel === 'function') {
      pollCancel();
    }
    pollCancel = null;
    lastAutoResumeAt = 0;
    autoResumeInFlight = false;
    hostErrorStreak = 0;
    notFoundStreak = 0;
    autoResumeIntervalMs = AUTO_RESUME_INTERVAL_MS_MIN;
    lastCounterActivityAt = Date.now();
    lastCounterActivityKey = '';
    lastPreviewFetchAt = 0;

    const cancel = adapters.malware.pollStatus(scanId, (status) => {
      handleStatusPoll(scanId, status);
    });
    // Guard: never store a Promise as cancel (async pollStatus was a production bug)
    pollCancel = typeof cancel === 'function' ? cancel : null;
    if (!pollCancel) {
      console.error('[SCANNING] pollStatus did not return a cancel function', cancel);
    }
  }

  function handleStatusPoll(scanId, status) {
            console.log('[SCANNING] Poll callback received:', status.status, 'progress:', status.progress);

            // Gateway/network soft failure — do NOT end the scan. Checkpoint lives server-side.
            // Also ignore host-error ticks if we already finished (race after complete).
            if (status._host_error || status.phase === 'host_timeout' || status.pause_reason === 'gateway_timeout') {
              const snap = getStateSnapshot();
              if (snap && !snap.scanning && snap.progressStatus === 'completed') {
                return;
              }
              hostErrorStreak++;
              autoResumeIntervalMs = Math.min(
                AUTO_RESUME_INTERVAL_MS_MAX,
                AUTO_RESUME_INTERVAL_MS_MIN * Math.pow(2, Math.min(4, hostErrorStreak - 1))
              );
              update(s => ({
                ...s,
                scanning: true, // keep session alive
                progressStatus: 'paused',
                pauseReason: status.pause_reason || 'gateway_timeout',
                progressMessage: status.message
                  || 'Host timed out (504). Retrying. Your scan progress is kept on the server.',
                hasResumeData: true,
              }));
              persistPointer({ scan_id: scanId, last_status: 'paused' });
              return;
            }

            // Transient checkpoint read race (server busy/retry sentinel, or progress
            // sentinel -1). Keep last UI; do not zero counters / hide the progress card.
            // (host_error path already returned above.)
            const isCheckpointBusy =
              status.reason === 'checkpoint_read_busy' ||
              status.phase === 'checkpoint_retry' ||
              Number(status.progress) === -1;

            if (isCheckpointBusy) {
              const snap = getStateSnapshot();
              if (snap && snap.scanning) {
                console.warn('[SCANNING] Checkpoint read busy — keeping last progress UI');
                update(s => ({
                  ...s,
                  scanning: true,
                  // Keep progressStatus as running/paused so ScanProgressCard stays visible
                  progressStatus:
                    s.progressStatus === 'paused' ? 'paused' : 'running',
                  progressMessage:
                    s.progressMessage && s.progressMessage !== 'Ready'
                      ? s.progressMessage
                      : 'Reconnecting to scan status…',
                }));
              }
              return;
            }

            // Soft not_found: one flaky poll must not blank the progress card or end the session.
            if (status.status === 'not_found') {
              const snap = getStateSnapshot();
              if (snap && snap.scanning && snap.scanId === scanId) {
                notFoundStreak++;
                console.warn(
                  '[SCANNING] Soft not_found for active scan',
                  scanId,
                  `(${notFoundStreak}/${NOT_FOUND_GIVE_UP}) — keeping UI`
                );
                if (notFoundStreak < NOT_FOUND_GIVE_UP) {
                  update(s => ({
                    ...s,
                    scanning: true,
                    progressStatus:
                      s.progressStatus === 'paused' || s.progressStatus === 'running'
                        ? s.progressStatus
                        : 'running',
                    progressMessage:
                      s.progressMessage && s.progressMessage !== 'Ready'
                        ? s.progressMessage
                        : 'Reconnecting to scan status…',
                  }));
                  return;
                }
                // Sustained not_found — scan really gone
                console.error('[SCANNING] Scan checkpoint lost after retries:', scanId);
                if (typeof pollCancel === 'function') {
                  pollCancel();
                }
                pollCancel = null;
                notFoundStreak = 0;
                invalidatePreviewFetch();
                update(s => ({
                  ...s,
                  scanning: false,
                  progressStatus: 'failed',
                  isContinuation: false,
                  progressMessage: 'Lost connection to scan state. Progress may still be on the server. Try refresh or Start again.',
                  previewLoading: false,
                  previewPartial: !!(s.previewThreats?.length || s.previewIntegrityThreats?.length),
                  previewStoppedReason: 'lost',
                }));
                clearScanPointer();
                return;
              }
              // Not an active UI scan — ignore
              return;
            }

            notFoundStreak = 0;
            hostErrorStreak = 0;
            autoResumeIntervalMs = AUTO_RESUME_INTERVAL_MS_MIN;

            // Keep liveProgress in sync so SecurityTab counters update mid-scan.
            const counters = status.counters || {};
            const q = status.queue || {};
            const filesN = counters.files_scanned ?? 0;
            const dbN = counters.db_rows_scanned ?? 0;
            const thrN = counters.threats_found ?? 0;
            const malwareN = malwareCountFromCounters(counters);
            const integrityN = counters.integrity_violations ?? 0;
            const pendN = q.pending ?? 0;
            const doneN = q.completed ?? 0;
            const activityKey = [filesN, dbN, thrN, pendN, doneN, status.status || ''].join('|');
            const nowMs = Date.now();
            if (activityKey !== lastCounterActivityKey) {
              lastCounterActivityKey = activityKey;
              lastCounterActivityAt = nowMs;
            } else if (!lastCounterActivityAt) {
              lastCounterActivityAt = nowMs;
            }

            scanning.setLiveProgress({
              files_scanned: filesN,
              files_visited: counters.files_visited ?? 0,
              files_skipped_unchanged: counters.files_skipped_unchanged ?? 0,
              db_rows_scanned: dbN,
              threats_found: thrN,
              malware_threats: malwareN,
              integrity_violations: integrityN,
              items_processed: q.items_processed ?? filesN,
              phase: status.phase || 'files',
              pending: pendN,
              completed: doneN,
              last_file_path: status.last_file_path ?? null,
              last_db_table: status.last_db_table ?? null,
              last_db_id: status.last_db_id ?? null,
              package_checksum_note: status.package_checksum_note ?? null,
            });
            if (status.status !== 'completed') {
              maybeFetchPreview(scanId, counters, status.status);
            }
            if (status.scan_scope || status.folder_path_display) {
              update(s => ({
                ...s,
                deepScope: status.scan_scope || s.deepScope || 'full',
                folderPathDisplay: status.folder_path_display ?? s.folderPathDisplay,
              }));
            }

            const hasPending = !!(q.has_pending) || pendN > 0;
            // progress === -1 is host-error sentinel — do not apply.
            const rawIncoming = Number(status.progress);
            const rawPct = (Number.isFinite(rawIncoming) && rawIncoming >= 0)
              ? Math.min(100, Math.max(0, Math.round(rawIncoming)))
              : null;
            const src = status.progress_source === 'queue' || status.progress_source === 'phase'
              ? status.progress_source
              : '';

            update(s => {
              const terminal = status.status === 'completed';
              let displayPct;
              if (terminal) {
                displayPct = 100;
              } else if (rawPct == null) {
                displayPct = s.progressPercent || 0;
              } else if (src === 'queue') {
                // Follow remaining work. Discovery may grow pending and the % should drop.
                displayPct = Math.min(99, rawPct);
              } else {
                // Early discovery heuristic: never look almost done.
                displayPct = Math.min(12, rawPct);
              }
              return {
                ...s,
                progressHighWater: terminal ? 0 : displayPct,
                progressSource: src || s.progressSource || '',
                progressPercent: displayPct,
                progressMessage: (thrN > 0
                  ? `${thrN} threat${thrN === 1 ? '' : 's'} · `
                  : '') + (status.phase || 'scanning'),
                progressStatus: status.status || 'running',
                phase: status.phase || s.phase,
                pauseReason: status.status === 'paused'
                  ? (status.pause_reason || 'time_budget')
                  : (status.pause_reason === 'stale_running' ? 'stale_running' : s.pauseReason),
                hasResumeData: hasPending,
                hasIntegrityBaseline: status.has_integrity_baseline ?? s.hasIntegrityBaseline,
                likelySource: status.likely_source ?? s.likelySource,
                integrityNote: status.integrity_note ?? s.integrityNote,
                integrityViolations: status.integrity_violations
                  ?? counters.integrity_violations
                  ?? s.integrityViolations
                  ?? 0,
                checksumNote: status.checksum_note ?? s.checksumNote,
                checksumChecked: status.checksum_checked ?? s.checksumChecked,
                checksumFindings: status.checksum_findings ?? s.checksumFindings,
                checksumVersion: status.checksum_version ?? s.checksumVersion,
                environmentAdvisory: status.environment_advisory ?? s.environmentAdvisory,
                restrictedHost: status.restricted_host ?? s.restrictedHost,
                loopbackKickCount: 0,
                executionContext: 'scanner_v2',
                pendingWorkCount: pendN,
                hasPendingWork: hasPending,
                workQueueStats: q ?? null,
              };
            });
            const appPct = status.status === 'completed'
              ? 100
              : (rawPct == null ? undefined : rawPct);
            if (appPct != null) {
              app.setProgress(appPct, status.phase || 'Scanning...', status.status || 'running');
            }
            persistPointer({
              scan_id: scanId,
              last_status: status.status || 'running',
              profile_id: status.profile_id,
              started_at: status.started_at || undefined,
              finished_at: status.finished_at || undefined,
            });

            // Client-side auto-resume. Two cases:
            // 1) status === 'paused' with pending work (normal time-budget pause)
            // 2) status === 'running' but counters/queue idle too long (zombie
            //    running — resume marked running then drain was busy/died)
            const now = Date.now();
            const idleMs = lastCounterActivityAt > 0 ? now - lastCounterActivityAt : 999999;
            const STALE_RUNNING_MS = 20000;
            const isPaused = status.status === 'paused';
            const isStaleRunning =
              status.status === 'running' &&
              hasPending &&
              idleMs >= STALE_RUNNING_MS;

            const needsResume =
              hasPending &&
              (isPaused || isStaleRunning) &&
              status.status !== 'completed' &&
              status.status !== 'cancelled' &&
              status.status !== 'failed';

            if (needsResume && !autoResumeInFlight) {
              if (now - lastAutoResumeAt >= autoResumeIntervalMs) {
                lastAutoResumeAt = now;
                autoResumeInFlight = true;
                console.log(
                  '[SCANNING] Auto-resume nudge for',
                  scanId,
                  'interval',
                  autoResumeIntervalMs,
                  isStaleRunning ? `(stale running, idle ${Math.round(idleMs / 1000)}s)` : '(paused)'
                );
                adapters.malware.resume(scanId)
                  .then((resp) => {
                    if (!resp?.success) {
                      hostErrorStreak++;
                      autoResumeIntervalMs = Math.min(
                        AUTO_RESUME_INTERVAL_MS_MAX,
                        autoResumeIntervalMs * 2
                      );
                      console.warn('[SCANNING] Auto-resume non-success:', resp);
                    } else {
                      hostErrorStreak = 0;
                      autoResumeIntervalMs = AUTO_RESUME_INTERVAL_MS_MIN;
                    }
                  })
                  .catch(err => {
                    hostErrorStreak++;
                    autoResumeIntervalMs = Math.min(
                      AUTO_RESUME_INTERVAL_MS_MAX,
                      autoResumeIntervalMs * 2
                    );
                    console.warn('[SCANNING] Auto-resume failed (scan kept):', err);
                    update(s => ({
                      ...s,
                      scanning: true,
                      progressStatus: 'paused',
                      pauseReason: 'gateway_timeout',
                      progressMessage: 'Resume timed out on the host. Will retry. Progress is kept.',
                      hasResumeData: true,
                    }));
                  })
                  .finally(() => { autoResumeInFlight = false; });
              }
            }

            // On completion, fetch threats and finish
            if (status.status === 'completed') {
              console.log('[SCANNING] Scan completed; loading threats');
              invalidatePreviewFetch();
              // Stop polling first (pollCancel must be a function, not a Promise)
              if (typeof pollCancel === 'function') {
                pollCancel();
              }
              pollCancel = null;
              loadAllThreats(scanId)
                .then(({ threats: allThreats, meta }) => {
                  const finishedAt = status.finished_at || Math.floor(Date.now() / 1000);
                  const mergedCounters = {
                    ...counters,
                    malware_threats:
                      meta.malware_threats ?? counters.malware_threats ?? undefined,
                    integrity_violations:
                      meta.integrity_violations ??
                      counters.integrity_violations ??
                      undefined,
                  };
                  applyThreatResults(
                    scanId,
                    allThreats,
                    mergedCounters,
                    finishedAt,
                    status.started_at || null,
                    { file_carry: status.file_carry || null }
                  );
                  update((s) => ({
                    ...s,
                    resultsRestoredBanner: null,
                    resultsFinishedAt: finishedAt,
                  }));
                })
                .catch((err) => {
                  console.error('[SCANNING] Failed to load threats:', err);
                  update((s) => ({
                    ...s,
                    scanning: false,
                    progressStatus: 'completed',
                    isContinuation: false,
                    previewLoading: false,
                    previewPartial: true,
                    previewReportFailed: true,
                    previewStoppedReason: 'report_failed',
                    progressMessage: 'Scan finished but loading the full report failed. Findings collected so far are still below.',
                  }));
                  persistPointer({
                    scan_id: scanId,
                    last_status: 'completed',
                    finished_at: status.finished_at || Math.floor(Date.now() / 1000),
                  });
                });
              return; // do not process further this tick
            } else if (status.status === 'cancelled' || status.status === 'failed') {
              invalidatePreviewFetch();
              if (typeof pollCancel === 'function') {
                pollCancel();
              }
              pollCancel = null;
              update(s => ({
                ...s,
                scanning: false,
                progressStatus: status.status,
                isContinuation: false,
                previewLoading: false,
                previewPartial: !!(s.previewThreats?.length || s.previewIntegrityThreats?.length),
                previewStoppedReason: status.status === 'cancelled' ? 'cancelled' : 'failed',
              }));
              clearScanPointer();
            }
  }
  
  const scanning = {
    subscribe,
    
    /**
     * Set scan type
     */
    setScanType: (type) => {
      update(s => ({ ...s, scanType: type }));
    },
    
    /**
     * Set deep scan toggle
     */
    setDeepScan: (enabled) => {
      update(s => ({ ...s, deepScan: enabled }));
    },
    
    /**
     * Set include vulnerabilities toggle
     */
    setIncludeVulnerabilities: (enabled) => {
      update(s => ({ ...s, includeVulnerabilities: enabled }));
    },

    /**
     * Set scan profile
     */
    setProfileId: (profileId) => {
      update(s => ({ ...s, profileId }));
    },

    /**
     * Set current scanId (Phase 3.5)
     */
    setScanId: (scanId) => {
      update(s => ({ ...s, scanId }));
    },

    /**
     * Set Deep scope (full | files | database | paths). Only meaningful for Deep profile.
     */
    setDeepScope: (scope) => {
      const allowed = ['full', 'files', 'database', 'paths'];
      const next = allowed.includes(scope) ? scope : 'full';
      update(s => ({
        ...s,
        deepScope: next,
        // Drop server display path when leaving paths so labels/inputs do not stick.
        folderPathDisplay: next === 'paths' ? s.folderPathDisplay : null,
        scanFolder: next === 'paths' ? s.scanFolder : '',
      }));
    },

    /**
     * Update live progress counters from a poll response.
     *
     * This is the source of truth for the "Files scanned" number while a
     * scan is running. It is updated on every poll so the UI shows the
     * current count even mid-scan, instead of waiting for `setResults`
     * to be called when the scan finishes (at which point `totalFilesScanned`
     * would otherwise be stuck on the previous completed scan's value or 0).
     *
     * The data is intentionally kept separate from `results` because the
     * work-queue-driven scan produces a final results payload that is a
     * different shape (it contains `summary`, threat list, etc.) than the
     * live counters we get from a poll.
     */
    setLiveProgress: (progress) => {
      update(s => {
        const files = progress.files_scanned ?? 0;
        const skipped = progress.files_skipped_unchanged ?? s.liveProgress?.files_skipped_unchanged ?? 0;
        const visited = progress.files_visited ?? s.liveProgress?.files_visited ?? 0;
        const dbRows = progress.db_rows_scanned ?? 0;
        const threats = progress.threats_found ?? 0;
        const malware = progress.malware_threats ?? s.liveProgress?.malware_threats ?? 0;
        const integrity =
          progress.integrity_violations ?? s.liveProgress?.integrity_violations ?? 0;
        const items = progress.items_processed ?? progress.work_queue?.items_processed ?? 0;
        const pending = progress.pending ?? s.pendingWorkCount ?? 0;
        const completed = progress.completed ?? s.workQueueStats?.completed ?? 0;
        const peakFiles = Math.max(s.liveProgress?.peak_files_scanned || 0, files);
        const lastFile = progress.last_file_path ?? s.liveProgress?.last_file_path ?? '';
        const lastTable = progress.last_db_table ?? s.liveProgress?.last_db_table ?? '';
        const lastDbId = progress.last_db_id ?? s.liveProgress?.last_db_id ?? '';
        const activityKey = [files, skipped, visited, dbRows, threats, items, pending, completed, lastFile, lastTable, lastDbId].join('|');
        const prevKey = s.liveProgress?.activity_key || '';
        const now = Date.now();
        const activityChanged = activityKey !== prevKey;

        return {
          ...s,
          liveProgress: {
            files_scanned: files,
            files_visited: visited,
            files_skipped_unchanged: skipped,
            db_rows_scanned: dbRows,
            threats_found: threats,
            malware_threats: malware,
            integrity_violations: integrity,
            items_processed: items,
            phase: progress.phase ?? s.liveProgress.phase,
            last_updated: now,
            peak_files_scanned: peakFiles,
            activity_key: activityKey,
            last_activity_at: activityChanged
              ? now
              : (s.liveProgress?.last_activity_at || now),
            last_file_path: progress.last_file_path !== undefined
              ? progress.last_file_path
              : (s.liveProgress?.last_file_path ?? null),
            last_db_table: progress.last_db_table !== undefined
              ? progress.last_db_table
              : (s.liveProgress?.last_db_table ?? null),
            last_db_id: progress.last_db_id !== undefined
              ? progress.last_db_id
              : (s.liveProgress?.last_db_id ?? null),
            package_checksum_note: progress.package_checksum_note !== undefined
              ? progress.package_checksum_note
              : (s.liveProgress?.package_checksum_note ?? null),
          },
        };
      });
    },

    /**
     * Reset live progress counters (e.g. when starting a new scan).
     */
    resetLiveProgress: () => {
      update(s => ({
        ...s,
        progressHighWater: 0,
        liveProgress: {
          files_scanned: 0,
          files_visited: 0,
          files_skipped_unchanged: 0,
          db_rows_scanned: 0,
          threats_found: 0,
          malware_threats: 0,
          integrity_violations: 0,
          items_processed: 0,
          phase: 'files',
          last_updated: 0,
          peak_files_scanned: 0,
          activity_key: '',
          last_activity_at: 0,
        },
        ...emptyPreviewState(),
      }));
    },

    /**
     * Set scan folder (for files-only scan)
     */
    setScanFolder: (folder) => {
      // Clear server display while the user edits so the input is not forced back.
      update(s => ({ ...s, scanFolder: folder, folderPathDisplay: null }));
    },
    
    /**
     * Set scan results (called after scan completes)
     */
    setResults: (results) => {
      // Ensure threats have valid unique IDs, risk_level, and deduplicate
      let threats = [];
      if (results?.threats && Array.isArray(results.threats)) {
        const seenIds = new Set();
        let fallbackCounter = 0;
        threats = results.threats.filter((t) => {
          if (!t) return false;

          if (!t.id || typeof t.id !== 'string' || t.id.length < 8) {
            t.id = 'threat_' + fallbackCounter++ + '_' + Date.now();
          }
          // Normalize severity field used by UI (backend may send threat_level / risk_score)
          if (!t.risk_level && t.threat_level) {
            t.risk_level = t.threat_level;
          }
          if (!t.risk_level && typeof t.risk_score === 'number') {
            if (t.risk_score >= 80) t.risk_level = 'critical';
            else if (t.risk_score >= 60) t.risk_level = 'high';
            else if (t.risk_score >= 40) t.risk_level = 'medium';
            else if (t.risk_score >= 20) t.risk_level = 'low';
            else t.risk_level = 'info';
          }
          if (!t.risk_level) {
            t.risk_level = 'medium';
          }
          // Unify legacy "warning" with "high"
          if (t.risk_level === 'warning') {
            t.risk_level = 'high';
          }

          if (seenIds.has(t.id)) return false;
          seenIds.add(t.id);
          return true;
        });
      }

      update(s => {
        const duration = s.startTime ? Math.max(0, Date.now() - s.startTime) : (s.scanDuration || 0);
        return {
          ...s,
          results: { ...results, threats },
          scanning: false,
          progressStatus: 'completed',
          scanDuration: duration,
        };
      });

      if (threats.length > 0) {
        files.updateInfectedFiles(threats);
      }
    },
    
    /**
     * Start malware scan using profile-based scanning (new architecture)
     * @param forceResume - If true, signals backend to use existing checkpoint for resuming
     * @param opts.freshScan - Clear this profile's file-hash cache and rescan every file
     */
    async startScan(forceResume = false, opts = {}) {
      if (forceResume && typeof forceResume === 'object' && forceResume.type) {
        forceResume = false;
      }
      const freshScan = !!(opts && opts.freshScan) && !forceResume;
      console.log('🔍 [SCANNING] startScan() called, forceResume:', forceResume, 'freshScan:', freshScan);
      let state;
      subscribe(s => state = s)();

      // Phase 3: Get UI session ID for checkpoint correlation
      // Session ID is pre-initialized on app load via session store (not lazy)
      const uiSessionId = session.getUiSessionId();
      console.log('🔍 [SCANNING] UI session ID:', uiSessionId);

      console.log('🔍 [SCANNING] Current state - profileId:', state.profileId, 'scanType:', state.scanType);

      // Phase 2.1: If forceResume is requested, the scan should pick up from existing checkpoint
      // The backend will detect existing checkpoint and resume automatically
      const isResuming = forceResume || state.isContinuation;

      if (!isResuming) {
        invalidatePreviewFetch();
      }

      update(s => ({
        ...s,
        scanning: true,
        error: null,
        // Keep last completed report until start ACK succeeds (failed start restores it).
        ...(isResuming ? { previewPartial: true, previewStoppedReason: null } : emptyPreviewState()),
        resultsScanLabel: isResuming ? s.resultsScanLabel : null,
        resultsScope: isResuming ? s.resultsScope : null,
        checksumNote: isResuming ? s.checksumNote : null,
        checksumChecked: isResuming ? s.checksumChecked : 0,
        checksumFindings: isResuming ? s.checksumFindings : 0,
        checksumVersion: isResuming ? s.checksumVersion : null,
        progressPercent: 0,
        progressHighWater: isResuming ? (s.progressHighWater || 0) : 0,
        progressSource: isResuming ? (s.progressSource || '') : '',
        progressStatus: 'running',
        progressMessage: isResuming
          ? 'Resuming scan...'
          : (freshScan ? 'Full file rescan (hash cache cleared)…' : 'Preparing scan (first files)...'),
        startTime: Date.now(),
        expandedThreats: [],
        // Reset live counters so the new scan starts at 0. The next poll
        // will populate them; without this, a resumed scan would briefly
        // show the previous scan's numbers.
        liveProgress: isResuming
          ? {
              ...s.liveProgress,
              last_updated: Date.now(),
              last_activity_at: Date.now(),
            }
          : {
              files_scanned: 0,
              files_visited: 0,
              files_skipped_unchanged: 0,
              db_rows_scanned: 0,
              threats_found: 0,
              malware_threats: 0,
              integrity_violations: 0,
              items_processed: 0,
              phase: 'files',
              last_updated: 0,
              peak_files_scanned: 0,
              activity_key: '',
              last_activity_at: Date.now(),
              last_file_path: null,
              last_db_table: null,
              last_db_id: null,
              package_checksum_note: null,
            },
        // Phase 2.1 - reset most state for new scan, but preserve continuation state
        // when forceResume is true - the backend response will provide authoritative values
        isContinuation: isResuming,
        continuationCount: isResuming ? (s.continuationCount || 0) + 1 : 0,
        // Preserve these when resuming - they will be overwritten by direct handler or polling response
        // Only reset to defaults when starting a fresh scan
        pauseReason: isResuming ? (s.pauseReason || null) : null,
        hasResumeData: isResuming ? (s.hasResumeData || true) : false,
        hasIntegrityBaseline: isResuming ? (s.hasIntegrityBaseline || false) : false,
        loopbackKickCount: isResuming ? (s.loopbackKickCount || 0) : 0,
        executionContext: isResuming ? (s.executionContext || null) : null,
      }));

      app.setProgress(0, 'Initializing...', 'running');

      debug.log('SCANNING', 'Starting scan', {
        profileId: state.profileId,
        deepScope: state.deepScope,
        scanFolder: state.scanFolder,
      });

      try {
        let response;

        const profileId = (state.profileId && ['quick', 'standard', 'deep', 'custom'].includes(state.profileId))
          ? state.profileId
          : 'standard';

        // Deep-only scope. Resume must not send conflicting custom_config (backend ignores it).
        const customConfig = {};
        if (!isResuming && profileId === 'deep') {
          const scope = state.deepScope || 'full';
          customConfig.scan_scope = scope;
          if (scope === 'paths' && state.scanFolder) {
            customConfig.folder_path = state.scanFolder;
          }
        }
        if (!isResuming && freshScan) {
          customConfig.fresh_scan = true;
        }

        console.log('🔍 [SCANNING] Starting profile scan', {
          profileId,
          deepScope: customConfig.scan_scope || null,
          forceResume: isResuming,
          freshScan,
          existingScanId: state.scanId,
        });

        // Backend handles force_resume: reuses requested scan_id or latest
        // resumable checkpoint for the profile. Drain runs after the ack is flushed.
        response = await adapters.malware.start(
          profileId,
          customConfig,
          uiSessionId,
          isResuming ? (state.scanId || null) : null,
          isResuming
        );

        console.log('[SCANNING] API response success:', response.success);

        if (response.success && response.data?.scan_id) {
          const scanId = response.data.scan_id;
          const ackStatus = response.data.status || '';
          const ackReason = response.data.reason || '';
          const terminalAck =
            ackReason === 'scan_terminal' ||
            ['completed', 'cancelled', 'failed'].includes(ackStatus);

          // Phase 2: capture baseline + host advisory + Deep scope from start ack
          update(s => ({
            ...s,
            scanId,
            hasIntegrityBaseline: !!(response.data.has_integrity_baseline ?? s.hasIntegrityBaseline),
            integrityNote: response.data.integrity_note ?? s.integrityNote,
            environmentAdvisory: response.data.environment_advisory ?? s.environmentAdvisory,
            restrictedHost: !!(response.data.restricted_host ?? s.restrictedHost),
            deepScope: response.data.scan_scope || s.deepScope || 'full',
            folderPathDisplay: response.data.folder_path_display ?? s.folderPathDisplay,
            scanFolder: response.data.folder_path_display || s.scanFolder,
            isContinuation: terminalAck ? false : s.isContinuation,
            scanning: terminalAck ? false : s.scanning,
            progressStatus: terminalAck
              ? (ackStatus === 'completed' ? 'completed' : ackStatus || 'idle')
              : s.progressStatus,
          }));

          if (terminalAck) {
            persistPointer({
              scan_id: scanId,
              profile_id: profileId,
              last_status: ackStatus || 'completed',
              finished_at: Math.floor(Date.now() / 1000),
            });
            // Reattach results for completed terminal acks without pretending to scan.
            if (ackStatus === 'completed') {
              attachStatusPoll(scanId);
            }
            return;
          }

          persistPointer({
            scan_id: scanId,
            profile_id: profileId,
            last_status: 'running',
            started_at: Math.floor(Date.now() / 1000),
          });
          update(s => ({
            ...s,
            results: isResuming ? s.results : null,
            resultsRestoredBanner: null,
            resultsFinishedAt: isResuming ? s.resultsFinishedAt : null,
            previewStoppedReason: null,
            previewReportFailed: false,
            ...(isResuming ? {} : emptyPreviewState()),
            previewPartial: true,
          }));
          if (!isResuming) {
            files.updateInfectedFiles([]);
          }
          attachStatusPoll(scanId);
          if (isResuming) {
            const snap = getStateSnapshot();
            maybeFetchPreview(scanId, {
              threats_found: snap?.liveProgress?.threats_found || 0,
              malware_threats: snap?.liveProgress?.malware_threats || 0,
              integrity_violations: snap?.liveProgress?.integrity_violations || 0,
            }, 'running');
          }
        } else {
          const errMsg = response.error || 'Scan failed to start';
          const errCode = response.code || 'SCAN_START_ERROR';
          update(s => ({
            ...s,
            scanning: false,
            error: response.code ? `${errMsg} (${response.code})` : errMsg,
            progressStatus: s.results ? 'completed' : 'error',
            isContinuation: false,
          }));
          app.setError(errMsg);
          errors.add({ message: errMsg, code: errCode });
        }
      } catch (e) {
        debug.error('SCANNING', 'Scan failed', e.message);
        update(s => ({
          ...s,
          scanning: false,
          error: e.message,
          progressStatus: s.results ? 'completed' : 'error',
          isContinuation: false,
        }));
        errors.add({ message: e.message, code: 'SCAN_ERROR' });
      }
    },

    /**
     * Start profile-based scan (convenience method)
     */
    async startProfileScan(profileId) {
      console.log('[SCANNING] startProfileScan called with:', profileId);
      update(s => ({ ...s, profileId }));
      await this.startScan();
    },
    
    /**
     * Cancel scan — stop polling and tell the server to mark cancelled
     * so drain() exits at the next safe point.
     */
    cancelScan: () => {
      let scanId = null;
      subscribe(s => { scanId = s.scanId; })();

      if (pollCancel) {
        pollCancel();
        pollCancel = null;
      }

      invalidatePreviewFetch();

      if (scanId) {
        adapters.malware.cancel(scanId).catch(err => {
          console.error('[SCANNING] cancel API failed:', err);
        });
      }
      
      clearScanPointer();
      update(s => ({
        ...s,
        scanning: false,
        progressStatus: 'cancelled',
        isContinuation: false,
        previewLoading: false,
        previewPartial: !!(s.previewThreats?.length || s.previewIntegrityThreats?.length),
        previewStoppedReason: 'cancelled',
        continuationCount: 0,
        pauseReason: null,
        hasResumeData: false,
        hasIntegrityBaseline: false,
        loopbackKickCount: 0,
        executionContext: null,
        resultsRestoredBanner: null,
      }));
      
      app.setProgress(0, 'Cancelled', 'idle');
    },
    
    /**
     * Toggle threat expansion
     */
    toggleThreat: (threatId) => {
      update(s => {
        const expanded = s.expandedThreats.includes(threatId)
          ? s.expandedThreats.filter(id => id !== threatId)
          : [...s.expandedThreats, threatId];
        return { ...s, expandedThreats: expanded };
      });
    },
    
    /**
     * Select a threat to view in editor
     * Handles both file threats and database threats
     */
    selectThreat: (threat) => {
      update(s => ({
        ...s,
        selectedThreat: threat,
        // Clear previous DB payload; refilled when a DB threat loads
        selectedDbContent: null,
      }));

      if (!threat) {
        return;
      }

      // If threat has a file, load it in the file viewer
      if (threat.source === 'database') {
        // Database threat - load content via getDbContent API
        if (threat.table && threat.row_id && threat.column) {
          adapters.malware.getDbContent(threat.table, threat.row_id, threat.column)
            .then(response => {
              if (response.success && response.data) {
                // Store database content for display
                update(s => ({
                  ...s,
                  selectedDbContent: {
                    table: threat.table,
                    row_id: threat.row_id,
                    column: threat.column,
                    content: response.data.content,
                    edit_link: response.data.edit_link
                  }
                }));
              }
            })
            .catch(err => {
              debug.error('SCANNING', 'Failed to load DB content', err.message);
            });
        }
      } else if (threat.file || threat.path) {
        // Prefer threat.path (already site-relative); fall back to converting absolute file
        const rel = threatToSiteRelativePath(threat);
        files.loadFile(rel, threat.line_number || null);
      }
    },
    
    /**
     * Select a vulnerability to view details
     */
    selectVulnerability: (vuln) => {
      update(s => ({ ...s, selectedVulnerability: vuln }));
    },
    
    /**
     * Reset store
     */
    reset: () => {
      if (pollCancel) {
        pollCancel();
        pollCancel = null;
      }
      invalidatePreviewFetch();
      set({
        scanning: false,
        progressPercent: 0,
        progressSource: '',
        progressMessage: 'Ready',
        progressStatus: 'idle',
        progressDetails: '',
        progressFile: null,
        results: null,
        error: null,
        scanDuration: 0,
        startTime: null,
        expandedThreats: [],
        scanType: 'all',
        deepScan: false,
        scanFolder: '',
        deepScope: 'full',
        folderPathDisplay: null,
        resultsScanLabel: null,
        resultsScope: null,
        includeVulnerabilities: false,
        selectedThreat: null,
        selectedVulnerability: null,
        selectedDbContent: null,
        profileId: 'standard', // Standard = recommended investigation default
        // Phase 2.1 - Resume / Continuation state
        isContinuation: false,
        continuationCount: 0,
        pauseReason: null,
        hasResumeData: false,
        hasIntegrityBaseline: false,
        loopbackKickCount: 0,
        executionContext: null,
        // Phase 3.5: Work queue and scan_id
        pendingWorkCount: 0,
        hasPendingWork: false,
        workQueueStats: null,
        scanId: null,
        ...emptyPreviewState(),
      });
      app.resetProgress();
    },

    /**
     * Dismiss restored/completed results from UI (keeps server files until cleanup).
     */
    dismissResults: () => {
      clearScanPointer();
      invalidatePreviewFetch();
      update(s => ({
        ...s,
        results: null,
        resultsRestoredBanner: null,
        resultsFinishedAt: null,
        resultsScanLabel: null,
        resultsScope: null,
        progressStatus: 'idle',
        progressMessage: 'Ready',
        progressPercent: 0,
        scanId: null,
        ...emptyPreviewState(),
      }));
      app.resetProgress();
    },

    clearRestoredBanner: () => {
      update(s => ({ ...s, resultsRestoredBanner: null }));
    },

    /**
     * Rehydrate scan UI after page refresh (A + C-lite).
     * 1) Client pointer → status
     * 2) Else server latest_scan (logs/checkpoint_*.json)
     */
    async rehydrateFromServer() {
      update(s => ({ ...s, rehydrating: true }));
      try {
        let scanId = null;
        let profileId = 'standard';
        let fromPointer = false;

        const pointer = loadScanPointer();
        if (pointer?.scan_id) {
          scanId = pointer.scan_id;
          profileId = pointer.profile_id || 'standard';
          fromPointer = true;
        } else {
          const latest = await adapters.malware.getLatestScan(COMPLETED_TTL_SECONDS);
          if (latest?.success && latest.data?.scan?.scan_id) {
            scanId = latest.data.scan.scan_id;
            profileId = latest.data.scan.profile_id || 'standard';
          }
        }

        if (!scanId) {
          update(s => ({ ...s, rehydrateAttempted: true, rehydrating: false }));
          return { restored: false };
        }

        let stResp = await adapters.malware.getStatus(scanId);
        if (!stResp?.success || !stResp.data || stResp.data.status === 'not_found') {
          if (fromPointer) {
            clearScanPointer();
            // C-lite: pointer stale — try latest on server
            const latest = await adapters.malware.getLatestScan(COMPLETED_TTL_SECONDS);
            if (latest?.success && latest.data?.scan?.scan_id) {
              scanId = latest.data.scan.scan_id;
              profileId = latest.data.scan.profile_id || profileId;
              stResp = await adapters.malware.getStatus(scanId);
            }
          }
          if (!stResp?.success || !stResp.data || stResp.data.status === 'not_found') {
            update(s => ({ ...s, rehydrateAttempted: true, rehydrating: false }));
            return { restored: false };
          }
        }

        return await applyRehydrateStatus(scanId, stResp.data, profileId);
      } catch (e) {
        console.warn('[SCANNING] rehydrate failed', e);
        update(s => ({ ...s, rehydrateAttempted: true, rehydrating: false }));
        return { restored: false, error: e.message };
      }
    },
  };

  async function applyRehydrateStatus(scanId, status, profileId) {
    const st = status.status || 'not_found';
    const counters = status.counters || {};

    if (st === 'running' || st === 'paused' || st === 'pending' || st === 'initializing') {
      update(s => ({
        ...s,
        scanning: true,
        scanId,
        profileId: status.profile_id || profileId || s.profileId,
        progressStatus: st === 'initializing' ? 'running' : st,
        progressPercent: Math.min(99, Math.round(status.progress || 0)),
        progressHighWater: Math.round(status.progress || 0),
        progressSource: status.progress_source === 'queue' || status.progress_source === 'phase'
          ? status.progress_source
          : '',
        progressMessage: 'Reattached after reload · ' + (status.phase || 'scanning'),
        startTime: status.started_at ? status.started_at * 1000 : Date.now(),
        restrictedHost: !!(status.restricted_host ?? s.restrictedHost),
        environmentAdvisory: status.environment_advisory ?? s.environmentAdvisory,
        hasIntegrityBaseline: !!(status.has_integrity_baseline ?? s.hasIntegrityBaseline),
        integrityNote: status.integrity_note ?? s.integrityNote,
        checksumNote: status.checksum_note ?? s.checksumNote,
        checksumChecked: status.checksum_checked ?? s.checksumChecked,
        checksumFindings: status.checksum_findings ?? s.checksumFindings,
        checksumVersion: status.checksum_version ?? s.checksumVersion,
        deepScope: status.scan_scope || s.deepScope || 'full',
        folderPathDisplay: status.folder_path_display ?? s.folderPathDisplay,
        hasResumeData: !!(status.queue?.has_pending),
        pendingWorkCount: status.queue?.pending ?? 0,
        workQueueStats: status.queue ?? null,
        results: null,
        ...emptyPreviewState(),
        previewPartial: true,
        resultsRestoredBanner: 'Scan reattached after page reload. Keep this tab open.',
        resultsFinishedAt: null,
        rehydrateAttempted: true,
        rehydrating: false,
        liveProgress: {
          ...s.liveProgress,
          files_scanned: counters.files_scanned ?? 0,
          db_rows_scanned: counters.db_rows_scanned ?? 0,
          threats_found: counters.threats_found ?? 0,
          malware_threats: malwareCountFromCounters(counters),
          integrity_violations: counters.integrity_violations ?? 0,
          peak_files_scanned: counters.files_scanned ?? 0,
          phase: status.phase || 'files',
          last_activity_at: Date.now(),
          last_file_path: status.last_file_path ?? s.liveProgress?.last_file_path ?? null,
          last_db_table: status.last_db_table ?? s.liveProgress?.last_db_table ?? null,
          last_db_id: status.last_db_id ?? s.liveProgress?.last_db_id ?? null,
          package_checksum_note: status.package_checksum_note ?? s.liveProgress?.package_checksum_note ?? null,
        },
      }));
      persistPointer({
        scan_id: scanId,
        profile_id: status.profile_id || profileId,
        started_at: status.started_at,
        last_status: st,
      });
      attachStatusPoll(scanId);
      maybeFetchPreview(scanId, counters, st);
      // Kick resume if already paused
      if (st === 'paused' && status.queue?.has_pending) {
        adapters.malware.resume(scanId).catch(() => {});
      }
      console.log('[SCANNING] Rehydrated active scan', scanId, st);
      return { restored: true, mode: 'active', scan_id: scanId };
    }

    if (st === 'completed') {
      const finishedAt = status.finished_at || status.last_updated || 0;
      if (!isCompletedWithinTtl(finishedAt, COMPLETED_TTL_SECONDS)) {
        clearScanPointer();
        update(s => ({ ...s, rehydrateAttempted: true, rehydrating: false }));
        return { restored: false, reason: 'completed_expired' };
      }
      const { threats: allThreats, meta } = await loadAllThreats(scanId);
      const age = formatScanAge(finishedAt);
      const mergedCounters = {
        ...counters,
        malware_threats: meta.malware_threats ?? counters.malware_threats ?? undefined,
        integrity_violations:
          meta.integrity_violations ?? counters.integrity_violations ?? undefined,
      };
      // Apply profile/scope before results so resultsScanLabel freezes correctly.
      update(s => ({
        ...s,
        scanId,
        profileId: status.profile_id || profileId || s.profileId,
        deepScope: status.scan_scope || s.deepScope || 'full',
        folderPathDisplay: status.folder_path_display ?? s.folderPathDisplay,
      }));
      applyThreatResults(
        scanId,
        allThreats,
        mergedCounters,
        finishedAt,
        status.started_at || null,
        { file_carry: status.file_carry || null }
      );
      update(s => ({
        ...s,
        resultsFinishedAt: finishedAt,
        resultsRestoredBanner: age
          ? `Results restored from scan finished ${age}. Run a new scan for a fresh check.`
          : 'Results restored from the last completed scan.',
        rehydrateAttempted: true,
        rehydrating: false,
        isContinuation: false,
        liveProgress: {
          ...s.liveProgress,
          files_scanned: counters.files_scanned ?? 0,
          db_rows_scanned: counters.db_rows_scanned ?? 0,
          threats_found: counters.threats_found ?? allThreats.length,
          malware_threats: malwareCountFromCounters(mergedCounters),
          integrity_violations: mergedCounters.integrity_violations ?? 0,
          peak_files_scanned: counters.files_scanned ?? 0,
          phase: 'complete',
        },
      }));
      console.log('[SCANNING] Rehydrated completed scan', scanId);
      return { restored: true, mode: 'completed', scan_id: scanId };
    }

    // cancelled / failed / unknown
    clearScanPointer();
    update(s => ({ ...s, rehydrateAttempted: true, rehydrating: false }));
    return { restored: false, reason: st };
  }

  return scanning;
}

export const scanning = createScanningStore();

// Derived store for computed values
export const riskCounts = derived(scanning, $s => {
  let critical = 0;
  let warning = 0;
  let info = 0;
  
  // Count malware threats (high/warning share the HIGH bucket)
  if ($s.results?.threats) {
    for (const t of $s.results.threats) {
      const lvl = (t.risk_level || t.threat_level || '').toLowerCase();
      if (lvl === 'critical') critical++;
      else if (lvl === 'high' || lvl === 'warning') warning++;
      else info++; // medium / low / info / unknown
    }
  }
  
  // Count vulnerabilities
  if ($s.results?.vulnerabilities?.vulnerabilities) {
    const vulns = $s.results.vulnerabilities.vulnerabilities;
    critical += vulns.filter(v => v.risk_level === 'critical').length;
    warning += vulns.filter(v => v.risk_level === 'high').length;
    info += vulns.filter(v => v.risk_level === 'medium' || v.risk_level === 'low' || v.risk_level === 'info').length;
  }
  
  return { critical, warning, info };
});


