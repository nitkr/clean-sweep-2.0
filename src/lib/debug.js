/**
 * Debug Mode Store
 * Provides debugging features and logging
 */

import { writable } from 'svelte/store';

function createDebugStore() {
  const stored = typeof localStorage !== 'undefined' 
    ? localStorage.getItem('debug') === 'true' 
    : false;
    
  const { subscribe, set, update } = writable(stored);
  
  let isEnabled = stored;
  
  return {
    subscribe,
    
    /**
     * Toggle debug mode
     */
    toggle: () => {
      update(enabled => {
        const next = !enabled;
        
        if (typeof localStorage !== 'undefined') {
          localStorage.setItem('debug', String(next));
        }
        
        isEnabled = next;
        return next;
      });
    },
    
    /**
     * Enable debug mode
     */
    enable: () => {
      if (typeof localStorage !== 'undefined') {
        localStorage.setItem('debug', 'true');
      }
      isEnabled = true;
      set(true);
    },
    
    /**
     * Disable debug mode
     */
    disable: () => {
      if (typeof localStorage !== 'undefined') {
        localStorage.setItem('debug', 'false');
      }
      isEnabled = false;
      set(false);
    },
    
    /**
     * Log debug message (only logs if debug is enabled)
     */
    log: (context, message, data = null) => {
      if (isEnabled && typeof console !== 'undefined') {
        console.log(`[DEBUG:${context}]`, message, data || '');
      }
    },
    
    /**
     * Log error (always logs)
     */
    error: (context, message, data = null) => {
      if (typeof console !== 'undefined') {
        console.error(`[ERROR:${context}]`, message, data || '');
      }
    },
    
    /**
     * Log warning
     */
    warn: (context, message, data = null) => {
      if (typeof console !== 'undefined') {
        console.warn(`[WARN:${context}]`, message, data || '');
      }
    },
    
    /**
     * Check if debug is enabled
     */
    isEnabled: () => isEnabled
  };
}

export const debug = createDebugStore();
