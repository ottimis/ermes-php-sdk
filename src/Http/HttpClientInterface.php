<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Http;

/**
 * Trasporto HTTP dell'SDK. Esiste per essere sostituibile: i consumatori che vogliono
 * testare senza rete, o passare per il proprio client PSR-18, iniettano la propria
 * implementazione nel costruttore di NotificationClient.
 *
 * Nessun metodo lancia per un errore di rete: il fallimento torna nell'array, con
 * `error` valorizzato e `statusCode` a 0. Chi chiama decide se è fatale.
 *
 * @phpstan-type HttpOptions array{timeout_ms?: int, connect_timeout_ms?: int}
 * @phpstan-type HttpResponse array{body: string, statusCode: int, error: string|null}
 */
interface HttpClientInterface
{
    /**
     * @param string[]                                          $headers header già formattate "Nome: valore"
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return array{body: string, statusCode: int, error: string|null}
     */
    public function get(string $url, array $headers = [], array $opts = []): array;

    /**
     * @param array<string, mixed>                              $data corpo JSON
     * @param string[]                                          $headers
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return array{body: string, statusCode: int, error: string|null}
     */
    public function post(string $url, array $data, array $headers = [], array $opts = []): array;
}
