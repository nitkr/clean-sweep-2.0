<?php
/**
 * Compare every installed wordpress.org plugin to official checksums.
 * Themes have no wordpress.org checksum API — identity / zip baseline only.
 * Quick / Standard / Deep all walk the full installed set. Premium/custom → unavailable.
 */
require_once dirname(__DIR__) . '/PackageChecksums.php';

final class CleanSweep_PackageChecksumWorker implements CleanSweep_Worker {

    public function type(): string {
        return CleanSweep_ScanWorkUnit::TYPE_PACKAGE_CHECKSUM;
    }

    /** Reuse window for same-version package_checksums_latest.json (6 hours). */
    private const REUSE_TTL = 21600;

    public function run(array $payload, CleanSweep_WorkerContext $ctx): CleanSweep_WorkerResult {
        $started = time();
        $profile = $ctx->profile();
        $all = CleanSweep_PackageChecksums::list_targets(false);
        $start = max(0, (int) ($payload['start'] ?? 0));
        $per = 6;
        $slice = array_slice($all, $start, $per);
        $force = !empty($payload['force'])
            || (method_exists($profile, 'get_profile_id') && $profile->get_profile_id() === 'deep');

        if (empty($all)) {
            $this->enqueue_package_trees($ctx, $profile);
            return CleanSweep_WorkerResult::completed([
                'skipped' => true,
                'note' => 'No plugins/themes to checksum (WordPress APIs unavailable?)',
                'duration_seconds' => time() - $started,
            ]);
        }

        // Fresh same-version results: skip re-hash, still expand package trees.
        if ($start === 0 && !$force && CleanSweep_PackageChecksums::can_reuse_latest($all, self::REUSE_TTL)) {
            $this->enqueue_package_trees($ctx, $profile);
            $note = sprintf(
                'Package checksums: reused prior results for %d packages (cache < %dh)',
                count($all),
                (int) (self::REUSE_TTL / 3600)
            );
            $ctx->mergeState([
                'options' => array_merge($ctx->state()->options ?? [], [
                    'package_checksum_note' => $note,
                    'package_checksum_reused' => true,
                ]),
            ]);
            clean_sweep_log_message("CleanSweep_PackageChecksumWorker: {$note}", 'info');
            return CleanSweep_WorkerResult::completed([
                'skipped' => true,
                'reused' => true,
                'total_packages' => count($all),
                'note' => $note,
                'duration_seconds' => time() - $started,
            ]);
        }

        $latest = $start === 0 ? [] : CleanSweep_PackageChecksums::load_latest();
        $findings = 0;
        $checked_pkgs = 0;

        foreach ($slice as $pkg) {
            if ($ctx->shouldStop()) {
                break;
            }
            $result = CleanSweep_PackageChecksums::check_package($pkg);
            $key = ($pkg['type'] ?? 'plugin') . ':' . ($pkg['slug'] ?? '');
            $latest[$key] = [
                'type' => $pkg['type'] ?? 'plugin',
                'slug' => $pkg['slug'] ?? '',
                'name' => $pkg['name'] ?? '',
                'version' => $pkg['version'] ?? '',
                'status' => $result['status'],
                'outcome' => $result['outcome'] ?? null,
                'checked' => $result['checked'],
                'finding_count' => count($result['findings']),
                'note' => $result['note'],
                'dir' => $result['dir'] ?? rtrim(str_replace('\\', '/', (string) ($pkg['dir'] ?? '')), '/') . '/',
                'official_ok' => $result['official_ok'] ?? [],
                'metrics' => $result['metrics'] ?? null,
                'annotations' => $result['annotations'] ?? null,
                'baseline_used' => !empty($result['baseline_used']),
            ];
            foreach ($result['findings'] as $threat) {
                $ctx->recordThreat($threat);
                $findings++;
            }
            $checked_pkgs++;
        }

        CleanSweep_PackageChecksums::save_latest($latest);

        if ($findings > 0) {
            $ctx->incrementCounter('threats_found', $findings);
            $ctx->incrementCounter('integrity_violations', $findings);
        }

        $next = $start + $per;
        $note = sprintf(
            'Package checksums: %d/%d packages (all installed)',
            min($next, count($all)),
            count($all)
        );
        $ctx->mergeState([
            'options' => array_merge($ctx->state()->options ?? [], [
                'package_checksum_note' => $note,
            ]),
        ]);

        clean_sweep_log_message("CleanSweep_PackageChecksumWorker: {$note}, {$findings} finding(s)", 'info');

        if ($next < count($all) && !$ctx->shouldStop()) {
            return CleanSweep_WorkerResult::moreWork([
                'checked_packages' => $checked_pkgs,
                'findings' => $findings,
                'follow_on_payload' => ['start' => $next],
                'duration_seconds' => time() - $started,
            ]);
        }

        if (!$ctx->shouldStop()) {
            $this->enqueue_package_trees($ctx, $profile);
        }

        return CleanSweep_WorkerResult::completed([
            'checked_packages' => $start + $checked_pkgs,
            'total_packages' => count($all),
            'findings' => $findings,
            'note' => $note,
            'duration_seconds' => time() - $started,
        ]);
    }

    /**
     * Start plugins/ and themes/ walks only after checksums so verified
     * official files can be skipped. Loose files (funcfile.php) still scan.
     */
    private function enqueue_package_trees(CleanSweep_WorkerContext $ctx, CleanSweep_ScanProfile $profile): void {
        $queue = ($ctx instanceof CleanSweep_WorkerContextImpl) ? $ctx->queue() : null;
        if ($queue === null) {
            return;
        }
        require_once dirname(__DIR__) . '/SitePaths.php';
        $content = CleanSweep_SitePaths::content_dir();
        if (!$content) {
            return;
        }
        $scan_id = $ctx->state()->scan_id;
        foreach (['plugins', 'themes'] as $seg) {
            $dir = $content . $seg;
            if (!is_dir($dir)) {
                continue;
            }
            $budget = (int) $profile->get_tree_max_depth($dir);
            $from = (int) $profile->content_relative_depth($dir);
            $remain = max(0, $budget - $from);
            $queue->enqueue(CleanSweep_ScanWorkUnit::create(
                $scan_id,
                CleanSweep_ScanWorkUnit::TYPE_FILE_DISCOVERY,
                [
                    'base_dir' => $dir,
                    'start_path' => $dir,
                    'max_depth' => $remain,
                    'use_checksum_skip' => true,
                ],
                120
            ));
        }
        clean_sweep_log_message('CleanSweep_PackageChecksumWorker: enqueued plugins/themes discovery after checksums', 'info');
    }
}
