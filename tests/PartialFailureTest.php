<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Ottimis\Ermes\Tests\Support\ClientTestCase;

/**
 * Un 202 dice che il core ha ACCETTATO il lotto, non che l'ha pubblicato tutto.
 * `202 {published: 0, failed: 100}` e' una risposta legittima che significa "non e' arrivato
 * niente": guardare il solo status la faceva passare per successo pieno.
 */
final class PartialFailureTest extends ClientTestCase
{
    /** @return array<string, mixed> */
    private function liveEvent(): array
    {
        return ['topic' => 'monitor.paused', 'event_name' => 'data.event', 'recipient_users' => ['124-92']];
    }

    public function testACleanBatchIsASuccess(): void
    {
        $this->http->queue(202, ['accepted' => 1, 'published' => 1, 'skipped_offline' => 0, 'failed' => 0]);

        $res = $this->client()->sendLiveEvents([$this->liveEvent()]);

        $this->assertTrue($res['success']);
        $this->assertFalse($res['partial']);
        $this->assertSame(1, $res['published']);
        $this->assertSame(0, $res['failed']);
    }

    public function testNobodyOnlineIsNotAFailure(): void
    {
        // skipped_offline e' il funzionamento previsto di un evento live, non un errore.
        $this->http->queue(202, ['accepted' => 1, 'published' => 0, 'skipped_offline' => 1, 'failed' => 0]);

        $res = $this->client()->sendLiveEvents([$this->liveEvent()]);

        $this->assertTrue($res['success']);
        $this->assertFalse($res['partial']);
    }

    public function testAPartiallyPublishedBatchIsNotASuccess(): void
    {
        $this->http->queue(202, ['accepted' => 2, 'published' => 1, 'skipped_offline' => 0, 'failed' => 1]);

        $res = $this->client()->sendLiveEvents([$this->liveEvent(), $this->liveEvent()]);

        $this->assertFalse($res['success'], 'un lotto con failed > 0 non e andato a buon fine');
        $this->assertTrue($res['partial']);
        $this->assertSame(1, $res['failed']);
    }

    public function testTheCountersAreAlwaysPresentEvenWhenSkipped(): void
    {
        // Un chiamante che legge published/failed non deve prendere una chiave indefinita.
        $res = $this->client(['enabled' => false])->sendLiveEvents([$this->liveEvent()]);

        $this->assertArrayHasKey('published', $res);
        $this->assertArrayHasKey('failed', $res);
        $this->assertArrayHasKey('partial', $res);
        $this->assertFalse($res['partial']);
    }
}
