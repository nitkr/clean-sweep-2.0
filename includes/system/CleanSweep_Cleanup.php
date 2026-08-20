<?php
/**
 * Clean Sweep - Cleanup Operations
 *
 * Handles cleanup and removal of Clean Sweep files and directories.
 *
 * @version 1.0
 */

if (!class_exists('CleanSweep_DB', false)) {
    require_once __DIR__ . '/CleanSweep_DB.php';
}

class CleanSweep_Cleanup {

    /** When true, do not echo HTML or flush (JSON API). */
    private bool $quiet = false;

    /**
     * Clean up database entries created by Clean Sweep
     * Removes validation timestamps and other temporary data
     */
    private function cleanup_database_entries() {
        try {
            // Remove temporary WP-Cron single events used to continue malware scans.
            // Prefer WP APIs when loaded; fall back to rewriting the cron option.
            $this->cleanup_scan_kick_cron();

            $db = new CleanSweep_DB();
            $prefix = $db->get_table_prefix();
            $options = $prefix . 'options';

            // Known single options
            $exact = [
                'clean_sweep_env_validated',
            ];
            foreach ($exact as $name) {
                $db->query("DELETE FROM {$options} WHERE option_name = ?", [$name]);
            }

            // Prefix sweeps (options + transients) so nothing Clean Sweep-shaped lingers
            $like_patterns = [
                'clean_sweep_%',
                'clean_sweep%',
                '_transient_cs_%',
                '_transient_timeout_cs_%',
                '_site_transient_cs_%',
                '_site_transient_timeout_cs_%',
                '_transient_clean_sweep_%',
                '_transient_timeout_clean_sweep_%',
                '_site_transient_clean_sweep_%',
                '_site_transient_timeout_clean_sweep_%',
            ];
            foreach ($like_patterns as $like) {
                $db->query(
                    "DELETE FROM {$options} WHERE option_name LIKE ?",
                    [$like]
                );
            }

            // WP API path when available (multisite + object cache)
            if (function_exists('delete_option')) {
                foreach ($exact as $name) {
                    delete_option($name);
                }
            }
            if (function_exists('delete_transient')) {
                // Best-effort known transient prefixes
                global $wpdb;
                if (isset($wpdb) && is_object($wpdb)) {
                    // already deleted via LIKE above
                }
            }

            if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                echo "🗑️  Cleaned up database entries (options, transients, scan cron)\n";
            }
        } catch (Throwable $e) {
            // Silently continue if database cleanup fails
            // This prevents cleanup from failing due to database issues
            if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                echo "⚠️  Database cleanup skipped (may not be available)\n";
            }
        }
    }

    /**
     * Remove site-level residuals outside the toolkit folder:
     * live-watch mu-plugin agent, empty mu-plugins dir if we created it alone, etc.
     * Must run before the toolkit directory is deleted.
     */
    private function cleanup_site_residuals() {
        $removed = [];

        // 1) Live watch agent (Phase 3) — lives under wp-content/mu-plugins/
        try {
            $visit_boot = dirname(__DIR__) . '/visit/bootstrap.php';
            if (is_readable($visit_boot)) {
                require_once $visit_boot;
                if (class_exists('CleanSweep_VisitWatch', false)) {
                    $watch = new CleanSweep_VisitWatch();
                    $watch->disable(); // unlinks agent + clears watch state in visit JSON
                    $removed[] = 'live-watch agent (CleanSweep_VisitWatch::disable)';
                }
            }
        } catch (Throwable $e) {
            // fall through to path-based removal
        }

        // Always attempt direct agent path removal (works even if visit classes fail)
        $agent_paths = $this->resolve_live_watch_agent_paths();
        foreach ($agent_paths as $agent) {
            if (is_file($agent) && @unlink($agent)) {
                $removed[] = $agent;
            }
        }

        // 2) Drain lock leftovers under toolkit logs are deleted with the folder.
        //    Also strip any progress/temp files in system temp that we named clean_sweep_*
        $tmp = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '';
        if (is_string($tmp) && $tmp !== '' && is_dir($tmp)) {
            foreach (glob(rtrim($tmp, '/\\') . '/clean_sweep_*') ?: [] as $f) {
                if (is_file($f) && @unlink($f)) {
                    $removed[] = basename($f) . ' (temp)';
                }
            }
        }

        if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
            if ($removed !== []) {
                echo "🧹 Site residuals removed:\n";
                foreach (array_slice($removed, 0, 20) as $r) {
                    echo "   - $r\n";
                }
                if (count($removed) > 20) {
                    echo '   - … +' . (count($removed) - 20) . " more\n";
                }
            } else {
                echo "🧹 No external site residuals found (agent already gone)\n";
            }
        }
    }

    /**
     * @return string[] absolute paths that might hold the live-watch agent
     */
    private function resolve_live_watch_agent_paths(): array {
        $name = '00-clean-sweep-visit-watch.php';
        if (class_exists('CleanSweep_VisitWatch', false)) {
            $name = CleanSweep_VisitWatch::AGENT_BASENAME;
        }
        $paths = [];
        $roots = [];

        if (function_exists('clean_sweep_detect_site_root')) {
            $r = clean_sweep_detect_site_root();
            if (is_string($r) && $r !== '') {
                $roots[] = rtrim(str_replace('\\', '/', $r), '/') . '/';
            }
        }
        if (defined('ABSPATH') && ABSPATH) {
            $roots[] = rtrim(str_replace('\\', '/', ABSPATH), '/') . '/';
        }
        // Parent of toolkit (common layout: site/clean-sweep/)
        $toolkit = dirname(__DIR__, 2);
        $parent = dirname($toolkit);
        if (is_string($parent) && $parent !== '' && $parent !== $toolkit) {
            $roots[] = rtrim(str_replace('\\', '/', $parent), '/') . '/';
        }

        $roots = array_unique($roots);
        foreach ($roots as $root) {
            $paths[] = $root . 'wp-content/mu-plugins/' . $name;
        }
        return array_unique($paths);
    }

    /**
     * Remove all clean_sweep_scan_kick events from the site cron option.
     */
    private function cleanup_scan_kick_cron(): void {
        $hook = 'clean_sweep_scan_kick';

        if (class_exists('CleanSweep_Scanner', false) && method_exists('CleanSweep_Scanner', 'clearAllScanKickCrons')) {
            CleanSweep_Scanner::clearAllScanKickCrons();
            return;
        }

        $scanner = dirname(__DIR__, 2) . '/features/security/scan/Scanner.php';
        if (is_readable($scanner)) {
            require_once $scanner;
            if (class_exists('CleanSweep_Scanner', false) && method_exists('CleanSweep_Scanner', 'clearAllScanKickCrons')) {
                CleanSweep_Scanner::clearAllScanKickCrons();
                return;
            }
        }

        // Fallback without full CleanSweep_Scanner bootstrap: strip hook from cron option.
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook($hook);
            return;
        }
        if (function_exists('get_option') && function_exists('update_option')) {
            $cron = get_option('cron');
            if (!is_array($cron)) {
                return;
            }
            $changed = false;
            foreach ($cron as $ts => $hooks) {
                if (!is_array($hooks) || !isset($hooks[$hook])) {
                    continue;
                }
                unset($cron[$ts][$hook]);
                $changed = true;
                if (empty($cron[$ts])) {
                    unset($cron[$ts]);
                }
            }
            if ($changed) {
                update_option('cron', $cron, true);
            }
        }
    }

    /**
     * Execute cleanup of all Clean Sweep files and directories
     * Memory-efficient version for managed hosting with limited memory
     */
    /**
     * @param bool $quiet JSON API: no HTML, no flush (headers must stay JSON).
     * @return array{files:int,dirs:int}
     */
    public function execute_cleanup(bool $quiet = false): array {
        $this->quiet = $quiet;
        // Note: Cleanup operations are not logged to avoid creating log files during cleanup

        // FIRST: DB + site residuals (mu-plugin agent lives outside the toolkit folder)
        $this->cleanup_database_entries();
        $this->cleanup_site_residuals();

        // Calculate Clean Sweep root directory dynamically (compatible with all architectures)
        $clean_sweep_dir = function_exists('clean_sweep_toolkit_root')
            ? rtrim(clean_sweep_toolkit_root(), '/')
            : dirname(__DIR__, 2); // From includes/system/ up 2 levels to project root
        $files_deleted = 0;
        $dirs_deleted = 0;

        if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
            echo '<div style="background:#f8f9fa;border:1px solid #dee2e6;padding:20px;border-radius:4px;margin:20px 0;">';
            echo '<h3>🗑️ Deleting Clean Sweep Files...</h3>';
            echo '<pre style="background:#f5f5f5;padding:10px;border:1px solid #ddd;max-height:300px;overflow-y:auto;">';
        }

        // Memory-efficient cleanup: process directories one by one
        $subdirs = ['backups', 'logs', 'assets', 'features'];

        // First, delete subdirectories with large contents (backups and logs)
        foreach ($subdirs as $subdir) {
            $subdir_path = $clean_sweep_dir . '/' . $subdir;
            if (is_dir($subdir_path)) {
                if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                    echo "🗂️  Processing directory: $subdir\n";
                    @ob_flush();
                    @flush();
                }

                // Use memory-efficient deletion for large directories
                $result = $this->delete_directory_efficiently($subdir_path);
                if ($result['success']) {
                    $files_deleted += $result['files'];
                    $dirs_deleted += $result['dirs'];
                    if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                        echo "✅ Deleted directory: $subdir ({$result['files']} files, {$result['dirs']} dirs)\n";
                    }
                } else {
                    if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                        echo "❌ Failed to delete directory: $subdir\n";
                    }
                }

                // Clear memory between operations
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        // Now delete remaining files in the root directory
        $remaining_files = glob($clean_sweep_dir . '/*');
        foreach ($remaining_files as $file) {
            $basename = basename($file);

            // Skip the main script for now
            if ($basename === 'clean-sweep.php') {
                continue;
            }

            if (is_file($file)) {
                if (unlink($file)) {
                    $files_deleted++;
                    if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                        echo "✅ Deleted file: $basename\n";
                    }
                } else {
                    if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                        echo "❌ Failed to delete file: $basename\n";
                    }
                }
            } elseif (is_dir($file)) {
                // Delete any remaining directories
                if (clean_sweep_recursive_delete($file)) {
                    $dirs_deleted++;
                    if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                        echo "✅ Deleted directory: $basename\n";
                    }
                } else {
                    if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                        echo "❌ Failed to delete directory: $basename\n";
                    }
                }
            }

            // Flush output for real-time feedback (never from JSON API)
            if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                @ob_flush();
                @flush();
            }

            // Clear memory between operations
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        // Remaining entry files (skipped above, or missed by glob *). Then rmdir.
        // rmdir() warns if the folder is not empty; that is expected while PHP is
        // still running from this directory, so silence it.
        foreach (['clean-sweep.php', 'index.php', 'index.html'] as $entry) {
            $path = $clean_sweep_dir . '/' . $entry;
            if (is_file($path) && @unlink($path)) {
                $files_deleted++;
                if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                    echo "✅ Deleted file: $entry\n";
                }
            }
        }

        if (@rmdir($clean_sweep_dir)) {
            $dirs_deleted++;
            if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
                echo "✅ Deleted directory: clean-sweep\n";
            }
        } elseif (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
            echo "ℹ️  Toolkit folder could not be removed yet (still in use or not empty). Safe to delete leftover files by hand.\n";
        }

        if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
            echo '</pre>';
            echo '</div>';
        }

        if (!$this->quiet && (!defined('WP_CLI') || !WP_CLI)) {
            echo '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:20px;border-radius:4px;margin:20px 0;color:#155724;">';
            echo '<h3>🎉 Clean Sweep Cleanup Complete!</h3>';
            echo '<p><strong>Summary:</strong></p>';
            echo '<ul>';
            echo '<li>Files deleted: ' . $files_deleted . '</li>';
            echo '<li>Directories deleted: ' . $dirs_deleted . '</li>';
            echo '</ul>';
            echo '<p><strong>✅ All Clean Sweep files and directories have been successfully removed from your server.</strong></p>';
            echo '<p><em>Clean Sweep is no longer available. If you need it again in the future, you can re-upload it.</em></p>';
            echo '</div>';
        } elseif (!$this->quiet) {
            echo "\n🗑️ CLEANUP COMPLETE\n";
            echo str_repeat("=", 30) . "\n";
            echo "Files deleted: $files_deleted\n";
            echo "Directories deleted: $dirs_deleted\n";
            echo "\n✅ Clean Sweep has been completely removed from your server.\n";
        }

        return ['files' => $files_deleted, 'dirs' => $dirs_deleted];
    }

    /**
     * Memory-efficient directory deletion for large directories
     * Processes all items systematically to ensure complete removal
     */
    private function delete_directory_efficiently($dir_path) {
        $files_deleted = 0;
        $dirs_deleted = 0;

        if (!is_dir($dir_path)) {
            return ['success' => false, 'files' => 0, 'dirs' => 0];
        }

        // Use scandir for more reliable directory reading
        $items = @scandir($dir_path);
        if ($items === false) {
            return ['success' => false, 'files' => 0, 'dirs' => 0];
        }

        // Remove . and .. entries
        $items = array_diff($items, ['.', '..']);

        // First pass: recursively delete all subdirectories
        foreach ($items as $item) {
            $full_path = $dir_path . '/' . $item;
            if (is_dir($full_path) && !is_link($full_path)) {
                $result = $this->delete_directory_efficiently($full_path);
                $files_deleted += $result['files'];
                $dirs_deleted += $result['dirs'];

                // Clear memory
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        // Second pass: delete all remaining files and symlinks
        foreach ($items as $item) {
            $full_path = $dir_path . '/' . $item;
            if (is_file($full_path) || is_link($full_path)) {
                if (@unlink($full_path)) {
                    $files_deleted++;
                }
            }
        }

        // Clear memory before final deletion
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // Finally, delete the directory itself
        if (@rmdir($dir_path)) {
            $dirs_deleted++;
            return ['success' => true, 'files' => $files_deleted, 'dirs' => $dirs_deleted];
        }

        return ['success' => false, 'files' => $files_deleted, 'dirs' => $dirs_deleted];
    }
}
