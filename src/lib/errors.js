/**
 * Error Handling System
 * Centralized error management with toast notifications
 */

import { writable } from 'svelte/store';

// Error levels
export const ErrorLevel = {
  INFO: 'info',
  WARNING: 'warning',
  ERROR: 'error',
  CRITICAL: 'critical'
};

function createErrorStore() {
  const { subscribe, set, update } = writable([]);
  
  return {
    subscribe,
    
    /**
     * Add a new error
     * @param {Object|string} error - Error object or message string
     * @returns {number} - Error ID for tracking
     */
    add: (error) => {
      const id = Date.now();
      const errorObj = {
        id,
        message: error.message || error,
        code: error.code || 'UNKNOWN',
        details: error.details || null,
        level: error.level || ErrorLevel.ERROR,
        timestamp: new Date().toISOString()
      };
      
      update(errors => [...errors, errorObj]);
      
      // Log to console in debug mode
      if (typeof console !== 'undefined') {
        console.error(`[ERROR:${errorObj.code}]`, errorObj.message, errorObj.details || '');
      }
      
      return id;
    },
    
    /**
     * Add info message
     */
    info: (message, code = 'INFO') => {
      const id = Date.now();
      const errorObj = {
        id,
        message,
        code,
        level: ErrorLevel.INFO,
        timestamp: new Date().toISOString()
      };
      update(errors => [...errors, errorObj]);
      if (typeof console !== 'undefined') {
        console.error(`[ERROR:${code}]`, message);
      }
      return id;
    },

    /**
     * Add warning
     */
    warning: (message, code = 'WARNING') => {
      const id = Date.now();
      const errorObj = {
        id,
        message,
        code,
        level: ErrorLevel.WARNING,
        timestamp: new Date().toISOString()
      };
      update(errors => [...errors, errorObj]);
      if (typeof console !== 'undefined') {
        console.warn(`[WARN:${code}]`, message);
      }
      return id;
    },

    /**
     * Add error
     */
    error: (message, code = 'ERROR', details = null) => {
      const id = Date.now();
      const errorObj = {
        id,
        message,
        code,
        details,
        level: ErrorLevel.ERROR,
        timestamp: new Date().toISOString()
      };
      update(errors => [...errors, errorObj]);
      if (typeof console !== 'undefined') {
        console.error(`[ERROR:${code}]`, message, details || '');
      }
      return id;
    },

    /**
     * Add critical error
     */
    critical: (message, code = 'CRITICAL', details = null) => {
      const id = Date.now();
      const errorObj = {
        id,
        message,
        code,
        details,
        level: ErrorLevel.CRITICAL,
        timestamp: new Date().toISOString()
      };
      update(errors => [...errors, errorObj]);
      if (typeof console !== 'undefined') {
        console.error(`[CRITICAL:${code}]`, message, details || '');
      }
      return id;
    },
    
    /**
     * Remove error by ID
     */
    remove: (id) => {
      update(errors => errors.filter(e => e.id !== id));
    },
    
    /**
     * Clear all errors
     */
    clear: () => set([]),
    
    /**
     * Get errors by code
     */
    getByCode: (code) => {
      let result;
      subscribe(errors => {
        result = errors.filter(e => e.code === code);
      })();
      return result;
    },
    
    /**
     * Get errors by level
     */
    getByLevel: (level) => {
      let result;
      subscribe(errors => {
        result = errors.filter(e => e.level === level);
      })();
      return result;
    }
  };
}

export const errors = createErrorStore();
