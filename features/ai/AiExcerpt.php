<?php
/**
 * Read at most 10 windows of 8KB with fopen. No shell.
 */
final class AiExcerpt {

    public const WINDOW = 8192;
    public const MAX_WINDOWS = 10;

    /**
     * @param array<int,array> $hits signature rows for this path
     * @return array{text:string,truncated:bool,missing:bool}
     */
    public static function read(string $abs, array $unit, array $hits): array {
        if (!is_file($abs) || !is_readable($abs)) {
            return ['text' => '', 'truncated' => false, 'missing' => true];
        }
        $size = (int) @filesize($abs);
        if ($size < 0) {
            $size = 0;
        }
        $cap = self::WINDOW * self::MAX_WINDOWS;
        $prefixEnd = min($size, $cap);
        $ranges = [];
        for ($i = 0; $i < self::MAX_WINDOWS && ($i * self::WINDOW) < $prefixEnd; $i++) {
            $start = $i * self::WINDOW;
            $ranges[] = [$start, min($prefixEnd, $start + self::WINDOW)];
        }
        if ($ranges === [] && $size >= 0) {
            $ranges[] = [0, min($size, self::WINDOW)];
            $prefixEnd = min($size, self::WINDOW);
        }
        $truncated = $size > $prefixEnd;
        $extra = 0;
        foreach ($hits as $hit) {
            $at = self::locate($abs, $size, $hit);
            if ($at === null || $at < $prefixEnd) {
                continue;
            }
            $start = max($prefixEnd, $at - (int) (self::WINDOW / 2));
            if ($extra >= self::MAX_WINDOWS) {
                $truncated = true;
                break;
            }
            $ranges[] = [$start, min($size, $start + self::WINDOW)];
            $extra++;
            if ($start + self::WINDOW < $size) {
                $truncated = true;
            }
        }
        $parts = [];
        foreach ($ranges as $i => $range) {
            $parts[] = '--- window ' . ($i + 1) . " ---\n" . self::slice($abs, $range[0], $range[1] - $range[0]);
        }
        return [
            'text' => implode("\n", $parts),
            'truncated' => $truncated,
            'missing' => false,
        ];
    }

    /** @param array $hit */
    private static function locate(string $abs, int $size, array $hit): ?int {
        $line = isset($hit['line_number']) ? (int) $hit['line_number'] : 0;
        if ($line > 0) {
            $fh = @fopen($abs, 'rb');
            if ($fh === false) {
                return null;
            }
            $n = 1;
            $pos = 0;
            while ($n < $line && ($row = fgets($fh)) !== false) {
                $pos += strlen($row);
                $n++;
            }
            fclose($fh);
            return $pos;
        }
        $needle = (string) ($hit['match'] ?? '');
        if ($needle === '') {
            $needle = (string) ($hit['content_preview'] ?? '');
        }
        $needle = substr($needle, 0, 80);
        if ($needle === '') {
            return 0;
        }
        $fh = @fopen($abs, 'rb');
        if ($fh === false) {
            return null;
        }
        $chunk = 65536;
        $carry = '';
        $offset = 0;
        $found = null;
        while (!feof($fh)) {
            $buf = fread($fh, $chunk);
            if ($buf === false || $buf === '') {
                break;
            }
            $hay = $carry . $buf;
            $at = strpos($hay, $needle);
            if ($at !== false) {
                $found = $offset - strlen($carry) + $at;
                break;
            }
            $carry = substr($hay, -strlen($needle));
            $offset += strlen($buf);
            if ($offset > $size) {
                break;
            }
        }
        fclose($fh);
        return $found ?? 0;
    }

    private static function slice(string $abs, int $start, int $len): string {
        if ($len <= 0) {
            return '';
        }
        $fh = @fopen($abs, 'rb');
        if ($fh === false) {
            return '';
        }
        if ($start > 0) {
            fseek($fh, $start);
        }
        $data = (string) fread($fh, $len);
        fclose($fh);
        return $data;
    }
}
