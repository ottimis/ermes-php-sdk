<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Ottimis\Ermes\Tests\Support\ClientTestCase;

final class SendEventTest extends ClientTestCase
{
    public function testAcceptedEventIsASuccess(): void
    {
        $this->http->queue(202, ['event_id' => 'evt-1', 'status' => 'accepted']);

        $result = $this->client()->sendEvent($this->validEvent(['event_id' => 'evt-1']));

        $this->assertTrue($result['success']);
        $this->assertSame(202, $result['core_status']);
        $this->assertSame(202, $result['statusCode']);
        $this->assertNull($result['error']);
        $this->assertFalse($result['skipped']);
        $this->assertSame('accepted', $result['body']['status']);
    }

    public function testReplayOfTheSameEventIdIsAlsoASuccess(): void
    {
        // Il core risponde 202 la prima volta e 200 already_processed sul replay idempotente:
        // chi ritenta non deve leggere un fallimento e ritentare all'infinito.
        $this->http
            ->queue(202, ['event_id' => 'evt-42', 'status' => 'accepted'])
            ->queue(200, ['event_id' => 'evt-42', 'status' => 'already_processed', 'inbox_status' => 'processed']);

        $event  = $this->validEvent(['event_id' => 'evt-42']);
        $client = $this->client();

        $first  = $client->sendEvent($event);
        $second = $client->sendEvent($event);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['success']);
        $this->assertSame(200, $second['core_status']);
        $this->assertSame('already_processed', $second['body']['status']);
    }

    public function testRejectedEventIsNotASuccess(): void
    {
        $this->http->queue(400, ['error' => 'invalid_payload']);

        $result = $this->client()->sendEvent($this->validEvent());

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['core_status']);
        $this->assertNull($result['error'], 'a regular 4xx is not a transport error');
        $this->assertSame('invalid_payload', $result['body']['error']);
    }

    public function testTransportFailureIsReported(): void
    {
        $this->http->queue(0, '', 'Could not resolve host: ermes.example.test');

        $result = $this->client()->sendEvent($this->validEvent());

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['core_status']);
        $this->assertSame('Could not resolve host: ermes.example.test', $result['error']);
    }

    public function testTenantAndApplicationAreInjectedAndTheEventIdIsReturned(): void
    {
        $this->http->queue(202);

        $result  = $this->client()->sendEvent($this->validEvent());
        $payload = $this->http->lastRequest()['data'];

        $this->assertSame('https://ermes.example.test/api/v1/events', $this->http->lastRequest()['url']);
        $this->assertSame('myapp', $payload['tenant_key']);
        $this->assertSame('my-backoffice', $payload['application_id']);
        $this->assertNotEmpty($result['event_id']);
        $this->assertSame($payload['event_id'], $result['event_id'], 'the caller must be able to retry with the same id');
    }

    public function testCallerSuppliedEventIdWins(): void
    {
        $this->http->queue(202);

        $result = $this->client()->sendEvent($this->validEvent(['event_id' => 'contract-1234-terminated']));

        $this->assertSame('contract-1234-terminated', $this->http->lastRequest()['data']['event_id']);
        $this->assertSame('contract-1234-terminated', $result['event_id']);
    }

    public function testProducerCallsUseBasicAuth(): void
    {
        $this->http->queue(202);
        $this->client()->sendEvent($this->validEvent());

        $this->assertContains(
            'Authorization: Basic ' . base64_encode('ak_test:as_test'),
            $this->http->lastRequest()['headers']
        );
    }

    public function testPerCallTimeoutsArePassedThrough(): void
    {
        $this->http->queue(202);
        $this->client()->sendEvent($this->validEvent(), ['timeout_ms' => 300, 'connect_timeout_ms' => 100]);

        $this->assertSame(['timeout_ms' => 300, 'connect_timeout_ms' => 100], $this->http->lastRequest()['opts']);
    }

    /**
     * @dataProvider invalidEvents
     *
     * @param array<string, mixed> $event
     */
    public function testInvalidEventsAreRejectedBeforeReachingTheNetwork(array $event, string $expectedMessage): void
    {
        try {
            $this->client()->sendEvent($event);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
            $this->assertSame(0, $this->http->requestCount(), 'nothing should have been sent');
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidEvents(): array
    {
        return [
            'missing topic'      => [['title' => 'x', 'recipient_users' => ['u1']], 'topic'],
            'missing title'      => [['topic' => 't', 'recipient_users' => ['u1']], 'title'],
            'no recipients'      => [['topic' => 't', 'title' => 'x', 'recipient_users' => []], 'recipient_users'],
            'recipients not set' => [['topic' => 't', 'title' => 'x'], 'recipient_users'],
            'too many recipients' => [
                ['topic' => 't', 'title' => 'x', 'recipient_users' => array_fill(0, 501, 'u')],
                'at most 500',
            ],
            'unknown severity' => [
                ['topic' => 't', 'title' => 'x', 'recipient_users' => ['u1'], 'severity' => 'critical'],
                'severity',
            ],
            'title too long' => [
                ['topic' => 't', 'title' => str_repeat('a', 256), 'recipient_users' => ['u1']],
                'at most 255 characters',
            ],
            'body too long' => [
                ['topic' => 't', 'title' => 'x', 'recipient_users' => ['u1'], 'body' => str_repeat('a', 10001)],
                'at most 10000 characters',
            ],
        ];
    }

    public function testDisabledConfigSkipsTheCallWithoutFailing(): void
    {
        $result = $this->client(['enabled' => false])->sendEvent($this->validEvent(['event_id' => 'evt-9']));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
        $this->assertSame('evt-9', $result['event_id']);
        $this->assertSame(0, $this->http->requestCount(), 'a disabled client must not pay any timeout');
    }
}
