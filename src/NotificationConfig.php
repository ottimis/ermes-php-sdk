<?php

declare(strict_types=1);

namespace Ottimis\Ermes;

/**
 * Configurazione del client.
 *
 * I due segreti (API secret e chiave privata) non sono proprieta' dell'oggetto: sono chiusi
 * dentro due closure, perche' una proprieta' privata resta comunque visibile a print_r,
 * var_dump e var_export, mentre il valore catturato da una closure no. Si leggono solo dai
 * getter dedicati, che l'SDK usa internamente.
 */
class NotificationConfig implements \JsonSerializable
{
    /** Durata di default del token utente, in secondi. */
    public const DEFAULT_USER_TOKEN_TTL = 3600;

    /** Audience di default, uguale a quella con cui il core provisiona un tenant. */
    public const DEFAULT_AUDIENCE = 'notification-platform';

    /** @var \Closure(): string */
    private readonly \Closure $readApiSecret;

    /** @var \Closure(): string */
    private readonly \Closure $readPrivateKeyPem;

    /**
     * @param string               $coreUrl              base URL del core Ermes, senza slash finale
     * @param int                  $userTokenTtl         durata dei token emessi da createUserToken*(), in secondi
     * @param bool                 $enabled              false spegne ogni chiamata di rete (ambienti senza Ermes)
     * @param array<string,string> $additionalPublicKeys chiavi extra da pubblicare nella JWKS, come kid => PEM.
     *                                                   Servono a ruotare la chiave di firma senza invalidare i
     *                                                   token già emessi: la nuova firma, la vecchia resta
     *                                                   pubblicata finché l'ultimo token emesso con essa è scaduto.
     * @param int                  $timeoutMs            attesa massima della risposta, per chiamata
     * @param int                  $connectTimeoutMs     attesa massima della sola connessione
     *
     * @throws \InvalidArgumentException se la configurazione è incoerente e `enabled` è true
     */
    public function __construct(
        public readonly string $coreUrl,
        public readonly string $tenantKey,
        public readonly string $applicationId,
        public readonly string $issuer,
        public readonly string $apiKey,
        #[\SensitiveParameter]
        string $apiSecret,
        #[\SensitiveParameter]
        string $privateKeyPem,
        public readonly string $kid = 'key-1',
        public readonly int $userTokenTtl = self::DEFAULT_USER_TOKEN_TTL,
        /**
         * Claim `aud` dei token emessi. Sul core l'audience e' una colonna del tenant:
         * un valore fisso rende l'SDK inutilizzabile su un tenant provisionato con
         * un'audience diversa da quella di default.
         */
        public readonly string $audience = self::DEFAULT_AUDIENCE,
        public readonly bool $enabled = true,
        public readonly array $additionalPublicKeys = [],
        public readonly int $timeoutMs = Http\CurlHttpClient::DEFAULT_TIMEOUT_MS,
        public readonly int $connectTimeoutMs = Http\CurlHttpClient::DEFAULT_CONNECT_TIMEOUT_MS,
    ) {
        $this->readApiSecret     = static fn (): string => $apiSecret;
        $this->readPrivateKeyPem = static fn (): string => $privateKeyPem;

        if ($this->userTokenTtl < 1) {
            throw new \InvalidArgumentException('Ermes SDK: userTokenTtl must be a positive number of seconds.');
        }
        if (!$this->enabled) {
            // Con Ermes spento la configurazione può essere vuota: non si valida ciò che non si usa.
            return;
        }
        self::assertHttpUrl($this->coreUrl, 'coreUrl');
        foreach (['tenantKey', 'applicationId', 'issuer', 'apiKey'] as $field) {
            if (trim((string) $this->{$field}) === '') {
                throw new \InvalidArgumentException("Ermes SDK: {$field} is required.");
            }
        }
        if (trim($apiSecret) === '') {
            throw new \InvalidArgumentException('Ermes SDK: apiSecret is required.');
        }
    }

