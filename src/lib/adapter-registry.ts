/**
 * Adapter Registry
 * Central registry for all domain API adapters
 * Provides a single point of access to all adapters
 */

import {
  HttpApiAdapter,
  type IApiAdapter,
  PluginsApiAdapter,
  ThemesApiAdapter,
  CoreApiAdapter,
  MalwareApiAdapter,
  UploadApiAdapter,
  CleanupApiAdapter,
  FilesApiAdapter,
  IntegrityApiAdapter,
  VulnerabilitiesApiAdapter,
  UsersApiAdapter,
  CronApiAdapter
} from './api/index.js';

/**
 * Adapter Registry
 * Singleton that provides access to all domain adapters
 */
class AdapterRegistry {
  private static instance: AdapterRegistry;

  private _adapter: IApiAdapter;
  private _plugins: PluginsApiAdapter | null = null;
  private _themes: ThemesApiAdapter | null = null;
  private _core: CoreApiAdapter | null = null;
  private _malware: MalwareApiAdapter | null = null;
  private _upload: UploadApiAdapter | null = null;
  private _cleanup: CleanupApiAdapter | null = null;
  private _files: FilesApiAdapter | null = null;
  private _integrity: IntegrityApiAdapter | null = null;
  private _vulnerabilities: VulnerabilitiesApiAdapter | null = null;
  private _users: UsersApiAdapter | null = null;
  private _cron: CronApiAdapter | null = null;

  private constructor() {
    // Use default HTTP adapter
    this._adapter = new HttpApiAdapter();
  }
  static getInstance(): AdapterRegistry {
    if (!AdapterRegistry.instance) {
      AdapterRegistry.instance = new AdapterRegistry();
    }
    return AdapterRegistry.instance;
  }

  /**
   * Set custom adapter implementation
   * Use this to swap backends (e.g., for testing or different backends)
   */
  setAdapter(adapter: IApiAdapter): void {
    this._adapter = adapter;
    // Clear cached domain adapters to use new adapter
    this._plugins = null;
    this._themes = null;
    this._core = null;
    this._malware = null;
    this._upload = null;
    this._cleanup = null;
    this._files = null;
    this._integrity = null;
    this._vulnerabilities = null;
    this._users = null;
    this._cron = null;
  }

  /**
   * Get Files API adapter
   */
  get files(): FilesApiAdapter {
    if (!this._files) {
      this._files = new FilesApiAdapter(this._adapter);
    }
    return this._files;
  }

  /**
   * Get the base adapter
   */
  get adapter(): IApiAdapter {
    return this._adapter;
  }

  /**
   * Get Plugins API adapter
   */
  get plugins(): PluginsApiAdapter {
    if (!this._plugins) {
      this._plugins = new PluginsApiAdapter(this._adapter);
    }
    return this._plugins;
  }

  /**
   * Get Themes API adapter
   */
  get themes(): ThemesApiAdapter {
    if (!this._themes) {
      this._themes = new ThemesApiAdapter(this._adapter);
    }
    return this._themes;
  }

  /**
   * Get Core API adapter
   */
  get core(): CoreApiAdapter {
    if (!this._core) {
      this._core = new CoreApiAdapter(this._adapter);
    }
    return this._core;
  }

  /**
   * Get Malware API adapter
   */
  get malware(): MalwareApiAdapter {
    if (!this._malware) {
      this._malware = new MalwareApiAdapter(this._adapter);
    }
    return this._malware;
  }

  /**
   * Get Upload API adapter
   */
  get upload(): UploadApiAdapter {
    if (!this._upload) {
      this._upload = new UploadApiAdapter(this._adapter);
    }
    return this._upload;
  }

  /**
   * Get Cleanup API adapter
   */
  get cleanup(): CleanupApiAdapter {
    if (!this._cleanup) {
      this._cleanup = new CleanupApiAdapter(this._adapter);
    }
    return this._cleanup;
  }

  /**
   * Get Integrity API adapter
   */
  get integrity(): IntegrityApiAdapter {
    if (!this._integrity) {
      this._integrity = new IntegrityApiAdapter(this._adapter);
    }
    return this._integrity;
  }

  /**
   * Get Vulnerabilities API adapter (separate from malware)
   */
  get vulnerabilities(): VulnerabilitiesApiAdapter {
    if (!this._vulnerabilities) {
      this._vulnerabilities = new VulnerabilitiesApiAdapter(this._adapter);
    }
    return this._vulnerabilities;
  }

  get users(): UsersApiAdapter {
    if (!this._users) {
      this._users = new UsersApiAdapter(this._adapter);
    }
    return this._users;
  }

  get cron(): CronApiAdapter {
    if (!this._cron) {
      this._cron = new CronApiAdapter(this._adapter);
    }
    return this._cron;
  }
}

// Export singleton instance
export const adapters = AdapterRegistry.getInstance();

// Export registry class for testing or custom configurations
export { AdapterRegistry };
