<?php
/**
 * Clean Sweep - CleanSweep_Scheduler
 *
 * One job: "Given the current state and the result of the last drain,
 * what's the next step?"
 *
 * Replaces 6 schedule functions spread across 3 files in the old
 * architecture. Bugs that lived in those seams (undefined
 * $continuation_attempts, 1-second curl timeout, 5-minute token
 * expiry) become structurally impossible here.
 *
 * Decision tree (single place):
 *   - If scan is terminal (completed/failed/cancelled) → no kick
 *   - If queue is fully drained → no kick
 *   - If a kick was scheduled <45s ago → no kick (avoid storms)
 *   - If we have no kick channel available → no kick (caller must poll)
 *   - Otherwise → schedule a loopback kick (now) + a delayed kick (safety)
 *
 * The 5-minute token expiry is removed: the kick URL just carries the
 * scan_id and an HMAC. If the scan_id exists in the checkpoint, the
 * kick is valid. No time window.
 *
 * @since CleanSweep_Scanner v2
 */

/**
 * Where the next drain should be triggered.
 */
final class CleanSweep_ScheduledKick {
    public const CHANNEL_LOOPBACK = 'loopback';
    public const CHANNEL_CRON = 'cron';
    public const CHANNEL_NONE = 'none';

    public string $channel;
    public int $at_unix;          // 0 = immediate
    public ?string $url;          // populated when channel=loopback
    public string $reason;        // diagnostic, for logs

    public function __construct(string $channel, int $at_unix, string $reason, ?string $url = null) {
        $this->channel = $channel;
        $this->at_unix = $at_unix;
        $this->reason = $reason;
        $this->url = $url;
    }

    public static function none(string $reason): self {
        return new self(self::CHANNEL_NONE, 0, $reason);
    }
}

final class CleanSweep_Scheduler {

    /** @var CleanSweep_HostDetector */
    private CleanSweep_HostDetector $host;

    /** @var int Minimum seconds between scheduled kicks (storm guard) */
    private int $min_kick_interval = 20;

    public function __construct(CleanSweep_HostDetector $host) {
        $this->host = $host;
    }

    /**
     * Decide what to do after a drain invocation completes.
     *
     * The drain caller passes:
     *   - $state: current CleanSweep_ScanState (read from disk before drain)
     *   - $drainResult: the drain's verdict (reason, processed, completed, failed, etc.)
     *
     * The scheduler does NOT touch the queue, the checkpoint, or the loopback
     * firing itself. It returns a CleanSweep_ScheduledKick, and the caller (CleanSweep_Scanner) is
     * responsible for persisting the "kick scheduled" markers and firing the
     * HTTP request. This keeps the scheduler pure and unit-testable.
     *
     * @param CleanSweep_ScanState $state
     * @param array $drainResult
     * @return CleanSweep_ScheduledKick
     */
    public function scheduleNext(CleanSweep_ScanState $state, array $drainResult): CleanSweep_ScheduledKick {
        // Terminal states: no kick.
        if ($state->isTerminal()) {
            return CleanSweep_ScheduledKick::none('scan_terminal');
        }

        // Drain finished because the queue is empty: no kick.
        $reason = $drainResult['reason'] ?? 'unknown';
        if ($reason === 'scan_completed' || $reason === 'queue_empty') {
            return CleanSweep_ScheduledKick::none('queue_empty');
        }

        // Drain finished because the scanner paused deliberately: still need a kick
        // so we can come back and finish the rest of the work. The difference is
        // we wait a bit longer (give the host breathing room).
        // in_flight_busy: another worker holds leases — kick again after a short wait.
        $is_pause_exit = ($reason === 'scan_paused' || $reason === 'deliberate_pause' || $reason === 'in_flight_busy');

        // Storm guard: suppress redundant kick if drain finished very quickly
        // AND there is no clear "work remains" signal. When time_budget /
        // in_flight_busy / nothing_claimable fire, pending or leased work still
        // exists — we MUST schedule a follow-on kick even on a short drain.
        // (min_kick_interval defaults to 20s; see $this->min_kick_interval.)
        $drain_started = $state->drain_started_at;
        $must_continue = in_array($reason, [
            'time_budget_exceeded',
            'in_flight_busy',
            'nothing_claimable',
        ], true);
        if ($drain_started !== null && (time() - $drain_started) < $this->min_kick_interval && !$must_continue) {
            return CleanSweep_ScheduledKick::none('storm_guard_drain_in_progress');
        }

        // If loopback isn't usable on this host, fall back to WP-Cron only.
        // When loopback works, CleanSweep_Scanner::executeKick fires HTTP and does not
        // write clean_sweep_scan_kick into wp_options unless that fire fails.
        if (!$this->host->can_loopback) {
            return $this->scheduleCron($state, $is_pause_exit, 'no_loopback');
        }

        return $this->scheduleLoopback($state, $is_pause_exit);
    }

