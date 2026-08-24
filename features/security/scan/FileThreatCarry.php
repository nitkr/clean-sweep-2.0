<?php
/**
 * Carry previous file-signature hits into this scan when differential skip
 * certified the file unchanged (same bytes as last hash).
 *
 * Sources include completed, cancelled, and failed scans in the same hash
 * universe. Cancel does not roll hashes back, so cancelled JSONL is a valid
 * source. Does not carry database or integrity findings.
 */
final class CleanSweep_FileThreatCarry {

    /**
     * @param bool $require_skips Finalize path: no-op unless this run hash-skipped files.
     *                            Start path passes false so live preview can seed immediately.
     * @param CleanSweep_DifferentialScanner|null $diff Test injection.
     * @param list<CleanSweep_ScanState>|null $previous Test injection (skip checkpoint glob).
     * @return array{carried:int, from_scan_id:?string, from_profile:?string}
     */
    public static function apply(
        CleanSweep_ScanState $state,
        bool $require_skips = true,
        $diff = null,
        ?array $previous = null
    ): array {
        $empty = ['carried' => 0, 'from_scan_id' => null, 'from_profile' => null];
        $scan_id = (string) ($state->scan_id ?? '');
        if ($scan_id === '') {
            return $empty;
        }
        $opts = is_array($state->options ?? null) ? $state->options : [];
        if (array_key_exists('want_files', $opts) && empty($opts['want_files'])) {
            return $empty;
        }
        if (!empty($opts['fresh_scan'])) {
            return $empty;
        }
        if ($require_skips && (int) $state->files_skipped_unchanged <= 0) {
            return $empty;
        }

        require_once __DIR__ . '/Checkpoint.php';
        require_once __DIR__ . '/ThreatStore.php';
        require_once __DIR__ . '/ScannedPathStore.php';
        require_once dirname(__DIR__) . '/DifferentialScanner.php';

        if ($previous === null) {
            $previous = CleanSweep_Checkpoint::findPreviousForCarry(
                $scan_id,
                (string) ($state->profile_id ?? '')
            );
        }
        if ($previous === []) {
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

        if (!$diff instanceof CleanSweep_DifferentialScanner) {
            $diff = new CleanSweep_DifferentialScanner(null, false);
            $diff->set_enabled(true);
            $diff->set_profile_id((string) ($state->profile_id ?? 'deep'));
        } else {
            $diff->set_enabled(true);
        }

        $carried = [];
        $from_id = null;
        $from_profile = null;
        foreach ($previous as $prev) {
            if (!$prev instanceof CleanSweep_ScanState || empty($prev->scan_id) || $prev->scan_id === $scan_id) {
                continue;
            }
            $prev_threats = (new CleanSweep_ThreatStore($prev->scan_id))->all();
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
                if (!self::inScanScope($state, $abs)) {
                    continue;
                }
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
                if ($from_id === null) {
                    $from_id = $prev->scan_id;
                    $from_profile = $prev->profile_id;
                }
            }
        }

        if ($carried === []) {
            return [
                'carried' => 0,
                'from_scan_id' => $from_id,
                'from_profile' => $from_profile,
            ];
        }

        $this_store->appendMany($carried);
        return [
            'carried' => count($carried),
            'from_scan_id' => $from_id,
            'from_profile' => $from_profile,
        ];
    }

    /**
     * Path-scoped Deep scans must not inherit hits from outside the seed tree.
     */
    private static function inScanScope(CleanSweep_ScanState $state, string $abs): bool {
        $opts = is_array($state->options ?? null) ? $state->options : [];
        if (empty($opts['path_scoped'])) {
            return true;
        }
        $seeds = $opts['resolved_seeds'] ?? [];
        if (!is_array($seeds) || $seeds === []) {
            return true;
        }
        $abs_n = rtrim(str_replace('\\', '/', $abs), '/');
        foreach ($seeds as $seed) {
            $s = rtrim(str_replace('\\', '/', (string) $seed), '/');
            if ($s === '') {
                continue;
            }
            if ($abs_n === $s || str_starts_with($abs_n, $s . '/')) {
                return true;
            }
        }
        return false;
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
