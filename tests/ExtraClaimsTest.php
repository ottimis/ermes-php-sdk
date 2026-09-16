<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Ottimis\Ermes\Tests\Support\ClientTestCase;

/**
 * I claim applicativi servono per cose come l'id di sessione, con cui il backend del tenant
 * revoca un token al logout: finora chi ne aveva bisogno doveva firmare il token per conto
 * proprio, riscrivendo tutti gli altri claim.
 */
final class ExtraClaimsTest extends ClientTestCase
{
    public function testAnApplicationClaimEndsUpInTheToken(): void
    {
        $client = $this->client();

        $token   = $client->createUserToken('124-92', ['operator'], null, ['sid' => 'sessione-7']);
        $decoded = JWT::decode($token, JWK::parseKeySet($client->getJwks()));

        $this->assertSame('sessione-7', $decoded->sid);
        $this->assertSame('124-92', $decoded->sub);
    }

    public function testReservedClaimsCannotBeOverridden(): void
    {
        // Sovrascriverli significherebbe firmare un token valido per un altro tenant, un
        // altro utente o con un'altra scadenza: le cose che la firma dovrebbe garantire.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved claims');

        $this->client()->createUserToken('124-92', ['operator'], null, ['sub' => '1-1', 'exp' => 999]);
    }

    public function testTokensWithDifferentClaimsAreNotConfusedByTheCache(): void
    {
        $client = $this->client();

        $a = $client->createUserToken('124-92', ['operator'], null, ['sid' => 'sessione-a']);
        $b = $client->createUserToken('124-92', ['operator'], null, ['sid' => 'sessione-b']);

        $this->assertNotSame($a, $b, 'la cache non deve restituire il token di un altra sessione');
    }

    public function testWithoutExtraClaimsNothingChanges(): void
    {
        $client = $this->client();

        $decoded = JWT::decode($client->createUserToken('124-92'), JWK::parseKeySet($client->getJwks()));

        $this->assertSame('124-92', $decoded->sub);
        $this->assertSame('notification-platform', $decoded->aud);
    }
}
