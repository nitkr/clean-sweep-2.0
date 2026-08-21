/**
 * Themes Store
 * State and methods for theme management functionality
 * Handles analysis, selection, backup, and reinstallation of themes
 * 
 * Uses the modular adapter architecture for API calls
 */

import { writable } from 'svelte/store';
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

function createThemesStore() {
  const { subscribe, set, update } = writable({
    // Workflow state: 'initial' | 'analyzing' | 'analyzed' | 'reinstalling'
    workflowState: 'initial',
    
    // Theme data
    themes: [],
    themesLoading: false,
    themesAnalyzed: false,
    
    // All theme categories from API
    wpOrgThemes: [],
    customThemes: [],
    likelyFakeThemes: [],
    suspiciousFiles: [],
    
    // Selection state for bulk operations
    selectedWpOrg: [],
    selectedSuspicious: [],
    
    // Results with all counts
    themeResults: {
      total: 0,
      wp_org: 0,
      custom: 0,
      likely_fake: 0,
      suspicious: 0,
      needing_update: 0
    },
    
    // Reinstallation state
    themeReinstalling: false,
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
  
  return {
    subscribe,
    
    /**
     * Get current state synchronously
     */
    getState: () => {
      let currentState;
      update(s => { currentState = s; return s; });
      return currentState;
    },
    
    /**
     * Load and analyze themes
     */
    async loadThemes() {
      update(s => ({ ...s, themesLoading: true, error: null, workflowState: 'analyzing' }));
      
      // Log event
      events.themes.analyzeStart();
      debug.log('THEMES', 'Loading themes');
      
      try {
        // Use the adapter instead of direct API call
        const response = await adapters.themes.analyze();
        
        if (response.success && response.data) {
          const results = response.data.results || {};
          
          // Get all theme categories
          const wpOrgThemes = results.wp_org_themes || [];
          const customThemes = results.custom_themes || [];
          const likelyFakeThemes = results.likely_fake_themes || [];
          const suspiciousFiles = results.suspicious_files || [];
          
          // Get summary
          const summary = results.summary || {};
          const totalCount = wpOrgThemes.length + customThemes.length + likelyFakeThemes.length;
          
          update(s => ({
            ...s,
            themesLoading: false,
            themesAnalyzed: true,
            workflowState: 'analyzed',
            wpOrgThemes,
            customThemes,
            likelyFakeThemes,
            suspiciousFiles,
            themes: wpOrgThemes, // Backward compatibility
            selectedWpOrg: wpOrgThemes.map(t => t.slug),
            selectedSuspicious: defaultSuspiciousSelection(suspiciousFiles),
            themeResults: {
              total: summary.total_themes || totalCount,
              wp_org: wpOrgThemes.length,
              custom: customThemes.length,
              likely_fake: likelyFakeThemes.length,
              suspicious: suspiciousFiles.length,
              needing_update: summary.needing_update || 0
            }
          }));
          
          // Log success event
          if (results.identity_summary) {
            integrity.mergeVisitStatus({ likely_fake: results.identity_summary });
          }

          events.themes.analyzeComplete(totalCount);
          debug.log('THEMES', 'Themes loaded', {
            wpOrg: wpOrgThemes.length,
            custom: customThemes.length,
            suspicious: suspiciousFiles.length
          });
        } else {
          update(s => ({
            ...s,
            themesLoading: false,
            workflowState: 'initial',
            error: response.error || 'Failed to analyze themes'
          }));
          events.themes.analyzeError(response.error || 'Unknown error');
          errors.add({ message: response.error, code: 'THEMES_LOAD_ERROR' });
        }
      } catch (e) {
        debug.error('THEMES', 'Load failed', e.message);
        events.themes.analyzeError(e.message);
        update(s => ({
          ...s,
          themesLoading: false,
          workflowState: 'initial',
          error: e.message
        }));
        errors.add({ message: e.message, code: 'THEMES_ERROR' });
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
     * Toggle theme selection
     */
    toggleWpOrgTheme: (slug) => {
      update(s => {
        const selected = s.selectedWpOrg.includes(slug)
          ? s.selectedWpOrg.filter(x => x !== slug)
          : [...s.selectedWpOrg, slug];
        return { ...s, selectedWpOrg: selected };
      });
    },
    
    /**
     * Toggle suspicious file selection
     */
    toggleSuspiciousFile: (fileName) => {
      update(s => {
        const selected = s.selectedSuspicious.includes(fileName)
          ? s.selectedSuspicious.filter(x => x !== fileName)
          : [...s.selectedSuspicious, fileName];
        return { ...s, selectedSuspicious: selected };
      });
    },
    
    /**
     * Select all WP.org themes
     */
    selectAllWpOrg: () => {
      update(s => ({
        ...s,
        selectedWpOrg: s.wpOrgThemes.map(t => t.slug)
      }));
      events.themes.selectionChange(this.getSelectedCount());
    },
    
    /**
     * Deselect all WP.org themes
     */
    selectNoneWpOrg: () => {
      update(s => ({ ...s, selectedWpOrg: [] }));
      events.themes.selectionChange(0);
    },
    
    /**
     * Select all suspicious files
     */
    selectAllSuspicious: () => {
      update(s => ({
        ...s,
        selectedSuspicious: s.suspiciousFiles.map(f => f.name)
      }));
    },
    
    /**
     * Deselect all suspicious files
     */
    selectNoneSuspicious: () => {
      update(s => ({
        ...s,
        selectedSuspicious: []
      }));
    },
    
    /**
     * Get selected count
     */
    getSelectedCount: () => {
      let state;
      subscribe(s => state = s)();
      return state.selectedWpOrg.length;
    },
    
    /**
     * Start reinstall with optional backup
     * Uses batch processing with REAL-TIME polling for progress updates
     */
    async reinstallAllThemes(createBackup = false) {
      let state;
      subscribe(s => state = s)();
      
      const count = state.selectedWpOrg.length;
      if (count === 0) {
        errors.add({ message: 'Please select at least one theme to reinstall', code: 'THEMES_EMPTY_SELECTION' });
        return;
      }
      
      // Generate unique progress file for this operation
      const progressFile = `theme_reinstall_${Date.now()}.progress`;
      
      // Hide backup dialog and start reinstall
      update(s => ({ 
        ...s, 
        showBackupDialog: false,
        themeReinstalling: true,
        workflowState: 'reinstalling',
        backupInProgress: createBackup,
        reinstallProgress: { current: 0, total: count, theme: '' }
      }));
      
      events.themes.reinstallStart(count);
      app.setLoading(true);
      
      debug.log('THEMES', 'Starting reinstall', { count, createBackup, progressFile });
      
      // Get full theme objects for selected themes
      const selectedThemes = state.wpOrgThemes.filter(t => state.selectedWpOrg.includes(t.slug));
      
      // Get selected suspicious files
      const selectedSuspiciousFiles = state.suspiciousFiles.filter(f => state.selectedSuspicious.includes(f.name));
      
      console.log('[THEMES] Reinstall details:', {
        selectedWpOrg: state.selectedWpOrg,
        selectedSuspicious: state.selectedSuspicious,
        suspiciousFilesRaw: state.suspiciousFiles,
        themesCount: selectedThemes.length,
        suspiciousFilesCount: selectedSuspiciousFiles.length
      });
      
      const BATCH_SIZE = 3; // Process 3 themes at a time
      let lastResults = null;
      let lastCurrent = 0;
      let lastTotal = count;
      let lastTheme = '';
      let lastLoggedKey = '';
      let pollCancel = null;

      const readCount = (value, fallback) => {
        const n = parseInt(value, 10);
        return Number.isFinite(n) ? n : fallback;
      };

      const themeNameFromProgress = (pollProgress) => {
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
          || (phase === '' && text.includes('backup') && !text.includes('starting theme'));
        const isCleanup = phase === 'cleanup' || (phase === '' && message.toLowerCase().includes('cleaning up suspicious'));

        if (isBackup || isCleanup) {
          update(s => ({
            ...s,
            backupInProgress: isBackup,
            reinstallProgress: {
              current: lastCurrent,
              total: lastTotal,
              theme: '',
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
        const themeName = themeNameFromProgress(pollProgress);
        if (themeName) {
          lastTheme = themeName;
        }

        update(s => ({
          ...s,
          backupInProgress: false,
          reinstallProgress: {
            current: lastCurrent,
            total: lastTotal,
            theme: lastTheme,
            phase: phase || 'reinstall'
          }
        }));

        const pct = lastTotal > 0 ? Math.round((lastCurrent / lastTotal) * 100) : readCount(pollProgress.progress, 0);
        app.setProgress(
          pollProgress.status === 'complete' ? 100 : (Number.isFinite(Number(pollProgress.progress)) ? Number(pollProgress.progress) : pct),
          message || 'Processing...',
          pollProgress.status || 'running'
        );

        if (themeName) {
          const key = `${lastCurrent}:${themeName}`;
          if (key !== lastLoggedKey) {
            lastLoggedKey = key;
            events.themes.reinstallProgress(lastCurrent, lastTotal, themeName);
          }
        }
      };

      try {
        pollCancel = adapters.themes.pollReinstallProgress(progressFile, applyPoll, 1500);
        await processBatch(0, BATCH_SIZE, selectedThemes, count, progressFile, selectedSuspiciousFiles);
      } catch (e) {
        debug.error('THEMES', 'Reinstall failed', e.message);
        events.themes.reinstallError(e.message);
        errors.add({ message: e.message, code: 'REINSTALL_ERROR' });
        update(s => ({ 
          ...s, 
          themeReinstalling: false,
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
      
      async function processBatch(batchStart, batchSize, allThemes, totalCount, progFile, suspiciousFilesToDelete) {
        console.log('[THEMES] processBatch called', { batchStart, batchSize, totalCount, progFile, suspiciousFilesCount: suspiciousFilesToDelete?.length });

        const batchResponse = await adapters.themes.reinstall(
          allThemes,
          createBackup,
          batchStart,
          batchSize,
          progFile,
          suspiciousFilesToDelete
        );
        console.log('[THEMES] Batch response received:', batchResponse);
        
        if (!batchResponse.success) {
          console.error('[THEMES] Batch failed:', batchResponse.error);
          throw new Error(batchResponse.error || 'Batch failed');
        }
        
        const progress = batchResponse.data?.progress || {};
        const batchInfo = batchResponse.data?.batch_info || {};
        lastCurrent = readCount(progress.current ?? batchInfo.processed, lastCurrent);
        lastTotal = readCount(progress.total ?? batchInfo.total, lastTotal) || totalCount;

        update(s => ({ 
          ...s, 
          reinstallProgress: { 
            current: lastCurrent, 
            total: lastTotal, 
            theme: lastTheme 
          }
        }));
        
        // Check if there are more batches IMMEDIATELY after batch response
        // Note: First batch returns results under 'wordpress_org' key, final batch returns top-level 'successful'/'failed'
        const rawResults = batchResponse.data?.results || {};
        const batchResults = rawResults.successful || rawResults.wordpress_org?.successful 
          ? { 
              successful: rawResults.successful || rawResults.wordpress_org?.successful || [],
              failed: rawResults.failed || rawResults.wordpress_org?.failed || []
            }
          : {};
        
        console.log('[THEMES] Raw results from batch:', rawResults);
        console.log('[THEMES] Parsed batch results:', batchResults);
        
        if (batchInfo.has_more_batches && batchInfo.next_batch_start !== null) {
          console.log('[THEMES] Processing next batch immediately...');
          
          // Accumulate results from this batch
          if (!lastResults) {
            lastResults = batchResults;
          } else {
            // Merge results
            lastResults.successful = [
              ...(lastResults.successful || []),
              ...(batchResults.successful || [])
            ];
            lastResults.failed = [
              ...(lastResults.failed || []),
              ...(batchResults.failed || [])
            ];
          }
          
          // Process next batch immediately
          await processBatch(
            batchInfo.next_batch_start,
            batchSize,
            allThemes,
            totalCount,
            progFile,
            suspiciousFilesToDelete
          );
          return;
        }
        
        // No more batches - accumulate final results
        // Note: Final batch returns top-level 'successful'/'failed', but also nested under 'wordpress_org'
        const rawFinalResults = batchResponse.data?.results || {};
        const finalBatchResults = rawFinalResults.successful || rawFinalResults.wordpress_org?.successful
          ? {
              successful: rawFinalResults.successful || rawFinalResults.wordpress_org?.successful || [],
              failed: rawFinalResults.failed || rawFinalResults.wordpress_org?.failed || []
            }
          : {};
        
        console.log('[THEMES] Final raw results:', rawFinalResults);
        console.log('[THEMES] Final parsed results:', finalBatchResults);
        
        if (!lastResults) {
          lastResults = finalBatchResults;
        } else {
          lastResults.successful = [
            ...(lastResults.successful || []),
            ...(finalBatchResults.successful || [])
          ];
          lastResults.failed = [
            ...(lastResults.failed || []),
            ...(finalBatchResults.failed || [])
          ];
        }
        
        console.log('[THEMES] All batches complete! Final results:', lastResults);
        
        // All batches complete
        update(s => ({ 
          ...s, 
          reinstallResults: lastResults || {},
          showSummary: true
        }));
        
        events.themes.reinstallComplete(lastResults?.successful?.length || 0);
        
        update(s => ({ 
          ...s, 
          themeReinstalling: false,
          workflowState: 'analyzed',
          reinstallProgress: null
        }));
      }
    },
    
    /**
     * Create a backup before reinstalling
     */
    async createBackup() {
      update(s => ({ ...s, backupInProgress: true }));
      events.themes.backupStart();
      
      try {
        const response = await adapters.themes.createBackup();
        
        if (response.success) {
          const backupPath = response.data?.backup_path || 'themes-backup.zip';
          update(s => ({ 
            ...s, 
            backupInProgress: false, 
            backupPath 
          }));
          events.themes.backupComplete(backupPath);
          debug.log('THEMES', 'Backup created', backupPath);
          return true;
        } else {
          events.themes.backupComplete('Backup may have failed');
          return false;
        }
      } catch (e) {
        debug.error('THEMES', 'Backup failed', e.message);
        return false;
      }
    },
    
    /**
     * Skip backup and proceed with reinstall
     */
    skipBackup() {
      events.themes.backupSkip();
      update(s => ({ ...s, showBackupDialog: false }));
    },
    
    /**
     * Copy selected theme list to clipboard
     */
    copyThemeList: async () => {
      let state;
      subscribe(s => state = s)();
      
      // Filter only selected themes
      const selectedThemes = state.wpOrgThemes.filter(t => state.selectedWpOrg.includes(t.slug));
      
      const list = selectedThemes.map(t => `${t.name} (${t.version}) [WP.org]`).join('\n');
      
      try {
        await navigator.clipboard.writeText(list);
        events.add('THEMES', 'copy_list', 'Selected theme list copied to clipboard');
      } catch (e) {
        debug.error('THEMES', 'Copy failed', e.message);
      }
    },
    
    /**
     * Reset store to initial state
     */
    reset: () => {
      set({
        workflowState: 'initial',
        themes: [],
        themesLoading: false,
        themesAnalyzed: false,
        wpOrgThemes: [],
        customThemes: [],
        likelyFakeThemes: [],
        suspiciousFiles: [],
        selectedWpOrg: [],
        selectedSuspicious: [],
        themeResults: {
          total: 0,
          wp_org: 0,
          custom: 0,
          likely_fake: 0,
          suspicious: 0,
          needing_update: 0
        },
        themeReinstalling: false,
        reinstallProgress: null,
        reinstallResults: null,
        showSummary: false,
        showBackupDialog: false,
        backupInProgress: false,
        backupPath: null,
        error: null
      });
    }
  };
}

export const themes = createThemesStore();
