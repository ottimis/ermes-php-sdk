<?php

declare(strict_types=1);

namespace Ottimis\Ermes;

use Firebase\JWT\JWT;
use Ottimis\Ermes\Http\CurlHttpClient;
use Ottimis\Ermes\Http\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Client dell'API Ermes: invio eventi, emissione dei token utente, proxy dell'inbox.
 *
 * Ogni metodo di rete torna un array con la stessa forma:
 * `['success' => bool, 'core_status' => int, 'statusCode' => int, 'body' => array|null,
 *   'error' => string|null, 'skipped' => bool]`.
 * `core_status` e `statusCode` sono lo stesso valore con due nomi, per compatibilità con
 * le versioni precedenti. `error` è valorizzato solo quando la richiesta non è partita o
 * non è arrivata (DNS, TLS, timeout): una 4xx/5xx regolare ha `error` a null e lo stato in
 * `core_status`. `skipped` è true solo quando la config ha `enabled: false`.
 *
 * @phpstan-type ErmesResult array{success: bool, core_status: int, statusCode: int, body: array<string, mixed>|null, error: string|null, skipped: bool}
 * @phpstan-type ErmesSendResult array{success: bool, core_status: int, statusCode: int, body: array<string, mixed>|null, error: string|null, skipped: bool, event_id: string}
 */
class NotificationClient
{
    /** Severità ammesse dal core (openapi.yaml). */
    private const SEVERITIES = ['info', 'warning', 'error', 'success'];

    /** Nome evento riservato alle notifiche persistite: i live event non possono usarlo. */
    private const RESERVED_EVENT_NAME = 'notification.new';

    private const MAX_RECIPIENTS = 500;
    private const MAX_LIVE_EVENTS = 100;

    /** Margine di sicurezza sulla cache dei token: non si riusa un token che sta per scadere. */
    private const TOKEN_CACHE_SKEW_SECONDS = 60;

    private readonly HttpClientInterface $http;

    /** @var array<string, array{token: string, info: array<string, mixed>}> */
    private array $tokenCache = [];

    public function __construct(
        private readonly NotificationConfig $config,
        ?HttpClientInterface $http = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->http = $http ?? new CurlHttpClient($config->timeoutMs, $config->connectTimeoutMs);
    }

    /**
     * JWKS pubblica del tenant, da esporre su /.well-known/jwks.json perché Ermes possa
     * validare i token utente.
     *
     * Non lancia mai: una chiave illeggibile torna una JWKS vuota e viene loggata. L'endpoint
     * è montato spesso senza try/catch e un 500 qui ferma la validazione dei token del tenant,
     * il che è peggio di una JWKS vuota.
     *
     * @return array{keys: array<int, array<string, string>>}
     */
    public function getJwks(): array
    {
        $keys = [];

        $current = $this->jwkFromPem($this->config->privateKeyPem(), $this->config->kid, true);
        if ($current !== null) {
            $keys[] = $current;
        }

        foreach ($this->config->additionalPublicKeys as $kid => $pem) {
            $extra = $this->jwkFromPem((string) $pem, (string) $kid, false);
            if ($extra !== null) {
                $keys[] = $extra;
            }
        }

        return ['keys' => $keys];
    }

    /**
     * Emette un JWT RS256 per l'utente, da usare per l'handshake Socket.IO e per l'inbox.
     * Torna la sola stringa del token.
     *
     * @param string[] $roles ruoli applicativi propagati nel claim `roles`
     * @param int|null $ttl   durata in secondi; null usa `userTokenTtl` della config (default 1 ora).
     *                        Un token è irrevocabile fino alla scadenza: allungarlo è una scelta
     *                        da fare consapevolmente, non un default.
     */
    public function createUserToken(
        #[\SensitiveParameter]
        string $userId,
        array $roles = ['operator'],
        ?int $ttl = null,
    ): string {
        return $this->createUserTokenWithInfo($userId, $roles, $ttl)['token'];
    }

