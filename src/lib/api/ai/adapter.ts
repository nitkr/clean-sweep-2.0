/**
 * AI scan API. Separate from the signature scanner.
 */
import type { ApiResponse } from '../../../shared/types/api.js';
import { API_CONFIG, buildApiBody } from '../../../config/api.js';
import type { IApiAdapter } from '../adapter.js';

export class AiApiAdapter {
  constructor(private adapter: IApiAdapter) {}

  private post(action: string, data: Record<string, unknown> = {}): Promise<ApiResponse<any>> {
    return this.adapter.request(API_CONFIG.endpoints.ai.base, {
      method: 'POST',
      body: buildApiBody(action, data),
    });
  }

  getConfig() { return this.post('get_config'); }
  saveConfig(data: Record<string, unknown>) { return this.post('save_config', data); }
  savePolicy(data: Record<string, unknown>) { return this.post('save_policy', data); }
  listModels() { return this.post('list_models'); }
  test() { return this.post('test'); }
  begin(scanId: string) { return this.post('begin', { scan_id: scanId }); }
  start(scanId?: string) { return this.post('start', scanId ? { scan_id: scanId } : {}); }
  resume() { return this.post('resume'); }
  status() { return this.post('status'); }
  cancel() { return this.post('cancel'); }
  getLog(offset = 0, limit = 200) { return this.post('get_log', { offset, limit }); }
  getReport() { return this.post('get_report'); }
  sendFile(path: string) { return this.post('send_file', { path }); }
  keep(path: string) { return this.post('keep', { path }); }
}
