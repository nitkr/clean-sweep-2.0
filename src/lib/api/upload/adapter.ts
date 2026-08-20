/**
 * Upload API Adapter
 * Domain-specific adapter for file upload operations
 */

import type {
  ApiResponse,
  ProgressUpdate,
  UploadDiscardResult,
  UploadExtractOpts,
  UploadExtractResult,
  UploadInspectResult,
  UploadStageResult,
  UploadStatusResult,
} from '../../../shared/types/api.js';
import { API_CONFIG } from '../../../config/api.js';
import type { IApiAdapter, ProgressCallback } from '../adapter.js';

/**
 * Upload API Adapter
 * Handles file upload operations
 */
export class UploadApiAdapter {
  constructor(private adapter: IApiAdapter) {}

  /**
   * Upload a ZIP file
   * @param file - File to upload
   * @param onProgress - Progress callback (0-100)
   */
  async uploadZip(
    file: File,
    onProgress?: (percent: number) => void
  ): Promise<ApiResponse<UploadStageResult>> {
    const endpoint = API_CONFIG.endpoints.upload.base;

    if (onProgress) {
      return this.adapter.uploadFile(endpoint, file, onProgress) as Promise<ApiResponse<UploadStageResult>>;
    }

    const formData = new FormData();
    formData.append('action', 'upload_zip');
    formData.append('file', file);

    return this.adapter.request<UploadStageResult>(endpoint, {
      method: 'POST',
      body: formData
    });
  }

  async inspect(uploadId: string): Promise<ApiResponse<UploadInspectResult>> {
    const endpoint = API_CONFIG.endpoints.upload.base;
    const formData = new FormData();
    formData.append('action', 'inspect_zip');
    formData.append('upload_id', uploadId);

    return this.adapter.request<UploadInspectResult>(endpoint, {
      method: 'POST',
      body: formData
    });
  }

  async discard(uploadId: string): Promise<ApiResponse<UploadDiscardResult>> {
    const endpoint = API_CONFIG.endpoints.upload.base;
    const formData = new FormData();
    formData.append('action', 'discard_upload');
    formData.append('upload_id', uploadId);

    return this.adapter.request<UploadDiscardResult>(endpoint, {
      method: 'POST',
      body: formData
    });
  }

  async status(uploadId: string): Promise<ApiResponse<UploadStatusResult>> {
    const endpoint = API_CONFIG.endpoints.upload.base;
    const formData = new FormData();
    formData.append('action', 'get_upload_status');
    formData.append('upload_id', uploadId);

    return this.adapter.request<UploadStatusResult>(endpoint, {
      method: 'POST',
      body: formData
    });
  }

  /**
   * Extract a staged ZIP. Destination is required. No activate / network / force.
   */
  async extract(opts: UploadExtractOpts): Promise<ApiResponse<UploadExtractResult>> {
    const endpoint = API_CONFIG.endpoints.upload.base;
    const formData = new FormData();
    formData.append('action', 'extract_zip');
    formData.append('upload_id', opts.uploadId);
    formData.append('destination', opts.destination);
    if (opts.customRel) {
      formData.append('custom_rel', opts.customRel);
    }
    if (opts.confirmOverwrite) {
      formData.append('confirm_overwrite', '1');
    }
    if (opts.createBackup) {
      formData.append('create_backup', '1');
    }
    if (opts.confirmRoot) {
      formData.append('confirm_root', '1');
    }

    return this.adapter.request<UploadExtractResult>(endpoint, {
      method: 'POST',
      body: formData
    });
  }

  pollExtractProgress(
    progressFile: string,
    onProgress: (progress: ProgressUpdate) => void
  ): () => void {
    return this.adapter.pollProgress(
      progressFile,
      onProgress as ProgressCallback,
      API_CONFIG.polling.interval
    );
  }
}
