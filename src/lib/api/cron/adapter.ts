/**
 * Cron audit API adapter
 */
import type { ApiResponse } from '../../../shared/types/api.js';
import { API_CONFIG, buildApiBody } from '../../../config/api.js';
import type { IApiAdapter } from '../adapter.js';

export class CronApiAdapter {
  constructor(private adapter: IApiAdapter) {}

  private endpoint() {
    return API_CONFIG.endpoints.cron?.base ?? 'api/cron.php';
  }

  async audit(): Promise<ApiResponse<any>> {
    return this.adapter.request(this.endpoint(), {
      method: 'POST',
      body: buildApiBody('audit_cron', {}),
    });
  }

  async deleteEvent(hook: string, timestamp: number, sig?: string | null): Promise<ApiResponse<any>> {
    const data: Record<string, unknown> = { hook, timestamp };
    if (sig) data.sig = sig;
    return this.adapter.request(this.endpoint(), {
      method: 'POST',
      body: buildApiBody('delete_event', data),
    });
  }

  async clearHook(hook: string): Promise<ApiResponse<any>> {
    return this.adapter.request(this.endpoint(), {
      method: 'POST',
      body: buildApiBody('clear_hook', { hook }),
    });
  }

  async cancelAsAction(actionId: number): Promise<ApiResponse<any>> {
    return this.adapter.request(this.endpoint(), {
      method: 'POST',
      body: buildApiBody('cancel_as_action', { action_id: actionId }),
    });
  }
}
