<?php
/**
 * Choices for one run. databaseMode() is signature_only in this build.
 */
final class AiPolicy {

    public const DATABASE_SIGNATURE_ONLY = 'signature_only';
    public const DATABASE_SEND = 'send';

    private bool $fullPass;
    private bool $requestLogs;
    private string $databaseMode;
    private AiExcludeList $excludes;
    private string $provider;

    public function __construct(bool $fullPass, bool $requestLogs, AiExcludeList $excludes, string $provider = '', string $databaseMode = self::DATABASE_SIGNATURE_ONLY) {
        $this->fullPass = $fullPass;
        $this->requestLogs = $requestLogs;
        $this->excludes = $excludes;
        $this->provider = $provider;
        $this->databaseMode = $databaseMode === self::DATABASE_SEND
            ? self::DATABASE_SEND
            : self::DATABASE_SIGNATURE_ONLY;
    }

    public static function fromConfig(AiConfig $config, ?AiExcludeList $excludes = null): self {
        $public = $config->getPublic();
        $excludes = $excludes ?: new AiExcludeList($config->store());
        return new self(
            !empty($public['full_pass']),
            !empty($public['request_logs']),
            $excludes,
            (string) $public['provider'],
            self::DATABASE_SIGNATURE_ONLY
        );
    }

    public static function fromSnapshot(array $snap, AiExcludeList $excludes): self {
        return new self(
            !empty($snap['full_pass']),
            array_key_exists('request_logs', $snap) ? !empty($snap['request_logs']) : true,
            $excludes,
            (string) ($snap['provider'] ?? ''),
            self::DATABASE_SIGNATURE_ONLY
        );
    }

    public function fullPass(): bool {
        return $this->fullPass;
    }

    public function requestLogs(): bool {
        return $this->requestLogs;
    }

    public function includeQueryStrings(): bool {
        return $this->fullPass;
    }

    public function databaseMode(): string {
        return self::DATABASE_SIGNATURE_ONLY;
    }

    public function provider(): string {
        return $this->provider;
    }

    public function excludes(): AiExcludeList {
        return $this->excludes;
    }

    public function snapshot(): array {
        return [
            'full_pass' => $this->fullPass,
            'request_logs' => $this->requestLogs,
            'database_ai' => self::DATABASE_SIGNATURE_ONLY,
            'provider' => $this->provider,
            'prefixes' => $this->excludes->all(),
        ];
    }
}
