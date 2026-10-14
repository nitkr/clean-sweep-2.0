<?php
/**
 * Parse one model reply into per-file verdicts.
 */
final class AiReplyParser {

    public const NOT_JSON = 'The model reply was not the expected JSON.';

    public const LEFT_OUT = 'The model reply left this file out.';

    /**
     * @param int[] $fileNumbers
     * @return array<int,array{verdict:string,summary:string,cache?:bool}>
     */
    public static function parse(string $body, array $fileNumbers): array {
        $decoded = self::decode($body);
        $byFile = [];
        $bodyRead = is_array($decoded) && isset($decoded['results']) && is_array($decoded['results']);
        if ($bodyRead) {
            foreach ($decoded['results'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $n = isset($item['file']) ? (int) $item['file'] : 0;
                $verdict = (string) ($item['verdict'] ?? '');
                if (!in_array($verdict, ['malware', 'likely_safe', 'unsure'], true)) {
                    continue;
                }
                $byFile[$n] = [
                    'verdict' => $verdict,
                    'summary' => mb_substr(trim((string) ($item['summary'] ?? '')), 0, 240),
                ];
            }
        }
        $out = [];
        foreach ($fileNumbers as $n) {
            if (isset($byFile[$n])) {
                $out[$n] = $byFile[$n];
            } else {
                $out[$n] = [
                    'verdict' => 'unsure',
                    'summary' => $bodyRead ? self::LEFT_OUT : self::NOT_JSON,
                    'cache' => false,
                ];
            }
        }
        return $out;
    }

    /** @return array|null */
    private static function decode(string $body) {
        $body = trim($body);
        $data = json_decode($body, true);
        if (is_array($data)) {
            return $data;
        }
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $body, $m)) {
            $data = json_decode(trim($m[1]), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }
}
