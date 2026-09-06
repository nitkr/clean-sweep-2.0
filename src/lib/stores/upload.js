/**
 * Upload Store
 * State and methods for file upload functionality
 *
 * Uses the modular adapter architecture for API calls
 */

import { writable } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { app } from './app.js';
import { errors } from '../errors.js';
import { debug } from '../debug.js';
import { isFeatureEnabled } from '../../config/features.ts';

export const UPLOAD_DEST_OPTIONS = [
  { id: 'uploads', label: 'Media / uploads', hint: 'WordPress uploads folder' },
  { id: 'wp-content', label: 'wp-content', hint: 'Site content directory' },
];

export const UPLOAD_REINSTALL_CARDS = [
  { id: 'plugins', label: 'Reinstall as plugin', hint: 'Writes into the live plugins directory' },
  { id: 'themes', label: 'Reinstall as theme', hint: 'Writes into the live themes directory' },
];

export const UPLOAD_PATH_CHIPS = [
  { id: 'uploads', label: 'Media', hint: 'WordPress uploads folder' },
  { id: 'wp-content', label: 'wp-content', hint: 'Site content directory' },
  { id: 'root', label: 'WordPress root', hint: 'Writes at the site root' },
];

const EXTRACT_DESTS = new Set(['uploads', 'wp-content', 'root', 'custom']);
const PR1_DESTS = new Set(['uploads', 'wp-content']);
const PACKAGE_DESTS = new Set(['plugins', 'themes']);

function destLabelFor(id, customRel = '') {
  if (id === 'plugins') return 'Plugin';
  if (id === 'themes') return 'Theme';
  if (id === 'custom') {
    return customRel ? customRel : 'custom path';
  }
  if (id === 'root') return 'WordPress root';
  if (id === 'uploads') return 'Media / uploads';
  if (id === 'wp-content') return 'wp-content';
  const found = UPLOAD_DEST_OPTIONS.find((opt) => opt.id === id)
    || UPLOAD_PATH_CHIPS.find((opt) => opt.id === id)
    || UPLOAD_REINSTALL_CARDS.find((opt) => opt.id === id);
  return found ? found.label : id || '';
}

function isPackageDest(id) {
  return PACKAGE_DESTS.has(id);
}

function isExtractDest(id) {
  if (!id) return false;
  if (isFeatureEnabled('uploadCustomPath')) return EXTRACT_DESTS.has(id);
  return PR1_DESTS.has(id);
}

let queueSeq = 0;

function nextQueueId() {
  queueSeq += 1;
  return `q_${queueSeq}_${Date.now().toString(36)}`;
}

function isBusy(state) {
  return !!(state && (state.uploading || state.installing));
}

function initialState() {
  return {
    uploadQueue: [],
    destination: 'plugins',
    mixedBatch: false,
    customRel: '',
    createBackup: true,
    extractOpen: false,
    confirmOpen: false,
    confirmRoot: false,
    uploading: false,
    installing: false,
    uploadProgress: 0,
    installProgress: 0,
    installMessage: '',
    uploadResult: null,
    batchResult: null,
    leftoverUploadIds: [],
    dragOver: false,
    error: null,
    browseOpen: false,
    browsePath: '',
    browseEntries: [],
    browseLoading: false,
    hostLimitBytes: 0,
    hostPostMaxSize: '',
    hostUploadMaxFilesize: '',
  };
}

function isRootRel(rel) {
  const v = String(rel || '').replace(/\\/g, '/').trim();
  if (v === '' || v === '.' || v === './') return true;
  return /^(?:\.\/)+$/.test(v);
}

function destIsValid(state) {
  if (!state.destination) return false;
  if (isPackageDest(state.destination)) return true;
  if (!isExtractDest(state.destination)) return false;
  if (state.destination === 'custom') {
    const rel = String(state.customRel || '').trim();
    return rel !== '' && !isRootRel(rel);
  }
  return true;
}

function writesAtRoot(state) {
  return state.destination === 'root' || (state.destination === 'custom' && isRootRel(state.customRel));
}

/** Operator chose Extract files to a path (not Reinstall as plugin/theme). */
function isExtractMode(state) {
  return !!(state && state.extractOpen && isExtractDest(state.destination));
}

