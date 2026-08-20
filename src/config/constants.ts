/**
 * Application Constants
 * Static values used throughout the application
 */

/**
 * Application metadata
 */
export const APP = {
  name: 'Clean Sweep',
  version: '2.0',
  description: 'WordPress Site Recovery & Security Tool'
} as const;

/**
 * Tab identifiers
 */
export const TABS = {
  DASHBOARD: 'dashboard',
  CORE: 'core',
  PLUGINS: 'plugins',
  SCANNER: 'scanner',
  SECURITY: 'security',
  USERS: 'users',
  CRON: 'cron',
  UPLOAD: 'upload',
  CLEANUP: 'cleanup'
} as const;

export type TabId = typeof TABS[keyof typeof TABS];

/**
 * Tab display names
 */
export const TAB_NAMES: Record<TabId, string> = {
  [TABS.DASHBOARD]: 'Dashboard',
  [TABS.CORE]: 'Core Files',
  [TABS.PLUGINS]: 'Extensions',
  [TABS.SCANNER]: 'Scanner',
  [TABS.SECURITY]: 'Security',
  [TABS.USERS]: 'Users',
  [TABS.CRON]: 'Cron',
  [TABS.UPLOAD]: 'Upload',
  [TABS.CLEANUP]: 'Remove Clean Sweep'
} as const;

/**
 * Workflow states for tabs
 */
export const WORKFLOW_STATES = {
  INITIAL: 'initial',
  LOADING: 'loading',
  ANALYZING: 'analyzing',
  ANALYZED: 'analyzed',
  REINSTALLING: 'reinstalling',
  COMPLETE: 'complete',
  ERROR: 'error'
} as const;

export type WorkflowState = typeof WORKFLOW_STATES[keyof typeof WORKFLOW_STATES];

/**
 * Default timeouts
 */
export const TIMEOUTS = {
  default: 30000,    // 30 seconds
  longRunning: 300000, // 5 minutes
  progress: 3000      // 3 seconds polling
} as const;

/**
 * Local storage keys
 */
export const STORAGE_KEYS = {
  theme: 'cleansweep_theme',
  debug: 'debug',
  lastTab: 'cleansweep_last_tab'
} as const;

/**
 * File type filters
 */
export const FILE_FILTERS = {
  zipOnly: {
    name: 'ZIP Archives',
    extensions: ['zip']
  },
  allFiles: {
    name: 'All Files',
    extensions: ['*']
  }
} as const;

/**
 * Risk levels for threats
 */
export const RISK_LEVELS = {
  CRITICAL: 'critical',
  HIGH: 'high',
  MEDIUM: 'medium',
  LOW: 'low',
  INFO: 'info'
} as const;

export type RiskLevel = typeof RISK_LEVELS[keyof typeof RISK_LEVELS];

/**
 * Scan types
 */
export const SCAN_TYPES = {
  ALL: 'all',
  CORE: 'core',
  PLUGINS: 'plugins',
  THEMES: 'themes',
  UPLOADS: 'uploads'
} as const;

export type ScanType = typeof SCAN_TYPES[keyof typeof SCAN_TYPES];
