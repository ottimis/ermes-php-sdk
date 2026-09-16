<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Ottimis\Ermes\Tests\Support\ClientTestCase;

final class InboxTest extends ClientTestCase
{
    public function testListPassesTheKnownParametersOnly(): void
    {
        // I consumatori inoltrano spesso la query string grezza della propria richiesta:
        // quello che il core non conosce non deve arrivarci.
        $this->http->queue(200, ['items' => [], 'pagination' => ['page' => 1]]);

        $this->client()->getNotifications('user_42', [
            'status' => 'unread',
            'page'   => 2,
            'limit'  => 20,
            'topic'  => 'contracts.approved',
            'debug'  => 'sql',
            'user_id' => 'user_1',
        ]);

        $url = $this->http->lastRequest()['url'];
        $this->assertStringStartsWith('https://ermes.example.test/api/v1/notifications?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['status' => 'unread', 'page' => '2', 'limit' => '20', 'topic' => 'contracts.approved'], $query);
    }

    public function testListWithoutParametersHasNoQueryString(): void
    {
        $this->http->queue(200, ['items' => []]);

        $this->client()->getNotifications('user_42');

        $this->assertSame('https://ermes.example.test/api/v1/notifications', $this->http->lastRequest()['url']);
    }

    public function testSyncPassesCursorAndLimitOnly(): void
    {
        $this->http->queue(200, ['items' => [], 'cursor' => null]);

        $this->client()->syncNotifications('user_42', ['after' => 'notif_1250', 'limit' => 50, 'status' => 'unread']);

        parse_str((string) parse_url($this->http->lastRequest()['url'], PHP_URL_QUERY), $query);
        $this->assertSame(['after' => 'notif_1250', 'limit' => '50'], $query);
    }

    public function testInboxCallsCarryAUserScopedBearerToken(): void
    {
        $this->http->queue(200, ['count' => 3]);

        $client = $this->client();
        $client->getUnreadCount('user_42');

        $authorization = '';
        foreach ($this->http->lastRequest()['headers'] as $header) {
            if (str_starts_with($header, 'Authorization: Bearer ')) {
                $authorization = substr($header, strlen('Authorization: Bearer '));
            }
        }

        $this->assertNotSame('', $authorization, 'inbox calls must be authenticated as the user');
        $claims = JWT::decode($authorization, JWK::parseKeySet($client->getJwks()));
        $this->assertSame('user_42', $claims->sub);
        $this->assertLessThanOrEqual(3600, $claims->exp - $claims->iat, 'proxied tokens must be short lived too');
    }

    public function testUnreadCountReadsTheBody(): void
    {
        $this->http->queue(200, ['count' => 7]);

        $result = $this->client()->getUnreadCount('user_42');

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['body']['count']);
    }

    public function testMarkAsReadAcceptsBoth200And204(): void
    {
        $this->http->queue(204)->queue(200, ['updated' => 1]);

        $client = $this->client();

        $this->assertTrue($client->markAsRead('1b2c3d4e-5f60-4718-9abc-def012345678', 'user_42')['success']);
        $this->assertSame(
            'https://ermes.example.test/api/v1/notifications/1b2c3d4e-5f60-4718-9abc-def012345678/read',
            $this->http->lastRequest()['url']
        );
        $this->assertTrue($client->markAllAsRead('user_42')['success']);
    }

    public function testBulkReadSendsAListOfUuids(): void
    {
        $this->http->queue(200, ['updated' => 2]);

        $this->client()->markBulkRead([5 => 'uuid-a', 9 => 'uuid-b'], 'user_42');

        $this->assertSame(['notification_uuids' => ['uuid-a', 'uuid-b']], $this->http->lastRequest()['data']);
    }

    public function testExpiredOrInvalidTokenIsReportedAsFailure(): void
    {
        $this->http->queue(401, ['error' => 'invalid_token']);

        $result = $this->client()->getNotifications('user_42');

        $this->assertFalse($result['success']);
        $this->assertSame(401, $result['statusCode']);
        $this->assertSame('invalid_token', $result['body']['error']);
    }

    public function testTransportFailureOnReadIsReported(): void
    {
        $this->http->queue(0, '', 'Operation timed out after 2000 milliseconds');

        $result = $this->client()->getNotifications('user_42');

        $this->assertFalse($result['success']);
        $this->assertSame('Operation timed out after 2000 milliseconds', $result['error']);
    }

    public function testDisabledConfigSkipsInboxCalls(): void
    {
        $client = $this->client(['enabled' => false]);

        $this->assertTrue($client->getNotifications('user_42')['skipped']);
        $this->assertTrue($client->getUnreadCount('user_42')['skipped']);
        $this->assertTrue($client->syncNotifications('user_42')['skipped']);
        $this->assertTrue($client->markAllAsRead('user_42')['skipped']);
        $this->assertSame(0, $this->http->requestCount());
    }
}