    /**
     * Come createUserToken(), ma torna anche i claim: `info['exp']` dice al frontend quando
     * dovrà chiedere un token nuovo.
     *
     * @param string[] $roles
     * @param int|null $ttl
     *
     * @return array{token: string, info: array<string, mixed>}
     */
    public function createUserTokenWithInfo(
        #[\SensitiveParameter]
        string $userId,
        array $roles = ['operator'],
        ?int $ttl = null,
    ): array {
        if (trim($userId) === '') {
            throw new \InvalidArgumentException('Ermes SDK: userId is required to issue a user token.');
        }
        if ($this->config->privateKeyPem() === '') {
            throw new \LogicException(
                'Ermes SDK: cannot issue a user token, no private key configured.'
            );
        }

        $ttl = $ttl ?? $this->config->userTokenTtl;
        if ($ttl < 1) {
            throw new \InvalidArgumentException('Ermes SDK: token ttl must be a positive number of seconds.');
        }

        $roles    = array_values($roles);
        $cacheKey = $userId . '|' . implode(',', $roles) . '|' . $ttl;
        $now      = time();

        // Le chiamate proxy dell'inbox firmano un token a ogni richiesta: senza cache si
        // paga una firma RSA per ogni lettura della stessa pagina.
        $cached = $this->tokenCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['info']['exp'] > $now + self::TOKEN_CACHE_SKEW_SECONDS) {
            return $cached;
        }

        $claims = [
            'tenant_id' => $this->config->tenantKey,
            'roles'     => $roles,
            'iss'       => $this->config->issuer,
            'aud'       => $this->config->audience,
            'sub'       => $userId,
            'iat'       => $now,
            'exp'       => $now + $ttl,
        ];

        $result = [
            'token' => JWT::encode($claims, $this->config->privateKeyPem(), 'RS256', $this->config->kid),
            'info'  => $claims,
        ];

