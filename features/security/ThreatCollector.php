<?php
/**
 * Clean Sweep - Threat Collector
 *
 * Per-batch threat buffer. Accumulates signature matches from the
 * scanners in memory, then flushes them as JSONL to the CleanSweep_ThreatStore
 * when the threshold is reached.
 *
 * In the CleanSweep_Scanner v2 architecture, the worker context owns cumulative
 * counters; this class is just a per-batch coalescer.
 *
 * @since CleanSweep_Scanner v2
 */

require_once __DIR__ . '/scan/ThreatStore.php';

class CleanSweep_ThreatCollector {

    /** @var array Buffered threats not yet flushed */
    private $threats = [];

    /** @var int Flush threshold - flush every N threats */
    private $flush_threshold = 100;

    /** @var int Flush after every N files processed */
    private $flush_file_interval = 10;

    /** @var int Files processed since last flush */
    private $files_processed = 0;

    /** @var array Counters for reporting */
    private $counters = [
        'threats_total' => 0,
        'files_scanned' => 0,
        'db_rows_scanned' => 0,
    ];

    /** @var CleanSweep_ThreatStore|null Optimized append-only threat store (CleanSweep_Scanner v2) */
    private $threat_store = null;

    /**
     * Constructor.
     *
     * @param int $flush_threshold Number of threats before flush
     */
    public function __construct($flush_threshold = 100) {
        $this->flush_threshold = $flush_threshold;
    }

    /**
     * Set threat store for optimized threat storage.
     *
     * @param CleanSweep_ThreatStore $store
     */
    public function set_threat_store(CleanSweep_ThreatStore $store) {
        $this->threat_store = $store;
    }

    /**
     * Set flush thresholds.
     *
     * @param int $threat_threshold Flush after N threats
     * @param int $file_interval Flush after every N files
     */
    public function set_thresholds($threat_threshold, $file_interval) {
        $this->flush_threshold = $threat_threshold;
        $this->flush_file_interval = $file_interval;
    }

    /**
     * Add a threat to the collection.
     * Automatically flushes when threshold reached.
     *
     * @param array $threat Threat data
     */
    public function add($threat) {
        // Add metadata
        $threat['detected_at'] = date('c');

        // Only generate ID if not already set by scanner
        if (!isset($threat['id'])) {
            $threat['id'] = $this->generate_threat_id($threat);
        }

        $this->threats[] = $threat;
        $this->counters['threats_total']++;

        // Check if flush needed
        if (count($this->threats) >= $this->flush_threshold) {
            $this->flush();
        }
    }

    /**
     * Record that a file was processed.
     *
     * @param int $count Number of files
     */
    public function files_processed($count = 1) {
        $this->counters['files_scanned'] += $count;
        $this->files_processed += $count;

        if ($this->files_processed >= $this->flush_file_interval) {
            $this->flush();
            $this->files_processed = 0;
        }
    }

    /**
     * Record database rows scanned.
     *
     * @param int $count Number of rows
     */
    public function db_rows_scanned($count = 1) {
        $this->counters['db_rows_scanned'] += $count;
    }

    /**
     * Flush buffered threats to the CleanSweep_ThreatStore (O(1) per threat).
     * No-op if no CleanSweep_ThreatStore is wired or there are no threats to flush.
     */
    public function flush() {
        if (empty($this->threats) || !$this->threat_store) {
            return;
        }
        $this->threat_store->appendMany($this->threats);
        $this->threats = [];
    }

    /**
     * Get all threats collected so far (in memory).
     *
     * @return array
     */
    public function get_all() {
        return $this->threats;
    }

    /**
     * Get threat count.
     *
     * @return int
     */
    public function count() {
        return count($this->threats);
    }

    /**
     * Get total threats (including flushed).
     *
     * @return int
     */
    public function get_total() {
        return $this->counters['threats_total'];
    }

    /**
     * Get counters.
     *
     * @return array
     */
    public function get_counters() {
        return $this->counters;
    }

