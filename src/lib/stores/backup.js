/**
 * Backup Store
 * State and methods for backup functionality
 * 
 * Uses the modular adapter architecture for API calls
 */

import { writable } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { app } from './app.js';
import { errors } from '../errors.js';
import { debug } from '../debug.js';

function createBackupStore() {
  const { subscribe, set, update } = writable({
    backingUp: false,
    backupProgress: 0,
    backupMessage: '',
    backupComplete: false,
    backupResult: null,
    error: null
  });
  
  return {
    subscribe,
    
    /**
     * Start backup
     */
    async startBackup(opts = {}) {
      // Callers that need confirmation should pass { confirmed: true } after an in-app dialog.
      if (opts.confirmed === false) {
        return;
      }

      update(s => ({
        ...s,
        backingUp: true,
        backupProgress: 0,
        backupMessage: 'Starting backup...',
        backupComplete: false,
        error: null
      }));
      
      app.setProgress(0, 'Starting backup...', 'running');
      debug.log('BACKUP', 'Starting backup');
      
      try {
        // Use the adapter instead of direct API call
        const response = await adapters.plugins.createBackup();
        
        if (response.success) {
          update(s => ({
            ...s,
            backingUp: false,
            backupComplete: true,
            backupProgress: 100,
            backupMessage: 'Backup complete!',
            backupResult: response.data
          }));
          app.setProgress(100, 'Backup complete!', 'complete');
        } else {
          update(s => ({
            ...s,
            backingUp: false,
            error: response.error || 'Backup failed',
            backupMessage: 'Failed'
          }));
          errors.add({ message: response.error, code: 'BACKUP_ERROR' });
        }
      } catch (e) {
        debug.error('BACKUP', 'Failed', e.message);
        update(s => ({
          ...s,
          backingUp: false,
          error: e.message,
          backupMessage: 'Failed'
        }));
        errors.add({ message: e.message, code: 'BACKUP_ERROR' });
      }
    },
    
    /**
     * Reset store
     */
    reset: () => {
      set({
        backingUp: false,
        backupProgress: 0,
        backupMessage: '',
        backupComplete: false,
        backupResult: null,
        error: null
      });
      app.resetProgress();
    }
  };
}

export const backup = createBackupStore();
