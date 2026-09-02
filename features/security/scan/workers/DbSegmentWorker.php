<?php
/**
 * Clean Sweep - Database Segment CleanSweep_Worker
 *
 * Scans a single ID-range segment of one database table. The orchestrator
 * (CleanSweep_Scanner) decides how to segment; this worker just runs one segment
 * and reports what happened.
 *
 * Extracted from EpisodeRunner::execute_db_table_segment() + the
 * DatabaseScanner::scan_table_segment() method.
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_DbSegmentWorker implements CleanSweep_Worker {

    public function type(): string {
        return CleanSweep_ScanWorkUnit::TYPE_DB_TABLE_SEGMENT;
    }

    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        global $wpdb;
        $state = $ctx->state();
        $profile = $ctx->profile();

        $table        = $payload['table'] ?? ($wpdb->prefix . 'posts');
        $id_column    = $payload['id_column'] ?? 'ID';
        $start_id     = (int)($payload['start_id'] ?? 0);
        $end_id       = (int)($payload['end_id'] ?? 0);
        $where_extra  = $payload['where_clause'] ?? null;

        clean_sweep_log_message("CleanSweep_DbSegmentWorker: {$table} {$start_id}..{$end_id}", 'debug');

        require_once dirname(__DIR__, 2) . '/content-scanners/DatabaseScanner.php';
        if (!function_exists('clean_sweep_get_malware_signatures')) {
            require_once dirname(__DIR__, 2) . '/signatures.php';
        }
        require_once dirname(__DIR__, 2) . '/ThreatCollector.php';

        $throttle = $ctx->throttle();
        $db_scanner = new CleanSweep_DatabaseScanner($profile, $throttle);
        $db_scanner->set_signatures(clean_sweep_get_malware_signatures()->get_signatures());
        $db_scanner->set_checkpoint_interval($profile->get_checkpoint_interval());
        $db_scanner->set_profile_options(
            $profile->get_pause_threshold(),
            $profile->get_phase_time_limit('database'),
            $profile->should_disable_heavy_analysis()
        );
        $collector = new CleanSweep_ThreatCollector($profile->get_batch_size('db_rows') * 2);
        $db_scanner->set_collector($collector);
        $db_scanner->set_context($ctx);

        // Wire collector to CleanSweep_ThreatStore so flush() actually persists
        $collector->set_threat_store(new CleanSweep_ThreatStore($state->scan_id));

        // Progress callback updates the ctx counter
        $last_progress = 0;
        $progress_cb = function($current, $total, $message, $progress = null) use ($ctx, &$last_progress) {
            $delta = $current - $last_progress;
            if ($delta > 0) {
                $ctx->incrementCounter('items_processed', $delta);
            }
            $last_progress = $current;
            $ctx->progress($current, $total, $message);
        };
        $db_scanner->set_progress_callback($progress_cb);

        $start_time = time();
        // Exclusive cursor: planner writes start_id-1 so the first ID is included.
        // Older units that omitted last_processed_id still start at start_id-1.
        $cursor = array_key_exists('last_processed_id', $payload)
            ? (int)$payload['last_processed_id']
            : ($start_id - 1);

        $skip_ids = CleanSweep_DatabaseScanner::normalize_skip_ids($payload['poison_ids'] ?? []);
        if (($state->last_db_table ?? '') === $table) {
            $in_progress = (int) ($state->db_in_progress_id ?? 0);
            $checkpoint_id = (int) ($state->last_db_id ?? 0);
            if ($in_progress > 0) {
                $skip_ids[$in_progress] = true;
            }
            if ($checkpoint_id > $cursor && $checkpoint_id !== $in_progress) {
                $cursor = $checkpoint_id;
            }
        }

        $result = $db_scanner->scan_table_segment($table, $id_column, $cursor, $end_id, $where_extra, [
            'skip_ids' => $skip_ids,
        ]);

        // Flush any buffered threats
        $collector->flush();

        $items = (int)($result['scanned'] ?? 0);
        $threats = (int)($result['threats_found'] ?? 0);
        $last_processed = (int)($result['last_id'] ?? $cursor);

        // Persist cumulative counters (sole owner)
        $ctx->incrementCounter('db_rows_scanned', $items);
        if ($threats > 0) {
            $ctx->incrementCounter('threats_found', $threats);
        }
        $poison_map = CleanSweep_DatabaseScanner::normalize_skip_ids($payload['poison_ids'] ?? []);
        foreach (CleanSweep_DatabaseScanner::normalize_skip_ids($result['poison_ids'] ?? []) as $pid => $on) {
            if ($on) {
                $poison_map[$pid] = true;
            }
        }
        $poison = array_keys($poison_map);
        sort($poison, SORT_NUMERIC);
        if (count($poison) > 50) {
            $poison = array_slice($poison, -50);
        }

        $ctx->mergeState([
            'phase' => 'database',
            'last_db_id' => $last_processed,
            'last_db_table' => $table,
            'db_in_progress_id' => null,
        ]);

        $this->enqueueRevisionFollowOn($ctx, $payload, $table, $result);

        // If the scanner wanted a pause, return moreWork and let the
        // orchestrator enqueue a follow-on segment.
        if ($db_scanner->needs_continuation()) {
            $db_scanner->reset_pause_flag();
            return CleanSweep_WorkerResult::moreWork([
                'items_processed' => $items,
                'threats' => $threats,
                'last_id' => $last_processed,
                'table' => $table,
                'duration_seconds' => time() - $start_time,
                'follow_on_payload' => array_merge($payload, [
                    'last_processed_id' => $last_processed,
                    'poison_ids' => $poison,
                ]),
            ]);
        }

        return CleanSweep_WorkerResult::completed([
            'items_processed' => $items,
            'threats' => $threats,
            'last_id' => $last_processed,
            'table' => $table,
            'duration_seconds' => time() - $start_time,
        ]);
    }

    /**
     * When a live post is flagged, queue its revisions/autosaves so a
     * later rollback cannot restore the payload. Skipped when this unit
     * is already a revision follow-on.
     */
    private function enqueueRevisionFollowOn(CleanSweep_WorkerContext $ctx, array $payload, string $table, array $result): void {
        if (!empty($payload['skip_revision_follow_on'])) {
            return;
        }
        if ($ctx->profile()->include_revisions_in_main_walk()) {
            return;
        }
        $flagged = $result['flagged_post_ids'] ?? [];
        if (empty($flagged) || !preg_match('/(^|_)posts$/', $table)) {
            return;
        }
        $queue = ($ctx instanceof CleanSweep_WorkerContextImpl) ? $ctx->queue() : null;
        if ($queue === null) {
            return;
        }
        require_once dirname(__DIR__) . '/DbScanPlanner.php';
        require_once dirname(__DIR__) . '/HostDetector.php';
        $n = CleanSweep_DbScanPlanner::enqueue_revisions(
            $queue,
            $ctx->state()->scan_id,
            $table,
            $flagged,
            $ctx->profile(),
            new CleanSweep_HostDetector()
        );
        if ($n > 0) {
            clean_sweep_log_message(
                "CleanSweep_DbSegmentWorker: queued {$n} revision segment(s) for " . count($flagged) . " flagged post(s) on {$table}",
                'info'
            );
        }
    }
}
