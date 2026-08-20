<?php
/**
 * Clean Sweep - Chunk Processor
 *
 * Memory-efficient chunk reading with overlap support for multi-line
 * pattern matching across chunk boundaries.
 */

class CleanSweep_ChunkProcessor {

    /** @var int Standard chunk size (16KB) */
    const CHUNK_SIZE = 16384;

    /** @var int Overlap size carried forward (2KB; wide (?s) malware windows) */
    const OVERLAP_SIZE = 2048;

    /** @var int Files smaller than this are loaded fully */
    const SMALL_FILE_THRESHOLD = 32768;

    /** @var int Large file threshold for heavy signature skip */
    const LARGE_FILE_THRESHOLD = 1048576;

    /** @var string Previous chunk's tail for overlap */
    private $previous_tail = '';

    /** @var int Total newlines before current chunk */
    private $total_newlines_before = 0;

    /** @var int Current chunk index */
    private $chunk_index = 0;

    /**
     * Read file in chunks with overlap for multi-line pattern support.
     *
     * @param string $file_path Path to file
     * @param callable $callback Function(chunk_content, chunk_info) called for each chunk
     * @param array $options Processing options
     *
     * @return array Results from callback calls
     */
    public function read_chunks($file_path, $callback, $options = []) {
        $results = [];

        if (!file_exists($file_path) || !is_readable($file_path)) {
            return $results;
        }

        $file_size = filesize($file_path);
        $handle = fopen($file_path, 'rb');

        if (!$handle) {
            return $results;
        }

        // Reset state
        $this->previous_tail = '';
        $this->total_newlines_before = 0;
        $this->chunk_index = 0;

        // For small files, load entirely and process as single chunk.
        // Reuse the already-open handle (avoid fopen + file_get_contents double open).
        if ($file_size < self::SMALL_FILE_THRESHOLD) {
            $full_content = ($file_size > 0) ? stream_get_contents($handle) : '';
            fclose($handle);
            if ($full_content !== false) {
                $chunk_info = [
                    'chunk_index' => 0,
                    'is_first' => true,
                    'is_last' => true,
                    'start_offset' => 0,
                    'new_content_length' => strlen($full_content),
                    'total_newlines_before' => 0,
                    'file_size' => $file_size,
                    'is_single_chunk' => true,
                    'previous_tail' => '',
                    'raw_chunk' => $full_content
                ];
                $results[] = $callback($full_content, $chunk_info);
            }
            return $results;
        }

        // Process large files in chunks
        while (!feof($handle)) {
            $raw_chunk = fread($handle, self::CHUNK_SIZE);

            // Use === '' not empty() — empty("0") is true in PHP and would drop a valid chunk.
            if ($raw_chunk === false || $raw_chunk === '') {
                break;
            }

            // Construct chunk to scan by combining previous tail + new content
            $chunk_to_scan = $this->previous_tail . $raw_chunk;
            $new_content_length = strlen($raw_chunk);

            // Count newlines in raw chunk
            $newlines_in_raw = substr_count($raw_chunk, "\n");

            // Build chunk info
            $chunk_info = [
                'chunk_index' => $this->chunk_index,
                'is_first' => ($this->chunk_index === 0),
                'is_last' => (ftell($handle) >= $file_size),
                'start_offset' => ftell($handle) - strlen($this->previous_tail) - strlen($raw_chunk),
                'new_content_length' => $new_content_length,
                'total_newlines_before' => $this->total_newlines_before,
                'file_size' => $file_size,
                'is_single_chunk' => false,
                'previous_tail' => $this->previous_tail,
                'raw_chunk' => $raw_chunk
            ];

            // Call callback with chunk
            $result = $callback($chunk_to_scan, $chunk_info);
            if ($result !== null) {
                $results[] = $result;
            }

            // Update state for next iteration
            // Keep OVERLAP_SIZE bytes from end of raw chunk as tail for next chunk
            $this->previous_tail = strlen($raw_chunk) >= self::OVERLAP_SIZE
                ? substr($raw_chunk, -self::OVERLAP_SIZE)
                : $raw_chunk;

            $this->total_newlines_before += $newlines_in_raw;
            $this->chunk_index++;
        }

        fclose($handle);
        return $results;
    }

    /**
     * Calculate accurate line number for a match position.
     *
     * @param string $chunk_to_scan Full chunk content (including overlap)
     * @param int $match_position Position of match within chunk_to_scan
     * @param array $chunk_info Chunk metadata
     * @return int Line number (1-indexed)
     */
    public function calculate_line_number($chunk_to_scan, $match_position, $chunk_info) {
        $overlap_len = strlen($chunk_info['previous_tail']);

        if ($chunk_info['is_first_chunk'] ?? $chunk_info['is_first']) {
            // First chunk: count from start
            return substr_count(substr($chunk_to_scan, 0, $match_position), "\n") + 1;
        }

        if ($match_position < $overlap_len) {
            // Match in overlap region: use previous chunk's line count
            $tail_newlines = substr_count($chunk_info['previous_tail'], "\n");
            return $chunk_info['total_newlines_before'] - $tail_newlines
                   + substr_count(substr($chunk_to_scan, 0, $match_position), "\n") + 1;
        }

        // Match in new content (after overlap)
        $position_in_new = $match_position - $overlap_len;
        $new_content_before_match = substr($chunk_info['raw_chunk'], 0, $position_in_new);

        return $chunk_info['total_newlines_before']
               + substr_count($new_content_before_match, "\n") + 1;
    }

    /**
     * Get byte offset within the original file for a match position.
     *
     * @param int $match_position Position within chunk (including overlap)
     * @param array $chunk_info Chunk metadata
     * @return int Byte offset in original file
     */
    public function calculate_byte_offset($match_position, $chunk_info) {
        $overlap_len = strlen($chunk_info['previous_tail']);
        $chunk_start = $chunk_info['start_offset'];

        if ($chunk_info['is_first'] ?? false) {
            return $match_position;
        }

        // Adjust for overlap - the match position includes the overlap
        // but the byte offset should be relative to new content only
        if ($match_position < $overlap_len) {
            // Match in overlap region
            return $chunk_start - $overlap_len + $match_position;
        }

        // Match in new content
        return $chunk_start + ($match_position - $overlap_len);
    }

    /**
     * Extract content preview around a match with context.
     *
     * @param string $content Full chunk content
     * @param int $match_position Position of match
     * @param int $context_chars Characters of context on each side
     * @return string Content preview with ellipsis
     */
    public function extract_preview($content, $match_position, $context_chars = 100) {
        $start = max(0, $match_position - $context_chars);
        $end = min(strlen($content), $match_position + $context_chars);

        $preview = substr($content, $start, $end - $start);

        if ($start > 0) {
            $preview = '...' . $preview;
        }
        if ($end < strlen($content)) {
            $preview .= '...';
        }

        return $preview;
    }

    /**
     * Check if a file should be skipped based on size and options.
     *
     * @param string $file_path Path to file
     * @param array $options Options including 'skip_large_files'
     * @return bool True if file should be skipped
     */
    public function should_skip_file($file_path, $options = []) {
        $file_size = filesize($file_path);

        // Skip very large files if option is set
        if (!empty($options['skip_large_files']) && $file_size > self::LARGE_FILE_THRESHOLD) {
            return true;
        }

        return false;
    }
}