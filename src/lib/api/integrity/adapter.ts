/**
 * Integrity API Adapter
 * Handles API communication for Integrity Baseline Management
 */

import { type IApiAdapter, type ProgressCallback } from '../adapter.js';

export class IntegrityApiAdapter {
  private adapter: IApiAdapter;
  
  constructor(adapter: IApiAdapter) {
    this.adapter = adapter;
  }
  
  /**
   * Get baseline information
   */
  async getBaselineInfo(): Promise<any> {
    return this.adapter.request('api/integrity.php', {
      method: 'POST',
      body: { action: 'get_baseline_info' }
    });
  }
  
  /**
   * Establish a new integrity baseline
   */
  async establishBaseline(mode: string = 'core'): Promise<any> {
    return this.adapter.request('api/integrity.php', {
      method: 'POST',
      body: { action: 'establish_baseline', mode }
    });
  }
  
  /**
   * Check integrity against existing baseline
   */
  async checkIntegrity(): Promise<any> {
    return this.adapter.request('api/integrity.php', {
      method: 'POST',
      body: { action: 'check_integrity' }
    });
  }

  async exportSnapshot(): Promise<any> {
    return this.adapter.request('api/integrity.php', {
      method: 'POST',
      body: { action: 'export_snapshot' }
    });
  }

  async importSnapshot(snapshot: string | File, secret: string, confirmLegacy = false): Promise<any> {
    const body: Record<string, unknown> = {
      action: 'import_snapshot',
      secret,
      confirm_legacy: confirmLegacy ? '1' : '',
    };
    if (snapshot instanceof File) {
      body.snapshot_file = snapshot;
    } else {
      body.snapshot = snapshot;
    }
    return this.adapter.request('api/integrity.php', {
      method: 'POST',
      body,
    });
  }

  async findElsewhere(basename: string, hash: string = ''): Promise<any> {
    return this.adapter.request('api/integrity.php', {
      method: 'POST',
      body: { action: 'find_elsewhere', basename, hash }
    });
  }

  async setIncludeAllMedia(on: boolean): Promise<any> {
    return this.adapter.request('api/integrity.php', {
      method: 'POST',
      body: { action: 'set_include_all_media', include_all_media: on ? '1' : '' }
    });
  }

  async skipSnapshot(): Promise<any> {
    return this.adapter.request('api/integrity.php', {
      method: 'POST',
      body: { action: 'skip_snapshot' }
    });
  }

  /** Opt-in always-on high-value file watch (mu-plugin agent). */
  async enableLiveWatch(): Promise<any> {
    return this.adapter.request('api/integrity.php', {
      method: 'POST',
      body: { action: 'enable_live_watch' }
    });
  }

  async disableLiveWatch(): Promise<any> {
    return this.adapter.request('api/integrity.php', {
      method: 'POST',
      body: { action: 'disable_live_watch' }
    });
  }

  async liveWatchTick(): Promise<any> {
    return this.adapter.request('api/integrity.php', {
      method: 'POST',
      body: { action: 'live_watch_tick' }
    });
  }
  
  /**
   * Poll progress for ongoing operation
   */
  pollProgress(progressFile: string, callback: (progress: any) => void): () => void {
    return this.adapter.pollProgress(progressFile, callback, 2000);
  }
}
