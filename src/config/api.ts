/**
 * API Configuration
 * Centralized API endpoint definitions
 */

import type { ScanType } from '../shared/types/api.js';

/**
 * API endpoint configuration
 */
export const API_CONFIG = {
  /**
   * Base URL for API calls
   * Determined at runtime from environment or defaults to relative path
   */
  baseUrl: '',

  /**
   * Endpoint definitions mapped to domain actions
   */
  endpoints: {
    // Bootstrap - general analysis
    bootstrap: 'api/bootstrap.php',

    // Plugins
    plugins: {
      base: 'api/plugins.php',
      analyze: 'api/plugins.php',
      reinstall: 'api/plugins.php',
      backup: 'api/plugins.php'
    },

    // Themes
    themes: {
      base: 'api/themes.php',
      analyze: 'api/themes.php',
      reinstall: 'api/themes.php',
      backup: 'api/themes.php'
    },

    // Core
    core: {
      base: 'api/core.php',
      info: 'api/core.php',
      reinstall: 'api/core.php'
    },

    // Malware/Scanner
    malware: {
      base: 'api/malware.php',
      scan: 'api/malware.php',
      progress: 'api/malware.php'
    },

    // Vulnerabilities (separate from malware)
    vulnerabilities: {
      base: 'api/vulnerabilities.php',
      scan: 'api/vulnerabilities.php'
    },

    // Upload
    upload: {
      base: 'api/upload.php',
      file: 'api/upload.php',
      extract: 'api/upload.php'
    },

    // Cleanup
    cleanup: {
      base: 'api/cleanup.php',
      run: 'api/cleanup.php'
    },

    // Files / Explorer
    files: {
      base: 'api/files.php',
      list: 'api/files.php',
      content: 'api/files.php'
    },

    // Users / access audit
    users: {
      base: 'api/users.php',
      audit: 'api/users.php'
    },

    // Cron / scheduled tasks audit
    cron: {
      base: 'api/cron.php',
      audit: 'api/cron.php'
    },

    // Progress
    progress: (filename: string) => `/logs/${filename}`
  },

  /**
   * Polling configuration for long-running operations
   */
  polling: {
    interval: 3000,    // ms between polls
    timeout: 300000,   // 5 minutes max
    maxRetries: 3
  }
} as const;

/**
 * API Action mappings - what action param to send for each operation
 */
export const API_ACTIONS = {
  // Bootstrap actions
  analyzePlugins: 'analyze_plugins',
  analyzeThemes: 'analyze_themes',
  createPluginBackup: 'create_plugin_backup',
  createThemeBackup: 'create_theme_backup',
  reinstallThemes: 'reinstall_themes',

  // Plugin actions
  reinstallPlugins: 'reinstall_plugins',

  // Core actions
  reinstallCore: 'reinstall_core',

  // Malware actions
  scanMalware: 'scan_malware',

  // Upload actions
  uploadZip: 'upload_zip',

  // Cleanup actions
  cleanupTool: 'cleanup_tool',

  // Files actions
  listDirectory: 'list_directory',
  getFileContent: 'get_file_content',
  getOriginalContent: 'get_original_content',
  restoreFile: 'restore_file'
} as const;

/**
 * Build request body for API call
 */
export function buildApiBody(
  action: string,
  additionalData: Record<string, unknown> = {}
): FormData {
  const formData = new FormData();
  formData.append('action', action);

  for (const [key, value] of Object.entries(additionalData)) {
    if (value !== undefined && value !== null) {
      if (typeof value === 'boolean') {
        formData.append(key, value ? '1' : '0');
      } else if (typeof value === 'object') {
        formData.append(key, JSON.stringify(value));
      } else {
        formData.append(key, String(value));
      }
    }
  }

  return formData;
}

/**
 * Get endpoint for a specific action
 */
export function getEndpoint(
  domain: keyof typeof API_CONFIG.endpoints,
  action?: string
): string {
  const domainConfig = API_CONFIG.endpoints[domain];

  if (typeof domainConfig === 'string') {
    return domainConfig;
  }

  if (typeof domainConfig === 'object' && domainConfig !== null) {
    // @ts-ignore - dynamic access
    return action ? domainConfig[action] || domainConfig.base : domainConfig.base;
  }

  // Fallback to bootstrap for unknown domains
  return API_CONFIG.endpoints.bootstrap;
}

export type ApiDomain = keyof typeof API_CONFIG.endpoints;
