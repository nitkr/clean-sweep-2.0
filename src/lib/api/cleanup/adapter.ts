/**
 * Cleanup API Adapter
 * Domain-specific adapter for cleanup operations
 */

import type { ApiResponse, CleanupResult } from '../../../shared/types/api.js';
import { API_CONFIG, buildApiBody } from '../../../config/api.js';
import type { IApiAdapter } from '../adapter.js';

/**
 * Cleanup API Adapter
 * Handles cleanup/removal operations
 */
export class CleanupApiAdapter {
  constructor(private adapter: IApiAdapter) {}

  /**
   * Remove Clean Sweep tool from the site
   * @param options.skipSnapshot - Mark snapshot as skipped for this visit if none downloaded
   */
  async removeTool(options: { skipSnapshot?: boolean } = {}): Promise<ApiResponse<CleanupResult>> {
    const endpoint = API_CONFIG.endpoints.cleanup.base;
    const body = buildApiBody('cleanup_tool', {
      confirm: 'yes',
      ...(options.skipSnapshot ? { skip_snapshot: '1' } : {})
    });

    return this.adapter.request<CleanupResult>(endpoint, {
      method: 'POST',
      body
    });
  }
}
