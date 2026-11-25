<?php

namespace App\Support;

/** Wire-compatible port of utils/encryption.js (aes-256-gcm, PBKDF2-SHA512). */
class Encryption
{
    private static function key(string $salt): string
    {
        $passphrase = env('ENCRYPTION_KEY', '');
        if (! preg_match('/^(?:[0-9a-f]{64}|.{32,})$/is', $passphrase)) {
            throw new \RuntimeException('ENCRYPTION_KEY must be configured (64 hex chars or at least 32 characters)');
        }

        return hash_pbkdf2('sha512', $passphrase, $salt, 100000, 32, true);
    }

    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }
        $salt = random_bytes(32);
        $iv = random_bytes(16);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', self::key($salt), OPENSSL_RAW_DATA, $iv, $tag);

        return implode(':', [bin2hex($salt), bin2hex($iv), bin2hex($tag), bin2hex($ciphertext)]);
    }

    public static function decrypt(?string $value): ?string
    {
        if (! $value) {
            return $value;
        }
        $parts = explode(':', $value);
        if (count($parts) !== 4) {
            return $value; // legacy plaintext
        }
        try {
            [$salt, $iv, $tag, $ciphertext] = $parts;

            return openssl_decrypt(
                hex2bin($ciphertext),
                'aes-256-gcm',
                self::key(hex2bin($salt)),
                OPENSSL_RAW_DATA,
                hex2bin($iv),
                hex2bin($tag)
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && count(explode(':', $value)) === 4
            && ! array_filter(explode(':', $value), fn ($p) => ! preg_match('/^[0-9a-f]+$/i', $p));
    }
}
