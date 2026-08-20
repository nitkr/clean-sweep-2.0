<?php
/**
 * Clean Sweep — offline signature pack crypto
 *
 * Format csig-v1 (no remote server):
 *   - AES-256-GCM encryption (key embedded in product)
 *   - ECDSA P-256 (SHA-256) signature (private key only on build machine)
 *
 * This is shipping privacy + pack authenticity, not perfect secrecy on a
 * fully compromised host.
 */

if (!class_exists('CleanSweep_SignatureCrypto')) {

class CleanSweep_SignatureCrypto {

    const FORMAT = 'csig-v1';
    const ENC_ALG = 'aes-256-gcm';
    const SIGN_ALG = 'ecdsa-p256-sha256';

    /**
     * Encrypt + sign a signatures payload (array of entries or plain patterns).
     *
     * @param array $payload Decrypted JSON body (must include "signatures" key)
     * @param string $enc_key_bin 32-byte AES key
     * @param string $private_key_pem EC private key PEM
     * @param string $version Semver string
     * @return array Pack structure (JSON-serializable)
     */
    public static function seal(array $payload, $enc_key_bin, $private_key_pem, $version) {
        if (strlen($enc_key_bin) !== 32) {
            throw new InvalidArgumentException('Encryption key must be 32 bytes');
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL extension required');
        }

        $plaintext = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($plaintext === false) {
            throw new RuntimeException('Failed to JSON-encode payload');
        }

        // Optional compress before encrypt
        if (function_exists('gzencode')) {
            $plaintext = gzencode($plaintext, 9);
            $compressed = true;
        } else {
            $compressed = false;
        }

        $iv = random_bytes(12); // 96-bit nonce for GCM
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $enc_key_bin,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($ciphertext === false) {
            throw new RuntimeException('AES-GCM encrypt failed: ' . self::openssl_err());
        }

        $count = 0;
        if (isset($payload['signatures']) && is_array($payload['signatures'])) {
            $count = count($payload['signatures']);
        }

        // Signed message: canonical fields without the signature itself
        $to_sign = self::canonical_sign_input([
            'format' => self::FORMAT,
            'version' => (string) $version,
            'enc' => self::ENC_ALG,
            'sign' => self::SIGN_ALG,
            'compressed' => $compressed ? 1 : 0,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
            'signature_count' => $count,
        ]);

        $sig_bin = '';
        $pkey = openssl_pkey_get_private($private_key_pem);
        if ($pkey === false) {
            throw new RuntimeException('Invalid private signing key: ' . self::openssl_err());
        }
        $ok = openssl_sign($to_sign, $sig_bin, $pkey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('ECDSA sign failed: ' . self::openssl_err());
        }

        return [
            'format' => self::FORMAT,
            'version' => (string) $version,
            'enc' => self::ENC_ALG,
            'sign' => self::SIGN_ALG,
            'compressed' => $compressed,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
            'sig' => base64_encode($sig_bin),
            'signature_count' => $count,
            'built_at' => date('c'),
        ];
    }

    /**
     * Verify + decrypt a csig-v1 pack.
     *
     * @param array $pack Decoded JSON pack
     * @param string $enc_key_bin 32-byte AES key
     * @param string $public_key_pem EC public key PEM
     * @return array Decrypted payload
     */
    public static function open(array $pack, $enc_key_bin, $public_key_pem) {
        if (($pack['format'] ?? '') !== self::FORMAT) {
            throw new RuntimeException('Unsupported signature pack format');
        }
        if (strlen($enc_key_bin) !== 32) {
            throw new InvalidArgumentException('Encryption key must be 32 bytes');
        }

        $iv = base64_decode($pack['iv'] ?? '', true);
        $tag = base64_decode($pack['tag'] ?? '', true);
        $ciphertext = base64_decode($pack['ciphertext'] ?? '', true);
        $sig_bin = base64_decode($pack['sig'] ?? '', true);

        if ($iv === false || $tag === false || $ciphertext === false || $sig_bin === false) {
            throw new RuntimeException('Invalid base64 fields in signature pack');
        }

        $to_sign = self::canonical_sign_input([
            'format' => self::FORMAT,
            'version' => (string) ($pack['version'] ?? ''),
            'enc' => self::ENC_ALG,
            'sign' => self::SIGN_ALG,
            'compressed' => !empty($pack['compressed']) ? 1 : 0,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
            'signature_count' => (int) ($pack['signature_count'] ?? 0),
        ]);

        $pkey = openssl_pkey_get_public($public_key_pem);
        if ($pkey === false) {
            throw new RuntimeException('Invalid public verify key: ' . self::openssl_err());
        }
        $verified = openssl_verify($to_sign, $sig_bin, $pkey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new RuntimeException('Signature pack authenticity check failed (bad ECDSA signature)');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $enc_key_bin,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plaintext === false) {
            throw new RuntimeException('AES-GCM decrypt failed (wrong key or tampered ciphertext)');
        }

        if (!empty($pack['compressed'])) {
            $json = function_exists('gzdecode') ? @gzdecode($plaintext) : false;
            if ($json === false) {
                $json = @gzuncompress($plaintext);
            }
            if ($json === false) {
                throw new RuntimeException('Failed to decompress signature payload');
            }
            $plaintext = $json;
        }

        $payload = json_decode($plaintext, true);
        if (!is_array($payload) || empty($payload['signatures']) || !is_array($payload['signatures'])) {
            throw new RuntimeException('Decrypted payload missing signatures array');
        }

        return $payload;
    }

    /**
     * Canonical string for signing (order-stable).
     */
    public static function canonical_sign_input(array $fields) {
        // Fixed key order
        $keys = ['format', 'version', 'enc', 'sign', 'compressed', 'iv', 'tag', 'ciphertext', 'signature_count'];
        $parts = [];
        foreach ($keys as $k) {
            $parts[] = $k . '=' . (string) ($fields[$k] ?? '');
        }
        return implode("\n", $parts);
    }

    public static function openssl_err() {
        $msgs = [];
        while ($e = openssl_error_string()) {
            $msgs[] = $e;
        }
        return $msgs ? implode('; ', $msgs) : 'unknown';
    }

    /**
     * Generate AES key + ECDSA P-256 keypair.
     *
     * @return array{enc_key:string,private_pem:string,public_pem:string}
     */
    public static function generate_keys() {
        $enc_key = random_bytes(32);
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($res === false) {
            throw new RuntimeException('Failed to generate EC key: ' . self::openssl_err());
        }
        $private_pem = '';
        if (!openssl_pkey_export($res, $private_pem)) {
            throw new RuntimeException('Failed to export private key: ' . self::openssl_err());
        }
        $details = openssl_pkey_get_details($res);
        if (empty($details['key'])) {
            throw new RuntimeException('Failed to export public key');
        }
        return [
            'enc_key' => $enc_key,
            'private_pem' => $private_pem,
            'public_pem' => $details['key'],
        ];
    }
}

} // class_exists