/** @returns {{ plugin: number, theme: number, unknown: number, other: number }} */
function queuePackageKinds(queue) {
  let plugin = 0;
  let theme = 0;
  let unknown = 0;
  let other = 0;
  for (const row of queue || []) {
    const kind = row?.inspect?.kind;
    if (kind === 'plugin') plugin += 1;
    else if (kind === 'theme') theme += 1;
    else if (kind === 'unknown') unknown += 1;
    else if (kind) other += 1;
  }
  return { plugin, theme, unknown, other };
}

/** True when every inspected row is a plugin or theme package. */
function isSmartPackageQueue(queue) {
  const rows = queue || [];
  if (rows.length === 0) return false;
  return rows.every((row) => {
    const kind = row?.inspect?.kind;
    return kind === 'plugin' || kind === 'theme';
  });
}

function isMixedPackageQueue(queue) {
  const c = queuePackageKinds(queue);
  return c.plugin > 0 && c.theme > 0;
}

/** Human summary for confirm / banner, e.g. "2 plugins → plugins/, 1 theme → themes/". */
function smartBatchSummary(queue) {
  const c = queuePackageKinds(queue);
  const parts = [];
  if (c.plugin > 0) {
    parts.push(`${c.plugin} plugin${c.plugin === 1 ? '' : 's'} → plugins/`);
  }
  if (c.theme > 0) {
    parts.push(`${c.theme} theme${c.theme === 1 ? '' : 's'} → themes/`);
  }
  return parts.join(', ');
}

function packageBanner(queue, extractMode = false) {
  if (extractMode) return '';
  if (!isMixedPackageQueue(queue)) return '';
  if (!isSmartPackageQueue(queue)) {
    return 'This batch mixes packages with other ZIPs. Upload plugins/themes separately from path extracts.';
  }
  return `Mixed batch will auto-route: ${smartBatchSummary(queue)}.`;
}

function mixBlocksConfirm(state) {
  if (isExtractMode(state)) return false;
  const counts = queuePackageKinds(state.uploadQueue);
  return (counts.plugin > 0 || counts.theme > 0) && (counts.unknown > 0 || counts.other > 0);
}

/**
 * Per-ZIP install target. Reinstall cards route plugin/theme ZIPs by inspect.kind.
 * Extract-to-path always uses the operator-chosen destination.
 */
function resolveItemRoute(item, state) {
  const kind = item?.inspect?.kind;
  if (!isExtractMode(state) && kind === 'plugin') {
    return { destination: 'plugins', customRel: '', package: true, label: destLabelFor('plugins') };
  }
  if (!isExtractMode(state) && kind === 'theme') {
    return { destination: 'themes', customRel: '', package: true, label: destLabelFor('themes') };
  }
  const dest = writesAtRoot(state) ? 'root' : state.destination;
  const customRel = dest === 'custom' ? String(state.customRel || '').trim() : '';
  return {
    destination: dest,
    customRel,
    package: isPackageDest(dest),
    label: destLabelFor(dest, customRel),
  };
}

function backupEligibleBatch(state) {
  if (!isFeatureEnabled('uploadPackageBackup')) return false;
  if (isExtractMode(state)) return false;
  const queue = state.uploadQueue || [];
  const smart = isSmartPackageQueue(queue);
  if (!smart && !isPackageDest(state.destination)) return false;
  return queue.some(
    (row) =>
      (row.inspect?.kind === 'plugin' || row.inspect?.kind === 'theme')
      && row.inspect?.backup_eligible
      && row.inspect?.existing?.present
  );
}

function backupBlockedReason(state) {
  if (!isFeatureEnabled('uploadPackageBackup')) return '';
  if (isExtractMode(state)) return '';
  const queue = state.uploadQueue || [];
  const smart = isSmartPackageQueue(queue);
  if (!smart && !isPackageDest(state.destination)) return '';
  const packageRows = smart
    ? queue.filter((row) => row.inspect?.kind === 'plugin' || row.inspect?.kind === 'theme')
    : queue;
  if (packageRows.some((row) => row.inspect?.existing?.present && !row.inspect?.backup_eligible)) {
    return 'Archive has no single top-level folder.';
  }
  return '';
}

