/**
 * Files API Adapter
 * Domain-specific adapter for file operations
 */

import type {
  ApiResponse
} from '../../../shared/types/api.js';
import { API_CONFIG, buildApiBody } from '../../../config/api.js';
import type { IApiAdapter } from '../adapter.js';

/**
 * Directory listing response
 */
export interface DirectoryListing {
  path: string;
  files: Array<{
    name: string;
    path: string;
    type: 'file' | 'folder';
    size?: number;
    modified?: string;
    infected?: boolean;
  }>;
}

/**
 * File content response
 */
export interface FileContentData {
  path: string;
  content: string;
  original?: string;
  modified: boolean;
  lineCount: number;
}

/**
 * Files API Adapter
 * Handles file browsing and content operations
 */
export class FilesApiAdapter {
  constructor(private adapter: IApiAdapter) {}

  /**
   * List directory contents
   * @param path - Directory path (empty for root)
   */
  async listDirectory(path: string = ''): Promise<ApiResponse<DirectoryListing>> {
    const endpoint = API_CONFIG.endpoints.files.base;
    
    const body = buildApiBody('list_directory', {
      path: path
    });

    return this.adapter.request<DirectoryListing>(endpoint, {
      method: 'POST',
      body
    });
  }

  /**
   * Get file content for diff view
   * @param path - File path
   */
  async getFileContent(path: string): Promise<ApiResponse<FileContentData>> {
    const endpoint = API_CONFIG.endpoints.files.base;
    
    const body = buildApiBody('get_file_content', {
      path: path
    });

    return this.adapter.request<FileContentData>(endpoint, {
      method: 'POST',
      body
    });
  }

  /**
   * Official wordpress.org file for checksum diffs.
   */
  async getOriginalContent(
    path: string,
    meta: { package_type?: string; package_slug?: string; version?: string } = {}
  ): Promise<ApiResponse<{ content: string; version?: string; source?: string; kind?: string }>> {
    const endpoint = API_CONFIG.endpoints.files.base;

    const body = buildApiBody('get_original_content', {
      path,
      package_type: meta.package_type || '',
      package_slug: meta.package_slug || '',
      version: meta.version || '',
    });

    return this.adapter.request<{ content: string; version?: string; source?: string; kind?: string }>(endpoint, {
      method: 'POST',
      body
    });
  }

  /**
   * Restore file to original/clean version
   * @param path - File path to restore
   * @param content - New content to write
   */
  async saveFileContent(path: string, content: string): Promise<ApiResponse<{ bytes_written: number; backup_path: string }>> {
    const endpoint = API_CONFIG.endpoints.files.base;
    
    const body = buildApiBody('save_file_content', {
      path,
      content
    });

    return this.adapter.request<{ bytes_written: number; backup_path: string }>(endpoint, {
      method: 'POST',
      body
    });
  }
}
