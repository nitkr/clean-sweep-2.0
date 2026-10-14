<?php
/**
 * One batch: read, mask, send, parse, latch, store.
 */
final class AiReview {

    public const BATCH_FILES = 6;
    public const BATCH_BYTES = 24576;
    public const NOT_ENCODED_SUMMARY = 'This file was not sent. Its bytes could not be encoded, so the model did not see it.';

    private AiStore $store;
    private string $root;
    private AiPolicy $policy;
    private string $model;
    /** @var callable */
    private $complete;
    /** @var array<string,array<int,array>> */
    private array $hitsByPath;
    /** @var array<string,string> */
    private array $firstRequest;
    /** @var array<string,array{file_time:string,file_time_basis:string}> */
    private array $times;
    /** @var array<string,string[]> */
    private array $logLines;
    /** @var array<string,int>|null */
    private ?array $watchMap = null;

    /**
     * @param callable $complete function(string $user, int $maxTokens): array{ok:bool,content:string}
     * @param array<string,array<int,array>> $hitsByPath
     * @param array<string,string[]> $logLines
     */
    public function __construct(
        AiStore $store,
        string $root,
        AiPolicy $policy,
        string $model,
        callable $complete,
        array $hitsByPath = [],
        array $firstRequest = [],
        array $times = [],
        array $logLines = []
    ) {
        $this->store = $store;
        $this->root = rtrim(AiPaths::norm($root), '/') . '/';
        $this->policy = $policy;
        $this->model = $model;
        $this->complete = $complete;
        $this->hitsByPath = $hitsByPath;
        $this->firstRequest = $firstRequest;
        $this->times = $times;
        $this->logLines = $logLines;
    }

