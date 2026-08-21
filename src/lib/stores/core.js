/**
 * Core Store
 * State and methods for WordPress core reinstallation
 * 
 * Uses the modular adapter architecture for API calls
 */

import { writable } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { app } from './app.js';
import { errors } from '../errors.js';
import { debug } from '../debug.js';

function createCoreStore() {
  const { subscribe, set, update } = writable({
    coreVersion: '',
    selectedVersion: 'latest',
    availableVersions: [],
    currentVersion: '',
    latestVersion: '',
    createBackup: true,
    coreScanning: false,
    coreProgress: 0,
    coreProgressMessage: '',
    error: null,
    showBackupChoice: false,
    showBackupSelection: false,  // Show inline backup selection after clicking reinstall
    diskCheck: null,
    backupInProgress: false,
    loadingVersions: true,  // Track if versions are being loaded
    reinstallSuccess: null,
  });
  
  // Polling cancellation function
  let pollCancel = null;
  
  return {
    subscribe,
    
    /**
     * Fetch available version options from API
     */
    async fetchVersionOptions() {
      // Load versions only — do not open the backup/reinstall step until the user
      // picks a version or clicks Core Reinstallation.
      update(s => ({ ...s, loadingVersions: true, showBackupSelection: false, error: null }));
      
      try {
        const response = await adapters.core.getVersionOptions();
        
        if (response.success && response.data) {
          // Get versions - we request 6 from API to have enough after filtering
          const latest = response.data.latest_version || '';
          const current = response.data.current_version || '';
          const allVersions = response.data.versions || [];
          
          // Get unique versions, excluding latest and current (to avoid duplicates in grid)
          const uniqueVersions = [...new Set(allVersions)]
            .filter(v => v !== latest && v !== current)
            .slice(0, 4); // Show up to 4 previous versions
          
          update(s => ({
            ...s,
            availableVersions: uniqueVersions,
            currentVersion: current,
            latestVersion: latest,
            selectedVersion: s.selectedVersion && s.selectedVersion !== 'latest'
              ? s.selectedVersion
              : (latest || current || 'latest'),
            loadingVersions: false
          }));
          debug.log('CORE', 'Version options fetched', response.data);
        } else {
          update(s => ({
            ...s,
            availableVersions: [],
            currentVersion: s.currentVersion || 'unknown',
            latestVersion: s.latestVersion || 'unknown',
            loadingVersions: false
          }));
        }
      } catch (e) {
        debug.error('CORE', 'Failed to fetch version options', e.message);
        update(s => ({
          ...s,
          availableVersions: [],
          loadingVersions: false
        }));
      }
    },
    
    /**
     * Set selected version
     */
    setVersion: (version) => {
      update(s => ({ ...s, selectedVersion: version }));
    },
    
    /**
     * Set backup preference
     */
    setBackupPreference: (createBackup) => {
      update(s => ({ ...s, createBackup }));
    },
    
    /**
     * Set show backup choice flag
     */
    setShowBackupChoice: (show) => {
      update(s => ({ ...s, showBackupChoice: show }));
    },
    
    /**
     * Set disk check data
     */
    setDiskCheck: (diskCheck) => {
      update(s => ({ ...s, diskCheck }));
    },
    
    /**
     * Start WordPress core reinstallation
     * Shows backup selection first, then proceeds with reinstall
     */
    startCoreReinstall() {
      let state;
      subscribe(s => state = s)();

      if (state.loadingVersions) {
        return;
      }

      const version = state.selectedVersion;
      const versionReady = version && version !== 'latest'
        ? true
        : !!(state.latestVersion || state.currentVersion);
      if (!versionReady) {
        update(s => ({
          ...s,
          error: 'Wait for WordPress versions to finish loading, then select one.',
        }));
        return;
      }

      // Prefer a concrete version string for the backup panel title
      const resolved = (version && version !== 'latest')
        ? version
        : (state.latestVersion || state.currentVersion || version);

      update(s => ({
        ...s,
        selectedVersion: resolved,
        showBackupSelection: true,
        error: null
      }));
    },
    
    /**
     * Proceed with reinstall after backup selection is made
     * Uses REAL-TIME polling for progress updates
     */
    markReinstallComplete(message) {
      if (pollCancel) {
        pollCancel();
        pollCancel = null;
      }
      update(s => ({
        ...s,
        coreScanning: false,
        coreProgress: 100,
        coreProgressMessage: 'Complete!',
        backupInProgress: false,
        reinstallSuccess: message || 'WordPress core was reinstalled successfully.',
        error: null,
      }));
      app.setProgress(100, 'Complete!', 'complete');
    },

    attachProgressPoll(progressFile) {
      if (pollCancel) {
        pollCancel();
        pollCancel = null;
      }
      pollCancel = adapters.core.pollReinstallProgress(
        progressFile,
        (pollProgress) => {
          if (!pollProgress || !pollProgress.status || pollProgress.status === 'pending') {
            return;
          }
          const displayProgress = Number(pollProgress.progress) || 0;
          update(s => ({
            ...s,
            coreProgress: displayProgress,
            coreProgressMessage: pollProgress.message || 'Processing...',
            backupInProgress: String(pollProgress.message || '').toLowerCase().includes('backup'),
          }));
          app.setProgress(
            displayProgress,
            pollProgress.message || 'Processing...',
            pollProgress.status || 'running'
          );
          if (pollProgress.status === 'complete') {
            this.markReinstallComplete(pollProgress.message || 'WordPress core was reinstalled successfully.');
          } else if (pollProgress.status === 'error') {
            if (pollCancel) {
              pollCancel();
              pollCancel = null;
            }
            update(s => ({
              ...s,
              coreScanning: false,
              error: pollProgress.message || 'Core re-installation failed',
              coreProgressMessage: 'Failed',
              backupInProgress: false,
            }));
            app.setError(pollProgress.message);
            errors.add({ message: pollProgress.message, code: 'CORE_REINSTALL_ERROR' });
          }
        }
      );
    },

    async proceedWithReinstall() {
      let state;
      subscribe(s => state = s)();
      
      const progressFile = `core_reinstall_${Date.now()}.progress`;

      update(s => ({
        ...s,
        showBackupSelection: false,
        coreScanning: true,
        coreProgress: 1,
        coreProgressMessage: 'Starting…',
        error: null,
        reinstallSuccess: null,
        backupInProgress: state.createBackup
      }));
      
      app.setProgress(1, 'Starting…', 'running');
      debug.log('CORE', 'Starting reinstall', { version: state.selectedVersion, backup: state.createBackup });

      this.attachProgressPoll(progressFile);
      
      try {
        const response = await adapters.core.reinstall(
          state.selectedVersion,
          state.createBackup,
          undefined,
          progressFile
        );
        
        if (!response.success) {
          if (response.details?.backup_choice || response.details?.disk_check) {
            if (pollCancel) {
              pollCancel();
              pollCancel = null;
            }
            update(s => ({
              ...s,
              coreScanning: false,
              showBackupChoice: true,
              diskCheck: response.details.disk_check || null,
              error: null
            }));
            return;
          }
          if (pollCancel) {
            pollCancel();
            pollCancel = null;
          }
          update(s => ({
            ...s,
            coreScanning: false,
            error: response.error || 'Core re-installation failed',
            coreProgressMessage: 'Failed'
          }));
          app.setError(response.error);
          errors.add({ message: response.error, code: 'CORE_REINSTALL_ERROR' });
          return;
        }

        this.markReinstallComplete(
          response.message || response.data?.results?.message || 'WordPress core was reinstalled successfully.'
        );
        
      } catch (e) {
        if (pollCancel) {
          pollCancel();
          pollCancel = null;
        }
        debug.error('CORE', 'Reinstall failed', e.message);
        update(s => ({
          ...s,
          coreScanning: false,
          error: e.message,
          coreProgressMessage: 'Failed',
          backupInProgress: false
        }));
        errors.add({ message: e.message, code: 'CORE_ERROR' });
      }
    },
    
    /**
     * Proceed with reinstall after backup choice
     * Uses REAL-TIME polling for progress updates
     */
    async proceedWithBackupChoice(createBackup) {
      let state;
      subscribe(s => state = s)();
      const progressFile = `core_reinstall_${Date.now()}.progress`;
      
      update(s => ({
        ...s,
        showBackupChoice: false,
        coreScanning: true,
        coreProgress: 1,
        coreProgressMessage: 'Starting...',
        error: null,
        reinstallSuccess: null,
        backupInProgress: createBackup
      }));
      
      app.setProgress(1, 'Starting...', 'running');
      debug.log('CORE', 'Proceeding with reinstall', { version: state.selectedVersion, backup: createBackup });
      this.attachProgressPoll(progressFile);
      
      try {
        const response = await adapters.core.reinstall(
          state.selectedVersion,
          createBackup,
          undefined,
          progressFile
        );
        
        if (!response.success) {
          if (pollCancel) {
            pollCancel();
            pollCancel = null;
          }
          update(s => ({
            ...s,
            coreScanning: false,
            error: response.error || 'Core re-installation failed',
            coreProgressMessage: 'Failed',
            backupInProgress: false
          }));
          app.setError(response.error);
          errors.add({ message: response.error, code: 'CORE_REINSTALL_ERROR' });
          return;
        }

        this.markReinstallComplete(
          response.message || response.data?.results?.message || 'WordPress core was reinstalled successfully.'
        );
        
      } catch (e) {
        if (pollCancel) {
          pollCancel();
          pollCancel = null;
        }
        debug.error('CORE', 'Reinstall failed', e.message);
        update(s => ({
          ...s,
          coreScanning: false,
          error: e.message,
          coreProgressMessage: 'Failed',
          backupInProgress: false
        }));
        errors.add({ message: e.message, code: 'CORE_ERROR' });
      }
    },
    
    /**
     * Cancel ongoing core reinstall
     */
    cancelCoreReinstall: () => {
      if (pollCancel) {
        pollCancel();
        pollCancel = null;
      }
      update(s => ({
        ...s,
        coreScanning: false,
        showBackupSelection: false,
        coreProgress: 0,
        coreProgressMessage: 'Cancelled',
        backupInProgress: false
      }));
      app.setProgress(0, 'Cancelled', 'cancelled');
    },
    
    /**
     * Cancel backup selection and go back
     */
    cancelBackupSelection: () => {
      update(s => ({
        ...s,
        showBackupSelection: false
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
        coreVersion: '',
        selectedVersion: 'latest',
        availableVersions: [],
        currentVersion: '',
        latestVersion: '',
        createBackup: true,
        coreScanning: false,
        coreProgress: 0,
        coreProgressMessage: '',
        error: null,
        showBackupChoice: false,
        showBackupSelection: false,
        diskCheck: null,
        backupInProgress: false,
        loadingVersions: true,
        reinstallSuccess: null,
      });
      app.resetProgress();
    }
  };
}

export const core = createCoreStore();
