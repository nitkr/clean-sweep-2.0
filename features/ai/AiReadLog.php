<?php
/**
 * Paths the AI pass touched. Rows never store excerpts or keys.
 */
final class AiReadLog {

    public const SENT = 'Sent';
    public const SENT_MASKED = 'Sent, values masked';
    public const NOT_SECRET = 'Not sent — secret file';
    public const NOT_SKIP = 'Not sent — your skip list';
    public const NOT_DATABASE = 'Not sent — database off';
    public const NOT_MISSING = 'Not sent — file missing';
    public const NOT_ENCODE = 'Not sent — could not encode';

    private AiStore $store;

    public function __construct(?AiStore $store = null) {
        $this->store = $store ?: new AiStore();
    }

    public function append(string $scanId, string $path, string $outcome, string $verdict = '', string $subject = 'file'): void {
        $row = [
            'scan_id' => $scanId,
            'subject' => $subject,
            'path' => $path,
            'outcome' => $outcome,
            'verdict' => $verdict,
        ];
        $this->store->appendJsonl('read-log.jsonl', $row);
    }

    public function setVerdict(string $scanId, string $path, string $verdict): void {
        $rows = $this->store->readJsonl('read-log.jsonl');
        $changed = false;
        foreach ($rows as &$row) {
            if (($row['scan_id'] ?? '') === $scanId && ($row['path'] ?? '') === $path && ($row['verdict'] ?? '') === '') {
                $row['verdict'] = $verdict;
                $changed = true;
            }
        }
        unset($row);
        if (!$changed) {
            return;
        }
        $this->store->remove('read-log.jsonl');
        foreach ($rows as $row) {
            $this->store->appendJsonl('read-log.jsonl', $row);
        }
    }

    /** @return array<int,array> */
    public function forScan(string $scanId, int $offset = 0, int $limit = 200): array {
        $rows = [];
        foreach ($this->store->readJsonl('read-log.jsonl') as $row) {
            if (($row['scan_id'] ?? '') === $scanId) {
                $rows[] = $row;
            }
        }
        return array_slice($rows, max(0, $offset), max(1, $limit));
    }

    public function count(string $scanId): int {
        $n = 0;
        foreach ($this->store->readJsonl('read-log.jsonl') as $row) {
            if (($row['scan_id'] ?? '') === $scanId) {
                $n++;
            }
        }
        return $n;
    }

    public function has(string $scanId, string $path): bool {
        foreach ($this->store->readJsonl('read-log.jsonl') as $row) {
            if (($row['scan_id'] ?? '') === $scanId && ($row['path'] ?? '') === $path) {
                return true;
            }
        }
        return false;
    }
}
