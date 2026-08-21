/**
 * Vulnerabilities store — independent of malware scanning.
 */
import { writable, derived } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { errors } from '../errors.js';

function createVulnerabilitiesStore() {
  const { subscribe, set, update } = writable({
    scanning: false,
    error: null,
    results: null,
    selectedVulnerability: null,
    lastScannedAt: null,
  });

  return {
    subscribe,

    async startScan() {
      update(s => ({
        ...s,
        scanning: true,
        error: null,
      }));

      try {
        const response = await adapters.vulnerabilities.scan();
        if (response.success && response.data) {
          update(s => ({
            ...s,
            scanning: false,
            results: response.data,
            lastScannedAt: response.data.scanned_at
              ? response.data.scanned_at * 1000
              : Date.now(),
          }));
        } else {
          const msg = response.error || 'Vulnerability scan failed';
          update(s => ({
            ...s,
            scanning: false,
            error: msg,
          }));
          errors.add({ message: msg, code: 'VULN_SCAN_ERROR' });
        }
      } catch (e) {
        const msg = e?.message || String(e);
        update(s => ({
          ...s,
          scanning: false,
          error: msg,
        }));
        errors.add({ message: msg, code: 'VULN_SCAN_ERROR' });
      }
    },

    /**
     * Restore last vulnerability results after page refresh.
     * Does not start a new network CVE lookup.
     */
    async rehydrateFromServer() {
      try {
        const response = await adapters.vulnerabilities.latest();
        const data = response?.success ? response.data : null;
        const hasPayload = !!(data && (data.summary || Array.isArray(data.vulnerabilities)));
        if (!hasPayload) {
          return { restored: false };
        }
        let restored = false;
        update(s => {
          if (s.scanning || s.results) {
            return s;
          }
          restored = true;
          return {
            ...s,
            results: data,
            lastScannedAt: data.scanned_at
              ? data.scanned_at * 1000
              : Date.now(),
            error: null,
          };
        });
        return { restored };
      } catch (e) {
        return { restored: false, error: e?.message || String(e) };
      }
    },

    selectVulnerability(vuln) {
      update(s => ({ ...s, selectedVulnerability: vuln }));
    },

    clearResults() {
      update(s => ({
        ...s,
        results: null,
        selectedVulnerability: null,
        error: null,
      }));
    },

    reset() {
      set({
        scanning: false,
        error: null,
        results: null,
        selectedVulnerability: null,
        lastScannedAt: null,
      });
    },
  };
}

export const vulnerabilities = createVulnerabilitiesStore();

export const vulnCounts = derived(vulnerabilities, $v => {
  const list = $v.results?.vulnerabilities || [];
  return {
    total: list.length || $v.results?.summary?.total || 0,
    critical: list.filter(x => x.risk_level === 'critical').length,
    high: list.filter(x => x.risk_level === 'high').length,
    medium: list.filter(x => x.risk_level === 'medium').length,
    low: list.filter(x => x.risk_level === 'low' || x.risk_level === 'info').length,
  };
});
