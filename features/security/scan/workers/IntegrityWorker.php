<?php
/**
 * Clean Sweep - Integrity CleanSweep_Worker
 *
 * Phase 2: Core integrity baseline is ONLY meaningful after a successful
 * Core Reinstall (or comprehensive plugin reinstall) via Clean Sweep.
 * It is re-infection detection, not a general malware signal.
 *
 * Behaviour:
 *  - No baseline file → complete with note; do NOT invent violations.
 *  - Baseline exists → compare hashes; record each violation as a threat
 *    with source=integrity (separate from malware signature hits).
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_IntegrityWorker implements CleanSweep_Worker {

    public function type(): string {
        return CleanSweep_ScanWorkUnit::TYPE_INTEGRITY_CHECK;
    }

    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        $state = $ctx->state();
        $start_time = time();

        $boot = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 3) . '/')
            . 'includes/system/visit/bootstrap.php';
        if (is_readable($boot)) {
            require_once $boot;
        }
        $visit_sealed = class_exists('CleanSweep_VisitState')
            && !empty((new CleanSweep_VisitState())->load()['scopes']['core']['sealed']);

        $baseline_file = defined('CLEAN_SWEEP_ROOT')
            ? CLEAN_SWEEP_ROOT . 'backups/core_integrity_baseline.json'
            : dirname(__DIR__, 3) . '/backups/core_integrity_baseline.json';

        // Phase 2 §5.2: no baseline → not an error, not a violation.
        if (!$visit_sealed && !file_exists($baseline_file)) {
            $note = 'No trusted baseline is available (run Core Reinstall first for reinfection detection).';
            $ctx->mergeState([
                'has_integrity_baseline' => false,
                'integrity_violations' => 0,
                'integrity_baseline' => [
                    'available' => false,
                    'note' => $note,
                ],
            ]);
            return CleanSweep_WorkerResult::completed([
                'violations_found' => 0,
                'baseline_available' => false,
                'note' => $note,
                'duration_seconds' => time() - $start_time,
            ]);
        }

        if (!function_exists('clean_sweep_detect_site_root')) {
            require_once CLEAN_SWEEP_ROOT . 'features/maintenance/core-reinstall.php';
        }
        if (!function_exists('clean_sweep_check_for_reinfection')) {
            require_once CLEAN_SWEEP_ROOT . 'includes/system/CleanSweep_Integrity.php';
        }

        if (!function_exists('clean_sweep_check_for_reinfection')) {
            $note = 'Integrity check function unavailable.';
            $ctx->mergeState([
                'has_integrity_baseline' => true,
                'integrity_violations' => 0,
                'integrity_baseline' => [
                    'available' => true,
                    'note' => $note,
                ],
            ]);
            return CleanSweep_WorkerResult::completed([
                'violations_found' => 0,
                'baseline_available' => true,
                'note' => $note,
                'duration_seconds' => time() - $start_time,
            ]);
        }

        $violations = clean_sweep_check_for_reinfection();
        if (!is_array($violations)) {
            $violations = [];
        }
        $count = count($violations);

        // Record each violation as a distinct integrity threat (not malware).
        foreach ($violations as $i => $v) {
            if (!is_array($v)) {
                continue;
            }
            $file = (string)($v['file'] ?? $v['path'] ?? 'unknown');
            $severity = (string)($v['severity'] ?? 'critical');
            $risk = ($severity === 'warning' || $severity === 'medium') ? 'warning' : 'critical';

            $ctx->recordThreat([
                'id' => 'integrity_' . md5($file . '|' . ($v['type'] ?? '') . '|' . $i),
                'source' => 'integrity',
                'type' => (string)($v['type'] ?? 'modified'),
                'file' => $file,
                'pattern' => (string)($v['pattern'] ?? 'Core integrity baseline mismatch'),
                'match' => (string)($v['match'] ?? ''),
                'description' => strip_tags((string)($v['description'] ?? 'Core file differs from post-reinstall baseline')),
                'risk_level' => $risk,
                'severity' => $severity,
                'line_number' => null,
                // Keep raw for UI tooltips
                'integrity' => true,
                'scope' => (string)($v['scope'] ?? 'core'),
            ]);
        }

        if ($count > 0 && class_exists('CleanSweep_VisitStore')) {
            $store = new CleanSweep_VisitStore();
            foreach ($violations as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $store->state()->event('unexpected:core', (string)($v['file'] ?? ''));
            }
            if (class_exists('CleanSweep_Correlator')) {
                (new CleanSweep_Correlator($store))->run($violations, []);
            }
        }

        if ($count > 0) {
            $ctx->incrementCounter('integrity_violations', $count);
        }
        $ctx->mergeState([
            'has_integrity_baseline' => true,
            'integrity_baseline' => [
                'available' => true,
                'violations' => $count,
                'note' => $count > 0
                    ? "{$count} core file(s) differ from the trusted post-reinstall baseline (possible reinfection)."
                    : 'Core files match the trusted post-reinstall baseline.',
            ],
        ]);
        // Threats are recorded above; also bump the counter for status polls.
        if ($count > 0) {
            $ctx->incrementCounter('threats_found', $count);
        }

        return CleanSweep_WorkerResult::completed([
            'violations_found' => $count,
            'baseline_available' => true,
            'note' => $count > 0
                ? "{$count} integrity violation(s) against post-reinstall baseline"
                : 'Core integrity baseline clean',
            'duration_seconds' => time() - $start_time,
        ]);
    }
}
