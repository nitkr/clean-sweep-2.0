/**
 * Cron / scheduled task audit store
 */
import { writable, derived } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { errors } from '../errors.js';

function createCronStore() {
  const { subscribe, set, update } = writable({
    auditing: false,
    acting: false,
    error: null,
    results: null,
    selectedKeys: [],
    expandedKey: null,
    lastAuditedAt: null,
    filter: 'issues', // issues | all | wp_cron | action_scheduler
  });

  return {
    subscribe,

    setFilter(filter) {
      update(s => ({ ...s, filter }));
    },

    toggleSelected(key) {
      update(s => {
        const has = s.selectedKeys.includes(key);
        return {
          ...s,
          selectedKeys: has
            ? s.selectedKeys.filter(x => x !== key)
            : [...s.selectedKeys, key],
        };
      });
    },

    selectSuspicious() {
      update(s => {
        const events = [
          ...(s.results?.wp_cron?.events || []),
          ...(s.results?.action_scheduler?.actions || []),
        ];
        return {
          ...s,
          selectedKeys: events
            .filter(e => e.status === 'critical' || e.status === 'warning')
            .map(e => e.id),
        };
      });
    },

    clearSelected() {
      update(s => ({ ...s, selectedKeys: [] }));
    },

    expand(key) {
      update(s => ({ ...s, expandedKey: s.expandedKey === key ? null : key }));
    },

    async runAudit() {
      update(s => ({
        ...s,
        auditing: true,
        error: null,
        selectedKeys: [],
        expandedKey: null,
      }));
      try {
        const response = await adapters.cron.audit();
        if (response.success && response.data) {
          update(s => ({
            ...s,
            auditing: false,
            results: response.data,
            lastAuditedAt: Date.now(),
          }));
        } else {
          const msg = response.error || 'Cron audit failed';
          update(s => ({ ...s, auditing: false, error: msg }));
          errors.add({ message: msg, code: 'CRON_AUDIT_ERROR' });
        }
      } catch (e) {
        const msg = e?.message || String(e);
        update(s => ({ ...s, auditing: false, error: msg }));
        errors.add({ message: msg, code: 'CRON_AUDIT_ERROR' });
      }
    },

    async deleteSelected() {
      let state;
      subscribe(s => { state = s; })();
      const keys = state.selectedKeys || [];
      if (!keys.length) return;

      const events = [
        ...(state.results?.wp_cron?.events || []),
        ...(state.results?.action_scheduler?.actions || []),
      ];
      const byId = new Map(events.map(e => [e.id, e]));

      update(s => ({ ...s, acting: true }));
      try {
        for (const key of keys) {
          const ev = byId.get(key);
          if (!ev) continue;
          if (ev.source === 'action_scheduler' && ev.action_id) {
            const res = await adapters.cron.cancelAsAction(ev.action_id);
            if (!res.success) {
              errors.add({ message: res.error || 'Cancel AS failed', code: 'CRON_ACTION_ERROR' });
            }
          } else if (ev.source === 'wp_cron') {
            const res = await adapters.cron.deleteEvent(ev.hook, ev.timestamp, ev.sig);
            if (!res.success) {
              errors.add({ message: res.error || 'Delete event failed', code: 'CRON_ACTION_ERROR' });
            }
          }
        }
        await this.runAudit();
      } finally {
        update(s => ({ ...s, acting: false, selectedKeys: [] }));
      }
    },

    async deleteEvent(ev) {
      update(s => ({ ...s, acting: true }));
      try {
        if (ev.source === 'action_scheduler' && ev.action_id) {
          const res = await adapters.cron.cancelAsAction(ev.action_id);
          if (!res.success) {
            errors.add({ message: res.error || 'Cancel failed', code: 'CRON_ACTION_ERROR' });
          }
        } else {
          const res = await adapters.cron.deleteEvent(ev.hook, ev.timestamp, ev.sig);
          if (!res.success) {
            errors.add({ message: res.error || 'Delete failed', code: 'CRON_ACTION_ERROR' });
          }
        }
        await this.runAudit();
      } finally {
        update(s => ({ ...s, acting: false }));
      }
    },
  };
}

export const cronAudit = createCronStore();

export const cronEventsView = derived(cronAudit, $c => {
  const wp = $c.results?.wp_cron?.events || [];
  const as = $c.results?.action_scheduler?.actions || [];
  let list;
  if ($c.filter === 'wp_cron') {
    list = wp;
  } else if ($c.filter === 'action_scheduler') {
    list = as;
  } else {
    list = [...wp, ...as];
  }
  if ($c.filter === 'issues') {
    list = list.filter(e => e.status === 'critical' || e.status === 'warning');
  }
  return list;
});