    /**
     * @return array{done:bool,reviewed:int,error?:string}
     */
    public function batch(string $scanId, ?string $onlyPath = null): array {
        $verdicts = new AiVerdictStore($this->store);
        $doneRows = $verdicts->forScan($scanId);
        $pending = [];
        foreach ($this->store->readJsonl('queue.jsonl') as $unit) {
            if (($unit['scan_id'] ?? '') !== $scanId) {
                continue;
            }
            if ($onlyPath !== null && (string) ($unit['path'] ?? '') !== $onlyPath) {
                continue;
            }
            if ($this->alreadyJudged($doneRows, $unit)) {
                continue;
            }
            $pending[] = $unit;
        }
        if ($pending === []) {
            return ['done' => true, 'reviewed' => 0];
        }
        $batch = [];
        $bytes = 0;
        $blocks = [];
        $prepared = [];
        $n = 0;
        foreach ($pending as $unit) {
            if (count($batch) >= self::BATCH_FILES) {
                break;
            }
            $subject = (string) ($unit['subject'] ?? 'file');
            if ($subject === 'database') {
                $this->storeDatabaseUnsure($scanId, $unit, $verdicts);
                continue;
            }
            $path = (string) $unit['path'];
            $abs = AiPaths::abs($path, $this->root);
            $hits = $this->hitsByPath[$path] ?? [];
            $excerpt = AiExcerpt::read($abs, $unit, $hits);
            if ($excerpt['missing']) {
                (new AiReadLog($this->store))->append($scanId, $path, AiReadLog::NOT_MISSING);
                $verdicts->append([
                    'scan_id' => $scanId,
                    'subject' => 'file',
                    'path' => $path,
                    'verdict' => 'unsure',
                    'summary' => 'The file could not be read.',
                    'file_time_basis' => 'unknown',
                ]);
                continue;
            }
            $text = $excerpt['text'];
            $maskedHeavy = !empty($unit['masked_heavy']);
            $masked = false;
            if (!$this->policy->fullPass()) {
                $mask = AiSecretMask::apply($text);
                $text = $mask['text'];
                $masked = $mask['masked'];
                $maskedHeavy = $maskedHeavy || $mask['masked_heavy'];
            }
            $len = strlen($text);
            if ($batch !== [] && ($bytes + $len) > self::BATCH_BYTES) {
                break;
            }
            $n++;
            $time = $this->times[$path] ?? $this->fileTime($abs, $path);
            $first = (string) ($this->firstRequest[$path] ?? '');
            $reasons = is_array($unit['reasons'] ?? null) ? $unit['reasons'] : [];
            $block = "FILE {$n}\npath: {$path}\nreasons: " . implode(', ', $reasons)
                . "\nsignature_hit: " . (!empty($unit['signature_hit']) ? 'yes' : 'no')
                . "\nsha256: " . hash('sha256', $text)
                . "\nfile_time: " . ($time['file_time'] ?? '')
                . "\nfile_time_basis: " . ($time['file_time_basis'] ?? 'unknown');
            if ($first !== '') {
                $block .= "\nfirst_request_at: {$first}";
            }
            $notes = $this->logLines[$path] ?? [];
            if (is_array($notes)) {
                foreach ($notes as $note) {
                    if (!is_string($note) || $note === '') {
                        continue;
                    }
                    $block .= "\nlog: " . str_replace(["\r", "\n"], ' ', $note);
                }
            }
            $block .= "\n" . $text;
            $blocks[$n] = $block;
            $batch[] = $n;
            $prepared[$n] = [
                'unit' => $unit,
                'path' => $path,
                'text' => $text,
                'truncated' => $excerpt['truncated'],
                'masked' => $masked,
                'masked_heavy' => $maskedHeavy,
                'time' => $time,
                'first' => $first,
            ];
            $bytes += $len;
        }
        if ($prepared === []) {
            return ['done' => $this->remaining($scanId, $verdicts) === 0, 'reviewed' => 0];
        }
        $cached = [];
        $sendParts = [];
        $sendNumbers = [];
        $unencoded = [];
        foreach ($prepared as $num => $item) {
            if (!AiChatClient::encodable($blocks[$num])) {
                $unencoded[$num] = true;
                continue;
            }
            $hit = $this->cacheGet($item['text']);
            if ($hit !== null) {
                $cached[$num] = $hit;
            } else {
                $sendParts[] = $blocks[$num];
                $sendNumbers[] = $num;
            }
        }
        $log = new AiReadLog($this->store);
        foreach (array_keys($unencoded) as $num) {
            $this->storeUnencoded($scanId, $prepared[$num], $verdicts, $log);
            unset($prepared[$num]);
        }
        $parsed = $cached;
        if ($sendNumbers !== []) {
            $reply = call_user_func($this->complete, implode("\n\n", $sendParts), 1024);
            if (!is_array($reply) || empty($reply['ok'])) {
                $reply = is_array($reply) ? $reply : [];
                $failed = [
                    'done' => false,
                    'reviewed' => 0,
                    'error' => AiChatClient::reviewStop($reply),
                ];
                if (AiChatClient::isTransportTimeout($reply)) {
                    $failed['timeout'] = true;
                }
                return $failed;
            }
            $fresh = AiReplyParser::parse((string) $reply['content'], $sendNumbers);
            foreach ($fresh as $num => $row) {
                $parsed[$num] = $row;
                if (($row['cache'] ?? true) === false) {
                    continue;
                }
                $this->cachePut($prepared[$num]['text'], $row);
            }
        }
        foreach ($prepared as $num => $item) {
            $row = $parsed[$num];
            $verdict = AiLatch::apply(
                $row['verdict'],
                $item['path'],
                $item['truncated'],
                $item['masked_heavy'],
                $row['summary']
            );
            $verdicts->append([
                'scan_id' => $scanId,
                'subject' => 'file',
                'path' => $item['path'],
                'verdict' => $verdict,
                'summary' => $row['summary'],
                'file_time' => (string) ($item['time']['file_time'] ?? ''),
                'file_time_basis' => (string) ($item['time']['file_time_basis'] ?? 'unknown'),
                'first_request_at' => $item['first'],
            ]);
            $outcome = $item['masked'] ? AiReadLog::SENT_MASKED : AiReadLog::SENT;
            if ($log->has($scanId, $item['path'])) {
                $log->setVerdict($scanId, $item['path'], $verdict);
            } else {
                $log->append($scanId, $item['path'], $outcome, $verdict);
            }
        }
        return ['done' => $this->remaining($scanId, $verdicts) === 0, 'reviewed' => count($prepared) + count($unencoded)];
    }

    /**
     * The file stays in the queue result so later files are still reviewed.
     * It is not cached and it is not sent.
     *
     * @param array $item
     */
    private function storeUnencoded(string $scanId, array $item, AiVerdictStore $verdicts, AiReadLog $log): void {
        $path = (string) $item['path'];
        $log->append($scanId, $path, AiReadLog::NOT_ENCODE, 'unsure');
        $verdicts->append([
            'scan_id' => $scanId,
            'subject' => 'file',
            'path' => $path,
            'verdict' => 'unsure',
            'summary' => self::NOT_ENCODED_SUMMARY,
            'file_time' => (string) ($item['time']['file_time'] ?? ''),
            'file_time_basis' => (string) ($item['time']['file_time_basis'] ?? 'unknown'),
            'first_request_at' => (string) ($item['first'] ?? ''),
        ]);
    }