        return $this->tokenCache[$cacheKey] = $result;
    }

    /**
     * Invia un evento di business (POST /api/v1/events): viene persistito nell'inbox e
     * recapitato via Socket.IO. Auth Basic. `tenant_key` e `application_id` sono iniettati.
     *
     * `event_id` è la chiave di idempotenza del core. Se non lo passi ne viene generato uno
     * casuale, quindi **ogni retry crea una notifica duplicata**: per un invio ritentabile
     * passa un identificatore deterministico del fatto di dominio (es. `contract-1234-terminated`).
     * L'`event_id` effettivamente usato torna nel risultato.
     *
     * @param array<string, mixed>                              $event
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesSendResult
     *
     * @throws \InvalidArgumentException se l'evento non rispetta i vincoli del core
     */
    public function sendEvent(array $event, array $opts = []): array
    {
        // I campi che dicono CHI sta scrivendo si applicano DOPO quelli del chiamante.
        // Con `array_merge([...autorevoli], $event)` un payload che contenesse `tenant_key` o
        // `application_id` li sovrascriveva, e l'evento partiva dichiarando un mittente
        // diverso da quello autenticato dalle credenziali Basic. L'`event_id` invece resta
        // del chiamante quando lo fornisce: e' la chiave di idempotenza, e serve proprio
        // perche' un retry possa riusare la stessa.
        $payload = $event;
        $payload['event_id'] = isset($event['event_id']) && $event['event_id'] !== ''
            ? (string) $event['event_id']
            : 'evt-' . (int) (microtime(true) * 1000) . '-' . bin2hex(random_bytes(8));
        $payload['tenant_key'] = $this->config->tenantKey;
        $payload['application_id'] = $this->config->applicationId;

        $this->assertValidEvent($payload);

        if (!$this->config->enabled) {
            return $this->skipped() + ['event_id' => (string) $payload['event_id']];
        }

        $res = $this->http->post(
            $this->config->coreUrl . '/api/v1/events',
            $payload,
            $this->producerHeaders(),
            $opts
        );

        // 202 alla prima consegna, 200 con status=already_processed quando il core riconosce
        // un event_id già visto: per chi ritenta è un successo, non un errore da ritentare ancora.
        return $this->respond($res, [200, 202]) + ['event_id' => (string) $payload['event_id']];
    }

    /**
     * Invia eventi dati non persistiti (POST /api/v1/events/live). Richiede Ermes >= 0.2.0.
     *
     * Sono recapitati via Socket.IO ai soli destinatari online in quel momento e non sono
     * mai salvati: non compaiono nell'inbox, non contano come non letti, non si recuperano
     * con /sync. Non hanno `event_id` perché non c'è niente da deduplicare — un evento perso
     * si recupera rileggendo i propri dati alla riconnessione. È un campanello, non un dato.
     *
     * Ogni evento richiede `topic`, `event_name` (qualsiasi cosa tranne `notification.new`)
     * e `recipient_users` non vuoto.
     *
     * @param array<int, array<string, mixed>>                  $events 1-100 eventi
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     *
     * @throws \InvalidArgumentException se il batch non rispetta i vincoli del core
     */
    public function sendLiveEvents(array $events, array $opts = []): array
    {
        $events = array_values($events);
        $this->assertValidLiveEvents($events);

        if (!$this->config->enabled) {
            return $this->skipped();
        }

        $res = $this->http->post(
            $this->config->coreUrl . '/api/v1/events/live',
            [
                'tenant_key'     => $this->config->tenantKey,
                'application_id' => $this->config->applicationId,
                'events'         => $events,
            ],
            $this->producerHeaders(),
            $opts
        );

        return $this->respond($res, [200, 202]);
    }

    /**
     * Scorciatoia per un singolo evento live.
     *
     * @param array<string, mixed>                              $event
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     */
    public function sendLiveEvent(array $event, array $opts = []): array
    {
        return $this->sendLiveEvents([$event], $opts);
    }

    /**
     * Utenti del tenant con una presenza attiva e il focus dichiarato dai loro socket
     * (GET /api/v1/presence). Richiede Ermes >= 0.2.0.
     *
     * Serve a decidere se vale la pena mandare eventi live: nessuno collegato, niente da
     * recapitare. Il contenuto di `focus` è opaco alla piattaforma, è quello che il client
     * del tenant ha emesso con l'evento socket `focus`.
     *
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     *         body: ['online' => [['user_id' => string, 'focus' => array], ...], 'ttl_sec' => int]
     */
    public function getPresence(array $opts = []): array
    {
        if (!$this->config->enabled) {
            return $this->skipped();
        }

        $res = $this->http->get(
            $this->config->coreUrl . '/api/v1/presence',
            $this->producerHeaders(),
            $opts
        );

        return $this->respond($res, [200]);
    }

    /**
     * Inbox dell'utente (GET /api/v1/notifications).
     *
     * @param array<string, mixed>                              $params status, page, limit, topic.
     *                                                                  Le altre chiavi sono ignorate: il metodo
     *                                                                  riceve spesso query string grezze.
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     */
    public function getNotifications(string $userId, array $params = [], array $opts = []): array
    {
        return $this->userGet(
            '/api/v1/notifications',
            $userId,
            $this->filterParams($params, ['status', 'page', 'limit', 'topic']),
            $opts
        );
    }

    /**
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     */
    public function getUnreadCount(string $userId, array $opts = []): array
    {
        return $this->userGet('/api/v1/notifications/unread-count', $userId, [], $opts);
    }

    /**
     * Lettura incrementale a cursore (GET /api/v1/notifications/sync).
     *
     * @param array<string, mixed>                              $params after, limit. Le altre chiavi sono ignorate.
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     */
    public function syncNotifications(string $userId, array $params = [], array $opts = []): array
    {
        return $this->userGet(
            '/api/v1/notifications/sync',
            $userId,
            $this->filterParams($params, ['after', 'limit']),
            $opts
        );
    }

    /**
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     */
    public function markAsRead(string $uuid, string $userId, array $opts = []): array
    {
        return $this->userPost("/api/v1/notifications/{$uuid}/read", $userId, [], $opts);
    }

    /**
     * @param string[]                                          $uuids fino a 200 UUID
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     */
    public function markBulkRead(array $uuids, string $userId, array $opts = []): array
    {
        return $this->userPost(
            '/api/v1/notifications/read',
            $userId,
            ['notification_uuids' => array_values($uuids)],
            $opts
        );
    }

    /**
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     */
    public function markAllAsRead(string $userId, array $opts = []): array
    {
        return $this->userPost('/api/v1/notifications/read-all', $userId, [], $opts);
    }

    /**
     * @param array<string, mixed>                              $params
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     */
    private function userGet(string $path, string $userId, array $params, array $opts): array
    {
        if (!$this->config->enabled) {
            return $this->skipped();
        }

        $url = $this->config->coreUrl . $path;
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        return $this->respond($this->http->get($url, $this->bearerHeaders($userId), $opts), [200]);
    }

    /**
     * @param array<string, mixed>                              $body
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     */
    private function userPost(string $path, string $userId, array $body, array $opts): array
    {
        if (!$this->config->enabled) {
            return $this->skipped();
        }

        $res = $this->http->post(
            $this->config->coreUrl . $path,
            $body,
            $this->bearerHeaders($userId),
            $opts
        );

        return $this->respond($res, [200, 204]);
    }

    /**
     * @param array{body: string, statusCode: int, error?: string|null} $res
     * @param int[]                                                     $successCodes
     *
     * @return ErmesResult
     */
    private function respond(array $res, array $successCodes): array
    {
        $statusCode = $res['statusCode'];
        $decoded    = $res['body'] !== '' ? json_decode($res['body'], true) : null;

        return [
            'success'     => in_array($statusCode, $successCodes, true),
            'core_status' => $statusCode,
            'statusCode'  => $statusCode,
            'body'        => is_array($decoded) ? $decoded : null,
            'error'       => $res['error'] ?? null,
            'skipped'     => false,
        ];
    }

    /**
     * Risposta per config con `enabled: false`: nessuna chiamata parte, e il chiamante non
     * vede un errore per qualcosa che ha scelto di spegnere. `skipped` distingue il caso.
     *
     * @return ErmesResult
     */
    private function skipped(): array
    {
        return [
            'success'     => true,
            'core_status' => 0,
            'statusCode'  => 0,
            'body'        => null,
            'error'       => null,
            'skipped'     => true,
        ];
    }

    /**
     * Header per le chiamate producer (backend-to-backend): Basic con le credenziali del tenant.
     *
     * @return string[]
     */
    private function producerHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: ' . $this->config->basicAuthHeaderValue(),
        ];
    }

    /**
     * @return string[]
     */
    private function bearerHeaders(string $userId): array
    {
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->createUserToken($userId),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param string[]             $allowed
     *
     * @return array<string, mixed>
     */
    private function filterParams(array $params, array $allowed): array
    {
        return array_intersect_key($params, array_flip($allowed));
    }

    /**
     * Vincoli che, se violati, fanno perdere l'intera richiesta contro il core. Vengono
     * verificati qui perché un InvalidArgumentException con il nome del campo è leggibile,
     * una 400 `invalid_payload` no.
     *
     * @param array<string, mixed> $event
     */
    private function assertValidEvent(array $event): void
    {
        $this->assertNonEmptyString($event, 'topic', 255);
        $this->assertNonEmptyString($event, 'title', 255);
        $this->assertMaxLength($event, 'event_id', 255);
        $this->assertMaxLength($event, 'body', 10000);
        $this->assertMaxLength($event, 'entity_type', 100);
        $this->assertMaxLength($event, 'entity_id', 255);
        $this->assertRecipients($event['recipient_users'] ?? null, 'recipient_users');

        if (isset($event['severity']) && !in_array($event['severity'], self::SEVERITIES, true)) {
            throw new \InvalidArgumentException(
                'Ermes SDK: severity must be one of ' . implode(', ', self::SEVERITIES) . '.'
            );
        }
    }

    /**
     * @param array<int, mixed> $events
     */
    private function assertValidLiveEvents(array $events): void
    {
        if ($events === []) {
            throw new \InvalidArgumentException('Ermes SDK: events must contain at least one live event.');
        }
        if (count($events) > self::MAX_LIVE_EVENTS) {
            throw new \InvalidArgumentException(
                'Ermes SDK: events accepts at most ' . self::MAX_LIVE_EVENTS . ' events per request, got ' . count($events) . '.'
            );
        }

        foreach ($events as $i => $event) {
            if (!is_array($event)) {
                throw new \InvalidArgumentException("Ermes SDK: events[{$i}] must be an array.");
            }
            $this->assertNonEmptyString($event, 'topic', 255, "events[{$i}].");
            $this->assertNonEmptyString($event, 'event_name', 100, "events[{$i}].");
            $this->assertRecipients($event['recipient_users'] ?? null, "events[{$i}].recipient_users");

            if ($event['event_name'] === self::RESERVED_EVENT_NAME) {
                throw new \InvalidArgumentException(
                    "Ermes SDK: events[{$i}].event_name cannot be '" . self::RESERVED_EVENT_NAME
                    . "', that name belongs to persisted notifications."
                );
            }
            if (!is_string($event['event_name']) || preg_match('/^[a-zA-Z0-9_.:\-]{1,100}$/', $event['event_name']) !== 1) {
                throw new \InvalidArgumentException(
                    "Ermes SDK: events[{$i}].event_name must match ^[a-zA-Z0-9_.:\\-]{1,100}$."
                );
            }
            if (isset($event['severity']) && !in_array($event['severity'], self::SEVERITIES, true)) {
                throw new \InvalidArgumentException(
                    "Ermes SDK: events[{$i}].severity must be one of " . implode(', ', self::SEVERITIES) . '.'
                );
            }
        }
    }

    private function assertRecipients(mixed $recipients, string $field): void
    {
        if (!is_array($recipients) || $recipients === []) {
            throw new \InvalidArgumentException("Ermes SDK: {$field} must be a non-empty array of user IDs.");
        }
        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException(
                "Ermes SDK: {$field} accepts at most " . self::MAX_RECIPIENTS . ' user IDs, got ' . count($recipients) . '.'
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertNonEmptyString(array $data, string $field, int $maxLength, string $prefix = ''): void
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Ermes SDK: {$prefix}{$field} is required and must be a non-empty string.");
        }
        $this->assertMaxLength($data, $field, $maxLength, $prefix);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertMaxLength(array $data, string $field, int $maxLength, string $prefix = ''): void
    {
        $value = $data[$field] ?? null;
        if (is_string($value) && mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(
                "Ermes SDK: {$prefix}{$field} must be at most {$maxLength} characters, got " . mb_strlen($value) . '.'
            );
        }
    }

    /**
     * Estrae modulo ed esponente da un PEM. Torna null, senza lanciare, se il PEM non è
     * leggibile o non è RSA.
     *
     * @return array<string, string>|null
     */
    private function jwkFromPem(string $pem, string $kid, bool $isPrivate): ?array
    {
        if (trim($pem) === '') {
            return null;
        }

        // openssl_* emette warning su PEM malformati: qui interessa solo il valore di ritorno.
        $key = $isPrivate
            ? @openssl_pkey_get_private($pem)
            : (@openssl_pkey_get_public($pem) ?: @openssl_pkey_get_private($pem));

        if ($key === false) {
            $this->logger?->warning('Ermes SDK: cannot read key for JWKS', [
                'kid'   => $kid,
                'error' => openssl_error_string() ?: 'unreadable PEM',
            ]);

            return null;
        }

        $details = @openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            $this->logger?->warning('Ermes SDK: key is not usable as an RS256 JWK', ['kid' => $kid]);

            return null;
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n'   => $this->base64Url($details['rsa']['n']),
            'e'   => $this->base64Url($details['rsa']['e']),
        ];
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
