<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ottimis\Ermes\NotificationClient;
use Ottimis\Ermes\NotificationConfig;

abstract class ClientTestCase extends TestCase
{
    protected FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function config(array $overrides = []): NotificationConfig
    {
        return new NotificationConfig(...array_merge([
            'coreUrl'       => 'https://ermes.example.test',
            'tenantKey'     => 'myapp',
            'applicationId' => 'my-backoffice',
            'issuer'        => 'https://auth.example.test',
            'apiKey'        => 'ak_test',
            'apiSecret'     => 'as_test',
            'privateKeyPem' => TestKeys::privatePem(),
            'kid'           => 'myapp-key-1',
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function client(array $overrides = []): NotificationClient
    {
        return new NotificationClient($this->config($overrides), $this->http);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed> un evento valido minimo
     */
    protected function validEvent(array $overrides = []): array
    {
        return array_merge([
            'topic'           => 'contract.termination.completed',
            'title'           => 'Cessazione completata',
            'recipient_users' => ['user_42'],
        ], $overrides);
    }
}
