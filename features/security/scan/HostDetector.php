<?php
/**
 * Clean Sweep - Host Detector
 *
 * Structured access to host environment characteristics.
 * Replaces ad-hoc `clean_sweep_is_shared_hosting()` checks scattered across the codebase.
 *
 * Used by CleanSweep_Scanner to:
 *   - pick a default profile (quick/standard/deep)
 *   - pick a CpuGovernor preset
 *   - compute the time budget
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_HostDetector {

    /** @var int Memory limit in bytes (0 = unknown / unlimited) */
    public int $memory_limit_bytes;

    /** @var int PHP max_execution_time in seconds (0 = unlimited) */
    public int $max_execution_time;

    /** @var int Detected CPU cores (best effort) */
    public int $cpu_cores;

    /** @var bool Whether loopback HTTP requests are likely to work */
    public bool $can_loopback;

    /** @var bool Whether WP-Cron is available */
    public bool $can_wp_cron;

    /** @var string One of: 'cli', 'web' */
    public string $context;

    /** @var bool Whether loopback has been tested via actual probe */
    private bool $loopback_tested = false;

    /** @var string|null Path to loopback test cache file */
    private static ?string $loopback_cache_file = null;

    public function __construct() {
        $this->context = (php_sapi_name() === 'cli') ? 'cli' : 'web';
        $this->max_execution_time = (int)ini_get('max_execution_time');
        $this->memory_limit_bytes = $this->parseMemoryLimit(ini_get('memory_limit'));
        $this->cpu_cores = $this->detectCpuCores();
        $this->can_loopback = function_exists('curl_init') && $this->max_execution_time >= 30;
        $this->can_wp_cron = function_exists('wp_schedule_single_event');
    }

    /**
     * Get the path to the loopback test cache file.
     * Uses the logs directory for persistence.
     *
     * @return string Path to cache file
     */
    private static function getLoopbackCacheFile(): string {
        if (self::$loopback_cache_file !== null) {
            return self::$loopback_cache_file;
        }
        $logs_dir = defined('CLEAN_SWEEP_LOGS_DIR') ? CLEAN_SWEEP_LOGS_DIR : __DIR__ . '/../../../logs/';
        self::$loopback_cache_file = rtrim($logs_dir, '/') . '/.loopback_test_cache.json';
        return self::$loopback_cache_file;
    }

    /**
     * Load cached loopback test result from disk.
     * Returns null if no valid cached result exists.
     *
     * @return bool|null True if loopback works, false if it doesn't, null if cache miss
     */
    private static function loadLoopbackCache(): ?bool {
        $cache_file = self::getLoopbackCacheFile();
        if (!file_exists($cache_file)) {
            return null;
        }

        $data = @file_get_contents($cache_file);
        if ($data === false) {
            return null;
        }

        $cached = json_decode($data, true);
        if (!is_array($cached)) {
            return null;
        }

        // Cache expires after 1 hour to avoid stale results if network config changes
        $max_age = 3600;
        if (isset($cached['tested_at']) && (time() - $cached['tested_at']) > $max_age) {
            return null;
        }

        if (isset($cached['can_loopback'])) {
            return (bool)$cached['can_loopback'];
        }

        return null;
    }

    /**
     * Save loopback test result to disk cache.
     *
     * @param bool $can_loopback Whether loopback works
     */
    private static function saveLoopbackCache(bool $can_loopback): void {
        $cache_file = self::getLoopbackCacheFile();
        $data = [
            'can_loopback' => $can_loopback,
            'tested_at' => time(),
        ];

        @file_put_contents($cache_file, json_encode($data), LOCK_EX);
    }

    /**
     * Ensure loopback has been tested. If not yet tested, performs an actual
     * probe request to verify self-loopback works on this host.
     *
     * Results are cached to disk so the probe only fires once per hour across
     * all PHP requests (not once per drain invocation).
     *
     * @return bool True if loopback is confirmed working, false otherwise
     */
    public function ensureLoopbackTested(): bool {
        if ($this->loopback_tested) {
            return $this->can_loopback;
        }
        $this->loopback_tested = true;

        // Try to load from persistent cache first
        $cached_result = self::loadLoopbackCache();
        if ($cached_result !== null) {
            $this->can_loopback = $cached_result;
            clean_sweep_log_message(
                "CleanSweep_HostDetector: loopback test loaded from cache (can_loopback={$cached_result})",
                'debug'
            );
            return $this->can_loopback;
        }

        // No cached result — perform actual probe
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = $_SERVER['REQUEST_URI'] ?? '/clean-sweep.php';
        $base = strtok($base, '?');

        $test_url = $scheme . '://' . $host . $base
                  . '?action=internal_kick'
                  . '&scan_id=_loopback_test'
                  . '&hmac=_invalid_hmac'; // Intentionally invalid — we only care if the request reaches us

        // Use a very short timeout — we just need to know if the request gets through
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $test_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: CleanSweep-LoopbackTest/1.0']);
        // Don't follow redirects
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        @curl_exec($ch);
        $err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // If we got any HTTP response (even 403/404 from our invalid HMAC),
        // the loopback request reached the server. If curl failed to connect,
        // loopback is blocked.
        $this->can_loopback = empty($err) && $http_code > 0;

        // Persist result to cache
        self::saveLoopbackCache($this->can_loopback);

        if (!$this->can_loopback) {
            clean_sweep_log_message(
                "CleanSweep_HostDetector: loopback test failed — curl_error='{$err}', http_code={$http_code}. Falling back to cron.",
                'warning'
            );
        } else {
            clean_sweep_log_message(
                "CleanSweep_HostDetector: loopback test succeeded (http_code={$http_code}), cached for 1 hour",
                'debug'
            );
        }

        return $this->can_loopback;
    }

    /**
     * CpuGovernor preset appropriate for this host.
     */
    public function cpuGovernorPreset(): string {
        $is_web = ($this->context === 'web');
        // On web, be conservative even on big boxes (noisy neighbors, process killing).
        if ($this->memory_limit_bytes > 0 && $this->memory_limit_bytes <= 384 * 1024 * 1024) {
            return $this->cpu_cores <= 2 ? 'aggressive' : 'low';
        }
        if ($this->memory_limit_bytes > 0 && $this->memory_limit_bytes <= 768 * 1024 * 1024) {
            return 'low';
        }
        if ($this->memory_limit_bytes > 0 && $this->memory_limit_bytes <= 1536 * 1024 * 1024) {
            return $is_web ? 'low' : 'balanced';
        }
        return $is_web ? 'balanced' : 'high';
    }

    /**
     * Recommended time budget per drain invocation, in seconds.
     *
     * IMPORTANT: Gateways (nginx/Cloudflare/cPanel) often kill PHP at ~60s
     * with 504 even when set_time_limit(0) is used. Long drains also pin
     * PHP-FPM workers so status/resume requests queue and 504 as well —
     * which looks like a "dead" scan. Prefer short slices + kick/resume.
     */
    public function recommendedTimeBudget(): int {
        // Hard gateway-safe ceilings. Shared hosts get shorter slices.
        if ($this->isSharedHosting()) {
            return 18; // leave headroom under typical 30–60s proxy limits
        }
        if ($this->max_execution_time > 0 && $this->max_execution_time <= 60) {
            return max(12, min(25, $this->max_execution_time - 8));
        }
        // Comfortable hosts: still avoid multi-minute single requests
        return 35;
    }

    /**
     * Max work units to process in one drain() call.
     * Forces release of the FPM worker even if wall time is not exhausted.
     */
    public function recommendedMaxUnitsPerDrain(): int {
        if ($this->isSharedHosting()) {
            return 4;
        }
        return 12;
    }

    /**
     * True if this host has the characteristics of a constrained shared host.
     * Short time limit + low memory + few cores = shared.
     */
    public function isSharedHosting(): bool {
        if ($this->max_execution_time > 0 && $this->max_execution_time <= 30) {
            return true;
        }
        if ($this->memory_limit_bytes > 0 && $this->memory_limit_bytes <= 256 * 1024 * 1024) {
            return true;
        }
        return false;
    }

    private function parseMemoryLimit(string $limit): int {
        if ($limit === '' || $limit === '-1') return 0;
        if (preg_match('/^(\d+)([KMG])$/i', $limit, $m)) {
            $v = (int)$m[1];
            switch (strtoupper($m[2])) {
                case 'K': return $v * 1024;
                case 'M': return $v * 1024 * 1024;
                case 'G': return $v * 1024 * 1024 * 1024;
            }
        }
        return (int)$limit;
    }

    private function detectCpuCores(): int {
        if (function_exists('shell_exec') && is_readable('/proc/cpuinfo')) {
            $out = @shell_exec('grep -c ^processor /proc/cpuinfo 2>/dev/null');
            if (is_string($out) && (int)trim($out) > 0) {
                return (int)trim($out);
            }
        }
        if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Linux' && is_readable('/sys/fs/cgroup/cpu.max')) {
            $raw = @file_get_contents('/sys/fs/cgroup/cpu.max');
            if ($raw && preg_match('/(\d+)\s+(\d+)/', $raw, $m)) {
                $quota = (float)$m[1];
                $period = (float)$m[2];
                if ($quota > 0 && $period > 0) {
                    return max(1, (int)floor($quota / $period));
                }
            }
        }
        return 1;
    }
}