function createUploadStore() {
  const { subscribe, set, update } = writable(initialState());

  function readState() {
    let state;
    subscribe((s) => { state = s; })();
    return state;
  }

  async function discardId(uploadId) {
    if (!uploadId) return;
    try {
      await adapters.upload.discard(uploadId);
    } catch (e) {
      debug.error('UPLOAD', 'Discard failed', e.message);
    }
  }

  return {
    subscribe,

    destLabel: destLabelFor,
    packageBanner,
    queuePackageKinds,
    isSmartPackageQueue,
    isMixedPackageQueue,
    smartBatchSummary,
    resolveItemRoute,
    destIsValid,
    isExtractDest,
    isExtractMode,
    isPackageDest,
    writesAtRoot,
    isRootRel,
    backupEligibleBatch,
    backupBlockedReason,

    /**
     * Handle file drop
     */
    handleFileDrop: (event) => {
      event.preventDefault();
      update(s => ({ ...s, dragOver: false }));
      if (isBusy(readState())) return;
      queueZipFiles(event.dataTransfer.files, update, readState().hostLimitBytes);
    },

    /**
     * Handle file selection
     */
    handleFileSelect: (event) => {
      if (isBusy(readState())) return;
      queueZipFiles(event.target.files, update, readState().hostLimitBytes);
    },

    /**
     * Add files to queue
     */
    addFilesToQueue: (files) => {
      if (isBusy(readState())) return;
      queueZipFiles(files, update, readState().hostLimitBytes);
    },

    /**
     * Host PHP cap from get_upload_limits. Best-effort; staging still checks server-side.
     */
    async loadLimits() {
      const state = readState();
      if (state.hostLimitBytes > 0) return;
      try {
        const response = await adapters.upload.limits();
        const bytes = response.success ? Number(response.data?.limit_bytes) : 0;
        if (bytes > 0) {
          update(s => ({
            ...s,
            hostLimitBytes: bytes,
            hostPostMaxSize: response.data?.post_max_size || '',
            hostUploadMaxFilesize: response.data?.upload_max_filesize || '',
          }));
        }
      } catch (e) {
        debug.error('UPLOAD', 'Failed to load host upload limit', e.message);
      }
    },

    /**
     * Remove file from queue
     */
    removeFromQueue: (index) => {
      if (isBusy(readState())) return;
      const state = readState();
      const item = state.uploadQueue[index];
      if (item?.uploadId) {
        discardId(item.uploadId);
      }
      update(s => ({
        ...s,
        uploadQueue: s.uploadQueue.filter((_, i) => i !== index)
      }));
    },

    /**
     * Clear queue
     */
    clearQueue: () => {
      if (isBusy(readState())) return;
      const state = readState();
      for (const item of state.uploadQueue) {
        if (item.uploadId) discardId(item.uploadId);
      }
      update(s => ({ ...s, uploadQueue: [], mixedBatch: false }));
    },

    /**
     * Idle default is plugins. Extract dests expand the path panel.
     */
    setDestination: (id) => {
      if (isPackageDest(id)) {
        update(s => ({
          ...s,
          destination: id,
          extractOpen: false,
          customRel: '',
          confirmRoot: false,
        }));
        return;
      }
      if (!isExtractDest(id)) {
        update(s => ({ ...s, destination: null }));
        return;
      }
      update(s => ({
        ...s,
        destination: id,
        extractOpen: true,
        customRel: id === 'custom' ? s.customRel : '',
        confirmRoot: id === 'root' ? s.confirmRoot : false,
      }));
    },

    openExtractPath: () => {
      update(s => ({
        ...s,
        destination: s.extractOpen && isExtractDest(s.destination) ? s.destination : 'uploads',
        extractOpen: true,
        confirmRoot: false,
      }));
    },

    setCreateBackup: (on) => {
      if (!isFeatureEnabled('uploadPackageBackup')) return;
      update(s => ({ ...s, createBackup: !!on }));
    },

    setCustomRel: (rel) => {
      if (!isFeatureEnabled('uploadCustomPath')) return;
      const value = String(rel || '').replace(/\\/g, '/');
      if (isRootRel(value)) {
        update(s => ({
          ...s,
          destination: 'root',
          customRel: '',
          extractOpen: true,
          confirmRoot: false,
        }));
        return;
      }
      update(s => ({
        ...s,
        destination: 'custom',
        customRel: value,
        extractOpen: true,
        confirmRoot: false,
      }));
    },

    setConfirmRoot: (on) => {
      update(s => ({ ...s, confirmRoot: !!on }));
    },

    closeConfirm: () => {
      update(s => ({ ...s, confirmOpen: false }));
    },

    async openBrowse(path) {
      if (!isFeatureEnabled('uploadCustomPath')) return;
      const nextPath = path === undefined ? readState().browsePath : String(path || '');
      update(s => ({ ...s, browseOpen: true, browseLoading: true, browsePath: nextPath }));
      try {
        const response = await adapters.files.listDirectory(nextPath);
        const files = response.success && response.data?.files
          ? response.data.files.filter((f) => f.type === 'folder')
          : [];
        update(s => ({
          ...s,
          browseEntries: files,
          browseLoading: false,
          browsePath: response.data?.path ?? nextPath,
        }));
      } catch (e) {
        update(s => ({ ...s, browseLoading: false, browseEntries: [] }));
        errors.add({ message: e.message || 'Could not list directory', code: 'BROWSE_ERROR' });
      }
    },

    closeBrowse: () => {
      update(s => ({ ...s, browseOpen: false }));
    },

    useBrowseFolder: () => {
      const state = readState();
      const rel = String(state.browsePath || '').replace(/^\/+/, '');
      if (isRootRel(rel)) {
        update(s => ({
          ...s,
          destination: 'root',
          customRel: '',
          browseOpen: false,
          confirmRoot: false,
        }));
        return;
      }
      update(s => ({
        ...s,
        destination: 'custom',
        customRel: rel,
        browseOpen: false,
        confirmRoot: false,
      }));
    },

    formatFileSize,

    /**
     * Stage queued ZIPs. Does not extract.
     */
    async stageAll() {
      const state = readState();
      if (state.uploadQueue.length === 0) {
        errors.add({ message: 'No files in queue', code: 'EMPTY_QUEUE' });
        return false;
      }

      update(s => ({
        ...s,
        uploading: true,
        uploadProgress: 0,
        error: null
      }));

      app.setProgress(0, 'Uploading...', 'running');
      debug.log('UPLOAD', 'Staging upload', { count: state.uploadQueue.length });

      try {
        const queue = [...state.uploadQueue];
        for (let i = 0; i < queue.length; i++) {
          const item = queue[i];
          if (item.uploadId) continue;

          const rowId = item.id;
          const file = item.file || item;
          update(s => ({
            ...s,
            uploadQueue: s.uploadQueue.map((row) =>
              row.id === rowId ? { ...row, status: 'staging' } : row
            )
          }));

          const hostLimit = readState().hostLimitBytes;
          if (hostLimit > 0 && file.size > hostLimit) {
            const tooLarge = `${file.name} is ${formatFileSize(file.size)}; this host allows ${formatFileSize(hostLimit)}. Raise post_max_size and upload_max_filesize, then retry.`;
            update(s => ({
              ...s,
              uploading: false,
              error: tooLarge,
              uploadQueue: s.uploadQueue.map((row) =>
                row.id === rowId
                  ? { ...row, status: 'error', error: tooLarge }
                  : row
              )
            }));
            errors.add({ message: tooLarge, code: 'FILE_TOO_LARGE' });
            return false;
          }

          const response = await adapters.upload.uploadZip(file, (percent) => {
            const overall = Math.round(((i + percent / 100) / queue.length) * 80);
            update(s => ({ ...s, uploadProgress: overall }));
          });

          if (!response.success || !response.data?.upload_id) {
            const failMsg = response.error || 'Upload failed';
            const failCode = response.code || 'UPLOAD_ERROR';
            update(s => ({
              ...s,
              uploading: false,
              error: failMsg,
              uploadQueue: s.uploadQueue.map((row) =>
                row.id === rowId
                  ? { ...row, status: 'error', error: failMsg }
                  : row
              )
            }));
            errors.add({ message: failMsg, code: failCode, details: response.details || null });
            return false;
          }

          update(s => ({
            ...s,
            uploadQueue: s.uploadQueue.map((row) =>
              row.id === rowId
                ? { ...row, uploadId: response.data.upload_id, status: 'staged', error: null }
                : row
            )
          }));
        }

        update(s => ({ ...s, uploading: false, uploadProgress: 80 }));
        app.setProgress(80, 'Staged. Review destination to extract.', 'running');
        return true;
      } catch (e) {
        debug.error('UPLOAD', 'Failed', e.message);
        update(s => ({
          ...s,
          uploading: false,
          error: e.message
        }));
        errors.add({ message: e.message, code: 'UPLOAD_ERROR' });
        return false;
      }
    },

    /**
     * Inspect each staged file. May set dest to plugins/themes when kinds agree.
     */
    async inspectAll() {
      if (!isFeatureEnabled('uploadInspect')) {
        return true;
      }

      const state = readState();
      const queue = [...state.uploadQueue];
      update(s => ({ ...s, uploading: true }));
      app.setProgress(85, 'Inspecting archives…', 'running');

      try {
        for (let i = 0; i < queue.length; i++) {
          const item = queue[i];
          const uploadId = item.uploadId;
          if (!uploadId) {
            errors.add({ message: `${(item.file || item).name} is not staged`, code: 'UPLOAD_NOT_FOUND' });
            update(s => ({ ...s, uploading: false }));
            return false;
          }
          if (item.inspect && item.status === 'inspected') continue;

          const rowId = item.id;
          update(s => ({
            ...s,
            uploadQueue: s.uploadQueue.map((row) =>
              row.id === rowId ? { ...row, status: 'inspecting' } : row
            )
          }));

          const response = await adapters.upload.inspect(uploadId);
          if (!response.success || !response.data) {
            update(s => ({
              ...s,
              uploading: false,
              error: response.error || 'Inspect failed',
              uploadQueue: s.uploadQueue.map((row) =>
                row.id === rowId
                  ? {
                      ...row,
                      status: response.code === 'UNSAFE_ZIP' ? 'unsafe' : 'error',
                      error: response.error || 'Inspect failed',
                    }
                  : row
              )
            }));
            errors.add({
              message: response.error || 'Inspect failed',
              code: response.code || 'UNSAFE_ZIP',
            });
            return false;
          }

          update(s => ({
            ...s,
            uploadQueue: s.uploadQueue.map((row) =>
              row.id === rowId
                ? { ...row, inspect: response.data, status: 'inspected', error: null }
                : row
            )
          }));
        }

        const after = readState();
        const extractMode = isExtractMode(after);
        const smart = isSmartPackageQueue(after.uploadQueue);
        const mixed = isMixedPackageQueue(after.uploadQueue);
        const allPlugin = after.uploadQueue.length > 0
          && after.uploadQueue.every((row) => row.inspect?.kind === 'plugin');
        const allTheme = after.uploadQueue.length > 0
          && after.uploadQueue.every((row) => row.inspect?.kind === 'theme');
        update(s => {
          const next = {
            ...s,
            uploading: false,
            uploadProgress: 90,
            mixedBatch: mixed,
          };
          // Extract-to-path: keep the operator destination even for plugin/theme ZIPs.
          if (isExtractMode(s)) {
            return next;
          }
          // Reinstall cards: homogeneous packages snap dest; mixed smart batches route per ZIP.
          if (allPlugin) {
            return { ...next, destination: 'plugins', extractOpen: false };
          }
          if (allTheme) {
            return { ...next, destination: 'themes', extractOpen: false };
          }
          if (smart && mixed) {
            return { ...next, extractOpen: false };
          }
          return next;
        });
        app.setProgress(
          90,
          extractMode
            ? 'Inspected. Confirm extract path.'
            : (smart && mixed
              ? `Inspected. Will auto-route: ${smartBatchSummary(after.uploadQueue)}.`
              : 'Inspected. Confirm destination.'),
          'running'
        );
        return true;
      } catch (e) {
        debug.error('UPLOAD', 'Inspect failed', e.message);
        update(s => ({ ...s, uploading: false, error: e.message }));
        errors.add({ message: e.message, code: 'UNSAFE_ZIP' });
        return false;
      }
    },

    openConfirm() {
      const state = readState();
      const extractMode = isExtractMode(state);
      const smart = isSmartPackageQueue(state.uploadQueue);

      // Reinstall only: packages + raw/unknown cannot share one confirm path.
      if (mixBlocksConfirm(state)) {
        errors.add({
          message: 'This batch mixes packages with other ZIPs. Upload plugins/themes separately from path extracts.',
          code: 'DEST_MISMATCH',
        });
        return false;
      }

      if ((extractMode || !smart) && !destIsValid(state)) {
        errors.add({ message: 'Choose where to extract the files', code: 'MISSING_DESTINATION' });
        return false;
      }
      update(s => ({
        ...s,
        confirmOpen: true,
        confirmRoot: writesAtRoot(s) ? false : s.confirmRoot,
        createBackup: backupEligibleBatch(s) ? s.createBackup : false,
      }));
      return true;
    },

    /**
     * Stage only — never extract here.
     */
    async startUpload() {
      return this.stageAll();
    },

    /**
     * Stage + inspect, then open confirm. Does not write.
     */
    async reviewAndExtract() {
      const state = readState();
      if (state.uploadQueue.length === 0) {
        errors.add({ message: 'No files in queue', code: 'EMPTY_QUEUE' });
        return;
      }
      // Destination is required up front for raw extracts; package ZIPs are
      // routed after inspect. Default card (plugins) keeps destIsValid true.
      if (!destIsValid(state)) {
        errors.add({ message: 'Choose where to extract the files', code: 'MISSING_DESTINATION' });
        return;
      }

      const staged = await this.stageAll();
      if (!staged) return;
      const inspected = await this.inspectAll();
      if (!inspected) return;
      this.openConfirm();
    },

    /**
     * Confirm modal CTA — serial extract. Reinstall cards route by inspect.kind;
     * extract-to-path uses the chosen destination for every ZIP.
     */
    async confirmAndInstall() {
      const state = readState();
      const extractMode = isExtractMode(state);
      const smart = isSmartPackageQueue(state.uploadQueue);
      if ((extractMode || !smart) && !destIsValid(state)) {
        errors.add({ message: 'Choose where to extract the files', code: 'MISSING_DESTINATION' });
        return;
      }
      if ((extractMode || !smart) && writesAtRoot(state) && !state.confirmRoot) {
        errors.add({
          message: 'Confirm that you understand this writes at the WordPress root',
          code: 'OVERWRITE_NOT_CONFIRMED',
        });
        return;
      }

      if (mixBlocksConfirm(state)) {
        errors.add({
          message: 'This batch mixes packages with other ZIPs. Upload plugins/themes separately from path extracts.',
          code: 'DEST_MISMATCH',
        });
        return;
      }

      const fallbackDest = writesAtRoot(state) ? 'root' : state.destination;
      const fallbackLabel = destLabelFor(
        fallbackDest,
        fallbackDest === 'custom' ? String(state.customRel || '').trim() : ''
      );
      const mixed = isMixedPackageQueue(state.uploadQueue);
      const startMsg = extractMode
        ? `Extracting to ${fallbackLabel}…`
        : (smart
          ? (mixed
              ? `Reinstalling mixed batch (${smartBatchSummary(state.uploadQueue)})…`
              : `Reinstalling to ${fallbackLabel}…`)
          : `Extracting to ${fallbackLabel}…`);

      update(s => ({
        ...s,
        confirmOpen: false,
        installing: true,
        installProgress: 0,
        installMessage: startMsg,
        error: null
      }));
      app.setProgress(90, startMsg, 'running');

      try {
        const ok = [];
        const failed = [];
        const kindOrder = { plugin: 0, theme: 1 };
        const queue = [...readState().uploadQueue].sort((a, b) => {
          const ak = kindOrder[a?.inspect?.kind] ?? 2;
          const bk = kindOrder[b?.inspect?.kind] ?? 2;
          return ak - bk;
        });
        const backupWanted = isFeatureEnabled('uploadPackageBackup') && state.createBackup;

        for (let i = 0; i < queue.length; i++) {
          const item = queue[i];
          const uploadId = item.uploadId;
          const name = (item.file || item).name;
          if (!uploadId) {
            failed.push({ name, error: 'Not staged' });
            continue;
          }
          if (item.status === 'unsafe') {
            failed.push({ name, uploadId, error: item.error || 'Unsafe ZIP' });
            continue;
          }

          const route = resolveItemRoute(item, state);
          const itemDest = route.destination;
          const itemPkg = route.package;
          const itemLabel = route.label;

          update(s => ({
            ...s,
            installProgress: Math.round((i / queue.length) * 100),
            installMessage: itemPkg
              ? `Reinstalling ${name} → ${itemLabel}…`
              : `Extracting ${name} → ${itemLabel}…`,
            uploadQueue: s.uploadQueue.map((row) =>
              row.id === item.id ? { ...row, status: 'extracting' } : row
            )
          }));

          const extracted = await adapters.upload.extract({
            uploadId,
            destination: itemDest,
            customRel: itemDest === 'custom' ? route.customRel : undefined,
            confirmOverwrite: true,
            createBackup: !!(backupWanted && itemPkg && item.inspect?.backup_eligible),
            confirmRoot: itemDest === 'root' ? true : undefined,
          });

          if (!extracted.success) {
            const failMeta = extractFailMeta(extracted);
            failed.push({
              name,
              uploadId,
              error: extracted.error || 'Extract failed',
              code: extracted.code,
              destination_name: failMeta.destination_name,
              backup_rel: failMeta.backup_rel,
            });
            errors.add({
              message: extracted.error || 'Extract failed',
              code: extracted.code || 'EXTRACT_ERROR',
            });
            update(s => ({
              ...s,
              uploadQueue: s.uploadQueue.map((row) =>
                row.id === item.id
                  ? { ...row, status: 'error', error: extracted.error || 'Extract failed' }
                  : row
              )
            }));
            continue;
          }
          ok.push({
            name,
            uploadId,
            destination: itemDest,
            results: extracted.data?.results,
          });
        }

        const success = failed.length === 0 && ok.length > 0;
        const anyReinstalled = ok.some((row) => {
          const mode = row.results?.mode || '';
          return isPackageDest(row.destination)
            || mode === 'plugin_upgrader'
            || mode === 'theme_upgrader';
        });
        const destRel = !extractMode && mixed && smart
          ? smartBatchSummary(queue)
          : (ok[0]?.results?.destination_rel || fallbackLabel);
        const doneMsg = anyReinstalled
          ? (mixed && smart
              ? `Reinstalled mixed batch (${destRel}). If they were already active, they stay active.`
              : 'Reinstalled. If it was already active, it stays active.')
          : `Extracted to ${destRel}. New packages stay inactive. Existing ones keep their current active state.`;
        const leftoverUploadIds = leftoverIdsFromQueue(queue, ok);
        update(s => ({
          ...s,
          installing: false,
          uploading: false,
          uploadProgress: success ? 100 : s.uploadProgress,
          installProgress: 100,
          installMessage: success ? doneMsg : (failed[0]?.error || 'Extract failed'),
          uploadResult: success
            ? {
                destination: !extractMode && mixed && smart ? 'mixed' : (ok[0]?.destination || fallbackDest),
                destination_rel: destRel,
                extracted: ok,
                reinstalled: anyReinstalled,
                slug: ok[0]?.results?.slug || ok[0]?.results?.destination_name || '',
                backup_rel: ok[0]?.results?.backup_rel || '',
              }
            : null,
          batchResult: { ok, failed },
          leftoverUploadIds,
          uploadQueue: success ? [] : s.uploadQueue,
          mixedBatch: success ? false : s.mixedBatch,
          error: success ? null : (failed[0]?.error || 'Extract failed'),
        }));

        if (success) {
          app.setProgress(100, anyReinstalled ? 'Reinstalled.' : `Extracted to ${destRel}.`, 'complete');
        } else {
          app.setProgress(0, failed[0]?.error || 'Extract failed', 'error');
        }
      } catch (e) {
        debug.error('UPLOAD', 'Extract failed', e.message);
        update(s => ({
          ...s,
          installing: false,
          uploading: false,
          error: e.message
        }));
        errors.add({ message: e.message, code: 'EXTRACT_ERROR' });
      }
    },

    /**
     * Set drag over state
     */
    setDragOver: (isOver) => {
      update(s => ({ ...s, dragOver: isOver }));
    },

    /**
     * Reset store
     */
    reset: () => {
      set(initialState());
      app.resetProgress();
    },

    /**
     * Idle again: Plugin card selected, extract-to-path collapsed. Does not discard temps.
     */
    installAnother() {
      set(initialState());
      app.resetProgress();
    },

    /**
     * Discard remaining staged temps, then idle.
     */
    async discardLeftovers() {
      const state = readState();
      if (isBusy(state)) return;
      const ids = leftoverIdsFrom(state);
      for (const id of ids) {
        await discardId(id);
      }
      set(initialState());
      app.resetProgress();
    },

    /**
     * Switch to Extensions and start analyze only because the operator clicked.
     */
    analyzeExtensions() {
      const state = readState();
      const dest = state.uploadResult?.destination || state.destination;
      app.requestExtensionsAnalyze(dest === 'themes' ? 'themes' : 'plugins');
    }
  };
}

