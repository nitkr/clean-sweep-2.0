<?php
/**
 * Provider key and model. getPublic() never returns the key.
 */
final class AiConfig {

    public const PROVIDERS = [
        'SpaceXAI' => 'https://api.x.ai/v1',
        'OpenAI' => 'https://api.openai.com/v1',
        'OpenRouter' => 'https://openrouter.ai/api/v1',
    ];

    private AiStore $store;

    public function __construct(?AiStore $store = null) {
        $this->store = $store ?: new AiStore();
    }

    public function store(): AiStore {
        return $this->store;
    }

    public function raw(): array {
        $data = $this->store->readJson('config.json', []);
        $data['provider'] = (string) ($data['provider'] ?? '');
        $data['key'] = (string) ($data['key'] ?? '');
        $data['model'] = (string) ($data['model'] ?? '');
        $data['tested'] = !empty($data['tested']);
        $data['request_logs'] = array_key_exists('request_logs', $data) ? !empty($data['request_logs']) : true;
        $data['full_pass'] = is_array($data['full_pass'] ?? null) ? $data['full_pass'] : [];
        $data['database_ai'] = 'signature_only';
        return $data;
    }

    public function getPublic(): array {
        $raw = $this->raw();
        $provider = $raw['provider'];
        $full = !empty($raw['full_pass'][$provider]);
        return [
            'provider' => $provider,
            'has_key' => $raw['key'] !== '',
            'key_hint' => $raw['key'] === '' ? '' : substr($raw['key'], -4),
            'model' => $raw['model'],
            'tested' => $raw['tested'] && $raw['key'] !== '' && $raw['model'] !== '',
            'request_logs' => $raw['request_logs'],
            'full_pass' => $full,
            'database_ai' => 'signature_only',
            'base_url' => self::PROVIDERS[$provider] ?? '',
        ];
    }

    /**
     * @return array{ok:bool,error?:string,code?:string}
     */
    public function save(array $input): array {
        $raw = $this->raw();
        $provider = isset($input['provider']) ? trim((string) $input['provider']) : $raw['provider'];
        if ($provider !== '' && !isset(self::PROVIDERS[$provider])) {
            return ['ok' => false, 'error' => 'Unknown provider.', 'code' => 'UNKNOWN_PROVIDER'];
        }
        $providerChanged = $provider !== $raw['provider'];
        if ($providerChanged) {
            $raw['key'] = '';
            $raw['model'] = '';
            $raw['tested'] = false;
            $raw['full_pass'][$provider] = false;
            $raw['context_windows'] = [];
            $raw['model_prices'] = [];
        }
        $raw['provider'] = $provider;

        if (array_key_exists('key', $input)) {
            $key = trim((string) $input['key']);
            if ($key !== '' && $key !== $raw['key']) {
                $raw['key'] = $key;
                $raw['tested'] = false;
            }
        }
        if (array_key_exists('model', $input)) {
            $model = trim((string) $input['model']);
            if ($model !== $raw['model']) {
                $raw['model'] = $model;
                $raw['tested'] = false;
            }
        }
        if (array_key_exists('request_logs', $input)) {
            $raw['request_logs'] = self::toBool($input['request_logs']);
        }
        if (array_key_exists('full_pass', $input) && $provider !== '') {
            $raw['full_pass'][$provider] = self::toBool($input['full_pass']);
        }
        if (isset($input['database_ai']) && (string) $input['database_ai'] === 'send') {
            return ['ok' => false, 'error' => 'Database AI review is not in this version.', 'code' => 'DATABASE_AI_UNAVAILABLE'];
        }
        $raw['database_ai'] = 'signature_only';
        unset($raw['key_hint']);
        $this->persist($raw);
        return ['ok' => true];
    }

    public function markTested(bool $ok): void {
        $raw = $this->raw();
        $raw['tested'] = $ok && $raw['key'] !== '' && $raw['model'] !== '';
        $this->persist($raw);
    }

    /**
     * Model id to context window, from the provider's model list.
     * A model with no reported window is absent.
     *
     * @return array<string,int>
     */
    public function contextWindows(): array {
        $raw = $this->raw();
        return self::cleanWindows(is_array($raw['context_windows'] ?? null) ? $raw['context_windows'] : []);
    }

    /** @param array<mixed,mixed> $windows */
    public function rememberWindows(array $windows): void {
        $raw = $this->raw();
        $merged = is_array($raw['context_windows'] ?? null) ? $raw['context_windows'] : [];
        foreach ($windows as $id => $n) {
            $merged[$id] = $n;
        }
        $raw['context_windows'] = $merged;
        $this->persist($raw);
    }

    /**
     * Model id to list price, already normalized to USD per token.
     * The request fee is USD per request. A model with no rate is absent.
     *
     * @return array<string,array>
     */
    public function modelPrices(): array {
        $raw = $this->raw();
        return self::cleanPrices(is_array($raw['model_prices'] ?? null) ? $raw['model_prices'] : []);
    }

    /** @param array<mixed,mixed> $prices */
    public function rememberPrices(array $prices): void {
        $raw = $this->raw();
        $merged = is_array($raw['model_prices'] ?? null) ? $raw['model_prices'] : [];
        foreach ($prices as $id => $rate) {
            $merged[$id] = $rate;
        }
        $raw['model_prices'] = $merged;
        $this->persist($raw);
    }

    /** @return array{input:float,output:float,cached?:float,cache_write?:float,request?:float}|null */
    public function priceFor(string $model): ?array {
        $model = trim($model);
        if ($model === '') {
            return null;
        }
        $prices = $this->modelPrices();
        return $prices[$model] ?? null;
    }

    /** @param array $raw */
    private function persist(array $raw): void {
        $this->store->writeJson('config.json', [
            'provider' => (string) ($raw['provider'] ?? ''),
            'key' => (string) ($raw['key'] ?? ''),
            'model' => (string) ($raw['model'] ?? ''),
            'tested' => !empty($raw['tested']),
            'request_logs' => array_key_exists('request_logs', $raw) ? !empty($raw['request_logs']) : true,
            'full_pass' => is_array($raw['full_pass'] ?? null) ? $raw['full_pass'] : [],
            'database_ai' => 'signature_only',
            'context_windows' => self::cleanWindows(is_array($raw['context_windows'] ?? null) ? $raw['context_windows'] : []),
            'model_prices' => self::cleanPrices(is_array($raw['model_prices'] ?? null) ? $raw['model_prices'] : []),
        ]);
    }

    /**
     * @param array<mixed,mixed> $windows
     * @return array<string,int>
     */
    private static function cleanWindows(array $windows): array {
        $out = [];
        foreach ($windows as $id => $n) {
            $id = trim((string) $id);
            if ($id === '' || is_array($n) || !is_numeric($n) || (int) $n < 1) {
                continue;
            }
            $out[$id] = (int) $n;
        }
        return $out;
    }

    /**
     * @param array<mixed,mixed> $prices
     * @return array<string,array>
     */
    private static function cleanPrices(array $prices): array {
        $out = [];
        foreach ($prices as $id => $rate) {
            $id = trim((string) $id);
            if ($id === '' || !is_array($rate)) {
                continue;
            }
            $clean = AiUsage::cleanPrice($rate);
            if ($clean !== null) {
                $out[$id] = $clean;
            }
        }
        return $out;
    }

    public function key(): string {
        return $this->raw()['key'];
    }

    public function baseUrl(): string {
        $provider = $this->raw()['provider'];
        return self::PROVIDERS[$provider] ?? '';
    }

    /** @param mixed $v */
    public static function toBool($v): bool {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return ((int) $v) === 1;
        }
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
