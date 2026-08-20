/**
 * Toolkit self-check result from every API response.
 */
import { writable } from 'svelte/store';

function createToolkitStore() {
  const { subscribe, set } = writable({
    kind: 'ok',
    ok: true,
    extras: [],
    patched: [],
    checked: 0,
    dismissed: false,
  });

  return {
    subscribe,
    apply(payload) {
      if (!payload || typeof payload !== 'object') return;
      set({
        kind: payload.kind || 'ok',
        ok: payload.ok !== false && payload.kind === 'ok',
        extras: payload.extras || [],
        patched: payload.patched || [],
        checked: payload.checked || 0,
        dismissed: false,
      });
    },
    dismiss() {
      set({
        kind: 'ok',
        ok: true,
        extras: [],
        patched: [],
        checked: 0,
        dismissed: true,
      });
    },
  };
}

export const toolkit = createToolkitStore();
