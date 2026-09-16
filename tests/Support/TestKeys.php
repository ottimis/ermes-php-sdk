<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests\Support;

/**
 * Chiavi RSA generate a runtime: nessun materiale crittografico nel repository.
 */
final class TestKeys
{
    /** @var array<string, array{private: string, public: string}> */
    private static array $cache = [];

    /**
     * @return array{private: string, public: string}
     */
    public static function pair(string $name = 'default'): array
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new \RuntimeException('Cannot generate an RSA key for the tests.');
        }

        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);

        return self::$cache[$name] = [
            'private' => (string) $private,
            'public'  => (string) $details['key'],
        ];
    }

    public static function privatePem(string $name = 'default'): string
    {
        return self::pair($name)['private'];
    }

    public static function publicPem(string $name = 'default'): string
    {
        return self::pair($name)['public'];
    }
}
