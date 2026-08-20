/**
 * Session Store
 * Manages UI session state that persists across the browser session
 */

import { writable } from 'svelte/store';

const STORAGE_KEY = 'clean_sweep_ui_session_id';

/**
 * Generate a UUID v4
 * @returns {string} UUID v4 string
 */
function generateUuidV4() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    const r = Math.random() * 16 | 0;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
}

/**
 * Get or create the UI session ID
 * This is called immediately on module import to ensure the ID exists
 * before any scan operations are initiated.
 *
 * @returns {string} The UI session ID
 */
function getOrCreateUiSessionId() {
  // Try to get existing session ID from localStorage
  try {
    const existing = localStorage.getItem(STORAGE_KEY);
    if (existing && typeof existing === 'string' && existing.length > 0) {
      return existing;
    }
  } catch (e) {
    console.warn('[SESSION] Could not read ui_session_id from localStorage:', e);
  }

  // Generate new UUID v4
  const newId = generateUuidV4();

  // Store for future use
  try {
    localStorage.setItem(STORAGE_KEY, newId);
  } catch (e) {
    console.warn('[SESSION] Could not save ui_session_id to localStorage:', e);
  }

  return newId;
}

function createSessionStore() {
  // IMMEDIATELY generate/create the session ID on store creation
  // This ensures the ID exists before any scan operations
  const initialSessionId = getOrCreateUiSessionId();

  console.log('[SESSION] UI session initialized:', initialSessionId);

  const { subscribe, set, update } = writable({
    uiSessionId: initialSessionId,
    lastActivityAt: Date.now(),
    lastActivityType: null,
  });

  return {
    subscribe,

    /**
     * Get the current UI session ID
     * @returns {string} The session ID
     */
    getUiSessionId() {
      return initialSessionId;
    },

    /**
     * Record a user activity event
     * @param {string} activityType - Type of activity (e.g., 'scan_start', 'resume', 'loopback')
     */
    recordActivity(activityType) {
      update(s => ({
        ...s,
        lastActivityAt: Date.now(),
        lastActivityType: activityType,
      }));
    },

    /**
     * Reset session (generates new session ID)
     * Use with caution - this breaks correlation with previous sessions
     */
    reset() {
      const newId = generateUuidV4();
      try {
        localStorage.setItem(STORAGE_KEY, newId);
      } catch (e) {
        console.warn('[SESSION] Could not save new ui_session_id to localStorage:', e);
      }
      set({
        uiSessionId: newId,
        lastActivityAt: Date.now(),
        lastActivityType: null,
      });
    }
  };
}

export const session = createSessionStore();