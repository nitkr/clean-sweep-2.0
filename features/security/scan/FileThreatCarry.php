<?php
/**
 * Carry previous file-signature hits into this scan when differential skip
 * certified the file unchanged (same bytes as last hash).
 *
 * Does not carry database or integrity findings.
 */
final class CleanSweep_FileThreatCarry {

    /**
     * @return array{carried:int, from_scan_id:?string, from_profile:?string}
     */
    public static function apply(CleanSweep_ScanState $state): array {
        $empty = ['carried' => 0, 'from_scan_id' => null, 'from_profile' => null];
        $scan_id = (string) ($state->scan_id ?? '');
        if ($scan_id === '') {
            return $empty;
        }
        if ((int) $state->files_skipped_unchanged <= 0) {
            return $empty;
        }

        require_once __DIR__ . '/Checkpoint.php';
        $prev = CleanSweep_Checkpoint::findPreviousCompleted($scan_id, (string) ($state->profile_id ?? ''));
        if ($prev === null || empty($prev->scan_id) || $prev->scan_id === $scan_id) {
            return $empty;
        }

        require_once __DIR__ . '/ThreatStore.php';
        require_once __DIR__ . '/ScannedPathStore.php';
        require_once dirname(__DIR__) . '/DifferentialScanner.php';

        $prev_threats = (new CleanSweep_ThreatStore($prev->scan_id))->all();
        if ($prev_threats === []) {
            return $empty;
        }

        $rescanned = (new CleanSweep_ScannedPathStore($scan_id))->allKeys();
        $this_store = new CleanSweep_ThreatStore($scan_id);
        $existing_ids = [];
        $this_store->stream(static function ($t) use (&$existing_ids) {
            if (is_array($t) && !empty($t['id'])) {
                $existing_ids[(string) $t['id']] = true;
            }
        });

        $diff = new CleanSweep_DifferentialScanner(null, false);
        $diff->set_enabled(true);
        $diff->set_profile_id((string) ($state->profile_id ?? 'deep'));

        $carried = [];
        foreach ($prev_threats as $t) {
            if (!is_array($t)) {
                continue;
            }
            if (!self::isFileSignature($t)) {
                continue;
            }
            $path = self::threatPath($t);
            if ($path === '') {
                continue;
            }
            $norm = str_replace('\\', '/', $path);
            if (isset($rescanned[$norm]) || isset($rescanned[basename($norm)])) {
                continue;
            }
            $id = (string) ($t['id'] ?? '');
            if ($id !== '' && isset($existing_ids[$id])) {
                continue;
            }
            if (!is_file($path) && !is_file($norm)) {
                continue;
            }
            $abs = is_file($path) ? $path : $norm;
            $hash = CleanSweep_DifferentialScanner::hash_file($abs);
            if ($hash === null || !$diff->hash_matches_manifest($abs, $hash)) {
                continue;
            }
            $t['carried_forward'] = true;
            $t['carried_from_scan_id'] = $prev->scan_id;
            $carried[] = $t;
            if ($id !== '') {
                $existing_ids[$id] = true;
            }
        }

        if ($carried === []) {
            return [
                'carried' => 0,
                'from_scan_id' => $prev->scan_id,
                'from_profile' => $prev->profile_id,
            ];
        }

        $this_store->appendMany($carried);
        return [
            'carried' => count($carried),
            'from_scan_id' => $prev->scan_id,
            'from_profile' => $prev->profile_id,
        ];
    }

    private static function isFileSignature(array $t): bool {
        $src = (string) ($t['source'] ?? '');
        if ($src === 'database' || $src === 'integrity') {
            return false;
        }
        if (!empty($t['checksum']) || !empty($t['integrity'])) {
            return false;
        }
        return $src === 'file' || $src === '' || isset($t['file']) || isset($t['path']);
    }

    private static function threatPath(array $t): string {
        $file = (string) ($t['file'] ?? '');
        $path = (string) ($t['path'] ?? '');
        if ($file !== '' && ($file[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $file))) {
            return $file;
        }
        if ($path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path))) {
            return $path;
        }
        return $file !== '' ? $file : $path;
    }
}