function leftoverIdsFromQueue(queue, ok) {
  const done = new Set((ok || []).map((row) => row.uploadId).filter(Boolean));
  const ids = [];
  const seen = new Set();
  for (const item of queue || []) {
    const id = item.uploadId;
    if (!id || done.has(id) || seen.has(id)) continue;
    seen.add(id);
    ids.push(id);
  }
  return ids;
}

function leftoverIdsFrom(state) {
  const ids = [];
  const seen = new Set();
  const add = (id) => {
    if (!id || seen.has(id)) return;
    seen.add(id);
    ids.push(id);
  };
  for (const id of state.leftoverUploadIds || []) add(id);
  for (const item of state.uploadQueue || []) add(item.uploadId);
  for (const row of state.batchResult?.failed || []) add(row.uploadId);
  return ids;
}

function extractFailMeta(extracted) {
  const details = extracted?.details || {};
  let destination_name = details.destination_name || '';
  let backup_rel = details.backup_rel || '';
  if ((!destination_name || !backup_rel) && typeof details.errorText === 'string') {
    try {
      const parsed = JSON.parse(details.errorText);
      destination_name = destination_name || parsed?.details?.destination_name || '';
      backup_rel = backup_rel || parsed?.details?.backup_rel || '';
    } catch {
      // keep empty — do not invent a preview slug
    }
  }
  return { destination_name, backup_rel };
}

function formatFileSize(bytes) {
  const n = Number(bytes) || 0;
  if (n === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.min(sizes.length - 1, Math.floor(Math.log(n) / Math.log(k)));
  return parseFloat((n / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function queueZipFiles(files, update, limitBytes = 0) {
  if (!files || !files.length) return;
  update(s => {
    const newFiles = [];
    for (const file of files) {
      if (!file.name || !String(file.name).toLowerCase().endsWith('.zip')) {
        errors.add({
          message: `${file.name || 'File'} is not a ZIP file`,
          code: 'INVALID_FILE_TYPE',
          level: 'warning'
        });
        continue;
      }
      if (limitBytes > 0 && file.size > limitBytes) {
        errors.add({
          message: `${file.name} is ${formatFileSize(file.size)}; this host allows ${formatFileSize(limitBytes)}. Raise post_max_size and upload_max_filesize, then retry.`,
          code: 'FILE_TOO_LARGE',
        });
        continue;
      }
      newFiles.push({
        id: nextQueueId(),
        file,
        uploadId: null,
        inspect: null,
        status: 'queued',
        error: null,
      });
    }
    return { ...s, uploadQueue: [...s.uploadQueue, ...newFiles], uploadResult: null };
  });
}

export const upload = createUploadStore();