    /**
     * Costruisce la configurazione dalle variabili d'ambiente, fallendo subito e con il
     * nome della variabile mancante invece di lasciar scoprire il problema a una 401 o a
     * un errore cURL muto in produzione.
     *
     * @throws \RuntimeException se manca una variabile obbligatoria
     */
    public static function fromEnv(): self
    {
        $enabled = self::enabledFromEnv();

        return new self(
            coreUrl:       rtrim(self::env('NOTIFICATION_CORE_URL', $enabled), '/'),
            tenantKey:     self::env('NOTIFICATION_TENANT_KEY', $enabled),
            applicationId: self::env('NOTIFICATION_APPLICATION_ID', $enabled),
            issuer:        self::env('NOTIFICATION_ISSUER', $enabled),
            apiKey:        self::env('NOTIFICATION_API_KEY', $enabled),
            apiSecret:     self::env('NOTIFICATION_API_SECRET', $enabled),
            privateKeyPem: self::loadKeyFromEnv($enabled),
            kid:           self::env('NOTIFICATION_KID', false) ?: 'key-1',
            userTokenTtl:  (int) (self::env('NOTIFICATION_USER_TOKEN_TTL', false) ?: self::DEFAULT_USER_TOKEN_TTL),
            audience:      getenv('NOTIFICATION_AUDIENCE') ?: self::DEFAULT_AUDIENCE,
            enabled:       $enabled,
        );
    }

    public function apiSecret(): string
    {
        return ($this->readApiSecret)();
    }

    public function privateKeyPem(): string
    {
        return ($this->readPrivateKeyPem)();
    }

    /**
     * Credenziali producer pronte per l'header Basic, senza far circolare il secret.
     */
    public function basicAuthHeaderValue(): string
    {
        return 'Basic ' . base64_encode("{$this->apiKey}:{$this->apiSecret()}");
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * Le closure non sono serializzabili: i segreti vengono riportati in chiaro solo quando
     * qualcuno serializza deliberatamente la configurazione.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return array_merge($this->jsonSerialize(), [
            'apiSecret'            => $this->apiSecret(),
            'privateKeyPem'        => $this->privateKeyPem(),
            'additionalPublicKeys' => $this->additionalPublicKeys,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        /** @var array<string, mixed> $data */
        $this->__construct(...$data);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'coreUrl'              => $this->coreUrl,
            'tenantKey'            => $this->tenantKey,
            'applicationId'        => $this->applicationId,
            'issuer'               => $this->issuer,
            'apiKey'               => $this->apiKey,
            'apiSecret'            => '***redacted***',
            'privateKeyPem'        => '***redacted***',
            'kid'                  => $this->kid,
            'userTokenTtl'         => $this->userTokenTtl,
            'audience'             => $this->audience,
            'enabled'              => $this->enabled,
            'additionalPublicKeys' => array_keys($this->additionalPublicKeys),
            'timeoutMs'            => $this->timeoutMs,
            'connectTimeoutMs'     => $this->connectTimeoutMs,
        ];
    }

    private static function env(string $name, bool $required): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            if ($required) {
                throw new \RuntimeException("Ermes SDK: missing required environment variable {$name}.");
            }

            return '';
        }

        return $value;
    }

    private static function enabledFromEnv(): bool
    {
        // ERMES_DISABLED è il kill switch già in uso nei consumatori; NOTIFICATION_ENABLED
        // segue la convenzione delle altre variabili dell'SDK. Basta uno dei due.
        if (filter_var(getenv('ERMES_DISABLED') ?: '', FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        $enabled = getenv('NOTIFICATION_ENABLED');
        if ($enabled === false || trim($enabled) === '') {
            return true;
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    private static function loadKeyFromEnv(bool $required): string
    {
        $path = getenv('NOTIFICATION_RSA_PRIVATE_KEY_PATH');
        if (is_string($path) && $path !== '') {
            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException(
                    "Ermes SDK: private key file not readable at {$path} (NOTIFICATION_RSA_PRIVATE_KEY_PATH)."
                );
            }
            $pem = file_get_contents($path);
            if ($pem === false || trim($pem) === '') {
                throw new \RuntimeException("Ermes SDK: private key file is empty or unreadable at {$path}.");
            }

            return $pem;
        }

        $inline = getenv('NOTIFICATION_RSA_PRIVATE_KEY');
        if (is_string($inline) && trim($inline) !== '') {
            return str_replace('\n', "\n", $inline);
        }

        if (!$required) {
            return '';
        }

        throw new \RuntimeException(
            'Ermes SDK: private key not configured. Set NOTIFICATION_RSA_PRIVATE_KEY or NOTIFICATION_RSA_PRIVATE_KEY_PATH.'
        );
    }

    private static function assertHttpUrl(string $url, string $field): void
    {
        $parts = parse_url($url);
        if ($url === '' || $parts === false || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            throw new \InvalidArgumentException(
                "Ermes SDK: {$field} must be an absolute http(s) URL, got " . ($url === '' ? '(empty)' : $url) . '.'
            );
        }
    }
}
