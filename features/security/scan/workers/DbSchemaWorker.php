<?php
/**
 * One catalog lookup for MySQL triggers, events, and stored routines.
 * Runs once at the start of a database scan, not once per table row.
 */
require_once dirname(__DIR__) . '/DbSchemaPersistence.php';

final class CleanSweep_DbSchemaWorker implements CleanSweep_Worker {

    public function type(): string {
        return CleanSweep_ScanWorkUnit::TYPE_DB_SCHEMA;
    }

    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        $started = time();
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return CleanSweep_WorkerResult::completed([
                'skipped' => true,
                'note' => 'wpdb unavailable',
                'duration_seconds' => time() - $started,
            ]);
        }

        $report = (new CleanSweep_DbSchemaPersistence())->scan($wpdb);
        $threats = $report['threats'] ?? [];
        foreach ($threats as $threat) {
            if (is_array($threat)) {
                $ctx->recordThreat($threat);
            }
        }
        $found = count($threats);
        if ($found > 0) {
            $ctx->incrementCounter('threats_found', $found);
        }
        $ctx->mergeState(['phase' => 'database']);

        $counts = $report['counts'] ?? [];
        $unread = array_keys($report['unread'] ?? []);
        clean_sweep_log_message(
            'CleanSweep_DbSchemaWorker: triggers=' . (int) ($counts['triggers'] ?? 0)
            . ' events=' . (int) ($counts['events'] ?? 0)
            . ' routines=' . (int) ($counts['routines'] ?? 0)
            . ' findings=' . $found
            . ($unread !== [] ? ' unread=' . implode(',', $unread) : ''),
            $unread !== [] ? 'warning' : 'info'
        );

        return CleanSweep_WorkerResult::completed([
            'findings' => $found,
            'triggers' => (int) ($counts['triggers'] ?? 0),
            'events' => (int) ($counts['events'] ?? 0),
            'routines' => (int) ($counts['routines'] ?? 0),
            'unread' => $unread,
            'duration_seconds' => time() - $started,
        ]);
    }
}