    /** @param array<int,array> $rows */
    private function alreadyJudged(array $rows, array $unit): bool {
        $subject = (string) ($unit['subject'] ?? 'file');
        foreach ($rows as $row) {
            if ($subject === 'database') {
                $ref = (string) ($unit['ref'] ?? '');
                if ($ref !== '' && ($row['ref'] ?? '') === $ref && ($row['subject'] ?? '') === 'database') {
                    return true;
                }
            } else {
                $path = (string) ($unit['path'] ?? '');
                if ($path !== '' && ($row['path'] ?? '') === $path && ($row['subject'] ?? 'file') !== 'database') {
                    return true;
                }
            }
        }
        return false;
    }

    private function remaining(string $scanId, AiVerdictStore $verdicts): int {
        $rows = $verdicts->forScan($scanId);
        $left = 0;
        foreach ($this->store->readJsonl('queue.jsonl') as $unit) {
            if (($unit['scan_id'] ?? '') === $scanId && !$this->alreadyJudged($rows, $unit)) {
                $left++;
            }
        }
        return $left;
    }

    private function storeDatabaseUnsure(string $scanId, array $unit, AiVerdictStore $verdicts): void {
        $ref = (string) ($unit['ref'] ?? '');
        $verdicts->append([
            'scan_id' => $scanId,
            'subject' => 'database',
            'path' => '',
            'ref' => $ref,
            'verdict' => 'unsure',
            'summary' => 'Database AI review is not in this version.',
            'file_time_basis' => 'unknown',
        ]);
        (new AiReadLog($this->store))->append($scanId, $ref, AiReadLog::NOT_DATABASE, 'unsure', 'database');
    }

    /** @return array{verdict:string,summary:string}|null */
    private function cacheGet(string $text): ?array {
        $file = $this->cacheFile($text);
        if (!is_readable($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || empty($data['verdict'])) {
            return null;
        }
        $age = time() - (int) ($data['stored_at'] ?? 0);
        if ($age > 30 * 86400) {
            return null;
        }
        return [
            'verdict' => (string) $data['verdict'],
            'summary' => (string) ($data['summary'] ?? ''),
        ];
    }

    /** @param array{verdict:string,summary:string} $row */
    private function cachePut(string $text, array $row): void {
        $this->store->ensure();
        $file = $this->cacheFile($text);
        @file_put_contents($file, json_encode([
            'verdict' => $row['verdict'],
            'summary' => $row['summary'],
            'stored_at' => time(),
        ]));
        @chmod($file, 0600);
    }

    /**
     * @return array{file_time:string,file_time_basis:string}
     */
    private function fileTime(string $abs, string $path): array {
        $mtime = null;
        $ctime = null;
        $inode = true;
        if (class_exists('CleanSweep_VisitCapabilities')) {
            $caps = CleanSweep_VisitCapabilities::instance();
            $mtime = $caps->mtime($abs);
            $ctime = $caps->ctime($abs);
            $inode = $caps->ctime_is_inode;
        } else {
            $m = @filemtime($abs);
            $c = @filectime($abs);
            $mtime = $m === false ? null : (int) $m;
            $ctime = $c === false ? null : (int) $c;
        }
        $watch = $this->watchMap()[$path] ?? null;
        return AiFileTime::decide($mtime, $ctime, $watch, $inode);
    }

    /** @return array<string,int> */
    private function watchMap(): array {
        if ($this->watchMap !== null) {
            return $this->watchMap;
        }
        $this->watchMap = [];
        if (!class_exists('CleanSweep_VisitWatch')) {
            return $this->watchMap;
        }
        try {
            $events = (new CleanSweep_VisitWatch())->recent_events(200);
        } catch (Throwable $e) {
            return $this->watchMap;
        }
        if (!is_array($events)) {
            return $this->watchMap;
        }
        $cut = time() - 172800;
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $when = (int) ($ev['first_seen'] ?? $ev['t'] ?? 0);
            if ($when < $cut || $when <= 0) {
                continue;
            }
            $rel = ltrim(AiPaths::rel((string) ($ev['path'] ?? ''), $this->root), '/');
            if ($rel === '') {
                continue;
            }
            if (!isset($this->watchMap[$rel]) || $when < $this->watchMap[$rel]) {
                $this->watchMap[$rel] = $when;
            }
        }
        return $this->watchMap;
    }

    private function cacheFile(string $text): string {
        $hash = hash('sha256', $this->model . '|' . AiPrompt::PROMPT_VERSION . '|' . $text);
        return $this->store->path('cache/' . $hash . '.json');
    }
}
