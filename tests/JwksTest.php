<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Ottimis\Ermes\NotificationClient;
use Ottimis\Ermes\Tests\Support\ClientTestCase;
use Ottimis\Ermes\Tests\Support\TestKeys;

final class JwksTest extends ClientTestCase
{
    public function testPublishesTheCurrentKey(): void
    {
        $jwks = $this->client()->getJwks();

        $this->assertCount(1, $jwks['keys']);
        $this->assertSame('RSA', $jwks['keys'][0]['kty']);
        $this->assertSame('sig', $jwks['keys'][0]['use']);
        $this->assertSame('RS256', $jwks['keys'][0]['alg']);
        $this->assertSame('myapp-key-1', $jwks['keys'][0]['kid']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwks['keys'][0]['n']);
        $this->assertSame('AQAB', $jwks['keys'][0]['e']);
    }

    public function testBrokenPrivateKeyReturnsAnEmptyKeySetWithoutWarnings(): void
    {
        // Un 500 su /.well-known/jwks.json toglie a Ermes la possibilità di validare i
        // token del tenant: qui si degrada, non si esplode.
        $client = new NotificationClient(
            $this->config(['enabled' => false, 'privateKeyPem' => "-----BEGIN PRIVATE KEY-----\nnot a key\n-----END PRIVATE KEY-----"]),
            $this->http
        );

        $this->assertSame(['keys' => []], $client->getJwks());
    }

    public function testMissingPrivateKeyReturnsAnEmptyKeySet(): void
    {
        $client = new NotificationClient($this->config(['enabled' => false, 'privateKeyPem' => '']), $this->http);

        $this->assertSame(['keys' => []], $client->getJwks());
    }

    public function testNonRsaKeyIsSkipped(): void
    {
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($ec, 'EC key generation is required by this test');
        openssl_pkey_export($ec, $pem);

        $client = new NotificationClient($this->config(['enabled' => false, 'privateKeyPem' => (string) $pem]), $this->http);

        $this->assertSame(['keys' => []], $client->getJwks());
    }

    public function testRotationPublishesTheOldKeyAlongsideTheNewOne(): void
    {
        $client = $this->client([
            'privateKeyPem'        => TestKeys::privatePem('new'),
            'kid'                  => 'myapp-key-2',
            'additionalPublicKeys' => ['myapp-key-1' => TestKeys::publicPem('old')],
        ]);

        $jwks = $client->getJwks();
        $this->assertSame(['myapp-key-2', 'myapp-key-1'], array_column($jwks['keys'], 'kid'));

        // Un token firmato con la chiave vecchia resta validabile contro la JWKS pubblicata:
        // è questo che rende la rotazione possibile senza invalidare i token già emessi.
        $old = JWT::encode(['sub' => 'user_42', 'exp' => time() + 60], TestKeys::privatePem('old'), 'RS256', 'myapp-key-1');
        $this->assertSame('user_42', JWT::decode($old, JWK::parseKeySet($jwks))->sub);

        // E la chiave nuova firma i token nuovi.
        $this->assertSame('user_7', JWT::decode($client->createUserToken('user_7'), JWK::parseKeySet($jwks))->sub);
    }

    public function testUnreadableAdditionalKeyIsSkippedAndLogged(): void
    {
        // La firma non è tipizzata di proposito: psr/log 1.x non tipizza log(), e questo
        // pacchetto supporta tutte e tre le major.
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var array<int, string> */
            public array $records = [];

            /**
             * @param mixed                $level
             * @param string|\Stringable   $message
             * @param array<string, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = (string) $level . ': ' . $message;
            }
        };

        $client = new NotificationClient(
            $this->config(['additionalPublicKeys' => ['broken' => 'nonsense']]),
            $this->http,
            $logger
        );

        $this->assertCount(1, $client->getJwks()['keys']);
        $this->assertNotEmpty($logger->records);
    }
}
