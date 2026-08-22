/**
 * Integrity Store
 * State and methods for Integrity Baseline Management
 * 
 * Uses the modular adapter architecture for API calls
 */

import { writable } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { app } from './app.js';
import { errors } from '../errors.js';
import { debug } from '../debug.js';

function createIntegrityStore() {
  const { subscribe, set, update } = writable({
    // Baseline state
    baselineExists: false,
    baselineInfo: null,
    baselineMode: 'none',
    visitStatus: null,
    lastSecret: null,
    lastExportScopes: null,
    lastPinWarnings: [],
    lastPinWarningGroups: null,
    pinnedFileCount: 0,
    importing: false,
    exporting: false,
    liveWatchBusy: false,
    
    // Check state
    checking: false,
    checkResults: null,
    checkProgress: 0,
    checkMessage: 'Ready',
    
    // Establish state
    establishing: false,
    establishProgress: 0,
    establishMessage: 'Ready',
    
    // UI state
    activeSection: 'overview', // 'overview', 'establish', 'check', 'results'
    error: null
  });
  
  let pollCancel = null;
  
  return {
    subscribe,
    
    /**
     * Set active section
     */
    setSection: (section) => {
      update(s => ({ ...s, activeSection: section }));
    },

    /**
     * Patch visitStatus without a round-trip (e.g. after plugin/theme analyze).
     */
    mergeVisitStatus(partial) {
      if (!partial || typeof partial !== 'object') return;
      update(s => {
        const prev = s.visitStatus || s.baselineInfo || {};
        const next = { ...prev, ...partial };
        return { ...s, visitStatus: next, baselineInfo: s.baselineInfo ? { ...s.baselineInfo, ...partial } : s.baselineInfo };
      });
    },
    
    /**
     * Check if baseline exists and load info
     */
    async loadBaselineInfo() {
      debug.log('INTEGRITY', 'Loading visit status');
      
      try {
        const response = await adapters.integrity.getBaselineInfo();
        if (response.code === 'TOOLKIT_GONE') {
          return;
        }
        
        if (response.success) {
          const d = response.data || {};
          try {
            const prev = JSON.parse(sessionStorage.getItem('cs_canary') || '{}');
            const next = d.canary || {};
            const mismatch = [];
            for (const k of Object.keys(prev)) {
              if (next[k] && prev[k] && next[k] !== prev[k]) mismatch.push(k);
            }
            sessionStorage.setItem('cs_canary', JSON.stringify(next));
            if (mismatch.length) {
              d.canary_mismatch = mismatch;
            }
          } catch (_) { /* ignore */ }
          update(s => ({
            ...s,
            baselineExists: !!d.has_baseline,
            baselineInfo: d,
            baselineMode: d.core_sealed ? 'core' : 'none',
            visitStatus: d,
            liveWatchBusy: false,
            error: null,
          }));
        } else {
          update(s => ({
            ...s,
            baselineExists: false,
            baselineInfo: null,
            error: response.error
          }));
        }
      } catch (e) {
        debug.error('INTEGRITY', 'Failed to load visit status', e.message);
        update(s => ({
          ...s,
          baselineExists: false,
          error: e.message
        }));
      }
    },

    async enableLiveWatch() {
      update(s => ({ ...s, liveWatchBusy: true, error: null }));
      try {
        const response = await adapters.integrity.enableLiveWatch();
        if (response.success) {
          const d = response.data || {};
          update(s => ({
            ...s,
            visitStatus: { ...(s.visitStatus || {}), ...d },
            baselineInfo: { ...(s.baselineInfo || {}), ...d },
            liveWatchBusy: false,
          }));
          return true;
        }
        update(s => ({ ...s, liveWatchBusy: false, error: response.error || 'Failed to enable live watch' }));
        return false;
      } catch (e) {
        update(s => ({ ...s, liveWatchBusy: false, error: e.message }));
        return false;
      }
    },

    async disableLiveWatch() {
      update(s => ({ ...s, liveWatchBusy: true, error: null }));
      try {
        const response = await adapters.integrity.disableLiveWatch();
        if (response.success) {
          const d = response.data || {};
          update(s => ({
            ...s,
            visitStatus: { ...(s.visitStatus || {}), ...d },
            baselineInfo: { ...(s.baselineInfo || {}), ...d },
            liveWatchBusy: false,
          }));
          return true;
        }
        update(s => ({ ...s, liveWatchBusy: false, error: response.error || 'Failed to disable live watch' }));
        return false;
      } catch (e) {
        update(s => ({ ...s, liveWatchBusy: false, error: e.message }));
        return false;
      }
    },

    async liveWatchTick() {
      try {
        const response = await adapters.integrity.liveWatchTick();
        if (response.success) {
          const d = response.data || {};
          update(s => ({
            ...s,
            visitStatus: { ...(s.visitStatus || {}), ...d },
            baselineInfo: { ...(s.baselineInfo || {}), ...d },
          }));
        }
        return response;
      } catch (e) {
        debug.error('INTEGRITY', 'live watch tick failed', e.message);
        return null;
      }
    },

    async exportSnapshot() {
      update(s => ({ ...s, exporting: true, error: null }));
      try {
        const response = await adapters.integrity.exportSnapshot();
        if (response.success && response.data?.data) {
          const blob = new Blob([response.data.data], { type: 'application/json' });
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = response.data.filename || 'clean-sweep-snapshot.json';
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          URL.revokeObjectURL(url);
          update(s => ({
            ...s,
            lastSecret: response.data.secret || null,
            lastExportScopes: response.data.scopes || null,
            lastPinWarnings: response.data.pin_warnings || [],
            lastPinWarningGroups: response.data.pin_warning_groups || null,
            pinnedFileCount: response.data.pinned_file_count || 0,
            error: null,
          }));
          await this.loadBaselineInfo();
          return response.data;
        }
        update(s => ({ ...s, error: response.error || 'Export failed' }));
        return null;
      } catch (e) {
        update(s => ({ ...s, error: e.message }));
        return null;
      } finally {
        update(s => ({ ...s, exporting: false }));
      }
    },

    async setIncludeAllMedia(on) {
      await adapters.integrity.setIncludeAllMedia(!!on);
      await this.loadBaselineInfo();
    },

    async importSnapshot(json, secret, confirmLegacy = false) {
      update(s => ({ ...s, importing: true, error: null }));
      try {
        const response = await adapters.integrity.importSnapshot(json, secret, confirmLegacy);
        if (response.success) {
          await this.loadBaselineInfo();
          return { ok: true, compare: response.data?.compare };
        }
        update(s => ({ ...s, error: response.error || 'Import failed' }));
        return {
          ok: false,
          needsLegacy: !!(response.details?.needs_legacy_confirm || response.data?.needs_legacy_confirm || /legacy/i.test(response.error || '')),
        };
      } catch (e) {
        update(s => ({ ...s, error: e.message }));
        return false;
      } finally {
        update(s => ({ ...s, importing: false }));
      }
    },

    async findElsewhere(basename, hash = '') {
      const response = await adapters.integrity.findElsewhere(basename, hash);
      if (response.success) {
        return response.data;
      }
      throw new Error(response.error || 'Find failed');
    },

    async skipSnapshot() {
      try {
        await adapters.integrity.skipSnapshot();
        await this.loadBaselineInfo();
      } catch (e) {
        update(s => ({ ...s, error: e.message }));
      }
    },
    
    /**
     * Establish a new integrity baseline
     */
    async establishBaseline(mode = 'core') {
      let state;
      subscribe(s => state = s)();
      
      update(s => ({
        ...s,
        establishing: true,
        establishProgress: 0,
        establishMessage: 'Starting baseline establishment...',
        error: null,
        activeSection: 'establish'
      }));
      
      app.setProgress(0, 'Starting integrity baseline...', 'running');
      debug.log('INTEGRITY', 'Establishing baseline', { mode });
      
      // Set comprehensive mode in session for the backend
      if (mode === 'comprehensive') {
        sessionStorage.setItem('clean_sweep_comprehensive_baseline', '1');
      }
      
      try {
        const response = await adapters.integrity.establishBaseline(mode);
        
        if (response.success) {
          // Start polling for progress
          pollCancel = adapters.integrity.pollProgress(response.data.progress_file, (progress) => {
            update(s => ({
              ...s,
              establishProgress: progress.progress || 0,
              establishMessage: progress.message || 'Establishing baseline...'
            }));
            app.setProgress(progress.progress || 0, progress.message || 'Establishing baseline...', progress.status || 'running');
            
            // When complete, load new baseline info
            if (progress.status === 'complete') {
              update(s => ({
                ...s,
                establishing: false,
                establishProgress: 100,
                establishMessage: 'Baseline established successfully!'
              }));
              app.setProgress(100, 'Baseline established!', 'complete');
              
              // Reload baseline info
              setTimeout(() => {
                this.loadBaselineInfo();
              }, 500);
            }
          });
        } else {
          update(s => ({
            ...s,
            establishing: false,
            establishMessage: 'Failed',
            error: response.error
          }));
          errors.add({ message: response.error, code: 'BASELINE_ERROR' });
        }
      } catch (e) {
        debug.error('INTEGRITY', 'Failed to establish baseline', e.message);
        update(s => ({
          ...s,
          establishing: false,
          establishMessage: 'Failed',
          error: e.message
        }));
        errors.add({ message: e.message, code: 'BASELINE_ERROR' });
      }
    },
    
    /**
     * Check integrity against existing baseline
     */
    async checkIntegrity() {
      let state;
      subscribe(s => state = s)();
      
      if (!state.baselineExists) {
        update(s => ({
          ...s,
          error: 'No baseline exists. Establish a baseline first.',
        }));
        errors.add({ message: 'No baseline exists. Establish a baseline first.', code: 'NO_BASELINE' });
        return;
      }

      update(s => ({
        ...s,
        checking: true,
        checkProgress: 0,
        checkMessage: 'Starting integrity check...',
        error: null,
        activeSection: 'check'
      }));
      
      app.setProgress(0, 'Starting integrity check...', 'running');
      debug.log('INTEGRITY', 'Starting integrity check');
      
      try {
        const response = await adapters.integrity.checkIntegrity();
        
        if (response.success) {
          // Start polling for progress
          pollCancel = adapters.integrity.pollProgress(response.data.progress_file, (progress) => {
            update(s => ({
              ...s,
              checkProgress: progress.progress || 0,
              checkMessage: progress.message || 'Checking integrity...'
            }));
            app.setProgress(progress.progress || 0, progress.message || 'Checking integrity...', progress.status || 'running');
            
            // When complete, load results
            if (progress.status === 'complete') {
              update(s => ({
                ...s,
                checking: false,
                checkProgress: 100,
                checkMessage: 'Integrity check complete!',
                checkResults: progress.results,
                activeSection: 'results'
              }));
              app.setProgress(100, 'Integrity check complete!', 'complete');
            }
          });
        } else {
          update(s => ({
            ...s,
            checking: false,
            checkMessage: 'Failed',
            error: response.error
          }));
          errors.add({ message: response.error, code: 'CHECK_ERROR' });
        }
      } catch (e) {
        debug.error('INTEGRITY', 'Failed to check integrity', e.message);
        update(s => ({
          ...s,
          checking: false,
          checkMessage: 'Failed',
          error: e.message
        }));
        errors.add({ message: e.message, code: 'CHECK_ERROR' });
      }
    },
    
    /**
     * Cancel ongoing operation
     */
    cancelOperation: () => {
      if (pollCancel) {
        pollCancel();
        pollCancel = null;
      }
      
      update(s => ({
        ...s,
        establishing: false,
        checking: false,
        establishMessage: 'Cancelled',
        checkMessage: 'Cancelled'
      }));
      
      app.setProgress(0, 'Cancelled', 'idle');
    },
    
    /**
     * Clear results
     */
    clearResults: () => {
      update(s => ({
        ...s,
        checkResults: null,
        activeSection: 'overview'
      }));
    },
    
    /**
     * Reset store
     */
    reset: () => {
      if (pollCancel) {
        pollCancel();
        pollCancel = null;
      }
      
      set({
        baselineExists: false,
        baselineInfo: null,
        baselineMode: 'none',
        visitStatus: null,
        lastSecret: null,
        lastExportScopes: null,
        lastPinWarnings: [],
        pinnedFileCount: 0,
        importing: false,
        checking: false,
        checkResults: null,
        checkProgress: 0,
        checkMessage: 'Ready',
        establishing: false,
        establishProgress: 0,
        establishMessage: 'Ready',
        activeSection: 'overview',
        error: null
      });
      
      app.resetProgress();
    }
  };
}

export const integrity = createIntegrityStore();
