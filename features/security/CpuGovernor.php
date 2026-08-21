<?php
/**
 * Clean Sweep - CPU/IO Governor (replaces Throttler)
 *
 * Adaptive CPU and I/O throttling that actually keeps the scanner off the
 * shared-hosting CPU ceiling. The previous Throttler only slept once per
 * file (1ms-1.5ms) and completely missed the hot path (signature matching
 * inside a single file), which is what pegs the CPU.
 *
 * Design goals:
 *  - Sample real system load via sys_getloadavg() and throttle harder
 *    when the box is already under pressure.
 *  - Yield at three granularities so we don't waste CPU time:
 *      micro-yield  -> per signature (between regex matches) - cheap
 *      file-yield   -> per file boundary
 *      batch-yield  -> per work-unit boundary (heavier, allows GC)
 *  - Stay safe on hosts where loadavg/proc is unavailable.
 *  - Behave correctly with max_execution_time - never sleep more than
 *    a small fraction of the remaining budget.
 *
 * @since Phase 4
 */
class CleanSweep_CpuGovernor {

    /** @var int Detected CPU core count (fallback = 1). */
    private $cpu_cores = 1;

    /** @var float Last observed system load (1-min). */
    private $last_load = 0.0;

    /** @var float Last sample time (microseconds). */
    private $last_sample_at = 0.0;

    /** @var int Minimum microseconds between load samples. */
    private const SAMPLE_INTERVAL_US = 250000; // 0.25s

    /** @var float Target load per core (loadavg / cores). Stay under this. */
    private $target_load_per_core;

    /** @var int Minimum delay (microseconds) to apply on a micro-yield. */
    private $micro_delay_us;

    /** @var int Minimum delay (microseconds) on a file-yield. */
    private $file_delay_us;

    /** @var int Maximum delay we are ever willing to add in one yield. */
    private const MAX_SINGLE_DELAY_US = 20000; // 20ms (was 50ms — long sleeps pin FPM workers)

    /** @var float Wall-clock budget for intentional sleeps in this request (seconds). */
    private $sleep_budget_seconds = 2.0;

    /** @var float Microseconds already spent in usleep this request. */
    private $slept_us = 0;

    /** @var int How often to do a micro-yield (every N signatures). */
    private $signature_yield_every;

    /** @var int Microtime of last time we did a "hard" yield. */
    private $last_hard_yield_at = 0.0;

    /**
     * @param array $options
     *   - 'preset'        : 'high'|'balanced'|'low'|'aggressive' (default: auto)
     *   - 'force_off'     : bool, disable all throttling (e.g. CLI cron path)
     *   - 'cpu_cores'     : int, override detected core count
     *   - 'memory_limit'  : int, override PHP memory_limit in bytes
     */
    public function __construct($options = []) {
        if (!empty($options['force_off'])) {
            $this->micro_delay_us = 0;
            $this->file_delay_us = 0;
            $this->target_load_per_core = 99.0;
            $this->signature_yield_every = PHP_INT_MAX;
            return;
        }

        $this->cpu_cores = !empty($options['cpu_cores'])
            ? max(1, (int)$options['cpu_cores'])
            : $this->detect_cpu_cores();

        $mem_limit = !empty($options['memory_limit'])
            ? (int)$options['memory_limit']
            : $this->parse_memory_limit(ini_get('memory_limit'));

        // Decide preset from observed environment.
        $preset = $options['preset'] ?? $this->auto_preset($mem_limit);

        switch ($preset) {
            case 'high':
                // High-resource host. Still yield, but minimally.
                $this->micro_delay_us = 200;          // 0.2ms per signature
                $this->file_delay_us  = 2000;         // 2ms per file
                $this->target_load_per_core = 1.5;
                $this->signature_yield_every = 10;
                break;

            case 'balanced':
                $this->micro_delay_us = 800;          // 0.8ms
                $this->file_delay_us  = 5000;         // 5ms
                $this->target_load_per_core = 0.9;
                $this->signature_yield_every = 5;
                break;

            case 'low':
                // Shared / restricted hosting.
                // Previous values (4ms every 3 sigs + 25ms/file) spent more wall
                // time in usleep() than scanning — slow logs showed CpuGovernor
                // as the top stack, and long drains caused gateway 504s that
                // starved status/resume and "killed" the scan from the UI.
                $this->micro_delay_us = 400;          // 0.4ms
                $this->file_delay_us  = 3000;         // 3ms
                $this->target_load_per_core = 0.75;
                $this->signature_yield_every = 12;
                break;

            case 'aggressive':
                // Very restricted box — still lighter than the old multi-ms sleeps.
                $this->micro_delay_us = 800;          // 0.8ms
                $this->file_delay_us  = 8000;         // 8ms
                $this->target_load_per_core = 0.5;
                $this->signature_yield_every = 8;
                break;

            default:
                $this->micro_delay_us = 800;
                $this->file_delay_us  = 5000;
                $this->target_load_per_core = 0.9;
                $this->signature_yield_every = 5;
        }

        $this->last_load = $this->sample_load();
        $this->last_sample_at = microtime(true);
    }

