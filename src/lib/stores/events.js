/**
 * Events Store
 * Centralized event logging for the RightPanel Events tab
 * Provides real-time logging of all plugin/theme operations
 */

import { writable, derived } from 'svelte/store';

const MAX_EVENTS = 100; // Limit events to prevent memory issues

function createEventsStore() {
  const { subscribe, set, update } = writable([]);
  
  // Helper to add event
  const addEvent = (category, action, message, details = null) => {
    const event = {
      id: Date.now() + Math.random(),
      timestamp: new Date().toISOString(),
      category,
      action,
      message,
      details,
      // Status derived from action
      status: action.includes('error') || action.includes('fail') ? 'error' 
        : action.includes('complete') || action.includes('success') ? 'success'
        : action.includes('warning') ? 'warning'
        : 'info'
    };
    
    update(events => {
      const updated = [event, ...events];
      // Keep only the most recent MAX_EVENTS
      return updated.slice(0, MAX_EVENTS);
    });
    
    return event.id;
  };
  
  return {
    subscribe,
    
    /**
     * Add a new event to the log
     * @param {string} category - Event category (PLUGINS, THEMES, CORE, etc.)
     * @param {string} action - Action type (analyze_start, reinstall, backup, etc.)
     * @param {string} message - Human-readable message
     * @param {Object} details - Optional additional details
     */
    add: addEvent,
    
    /**
     * Plugin-specific event helpers
     */
    plugins: {
      analyzeStart: () => addEvent('PLUGINS', 'analyze_start', 'Starting plugin analysis...'),
      analyzeComplete: (count) => addEvent('PLUGINS', 'analyze_complete', `Analysis complete. Found ${count} plugins`),
      analyzeError: (error) => addEvent('PLUGINS', 'analyze_error', `Analysis failed: ${error}`),
      reinstallStart: (count) => addEvent('PLUGINS', 'reinstall_start', `Starting reinstallation of ${count} plugins...`),
      reinstallProgress: (current, total, plugin) => addEvent('PLUGINS', 'reinstall_progress', `Reinstalling (${current}/${total}): ${plugin}`),
      reinstallComplete: (count) => addEvent('PLUGINS', 'reinstall_complete', `Successfully reinstalled ${count} plugins`),
      reinstallError: (error) => addEvent('PLUGINS', 'reinstall_error', `Reinstallation failed: ${error}`),
      backupStart: () => addEvent('PLUGINS', 'backup_start', 'Creating plugin backup...'),
      backupComplete: (path) => addEvent('PLUGINS', 'backup_complete', `Backup created successfully`),
      backupSkip: () => addEvent('PLUGINS', 'backup_skip', 'Backup skipped by user'),
      selectionChange: (count) => addEvent('PLUGINS', 'selection_change', `${count} plugins selected for reinstallation`)
    },
    
    /**
     * Theme-specific event helpers
     */
    themes: {
      analyzeStart: () => addEvent('THEMES', 'analyze_start', 'Starting theme analysis...'),
      analyzeComplete: (count) => addEvent('THEMES', 'analyze_complete', `Analysis complete. Found ${count} themes`),
      analyzeError: (error) => addEvent('THEMES', 'analyze_error', `Analysis failed: ${error}`),
      reinstallStart: (count) => addEvent('THEMES', 'reinstall_start', `Starting reinstallation of ${count} themes...`),
      reinstallProgress: (current, total, theme) => addEvent('THEMES', 'reinstall_progress', `Reinstalling (${current}/${total}): ${theme}`),
      reinstallComplete: (count) => addEvent('THEMES', 'reinstall_complete', `Successfully reinstalled ${count} themes`),
      reinstallError: (error) => addEvent('THEMES', 'reinstall_error', `Reinstallation failed: ${error}`),
      backupStart: () => addEvent('THEMES', 'backup_start', 'Creating theme backup...'),
      backupComplete: (path) => addEvent('THEMES', 'backup_complete', `Backup created successfully`),
      backupSkip: () => addEvent('THEMES', 'backup_skip', 'Backup skipped by user'),
      selectionChange: (count) => addEvent('THEMES', 'selection_change', `${count} themes selected for reinstallation`)
    },

    /**
     * Core-specific event helpers
     */
    core: {
      reinstallStart: () => addEvent('CORE', 'reinstall_start', 'Starting WordPress core reinstallation...'),
      reinstallProgress: (step) => addEvent('CORE', 'reinstall_progress', step),
      reinstallComplete: () => addEvent('CORE', 'reinstall_complete', 'Core reinstallation completed'),
      reinstallError: (error) => addEvent('CORE', 'reinstall_error', `Reinstallation failed: ${error}`)
    },
    
    /**
     * Clear all events
     */
    clear: () => {
      set([]);
    },
    
    /**
     * Get events filtered by category
     */
    getByCategory: (category) => {
      let currentEvents = [];
      subscribe(e => currentEvents = e)();
      return currentEvents.filter(e => e.category === category);
    }
  };
}

export const events = createEventsStore();

// Derived store for plugin-specific events (for easy filtering)
export const pluginEvents = derived(events, $events => 
  $events.filter(e => e.category === 'PLUGINS')
);

// Derived store for theme-specific events
export const themeEvents = derived(events, $events => 
  $events.filter(e => e.category === 'THEMES')
);
