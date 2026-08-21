/**
 * Plugins Store
 * State and methods for plugin management functionality
 * Handles analysis, selection, backup, and reinstallation of plugins
 * 
 * Uses the modular adapter architecture for API calls
 */

import { writable, derived } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { app } from './app.js';
import { errors } from '../errors.js';
import { debug } from '../debug.js';
import { events } from './events.js';
import { integrity } from './integrity.js';

/** Auto-select high-severity (or legacy rows without severity). */
function defaultSuspiciousSelection(files) {
  return (files || [])
    .filter((f) => {
      const sev = (f?.severity || 'high').toLowerCase();
      return sev === 'high' || sev === 'critical';
    })
    .map((f) => f.name);
}

function createPluginsStore() {
  const { subscribe, set, update } = writable({
    // Workflow state: 'initial' | 'analyzing' | 'analyzed' | 'reinstalling'
    workflowState: 'initial',
    
    // Plugin data
    plugins: [],
    pluginsLoading: false,
    pluginsAnalyzed: false,
    
    // All plugin categories from API
    wpOrgPlugins: [],
    wpmuDevPlugins: [],
    customPlugins: [],
    likelyFakePlugins: [],
    suspiciousFiles: [],
    
    // Selection state for bulk operations
    selectedWpOrg: [],
    selectedWpmuDev: [],
    selectedSuspicious: [],
    
    // Results with all counts
    pluginResults: {
      total: 0,
      wp_org: 0,
      wpmu_dev: 0,
      custom: 0,
      likely_fake: 0,
      suspicious: 0,
      needing_update: 0,
      wpmu_dev_available: true
    },
    
    // Reinstallation state
    pluginReinstalling: false,
    reinstallProgress: null,
    reinstallResults: null,
    showSummary: false,
    
    // Backup state
    showBackupDialog: false,
    backupInProgress: false,
    backupPath: null,
    
    // Error state
    error: null
  });
  
  // Derived store for selected count - this is reactive!
  const selectedCount = derived(
    { subscribe },
    ($plugins) => $plugins.selectedWpOrg.length + $plugins.selectedWpmuDev.length + $plugins.selectedSuspicious.length
  );
  
  return {
    subscribe,
    selectedCount, // Export the derived store for reactive updates
    
    /**
     * Get current state synchronously
     */
    getState: () => {
      let currentState;
      update(s => { currentState = s; return s; });
      return currentState;
    },
    /**
     * Load and analyze plugins
     */
    async loadPlugins() {
      update(s => ({ ...s, pluginsLoading: true, error: null, workflowState: 'analyzing' }));
      
      // Log event
      events.plugins.analyzeStart();
      debug.log('PLUGINS', 'Loading plugins');
      
      try {
        // Use the adapter instead of direct API call
        const response = await adapters.plugins.analyze();
        
        if (response.success && response.data) {
          const results = response.data.results || {};
          
          // Get all plugin categories
          const wpOrgPlugins = results.wp_org_plugins || [];
          const wpmuDevPlugins = results.wpmu_dev_plugins || [];
          const customPlugins = results.non_repo_plugins || [];
          const likelyFakePlugins = results.likely_fake_plugins || [];
          const suspiciousFiles = results.suspicious_files || [];
          
          // Get summary (API returns different field names - adapt accordingly)
          const summary = results.summary || {};
          const totalCount = (summary.total || wpOrgPlugins.length + wpmuDevPlugins.length + customPlugins.length + likelyFakePlugins.length);
          
          update(s => ({
            ...s,
            pluginsLoading: false,
            pluginsAnalyzed: true,
            workflowState: 'analyzed',
            wpOrgPlugins,
            wpmuDevPlugins,
            customPlugins,
            likelyFakePlugins,
            suspiciousFiles,
            plugins: wpOrgPlugins, // Backward compatibility
            selectedWpOrg: wpOrgPlugins.map(p => p.slug),
            selectedWpmuDev: wpmuDevPlugins.map(p => p.slug),
            selectedSuspicious: defaultSuspiciousSelection(suspiciousFiles),
            pluginResults: {
              total: summary.total || totalCount,
              wp_org: summary.wp_org_count || wpOrgPlugins.length,
              wpmu_dev: summary.wpmu_dev_count || wpmuDevPlugins.length,
              custom: summary.non_repo_count || customPlugins.length,
              likely_fake: summary.likely_fake_count || likelyFakePlugins.length,
              suspicious: summary.suspicious_count ?? suspiciousFiles.length,
              needing_update: summary.needs_reinstall ? 1 : 0,
              wpmu_dev_available: results.wpmu_dev_available !== undefined ? results.wpmu_dev_available : true
            }
          }));
          
          if (results.identity_summary) {
            integrity.mergeVisitStatus({ likely_fake: results.identity_summary });
          }

          // Log success event
          events.plugins.analyzeComplete(totalCount);
          debug.log('PLUGINS', 'Plugins loaded', {
            wpOrg: wpOrgPlugins.length,
            wpmuDev: wpmuDevPlugins.length,
            custom: customPlugins.length
          });
        } else {
          update(s => ({
            ...s,
            pluginsLoading: false,
            workflowState: 'initial',
            error: response.error || 'Failed to analyze plugins'
          }));
          events.plugins.analyzeError(response.error || 'Unknown error');
          errors.add({ message: response.error, code: 'PLUGINS_LOAD_ERROR' });
        }
      } catch (e) {
        debug.error('PLUGINS', 'Load failed', e.message);
        events.plugins.analyzeError(e.message);
        update(s => ({
          ...s,
          pluginsLoading: false,
          workflowState: 'initial',
          error: e.message
        }));
        errors.add({ message: e.message, code: 'PLUGINS_ERROR' });
      }
    },
    
    /**
     * Show the backup dialog before reinstall
     */
    showReinstallDialog() {
      update(s => ({ ...s, showBackupDialog: true }));
    },
    
    /**
     * Hide the backup dialog
     */
    hideReinstallDialog() {
      update(s => ({ ...s, showBackupDialog: false }));
    },
    
    /**
     * Hide the reinstall summary
     */
    hideSummary() {
      update(s => ({ ...s, showSummary: false, reinstallResults: null }));
    },
    
    /**
     * Create a backup before reinstalling
     */
    async createBackup() {
      update(s => ({ ...s, backupInProgress: true }));
      events.plugins.backupStart();
      
      try {
        const response = await adapters.plugins.createBackup();
        
        if (response.success) {
          const backupPath = response.data?.backup_path || 'plugins-backup.zip';
          update(s => ({ 
            ...s, 
            backupInProgress: false, 
            backupPath 
          }));
          events.plugins.backupComplete(backupPath);
          debug.log('PLUGINS', 'Backup created', backupPath);
          return true;
        } else {
          events.plugins.backupComplete('Backup may have failed');
          return false;
        }
      } catch (e) {
        debug.error('PLUGINS', 'Backup failed', e.message);
        return false;
      }
    },
    
    /**
     * Skip backup and proceed with reinstall
     */
    skipBackup() {
      events.plugins.backupSkip();
      update(s => ({ ...s, showBackupDialog: false }));
    },
    
    /**
     * Toggle plugin selection
     */
    toggleWpOrgPlugin: (slug) => {
      update(s => {
        const selected = s.selectedWpOrg.includes(slug)
          ? s.selectedWpOrg.filter(x => x !== slug)
          : [...s.selectedWpOrg, slug];
        const count = selected.length + s.selectedWpmuDev.length;
        events.plugins.selectionChange(count);
        return { ...s, selectedWpOrg: selected };
      });
    },
    
    /**
     * Toggle WPMU DEV plugin selection
     */
    toggleWpmuDevPlugin: (slug) => {
      update(s => {
        const selected = s.selectedWpmuDev.includes(slug)
          ? s.selectedWpmuDev.filter(x => x !== slug)
          : [...s.selectedWpmuDev, slug];
        const count = s.selectedWpOrg.length + selected.length;
        events.plugins.selectionChange(count);
        return { ...s, selectedWpmuDev: selected };
      });
    },
    
    /**
     * Select all WP.org plugins
     */
    selectAllWpOrg: () => {
      let newCount = 0;
      update(s => {
        const selected = s.wpOrgPlugins.map(p => p.slug);
        newCount = selected.length + s.selectedWpmuDev.length;
        return { ...s, selectedWpOrg: selected };
      });
      events.plugins.selectionChange(newCount);
    },
    
    /**
     * Deselect all WP.org plugins
     */
    selectNoneWpOrg: () => {
      let newCount = 0;
      update(s => {
        newCount = s.selectedWpmuDev.length;
        return { ...s, selectedWpOrg: [] };
      });
      events.plugins.selectionChange(newCount);
    },
    
    /**
     * Select all WPMU DEV plugins
     */
    selectAllWpmuDev: () => {
      let newCount = 0;
      update(s => {
        const selected = s.wpmuDevPlugins.map(p => p.slug);
        newCount = s.selectedWpOrg.length + selected.length;
        return { ...s, selectedWpmuDev: selected };
      });
      events.plugins.selectionChange(newCount);
    },
    
    /**
     * Deselect all WPMU DEV plugins
     */
    selectNoneWpmuDev: () => {
      let newCount = 0;
      update(s => {
        newCount = s.selectedWpOrg.length;
        return { ...s, selectedWpmuDev: [] };
      });
      events.plugins.selectionChange(newCount);
    },
    
    /**
     * Toggle suspicious file selection
     */
    toggleSuspiciousFile: (fileName) => {
      update(s => {
        const selected = s.selectedSuspicious.includes(fileName)
          ? s.selectedSuspicious.filter(x => x !== fileName)
          : [...s.selectedSuspicious, fileName];
        const count = selected.length + s.selectedWpOrg.length + s.selectedWpmuDev.length;
        events.plugins.selectionChange(count);
        return { ...s, selectedSuspicious: selected };
      });
    },
    
    /**
     * Select all suspicious files
     */
    selectAllSuspicious: () => {
      update(s => {
        return { ...s, selectedSuspicious: s.suspiciousFiles.map(f => f.name) };
      });
    },
    
    /**
     * Deselect all suspicious files
     */
    selectNoneSuspicious: () => {
      update(s => {
        return { ...s, selectedSuspicious: [] };
      });
    },
    
    /**
     * Start reinstall with optional backup
     * Uses batch processing with REAL-TIME polling for progress updates
     */
    async reinstallAllPlugins(createBackup = false) {
      let state;
      subscribe(s => state = s)();
      
      const count = state.selectedWpOrg.length + state.selectedWpmuDev.length;
      if (count === 0) {
        errors.add({ message: 'Please select at least one plugin to reinstall', code: 'PLUGINS_EMPTY_SELECTION' });
        return;
      }
      
      // Generate unique progress file for this operation (still used by backend)
      const progressFile = `plugin_reinstall_${Date.now()}.progress`;
      
      // Hide backup dialog and start reinstall
      update(s => ({ 
        ...s, 
        showBackupDialog: false,
        pluginReinstalling: true,
        workflowState: 'reinstalling',
        backupInProgress: createBackup, // Set backup in progress flag
        reinstallProgress: { current: 0, total: count, plugin: '' }
      }));
      
      events.plugins.reinstallStart(count);
      app.setLoading(true);
      
      debug.log('PLUGINS', 'Starting reinstall', { count, createBackup, progressFile });
      
      // Get full plugin objects for selected plugins - separate WP.org and WPMU DEV
      const selectedWpOrgPlugins = state.wpOrgPlugins.filter(p => state.selectedWpOrg.includes(p.slug));
      const selectedWpmuDevPlugins = state.wpmuDevPlugins.filter(p => state.selectedWpmuDev.includes(p.slug));
      
      // Get selected suspicious files (filter suspiciousFiles to only include selected ones)
      const selectedSuspiciousFiles = state.suspiciousFiles.filter(f => state.selectedSuspicious.includes(f.name));
      
      // Add is_wpmudev flag to each plugin
      const wpOrgWithFlag = selectedWpOrgPlugins.map(p => ({...p, is_wpmudev: false}));
      const wpmuDevWithFlag = selectedWpmuDevPlugins.map(p => ({...p, is_wpmudev: true}));
      
      // Combine with flag
      const allPlugins = [...wpOrgWithFlag, ...wpmuDevWithFlag];
      
      console.log('[PLUGINS] Reinstall details:', {
        selectedWpOrg: state.selectedWpOrg,
        selectedWpmuDev: state.selectedWpmuDev,
        selectedSuspicious: state.selectedSuspicious,
        suspiciousFilesRaw: state.suspiciousFiles,
        wpOrgPluginsCount: state.wpOrgPlugins.length,
        wpmuDevPluginsCount: state.wpmuDevPlugins.length,
        suspiciousFilesCount: state.suspiciousFiles.length,
        selectedSuspiciousFilesCount: selectedSuspiciousFiles.length,
        selectedSuspiciousFilesRaw: selectedSuspiciousFiles,
        selectedPluginsCount: allPlugins.length,
        selectedPluginsSlugs: allPlugins.map(p => p.slug)
      });
      
      const BATCH_SIZE = 5; // Process 5 plugins at a time
      let lastResults = null;
      let lastCurrent = 0;
      let lastTotal = count;
      let lastPlugin = '';
      let lastLoggedKey = '';
      let pollCancel = null;

      const readCount = (value, fallback) => {
        const n = parseInt(value, 10);
        return Number.isFinite(n) ? n : fallback;
      };

      const pluginNameFromProgress = (pollProgress) => {
        if (pollProgress?.plugin) {
          return String(pollProgress.plugin).trim();
        }
        const message = pollProgress?.message || '';
        const match = message.match(/^Reinstalling\s+(.+?)(?:\.\.\.|$)/);
        if (!match) {
          return '';
        }
        const name = match[1].trim();
        if (!name || /re-?installation/i.test(name) || /^(plugins?|themes?)$/i.test(name)) {
          return '';
        }
        return name;
      };

      const applyPoll = (pollProgress) => {
        if (!pollProgress || !pollProgress.status || pollProgress.status === 'pending') {
          return;
        }

        const message = pollProgress.message || '';
        const details = pollProgress.details || '';
        const phase = String(pollProgress.phase || '').toLowerCase();
        const text = `${message} ${details}`.toLowerCase();
        const isBackup = phase === 'backup'
          || pollProgress.status === 'backing_up'
          || (phase === '' && text.includes('backup') && !text.includes('starting plugin'));
        const isCleanup = phase === 'cleanup' || (phase === '' && message.toLowerCase().includes('cleaning up suspicious'));

        if (isBackup || isCleanup) {
          update(s => ({
            ...s,
            backupInProgress: isBackup,
            reinstallProgress: {
              current: lastCurrent,
              total: lastTotal,
              plugin: '',
              phase: isBackup ? 'backup' : 'cleanup'
            }
          }));
          app.setProgress(
            readCount(pollProgress.progress, 0),
            message || (isBackup ? 'Creating backup...' : 'Cleaning up...'),
            pollProgress.status || 'running'
          );
          return;
        }

        let current = lastCurrent;
        let total = lastTotal;
        if (pollProgress.current !== undefined && pollProgress.current !== null && pollProgress.current !== '') {
          current = readCount(pollProgress.current, lastCurrent);
        } else if (details) {
          const detailsMatch = details.match(/\((\d+)\/(\d+)\)/);
          if (detailsMatch && !details.includes('orphans')) {
            current = parseInt(detailsMatch[1], 10);
            total = parseInt(detailsMatch[2], 10);
          }
        }
        if (pollProgress.total !== undefined && pollProgress.total !== null && pollProgress.total !== '') {
          const parsedTotal = readCount(pollProgress.total, 0);
          if (parsedTotal > 0) {
            total = parsedTotal;
          }
        }

        lastCurrent = current;
        lastTotal = total > 0 ? total : lastTotal;

        const pluginName = pluginNameFromProgress(pollProgress);
        if (pluginName) {
          lastPlugin = pluginName;
        }

        update(s => ({
          ...s,
          backupInProgress: false,
          reinstallProgress: {
            current: lastCurrent,
            total: lastTotal,
            plugin: lastPlugin,
            phase: phase || 'reinstall'
          }
        }));

        const pct = lastTotal > 0 ? Math.round((lastCurrent / lastTotal) * 100) : readCount(pollProgress.progress, 0);
        app.setProgress(
          pollProgress.status === 'complete' ? 100 : (Number.isFinite(Number(pollProgress.progress)) ? Number(pollProgress.progress) : pct),
          message || 'Processing...',
          pollProgress.status || 'running'
        );

        if (pluginName) {
          const key = `${lastCurrent}:${pluginName}`;
          if (key !== lastLoggedKey) {
            lastLoggedKey = key;
            events.plugins.reinstallProgress(lastCurrent, lastTotal, pluginName);
          }
        }
      };

      try {
        pollCancel = adapters.plugins.pollReinstallProgress(progressFile, applyPoll, 1500);
        await processBatch(0, BATCH_SIZE, allPlugins, count, progressFile, selectedSuspiciousFiles);
      } catch (e) {
        debug.error('PLUGINS', 'Reinstall failed', e.message);
        events.plugins.reinstallError(e.message);
        errors.add({ message: e.message, code: 'REINSTALL_ERROR' });
        update(s => ({ 
          ...s, 
          pluginReinstalling: false,
          workflowState: 'analyzed',
          reinstallProgress: null,
          error: e.message
        }));
      } finally {
        if (pollCancel) {
          pollCancel();
        }
        app.setLoading(false);
      }
      
      async function processBatch(batchStart, batchSize, allPlugins, totalCount, progFile, suspiciousFilesToDelete) {
        console.log('[PLUGINS] processBatch called', { batchStart, batchSize, totalCount, progFile, suspiciousFilesCount: suspiciousFilesToDelete?.length });

        const response = await adapters.plugins.reinstall(
          allPlugins,
          createBackup,
          batchStart,
          batchSize,
          progFile,
          suspiciousFilesToDelete
        );
        console.log('[PLUGINS] Batch completed, success:', response.success, 'response:', response);
        
        if (response.success) {
            const progress = response.data?.progress || {};
            const batchInfo = response.data?.batch_info || {};
            const nextCurrent = readCount(progress.current ?? batchInfo.processed, lastCurrent);
            const nextTotal = readCount(progress.total ?? batchInfo.total, lastTotal) || totalCount;
            lastCurrent = nextCurrent;
            lastTotal = nextTotal;

            if (batchInfo.has_more_batches && batchInfo.next_batch_start !== null) {
              debug.log('PLUGINS', 'Processing next batch immediately', { nextStart: batchInfo.next_batch_start });
              
              update(s => ({ 
                ...s, 
                reinstallProgress: { 
                  current: lastCurrent, 
                  total: lastTotal, 
                  plugin: lastPlugin 
                },
                backupInProgress: false
              }));
              
              await processBatch(batchInfo.next_batch_start, batchSize, allPlugins, totalCount, progFile, suspiciousFilesToDelete);
              return;
            }
            
            lastResults = response.data?.results || {};
            events.plugins.reinstallComplete(count);
            
            update(s => ({ 
              ...s, 
              pluginReinstalling: false,
              workflowState: 'analyzed',
              reinstallProgress: null,
              reinstallResults: lastResults || {},
              showSummary: true
            }));
          } else {
            events.plugins.reinstallError(response.error || 'Unknown error');
            errors.add({ message: response.error, code: 'REINSTALL_ERROR' });
            update(s => ({ 
              ...s, 
              pluginReinstalling: false,
              workflowState: 'analyzed',
              reinstallProgress: null,
              error: response.error
            }));
          }
      }
    },
    
    /**
     * Cancel ongoing reinstall operation
     */
    cancelReinstall: () => {
      // This would need to be connected to the pollCancel function
      // For now, just reset state
      update(s => ({ 
        ...s, 
        pluginReinstalling: false,
        workflowState: 'analyzed',
        reinstallProgress: null
      }));
      app.setProgress(0, 'Ready', 'idle');
    },
    
    /**
     * Copy selected plugin list to clipboard
     */
    copyPluginList: async () => {
      let state;
      subscribe(s => state = s)();
      
      // Filter only selected plugins
      const selectedWpOrgPlugins = state.wpOrgPlugins.filter(p => state.selectedWpOrg.includes(p.slug));
      const selectedWpmuDevPlugins = state.wpmuDevPlugins.filter(p => state.selectedWpmuDev.includes(p.slug));
      const selectedSuspiciousFiles = state.suspiciousFiles.filter(f => state.selectedSuspicious.includes(f.name));
      
      const list = [
        ...selectedWpOrgPlugins.map(p => `${p.name} (${p.version}) [WP.org]`),
        ...selectedWpmuDevPlugins.map(p => `${p.name} (${p.version}) [WPMU DEV]`),
        ...selectedSuspiciousFiles.map(f => `${f.name} [SUSPICIOUS - will be removed]`)
      ].join('\n');
      
      try {
        await navigator.clipboard.writeText(list);
        events.add('PLUGINS', 'copy_list', 'Selected plugin list copied to clipboard');
      } catch (e) {
        debug.error('PLUGINS', 'Copy failed', e.message);
      }
    },
    
    /**
     * Reset store to initial state
     */
    reset: () => {
      set({
        workflowState: 'initial',
        plugins: [],
        pluginsLoading: false,
        pluginsAnalyzed: false,
        wpOrgPlugins: [],
        wpmuDevPlugins: [],
        customPlugins: [],
        likelyFakePlugins: [],
        suspiciousFiles: [],
        selectedWpOrg: [],
        selectedWpmuDev: [],
        selectedSuspicious: [],
        pluginResults: {
          total: 0,
          wp_org: 0,
          wpmu_dev: 0,
          custom: 0,
          likely_fake: 0,
          suspicious: 0,
          needing_update: 0,
          wpmu_dev_available: true
        },
        pluginReinstalling: false,
        reinstallProgress: null,
        showBackupDialog: false,
        backupInProgress: false,
        backupPath: null,
        error: null
      });
    },
    
    /**
     * Go back to initial state
     */
    goBack: () => {
      update(s => ({ ...s, workflowState: 'initial', pluginsAnalyzed: false }));
    },
    
    /**
     * Get selected count
     */
    getSelectedCount: () => {
      let state;
      subscribe(s => state = s)();
      return state.selectedWpOrg.length + state.selectedWpmuDev.length + state.selectedSuspicious.length;
    }
  };
}

export const plugins = createPluginsStore();
