<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Ottimis\Ermes\NotificationConfig;
use Ottimis\Ermes\Tests\Support\ClientTestCase;
use Ottimis\Ermes\Tests\Support\TestKeys;

final class ConfigTest extends ClientTestCase
{
    private const ENV_VARS = [
        'NOTIFICATION_CORE_URL',
        'NOTIFICATION_TENANT_KEY',
        'NOTIFICATION_APPLICATION_ID',
        'NOTIFICATION_ISSUER',
        'NOTIFICATION_API_KEY',
        'NOTIFICATION_API_SECRET',
        'NOTIFICATION_RSA_PRIVATE_KEY',
        'NOTIFICATION_RSA_PRIVATE_KEY_PATH',
        'NOTIFICATION_KID',
        'NOTIFICATION_USER_TOKEN_TTL',
        'NOTIFICATION_ENABLED',
        'ERMES_DISABLED',
    ];

    protected function tearDown(): void
    {
        foreach (self::ENV_VARS as $name) {
            putenv($name);
        }
    }

    /**
     * @dataProvider dumpers
     *
     * @param callable(NotificationConfig): string $dump
     */
    public function testSecretsNeverShowUpInDumps(callable $dump): void
    {
        $config = $this->config(['apiSecret' => 'as_topsecret', 'privateKeyPem' => TestKeys::privatePem()]);

        $output = $dump($config);

        $this->assertStringNotContainsString('as_topsecret', $output);
        $this->assertStringNotContainsString('PRIVATE KEY', $output);
        $this->assertStringContainsString('myapp', $output, 'the rest of the config must stay readable');
    }

    /**
     * @return array<string, array{0: callable(NotificationConfig): string}>
     */
    public static function dumpers(): array
    {
        return [
            'var_dump'    => [static function (NotificationConfig $c): string {
                ob_start();
                var_dump($c);

                return (string) ob_get_clean();
            }],
            'print_r'     => [static fn (NotificationConfig $c): string => print_r($c, true)],
            'var_export'  => [static fn (NotificationConfig $c): string => var_export($c, true)],
            'json_encode' => [static fn (NotificationConfig $c): string => (string) json_encode($c)],
        ];
    }

    public function testSecretsAreStillReadableThroughTheGetters(): void
    {
        $config = $this->config(['apiSecret' => 'as_topsecret']);

        $this->assertSame('as_topsecret', $config->apiSecret());
        $this->assertStringContainsString('PRIVATE KEY', $config->privateKeyPem());
        $this->assertSame('Basic ' . base64_encode('ak_test:as_topsecret'), $config->basicAuthHeaderValue());
    }

    public function testSerializationRoundTripKeepsTheSecrets(): void
    {
        $restored = unserialize(serialize($this->config(['apiSecret' => 'as_topsecret'])));

        $this->assertInstanceOf(NotificationConfig::class, $restored);
        $this->assertSame('as_topsecret', $restored->apiSecret());
        $this->assertSame('myapp', $restored->tenantKey);
    }

