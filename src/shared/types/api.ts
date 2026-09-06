/**
 * API Types - Shared TypeScript interfaces for API contracts
 * These types define the contract between frontend and backend
 */

// ============================================
// Base API Response Types
// ============================================

/**
 * Generic API response wrapper
 */
export interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  error?: string;
  code?: string;
  details?: Record<string, unknown>;
  timestamp: number;
}

/**
 * Error response from API
 */
export interface ApiError {
  success: false;
  error: string;
  code: string;
  details?: Record<string, unknown>;
}

// ============================================
// Progress Types
// ============================================

/**
 * Progress update from long-running operations
 */
export interface ProgressUpdate {
  status: ProgressStatus;
  progress: number;
  message: string;
  current?: number;
  total?: number;
  details?: string;
  phase?: string;
  file?: string;
}

/**
 * Progress status values
 */
export type ProgressStatus = 'idle' | 'running' | 'complete' | 'error' | 'cancelled';

// ============================================
// Plugin Types
// ============================================

/**
 * WordPress.org plugin
 */
export interface Plugin {
  slug: string;
  name: string;
  version: string;
  description?: string;
  author?: string;
  active?: boolean;
  needs_update?: boolean;
  wdp_id?: number | string;  // WPMU DEV project ID (for WPMU DEV plugins)
  /** Main plugin basename from get_plugins(), e.g. wp-smush-pro/wp-smush.php */
  plugin_file?: string;
}

/**
 * WPMU DEV plugin (from external source)
 */
export interface WpmuDevPlugin extends Plugin {
  source?: 'wpmu_dev';
  download_url?: string;
}

/**
 * Custom/Non-repo plugin
 */
export interface CustomPlugin extends Plugin {
  source: 'custom';
  path: string;
}

/**
 * Suspicious file/folder found during plugin or theme analysis
 */
export interface SuspiciousFile {
  /** Unique selection key (basename or relative path, e.g. mu-plugins/x.php) */
  name: string;
  path: string;
  is_directory?: boolean;
  size_bytes?: number;
  size_mb?: number;
  file_count?: number;
  severity?: 'low' | 'medium' | 'high' | 'critical';
  reasons?: string[];
  /** @deprecated Prefer reasons[] */
  reason?: string;
  category?: 'orphan' | 'nested' | 'mu_plugin';
  parent_slug?: string | null;
  last_modified?: number | null;
  detected_at?: string;
}

/**
 * Plugin analysis summary
 */
export interface PluginSummary {
  total: number;
  wp_org_count: number;
  wpmu_dev_count: number;
  non_repo_count: number;
  suspicious_count?: number;
  needs_reinstall?: boolean;
}

/**
 * Plugin analysis response from API
 */
export interface PluginAnalysisResponse {
  results: {
    wp_org_plugins: Plugin[];
    wpmu_dev_plugins: WpmuDevPlugin[];
    non_repo_plugins: CustomPlugin[];
    suspicious_files: SuspiciousFile[];
    summary: PluginSummary;
  };
  progress_file?: string;
}

/**
 * Plugin reinstallation request
 */
export interface PluginReinstallRequest {
  action: 'reinstall_plugins';
  plugins: string[];
  create_backup: boolean;
}

/**
 * Plugin reinstall response
 */
export interface PluginReinstallResponse {
  reinstalled: string[];
  failed: string[];
  backup_path?: string;
}

// ============================================
// Theme Types
// ============================================

/**
 * WordPress.org theme
 */
export interface Theme {
  slug: string;
  name: string;
  version: string;
  description?: string;
  author?: string;
  active?: boolean;
  screenshot?: string;
}

/**
 * Custom/Non-repo theme
 */
export interface CustomTheme extends Theme {
  source: 'custom';
  path: string;
}

/**
 * Theme analysis summary
 */
export interface ThemeSummary {
  total_themes: number;
  wp_org_count: number;
  custom_count: number;
  needs_update?: boolean;
}

/**
 * Theme analysis response from API
 */
