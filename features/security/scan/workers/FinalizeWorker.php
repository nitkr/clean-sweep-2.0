<?php
/**
 * Clean Sweep - Finalize CleanSweep_Worker
 *
 * Runs once at the end of every scan. Marks the scan as completed
 * and produces the final results envelope.
 *
 * Extracted from EpisodeRunner::execute_finalization().
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_FinalizeWorker implements CleanSweep_Worker {

    public function type(): string {
        return CleanSweep_ScanWorkUnit::TYPE_FINALIZATION;
    }

    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        $state = $ctx->state();
        $start_time = time();

        // Compute final cumulative threat count from the CleanSweep_ThreatStore
        // (authoritative) and the in-memory counter (fast).
        $threat_count = $state->threats_found;
        $carry = ['carried' => 0, 'from_scan_id' => null, 'from_profile' => null];
        if ($state->scan_id) {
            require_once dirname(__DIR__) . '/ThreatStore.php';
            require_once dirname(__DIR__) . '/FileThreatCarry.php';
            $carry = CleanSweep_FileThreatCarry::apply($state);
            if (!empty($carry['carried'])) {
                $ctx->incrementCounter('threats_found', (int) $carry['carried']);
            }
            $store_count = (new CleanSweep_ThreatStore($state->scan_id))->count();
            $threat_count = max($store_count, (int) $state->threats_found);
        }

        // Mark complete. The orchestrator will see the completed status
        // on the next status() call.
        $likely = null;
        $boot = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 3) . '/')
            . 'includes/system/visit/bootstrap.php';
        if (is_readable($boot)) {
            require_once $boot;
            if (class_exists('CleanSweep_Correlator') && class_exists('CleanSweep_ScopeSealer')) {
                $violations = (new CleanSweep_ScopeSealer())->compare_sealed();
                $threats = [];
                if ($state->scan_id) {
                    $threats = (new CleanSweep_ThreatStore($state->scan_id))->all();
                }
                $likely = (new CleanSweep_Correlator())->run($violations, $threats, [], false);
                if (!is_array($likely) || (empty($likely['reinfection']) && empty($likely['core_changed']))) {
                    $likely = null;
                }
            }
        }

        $opts = $state->options ?? [];
        // Always write: null clears VisitStore leftovers planted on older builds.
        $opts['likely_source'] = $likely;
        $prior_carry = is_array($opts['file_carry'] ?? null) ? $opts['file_carry'] : [];
        $opts['file_carry'] = [
            'carried' => (int) ($prior_carry['carried'] ?? 0) + (int) ($carry['carried'] ?? 0),
            'from_scan_id' => $carry['from_scan_id'] ?? ($prior_carry['from_scan_id'] ?? null),
            'from_profile' => $carry['from_profile'] ?? ($prior_carry['from_profile'] ?? null),
            'files_scanned' => (int) $state->files_scanned,
            'files_visited' => (int) $state->files_visited,
            'files_skipped_unchanged' => (int) $state->files_skipped_unchanged,
        ];
        $ctx->mergeState([
            'status' => 'completed',
            'phase' => 'complete',
            'finished_at' => time(),
            'threats_found' => $threat_count,
            'options' => $opts,
        ]);

        return CleanSweep_WorkerResult::completed([
            'status' => 'completed',
            'phase' => 'complete',
            'total_threats' => $threat_count,
            'duration_seconds' => time() - $start_time,
        ]);
    }
}