    /**
     * @dataProvider brokenConfigs
     *
     * @param array<string, mixed> $overrides
     */
    public function testIncoherentConfigurationFailsFast(array $overrides, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessage, '/') . '/');

        $this->config($overrides);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function brokenConfigs(): array
    {
        return [
            'empty core url'    => [['coreUrl' => ''], 'coreUrl'],
            'relative core url' => [['coreUrl' => '/api'], 'coreUrl'],
            'non http scheme'   => [['coreUrl' => 'ftp://ermes.example.test'], 'coreUrl'],
            'empty tenant'      => [['tenantKey' => ''], 'tenantKey'],
            'empty application' => [['applicationId' => ''], 'applicationId'],
            'empty issuer'      => [['issuer' => ''], 'issuer'],
            'empty api key'     => [['apiKey' => ''], 'apiKey'],
            'empty api secret'  => [['apiSecret' => ''], 'apiSecret'],
            'zero ttl'          => [['userTokenTtl' => 0], 'userTokenTtl'],
        ];
    }

    public function testDisabledConfigurationSkipsValidation(): void
    {
        // Negli ambienti senza Ermes la configurazione è vuota di proposito e non deve far
        // esplodere il bootstrap dell'applicazione.
        $config = new NotificationConfig(
            coreUrl: '', tenantKey: '', applicationId: '', issuer: '',
            apiKey: '', apiSecret: '', privateKeyPem: '', enabled: false,
        );

        $this->assertFalse($config->enabled);
    }

    public function testFromEnvNamesTheMissingVariable(): void
    {
        putenv('NOTIFICATION_CORE_URL=https://ermes.example.test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/NOTIFICATION_TENANT_KEY/');

        NotificationConfig::fromEnv();
    }

    public function testFromEnvReadsTheFullConfiguration(): void
    {
        $this->putValidEnv();
        putenv('NOTIFICATION_KID=myapp-key-9');
        putenv('NOTIFICATION_USER_TOKEN_TTL=1800');

        $config = NotificationConfig::fromEnv();

        $this->assertSame('https://ermes.example.test', $config->coreUrl);
        $this->assertSame('myapp', $config->tenantKey);
        $this->assertSame('myapp-key-9', $config->kid);
        $this->assertSame(1800, $config->userTokenTtl);
        $this->assertTrue($config->enabled);
        $this->assertStringContainsString('PRIVATE KEY', $config->privateKeyPem());
    }

    public function testFromEnvDefaultsTtlToOneHour(): void
    {
        $this->putValidEnv();

        $this->assertSame(3600, NotificationConfig::fromEnv()->userTokenTtl);
    }

    public function testFromEnvStripsTrailingSlashFromCoreUrl(): void
    {
        $this->putValidEnv();
        putenv('NOTIFICATION_CORE_URL=https://ermes.example.test/');

        $this->assertSame('https://ermes.example.test', NotificationConfig::fromEnv()->coreUrl);
    }

    public function testFromEnvUnescapesAnInlineKey(): void
    {
        $this->putValidEnv();
        putenv('NOTIFICATION_RSA_PRIVATE_KEY=' . str_replace("\n", '\n', TestKeys::privatePem()));

        $this->assertSame(TestKeys::privatePem(), NotificationConfig::fromEnv()->privateKeyPem());
    }

    public function testFromEnvReadsAKeyFile(): void
    {
        $this->putValidEnv();
        $path = tempnam(sys_get_temp_dir(), 'ermes-key-');
        file_put_contents((string) $path, TestKeys::privatePem());
        putenv('NOTIFICATION_RSA_PRIVATE_KEY=');
        putenv('NOTIFICATION_RSA_PRIVATE_KEY_PATH=' . $path);

        try {
            $this->assertSame(TestKeys::privatePem(), NotificationConfig::fromEnv()->privateKeyPem());
        } finally {
            unlink((string) $path);
        }
    }

    public function testFromEnvReportsAnUnreadableKeyFile(): void
    {
        $this->putValidEnv();
        putenv('NOTIFICATION_RSA_PRIVATE_KEY=');
        putenv('NOTIFICATION_RSA_PRIVATE_KEY_PATH=/nope/missing.pem');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not readable/');

        NotificationConfig::fromEnv();
    }

    /**
     * @dataProvider killSwitches
     */
    public function testKillSwitchDisablesTheClientWithoutRequiringAnyOtherVariable(string $name, string $value): void
    {
        putenv("{$name}={$value}");

        $config = NotificationConfig::fromEnv();

        $this->assertFalse($config->enabled);
        $this->assertSame('', $config->tenantKey);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function killSwitches(): array
    {
        return [
            'ERMES_DISABLED=1'            => ['ERMES_DISABLED', '1'],
            'ERMES_DISABLED=true'         => ['ERMES_DISABLED', 'true'],
            'NOTIFICATION_ENABLED=false'  => ['NOTIFICATION_ENABLED', 'false'],
            'NOTIFICATION_ENABLED=0'      => ['NOTIFICATION_ENABLED', '0'],
        ];
    }

    private function putValidEnv(): void
    {
        putenv('NOTIFICATION_CORE_URL=https://ermes.example.test');
        putenv('NOTIFICATION_TENANT_KEY=myapp');
        putenv('NOTIFICATION_APPLICATION_ID=my-backoffice');
        putenv('NOTIFICATION_ISSUER=https://auth.example.test');
        putenv('NOTIFICATION_API_KEY=ak_test');
        putenv('NOTIFICATION_API_SECRET=as_test');
        putenv('NOTIFICATION_RSA_PRIVATE_KEY=' . str_replace("\n", '\n', TestKeys::privatePem()));
    }
}
