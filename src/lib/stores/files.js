/**
 * Files Store
 * State and methods for file explorer functionality
 * 
 * Uses the modular adapter architecture for API calls
 */

import { writable, derived } from 'svelte/store';
import { adapters } from '../adapter-registry.ts';
import { app } from './app.js';
import { errors } from '../errors.js';
import { debug } from '../debug.js';

/**
 * File tree node type
 * @typedef {Object} FileNode
 * @property {string} name
 * @property {string} path
 * @property {'file'|'folder'} type
 * @property {number} [size]
 * @property {string} [modified]
 * @property {boolean} [infected]
 * @property {FileNode[]} [children]
 * @property {boolean} [loading]
 */

/**
 * File content for diff view
 * @typedef {Object} FileContent
 * @property {string} path
 * @property {string} content
 * @property {string} [original]
 * @property {boolean} modified
 * @property {number} lineCount
 */

/**
 * Official WP root PHP basenames (SitePaths::official_root_php_basenames) plus
 * user-owned / override files that also live at the install root.
 * Keep in sync with master/features/security/scan/SitePaths.php.
 */
const ROOT_BASENAMES = new Set([
  // Official packaged root PHP
  'index.php',
  'wp-activate.php',
  'wp-blog-header.php',
  'wp-comments-post.php',
  'wp-config-sample.php',
  'wp-cron.php',
  'wp-links-opml.php',
  'wp-load.php',
  'wp-login.php',
  'wp-mail.php',
  'wp-settings.php',
  'wp-signup.php',
  'wp-trackback.php',
  'xmlrpc.php',
  // User-owned / host overrides at site root
  'wp-config.php',
  '.htaccess',
  '.user.ini',
  'php.ini',
  // Common packaged root docs (checksummed on some installs)
  'license.txt',
  'readme.html',
]);

/**
 * True when path looks like a filesystem absolute path (Unix or Windows).
 * @param {string} p
 * @returns {boolean}
 */
function isAbsoluteFsPath(p) {
  if (!p) return false;
  return p.startsWith('/') || /^[A-Za-z]:\//.test(p);
}

/**
 * Strip trailing ":line" from open_in_editor values (e.g. /path/file.php:42).
 * Does not touch Windows drive prefixes like C:.
 * @param {string} p
 * @returns {string}
 */
function stripEditorLineSuffix(p) {
  if (!p) return '';
  const s = String(p);
  const m = s.match(/^(.*):(\d+)$/);
  if (!m) return s;
  const left = m[1];
  // Only strip when left side looks like a path (has sep or file extension)
  if (/[/\\]/.test(left) || /\.[A-Za-z0-9]+$/.test(left)) {
    return left;
  }
  return s;
}

/**
 * True when path is already usable by the files API under the WP site root.
 * Package-relative paths (assets/boo.php, vendor_prefixed/...) are NOT usable —
 * they must be derived from the absolute file path instead.
 * @param {string} p
 * @returns {boolean}
 */
function isUsableSiteRelativePath(p) {
  if (!p) return false;
  const n = String(p).replace(/\\/g, '/').replace(/^\/+/, '');
  if (!n || n.includes('\0')) return false;
  if (/^(wp-content|wp-includes|wp-admin)(\/|$)/i.test(n)) return true;
  if (!n.includes('/') && ROOT_BASENAMES.has(n.toLowerCase())) return true;
  return false;
}

/**
 * Convert absolute scanner paths to site-relative paths expected by files API.
 * e.g. /var/www/site/wp-content/uploads/boo.php → wp-content/uploads/boo.php
 * e.g. /var/www/site/wp-config-sample.php → wp-config-sample.php
 * @param {string} path
 * @returns {string}
 */
export function toSiteRelativePath(path) {
  if (!path) return '';
  let p = stripEditorLineSuffix(String(path).replace(/\\/g, '/').trim());
  if (!p) return '';

  // Already a clean site-relative path under a known WP tree
  if (/^(wp-content|wp-includes|wp-admin)(\/|$)/i.test(p)) {
    return p.replace(/^\/+/, '');
  }

  // Already just a known root basename (threat.path for core root files)
  const bare = p.replace(/^\/+/, '');
  if (!bare.includes('/') && ROOT_BASENAMES.has(bare.toLowerCase())) {
    return bare;
  }

  // Absolute (or long) paths under wp-content / wp-includes / wp-admin
  const markers = ['/wp-content/', '/wp-includes/', '/wp-admin/'];
  const pLower = p.toLowerCase();
  for (const m of markers) {
    const i = pLower.indexOf(m);
    if (i >= 0) {
      return p.slice(i + 1); // drop leading slash; keep original case for rest
    }
  }

  // Root-level files: match by basename (markers above already handled nested copies)
  const slash = p.lastIndexOf('/');
  const base = (slash >= 0 ? p.slice(slash + 1) : p).toLowerCase();
  if (base && ROOT_BASENAMES.has(base)) {
    // Accept ".../wp-config-sample.php" or bare "wp-config-sample.php"
    if (slash < 0 || p.endsWith('/' + p.slice(slash + 1))) {
      return p.slice(slash + 1);
    }
  }

  // Already relative — strip leading slashes only
  return p.replace(/^\/+/, '');
}

