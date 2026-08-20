<?php
/**
 * Load visit engine. Safe to include from API bootstrap and clean-sweep.php.
 */
$visit_dir = __DIR__;
require_once $visit_dir . '/VisitCapabilities.php';
require_once $visit_dir . '/VisitState.php';
require_once $visit_dir . '/SelfCheck.php';
require_once $visit_dir . '/ScopeSealer.php';
require_once $visit_dir . '/Snapshot.php';
require_once $visit_dir . '/VisitStore.php';
require_once $visit_dir . '/Census.php';
require_once $visit_dir . '/Correlator.php';
require_once $visit_dir . '/SnapshotCompare.php';
require_once $visit_dir . '/VisitSignals.php';
require_once $visit_dir . '/VisitWatch.php';

/**
 * Run self-check and persist last result on CleanSweep_VisitState.
 */
/**
 * Block remediations / snapshot export when the toolkit itself is compromised.
 * Kind A (extras) and kind B (patched) both refuse writes.
 */
function clean_sweep_require_toolkit_ok(): void {
    $info = clean_sweep_toolkit_integrity();
    $kind = $info['kind'] ?? 'ok';
    if ($kind === 'ok' || $kind === 'no_manifest') {
        return;
    }
    if (class_exists('CleanSweep_ApiResponse')) {
        $msg = $kind === 'patched'
            ? 'Shipped Clean Sweep files were modified. Delete this folder and re-upload the original zip. Remediations are paused.'
            : 'Unexpected files were found inside Clean Sweep. Remediations are paused. Re-upload Clean Sweep after you note the extra files.';
        CleanSweep_ApiResponse::sendError($msg, 'TOOLKIT_INTEGRITY', [
            'kind' => $kind,
            'extras' => $info['extras'] ?? [],
            'patched' => $info['patched'] ?? [],
        ]);
    }
}

function clean_sweep_seal_plugin_dir(string $slug, string $dir): void {
    if ($slug === '' || !is_dir($dir) || !class_exists('CleanSweep_ScopeSealer')) {
        return;
    }
    try {
        (new CleanSweep_ScopeSealer())->seal_package('plugin', $slug, $dir);
    } catch (Throwable $e) {
        if (function_exists('clean_sweep_log_message')) {
            clean_sweep_log_message('seal plugin failed: ' . $e->getMessage(), 'warning');
        }
    }
}

/**
 * Tell Live Watch that Clean Sweep is about to write site files.
 * Fail-open: no-op when watch is off or the class is not loaded.
 *
 * @param string   $op       plugin_reinstall|theme_reinstall|core_reinstall|scan
 * @param string[] $prefixes relative path prefixes this operation may touch
 * @param int      $ttl      seconds; 0 uses VisitWatch default
 * @param array    $meta     optional {detail:string}
 */
function clean_sweep_watch_note_operation(string $op, array $prefixes = [], int $ttl = 0, array $meta = []): void {
    if (!class_exists('CleanSweep_VisitWatch', false)) {
        return;
    }
    try {
        (new CleanSweep_VisitWatch())->note_operation($op, $prefixes, $ttl, $meta);
    } catch (Throwable $e) {
        // Never block remediations on watch tagging.
    }
}

function clean_sweep_seal_theme_dir(string $slug, string $dir): void {
    if ($slug === '' || !is_dir($dir) || !class_exists('CleanSweep_ScopeSealer')) {
        return;
    }
    try {
        (new CleanSweep_ScopeSealer())->seal_package('theme', $slug, $dir);
    } catch (Throwable $e) {
        if (function_exists('clean_sweep_log_message')) {
            clean_sweep_log_message('seal theme failed: ' . $e->getMessage(), 'warning');
        }
    }
}

function clean_sweep_toolkit_integrity(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $check = new CleanSweep_SelfCheck();
    $cached = $check->run();
    try {
        $kind = $cached['kind'] ?? 'ok';
        if ($kind === 'ok') {
            return $cached;
        }
        $state = new CleanSweep_VisitState();
        $code = 'self-check:' . $kind;
        $detail = '';
        if (!empty($cached['extras'])) {
            $detail = $cached['extras'][0]['path'] ?? '';
            if (count($cached['extras']) > 1) {
                $detail .= ' +' . (count($cached['extras']) - 1);
            }
        } elseif (!empty($cached['patched'])) {
            $detail = $cached['patched'][0]['path'] ?? '';
        }
        $state->event($code, $detail);
    } catch (Throwable $e) {
        // Never block boot on event write.
    }
    return $cached;
}