export interface ThemeAnalysisResponse {
  results: {
    wp_org_themes: Theme[];
    custom_themes: CustomTheme[];
    summary: ThemeSummary;
  };
  progress_file?: string;
}

// ============================================
// Core Types
// ============================================

/**
 * WordPress core information
 */
export interface CoreInfo {
  version: string;
  latest_version: string;
  locale: string;
  auto_update: boolean;
}

/**
 * Core reinstallation request
 */
export interface CoreReinstallRequest {
  action: 'reinstall_core';
  version: string;
}

/**
 * Core reinstall response
 */
export interface CoreReinstallResponse {
  success: boolean;
  version: string;
  message?: string;
}

/**
 * Version options response from API
 */
export interface VersionOptionsResponse {
  versions: string[];
  current_version: string;
  latest_version: string;
}

// ============================================
// Malware Scanning Types
// ============================================

/**
 * Scan type options
 */
export type ScanType = 'all' | 'core' | 'plugins' | 'themes' | 'uploads';

/**
 * Threat found during scan
 */
export interface Threat {
  id: string;
  type: 'malware' | 'suspicious' | 'modified' | 'backdoor';
  file: string;
  line_number?: number;
  byte_offset?: number;
  severity: ThreatSeverity;
  description: string;
  risk_level?: 'critical' | 'warning' | 'info';
  risk_score?: number;           // Numeric risk score 0-100
  category?: string;            // Signature category
  pattern?: string;              // Signature pattern that matched
  matched_content?: string;     // Actual matched content
  content_preview?: string;     // Content preview with context
  open_in_editor?: string;      // "file:line" or "DB:table:row:column"
  source?: 'file' | 'database';
  table?: string;                // For database threats
  row_id?: number;               // For database threats
  column?: string;               // For database threats
  detected_at?: string;
  details?: Record<string, unknown>;
}

/**
 * Threat severity levels
 */
export type ThreatSeverity = 'critical' | 'high' | 'medium' | 'low' | 'info';

/**
 * Malware scan results
 */
export interface ScanResults {
  threats: Threat[];
  scanned_files: number;
  scan_duration: number;
  scan_type: ScanType;
  completed_at?: string;
}

/**
 * Malware scan request
 */
export interface MalwareScanRequest {
  action: 'scan_malware';
  scan_type: ScanType;
  progress_file: string;
}

// ============================================
// Vulnerability Scanning Types
// ============================================

/**
 * Vulnerability severity levels
 */
export type VulnerabilitySeverity = 'critical' | 'high' | 'medium' | 'low' | 'info';

/**
 * CVSS Score information
 */
export interface CvssScore {
  version: string;
  vector: string;
  score: number;
  severity: string;
  exploitable: number;
  impact: number;
}

/**
 * CWE information
 */
export interface CweInfo {
  cwe: string;
  name: string;
  description: string;
}

/**
 * Vulnerability source (CVE reference)
 */
export interface VulnerabilitySource {
  id: string;
  name: string;
  link: string;
  description: string;
  date: string;
}

/**
 * Single vulnerability
 */
export interface Vulnerability {
  uuid: string;
  name: string;
  description: string;
  affected_version: string;
  target_type: 'core' | 'plugin' | 'theme';
  target_name: string;
  target_version: string;
  risk_level: VulnerabilitySeverity;
  source: VulnerabilitySource[];
  impact: Array<{
    cvss?: CvssScore;
    cwe?: CweInfo[];
  }>;
}

/**
 * Vulnerability scan results summary
 */
export interface VulnerabilitySummary {
  core_vulnerabilities: number;
  plugin_vulnerabilities: number;
  theme_vulnerabilities: number;
  total: number;
}

/**
 * Vulnerability scan results
 */
export interface VulnerabilityScanResults {
  summary: VulnerabilitySummary;
  vulnerabilities: Vulnerability[];
  by_type: {
    core: Vulnerability[];
    plugins: Vulnerability[];
    themes: Vulnerability[];
  };
  risk_counts: {
    critical: number;
    high: number;
    medium: number;
    low: number;
  };
}

/**
 * Combined scan results (malware + vulnerabilities)
 */
