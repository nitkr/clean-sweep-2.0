<?php
/**
 * Clean Sweep - Threat Store
 *
 * Unified facade for storing and reading scan threats.
 *
 * Design:
 *   - Threats are append-only JSONL (one JSON object per line).
 *   - Counters are stored in the CleanSweep_Checkpoint (single source of truth).
 *   - All writes are O(1) per threat.
 *   - Reads stream the JSONL file.
 *
 * This class is the single entry point for any code that wants to
 * record a threat. The old `CleanSweep_ThreatCollector` (in-memory
 * buffering then bulk flush) is now a thin adapter that just calls
 * CleanSweep_ThreatStore::append.
 *
 * @since CleanSweep_Scanner v2
 */
final class CleanSweep_ThreatStore {

    /** @var string Scan ID */
    private string $scan_id;

    /** @var string Threats file path (JSONL) */
    private string $threats_file;

    /** @var resource|null Append handle, kept open across appends */
    private $handle = null;

    /**
     * @param string $scan_id
     */
    public function __construct(string $scan_id) {
        $this->scan_id = $scan_id;
        $logs_dir = defined('CLEAN_SWEEP_PROGRESS_DIR') ? CLEAN_SWEEP_PROGRESS_DIR : __DIR__ . '/../../../logs/';
        $this->threats_file = rtrim($logs_dir, '/') . '/scan_' . $scan_id . '_threats.jsonl';
    }

    /**
     * Append a single threat (O(1) write).
     *
     * @param array $threat
     * @return bool True on success
     */
    public function append(array $threat): bool {
        $handle = $this->getHandle();
        if ($handle === null) return false;

        $json = json_encode($threat, JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;

        $written = fwrite($handle, $json . "\n");
        if ($written !== false && $written > 0) {
            @fflush($handle);
            return true;
        }
        return false;
    }

    /**
     * Append many threats in a single flush.
     *
     * @param array $threats Array of threat arrays
     * @return int Number successfully appended
     */
    public function appendMany(array $threats): int {
        $count = 0;
        $handle = $this->getHandle();
        if ($handle === null) return 0;

        foreach ($threats as $threat) {
            $json = json_encode($threat, JSON_UNESCAPED_UNICODE);
            if ($json === false) continue;
            if (fwrite($handle, $json . "\n") !== false) {
                $count++;
            }
        }
        if ($count > 0) {
            @fflush($handle);
        }
        return $count;
    }

    /**
     * Get total threat count by reading the file.
     * O(n) on the threats file. Use only at completion time, not on every poll.
     *
     * @return int
     */
    public function count(): int {
        if (!file_exists($this->threats_file)) return 0;
        $count = 0;
        $fp = @fopen($this->threats_file, 'r');
        if ($fp === false) return 0;
        while (($line = fgets($fp)) !== false) {
            if (trim($line) !== '') $count++;
        }
        fclose($fp);
        return $count;
    }

    /**
     * Load all threats into memory. Use only for the final result payload,
     * never on the polling hot path.
     *
     * @param int $limit 0 = all
     * @return array
     */
    public function all(int $limit = 0): array {
        $threats = [];
        if (!file_exists($this->threats_file)) return $threats;

        $fp = @fopen($this->threats_file, 'r');
        if ($fp === false) return $threats;

        $count = 0;
        while (($line = fgets($fp)) !== false) {
            $threat = json_decode(trim($line), true);
            if ($threat !== null) {
                $threats[] = $threat;
                $count++;
                if ($limit > 0 && $count >= $limit) break;
            }
        }
        fclose($fp);
        return $threats;
    }

    /**
     * Read a filtered page starting at a raw JSONL line index.
     * Lines before $after_line are skipped with fgets only (no json_decode).
     * Stops after $limit matching threats or $max_scan decoded lines, or EOF.
     *
     * @param int $after_line 0-based line index to start at (inclusive)
     * @param int $limit Max matching threats to return
     * @param callable $include function(array $threat): bool
     * @param int $max_scan Max lines to decode in this call
     * @return array{threats:array, next_line:int, eof:bool, scanned_lines:int}
     */
    public function pageFromLine(int $after_line, int $limit, callable $include, int $max_scan = 4000): array {
        $empty = [
            'threats' => [],
            'next_line' => max(0, $after_line),
            'eof' => true,
            'scanned_lines' => 0,
        ];
        if ($limit < 1 || !file_exists($this->threats_file)) {
            return $empty;
        }

        $fp = @fopen($this->threats_file, 'r');
        if ($fp === false) {
            return $empty;
        }

        $line_index = 0;
        $after_line = max(0, $after_line);
        while ($line_index < $after_line && ($skip = fgets($fp)) !== false) {
            $line_index++;
        }

        $threats = [];
        $decoded = 0;
        $eof = true;
        while (($raw = fgets($fp)) !== false) {
            $line_index++;
            $trim = trim($raw);
            if ($trim === '') {
                continue;
            }
            $threat = json_decode($trim, true);
            $decoded++;
            if (!is_array($threat)) {
                if ($decoded >= $max_scan && count($threats) < $limit) {
                    $eof = false;
                    break;
                }
                continue;
            }
            if ($include($threat)) {
                $threats[] = $threat;
                if (count($threats) >= $limit) {
                    $eof = false;
                    break;
                }
            }
            if ($decoded >= $max_scan && count($threats) < $limit) {
                $eof = false;
                break;
            }
        }
        if ($eof && !feof($fp)) {
            $eof = false;
        }
        fclose($fp);

        return [
            'threats' => $threats,
            'next_line' => $line_index,
            'eof' => $eof,
            'scanned_lines' => $decoded,
        ];
    }

    /**
     * Stream threats one at a time, calling $callback for each.
     *
     * @param callable $callback function(array $threat): void
     * @return int Number streamed
     */
    public function stream(callable $callback): int {
        if (!file_exists($this->threats_file)) return 0;
        $fp = @fopen($this->threats_file, 'r');
        if ($fp === false) return 0;

        $count = 0;
        while (($line = fgets($fp)) !== false) {
            $threat = json_decode(trim($line), true);
            if ($threat !== null) {
                $callback($threat);
                $count++;
            }
        }
        fclose($fp);
        return $count;
    }

    /**
     * Delete the threats file. Used by cancel/cleanup.
     */
    public function cleanup(): void {
        $this->close();
        if (file_exists($this->threats_file)) {
            @unlink($this->threats_file);
        }
    }

    /**
     * Close the append handle. Called automatically on destruct, but can
     * be called explicitly to flush after a batch.
     */
    public function close(): void {
        if ($this->handle !== null) {
            @fflush($this->handle);
            @fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct() {
        $this->close();
    }

    /**
     * Lazily open the append handle.
     */
    private function getHandle() {
        if ($this->handle !== null) return $this->handle;

        $dir = dirname($this->threats_file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($this->threats_file, 'a');
        if ($fp === false) {
            clean_sweep_log_message("CleanSweep_ThreatStore: failed to open {$this->threats_file} for append", 'error');
            return null;
        }
        $this->handle = $fp;
        return $fp;
    }
}