/**
 * Best site-relative path for a threat.
 *
 * Priority:
 *  1. Absolute file / open_in_editor (always convert via wp-content markers)
 *  2. path when it is already site-relative (wp-content/... or root basenames)
 *  3. Any remaining conversion
 *
 * Package integrity findings store package-relative path (e.g. assets/boo.php)
 * plus absolute file — preferring bare path caused files API 404s and mis-grouped
 * "Site file" cards. Preferring absolute file fixes core, plugins, and extras.
 *
 * @param {{ path?: string, file?: string, open_in_editor?: string }|null|undefined} threat
 * @returns {string}
 */
export function threatToSiteRelativePath(threat) {
  if (!threat || typeof threat !== 'object') return '';

  const path = threat.path != null ? String(threat.path).trim() : '';
  const file = threat.file != null ? String(threat.file).trim() : '';
  const openRaw = threat.open_in_editor != null ? String(threat.open_in_editor).trim() : '';
  const open = stripEditorLineSuffix(openRaw);

  /** @type {string[]} */
  const ordered = [];
  const pushUnique = (c) => {
    if (!c) return;
    const n = stripEditorLineSuffix(c);
    if (n && !ordered.includes(n)) ordered.push(n);
  };

  // 1) Absolute filesystem paths first (reliable site conversion)
  for (const c of [file, open, path]) {
    if (c && isAbsoluteFsPath(c.replace(/\\/g, '/'))) pushUnique(c);
  }
  // 2) Already-usable site-relative path (core root + wp-content/...)
  if (path && !isAbsoluteFsPath(path.replace(/\\/g, '/')) && isUsableSiteRelativePath(path)) {
    pushUnique(path);
  }
  // 3) Remaining fields
  pushUnique(file);
  pushUnique(path);
  pushUnique(open);

  let fallback = '';
  for (const candidate of ordered) {
    const rel = toSiteRelativePath(candidate);
    if (!rel) continue;
    if (isUsableSiteRelativePath(rel)) return packageDirToEditorFile(rel);
    if (!fallback) fallback = rel;
  }
  return packageDirToEditorFile(fallback);
}

/**
 * Package-level findings often store the plugin/theme folder, not a file.
 * The editor and files API only load files — map those folders to the
 * same entry files package_finding uses (style.css / {slug}.php).
 *
 * @param {string} rel
 * @returns {string}
 */
export function packageDirToEditorFile(rel) {
  if (!rel) return '';
  const p = String(rel).replace(/\\/g, '/').replace(/\/+$/, '');
  if (!p) return '';
  const segs = p.split('/').filter(Boolean);
  const base = segs[segs.length - 1] || '';
  if (!base) return p;
  if (base.startsWith('.') || /\.[A-Za-z0-9]+$/.test(base)) {
    return p;
  }
  if (segs.length === 3 && segs[0].toLowerCase() === 'wp-content' && segs[1].toLowerCase() === 'themes') {
    return p + '/style.css';
  }
  if (segs.length === 3 && segs[0].toLowerCase() === 'wp-content' && segs[1].toLowerCase() === 'plugins') {
    return p + '/' + base + '.php';
  }
  return p;
}

