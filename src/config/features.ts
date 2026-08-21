/**
 * Feature Flags
 * Toggle features on/off for the application
 */

/**
 * Application feature flags
 */
export const FEATURES = {
  /**
   * Enable debug mode (logs to console)
   */
  debug: false, // Controlled by debug store at runtime

  /**
   * Enable theme toggle (dark/light mode)
   */
  themeToggle: true,

  /**
   * Enable file tree navigation
   */
  fileTree: true,

  /**
   * Enable right panel for details
   */
  rightPanel: true,

  /**
   * Enable progress tracking
   */
  progressTracking: true,

  /**
   * Enable error toasts
   */
  errorToasts: true,

  /**
   * Enable backup before reinstall
   */
  backupBeforeReinstall: true,

  /**
   * Enable malware scanning
   */
  malwareScanning: true,

  /**
   * Enable file upload
   */
  fileUpload: true,

  /**
   * inspect_zip + dest suggestion / kind banner
   */
  uploadInspect: true,

  /**
   * First-class extract-to-path panel (custom_rel + root chip)
   */
  uploadCustomPath: true,

  /**
   * Backup existing plugin/theme slug before upgrader overwrite
   */
  uploadPackageBackup: true,

  /**
   * Enable cleanup functionality
   */
  cleanup: true,

  /**
   * Enable core reinstallation
   */
  coreReinstall: true,

  /**
   * Enable plugin analysis
   */
  pluginAnalysis: true,

  /**
   * Enable theme analysis
   */
  themeAnalysis: true
} as const;

/**
 * Feature flag type
 */
export type FeatureFlag = keyof typeof FEATURES;

/**
 * Check if a feature is enabled
 */
export function isFeatureEnabled(feature: FeatureFlag): boolean {
  return FEATURES[feature];
}

/**
 * Get all enabled features
 */
export function getEnabledFeatures(): string[] {
  return Object.entries(FEATURES)
    .filter(([, enabled]) => enabled)
    .map(([name]) => name);
}