export interface CombinedScanResults extends ScanResults {
  vulnerabilities?: VulnerabilityScanResults;
}

// ============================================
// Upload Types
// ============================================

/**
 * Canonical dest ids. Routing keys, not site-relative paths.
 * plugins|themes go through WP upgraders; other ids use safe unzip.
 */
export type UploadDestinationId =
  | 'plugins'
  | 'themes'
  | 'uploads'
  | 'wp-content'
  | 'root'
  | 'custom';

export type UploadKind = 'plugin' | 'theme' | 'unknown';
export type UploadInspectConfidence = 'header' | 'structure' | 'none';

/**
 * File upload request
 */
export interface FileUploadRequest {
  action: 'upload_zip';
  file: File;
}

/**
 * Staged upload payload from handle_upload / upload_zip.
 * Clients must not expect temp_path / temp_dir.
 */
export interface UploadStageResult {
  upload_id: string;
  filename: string;
  filesize: number;
  sha256?: string;
  expires_at?: number;
  ready_for_inspection?: boolean;
}

export interface UploadInspectExisting {
  present: boolean;
  installed_version?: string;
  path_rel?: string;
}

export interface UploadInspectResult {
  upload_id: string;
  filename: string;
  kind: UploadKind;
  confidence: UploadInspectConfidence;
  suggested_destination?: UploadDestinationId | '';
  slug: string;
  name?: string;
  version?: string;
  entry_count: number;
  uncompressed_bytes: number;
  has_traversal_entries: boolean;
  has_symlink_entries?: boolean;
  backup_eligible: boolean;
  existing?: UploadInspectExisting;
  warnings?: string[];
}

export interface UploadExtractOpts {
  uploadId: string;
  destination: UploadDestinationId;
  customRel?: string;
  confirmOverwrite?: boolean;
  createBackup?: boolean;
  confirmRoot?: boolean;
}

/**
 * Extract / reinstall result from extract_zip
 */
export interface UploadExtractResult {
  results: {
    success: boolean;
    mode?: string;
    destination?: UploadDestinationId;
    destination_rel?: string;
    destination_name?: string;
    slug?: string | null;
    plugin_file?: string | null;
    name?: string;
    version?: string;
    overwritten?: boolean;
    backup_rel?: string;
    sealed?: boolean;
    files_extracted?: string[];
    files_extracted_count?: number;
    directories_created?: string[];
    errors?: string[];
    warnings?: string[];
    message?: string;
    summary?: Record<string, unknown>;
  };
  progress_file?: string;
}

export interface UploadStatusResult {
  exists: boolean;
  ready?: boolean;
  filename?: string;
  filesize?: number;
  expires_at?: number | null;
  message?: string;
}

export interface UploadLimitsResult {
  limit_bytes: number;
  post_max_size: string;
  upload_max_filesize: string;
  post_max_size_bytes?: number;
  upload_max_filesize_bytes?: number;
}

export interface UploadDiscardResult {
  discarded: boolean;
}

// ============================================
// Cleanup Types
// ============================================

/**
 * Cleanup request
 */
export interface CleanupRequest {
  action: 'cleanup_tool';
  confirm: 'yes';
}

/**
 * Cleanup result
 */
export interface CleanupResult {
  success: boolean;
  message: string;
  files_removed?: number;
}

// ============================================
// Action Types - for form data
// ============================================

/**
 * All possible API actions
 */
export type ApiAction =
  // Bootstrap actions
  | 'analyze_plugins'
  | 'analyze_themes'
  | 'create_plugin_backup'
  | 'create_theme_backup'
  | 'reinstall_themes'
  // Plugin actions
  | 'reinstall_plugins'
  // Core actions
  | 'reinstall_core'
  | 'get_core_info'
  // Malware actions
  | 'scan_malware'
  // Upload actions
  | 'upload_zip'
  | 'inspect_zip'
  | 'extract_zip'
  | 'discard_upload'
  // Cleanup actions
  | 'cleanup_tool';

/**
 * Request body for API calls
 */
export interface ApiRequestBody {
  action: ApiAction;
  [key: string]: unknown;
}
