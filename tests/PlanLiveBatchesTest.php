<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Ottimis\Ermes\NotificationClient;
use PHPUnit\Framework\TestCase;

/**
 * L'SDK conosceva i limiti del core e si limitava a sollevare quando li si superava: la parte
 * non ovvia — dividere — restava al chiamante, che l'ha riscritta ogni volta. Qui la risolve.
 */
final class PlanLiveBatchesTest extends TestCase
{
    /** @return array<string, mixed> */
    private function event(int $recipients, string $topic = 'monitor.paused'): array
    {
        return [
            'topic'           => $topic,
            'event_name'      => 'data.event',
            'recipient_users' => array_map(static fn (int $i): string => "u{$i}", range(1, $recipients)),
        ];
    }

    public function testAnEventUnderTheLimitIsLeftAlone(): void
    {
        $batches = NotificationClient::planLiveBatches([$this->event(3)]);

        $this->assertCount(1, $batches);
        $this->assertCount(1, $batches[0]);
        $this->assertCount(3, $batches[0][0]['recipient_users']);
    }

    public function testSixHundredRecipientsBecomeTwoEventsWithTheSamePayload(): void
    {
        // Il caso reale: la prima organizzazione con piu' di 500 membri faceva rifiutare
        // dal core un lotto da 100 eventi INTERO, con un 400 invalid_payload.
        $batches = NotificationClient::planLiveBatches([$this->event(600)]);

        $this->assertCount(1, $batches);
        $this->assertCount(2, $batches[0]);
        $this->assertCount(500, $batches[0][0]['recipient_users']);
        $this->assertCount(100, $batches[0][1]['recipient_users']);
        $this->assertSame('monitor.paused', $batches[0][1]['topic']);
        $this->assertSame('data.event', $batches[0][1]['event_name']);
    }

    public function testMoreThanOneHundredEventsAreSplitIntoSeveralBatches(): void
    {
        $events = array_fill(0, 150, $this->event(2));

        $batches = NotificationClient::planLiveBatches($events);

        $this->assertCount(2, $batches);
        $this->assertCount(100, $batches[0]);
        $this->assertCount(50, $batches[1]);
    }

    public function testRecipientsAreSplitBeforeEventsAreGrouped(): void
    {
        // L'ordine conta: spezzare i destinatari AUMENTA il numero di eventi, quindi farlo
        // dopo il raggruppamento produce lotti ancora fuori limite.
        $events = array_fill(0, 60, $this->event(1000));

        $batches = NotificationClient::planLiveBatches($events);

        foreach ($batches as $batch) {
            $this->assertLessThanOrEqual(100, count($batch));
            foreach ($batch as $event) {
                $this->assertLessThanOrEqual(500, count($event['recipient_users']));
            }
        }
        $this->assertSame(120, array_sum(array_map('count', $batches)));
    }

    public function testDuplicateRecipientsAreRemoved(): void
    {
        $event = $this->event(3);
        $event['recipient_users'][] = 'u1';

        $batches = NotificationClient::planLiveBatches([$event]);

        $this->assertCount(3, $batches[0][0]['recipient_users']);
    }

    public function testAnEmptyInputProducesNoBatches(): void
    {
        $this->assertSame([], NotificationClient::planLiveBatches([]));
    }
}
