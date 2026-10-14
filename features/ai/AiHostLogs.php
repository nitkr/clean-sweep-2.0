<?php
/**
 * Known host log paths only. Does not walk the disk and does not ask the model.
 * Daily access copies access.log.1.gz through access.log.7.gz are streamed
 * oldest first. They are not extracted to disk.
 * One logs folder: the directory beside this site's public_html.
 * Dedicated is .../site/logs. Unlimited is .../sites/<site>/logs.
 * PHP error, slow, and WAF logs are not read.
 */
final class AiHostLogs {

    /**
     * The account home is not searched. Only the logs folder beside public_html is used.
     *
     * @param array<string,mixed> $server
     * @param callable(string):bool $readable
     * @return array{host:string,access_log:?string,status:string}
     */
    public static function locate(array $server, string $abspath, ?string $home, callable $readable): array {
        unset($home);
        $empty = [
            'host' => 'unknown',
            'access_log' => null,
            'status' => 'unavailable',
        ];
        if (!isset($server['WPMUDEV_HOSTED'])) {
            return $empty;
        }
        $empty['host'] = 'wpmudev';
        $abspath = rtrim(str_replace('\\', '/', $abspath), '/');
        $dir = self::logDir($abspath);
        if ($dir === '') {
            return $empty;
        }
        $access = $dir . '/access.log';
        if (!$readable($access)) {
            return $empty;
        }
        return [
            'host' => 'wpmudev',
            'access_log' => $access,
            'status' => 'ok',
        ];
    }

    /**
     * Why this request did not read an access log. Empty when the log can be read.
     *
     * @param array<string,mixed> $server
     * @param callable(string):bool $readable
     */
    public static function skipNote(array $server, string $abspath, callable $readable): string {
        if (!isset($server['WPMUDEV_HOSTED'])) {
            return 'Request logs were not checked.';
        }
        $abspath = rtrim(str_replace('\\', '/', $abspath), '/');
        $dir = self::logDir($abspath);
        if ($dir === '') {
            $shown = $abspath !== '' ? $abspath : 'the site root';
            return 'Request logs were not checked. No public_html directory was found from ' . $shown . '.';
        }
        $access = $dir . '/access.log';
        if (!$readable($access)) {
            return 'Request logs were not checked. ' . $access . ' is not readable.';
        }
        return '';
    }

    /**
     * One sentence for a log that was opened. Names the copies that could be read.
     */
    public static function checkedNote(string $accessLog, int $queued, int $matched): string {
        $names = [];
        foreach (self::accessSources($accessLog) as $file) {
            $names[] = basename($file);
        }
        $files = $names === [] ? 'no copy' : implode(', ', $names);
        $queued = max(0, $queued);
        $matched = max(0, $matched);
        $fileWord = $queued === 1 ? 'file' : 'files';
        return 'Request logs were checked at ' . $accessLog
            . '. Read ' . $files
            . '. ' . $matched . ' of ' . $queued . ' queued ' . $fileWord . ' had a matching request.';
    }

    /**
     * The page line. A request for a dangerous file is told in the infection summary.
     */
    public static function outcomeNote(int $matched, int $dangerousHits): string {
        if ($dangerousHits > 0) {
            return '';
        }
        if ($matched <= 0) {
            return 'The request logs have no visit to any file in this scan.';
        }
        return 'The request logs record visits to queued files. None of those files are on the dangerous list.';
    }

    /**
     * The logs directory beside this site's public_html, or empty.
     */
    private static function logDir(string $abspath): string {
        $public = self::publicHtmlDir($abspath);
        if ($public === '') {
            return '';
        }
        $dir = dirname($public) . '/logs';
        if ($dir === '' || $dir === '/logs') {
            return '';
        }
        return $dir;
    }

    /**
     * public_html for this site, or empty when the path has no such directory.
     */
    private static function publicHtmlDir(string $path): string {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        for ($i = 0; $i < 6; $i++) {
            if ($path === '' || $path === '/' || $path === '.') {
                return '';
            }
            if (basename($path) === 'public_html') {
                return $path;
            }
            $parent = dirname($path);
            if ($parent === $path) {
                return '';
            }
            $path = $parent;
        }
        return '';
    }

