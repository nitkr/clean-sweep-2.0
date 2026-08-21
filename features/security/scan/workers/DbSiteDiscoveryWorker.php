<?php
/**
 * Clean Sweep - Multisite DB site discovery
 *
 * Pages wp_blogs and enqueues per-site table segments. start() only
 * queues the current blog + globals so a 500-site network does not
 * stall the first request.
 *
 * @since CleanSweep_Scanner v2
 */
require_once dirname(__DIR__) . '/DbScanPlanner.php';
require_once dirname(__DIR__) . '/HostDetector.php';

final class CleanSweep_DbSiteDiscoveryWorker implements CleanSweep_Worker {

    public function type(): string {
        return CleanSweep_ScanWorkUnit::TYPE_DB_SITE_DISCOVERY;
    }

    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        global $wpdb;
        $profile = $ctx->profile();
        $state = $ctx->state();
        $queue = ($ctx instanceof CleanSweep_WorkerContextImpl) ? $ctx->queue() : null;

        if ($queue === null || !isset($wpdb) || $wpdb === null) {
            return CleanSweep_WorkerResult::completed(['skipped' => 'no_queue_or_wpdb']);
        }

        if (!CleanSweep_DbScanPlanner::is_multisite_target()) {
            return CleanSweep_WorkerResult::completed(['skipped' => 'not_multisite']);
        }

        $blogs_table = CleanSweep_DbScanPlanner::blogs_table();
        if (!CleanSweep_DbScanPlanner::table_exists($blogs_table)) {
            return CleanSweep_WorkerResult::completed(['skipped' => 'no_blogs_table']);
        }

        $last_blog_id = (int)($payload['last_blog_id'] ?? 0);
        $sites_done = (int)($payload['sites_done'] ?? 0);
        $skip = array_map('intval', $payload['skip_blog_ids'] ?? []);
        $skip = array_values(array_unique(array_filter($skip)));

        $max_sites = (int)$profile->get_max_multisite_sites();
        $per_tick = max(1, (int)$profile->get_multisite_sites_per_tick());

        if ($max_sites > 0 && $sites_done >= $max_sites) {
            return CleanSweep_WorkerResult::completed([
                'sites_done' => $sites_done,
                'capped' => true,
            ]);
        }

        $limit = $per_tick;
        if ($max_sites > 0) {
            $limit = min($limit, $max_sites - $sites_done);
        }

        $filters = 'deleted = 0 AND spam = 0';
        if (!$profile->include_archived_sites()) {
            $filters .= ' AND archived = 0';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT blog_id FROM `{$blogs_table}`
             WHERE blog_id > %d AND {$filters}
             ORDER BY blog_id ASC
             LIMIT %d",
            $last_blog_id,
            $limit + count($skip) + 5
        ));

        $host = new CleanSweep_HostDetector();
        $enqueued_sites = 0;
        $segments = 0;
        $new_last = $last_blog_id;
        $seen = 0;

        $queue->begin_batch($state->scan_id);
        try {
            foreach ($rows as $row) {
                $blog_id = (int)$row->blog_id;
                $new_last = $blog_id;
                if (in_array($blog_id, $skip, true)) {
                    continue;
                }
                if ($max_sites > 0 && ($sites_done + $enqueued_sites) >= $max_sites) {
                    break;
                }
                $prefix = CleanSweep_DbScanPlanner::blog_prefix($blog_id);
                $segments += CleanSweep_DbScanPlanner::enqueue_blog_tables(
                    $queue,
                    $state->scan_id,
                    $prefix,
                    $profile,
                    $host
                );
                $enqueued_sites++;
                $seen++;
                if ($seen >= $per_tick) {
                    break;
                }
                if ($ctx->shouldStop()) {
                    break;
                }
            }
        } finally {
            $queue->end_batch($state->scan_id);
        }

        $sites_done += $enqueued_sites;

        clean_sweep_log_message(
            "CleanSweep_DbSiteDiscoveryWorker: +{$enqueued_sites} sites, {$segments} segments (last_blog={$new_last}, done={$sites_done})",
            'info'
        );

        $more = false;
        if ($new_last > $last_blog_id && ($max_sites === 0 || $sites_done < $max_sites)) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $next = $wpdb->get_var($wpdb->prepare(
                "SELECT blog_id FROM `{$blogs_table}`
                 WHERE blog_id > %d AND {$filters}
                 ORDER BY blog_id ASC
                 LIMIT 1",
                $new_last
            ));
            $more = !empty($next);
        }

        if ($more && !$ctx->shouldStop()) {
            return CleanSweep_WorkerResult::moreWork([
                'sites_enqueued' => $enqueued_sites,
                'segments' => $segments,
                'sites_done' => $sites_done,
                'last_blog_id' => $new_last,
                'follow_on_payload' => [
                    'last_blog_id' => $new_last,
                    'sites_done' => $sites_done,
                    'skip_blog_ids' => $skip,
                ],
            ]);
        }

        return CleanSweep_WorkerResult::completed([
            'sites_enqueued' => $enqueued_sites,
            'segments' => $segments,
            'sites_done' => $sites_done,
            'last_blog_id' => $new_last,
        ]);
    }
}
