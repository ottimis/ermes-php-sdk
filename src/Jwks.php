<?php

declare(strict_types=1);

namespace Ottimis\Ermes;

use Psr\Log\LoggerInterface;

/**
 * Il JWKS che un tenant pubblica, senza bisogno di un client.
 *
 * Sta fuori da NotificationClient di proposito. Derivare un JWK da una chiave privata è
 * un'operazione LOCALE: non tocca la rete, non usa le credenziali del produttore, e serve
 * proprio nel momento in cui quelle credenziali non esistono ancora — un tenant deve
 * pubblicare la propria JWKS PRIMA di essere registrato su Ermes, altrimenti il core non ha
 * modo di verificare nessuno dei suoi token.
 *
 * Finché quella derivazione è stata un metodo privato del client, chi voleva solo la JWKS ha
 * dovuto costruire un `NotificationConfig` completo — che dalla 1.3.0 SOLLEVA se `apiKey` o
 * `apiSecret` sono vuote — oppure riscriversi la derivazione in casa. Entrambe le cose sono
 * successe davvero, in due progetti diversi.
 */
final class Jwks
{
    /**
     * JWKS a partire dalla chiave privata con cui si firmano i token.
     *
     * Non lancia mai: una chiave illeggibile produce un elenco senza quella chiave. Un
     * endpoint JWKS che risponde 500 blocca la verifica di TUTTI i token del tenant, il che è
     * peggio di un elenco incompleto.
     *
     * @param array<string, string> $additionalPublicKeys kid => PEM (pubblico o privato).
     *                                                    Servono durante una rotazione: il
     *                                                    core deve poter ancora verificare i
     *                                                    token firmati con la chiave uscente.
     *
     * @return array{keys: array<int, array<string, string>>}
     */
    public static function fromPrivateKey(
        #[\SensitiveParameter]
        string $privateKeyPem,
        string $kid,
        array $additionalPublicKeys = [],
        ?LoggerInterface $logger = null,
    ): array {
        $keys = [];

        $current = self::jwkFromPem($privateKeyPem, $kid, true, $logger);
        if ($current !== null) {
            $keys[] = $current;
        }

        foreach ($additionalPublicKeys as $extraKid => $pem) {
            $extra = self::jwkFromPem((string) $pem, (string) $extraKid, false, $logger);
            if ($extra !== null) {
                $keys[] = $extra;
            }
        }

        return ['keys' => $keys];
    }

    /**
     * Chiavi pubbliche aggiuntive lette da una directory, per la rotazione senza downtime.
     *
     * Convenzione: il `kid` è il nome del file senza estensione, quindi `key-2.pem` pubblica
     * il `kid` `key-2`. Accetta sia PEM pubblici sia privati — di un privato si pubblica solo
     * la parte pubblica.
     *
     * Una directory assente o illeggibile produce un elenco vuoto, non un errore: la
     * rotazione è una funzionalità opzionale e non deve poter impedire l'avvio.
     *
     * @return array<string, string> kid => PEM, pronto per $additionalPublicKeys
     */
    public static function publicKeysFromDirectory(string $dir, ?LoggerInterface $logger = null): array
    {
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }

        $keys = [];
        foreach (glob(rtrim($dir, '/') . '/*.pem') ?: [] as $file) {
            if (!is_readable($file)) {
                $logger?->warning('Ermes SDK: JWKS key file is not readable', ['file' => $file]);
                continue;
            }
            $kid = pathinfo($file, PATHINFO_FILENAME);
            if ($kid === '') {
                continue;
            }
            $keys[$kid] = (string) file_get_contents($file);
        }

        return $keys;
    }

    /**
     * Estrae modulo ed esponente da un PEM. Torna null, senza lanciare, se il PEM non è
     * leggibile o non è RSA.
     *
     * @return array<string, string>|null
     */
    public static function jwkFromPem(
        #[\SensitiveParameter]
        string $pem,
        string $kid,
        bool $isPrivate = false,
        ?LoggerInterface $logger = null,
    ): ?array {
        if (trim($pem) === '') {
            return null;
        }

        // openssl_* emette warning su PEM malformati: qui interessa solo il valore di ritorno.
        $key = $isPrivate
            ? @openssl_pkey_get_private($pem)
            : (@openssl_pkey_get_public($pem) ?: @openssl_pkey_get_private($pem));

        if ($key === false) {
            $logger?->warning('Ermes SDK: cannot read key for JWKS', [
                'kid'   => $kid,
                'error' => openssl_error_string() ?: 'unreadable PEM',
            ]);

            return null;
        }

        $details = @openssl_pkey_get_details($key);
        if (!is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || !isset($details['rsa']['n'], $details['rsa']['e'])
        ) {
            $logger?->warning('Ermes SDK: key is not usable as an RS256 JWK', ['kid' => $kid]);

            return null;
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n'   => self::base64Url($details['rsa']['n']),
            'e'   => self::base64Url($details['rsa']['e']),
        ];
    }

    public static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
