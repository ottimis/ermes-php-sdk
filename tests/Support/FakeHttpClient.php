<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Tests\Support;

use Ottimis\Ermes\Http\HttpClientInterface;

/**
 * Trasporto finto: registra le richieste e restituisce risposte preparate, in ordine.
 * Esaurite le risposte preparate risponde 200 con corpo vuoto.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, array{method: string, url: string, headers: string[], data: array<string, mixed>|null, opts: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<int, array{body: string, statusCode: int, error: string|null}> */
    private array $responses = [];

    /**
     * @param array<string, mixed>|string $body corpo come array (verrà codificato) o stringa grezza
     */
    public function queue(int $statusCode, array|string $body = '', ?string $error = null): self
    {
        $this->responses[] = [
            'body'       => is_string($body) ? $body : (string) json_encode($body),
            'statusCode' => $statusCode,
            'error'      => $error,
        ];

        return $this;
    }

    public function get(string $url, array $headers = [], array $opts = []): array
    {
        $this->requests[] = ['method' => 'GET', 'url' => $url, 'headers' => $headers, 'data' => null, 'opts' => $opts];

        return $this->next();
    }

    public function post(string $url, array $data, array $headers = [], array $opts = []): array
    {
        $this->requests[] = ['method' => 'POST', 'url' => $url, 'headers' => $headers, 'data' => $data, 'opts' => $opts];

        return $this->next();
    }

    /**
     * @return array{method: string, url: string, headers: string[], data: array<string, mixed>|null, opts: array<string, mixed>}
     */
    public function lastRequest(): array
    {
        if ($this->requests === []) {
            throw new \RuntimeException('No request was made.');
        }

        return $this->requests[array_key_last($this->requests)];
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * @return array{body: string, statusCode: int, error: string|null}
     */
    private function next(): array
    {
        return array_shift($this->responses) ?? ['body' => '', 'statusCode' => 200, 'error' => null];
    }
}
