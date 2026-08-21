/**
 * Vulnerability API adapter — separate from malware Scanner v2.
 */
import type { ApiResponse } from '../../../shared/types/api.js';
import { API_CONFIG, buildApiBody } from '../../../config/api.js';
import type { IApiAdapter } from '../adapter.js';

export interface VulnerabilityScanSummary {
  core_vulnerabilities?: number;
  plugin_vulnerabilities?: number;
  theme_vulnerabilities?: number;
  total: number;
}

export interface VulnerabilityScanPayload {
  summary: VulnerabilityScanSummary;
  vulnerabilities: any[];
  groups?: any[];
  core?: unknown;
  plugins?: unknown[];
  themes?: unknown[];
  scanned_at?: number;
  results?: null;
}

export class VulnerabilitiesApiAdapter {
  constructor(private adapter: IApiAdapter) {}

  /**
   * Run a full vulnerability check (core + plugins + themes).
   */
  async scan(): Promise<ApiResponse<VulnerabilityScanPayload>> {
    const endpoint = API_CONFIG.endpoints.vulnerabilities?.base
      ?? 'api/vulnerabilities.php';
    const body = buildApiBody('scan', {});
    return this.adapter.request(endpoint, { method: 'POST', body });
  }

  /**
   * Last completed vulnerability check (server disk, 48h default TTL).
   * Used to restore dashboard / scanner cards after refresh.
   */
  async latest(
    completedTtlSeconds: number = 172800
  ): Promise<ApiResponse<VulnerabilityScanPayload>> {
    const endpoint = API_CONFIG.endpoints.vulnerabilities?.base
      ?? 'api/vulnerabilities.php';
    const body = buildApiBody('latest_vulnerabilities', {
      completed_ttl_seconds: completedTtlSeconds,
    });
    return this.adapter.request(endpoint, { method: 'POST', body });
  }
}
