/**
 * Theme Store - Dark/Light Mode
 * Handles theme persistence and toggling.
 * Applies `.dark` on <html> for Tailwind class strategy + CSS tokens.
 *
 * First visit (no saved preference): dark or light is chosen at random
 * and stored. After that, only the theme toggle (or set()) changes it.
 */

import { writable } from 'svelte/store';

const STORAGE_KEY = 'theme';

function applyDom(themeName) {
  if (typeof document === 'undefined') return;
  const isDark = themeName === 'dark';
  document.documentElement.classList.toggle('dark', isDark);
  document.documentElement.setAttribute('data-theme', themeName);
  document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
}

function isValidTheme(value) {
  return value === 'light' || value === 'dark';
}

/**
 * Resolve theme for this session:
 * - saved preference → use it
 * - no preference → random dark/light, then persist
 */
function resolveTheme({ persistRandom = true } = {}) {
  if (typeof localStorage === 'undefined') {
    return Math.random() < 0.5 ? 'dark' : 'light';
  }

  const stored = localStorage.getItem(STORAGE_KEY);
  if (isValidTheme(stored)) {
    return stored;
  }

  const randomTheme = Math.random() < 0.5 ? 'dark' : 'light';
  if (persistRandom) {
    localStorage.setItem(STORAGE_KEY, randomTheme);
  }
  return randomTheme;
}

function createThemeStore() {
  // Match first paint when possible (may pick random once if unset)
  const initial = resolveTheme({ persistRandom: true });
  const { subscribe, set, update } = writable(initial);

  // Apply immediately so first paint matches store (SSR-safe guard inside applyDom)
  applyDom(initial);

  return {
    subscribe,

    /**
     * Toggle between dark and light mode (persists choice)
     */
    toggle: () => {
      update((current) => {
        const next = current === 'dark' ? 'light' : 'dark';

        if (typeof localStorage !== 'undefined') {
          localStorage.setItem(STORAGE_KEY, next);
        }
        applyDom(next);
        return next;
      });
    },

    /**
     * Initialize / re-sync theme on app load
     */
    init: () => {
      if (typeof document === 'undefined') return;

      const themeName = resolveTheme({ persistRandom: true });
      applyDom(themeName);
      set(themeName);
    },

    /**
     * Set specific theme (persists choice)
     */
    set: (themeName) => {
      const next = themeName === 'light' ? 'light' : 'dark';

      if (typeof localStorage !== 'undefined') {
        localStorage.setItem(STORAGE_KEY, next);
      }
      applyDom(next);
      set(next);
    },
  };
}

export const theme = createThemeStore();
