<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests;

use Ottimis\Ermes\NotificationClient;
use Ottimis\Ermes\Tests\Support\ClientTestCase;

/**
 * Il client completo contro un core che non risponde, con il trasporto vero: verifica che i
 * timeout della configurazione arrivino davvero fino a cURL e che chi chiama riceva un errore
 * invece di restare appeso.
 */
final class UnreachableCoreTest extends ClientTestCase
{
    public function testSendEventGivesUpQuicklyWhenTheCoreNeverAnswers(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);

        // Nessun client finto qui: NotificationClient costruisce da sé il trasporto cURL con
        // i timeout della configurazione.
        $client = new NotificationClient($this->config(['coreUrl' => 'http://' . $address]));

        try {
            $started = microtime(true);
            $result  = $client->sendEvent($this->validEvent(['event_id' => 'evt-1']));
            $elapsed = microtime(true) - $started;
        } finally {
            fclose($socket);
        }

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error'], 'the caller must learn why the event did not go out');
        $this->assertSame(0, $result['core_status']);
        $this->assertSame('evt-1', $result['event_id'], 'the caller needs the id to retry with');
        $this->assertLessThan(2.5, $elapsed, 'the default timeout is 2s: a dead core must not hold the request');
    }

    public function testInboxReadGivesUpQuicklyToo(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);

        $client = new NotificationClient($this->config(['coreUrl' => 'http://' . $address]));

        try {
            $started = microtime(true);
            $result  = $client->getUnreadCount('user_42');
            $elapsed = microtime(true) - $started;
        } finally {
            fclose($socket);
        }

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertLessThan(2.5, $elapsed);
    }

    public function testConfiguredTimeoutReachesTheTransport(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);

        $client = new NotificationClient($this->config(['coreUrl' => 'http://' . $address, 'timeoutMs' => 200]));

        try {
            $started = microtime(true);
            $client->sendEvent($this->validEvent());
            $elapsed = microtime(true) - $started;
        } finally {
            fclose($socket);
        }

        $this->assertLessThan(1.0, $elapsed, 'a 200ms configured timeout must not wait for the 2s default');
    }
}
