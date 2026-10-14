<?php
/**
 * Token totals for one AI scan. Numbers come only from the provider's usage object.
 * SpaceXAI, OpenAI, and OpenRouter share this reader. A missing field stays unknown.
 */
final class AiUsage {

    /** @return array<string,mixed> */
    public static function blank(string $model = ''): array {
        return [
            'model' => $model,
            'context_window' => 0,
            'input' => null,
            'output' => null,
            'total' => null,
            'cached' => null,
            'cache_write' => null,
            'reasoning' => null,
            'apart' => 0,
            'cost' => null,
            'largest_input' => null,
            'requests' => 0,
            'reported' => 0,
            'unreported' => 0,
            'input_missing' => 0,
            'output_missing' => 0,
            'total_missing' => 0,
            'cached_requests' => 0,
            'cache_write_requests' => 0,
            'reasoning_requests' => 0,
            'cost_requests' => 0,
            'review_requests' => 0,
            'review_input' => null,
            'review_output' => null,
            'spread_requests' => 0,
            'spread_input' => null,
            'spread_output' => null,
            'recorded' => true,
        ];
    }

    /**
     * Read one completion's usage object.
     * Accepts chat-completions names and responses-API names.
     * Cached, cache-write, and reasoning counts are subsets and are not added again.
     * usage.cost is the account charge in dollars when the provider sends one.
     * usage.cost_in_usd_ticks is the same charge in ticks (1 USD = 10^10 ticks)
     * and is stored as dollars. A dollar cost wins when both are present.
     * A cost with no token counts is not usage.
     *
     * @param array $usage
     * @return array{input:?int,output:?int,total:?int,cached:?int,cache_write:?int,reasoning:?int,cost:?float}|null
     */
    public static function fromProvider(array $usage): ?array {
        $input = self::intOf($usage, ['input', 'input_tokens', 'prompt_tokens']);
        $output = self::intOf($usage, ['output', 'output_tokens', 'completion_tokens']);
        $total = self::intOf($usage, ['total', 'total_tokens']);
        if ($input === null && $output === null && $total === null) {
            return null;
        }
        if ($total === null && $input !== null && $output !== null) {
            $total = $input + $output;
        }
        $promptDetails = isset($usage['prompt_tokens_details']) && is_array($usage['prompt_tokens_details'])
            ? $usage['prompt_tokens_details'] : [];
        $inputDetails = isset($usage['input_tokens_details']) && is_array($usage['input_tokens_details'])
            ? $usage['input_tokens_details'] : [];
        $completionDetails = isset($usage['completion_tokens_details']) && is_array($usage['completion_tokens_details'])
            ? $usage['completion_tokens_details'] : [];
        $outputDetails = isset($usage['output_tokens_details']) && is_array($usage['output_tokens_details'])
            ? $usage['output_tokens_details'] : [];

        $cached = self::intOf($usage, ['cached', 'cached_tokens']);
        if ($cached === null) {
            $cached = self::intOf($promptDetails, ['cached_tokens']);
        }
        if ($cached === null) {
            $cached = self::intOf($inputDetails, ['cached_tokens']);
        }

        $cacheWrite = self::intOf($usage, ['cache_write', 'cache_write_tokens']);
        if ($cacheWrite === null) {
            $cacheWrite = self::intOf($promptDetails, ['cache_write_tokens']);
        }
        if ($cacheWrite === null) {
            $cacheWrite = self::intOf($inputDetails, ['cache_write_tokens']);
        }

        $reasoning = self::intOf($usage, ['reasoning', 'reasoning_tokens']);
        if ($reasoning === null) {
            $reasoning = self::intOf($completionDetails, ['reasoning_tokens']);
        }
        if ($reasoning === null) {
            $reasoning = self::intOf($outputDetails, ['reasoning_tokens']);
        }

        return [
            'input' => $input,
            'output' => $output,
            'total' => $total,
            'cached' => $cached,
            'cache_write' => $cacheWrite,
            'reasoning' => $reasoning,
            'cost' => self::chargeOf($usage),
        ];
    }

