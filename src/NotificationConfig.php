<?php

namespace Ottimis\Ermes;

class NotificationConfig
{
    public function __construct(
        public readonly string $coreUrl,
        public readonly string $tenantKey,
        public readonly string $applicationId,
        public readonly string $issuer,
        public readonly string $apiKey,
        public readonly string $apiSecret,
        public readonly string $privateKeyPem,
        public readonly string $kid = 'key-1',
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            coreUrl:       rtrim(getenv('NOTIFICATION_CORE_URL') ?: '', '/'),
            tenantKey:     getenv('NOTIFICATION_TENANT_KEY') ?: '',
            applicationId: getenv('NOTIFICATION_APPLICATION_ID') ?: '',
            issuer:        getenv('NOTIFICATION_ISSUER') ?: '',
            apiKey:        getenv('NOTIFICATION_API_KEY') ?: '',
            apiSecret:     getenv('NOTIFICATION_API_SECRET') ?: '',
            privateKeyPem: self::loadKeyFromEnv(),
            kid:           getenv('NOTIFICATION_KID') ?: 'key-1',
        );
    }

    private static function loadKeyFromEnv(): string
    {
        $path = getenv('NOTIFICATION_RSA_PRIVATE_KEY_PATH');
        if ($path && file_exists($path)) {
            return file_get_contents($path);
        }
        $inline = getenv('NOTIFICATION_RSA_PRIVATE_KEY');
        if ($inline) {
            return str_replace('\n', "\n", $inline);
        }
        throw new \RuntimeException(
            'Ermes SDK: private key not configured. Set NOTIFICATION_RSA_PRIVATE_KEY or NOTIFICATION_RSA_PRIVATE_KEY_PATH.'
        );
    }
}
