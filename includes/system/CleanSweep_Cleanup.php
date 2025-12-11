<?php
/**
 * Clean Sweep - Cleanup Operations
 *
 * Handles cleanup and removal of Clean Sweep files and directories.
 *
 * @version 1.0
 */

class CleanSweep_Cleanup {

    /**
     * Clean up database entries created by Clean Sweep
     * Removes validation timestamps and other temporary data
     */
    private function cleanup_database_entries() {
        try {
            // Remove Clean Sweep validation timestamp from WordPress options
            $db = new CleanSweep_DB();
            $db->query(
                "DELETE FROM {$db->get_table_prefix()}options WHERE option_name = ?",
                ['clean_sweep_env_validated']
            );

            if (!defined('WP_CLI') || !WP_CLI) {
                echo "🗑️  Cleaned up database entries\n";
            }
        } catch (Exception $e) {
            // Silently continue if database cleanup fails
            // This prevents cleanup from failing due to database issues
            if (!defined('WP_CLI') || !WP_CLI) {
                echo "⚠️  Database cleanup skipped (may not be available)\n";
            }
        }
    }

    /**
     * Execute cleanup of all Clean Sweep files and directories
     * Memory-efficient version for managed hosting with limited memory
     */
    public function execute_cleanup() {
        // Note: Cleanup operations are not logged to avoid creating log files during cleanup

        // FIRST: Clean up database entries before file deletion
        $this->cleanup_database_entries();

        // Calculate Clean Sweep root directory dynamically (compatible with all architectures)
        $clean_sweep_dir = dirname(__DIR__, 2); // From includes/system/ up 2 levels to project root
        $files_deleted = 0;
        $dirs_deleted = 0;

        if (!defined('WP_CLI') || !WP_CLI) {
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
                if (!defined('WP_CLI') || !WP_CLI) {
                    echo "🗂️  Processing directory: $subdir\n";
                    ob_flush();
                    flush();
                }

                // Use memory-efficient deletion for large directories
                $result = $this->delete_directory_efficiently($subdir_path);
                if ($result['success']) {
                    $files_deleted += $result['files'];
                    $dirs_deleted += $result['dirs'];
                    if (!defined('WP_CLI') || !WP_CLI) {
                        echo "✅ Deleted directory: $subdir ({$result['files']} files, {$result['dirs']} dirs)\n";
                    }
                } else {
                    if (!defined('WP_CLI') || !WP_CLI) {
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
                    if (!defined('WP_CLI') || !WP_CLI) {
                        echo "✅ Deleted file: $basename\n";
                    }
                } else {
                    if (!defined('WP_CLI') || !WP_CLI) {
                        echo "❌ Failed to delete file: $basename\n";
                    }
                }
            } elseif (is_dir($file)) {
                // Delete any remaining directories
                if (clean_sweep_recursive_delete($file)) {
                    $dirs_deleted++;
                    if (!defined('WP_CLI') || !WP_CLI) {
                        echo "✅ Deleted directory: $basename\n";
                    }
                } else {
                    if (!defined('WP_CLI') || !WP_CLI) {
                        echo "❌ Failed to delete directory: $basename\n";
                    }
                }
            }

            // Flush output for real-time feedback
            if (!defined('WP_CLI') || !WP_CLI) {
                ob_flush();
                flush();
            }

            // Clear memory between operations
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        // Try to delete the main directory (may fail if script is running from it)
        if (rmdir($clean_sweep_dir)) {
            $dirs_deleted++;
            if (!defined('WP_CLI') || !WP_CLI) {
                echo "✅ Deleted directory: clean-sweep\n";
            }
        } else {
            if (!defined('WP_CLI') || !WP_CLI) {
                echo "ℹ️  Main directory will be empty (script running from it)\n";
            }
        }

        // Finally, delete the main script
        $main_script = $clean_sweep_dir . '/clean-sweep.php';
        if (file_exists($main_script) && unlink($main_script)) {
            $files_deleted++;
            if (!defined('WP_CLI') || !WP_CLI) {
                echo "✅ Deleted file: clean-sweep.php\n";
            }
        }

        if (!defined('WP_CLI') || !WP_CLI) {
            echo '</pre>';
            echo '</div>';
        }

        if (!defined('WP_CLI') || !WP_CLI) {
            echo '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:20px;border-radius:4px;margin:20px 0;color:#155724;">';
            echo '<h3>🎉 Clean Sweep Cleanup Complete!</h3>';
            echo '<p><strong>Summary:</strong></p>';
            echo '<ul>';
            echo '<li>Files deleted: ' . $files_deleted . '</li>';
            echo '<li>Directories deleted: ' . $dirs_deleted . '</li>';
            echo '</ul>';
            echo '<p><strong>✅ All Clean Sweep files and directories have been successfully removed from your server.</strong></p>';
            echo '<p><em>This toolkit is no longer available. If you need it again in the future, you can re-upload it.</em></p>';
            echo '</div>';
        } else {
            echo "\n🗑️ CLEANUP COMPLETE\n";
            echo str_repeat("=", 30) . "\n";
            echo "Files deleted: $files_deleted\n";
            echo "Directories deleted: $dirs_deleted\n";
            echo "\n✅ Clean Sweep has been completely removed from your server.\n";
        }
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
