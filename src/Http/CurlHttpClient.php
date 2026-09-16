<?php

declare(strict_types=1);

namespace Ottimis\Ermes\Http;

/**
 * Trasporto cURL di default.
 *
 * I timeout sono corti di proposito: quasi tutte le chiamate dell'SDK stanno sul percorso
 * di una risposta HTTP del consumatore, dove un core irraggiungibile non deve trasformarsi
 * in una pagina che non arriva. Chi fa invii in batch fuori dal ciclo di richiesta alza i
 * valori dalla config o per singola chiamata.
 */
class CurlHttpClient implements HttpClientInterface
{
    /** Attesa massima della risposta completa. */
    public const DEFAULT_TIMEOUT_MS = 2000;

    /** Attesa massima della sola connessione (DNS + TCP + TLS). */
    public const DEFAULT_CONNECT_TIMEOUT_MS = 1000;

    /**
     * Flag di json_encode usati per il corpo. JSON_THROW_ON_ERROR fa emergere subito il
     * dato che non si può serializzare, invece di lasciar partire un POST con corpo vuoto;
     * INVALID_UTF8_SUBSTITUTE salva i testi che arrivano da tabelle latin1.
     */
    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES;

    public function __construct(
        private readonly int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        private readonly int $connectTimeoutMs = self::DEFAULT_CONNECT_TIMEOUT_MS,
    ) {}

    /**
     * @param string[]                                          $headers
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return array{body: string, statusCode: int, error: string|null}
     */
    public function get(string $url, array $headers = [], array $opts = []): array
    {
        return $this->send([
            CURLOPT_URL        => $url,
            CURLOPT_HTTPHEADER => $headers,
        ], $opts);
    }

    /**
     * @param array<string, mixed>                              $data
     * @param string[]                                          $headers
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return array{body: string, statusCode: int, error: string|null}
     */
    public function post(string $url, array $data, array $headers = [], array $opts = []): array
    {
        try {
            $payload = json_encode($data, self::JSON_FLAGS);
        } catch (\JsonException $e) {
            return ['body' => '', 'statusCode' => 0, 'error' => 'json_encode failed: ' . $e->getMessage()];
        }

        return $this->send([
            CURLOPT_URL        => $url,
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
        ], $opts);
    }

    /**
     * @param array<int, mixed>                                 $curlOptions
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return array{body: string, statusCode: int, error: string|null}
     */
    private function send(array $curlOptions, array $opts): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, $curlOptions + [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_SSL_VERIFYHOST    => 2,
            CURLOPT_TIMEOUT_MS        => (int) ($opts['timeout_ms'] ?? $this->timeoutMs),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($opts['connect_timeout_ms'] ?? $this->connectTimeoutMs),
        ]);

        $body       = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_error va letto prima di curl_close, e solo se la chiamata è fallita:
        // su una risposta 4xx/5xx regolare curl non riporta nessun errore.
        $error = $body === false ? (curl_error($ch) ?: 'unknown cURL error') : null;
        curl_close($ch);

        return [
            'body'       => is_string($body) ? $body : '',
            'statusCode' => $statusCode,
            'error'      => $error,
        ];
    }
}
