<?php
/**
 * Clean Sweep - Differential CleanSweep_Scanner
 *
 * Incremental scanning using file hash comparisons.
 * Only scans files that are new or have changed since last scan.
 *
 * @since Phase 2
 */
class CleanSweep_DifferentialScanner {

    /** @var string File containing previous scan hashes */
    private $hash_file;

    /** @var array Previous hashes from last scan */
    private $previous_hashes = [];

    /** @var bool Whether previous_hashes has been loaded for the current hash_file */
    private $hashes_loaded = false;

    /** @var bool Whether differential mode is enabled */
    private $enabled = false;

    /** @var string|null Current profile ID for profile-specific hash files */
    private $profile_id = null;

    /**
     * Initialize differential scanner.
     *
     * @param string|null $hash_file Custom hash file path
     * @param bool $eager_load When false, defer disk read until hashes are needed
     */
    public function __construct($hash_file = null, $eager_load = false) {
        if ($hash_file !== null) {
            $this->hash_file = $hash_file;
        } else {
            // Default to global hash file (can be overridden by set_profile_id)
            $this->hash_file = CLEAN_SWEEP_LOGS_DIR . 'file_hashes.json';
        }
        if ($eager_load) {
            $this->load_previous_hashes();
        }
    }

    /**
     * Set profile ID for profile-specific hash caching.
     * This allows Quick scan to have a separate (smaller) hash cache
     * while Standard/Deep share another cache.
     *
     * @param string $profile_id Profile identifier (quick, standard, deep)
     */
    public function set_profile_id($profile_id) {
        $this->profile_id = $profile_id;

        // Use profile-specific hash file to keep caches separate
        // Quick scan gets its own cache (smaller, faster for quick repeat scans)
        // Standard/Deep share a cache (broader coverage)
        $new_file = ($profile_id === 'quick')
            ? CLEAN_SWEEP_LOGS_DIR . 'file_hashes_quick.json'
            : CLEAN_SWEEP_LOGS_DIR . 'file_hashes_standard.json';

        if ($new_file !== $this->hash_file) {
            $this->hash_file = $new_file;
            $this->hashes_loaded = false;
            $this->previous_hashes = [];
        }

        // Load once for the profile-specific file (skips if already loaded)
        $this->ensure_hashes_loaded();
    }

    /**
     * Get the current hash file path.
     *
     * @return string
     */
    public function get_hash_file() {
        return $this->hash_file;
    }

    /**
     * Ensure previous hashes are in memory for the current hash_file.
     */
    private function ensure_hashes_loaded(): void {
        if ($this->hashes_loaded) {
            return;
        }
        $this->load_previous_hashes();
    }

    /**
     * Load previous scan hashes from disk.
     *
     * @return array
     */
    public function load_previous_hashes() {
        if (!file_exists($this->hash_file)) {
            $this->previous_hashes = [];
            $this->hashes_loaded = true;
            return $this->previous_hashes;
        }

        $data = @file_get_contents($this->hash_file);
        if ($data === false) {
            $this->previous_hashes = [];
            $this->hashes_loaded = true;
            return $this->previous_hashes;
        }

        $decoded = json_decode($data, true);
        $this->previous_hashes = is_array($decoded) ? $decoded : [];
        $this->hashes_loaded = true;
        return $this->previous_hashes;
    }

