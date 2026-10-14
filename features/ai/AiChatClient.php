<?php
/**
 * POST /chat/completions. One retry on HTTP 429 or 5xx.
 */
final class AiChatClient {

    public const ENCODE_ERROR = 'Could not encode the request.';
    public const EMPTY_ANSWER = 'The provider answered, but the answer was empty.';

    private string $baseUrl;
    private string $key;
    private int $timeout;

    public function __construct(string $baseUrl, string $key, int $timeout) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->key = $key;
        $this->timeout = max(5, $timeout);
    }

    public static function timeoutForHost(): int {
        $budget = 35;
        $phpLimit = 0;
        if (class_exists('CleanSweep_HostDetector')) {
            try {
                $host = new CleanSweep_HostDetector();
                $budget = $host->recommendedTimeBudget();
                $phpLimit = (int) $host->max_execution_time;
            } catch (Throwable $e) {
                $budget = 35;
            }
        }
        if ($phpLimit <= 0) {
            $phpLimit = (int) ini_get('max_execution_time');
        }
        // A short host still ends the call before PHP or the gateway does.
        if ($budget < 20 || ($phpLimit > 0 && $phpLimit <= 45)) {
            return $budget < 20 ? 15 : 30;
        }
        // Reasoning models often stay silent longer than 30s. Leave headroom
        // under PHP's limit. An unlimited limit still caps the wait.
        $room = $phpLimit > 0 ? $phpLimit - 20 : 90;
        return max(30, min(75, $room));
    }

    /**
     * Curl stopped the transfer. A partial body is still a failed call.
     * A timeout is recognized even when some bytes arrived.
     */
    public static function curlAbandoned(string $raw, string $err): bool {
        if ($err === '') {
            return false;
        }
        if ($raw === '') {
            return true;
        }
        $message = strtolower($err);
        return strpos($message, 'timed out') !== false || strpos($message, 'timeout was reached') !== false;
    }

    /**
     * @return array{ok:bool,content:string,http:int,error:string,usage?:array}
     */
    public function complete(string $model, string $system, string $user, int $maxTokens): array {
        $attempt = 0;
        $last = ['ok' => false, 'content' => '', 'http' => 0, 'error' => 'No request was made.'];
        while ($attempt < 2) {
            $attempt++;
            $last = $this->once($model, $system, $user, $maxTokens);
            if ($last['ok']) {
                return $last;
            }
            if ($last['http'] !== 429 && ($last['http'] < 500 || $last['http'] > 599)) {
                return $last;
            }
        }
        return $last;
    }

    /**
     * @return array{ok:bool,content:string,http:int,error:string,usage?:array}
     */
    /**
     * Output cap for one chat completion.
     * OpenAI rejects max_tokens on current models. SpaceXAI and OpenRouter still require it.
     * The two names are never sent together.
     *
     * @return array{model:string,messages:array,max_tokens?:int,max_completion_tokens?:int}
     */
    public static function completionBody(string $baseUrl, string $model, string $system, string $user, int $maxTokens): array {
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $limit = $host === 'api.openai.com' ? 'max_completion_tokens' : 'max_tokens';
        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            $limit => $maxTokens,
        ];
    }

    /**
     * A file excerpt that is not valid UTF-8 cannot be placed in the JSON request.
     */
    public static function encodable(string $text): bool {
        return json_encode($text) !== false;
    }

    private function once(string $model, string $system, string $user, int $maxTokens): array {
        $url = $this->baseUrl . '/chat/completions';
        $body = json_encode(self::completionBody($this->baseUrl, $model, $system, $user, $maxTokens));
        if ($body === false) {
            return ['ok' => false, 'content' => '', 'http' => 0, 'error' => self::ENCODE_ERROR];
        }
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->key,
        ];
        $raw = '';
        $http = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            ]);
            $raw = (string) curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if (self::curlAbandoned($raw, $err)) {
                return ['ok' => false, 'content' => '', 'http' => $http, 'error' => $err];
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers),
                    'content' => $body,
                    'timeout' => $this->timeout,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = (string) @file_get_contents($url, false, $ctx);
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $http = (int) $m[1];
            }
        }
        $decoded = json_decode($raw, true);
        $content = '';
        $usage = null;
        if (is_array($decoded)) {
            $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
            if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                $usage = AiUsage::fromProvider($decoded['usage']);
            }
        }
        if ($http >= 200 && $http < 300 && $content !== '') {
            $reply = ['ok' => true, 'content' => $content, 'http' => $http, 'error' => ''];
            if ($usage !== null) {
                $reply['usage'] = $usage;
            }
            return $reply;
        }
        $apiError = self::apiErrorMessage($raw);
        if ($http >= 200 && $http < 300 && $content === '' && $apiError === '') {
            $reply = ['ok' => false, 'content' => '', 'http' => $http, 'error' => self::EMPTY_ANSWER, 'empty' => true];
            if ($usage !== null) {
                $reply['usage'] = $usage;
            }
            return $reply;
        }
        $message = self::providerError($raw, $http, '', 'The provider did not return a completion.');
        $reply = ['ok' => false, 'content' => $content, 'http' => $http, 'error' => $message];
        if ($usage !== null) {
            $reply['usage'] = $usage;
        }
        return $reply;
    }

    /**
     * Context window from a model row, when the provider sends one.
     * A missing field stays unknown. This does not guess from the model id.
     *
     * @param array $row
     */
    public static function contextWindow(array $row): int {
        $direct = self::positiveInt($row, ['context_length', 'context_window', 'max_context_length', 'max_model_len']);
        if ($direct > 0) {
            return $direct;
        }
        if (isset($row['top_provider']) && is_array($row['top_provider'])) {
            return self::positiveInt($row['top_provider'], ['context_length', 'context_window', 'max_context_length', 'max_model_len']);
        }
        return 0;
    }

    /**
     * @param array $decoded
     * @return array<string,int>
     */
    public static function windowsFromModels(array $decoded): array {
        $rows = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
        $windows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $window = self::contextWindow($row);
            if ($window < 1) {
                continue;
            }
            foreach (self::modelIds($row) as $id) {
                $windows[$id] = $window;
            }
        }
        return $windows;
    }

    /**
     * List price from a model row, when the provider sends one.
     * OpenRouter uses pricing.prompt and pricing.completion as USD-per-token strings.
     * A rate of at least 0.01 is USD per 1 million tokens and is stored per token.
     * The request fee stays USD per request. -1 means unknown and is skipped.
     * SpaceXAI sends prompt_text_token_price and completion_text_token_price on the
     * model row, in USD cents per 100 million tokens. Those are stored per token.
     * A cached rate uses the same unit. Long-context rates are not copied: the scan
     * stores sums, and the reply's tick charge already includes that tier.
     * A model needs both an input rate and an output rate. A zero rate is free, not unknown.
     * This does not guess a price from the model id.
     *
     * @param array $decoded
     * @return array<string,array{input:float,output:float,cached?:float,cache_write?:float,request?:float}>
     */
    public static function pricesFromModels(array $decoded): array {
        $rows = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
        $prices = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rate = self::priceOfRow($row);
            if ($rate === null) {
                continue;
            }
            foreach (self::modelIds($row) as $id) {
                $prices[$id] = $rate;
            }
        }
        return $prices;
    }

    /**
     * @param array $row
     * @return string[]
     */
    private static function modelIds(array $row): array {
        $ids = [];
        if (!empty($row['id'])) {
            $id = trim((string) $row['id']);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if (isset($row['aliases']) && is_array($row['aliases'])) {
            foreach ($row['aliases'] as $alias) {
                if (is_string($alias) && trim($alias) !== '') {
                    $ids[] = trim($alias);
                }
            }
        }
        return $ids;
    }

    /**
     * @param array $row
     * @return array{input:float,output:float,cached?:float,cache_write?:float,request?:float}|null
     */
    private static function priceOfRow(array $row): ?array {
        $src = null;
        if (isset($row['pricing']) && is_array($row['pricing'])) {
            $src = $row['pricing'];
        } elseif (isset($row['price']) && is_array($row['price'])) {
            $src = $row['price'];
        }
        if ($src !== null) {
            $rate = self::rateFromMap($src);
            if ($rate !== null) {
                return $rate;
            }
        }
        return self::xaiListRate($row);
    }

    /**
     * OpenRouter pricing map, or a price map with the same names.
     *
     * @param array $src
     * @return array{input:float,output:float,cached?:float,cache_write?:float,request?:float}|null
     */
    private static function rateFromMap(array $src): ?array {
        $input = self::perToken($src, ['prompt', 'input']);
        $output = self::perToken($src, ['completion', 'output']);
        if ($input === null || $output === null) {
            return null;
        }
        $rate = ['input' => $input, 'output' => $output];
        $cached = self::perToken($src, ['input_cache_read', 'cached_input', 'cache_read']);
        if ($cached !== null) {
            $rate['cached'] = $cached;
        }
        $write = self::perToken($src, ['input_cache_write', 'cache_write']);
        if ($write !== null) {
            $rate['cache_write'] = $write;
        }
        $request = self::nonNegative($src, ['request']);
        if ($request !== null) {
            $rate['request'] = round($request, 12);
        }
        return $rate;
    }

    /**
     * SpaceXAI list rate. Integers are USD cents per 100 million tokens.
     * That unit is not the OpenRouter per-token string and not USD per million.
     *
     * @param array $row
     * @return array{input:float,output:float,cached?:float}|null
     */
    private static function xaiListRate(array $row): ?array {
        $input = self::centsPerHundredMillion($row, 'prompt_text_token_price');
        $output = self::centsPerHundredMillion($row, 'completion_text_token_price');
        if ($input === null || $output === null) {
            return null;
        }
        $rate = ['input' => $input, 'output' => $output];
        $cached = self::centsPerHundredMillion($row, 'cached_prompt_text_token_price');
        if ($cached !== null) {
            $rate['cached'] = $cached;
        }
        return $rate;
    }

    /**
     * USD per token from USD cents per 100 million tokens.
     *
     * @param array $row
     */
    private static function centsPerHundredMillion(array $row, string $key): ?float {
        $n = self::nonNegative($row, [$key]);
        if ($n === null) {
            return null;
        }
        return round($n / 10000000000, 12);
    }

    /**
     * USD per token. A number of 0.01 or more is USD per 1 million tokens.
     *
     * @param array $row
     * @param string[] $keys
     */
    private static function perToken(array $row, array $keys): ?float {
        $n = self::nonNegative($row, $keys);
        if ($n === null) {
            return null;
        }
        if ($n >= 0.01) {
            $n = $n / 1000000;
        }
        return round($n, 12);
    }

    /**
     * @param array $row
     * @param string[] $keys
     */
    private static function nonNegative(array $row, array $keys): ?float {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || is_array($row[$key]) || is_bool($row[$key])) {
                continue;
            }
            $value = $row[$key];
            if (is_string($value)) {
                $value = trim($value);
            }
            if (!is_numeric($value)) {
                continue;
            }
            $n = (float) $value;
            if ($n < 0) {
                continue;
            }
            return $n;
        }
        return null;
    }

    /**
     * @param array $row
     * @param string[] $keys
     */
    private static function positiveInt(array $row, array $keys): int {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || is_array($row[$key])) {
                continue;
            }
            $value = $row[$key];
            $n = 0;
            if (is_int($value)) {
                $n = $value;
            } elseif (is_float($value) && floor($value) === $value) {
                $n = (int) $value;
            } elseif (is_string($value) && preg_match('/^\d+$/', $value)) {
                $n = (int) $value;
            }
            if ($n > 0) {
                return $n;
            }
        }
        return 0;
    }

    /**
     * @return array{ok:bool,models:string[],windows:array<string,int>,prices:array<string,array>,error:string}
     */
    public function listModels(): array {
        $url = $this->baseUrl . '/models';
        $headers = ['Authorization: Bearer ' . $this->key];
        $raw = '';
        $http = 0;
        $curlError = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
            ]);
            $raw = (string) curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = (string) curl_error($ch);
            curl_close($ch);
        }
        $decoded = json_decode($raw, true);
        $ids = [];
        $decodedRows = is_array($decoded) ? $decoded : [];
        $windows = self::windowsFromModels($decodedRows);
        $prices = self::pricesFromModels($decodedRows);
        if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
            foreach ($decoded['data'] as $row) {
                if (is_array($row) && !empty($row['id'])) {
                    $ids[] = (string) $row['id'];
                }
            }
        }
        if ($http >= 200 && $http < 300 && $ids !== []) {
            return ['ok' => true, 'models' => $ids, 'windows' => $windows, 'prices' => $prices, 'error' => ''];
        }
        $message = self::providerError($raw, $http, $curlError, 'Could not load models.');
        return ['ok' => false, 'models' => [], 'windows' => [], 'prices' => [], 'error' => $message];
    }

    /**
     * One sentence for a review that cannot continue.
     * The provider text is kept. A missing sentence still names the HTTP status.
     *
     * @param array{error?:string,http?:int} $reply
     */
    public static function isEmptyAnswer(array $reply): bool {
        if (!empty($reply['empty'])) {
            return true;
        }
        return trim((string) ($reply['error'] ?? '')) === self::EMPTY_ANSWER;
    }

    /**
     * Curl gave up with no HTTP status. The same request is not sent again here.
     * A second wait belongs on a later step, so one PHP request stays inside the host limit.
     *
     * @param array{error?:string,http?:int,empty?:bool} $reply
     */
    public static function isTransportTimeout(array $reply): bool {
        if (self::isEmptyAnswer($reply)) {
            return false;
        }
        $http = (int) ($reply['http'] ?? 0);
        // A provider HTTP error is retried on its own rules. A curl timeout can
        // still carry a status when headers arrived and the body never did.
        if ($http >= 400 && $http <= 599) {
            return false;
        }
        $message = strtolower(trim((string) ($reply['error'] ?? '')));
        if ($message === '') {
            return false;
        }
        return strpos($message, 'timed out') !== false || strpos($message, 'timeout was reached') !== false;
    }

    /**
     * The reason to show for one failed provider call.
     * An HTTP 200 with no answer text stays one clause.
     * An HTTP error keeps the status and the provider's own sentence.
     *
     * @param array{error?:string,http?:int,empty?:bool} $reply
     */
    public static function failureSentence(array $reply): string {
        if (self::isEmptyAnswer($reply)) {
            return self::EMPTY_ANSWER;
        }
        $message = trim((string) ($reply['error'] ?? ''));
        $http = (int) ($reply['http'] ?? 0);
        if ($message === '') {
            $message = $http > 0
                ? 'The provider returned HTTP ' . $http . '.'
                : 'The provider did not return a completion.';
        } elseif ($http >= 400 && strpos($message, 'HTTP ' . $http) === false) {
            $message = 'The provider returned HTTP ' . $http . '. ' . $message;
        }
        if (mb_strlen($message) > 300) {
            $message = mb_substr($message, 0, 300);
        }
        return $message;
    }

    public static function reviewStop(array $reply): string {
        return 'The AI review stopped. ' . self::failureSentence($reply);
    }

    private static function apiErrorMessage(string $raw): string {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('error', $decoded)) {
            return '';
        }
        $error = $decoded['error'];
        if (is_string($error)) {
            return trim($error);
        }
        if (is_array($error)) {
            return trim((string) ($error['message'] ?? ''));
        }
        return '';
    }

    /**
     * The sentence a provider sent, whether error is a string or error.message.
     */
    public static function providerError(string $raw, int $http, string $curlError, string $fallback): string {
        if ($raw === '' && $curlError !== '') {
            return $curlError;
        }
        $message = self::apiErrorMessage($raw);
        if ($message !== '') {
            return $message;
        }
        if ($http > 0) {
            return $fallback . ' The provider returned HTTP ' . $http . '.';
        }
        return $fallback;
    }
}
