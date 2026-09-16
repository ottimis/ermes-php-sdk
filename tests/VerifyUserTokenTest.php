<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Firebase\JWT\JWT;
use Ottimis\Ermes\Tests\Support\ClientTestCase;
use Ottimis\Ermes\Tests\Support\TestKeys;

/**
 * L'SDK emetteva token e non ne verificava nessuno, ma chi monta il proxy dell'inbox deve
 * riverificare i propri: la libreria Angular usa UN SOLO token sia per il socket sia per le
 * chiamate al backend del tenant.
 */
final class VerifyUserTokenTest extends ClientTestCase
{
    public function testAcceptsATokenItIssuedItself(): void
    {
        $client = $this->client();
        $token  = $client->createUserToken('124-92', ['operator', 'admin']);

        $verified = $client->verifyUserToken($token);

        $this->assertNotNull($verified);
        $this->assertSame('124-92', $verified['sub']);
        $this->assertSame(['operator', 'admin'], $verified['roles']);
        $this->assertIsInt($verified['exp']);
    }

    public function testExposesApplicationClaimsSoTheCallerCanUseThem(): void
    {
        $client = $this->client();
        $token  = $client->createUserToken('124-92', ['operator'], null, ['sid' => 'sessione-7']);

        $verified = $client->verifyUserToken($token);

        $this->assertSame('sessione-7', $verified['claims']['sid']);
    }

    public function testRejectsATokenSignedBySomeoneElse(): void
    {
        $altrui = JWT::encode(
            ['iss' => 'https://auth.example.test', 'aud' => 'notification-platform', 'sub' => '1-1', 'exp' => time() + 60],
            TestKeys::privatePem('altra'),
            'RS256',
            'altra'
        );

        $this->assertNull($this->client()->verifyUserToken($altrui));
    }

    public function testRejectsAWrongAudienceOrIssuer(): void
    {
        $client = $this->client();

        $aud = JWT::encode(
            ['iss' => 'https://auth.example.test', 'aud' => 'un-altra-piattaforma', 'sub' => '1-1', 'exp' => time() + 60],
            TestKeys::privatePem(),
            'RS256',
            'key-1'
        );
        $iss = JWT::encode(
            ['iss' => 'https://idp-di-altri.test', 'aud' => 'notification-platform', 'sub' => '1-1', 'exp' => time() + 60],
            TestKeys::privatePem(),
            'RS256',
            'key-1'
        );

        $this->assertNull($client->verifyUserToken($aud));
        $this->assertNull($client->verifyUserToken($iss));
    }

    public function testRejectsAnExpiredTokenWithoutThrowing(): void
    {
        $scaduto = JWT::encode(
            ['iss' => 'https://auth.example.test', 'aud' => 'notification-platform', 'sub' => '1-1',
             'iat' => time() - 7200, 'exp' => time() - 3600],
            TestKeys::privatePem(),
            'RS256',
            'key-1'
        );

        $this->assertNull($this->client()->verifyUserToken($scaduto));
    }

    public function testAcceptsATokenSignedWithTheOutgoingKeyDuringARotation(): void
    {
        // Il difetto che questo chiude: pubblicavamo la chiave uscente nel JWKS — quindi Ermes
        // accettava quei token — ma le nostre rotte le rifiutavano, perche' verificavano solo
        // con la chiave attiva. La rotazione era indolore solo a meta'.
        $vecchio = JWT::encode(
            ['iss' => 'https://auth.example.test', 'aud' => 'notification-platform', 'sub' => '124-92',
             'roles' => ['operator'], 'iat' => time(), 'exp' => time() + 600],
            TestKeys::privatePem('uscente'),
            'RS256',
            'key-0'
        );

        $client = $this->client(['additionalPublicKeys' => ['key-0' => TestKeys::publicPem('uscente')]]);

        $verified = $client->verifyUserToken($vecchio);

        $this->assertNotNull($verified, 'un token firmato con la chiave uscente deve restare valido');
        $this->assertSame('124-92', $verified['sub']);
    }

    public function testRejectsGarbageWithoutThrowing(): void
    {
        $client = $this->client();

        $this->assertNull($client->verifyUserToken(''));
        $this->assertNull($client->verifyUserToken('   '));
        $this->assertNull($client->verifyUserToken('non.e.un.jwt'));
    }
}