    /**
     * Auto-detect preset based on memory_limit and CPU count.
     * We are conservative for web execution (the common case for the UI-driven
     * resilient path) because even "high resource" boxes can have noisy neighbors
     * or aggressive process killing. CLI/cron paths can pass force_off or high.
     *
     * For 100GB+ sites we still want breathing room so a single runaway unit
     * doesn't peg the box while the work queue + small budgets keep things
     * under control.
     */
    private function auto_preset($mem_limit) {
        // Bias web / unknown toward low unless the box advertises lots of RAM.
        // This helps the "high CPU 100%" reports on real shared hosting even
        // when php.ini says memory_limit=512M or 1G.
        if ($mem_limit > 0 && $mem_limit <= 384 * 1024 * 1024) {
            return $this->cpu_cores <= 2 ? 'aggressive' : 'low';
        }
        if ($mem_limit > 0 && $mem_limit <= 768 * 1024 * 1024) {
            return 'low'; // was balanced; be nicer by default for web
        }
        if ($mem_limit > 0 && $mem_limit <= 1536 * 1024 * 1024) {
            return 'balanced';
        }
        return 'high';
    }

    /**
     * Best-effort CPU core count. /proc/cpuinfo on Linux, sysctl elsewhere.
     */
    private function detect_cpu_cores() {
        if (function_exists('shell_exec') && is_readable('/proc/cpuinfo')) {
            $out = @shell_exec('grep -c ^processor /proc/cpuinfo 2>/dev/null');
            if (is_string($out) && (int)trim($out) > 0) {
                return (int)trim($out);
            }
        }
        // PHP 7.2+ exposes this on some systems.
        if (defined('PHP_OS_FAMILY')) {
            if (PHP_OS_FAMILY === 'Linux' && is_readable('/sys/fs/cgroup/cpu.max')) {
                // cgroup v2 - quota/period
                $raw = @file_get_contents('/sys/fs/cgroup/cpu.max');
                if ($raw && preg_match('/(\d+)\s+(\d+)/', $raw, $m)) {
                    $quota = (float)$m[1];
                    $period = (float)$m[2];
                    if ($quota > 0 && $period > 0) {
                        return max(1, (int)floor($quota / $period));
                    }
                }
            }
        }
        return 1;
    }

    private function parse_memory_limit($limit) {
        if ($limit === '-1' || $limit === false || $limit === '') {
            return 0;
        }
        if (preg_match('/^(\d+)([KMG])/i', $limit, $m)) {
            $v = (int)$m[1];
            switch (strtoupper($m[2])) {
                case 'K': return $v * 1024;
                case 'M': return $v * 1024 * 1024;
                case 'G': return $v * 1024 * 1024 * 1024;
            }
        }
        return (int)$limit;
    }

    /**
     * Sample system load. Returns 0.0 if not available (Windows, locked down).
     */
    private function sample_load() {
        if (function_exists('sys_getloadavg')) {
            $la = @sys_getloadavg();
            if (is_array($la) && isset($la[0])) {
                return (float)$la[0];
            }
        }
        return 0.0;
    }

    /**
     * Refresh load sample if it's been long enough. Otherwise re-use cache.
     */
    private function refresh_load_if_needed() {
        $now = microtime(true);
        if (($now - $this->last_sample_at) * 1e6 < self::SAMPLE_INTERVAL_US) {
            return;
        }
        $this->last_load = $this->sample_load();
        $this->last_sample_at = $now;
    }

    /**
     * Compute load-per-core. If we don't have cores or load, return 0.
     */
    private function load_per_core() {
        if ($this->cpu_cores < 1) {
            return 0.0;
        }
        return $this->last_load / $this->cpu_cores;
    }

    /**
     * Compute additional delay multiplier based on current load.
     * Returns 1.0 when load is at/under target, up to 8.0 when 4x over target.
     */
    private function load_multiplier() {
        if ($this->last_load <= 0.0 || $this->target_load_per_core <= 0) {
            return 1.0;
        }
        $current = $this->load_per_core();
        if ($current <= $this->target_load_per_core) {
            return 1.0;
        }
        $ratio = $current / $this->target_load_per_core; // >1 when over
        $mult = 1.0 + min(7.0, log($ratio) * 2.5);
        return max(1.0, $mult);
    }

