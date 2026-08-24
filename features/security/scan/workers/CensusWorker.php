<?php
/**
 * Chunked visit census on the scan work queue.
 */
final class CleanSweep_CensusWorker implements CleanSweep_Worker {

    public function type(): string {
        return CleanSweep_ScanWorkUnit::TYPE_VISIT_CENSUS;
    }

    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        $started = time();
        $boot = (defined('CLEAN_SWEEP_ROOT') ? CLEAN_SWEEP_ROOT : dirname(__DIR__, 3) . '/')
            . 'includes/system/visit/bootstrap.php';
        if (is_readable($boot)) {
            require_once $boot;
        }
        if (!class_exists('CleanSweep_Census')) {
            return CleanSweep_WorkerResult::completed(['skipped' => true, 'note' => 'CleanSweep_Census unavailable']);
        }

        $phase = (string) ($payload['phase'] ?? 'site_owned');
        $offset = (int) ($payload['offset'] ?? 0);
        $ctx->mergeState(['phase' => 'visit_census']);
        $census = new CleanSweep_Census();
        $result = $census->run_phase($phase, $offset, $payload, $ctx);
        $ctx->progress(1, 4, 'Visit census: ' . ($result['phase'] ?? $phase));

        if (!empty($result['cancelled'])) {
            return CleanSweep_WorkerResult::completed([
                'cancelled' => true,
                'phase' => $phase,
                'count' => $result['count'] ?? 0,
                'duration_seconds' => time() - $started,
            ]);
        }

        $follow = $result['follow_on_payload'] ?? null;
        if (is_array($follow) && $follow !== []) {
            return CleanSweep_WorkerResult::moreWork([
                'phase' => $follow['phase'] ?? $phase,
                'count' => $result['count'] ?? 0,
                'duration_seconds' => time() - $started,
                'follow_on_payload' => $follow,
            ]);
        }

        if (empty($result['done']) && !empty($result['next'])) {
            return CleanSweep_WorkerResult::moreWork([
                'phase' => $phase,
                'follow_on_payload' => [
                    'phase' => $result['next'],
                    'offset' => (int) ($result['offset'] ?? 0),
                ],
                'count' => $result['count'] ?? 0,
                'duration_seconds' => time() - $started,
            ]);
        }

        $next = $result['next'] ?? null;
        if ($next) {
            return CleanSweep_WorkerResult::moreWork([
                'phase' => $phase,
                'follow_on_payload' => [
                    'phase' => $next,
                    'offset' => 0,
                ],
                'count' => $result['count'] ?? 0,
                'duration_seconds' => time() - $started,
            ]);
        }

        return CleanSweep_WorkerResult::completed([
            'phase' => $phase,
            'count' => $result['count'] ?? 0,
            'duration_seconds' => time() - $started,
        ]);
    }
}
