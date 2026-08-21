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
        if ($state->scan_id) {
            require_once dirname(__DIR__) . '/ThreatStore.php';
            $store_count = (new CleanSweep_ThreatStore($state->scan_id))->count();
            if ($store_count > $threat_count) {
                $threat_count = $store_count;
            }
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
                $likely = (new CleanSweep_Correlator())->run($violations, $threats);
            }
        }

        $opts = $state->options ?? [];
        if ($likely) {
            $opts['likely_source'] = $likely;
        }
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
