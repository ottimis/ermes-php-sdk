<?php

namespace Ottimis\Ermes\Internal;

class HttpClient
{
    /** Timeout totale di default, in secondi. Invariato dalla 1.0.0. */
    private const DEFAULT_TIMEOUT_SECONDS = 10;

    /**
     * Opzioni per-chiamata. Servono a chi fa richieste sul percorso di una risposta HTTP,
     * dove 10 secondi di attesa su un servizio che non risponde sono inaccettabili.
     *
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     * @return array<int, mixed> opzioni cURL da unire a quelle di base
     */
    private function timeoutOptions(array $opts): array
    {
        $curl = [];
        if (isset($opts['timeout_ms'])) {
            // CURLOPT_TIMEOUT_MS vince su CURLOPT_TIMEOUT quando entrambi sono impostati,
            // ma lasciarli insieme rende ambigua la lettura: si sceglie l'uno o l'altro.
            $curl[CURLOPT_TIMEOUT_MS] = (int) $opts['timeout_ms'];
        } else {
            $curl[CURLOPT_TIMEOUT] = self::DEFAULT_TIMEOUT_SECONDS;
        }
        if (isset($opts['connect_timeout_ms'])) {
            $curl[CURLOPT_CONNECTTIMEOUT_MS] = (int) $opts['connect_timeout_ms'];
        }
        return $curl;
    }

    /**
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     */
    public function get(string $url, array $headers = [], array $opts = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ] + $this->timeoutOptions($opts));
        $body       = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => $body ?: '', 'statusCode' => $statusCode];
    }

    /**
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     */
    public function post(string $url, array $data, array $headers = [], array $opts = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($data),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ] + $this->timeoutOptions($opts));
        $body       = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => $body ?: '', 'statusCode' => $statusCode];
    }
}

