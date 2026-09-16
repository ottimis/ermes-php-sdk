<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Ottimis\Ermes\Jwks;
use Ottimis\Ermes\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

/**
 * La JWKS deve essere producibile SENZA un client, quindi senza credenziali del produttore.
 *
 * E' il caso di bootstrap di ogni tenant: il JWKS va pubblicato prima della registrazione su
 * Ermes, cioe' quando apiKey/apiSecret non esistono ancora. Finche' la derivazione e' vissuta
 * dentro NotificationClient, quel caso obbligava a costruire una config completa — che dalla
 * 1.3.0 solleva — oppure a riscriversi la derivazione a mano.
 */
final class JwksStandaloneTest extends TestCase
{
    public function testProducesAJwkWithoutAnyCredential(): void
    {
        $jwks = Jwks::fromPrivateKey(TestKeys::privatePem(), 'key-1');

        $this->assertCount(1, $jwks['keys']);
        $this->assertSame('RSA', $jwks['keys'][0]['kty']);
        $this->assertSame('RS256', $jwks['keys'][0]['alg']);
        $this->assertSame('sig', $jwks['keys'][0]['use']);
        $this->assertSame('key-1', $jwks['keys'][0]['kid']);
        $this->assertNotSame('', $jwks['keys'][0]['n']);
        $this->assertNotSame('', $jwks['keys'][0]['e']);
    }

    public function testAnUnreadableKeyYieldsAnEmptySetInsteadOfThrowing(): void
    {
        // Un endpoint JWKS che risponde 500 blocca la verifica di TUTTI i token del tenant.
        $this->assertSame(['keys' => []], Jwks::fromPrivateKey('non-e-un-pem', 'key-1'));
        $this->assertSame(['keys' => []], Jwks::fromPrivateKey('', 'key-1'));
    }

    public function testRotationKeysArePublishedAlongsideTheActiveOne(): void
    {
        $jwks = Jwks::fromPrivateKey(TestKeys::privatePem(), 'key-1', [
            'key-0' => TestKeys::privatePem(),
        ]);

        $this->assertCount(2, $jwks['keys']);
        $this->assertSame(['key-1', 'key-0'], array_column($jwks['keys'], 'kid'));
    }

    public function testKeysAreReadFromADirectoryUsingTheFilenameAsKid(): void
    {
        $dir = sys_get_temp_dir() . '/ermes-jwks-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/key-7.pem', TestKeys::privatePem());
        file_put_contents($dir . '/ignorato.txt', 'non e un pem');

        try {
            $keys = Jwks::publicKeysFromDirectory($dir);

            $this->assertSame(['key-7'], array_keys($keys));
            $this->assertStringContainsString('-----BEGIN', $keys['key-7']);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    public function testAMissingDirectoryIsNotAnError(): void
    {
        // La rotazione e' opzionale: una directory assente non deve impedire l'avvio.
        $this->assertSame([], Jwks::publicKeysFromDirectory('/directory/che/non/esiste'));
        $this->assertSame([], Jwks::publicKeysFromDirectory(''));
    }
}
