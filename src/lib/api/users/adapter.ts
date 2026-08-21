/**
 * Users / access audit API adapter
 */
import type { ApiResponse } from '../../../shared/types/api.js';
import { API_CONFIG, buildApiBody } from '../../../config/api.js';
import type { IApiAdapter } from '../adapter.js';

export class UsersApiAdapter {
  constructor(private adapter: IApiAdapter) {}

  private endpoint() {
    return API_CONFIG.endpoints.users?.base ?? 'api/users.php';
  }

  async audit(): Promise<ApiResponse<any>> {
    return this.adapter.request(this.endpoint(), {
      method: 'POST',
      body: buildApiBody('audit_users', {}),
    });
  }

  async revokeAppPasswords(userId: number): Promise<ApiResponse<any>> {
    return this.adapter.request(this.endpoint(), {
      method: 'POST',
      body: buildApiBody('revoke_app_passwords', { user_id: userId }),
    });
  }

  async destroySessions(userId: number): Promise<ApiResponse<any>> {
    return this.adapter.request(this.endpoint(), {
      method: 'POST',
      body: buildApiBody('destroy_sessions', { user_id: userId }),
    });
  }

  async demoteUser(userId: number, role = 'subscriber'): Promise<ApiResponse<any>> {
    return this.adapter.request(this.endpoint(), {
      method: 'POST',
      body: buildApiBody('demote_user', { user_id: userId, role }),
    });
  }

  async deleteUser(userId: number, reassign?: number | null): Promise<ApiResponse<any>> {
    const data: Record<string, unknown> = { user_id: userId };
    if (reassign != null) data.reassign = reassign;
    return this.adapter.request(this.endpoint(), {
      method: 'POST',
      body: buildApiBody('delete_user', data),
    });
  }
}