    /**
     * Account charge in dollars. Ticks are converted. They are not added to a dollar cost.
     *
     * @param array $usage
     */
    private static function chargeOf(array $usage): ?float {
        $dollars = self::floatOf($usage, ['cost']);
        if ($dollars !== null) {
            return $dollars;
        }
        $ticks = self::floatOf($usage, ['cost_in_usd_ticks']);
        if ($ticks === null) {
            return null;
        }
        return $ticks / 10000000000;
    }

    /**
     * Count one provider call. A reply with no usage object increments the request
     * count and does not invent token numbers.
     * $phase is review for a file batch and spread for the follow-up question.
     *
     * @param array $sum
     * @param array $reply
     */
    public static function add(array $sum, array $reply, string $phase = 'review'): array {
        if (!isset($sum['requests'])) {
            $sum = self::blank((string) ($sum['model'] ?? ''));
        }
        $sum = self::ensure($sum);
        $sum['requests'] = (int) $sum['requests'] + 1;
        $bucket = $phase === 'spread' ? 'spread' : 'review';
        $sum[$bucket . '_requests'] = (int) $sum[$bucket . '_requests'] + 1;
        $parsed = null;
        if (isset($reply['usage']) && is_array($reply['usage'])) {
            $parsed = self::fromProvider($reply['usage']);
        }
        if ($parsed === null) {
            $sum['unreported'] = (int) ($sum['unreported'] ?? 0) + 1;
            return $sum;
        }
        $sum['reported'] = (int) ($sum['reported'] ?? 0) + 1;
        foreach (['input', 'output', 'total'] as $key) {
            if ($parsed[$key] === null) {
                $sum[$key . '_missing'] = (int) ($sum[$key . '_missing'] ?? 0) + 1;
                continue;
            }
            $sum[$key] = ($sum[$key] === null ? 0 : (int) $sum[$key]) + (int) $parsed[$key];
            $part = $bucket . '_' . $key;
            if ($key !== 'total') {
                $sum[$part] = ($sum[$part] === null ? 0 : (int) $sum[$part]) + (int) $parsed[$key];
            }
        }
        if ($parsed['input'] !== null && $parsed['output'] !== null && $parsed['total'] !== null) {
            $gap = (int) $parsed['total'] - (int) $parsed['input'] - (int) $parsed['output'];
            if ($gap > 0) {
                $sum['apart'] = (int) $sum['apart'] + $gap;
            }
        }
        if ($parsed['input'] !== null) {
            $size = (int) $parsed['input'];
            $sum['largest_input'] = $sum['largest_input'] === null
                ? $size
                : max((int) $sum['largest_input'], $size);
        }
        self::addOptional($sum, $parsed, 'cached', 'cached_requests', false);
        self::addOptional($sum, $parsed, 'cache_write', 'cache_write_requests', false);
        self::addOptional($sum, $parsed, 'reasoning', 'reasoning_requests', false);
        self::addOptional($sum, $parsed, 'cost', 'cost_requests', true);
        return $sum;
    }

    /**
     * Report view. An older block with no cost_requests key is not given a price claim.
     * An older block with no stored gap derives one when every reply reported input, output, and total.
     *
     * @param array $usage
     */
    public static function forReport(array $usage): array {
        $view = self::blank(str_replace(["\r", "\n"], '', (string) ($usage['model'] ?? '')));
        $view['context_window'] = max(0, (int) ($usage['context_window'] ?? 0));
        $view['requests'] = max(0, (int) ($usage['requests'] ?? 0));
        $view['reported'] = max(0, (int) ($usage['reported'] ?? 0));
        $view['unreported'] = max(0, (int) ($usage['unreported'] ?? 0));
        foreach (['input', 'output', 'total', 'largest_input', 'cached', 'cache_write', 'reasoning', 'review_input', 'review_output', 'spread_input', 'spread_output'] as $key) {
            if (array_key_exists($key, $usage) && $usage[$key] !== null && is_numeric($usage[$key]) && (int) $usage[$key] >= 0) {
                $view[$key] = (int) $usage[$key];
            }
        }
        foreach (['input_missing', 'output_missing', 'total_missing', 'cached_requests', 'cache_write_requests', 'reasoning_requests', 'review_requests', 'spread_requests'] as $key) {
            $view[$key] = max(0, (int) ($usage[$key] ?? 0));
        }
        if (array_key_exists('apart', $usage) && is_numeric($usage['apart'])) {
            $view['apart'] = max(0, (int) $usage['apart']);
        } elseif (
            $view['input'] !== null && $view['output'] !== null && $view['total'] !== null
            && $view['input_missing'] === 0 && $view['output_missing'] === 0 && $view['total_missing'] === 0
        ) {
            $view['apart'] = max(0, $view['total'] - $view['input'] - $view['output']);
        }
        if (array_key_exists('cost_requests', $usage)) {
            $view['cost_requests'] = max(0, (int) $usage['cost_requests']);
            if ($view['cost_requests'] > 0 && isset($usage['cost']) && is_numeric($usage['cost']) && (float) $usage['cost'] >= 0) {
                $view['cost'] = round((float) $usage['cost'], 8);
            } else {
                $view['cost'] = null;
            }
        } else {
            unset($view['cost'], $view['cost_requests']);
        }
        if (isset($usage['price']) && is_array($usage['price'])) {
            $price = self::cleanPrice($usage['price']);
            if ($price !== null) {
                $view['price'] = $price;
            }
        }
        return $view;
    }

