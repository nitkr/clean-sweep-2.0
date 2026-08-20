/**
 * Users / access audit store
 */
import { writable, derived } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { errors } from '../errors.js';

function createUsersStore() {
  const { subscribe, set, update } = writable({
    auditing: false,
    acting: false,
    error: null,
    results: null,
    selectedIds: [],
    expandedId: null,
    lastAuditedAt: null,
    filter: 'all', // all | issues | admins | hidden
  });

  return {
    subscribe,

    setFilter(filter) {
      update(s => ({ ...s, filter }));
    },

    setSelected(ids) {
      update(s => ({ ...s, selectedIds: ids }));
    },

    toggleSelected(id) {
      update(s => {
        const has = s.selectedIds.includes(id);
        return {
          ...s,
          selectedIds: has
            ? s.selectedIds.filter(x => x !== id)
            : [...s.selectedIds, id],
        };
      });
    },

    selectIssues() {
      update(s => {
        const users = s.results?.users || [];
        return {
          ...s,
          selectedIds: users
            .filter(u => u.status === 'critical' || u.status === 'warning')
            .map(u => u.id),
        };
      });
    },

    clearSelected() {
      update(s => ({ ...s, selectedIds: [] }));
    },

    expand(id) {
      update(s => ({ ...s, expandedId: s.expandedId === id ? null : id }));
    },

    async runAudit() {
      update(s => ({
        ...s,
        auditing: true,
        error: null,
        selectedIds: [],
        expandedId: null,
      }));
      try {
        const response = await adapters.users.audit();
        if (response.success && response.data) {
          update(s => ({
            ...s,
            auditing: false,
            results: response.data,
            lastAuditedAt: Date.now(),
          }));
        } else {
          const msg = response.error || 'User audit failed';
          update(s => ({ ...s, auditing: false, error: msg }));
          errors.add({ message: msg, code: 'USER_AUDIT_ERROR' });
        }
      } catch (e) {
        const msg = e?.message || String(e);
        update(s => ({ ...s, auditing: false, error: msg }));
        errors.add({ message: msg, code: 'USER_AUDIT_ERROR' });
      }
    },

    async revokeAppPasswords(userId) {
      update(s => ({ ...s, acting: true }));
      try {
        const response = await adapters.users.revokeAppPasswords(userId);
        if (!response.success) {
          errors.add({ message: response.error || 'Revoke failed', code: 'USER_ACTION_ERROR' });
        }
        await this.runAudit();
      } finally {
        update(s => ({ ...s, acting: false }));
      }
    },

    async destroySessions(userId) {
      update(s => ({ ...s, acting: true }));
      try {
        const response = await adapters.users.destroySessions(userId);
        if (!response.success) {
          errors.add({ message: response.error || 'Session destroy failed', code: 'USER_ACTION_ERROR' });
        }
        await this.runAudit();
      } finally {
        update(s => ({ ...s, acting: false }));
      }
    },

    async demoteUser(userId) {
      update(s => ({ ...s, acting: true }));
      try {
        const response = await adapters.users.demoteUser(userId, 'subscriber');
        if (!response.success) {
          errors.add({ message: response.error || 'Demote failed', code: 'USER_ACTION_ERROR' });
        }
        await this.runAudit();
      } finally {
        update(s => ({ ...s, acting: false }));
      }
    },

    async deleteUser(userId) {
      update(s => ({ ...s, acting: true }));
      try {
        const response = await adapters.users.deleteUser(userId);
        if (!response.success) {
          errors.add({ message: response.error || 'Delete failed', code: 'USER_ACTION_ERROR' });
        }
        await this.runAudit();
      } finally {
        update(s => ({ ...s, acting: false }));
      }
    },
  };
}

export const usersAudit = createUsersStore();

export const usersList = derived(usersAudit, $u => {
  const list = $u.results?.users || [];
  if ($u.filter === 'admins') {
    return list.filter(x => x.is_administrator || x.is_super_admin);
  }
  if ($u.filter === 'hidden') {
    return list.filter(x => x.hidden_from_admin || x.hidden_admin);
  }
  if ($u.filter === 'issues') {
    return list.filter(x => x.status === 'critical' || x.status === 'warning');
  }
  return list;
});