    /**
     * Save file hashes for next scan.
     *
     * Merges $hashes into the existing manifest by default so multi-batch
     * scans accumulate a full site map instead of overwriting with one batch.
     *
     * @param array $hashes Path => hash pairs
     * @param bool $merge Merge with in-memory/previous hashes (default true)
     * @return bool Success
     */
    public function save_hashes($hashes, $merge = true) {
        if (!is_array($hashes) || $hashes === []) {
            // Nothing new from this batch — avoid rewriting a multi-MB manifest.
            return true;
        }

        // Drop null/empty hash values so a failed hash_file cannot clobber a good entry.
        $hashes = array_filter($hashes, static function ($h) {
            return is_string($h) && $h !== '';
        });
        if ($hashes === []) {
            return true;
        }

        $this->ensure_hashes_loaded();

        if ($merge) {
            // New hashes win on key collision (current scan is authoritative).
            $hashes = array_merge($this->previous_hashes, $hashes);
        }

        $dir = dirname($this->hash_file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $result = @file_put_contents(
            $this->hash_file,
            json_encode($hashes, JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        if ($result === false) {
            // Do not advance in-memory map if disk write failed.
            return false;
        }

        // Keep in-memory map current so later units in the same drain see updates.
        $this->previous_hashes = $hashes;
        $this->hashes_loaded = true;
        return true;
    }

    /**
     * Get files that need scanning (new or changed).
     *
     * @param array $current_files Path => hash pairs for current files
     * @return array Files that need scanning
     */
    public function get_files_to_scan($current_files) {
        $this->ensure_hashes_loaded();
        if (!$this->enabled || empty($this->previous_hashes)) {
            // Full scan - return all files
            return $current_files;
        }

        $to_scan = [];

        foreach ($current_files as $path => $hash) {
            if (!isset($this->previous_hashes[$path])) {
                // New file
                $to_scan[$path] = $hash;
            } elseif ($this->previous_hashes[$path] !== $hash) {
                // Changed file
                $to_scan[$path] = $hash;
            }
            // Unchanged files are skipped
        }

        return $to_scan;
    }

    /**
     * Get files that have been removed since last scan.
     *
     * @param array $current_files Current files
     * @return array Paths of removed files
     */
    public function get_removed_files($current_files) {
        $this->ensure_hashes_loaded();
        if (empty($this->previous_hashes)) {
            return [];
        }

        $current_paths = array_keys($current_files);
        $removed = [];

        foreach (array_keys($this->previous_hashes) as $previous_path) {
            if (!in_array($previous_path, $current_paths, true)) {
                $removed[] = $previous_path;
            }
        }

        return $removed;
    }

    /**
     * Check if a specific file needs scanning.
     *
     * @param string $path File path
     * @param string $hash Current file hash
     * @return bool True if file needs scanning
     */
    public function needs_scanning($path, $hash) {
        if (!$this->enabled) {
            return true;
        }
        $this->ensure_hashes_loaded();
        if (!isset($this->previous_hashes[$path])) {
            return true;
        }

        return $this->previous_hashes[$path] !== $hash;
    }

    /**
     * True when $path is in the manifest with this exact hash (unchanged).
     */
    public function hash_matches_manifest(string $path, string $hash): bool {
        if ($hash === '' || !$this->enabled) {
            return false;
        }
        $this->ensure_hashes_loaded();
        $keys = [$path, str_replace('\\', '/', $path)];
        $real = @realpath($path);
        if (is_string($real) && $real !== '') {
            $keys[] = $real;
            $keys[] = str_replace('\\', '/', $real);
        }
        foreach (array_unique($keys) as $k) {
            if (isset($this->previous_hashes[$k]) && $this->previous_hashes[$k] === $hash) {
                return true;
            }
        }
        return false;
    }

    /**
     * Calculate hash for a file.
     *
     * @param string $path File path
     * @return string|null Hash or null on error
     */
    public static function hash_file($path) {
        if (!file_exists($path) || !is_readable($path)) {
            return null;
        }

        // Use fast hash for large files, sha256 for security
        $size = filesize($path);

        if ($size > 10485760) { // > 10MB
            // For large files, hash first 64KB + last 64KB + size
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return null;
            }
            $head = fread($handle, 65536);
            fseek($handle, -65536, SEEK_END);
            $tail = fread($handle, 65536);
            fclose($handle);
            $hash = md5($head . $tail . $size);
        } else {
            $hash = hash_file('sha256', $path);
        }

        return $hash;
    }

    /**
     * Enable or disable differential mode.
     *
     * @param bool $enabled
     */
    public function set_enabled($enabled) {
        $this->enabled = (bool)$enabled;
    }

    /**
     * Check if differential mode is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return $this->enabled;
    }

    /**
     * Get statistics about the last scan's file state.
     *
     * @return array Stats including new, changed, removed, unchanged counts
     */
    public function get_stats() {
        $hashes = $this->load_previous_hashes();

        return [
            'enabled' => $this->enabled,
            'tracked_files' => count($hashes),
            'hash_file' => $this->hash_file,
            'hash_file_exists' => file_exists($this->hash_file),
            'hash_file_size' => file_exists($this->hash_file) ? filesize($this->hash_file) : 0,
        ];
    }

    /**
     * Clear stored hashes (force full scan next time).
     *
     * @return bool Success
     */
    public function clear_hashes() {
        $this->previous_hashes = [];
        $this->hashes_loaded = true;

        if (file_exists($this->hash_file)) {
            return @unlink($this->hash_file);
        }

        return true;
    }

    /**
     * Build hash manifest from a list of files.
     *
     * @param array $files List of file paths
     * @return array Path => hash pairs
     */
    public function build_hash_manifest($files) {
        $manifest = [];

        foreach ($files as $path) {
            $hash = self::hash_file($path);
            if ($hash !== null) {
                $manifest[$path] = $hash;
            }
        }

        return $manifest;
    }
}
