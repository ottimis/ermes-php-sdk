<?php

declare(strict_types=1);

namespace Ottimis\Ermes;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
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
 * @phpstan-type ErmesLiveResult array{success: bool, core_status: int, statusCode: int, body: array<string, mixed>|null, error: string|null, skipped: bool, published: int, failed: int, partial: bool}
 */
class NotificationClient
{
    /** Severità ammesse dal core (openapi.yaml). */
    private const SEVERITIES = ['info', 'warning', 'error', 'success'];

    /** Nome evento riservato alle notifiche persistite: i live event non possono usarlo. */
    private const RESERVED_EVENT_NAME = 'notification.new';

    private const MAX_RECIPIENTS = 500;
    private const MAX_LIVE_EVENTS = 100;

    /** Tetto del core sulle operazioni massive di inbox (read/delete). */
    private const MAX_UUIDS = 200;

    /** Margine di sicurezza sulla cache dei token: non si riusa un token che sta per scadere. */
    private const TOKEN_CACHE_SKEW_SECONDS = 60;

    private readonly HttpClientInterface $http;

    /** @var array<string, array{token: string, info: array<string, mixed>}> */
    private array $tokenCache = [];

    /** @var array<string, string>|null chiavi pubbliche di verifica, derivate una volta sola */
    private ?array $verificationKeys = null;

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
        // La derivazione vive in Jwks perche' non ha bisogno di un client: chi deve solo
        // pubblicare il proprio JWKS — cosa che va fatta PRIMA di essere registrati su Ermes,
        // quando le credenziali del produttore non esistono ancora — puo' chiamare
        // Jwks::fromPrivateKey() senza costruire un NotificationConfig completo.
        return Jwks::fromPrivateKey(
            $this->config->privateKeyPem(),
            $this->config->kid,
            $this->config->additionalPublicKeys,
            $this->logger,
        );
    }

    /**
     * Emette un JWT RS256 per l'utente, da usare per l'handshake Socket.IO e per l'inbox.
     * Torna la sola stringa del token.
     *
     * @param string[]             $roles       ruoli applicativi propagati nel claim `roles`
     * @param array<string, mixed> $extraClaims claim applicativi, non possono sovrascrivere i riservati
     * @param int|null             $ttl   durata in secondi; null usa `userTokenTtl` della config (default 1 ora).
     *                        Un token è irrevocabile fino alla scadenza: allungarlo è una scelta
     *                        da fare consapevolmente, non un default.
     */
    public function createUserToken(
        #[\SensitiveParameter]
        string $userId,
        array $roles = ['operator'],
        ?int $ttl = null,
        array $extraClaims = [],
    ): string {
        return $this->createUserTokenWithInfo($userId, $roles, $ttl, $extraClaims)['token'];
    }

    /**
     * Come createUserToken(), ma torna anche i claim: `info['exp']` dice al frontend quando
     * dovrà chiedere un token nuovo.
     *
     * @param string[]             $roles
     * @param array<string, mixed> $extraClaims
     * @param int|null             $ttl
     *
     * @return array{token: string, info: array<string, mixed>}
     */
    public function createUserTokenWithInfo(
        #[\SensitiveParameter]
        string $userId,
        array $roles = ['operator'],
        ?int $ttl = null,
        array $extraClaims = [],
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
        $cacheKey = $userId . '|' . implode(',', $roles) . '|' . $ttl
            . '|' . ($extraClaims === [] ? '' : md5(serialize($extraClaims)));
        $now      = time();

        // Le chiamate proxy dell'inbox firmano un token a ogni richiesta: senza cache si
        // paga una firma RSA per ogni lettura della stessa pagina.
        $cached = $this->tokenCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['info']['exp'] > $now + self::TOKEN_CACHE_SKEW_SECONDS) {
            return $cached;
        }

        // I claim riservati si scrivono DOPO quelli applicativi: nessun `extraClaims` puo'
        // cambiare mittente, destinatario o scadenza del token. Gli extra servono per cose
        // come l'id di sessione, con cui il backend del tenant revoca un token al logout.
        $claims = array_merge($this->filterExtraClaims($extraClaims), [
            'tenant_id' => $this->config->tenantKey,
            'roles'     => $roles,
            'iss'       => $this->config->issuer,
            'aud'       => $this->config->audience,
            'sub'       => $userId,
            'iat'       => $now,
            'exp'       => $now + $ttl,
        ]);

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
    /**
     * Verifica un token emesso da questo SDK e ne restituisce i claim.
     *
     * Serve a chi monta il proxy dell'inbox: `@ottimis/ermes-ng` usa UN SOLO token sia per
     * l'handshake Socket.IO sia per l'`Authorization` verso il backend del tenant, quindi
     * quel backend deve saper riverificare i propri token. Finora ognuno se lo riscriveva.
     *
     * Verifica contro la chiave attiva E contro `additionalPublicKeys`: durante una rotazione
     * i token firmati con la chiave uscente sono ancora validi — il core li accetta, perche'
     * sono nel JWKS — e rifiutarli qui renderebbe la rotazione indolore solo a meta'.
     *
     * Torna `null` e non lancia per qualunque motivo di rifiuto: su questa strada arrivano
     * anche i JWT di sessione dell'applicazione, e riceverne uno non e' un errore.
     *
     * @return array{sub: string, roles: string[], exp: int|null, claims: array<string, mixed>}|null
     */
    public function verifyUserToken(string $jwt): ?array
    {
        if (trim($jwt) === '') {
            return null;
        }

        $claims = null;
        foreach ($this->verificationKeys() as $kid => $key) {
            try {
                $claims = (array) JWT::decode($jwt, new Key($key, 'RS256'));
                break;
            } catch (\Throwable) {
                // Chiave sbagliata, firma non nostra, token scaduto o malformato: si prova
                // la successiva, e se finiscono il token semplicemente non e' nostro.
                continue;
            }
        }

        if ($claims === null) {
            return null;
        }
        if (($claims['aud'] ?? null) !== $this->config->audience
            || ($claims['iss'] ?? null) !== $this->config->issuer
        ) {
            return null;
        }

        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            return null;
        }

        $roles = $claims['roles'] ?? [];

        return [
            'sub'    => $sub,
            'roles'  => is_array($roles) ? array_values(array_map('strval', $roles)) : [],
            'exp'    => isset($claims['exp']) && is_numeric($claims['exp']) ? (int) $claims['exp'] : null,
            'claims' => $claims,
        ];
    }

    /**
     * Chiavi pubbliche con cui verificare, in ordine: prima l'attiva, poi quelle di rotazione.
     *
     * @return array<string, string> kid => PEM pubblico
     */
    private function verificationKeys(): array
    {
        if ($this->verificationKeys !== null) {
            return $this->verificationKeys;
        }

        $keys = [];
        $active = @openssl_pkey_get_private($this->config->privateKeyPem());
        if ($active !== false) {
            $details = @openssl_pkey_get_details($active);
            if (is_array($details) && isset($details['key'])) {
                $keys[$this->config->kid] = (string) $details['key'];
            }
        }

        foreach ($this->config->additionalPublicKeys as $kid => $pem) {
            $pem = (string) $pem;
            $key = @openssl_pkey_get_public($pem) ?: @openssl_pkey_get_private($pem);
            if ($key === false) {
                continue;
            }
            $details = @openssl_pkey_get_details($key);
            if (is_array($details) && isset($details['key'])) {
                $keys[(string) $kid] = (string) $details['key'];
            }
        }

        return $this->verificationKeys = $keys;
    }

    /**
     * @param array<string, mixed>                              $event
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesSendResult
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
    /**
     * Prepara i lotti che il core e' disposto ad accettare.
     *
     * L'SDK ha sempre saputo i due limiti — 500 destinatari per evento, 100 eventi per
     * chiamata — e si e' sempre limitato a sollevare quando li si superava, lasciando al
     * chiamante l'unica parte non ovvia: dividere. Il risultato e' che ogni consumatore ha
     * riscritto la stessa cinquantina di righe, e chi non l'ha fatto ha scoperto il limite in
     * produzione, quando la prima organizzazione ha superato i 500 membri e un lotto da 100
     * eventi e' stato rifiutato INTERO.
     *
     * L'ordine conta: prima si spezzano i destinatari (che aumenta il numero di eventi), poi
     * si raggruppano gli eventi. Farlo al contrario produce lotti ancora fuori limite.
     *
     * Duplicare un evento su piu' lotti e' sicuro perche' gli eventi live non hanno chiave di
     * idempotenza: non c'e' niente da deduplicare lato core.
     *
     * @param array<int, array<string, mixed>> $events
     *
     * @return array<int, array<int, array<string, mixed>>> lotti pronti per sendLiveEvents()
     */
    public static function planLiveBatches(array $events): array
    {
        $split = [];
        foreach (array_values($events) as $event) {
            // I duplicati si tolgono SEMPRE, non solo quando si supera il limite: il conteggio
            // che decide la divisione dev'essere lo stesso che il core vedra'.
            $recipients = array_values(array_unique((array) ($event['recipient_users'] ?? [])));
            if (count($recipients) <= self::MAX_RECIPIENTS) {
                $event['recipient_users'] = $recipients;
                $split[] = $event;
                continue;
            }
            foreach (array_chunk($recipients, self::MAX_RECIPIENTS) as $chunk) {
                $copy = $event;
                $copy['recipient_users'] = $chunk;
                $split[] = $copy;
            }
        }

        return $split === [] ? [] : array_chunk($split, self::MAX_LIVE_EVENTS);
    }

    /**
     * @param array<int, array<string, mixed>>                  $events
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesLiveResult
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

        return $this->liveRespond($res);
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
            $this->filterParams($params, self::LIST_PARAMS),
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
            $this->filterParams($params, self::SYNC_PARAMS),
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
        $this->assertUuidBatch($uuids, 'notification_uuids');

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
     * Archivia una notifica per l'utente (`archived_at`). Il core risponde 204.
     *
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     */
    public function deleteNotification(string $uuid, string $userId, array $opts = []): array
    {
        return $this->userPost(
            '/api/v1/notifications/' . rawurlencode($uuid) . '/delete',
            $userId,
            [],
            $opts
        );
    }

    /**
     * Archivia piu' notifiche in una chiamata.
     *
     * @param string[]                                          $uuids 1-200 UUID
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     *
     * @throws \InvalidArgumentException se il numero di UUID e' fuori dai limiti del core
     */
    public function deleteBulk(array $uuids, string $userId, array $opts = []): array
    {
        $this->assertUuidBatch($uuids, 'notification_uuids');

        return $this->userPost(
            '/api/v1/notifications/delete',
            $userId,
            ['notification_uuids' => array_values($uuids)],
            $opts
        );
    }

    /**
     * Riporta in inbox una notifica archiviata (`archived_at = NULL`).
     *
     * @param array{timeout_ms?: int, connect_timeout_ms?: int} $opts
     *
     * @return ErmesResult
     */
    public function restoreNotification(string $uuid, string $userId, array $opts = []): array
    {
        return $this->userPost(
            '/api/v1/notifications/' . rawurlencode($uuid) . '/restore',
            $userId,
            [],
            $opts
        );
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
    /**
     * Risposta delle rotte live, con i contatori che il core restituisce.
     *
     * Il core risponde 202 quando ha ACCETTATO il lotto, non quando l'ha pubblicato tutto:
     * `202 {published: 0, failed: 100}` e' una risposta legittima e significa che nulla e'
     * arrivato. Guardare il solo status faceva passare quel caso per successo pieno, ed e'
     * un difetto che si scopre solo leggendo i contatori — cioe' quasi mai.
     *
     * @param array{statusCode: int, body: string, error: string|null} $res
     *
     * @return ErmesLiveResult
     */
    private function liveRespond(array $res): array
    {
        $result = $this->respond($res, [200, 202]);
        $body   = $result['body'] ?? null;

        $published = is_array($body) && isset($body['published']) ? (int) $body['published'] : 0;
        $failed    = is_array($body) && isset($body['failed']) ? (int) $body['failed'] : 0;

        // `skipped_offline` NON concorre: nessun destinatario collegato e' il funzionamento
        // previsto di un evento live, non un fallimento.
        $result['published'] = $published;
        $result['failed']    = $failed;
        $result['partial']   = $failed > 0;
        $result['success']   = $result['success'] && $failed === 0;

        return $result;
    }

    /**
     * @param array{statusCode: int, body: string, error: string|null} $res
     * @param int[]                                                    $successCodes
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
     * @return ErmesLiveResult
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
            'published'   => 0,
            'failed'      => 0,
            'partial'     => false,
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
    /**
     * Parametri accettati da GET /api/v1/notifications.
     *
     * La lista rispecchia lo schema del core (`listQuerySchema`): tenerla piu' stretta non
     * protegge da niente — il core valida comunque — e fa sparire in SILENZIO i filtri che il
     * chiamante ha chiesto. E' successo con `deleted`: l'archivio delle notifiche smetteva di
     * essere consultabile senza un solo errore.
     */
    private const LIST_PARAMS = [
        'status',
        'topic',
        'application_id',
        'created_after',
        'created_before',
        'deleted',
        'page',
        'limit',
    ];

    /** Parametri accettati da GET /api/v1/notifications/sync (`syncQuerySchema` del core). */
    private const SYNC_PARAMS = [
        'after',
        'limit',
        'created_after',
        'created_before',
        'deleted',
    ];

    /**
     * Il core accetta da 1 a 200 UUID per le operazioni massive: oltre, risponde
     * `400 invalid_payload` e l'intera chiamata si perde. Il README prometteva il limite
     * senza che nessuno lo verificasse.
     *
     * @param string[] $uuids
     */
    /**
     * Claim che l'SDK scrive sempre di suo, e che un chiamante non deve poter toccare.
     *
     * Sovrascriverli significherebbe firmare un token valido per un altro tenant, un altro
     * utente o con un'altra scadenza: proprio le cose che la firma dovrebbe garantire.
     */
    private const RESERVED_CLAIMS = ['tenant_id', 'roles', 'iss', 'aud', 'sub', 'iat', 'exp'];

    /**
     * @param array<string, mixed> $extraClaims
     *
     * @return array<string, mixed>
     */
    private function filterExtraClaims(array $extraClaims): array
    {
        if ($extraClaims === []) {
            return [];
        }

        $rejected = array_intersect(array_keys($extraClaims), self::RESERVED_CLAIMS);
        if ($rejected !== []) {
            throw new \InvalidArgumentException(
                'Ermes SDK: extraClaims cannot override reserved claims: ' . implode(', ', $rejected) . '.'
            );
        }

        return $extraClaims;
    }

    /**
     * @param string[] $uuids
     */
    private function assertUuidBatch(array $uuids, string $field): void
    {
        $count = count($uuids);
        if ($count < 1 || $count > self::MAX_UUIDS) {
            throw new \InvalidArgumentException(
                "Ermes SDK: {$field} accepts between 1 and " . self::MAX_UUIDS . " UUIDs, got {$count}."
            );
        }
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

}
