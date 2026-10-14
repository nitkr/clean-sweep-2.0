<?php
/**
 * Replace password-shaped values before an excerpt is sent.
 */
final class AiSecretMask {

    /**
     * @return array{text:string,masked:bool,masked_heavy:bool}
     */
    public static function apply(string $text): array {
        $masked = false;
        $heavy = false;
        $lines = preg_split("/\r\n|\n|\r/", $text);
        if (!is_array($lines)) {
            return ['text' => $text, 'masked' => false, 'masked_heavy' => false];
        }
        $out = [];
        $inPem = false;
        foreach ($lines as $line) {
            if (strpos($line, '-----BEGIN') !== false) {
                $inPem = true;
                $masked = true;
                if (!self::pemIsPublic($line)) {
                    $heavy = true;
                }
                $out[] = '[withheld]';
                continue;
            }
            if ($inPem) {
                if (strpos($line, '-----END') !== false) {
                    $inPem = false;
                }
                continue;
            }
            $replaced = self::maskLine($line, $heavy);
            if ($replaced !== $line) {
                $masked = true;
            }
            $out[] = $replaced;
        }
        return [
            'text' => implode("\n", $out),
            'masked' => $masked,
            'masked_heavy' => $heavy,
        ];
    }

    private static function maskLine(string $line, bool &$heavy): string {
        $pattern = '/([\'"]?)([A-Za-z0-9_]*(?:PASSWORD|PASSWD|SECRET|AUTH_KEY|AUTH_SALT|SECURE_AUTH_KEY|LOGGED_IN_KEY|LOGGED_IN_SALT|NONCE_KEY|NONCE_SALT|NONCE|TOKEN|PRIVATE_KEY)[A-Za-z0-9_]*)\1(\s*(?:,|=>|=|:)\s*)([\'"])(.*?)\4/i';
        $line = preg_replace_callback($pattern, static function ($m) use (&$heavy) {
            $value = (string) $m[5];
            if (strlen($value) > 80) {
                $heavy = true;
            }
            return $m[1] . $m[2] . $m[1] . $m[3] . $m[4] . '[withheld]' . $m[4];
        }, $line) ?? $line;

        $env = '/^(\s*)([A-Za-z0-9_]*(?:PASSWORD|PASSWD|SECRET|AUTH_KEY|AUTH_SALT|NONCE|TOKEN|PRIVATE_KEY)[A-Za-z0-9_]*)(\s*=\s*)(.*)$/i';
        $line = preg_replace_callback($env, static function ($m) use (&$heavy) {
            $value = trim((string) $m[4], " \t\"'");
            if (strlen($value) > 80) {
                $heavy = true;
            }
            return $m[1] . $m[2] . $m[3] . '[withheld]';
        }, $line) ?? $line;

        return $line;
    }

    /**
     * A published public key or certificate uses the same wrapper as a private key.
     * Withhold it, and still allow a safe verdict. A private key or an unrecognized
     * BEGIN block stays heavy so a safe verdict cannot clear the file.
     */
    private static function pemIsPublic(string $line): bool {
        if (!preg_match('/-----BEGIN ([A-Z0-9 ]+?)-----/', strtoupper($line), $m)) {
            return false;
        }
        $label = trim($m[1]);
        if (strpos($label, 'PRIVATE') !== false || strpos($label, 'ENCRYPTED') !== false) {
            return false;
        }
        if ($label === 'PUBLIC KEY' || substr($label, -11) === ' PUBLIC KEY') {
            return true;
        }
        if ($label === 'CERTIFICATE' || substr($label, -12) === ' CERTIFICATE') {
            return true;
        }
        if ($label === 'CERTIFICATE REQUEST' || substr($label, -20) === ' CERTIFICATE REQUEST') {
            return true;
        }
        if ($label === 'PGP PUBLIC KEY BLOCK') {
            return true;
        }
        return false;
    }
}
