<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Ottimis\Ermes\Tests\Support\ClientTestCase;

final class LiveEventsTest extends ClientTestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validLiveEvent(array $overrides = []): array
    {
        return array_merge([
            'topic'           => 'monitor.session',
            'event_name'      => 'session.updated',
            'recipient_users' => ['user_42'],
        ], $overrides);
    }

    public function testBatchIsSentWithTenantAndApplication(): void
    {
        $this->http->queue(202, ['accepted' => 2, 'published' => 1, 'skipped_offline' => 1, 'failed' => 0]);

        $result  = $this->client()->sendLiveEvents([$this->validLiveEvent(), $this->validLiveEvent()]);
        $request = $this->http->lastRequest();

        $this->assertTrue($result['success']);
        $this->assertSame('https://ermes.example.test/api/v1/events/live', $request['url']);
        $this->assertSame('myapp', $request['data']['tenant_key']);
        $this->assertSame('my-backoffice', $request['data']['application_id']);
        $this->assertCount(2, $request['data']['events']);
        $this->assertSame(1, $result['body']['skipped_offline']);
    }

    public function testSingleEventWrapperSendsABatchOfOne(): void
    {
        $this->http->queue(202);

        $this->client()->sendLiveEvent($this->validLiveEvent());

        $this->assertCount(1, $this->http->lastRequest()['data']['events']);
    }

    public function testEventsAreReindexedSoJsonEncodesAnArray(): void
    {
        $this->http->queue(202);

        $this->client()->sendLiveEvents([3 => $this->validLiveEvent()]);

        $json = json_encode($this->http->lastRequest()['data']['events']);
        $this->assertStringStartsWith('[', (string) $json, 'a JSON object here would be rejected by the core');
    }

    public function testRedisRefusalIsNotASuccess(): void
    {
        $this->http->queue(502, ['error' => 'publish_failed']);

        $this->assertFalse($this->client()->sendLiveEvents([$this->validLiveEvent()])['success']);
    }

    /**
     * @dataProvider invalidBatches
     *
     * @param array<int, mixed> $events
     */
    public function testInvalidBatchesAreRejectedBeforeReachingTheNetwork(array $events, string $expectedMessage): void
    {
        try {
            $this->client()->sendLiveEvents($events);
            $this->fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
            $this->assertSame(0, $this->http->requestCount());
        }
    }

    /**
     * @return array<string, array{0: array<int, mixed>, 1: string}>
     */
    public static function invalidBatches(): array
    {
        $valid = ['topic' => 't', 'event_name' => 'e.updated', 'recipient_users' => ['u1']];

        return [
            'empty batch'      => [[], 'at least one live event'],
            'too many events'  => [array_fill(0, 101, $valid), 'at most 100 events'],
            'missing topic'    => [[['event_name' => 'e.updated', 'recipient_users' => ['u1']]], 'events[0].topic'],
            'missing name'     => [[['topic' => 't', 'recipient_users' => ['u1']]], 'events[0].event_name'],
            'reserved name'    => [[['topic' => 't', 'event_name' => 'notification.new', 'recipient_users' => ['u1']]], 'persisted notifications'],
            'invalid name'     => [[['topic' => 't', 'event_name' => 'has space', 'recipient_users' => ['u1']]], 'must match'],
            'no recipients'    => [[['topic' => 't', 'event_name' => 'e.updated', 'recipient_users' => []]], 'events[0].recipient_users'],
            'bad severity'     => [[$valid + ['severity' => 'nope']], 'events[0].severity'],
            'not an array'     => [['nope'], 'events[0] must be an array'],
        ];
    }

    public function testDisabledConfigSkipsTheCall(): void
    {
        $result = $this->client(['enabled' => false])->sendLiveEvent($this->validLiveEvent());

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $this->http->requestCount());
    }

    public function testPresenceIsReadWithProducerCredentials(): void
    {
        $this->http->queue(200, ['online' => [['user_id' => 'user_42', 'focus' => ['page' => 'orders']]], 'ttl_sec' => 30]);

        $result = $this->client()->getPresence();

        $this->assertTrue($result['success']);
        $this->assertSame('https://ermes.example.test/api/v1/presence', $this->http->lastRequest()['url']);
        $this->assertContains(
            'Authorization: Basic ' . base64_encode('ak_test:as_test'),
            $this->http->lastRequest()['headers']
        );
        $this->assertSame('user_42', $result['body']['online'][0]['user_id']);
    }
}