    /**
     * Generate unique threat ID from content.
     *
     * @param array $threat
     * @return string MD5 hash for frontend key
     */
    private function generate_threat_id($threat) {
        $data = ($threat['file'] ?? '') . ($threat['pattern'] ?? '') . ($threat['match'] ?? '') . ($threat['line_number'] ?? 0);
        return md5($data);
    }

    /**
     * Calculate numeric risk score (0-100) from threat pattern.
     *
     * @param string $pattern Signature pattern
     * @param string $matched_content Matched content
     * @return int Risk score 0-100
     */
    public static function calculate_risk_score($pattern, $matched_content = '') {
        $score = 50; // Default medium score

        // Critical risk indicators (add 40-50 points)
        $critical_indicators = [
            'eval\s*\(\s*base64_decode' => 95,
            'base64_decode\s*\(\s*\$_(POST|GET|REQUEST)' => 90,
            'system\s*\(' => 90,
            'exec\s*\(' => 90,
            'shell_exec\s*\(' => 90,
            'passthru\s*\(' => 90,
            'popen\s*\(' => 85,
            'proc_open\s*\(' => 85,
            'assert\s*\(' => 85,
            'create_function\s*\(' => 80,
            'preg_replace\s*\(.*\/e' => 80,
            'move_uploaded_file\s*\(' => 80,
        ];

        // High risk indicators (add 25-40 points)
        $high_indicators = [
            'base64_decode' => 70,
            'gzinflate' => 70,
            'str_rot13' => 65,
            'ord\s*\(.*chr\(' => 65,
            '\$_(POST|GET|REQUEST)\s*\[' => 65,
            'file_get_contents\s*\(' => 60,
            'curl_exec' => 60,
            'curl_setopt' => 55,
            'fopen\s*\(' => 55,
            'fwrite\s*\(' => 60,
            'unserialize\s*\(' => 60,
            ' unserialize' => 55,
        ];

        // Medium risk indicators (add 10-25 points)
        $medium_indicators = [
            'eval\s*\(' => 55,
            'preg_replace' => 50,
            'extract\s*\(' => 45,
            'parse_str\s*\(' => 45,
            'mb_parse_str' => 45,
            'parse_url\s*\(' => 40,
            'html_entity_decode' => 40,
            'rawurldecode' => 35,
            'json_decode' => 35,
            'simplexml_load_string' => 40,
            '__wakeup' => 50,
            '__destruct' => 50,
        ];

        // Low risk indicators (subtract 10-20 points)
        $low_indicators = [
            'htmlspecialchars\s*\(' => 25,
            'addslashes\s*\(' => 25,
            'mysql_real_escape_string' => 25,
            'mysqli_real_escape_string' => 25,
            'PDO::quote' => 25,
            'prepared statements' => 20,
        ];

        $pattern_lower = strtolower($pattern);
        $content_lower = strtolower($matched_content);

        // Check critical indicators first
        foreach ($critical_indicators as $indicator => $points) {
            if (preg_match('/' . $indicator . '/i', $pattern) || preg_match('/' . $indicator . '/i', $content_lower)) {
                $score = max($score, $points);
            }
        }

        // Check high indicators
        foreach ($high_indicators as $indicator => $points) {
            if (preg_match('/' . $indicator . '/i', $pattern) || preg_match('/' . $indicator . '/i', $content_lower)) {
                $score = max($score, $points);
            }
        }

        // Check medium indicators
        foreach ($medium_indicators as $indicator => $points) {
            if (preg_match('/' . $indicator . '/i', $pattern) || preg_match('/' . $indicator . '/i', $content_lower)) {
                $score = max($score, $points);
            }
        }

        // Check low indicators (reduce score if present without higher indicators)
        foreach ($low_indicators as $indicator => $points) {
            if (preg_match('/' . $indicator . '/i', $pattern) || preg_match('/' . $indicator . '/i', $content_lower)) {
                if ($score < 50) {
                    $score = min($score, $points);
                }
            }
        }

        // Cap at 0-100 range
        return min(100, max(0, $score));
    }

