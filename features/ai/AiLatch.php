<?php
/**
 * Downgrade likely_safe when the excerpt is not safe to clear.
 * A path under uploads can stay likely_safe when the model had enough text to decide.
 */
final class AiLatch {

    public static function apply(string $verdict, string $rel, bool $truncated, bool $maskedHeavy, string $summary): string {
        if ($verdict !== 'likely_safe') {
            return $verdict;
        }
        $rel = ltrim(AiPaths::norm($rel), '/');
        $base = strtolower(basename($rel));
        if ($base === '.htaccess' || $base === '.user.ini') {
            return 'unsure';
        }
        if ($truncated || $maskedHeavy || trim($summary) === '') {
            return 'unsure';
        }
        return 'likely_safe';
    }
}
