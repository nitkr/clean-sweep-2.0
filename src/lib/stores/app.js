/**
 * Global App Store
 * Manages global application state
 */

import { writable } from 'svelte/store';

function createAppStore() {
  const { subscribe, set, update } = writable({
    // Navigation
    activeTab: 'dashboard',
    // One-shot: Upload success "Analyze Extensions" only. Null unless that button set it.
    extensionsAnalyze: null,

    // Progress (shared across features)
    progressPercent: 0,
    progressMessage: 'Ready',
    progressStatus: 'idle', // idle, running, complete, error
    progressDetails: '',
    progressFile: null,

    // Error state
    error: null,

    // Loading states
    isLoading: false,

    // Selected file from explorer
    selectedFile: null,

    // Editor scroll position (for jumping to threat line)
    scrollToLine: null,

    // After Remove Clean Sweep succeeds — hide the whole cockpit
    toolkitRemoved: false
  });

  return {
    subscribe,

    /**
     * Set active tab
     */
    setActiveTab: (tab) => {
      update(s => ({ ...s, activeTab: tab }));
    },

    markToolkitRemoved: () => {
      update(s => ({ ...s, toolkitRemoved: true }));
    },

    /**
     * Switch to Extensions and ask it to start analyze. Does not run analyze here.
     */
    requestExtensionsAnalyze: (kind = 'plugins') => {
      const next = kind === 'themes' ? 'themes' : 'plugins';
      update(s => ({
        ...s,
        activeTab: 'plugins',
        extensionsAnalyze: next,
      }));
    },

    /**
     * Read-and-clear the Analyze-Extensions intent. Null if the operator just opened the tab.
     */
    consumeExtensionsAnalyze: () => {
      let kind = null;
      update(s => {
        kind = s.extensionsAnalyze || null;
        if (!kind) return s;
        return { ...s, extensionsAnalyze: null };
      });
      return kind;
    },

    /**
     * Set selected file from explorer
     */
    setSelectedFile: (file) => {
      update(s => ({ ...s, selectedFile: file }));
    },

    /**
     * Set scroll-to line number in editor
     */
    setScrollToLine: (lineNumber) => {
      update(s => ({ ...s, scrollToLine: lineNumber }));
    },

    /**
     * Clear scroll position
     */
    clearScrollToLine: () => {
      update(s => ({ ...s, scrollToLine: null }));
    },

    /**
     * Update progress
     */
    setProgress: (percent, message, status = 'running') => {
      update(s => ({
        ...s,
        progressPercent: percent,
        progressMessage: message,
        progressStatus: status
      }));
    },

    /**
     * Reset progress
     */
    resetProgress: () => {
      update(s => ({
        ...s,
        progressPercent: 0,
        progressMessage: 'Ready',
        progressStatus: 'idle',
        progressDetails: '',
        progressFile: null
      }));
    },

    /**
     * Set error
     */
    setError: (error) => {
      update(s => ({ ...s, error }));
    },

    /**
     * Clear error
     */
    clearError: () => {
      update(s => ({ ...s, error: null }));
    },

    /**
     * Set loading state
     */
    setLoading: (isLoading) => {
      update(s => ({ ...s, isLoading }));
    }
  };
}

export const app = createAppStore();
