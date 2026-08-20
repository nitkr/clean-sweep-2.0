/**
 * Core API Adapter
 * Domain-specific adapter for WordPress core operations
 */

import type {
  ApiResponse,
  CoreReinstallResponse,
  VersionOptionsResponse
} from '../../../shared/types/api.js';
import { API_CONFIG, buildApiBody } from '../../../config/api.js';
import type { IApiAdapter, ProgressCallback } from '../adapter.js';

/**
 * Core API Adapter
 * Handles WordPress core reinstallation operations
 */
export class CoreApiAdapter {
  constructor(private adapter: IApiAdapter) {}

  /**
   * Reinstall WordPress core files
   * @param version - Version to reinstall (default: 'latest')
   * @param createBackup - Whether to create backup before reinstall (default: true)
   */
  async reinstall(
    version: string = 'latest',
    createBackup: boolean = true,
    onProgress?: ProgressCallback,
    progressFile?: string
  ): Promise<ApiResponse<CoreReinstallResponse>> {
    const progress_file = progressFile || `core_reinstall_${Date.now()}.progress`;
    
    const endpoint = API_CONFIG.endpoints.core.base;
    const body = buildApiBody('reinstall_core', { 
      wp_version: version,
      create_backup: createBackup,
      proceed_without_backup: !createBackup,
      progress_file: progress_file
    });

    if (onProgress) {
      return this.adapter.requestWithProgress<CoreReinstallResponse>(
        endpoint,
        { method: 'POST', body },
        onProgress
      );
    }

    const response = await this.adapter.request<CoreReinstallResponse>(endpoint, {
      method: 'POST',
      body
    });
    if (response && typeof response === 'object' && !(response as any).data?.progress_file) {
      (response as any).data = { ...(response as any).data, progress_file };
    }
    return response;
  }

  /**
   * Get current WordPress core information
   */
  async getInfo(): Promise<ApiResponse<{ version: string; latest_version: string }>> {
    const endpoint = API_CONFIG.endpoints.core.base;
    const body = buildApiBody('get_core_info');

    return this.adapter.request(endpoint, {
      method: 'POST',
      body
    });
  }

  /**
   * Get available WordPress version options for reinstallation
   */
  async getVersionOptions(): Promise<ApiResponse<VersionOptionsResponse>> {
    const endpoint = API_CONFIG.endpoints.core.base;
    const body = buildApiBody('get_version_options');

    return this.adapter.request(endpoint, {
      method: 'POST',
      body
    });
  }

  /**
   * Poll for core reinstall progress updates
   * @param progressFile - Progress file to poll
   * @param onProgress - Progress callback
   * @returns Cancel function
   */
  pollReinstallProgress(
    progressFile: string,
    onProgress: (progress: any) => void
  ): () => void {
    return this.adapter.pollProgress(progressFile, onProgress, 1000);
  }
}
