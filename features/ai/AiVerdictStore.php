<?php
/**
 * One verdict per queued subject.
 */
final class AiVerdictStore {

    private AiStore $store;

    public function __construct(?AiStore $store = null) {
        $this->store = $store ?: new AiStore();
    }

    public function append(array $row): void {
        $keep = [
            'scan_id' => (string) ($row['scan_id'] ?? ''),
            'subject' => (string) ($row['subject'] ?? 'file'),
            'path' => (string) ($row['path'] ?? ''),
            'ref' => (string) ($row['ref'] ?? ''),
            'verdict' => (string) ($row['verdict'] ?? 'unsure'),
            'summary' => mb_substr((string) ($row['summary'] ?? ''), 0, 240),
            'file_time' => (string) ($row['file_time'] ?? ''),
            'file_time_basis' => (string) ($row['file_time_basis'] ?? ''),
            'first_request_at' => (string) ($row['first_request_at'] ?? ''),
            'kept' => !empty($row['kept']),
        ];
        $this->store->appendJsonl('verdicts.jsonl', $keep);
    }

    /** @return array<int,array> */
    public function forScan(string $scanId): array {
        $out = [];
        foreach ($this->store->readJsonl('verdicts.jsonl') as $row) {
            if (($row['scan_id'] ?? '') === $scanId) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function hasPath(string $scanId, string $path): bool {
        foreach ($this->forScan($scanId) as $row) {
            if (($row['path'] ?? '') === $path) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,array> path => verdict */
    public function byPath(string $scanId): array {
        $map = [];
        foreach ($this->forScan($scanId) as $row) {
            $path = (string) ($row['path'] ?? '');
            if ($path !== '') {
                $map[$path] = $row;
            }
        }
        return $map;
    }
}
