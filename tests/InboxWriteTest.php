<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Ottimis\Ermes\Tests\Support\ClientTestCase;

/**
 * Archiviazione e ripristino: esistono sul core da sempre, ma non nell'SDK. Chi ne aveva
 * bisogno li ha scritti a mano con cURL, rifirmando un token RSA a ogni richiesta invece di
 * usare la cache che l'SDK ha gia'.
 */
final class InboxWriteTest extends ClientTestCase
{
    public function testDeleteHitsThePerUuidRouteWithTheUserBearer(): void
    {
        $this->http->queue(204);

        $res = $this->client()->deleteNotification('6b7d0f3e-0000-4000-8000-000000000001', 'user_42');

        $req = $this->http->lastRequest();
        $this->assertSame(
            'https://ermes.example.test/api/v1/notifications/6b7d0f3e-0000-4000-8000-000000000001/delete',
            $req['url']
        );
        $this->assertStringStartsWith('Bearer ', $this->authorizationOf($req));
        $this->assertTrue($res['success']);
    }

    public function testRestoreHitsItsOwnRoute(): void
    {
        $this->http->queue(204);

        $this->client()->restoreNotification('6b7d0f3e-0000-4000-8000-000000000001', 'user_42');

        $this->assertStringEndsWith('/restore', $this->http->lastRequest()['url']);
    }

    public function testBulkDeleteSendsTheUuidsReindexed(): void
    {
        $this->http->queue(204);

        $this->client()->deleteBulk([3 => 'a-uuid', 7 => 'b-uuid'], 'user_42');

        $this->assertSame(
            ['a-uuid', 'b-uuid'],
            $this->http->lastRequest()['data']['notification_uuids']
        );
    }

    public function testTheUuidLimitOfTheCoreIsEnforcedBeforeSending(): void
    {
        // Il core accetta 1..200: oltre risponde 400 e l'intera chiamata si perde. Il README
        // prometteva il limite senza che nessuno lo verificasse.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 200');

        $this->client()->deleteBulk(array_fill(0, 201, 'a-uuid'), 'user_42');
    }

    public function testAnEmptyBulkIsRejectedWithoutTouchingTheNetwork(): void
    {
        try {
            $this->client()->markBulkRead([], 'user_42');
            $this->fail('un batch vuoto deve essere rifiutato');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, $this->http->requestCount());
        }
    }

    public function testWithErmesDisabledNothingIsSent(): void
    {
        $res = $this->client(['enabled' => false])->deleteNotification('a-uuid', 'user_42');

        $this->assertTrue($res['skipped']);
        $this->assertSame(0, $this->http->requestCount());
    }

    /** @param array<string, mixed> $request */
    private function authorizationOf(array $request): string
    {
        foreach ($request['headers'] as $header) {
            if (stripos($header, 'Authorization:') === 0) {
                return trim(substr($header, strlen('Authorization:')));
            }
        }

        return '';
    }
}
