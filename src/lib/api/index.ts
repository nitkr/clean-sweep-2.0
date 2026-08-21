/**
 * API Index
 * Main export for all API adapters
 */

// Base adapter
export { HttpApiAdapter, defaultAdapter, type IApiAdapter, type RequestOptions, type ProgressCallback } from './adapter.js';

// Domain adapters
export { PluginsApiAdapter } from './plugins/adapter.js';
export { ThemesApiAdapter } from './themes/adapter.js';
export { CoreApiAdapter } from './core/adapter.js';
export { MalwareApiAdapter } from './malware/adapter.js';
export { UploadApiAdapter } from './upload/adapter.js';
export { CleanupApiAdapter } from './cleanup/adapter.js';
export { FilesApiAdapter } from './files/adapter.js';
export { IntegrityApiAdapter } from './integrity/adapter.js';
export { VulnerabilitiesApiAdapter } from './vulnerabilities/adapter.js';
export { UsersApiAdapter } from './users/adapter.js';
export { CronApiAdapter } from './cron/adapter.js';