    /**
     * Adaptive delay based on current load. Clamped to safe upper bound.
     */
    private function adaptive_delay_us($base_us) {
        // Stop intentional sleeping once we've used the sleep budget —
        // remaining time is for real work so drains finish under gateway limits.
        if ($this->slept_us >= ($this->sleep_budget_seconds * 1000000)) {
            return 0;
        }
        $mult = min(3.0, $this->load_multiplier()); // was up to ~8x
        $delay = (int)($base_us * $mult);
        return min(self::MAX_SINGLE_DELAY_US, max(0, $delay));
    }

    /**
     * YIELD BETWEEN SIGNATURES (the actual hot path).
     * Called once per regex match attempt inside a file's chunk loop.
     *
     * @param int $sig_index  Current signature index in the file's loop
     */
    public function micro_yield($sig_index = 0) {
        if ($this->micro_delay_us <= 0 || $this->signature_yield_every <= 0) {
            return;
        }
        if (($sig_index % $this->signature_yield_every) !== 0) {
            return;
        }
        $this->refresh_load_if_needed();
        $delay = $this->adaptive_delay_us($this->micro_delay_us);
        if ($delay > 0) {
            $this->safe_sleep($delay);
        }
    }

    /**
     * YIELD AT FILE BOUNDARIES. Called once per file processed.
     * Heavier than micro_yield, includes a small GC nudge occasionally.
     */
    public function file_yield() {
        if ($this->file_delay_us <= 0) {
            return;
        }
        $this->refresh_load_if_needed();
        $delay = $this->adaptive_delay_us($this->file_delay_us);
        if ($delay > 0) {
            $this->safe_sleep($delay);
        }
    }

    /**
     * HEAVY YIELD at work-unit boundaries. Forces a GC pass and longer rest.
     * Used at the end of a chunk of N files / M db rows.
     */
    public function batch_yield() {
        $this->refresh_load_if_needed();
        $delay = $this->adaptive_delay_us($this->file_delay_us * 4);
        $this->last_hard_yield_at = microtime(true);
        // GC only at work-unit / DB-batch boundaries (not per file).
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        if ($delay > 0) {
            $this->safe_sleep($delay);
        }
    }

    /**
     * Sleep that respects remaining PHP max_execution_time budget.
     * Never sleeps more than 10% of remaining time.
     *
     * Works correctly under web requests, CLI scripts, and cron. CLI
     * processes don't have REQUEST_TIME_FLOAT set, so we fall back to
     * tracking our own start time when in CLI mode.
     */
    private function safe_sleep($us) {
        $max = (int)@ini_get('max_execution_time');
        if ($max > 0) {
            $req_start = $this->get_request_start_time();
            $elapsed = microtime(true) - $req_start;
            $remaining = $max - $elapsed;
            // Cap at 10% of remaining (microseconds).
            $cap = (int)($remaining * 100000);
            if ($cap <= 0) {
                return;
            }
            if ($us > $cap) {
                $us = $cap;
            }
        }
        if ($us > 0) {
            usleep($us);
            $this->slept_us += $us;
        }
    }

    /**
     * Get the start time of the current request/process, working under
     * all SAPIs (web, CLI, cron).
     *
     * - Web (FPM/CGI/Apache): $_SERVER['REQUEST_TIME_FLOAT'] is accurate.
     * - CLI: no request start exists; we use the process start time
     *   captured at first governor use.
     * - Cron -> CLI script: same as CLI.
     */
    private function get_request_start_time() {
        if (php_sapi_name() !== 'cli' && !empty($_SERVER['REQUEST_TIME_FLOAT'])) {
            return (float)$_SERVER['REQUEST_TIME_FLOAT'];
        }
        if (!empty($_SERVER['REQUEST_TIME'])) {
            return (float)$_SERVER['REQUEST_TIME'];
        }
        // Fallback: lazily capture the first time we were asked.
        static $process_start = null;
        if ($process_start === null) {
            $process_start = microtime(true);
        }
        return $process_start;
    }

    public function is_enabled() {
        return $this->micro_delay_us > 0;
    }

    public function get_cpu_cores() {
        return $this->cpu_cores;
    }

    public function get_current_load() {
        $this->refresh_load_if_needed();
        return $this->last_load;
    }

    /**
     * Snapshot for logging / diagnostics.
     */
    public function stats() {
        $this->refresh_load_if_needed();
        return [
            'cpu_cores' => $this->cpu_cores,
            'load_1m' => $this->last_load,
            'load_per_core' => $this->load_per_core(),
            'target_load_per_core' => $this->target_load_per_core,
            'micro_delay_us' => $this->micro_delay_us,
            'file_delay_us' => $this->file_delay_us,
            'signature_yield_every' => $this->signature_yield_every,
            'current_multiplier' => $this->load_multiplier(),
        ];
    }
}