    /**
     * Determine threat level from risk score.
     *
     * @param int $risk_score Numeric risk score 0-100
     * @return string Threat level: critical, high, medium, low
     */
    public static function risk_score_to_level($risk_score) {
        if ($risk_score >= 80) return 'critical';
        if ($risk_score >= 60) return 'high';
        if ($risk_score >= 40) return 'medium';
        if ($risk_score >= 20) return 'low';
        return 'info';
    }

    /**
     * Map pack metadata severity to a numeric risk score.
     * Used when csig entries declare severity (preferred over pattern heuristics).
     *
     * @param string $severity critical|high|medium|low
     * @return int|null Null if severity unknown
     */
    public static function severity_to_risk_score($severity) {
        switch (strtolower((string) $severity)) {
            case 'critical':
                return 90;
            case 'high':
                return 70;
            case 'medium':
                return 50;
            case 'low':
                return 30;
            default:
                return null;
        }
    }

    /**
     * Resolve score for a hit: metadata severity wins when present, else heuristics.
     *
     * @param string $pattern
     * @param string $matched_content
     * @param string|null $severity Pack severity or null
     * @return int
     */
    public static function resolve_risk_score($pattern, $matched_content = '', $severity = null) {
        $from_meta = self::severity_to_risk_score($severity);
        if ($from_meta !== null) {
            return $from_meta;
        }
        return self::calculate_risk_score($pattern, $matched_content);
    }

/**
     * Finalize collection and return all threats.
     * Flushes remaining threats and also loads any that were previously flushed.
     *
     * @return array All threats collected
     */
    public function finalize() {
        // Flush any remaining in-memory threats
        $this->flush();

        // Also load threats that were flushed to progress store during scanning
        // to ensure we return ALL threats, not just in-memory ones
        if ($this->progress_store) {
            $flushed_threats = $this->progress_store->load_threats();
            // Merge with any remaining in-memory threats
            $all_threats = array_merge($flushed_threats, $this->threats);
            // Clear in-memory threats since they're now part of the returned set
            $this->threats = [];
            return $all_threats;
        }

        return $this->get_all();
    }

    /**
     * Build complete threat with editor link.
     *
     * @param string $pattern Signature pattern
     * @param string $match Matched content
     * @param string $source 'file' or 'database'
     * @param array $location Location data (file, line_number, table, row_id, column)
     * @param string $category Signature category
     * @param string $threat_level Threat level
     * @return array Complete threat
     */
    public static function build_threat($pattern, $match, $source, $location, $category = 'general', $threat_level = 'medium') {
        // Calculate risk score from pattern and matched content
        $risk_score = self::calculate_risk_score($pattern, $match);

        $threat = [
            'pattern' => $pattern,
            'match' => substr($match, 0, 100),
            'source' => $source,
            'category' => $category,
            'threat_level' => $threat_level,
            'risk_score' => $risk_score,
            'matched_content' => $match,
        ];

        // Add location fields
        if ($source === 'file') {
            $threat['file'] = $location['file'] ?? '';
            $threat['line_number'] = $location['line_number'] ?? 0;
            $threat['byte_offset'] = $location['byte_offset'] ?? 0;
            $threat['open_in_editor'] = ($location['file'] ?? '') . ':' . ($location['line_number'] ?? 0);
            $threat['content_preview'] = self::generate_preview($match);
        } else {
            // Database threat
            $threat['table'] = $location['table'] ?? '';
            $threat['row_id'] = $location['row_id'] ?? 0;
            $threat['column'] = $location['column'] ?? '';
            $threat['open_in_editor'] = 'DB:' . ($location['table'] ?? '') . ':' . ($location['row_id'] ?? 0) . ':' . ($location['column'] ?? '');
            $threat['content_preview'] = self::generate_preview($match);
        }

        return $threat;
    }

    /**
     * Generate content preview with context.
     *
     * @param string $content Matched content
     * @param int $length Preview length
     * @return string
     */
    private static function generate_preview($content, $length = 200) {
        if (strlen($content) <= $length) {
            return $content;
        }
        return substr($content, 0, $length) . '...';
    }
}