function createFilesStore() {
  const { subscribe, set, update } = writable({
    // File tree
    tree: [],
    rootPath: '',
    
    // UI state
    expandedFolders: {},
    selectedFile: null,
    
    // File content for editor
    currentContent: null,
    
    // Loading states
    loading: false,
    loadingContent: false,
    error: null
  });
  
  return {
    subscribe,
    
    /**
     * Load root directory (WP root)
     */
    async loadRoot() {
      update(s => ({
        ...s,
        loading: true,
        error: null
      }));
      
      debug.log('FILES', 'Loading root directory');
      
      try {
        const response = await adapters.files.listDirectory('');
        
        if (response.success && response.data) {
          update(s => ({
            ...s,
            tree: response.data.files || [],
            rootPath: response.data.path || '',
            loading: false
          }));
        } else {
          update(s => ({
            ...s,
            loading: false,
            error: response.error || 'Failed to load directory'
          }));
          errors.add({ message: response.error || 'Failed to load directory', code: 'FILES_LOAD_ERROR' });
        }
      } catch (e) {
        debug.error('FILES', 'Load failed', e.message);
        update(s => ({
          ...s,
          loading: false,
          error: e.message
        }));
        errors.add({ message: e.message, code: 'FILES_LOAD_ERROR' });
      }
    },
    
    /**
     * Load contents of a specific directory
     */
    async loadDirectory(path) {
      // Mark folder as loading and expanded
      update(s => ({
        ...s,
        expandedFolders: { ...s.expandedFolders, [path]: true }
      }));
      
      try {
        const response = await adapters.files.listDirectory(path);
        
        if (response.success && response.data) {
          // Update the tree with loaded children
          update(s => {
            // Helper to update tree recursively
            const updateNode = (nodes) => {
              return nodes.map(node => {
                if (node.path === path) {
                  return { ...node, children: response.data.files, loading: false };
                }
                if (node.children) {
                  return { ...node, children: updateNode(node.children) };
                }
                return node;
              });
            };
            
            const newTree = updateNode(s.tree);
            return { ...s, tree: newTree };
          });
        } else {
          errors.add({ message: response.error || 'Failed to load directory', code: 'FILES_LOAD_ERROR' });
        }
      } catch (e) {
        debug.error('FILES', 'Load directory failed', e.message);
        errors.add({ message: e.message, code: 'FILES_LOAD_ERROR' });
      }
    },
    
    /**
     * Toggle folder expansion
     */
    toggleFolder(path) {
      update(s => {
        const isExpanded = s.expandedFolders[path];
        return {
          ...s,
          expandedFolders: { ...s.expandedFolders, [path]: !isExpanded }
        };
      });
    },
    
    /**
     * Select a file to view in editor
     */
    async selectFile(node) {
      if (node.type === 'folder') {
        // Load directory contents if not already loaded
        if (!node.children || node.children.length === 0) {
          await this.loadDirectory(node.path);
        } else {
          // Just toggle expansion
          this.toggleFolder(node.path);
        }
        return;
      }
      
      update(s => ({
        ...s,
        selectedFile: node,
        loadingContent: true,
        error: null,
        // Drop previous content so the editor does not show the wrong file while loading
        currentContent: null,
      }));
      
      // Also update app store to trigger editor view
      app.setSelectedFile(node);
      
      debug.log('FILES', 'Loading file content', { path: node.path });
      
      try {
        const response = await adapters.files.getFileContent(node.path);
        
        if (response.success && response.data) {
          update(s => ({
            ...s,
            currentContent: response.data,
            loadingContent: false,
            error: null,
          }));
        } else {
          update(s => ({
            ...s,
            currentContent: null,
            loadingContent: false,
            error: response.error || 'Failed to load file'
          }));
          errors.add({ message: response.error || 'Failed to load file', code: 'FILES_CONTENT_ERROR' });
        }
      } catch (e) {
        debug.error('FILES', 'Load content failed', e.message);
        update(s => ({
          ...s,
          loadingContent: false,
          error: e.message
        }));
        errors.add({ message: e.message, code: 'FILES_CONTENT_ERROR' });
      }
    },

    /**
     * Load a file by path (for malware scan results)
     * This is a convenience method that creates a file node and loads its content
     * @param path - File path to load
     * @param lineNumber - Optional line number to scroll to
     */
    async loadFile(path, lineNumber = null) {
      const relativePath = packageDirToEditorFile(toSiteRelativePath(path));
      if (!relativePath) {
        errors.add({ message: 'Missing file path', code: 'FILES_CONTENT_ERROR' });
        return;
      }

      // Create a minimal file node (always site-relative for the API)
      const fileNode = {
        name: relativePath.split('/').pop(),
        path: relativePath,
        type: 'file'
      };

      // Set the scroll-to line in app store for CenterContent to use
      if (lineNumber !== null) {
        app.setScrollToLine(lineNumber);
      }

      // Load current file content only (editor is single-pane; no original compare)
      await this.selectFile(fileNode);
    },

    /**
     * Clear file selection
     */
    clearSelection() {
      update(s => ({
        ...s,
        selectedFile: null,
        currentContent: null
      }));
      app.setSelectedFile(null);
    },
    
    /**
     * Load original content from WordPress.org
     */
    async loadOriginalContent(path) {
      update(s => ({
        ...s,
        loadingContent: true
      }));
      
      debug.log('FILES', 'Loading original content from WordPress.org', { path });
      
      try {
        const response = await adapters.files.getOriginalContent(path);
        
        if (response.success && response.data) {
          update(s => ({
            ...s,
            currentContent: {
              ...s.currentContent,
              original: response.data.content,
              originalVersion: response.data.version
            },
            loadingContent: false
          }));
        } else {
          update(s => ({
            ...s,
            loadingContent: false,
            error: response.error || 'Failed to load original content'
          }));
          errors.add({ message: response.error || 'Failed to load original content', code: 'FILES_ORIGINAL_ERROR' });
        }
      } catch (e) {
        debug.error('FILES', 'Load original failed', e.message);
        update(s => ({
          ...s,
          loadingContent: false,
          error: e.message
        }));
        errors.add({ message: e.message, code: 'FILES_ORIGINAL_ERROR' });
      }
    },
    
    /**
     * Save/restore file content
     */
    async saveContent(path, content) {
      update(s => ({
        ...s,
        loadingContent: true
      }));
      
      debug.log('FILES', 'Saving file content', { path });
      
      try {
        const response = await adapters.files.saveFileContent(path, content);
        
        if (response.success && response.data) {
          update(s => ({
            ...s,
            currentContent: {
              ...s.currentContent,
              modified: false
            },
            loadingContent: false
          }));
          debug.log('FILES', 'File saved successfully', { backupPath: response.data.backup_path });
          return response.data;
        } else {
          update(s => ({
            ...s,
            loadingContent: false,
            error: response.error || 'Failed to save file'
          }));
          errors.add({ message: response.error || 'Failed to save file', code: 'FILES_SAVE_ERROR' });
          return null;
        }
      } catch (e) {
        debug.error('FILES', 'Save failed', e.message);
        update(s => ({
          ...s,
          loadingContent: false,
          error: e.message
        }));
        errors.add({ message: e.message, code: 'FILES_SAVE_ERROR' });
        return null;
      }
    },
    
    /**
     * Check if folder is expanded
     */
    isExpanded(path) {
      let state;
      subscribe(s => state = s)();
      return !!state.expandedFolders[path];
    },
    
    /**
     * Reset store
     */
    reset: () => {
      set({
        tree: [],
        rootPath: '',
        expandedFolders: {},
        selectedFile: null,
        currentContent: null,
        loading: false,
        loadingContent: false,
        error: null
      });
      app.setSelectedFile(null);
    },
    
    /**
     * Update infected files from scan results
     * @param threats - Array of threats from malware scan
     */
    updateInfectedFiles(threats) {
      if (!threats || !Array.isArray(threats)) return;
      
      // Create a map of infected file paths
      const infectedPaths = new Map();
      threats.forEach(threat => {
        const rel = threatToSiteRelativePath(threat);
        if (!rel) return;
        infectedPaths.set(rel, {
          infected: true,
          riskLevel: threat.risk_level || (threat.severity === 'critical' ? 'critical' : 'warning'),
          threatType: threat.type
        });
      });
      
      // Update the tree with infected status; clear marks no longer in this set.
      update(s => {
        const updateNode = (nodes) => {
          return nodes.map(node => {
            const infection = infectedPaths.get(node.path);
            let next = node;
            if (infection) {
              next = { ...node, ...infection };
            } else if (node.infected) {
              next = { ...node, infected: false, riskLevel: undefined, threatType: undefined };
            }
            if (next.children) {
              return { ...next, children: updateNode(next.children) };
            }
            return next;
          });
        };
        
        return {
          ...s,
          tree: updateNode(s.tree || []),
          infectedFiles: infectedPaths
        };
      });
    }
  };
}

export const files = createFilesStore();

// Derived store for flat file list (for search/filter)
export const flatFiles = derived(files, $files => {
  function flatten(nodes) {
    return nodes.reduce((acc, node) => {
      acc.push(node);
      if (node.children) {
        acc.push(...flatten(node.children));
      }
      return acc;
    }, []);
  }
  return flatten($files.tree);
});

// Derived store for infected files count
export const infectedCount = derived(files, $files => {
  function countInfected(nodes) {
    return nodes.reduce((acc, node) => {
      let count = node.infected ? 1 : 0;
      if (node.children) {
        count += countInfected(node.children);
      }
      return count;
    }, 0);
  }
  return countInfected($files.tree);
});