    /**
     * A stored model-list rate. Input and output are required. Rates are USD per token,
     * and request is USD per request. Already normalized. This does not convert units.
     *
     * @param array $rate
     * @return array{input:float,output:float,cached?:float,cache_write?:float,request?:float}|null
     */
    public static function cleanPrice(array $rate): ?array {
        $input = self::rateOf($rate, 'input');
        $output = self::rateOf($rate, 'output');
        if ($input === null || $output === null) {
            return null;
        }
        $clean = ['input' => $input, 'output' => $output];
        foreach (['cached', 'cache_write', 'request'] as $key) {
            $n = self::rateOf($rate, $key);
            if ($n !== null) {
                $clean[$key] = $n;
            }
        }
        return $clean;
    }

    /**
     * Fill keys added after a scan started, without wiping totals already stored.
     *
     * @param array $sum
     */
    private static function ensure(array $sum): array {
        foreach (self::blank((string) ($sum['model'] ?? '')) as $key => $value) {
            if (!array_key_exists($key, $sum)) {
                $sum[$key] = $value;
            }
        }
        return $sum;
    }

    /** @param array $sum @param array $parsed */
    private static function addOptional(array &$sum, array $parsed, string $key, string $counter, bool $float): void {
        if (!array_key_exists($key, $parsed) || $parsed[$key] === null) {
            return;
        }
        $sum[$counter] = (int) ($sum[$counter] ?? 0) + 1;
        if ($float) {
            $sum[$key] = ($sum[$key] === null ? 0.0 : (float) $sum[$key]) + (float) $parsed[$key];
            return;
        }
        $sum[$key] = ($sum[$key] === null ? 0 : (int) $sum[$key]) + (int) $parsed[$key];
    }

    /**
     * @param array $row
     * @param string[] $keys
     */
    private static function intOf(array $row, array $keys): ?int {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || is_array($row[$key]) || is_bool($row[$key])) {
                continue;
            }
            $value = $row[$key];
            if (is_int($value) && $value >= 0) {
                return $value;
            }
            if (is_float($value) && $value >= 0 && floor($value) === $value) {
                return (int) $value;
            }
            if (is_string($value) && preg_match('/^\d+$/', $value)) {
                return (int) $value;
            }
        }
        return null;
    }

    /**
     * @param array $row
     * @param string[] $keys
     */
    private static function floatOf(array $row, array $keys): ?float {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || is_array($row[$key]) || is_bool($row[$key])) {
                continue;
            }
            $value = $row[$key];
            if (is_string($value) && is_numeric($value)) {
                $value = (float) $value;
            }
            if ((is_int($value) || is_float($value)) && $value >= 0) {
                return (float) $value;
            }
        }
        return null;
    }

    /** @param array $rate */
    private static function rateOf(array $rate, string $key): ?float {
        if (!array_key_exists($key, $rate) || is_array($rate[$key]) || is_bool($rate[$key]) || !is_numeric($rate[$key])) {
            return null;
        }
        $n = (float) $rate[$key];
        if ($n < 0) {
            return null;
        }
        return round($n, 12);
    }
}
