/**
 * Cleanup Store
 * State and methods for cleanup functionality
 * 
 * Uses the modular adapter architecture for API calls
 */

import { writable } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { markToolkitGone } from '../api/adapter.ts';
import { app } from './app.js';
import { errors } from '../errors.js';
import { debug } from '../debug.js';

function createCleanupStore() {
  const { subscribe, set, update } = writable({
    cleaningUp: false,
    cleanupMessage: '',
    cleanupProgress: 0,
    cleanupComplete: false,
    error: null
  });
  
  return {
    subscribe,
    
    /**
     * Start cleanup process
     * @param {{ skipSnapshot?: boolean }} options
     */
    async startCleanup(options = {}) {
      update(s => ({
        ...s,
        cleaningUp: true,
        cleanupMessage: 'Starting cleanup...',
        cleanupProgress: 0,
        cleanupComplete: false,
        error: null
      }));
      
      app.setProgress(0, 'Starting cleanup...', 'running');
      debug.log('CLEANUP', 'Starting cleanup', options);
      
      try {
        const response = await adapters.cleanup.removeTool({
          skipSnapshot: !!options.skipSnapshot
        });
        
        if (response.success) {
          markToolkitGone();
          update(s => ({
            ...s,
            cleaningUp: false,
            cleanupComplete: true,
            cleanupMessage: 'Cleanup complete!',
            cleanupProgress: 100,
            error: null
          }));
          app.setProgress(100, 'Clean Sweep removed', 'complete');
          app.markToolkitRemoved();
          return true;
        } else {
          const msg = response.error || 'Cleanup failed';
          const needSnap = response.code === 'SNAPSHOT_REQUIRED' || /snapshot/i.test(msg);
          update(s => ({
            ...s,
            cleaningUp: false,
            error: needSnap
              ? 'Download a snapshot of this visit first (button above), or confirm “delete without snapshot”. Comparing an older snapshot is not enough.'
              : msg,
            cleanupMessage: 'Failed'
          }));
          errors.add({
            message: needSnap ? 'Snapshot required before remove' : msg,
            code: response.code || 'CLEANUP_ERROR'
          });
          return false;
        }
      } catch (e) {
        debug.error('CLEANUP', 'Failed', e.message);
        update(s => ({
          ...s,
          cleaningUp: false,
          error: e.message,
          cleanupMessage: 'Failed'
        }));
        errors.add({ message: e.message, code: 'CLEANUP_ERROR' });
        return false;
      }
    },
    
    /**
     * Reset store
     */
    reset: () => {
      set({
        cleaningUp: false,
        cleanupMessage: '',
        cleanupProgress: 0,
        cleanupComplete: false,
        error: null
      });
      app.resetProgress();
    }
  };
}

export const cleanup = createCleanupStore();
