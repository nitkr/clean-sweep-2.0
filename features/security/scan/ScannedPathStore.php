<?php
/**
 * Paths that were signature-scanned (not hash-skipped) for a scan_id.
 * Used at finalize to avoid carrying old hits for files this run re-checked.
 */
final class CleanSweep_ScannedPathStore {

    /** @var string */
    private $file;

    public function __construct(string $scan_id) {
        $logs = defined('CLEAN_SWEEP_PROGRESS_DIR') ? CLEAN_SWEEP_PROGRESS_DIR : __DIR__ . '/../../../logs/';
        $this->file = rtrim($logs, '/') . '/scan_' . $scan_id . '_scanned.jsonl';
    }

    /**
     * @param array<int,string> $paths
     */
    public function appendMany(array $paths): void {
        $lines = [];
        foreach ($paths as $p) {
            if (!is_string($p) || $p === '') {
                continue;
            }
            $n = str_replace('\\', '/', $p);
            $lines[] = $n;
        }
        if ($lines === []) {
            return;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fh = @fopen($this->file, 'a');
        if ($fh === false) {
            return;
        }
        foreach ($lines as $n) {
            fwrite($fh, $n . "\n");
        }
        @fflush($fh);
        @fclose($fh);
    }

    /**
     * @return array<string,true> Normalized path => true
     */
    public function allKeys(): array {
        $out = [];
        if (!is_file($this->file)) {
            return $out;
        }
        $fh = @fopen($this->file, 'r');
        if ($fh === false) {
            return $out;
        }
        while (($line = fgets($fh)) !== false) {
            $p = trim($line);
            if ($p === '') {
                continue;
            }
            $out[$p] = true;
        }
        fclose($fh);
        return $out;
    }
}