    /**
     * Build a loopback kick for the scheduler's decision. The caller is
     * responsible for firing it.
     *
     * Delay is adaptive:
     * - Short delay (5s) when drain hit time_budget with work remaining: the
     *   queue is still hot and we want to keep the pipeline full.
     * - Longer delay (25s) when drain exited via pause: user stepped away,
     *   host gets breathing room.
     * - Moderate delay (10s) when we have a file estimate and are near the
     *   end of scanning: avoid last-minute kicks.
     *
     * The storm guard (min_kick_interval=45s) prevents over-kicking from
     * short drains regardless of what we schedule here.
     */
    private function scheduleLoopback(CleanSweep_ScanState $state, bool $is_pause_exit): CleanSweep_ScheduledKick {
        $delay = $this->computeAdaptiveDelay($state, $is_pause_exit);
        $kick = $this->buildLoopbackUrl($state, $delay);

        $kick->reason = $is_pause_exit ? 'loopback_after_pause' : 'loopback_after_budget';
        return $kick;
    }

    /**
     * Compute an adaptive kick delay in seconds.
     */
    private function computeAdaptiveDelay(CleanSweep_ScanState $state, bool $is_pause_exit): int {
        if ($is_pause_exit) {
            // Host breathing room after a deliberate pause. Loopback still
            // fires immediately (see CleanSweep_Scanner::executeKick); this delay only
            // affects the WP-Cron safety-net schedule time.
            return 15;
        }

        // Time-budget exits: keep the pipeline hot. Immediate loopback + a
        // short cron backup is enough; no multi-second intentional stall.
        if ($state->total_files_estimate > 0) {
            $remaining = $state->total_files_estimate - $state->files_scanned;
            $batch_size = 50;
            if ($remaining <= 0) {
                return 10;
            }
            if ($remaining <= $batch_size * 3) {
                return 5;
            }
        }

        return 2;
    }

    private function scheduleCron(CleanSweep_ScanState $state, bool $is_pause_exit, string $why): CleanSweep_ScheduledKick {
        $delay = $is_pause_exit ? 25 : $this->computeAdaptiveDelay($state, false);
        return new CleanSweep_ScheduledKick(
            CleanSweep_ScheduledKick::CHANNEL_CRON,
            time() + $delay,
            'cron_fallback_' . $why
        );
    }

    /**
     * Build the loopback URL. Pure function - no side effects.
     * The token is an HMAC of scan_id + a server secret, so it has
     * no time window (see verifyKickToken in CleanSweep_Scanner).
     */
    private function buildLoopbackUrl(CleanSweep_ScanState $state, int $delay_seconds): CleanSweep_ScheduledKick {
        static $cached_base = null;
        if ($cached_base === null) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
                ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

            // Prefer a stable path to api/malware.php. REQUEST_URI during a kick
            // response is already malware.php, but during CLI/cron it may be empty
            // or point at clean-sweep.php (which does not route internal_kick).
            $base = null;
            if (defined('CLEAN_SWEEP_ROOT')) {
                // Best-effort public URL path: /.../clean-sweep/api/malware.php
                $script = $_SERVER['SCRIPT_NAME'] ?? '';
                if (is_string($script) && str_contains($script, '/api/')) {
                    $base = strtok($script, '?');
                } elseif (is_string($script) && $script !== '') {
                    // SCRIPT_NAME like /clean-sweep/clean-sweep.php → sibling api/malware.php
                    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
                    $base = $dir . '/api/malware.php';
                }
            }
            if ($base === null || $base === '' || $base === '/') {
                $req = $_SERVER['REQUEST_URI'] ?? '';
                $base = $req !== '' ? strtok($req, '?') : '/clean-sweep/api/malware.php';
                // If request was clean-sweep.php, rewrite to api/malware.php
                if (str_ends_with($base, 'clean-sweep.php')) {
                    $base = preg_replace('#/clean-sweep\\.php$#', '/api/malware.php', $base) ?: $base;
                }
            }
            $cached_base = $scheme . '://' . $host . $base;
        }

        $hmac = $this->makeKickToken($state->scan_id);

        $url = $cached_base
             . '?action=internal_kick'
             . '&scan_id=' . urlencode($state->scan_id)
             . '&hmac=' . urlencode($hmac);

        return new CleanSweep_ScheduledKick(CleanSweep_ScheduledKick::CHANNEL_LOOPBACK, time() + $delay_seconds, '', $url);
    }

    /**
     * Compute a per-scan HMAC token. No expiry window: if the scan_id
     * exists in the checkpoint, the kick is valid. This replaces the
     * old 5-minute token expiry.
     */
    public function makeKickToken(string $scan_id): string {
        $secret = $this->getKickSecret();
        return hash_hmac('sha256', $scan_id, $secret);
    }

    /**
     * Verify a kick token. Always returns true if the secret matches.
     * No time check.
     */
    public function verifyKickToken(string $scan_id, string $token): bool {
        $expected = $this->makeKickToken($scan_id);
        return hash_equals($expected, $token);
    }

    /**
     * Get the kick secret. Uses a file in the logs dir, or falls back
     * to a hardcoded fallback. Created on first use.
     */
    private function getKickSecret(): string {
        static $secret = null;
        if ($secret !== null) return $secret;

        $secret_file = (defined('CLEAN_SWEEP_LOGS_DIR') ? CLEAN_SWEEP_LOGS_DIR : __DIR__ . '/../../../logs/') . '.kick_secret';
        if (file_exists($secret_file)) {
            $secret = (string)@file_get_contents($secret_file);
            if (strlen($secret) >= 32) return $secret;
        }
        $secret = bin2hex(random_bytes(32));
        @file_put_contents($secret_file, $secret);
        return $secret;
    }
}
