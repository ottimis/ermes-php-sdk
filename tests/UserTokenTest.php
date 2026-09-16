<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Ottimis\Ermes\Tests\Support\ClientTestCase;

final class UserTokenTest extends ClientTestCase
{
    public function testTokenIssuedWithoutTtlExpiresWithinOneHour(): void
    {
        $result = $this->client()->createUserTokenWithInfo('user_42');

        $lifetime = $result['info']['exp'] - $result['info']['iat'];
        $this->assertLessThanOrEqual(3600, $lifetime);
        $this->assertGreaterThan(0, $lifetime);
    }

    public function testTokenValidatesAgainstTheJwksTheSdkPublishes(): void
    {
        $client = $this->client();
        $token  = $client->createUserToken('user_42', ['operator', 'admin']);

        $decoded = JWT::decode($token, JWK::parseKeySet($client->getJwks()));

        $this->assertSame('user_42', $decoded->sub);
        $this->assertSame('myapp', $decoded->tenant_id);
        $this->assertSame('https://auth.example.test', $decoded->iss);
        $this->assertSame('notification-platform', $decoded->aud);
    }

    public function testAudienceIsConfigurable(): void
    {
        // Sul core l'audience e' una colonna del tenant: con un valore fisso l'SDK non e'
        // utilizzabile su un tenant provisionato diversamente.
        $client = $this->client(['audience' => 'og-monitor']);
        $token  = $client->createUserToken('user_42');

        $decoded = JWT::decode($token, JWK::parseKeySet($client->getJwks()));

        $this->assertSame('og-monitor', $decoded->aud);
    }

    public function testExplicitTtlOverridesTheDefault(): void
    {
        $result = $this->client()->createUserTokenWithInfo('user_42', ['operator'], 86400);

        $this->assertSame(86400, $result['info']['exp'] - $result['info']['iat']);
    }

    public function testConfiguredTtlAppliesWhenNoneIsPassed(): void
    {
        $result = $this->client(['userTokenTtl' => 900])->createUserTokenWithInfo('user_42');

        $this->assertSame(900, $result['info']['exp'] - $result['info']['iat']);
    }

    public function testTokensAreReusedWithinTheirLifetime(): void
    {
        $client = $this->client();

        $this->assertSame(
            $client->createUserToken('user_42'),
            $client->createUserToken('user_42'),
            'the same user should not pay a fresh RSA signature on every call'
        );
        $this->assertNotSame(
            $client->createUserToken('user_42'),
            $client->createUserToken('user_43'),
            'different users must get different tokens'
        );
        $this->assertNotSame(
            $client->createUserToken('user_42'),
            $client->createUserToken('user_42', ['admin']),
            'different roles must get different tokens'
        );
    }

    public function testEmptyUserIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->createUserToken('   ');
    }

    public function testNonPositiveTtlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->createUserToken('user_42', ['operator'], 0);
    }

    public function testIssuingWithoutAPrivateKeyFailsLoudly(): void
    {
        $client = $this->client(['enabled' => false, 'privateKeyPem' => '']);

        $this->expectException(\LogicException::class);
        $client->createUserToken('user_42');
    }
}