    /**
     * Plain access.log plus the daily gzip copies beside it, oldest first.
     * A missing copy is skipped. The gzip files are not unpacked onto disk.
     *
     * @return string[]
     */
    public static function accessSources(string $accessLog): array {
        $files = [];
        if (basename($accessLog) === 'access.log' && function_exists('gzopen')) {
            $dir = dirname($accessLog);
            for ($n = 7; $n >= 1; $n--) {
                $gz = $dir . '/access.log.' . $n . '.gz';
                if (is_readable($gz)) {
                    $files[] = $gz;
                }
            }
        }
        if (is_readable($accessLog)) {
            $files[] = $accessLog;
        }
        return $files;
    }

    /**
     * Map site-relative path => up to 30 redacted lines.
     * Gzip copies are read before today's file, so the first line is the earliest.
     * Reading stops once every requested path has its 30 lines.
     *
     * @param string[] $paths
     * @return array<string,array<int,array{method:string,status:string,time:string,path:string,query:string}>>
     */
    public static function indexAccessLog(string $file, array $paths, bool $includeQuery, int $maxLines = 30): array {
        $want = [];
        foreach ($paths as $p) {
            $want[ltrim(AiPaths::norm($p), '/')] = true;
        }
        $pending = $want;
        $out = [];
        foreach (self::accessSources($file) as $source) {
            if ($pending === []) {
                break;
            }
            $fh = self::openLineStream($source);
            if ($fh === false) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                $parsed = self::parseLine($line, $includeQuery);
                if ($parsed === null) {
                    continue;
                }
                $path = $parsed['path'];
                if (!isset($pending[$path])) {
                    continue;
                }
                if (!isset($out[$path])) {
                    $out[$path] = [];
                }
                $out[$path][] = $parsed;
                if (count($out[$path]) >= $maxLines) {
                    unset($pending[$path]);
                    if ($pending === []) {
                        break;
                    }
                }
            }
            fclose($fh);
        }
        return $out;
    }

    /**
     * @return resource|false
     */
    private static function openLineStream(string $file) {
        if (substr($file, -3) === '.gz') {
            if (!function_exists('gzopen')) {
                return false;
            }
            return @fopen('compress.zlib://' . $file, 'rb');
        }
        return @fopen($file, 'rb');
    }

    /**
     * @return array{method:string,status:string,time:string,path:string,query:string}|null
     */
    public static function parseLine(string $line, bool $includeQuery): ?array {
        if (!preg_match('/\[([^\]]+)\]\s+"([A-Z]+)\s+(\S+)\s+[^"]+"\s+(\d{3})/', $line, $m)) {
            return null;
        }
        $url = $m[3];
        $query = '';
        $path = $url;
        $qpos = strpos($url, '?');
        if ($qpos !== false) {
            $query = $includeQuery ? substr($url, $qpos + 1) : '';
            $path = substr($url, 0, $qpos);
        }
        $path = rawurldecode($path);
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return [
            'method' => $m[2],
            'status' => $m[4],
            'time' => $m[1],
            'path' => $path,
            'query' => $query,
        ];
    }

    public static function firstRequest(array $lines): string {
        if ($lines === []) {
            return '';
        }
        $first = $lines[0];
        $ts = strtotime((string) ($first['time'] ?? ''));
        if ($ts === false) {
            return '';
        }
        return gmdate('c', $ts);
    }

    /**
     * One redacted line per parsed access-log row. No address and no user agent.
     *
     * @param array<int,array{method:string,status:string,time:string,path:string,query:string}> $rows
     * @return string[]
     */
    public static function displayLines(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = trim(
                (string) ($row['method'] ?? '') . ' '
                . (string) ($row['status'] ?? '') . ' '
                . (string) ($row['time'] ?? '') . ' '
                . (string) ($row['path'] ?? '')
            );
            $query = (string) ($row['query'] ?? '');
            if ($query !== '') {
                $line .= '?' . $query;
            }
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}
