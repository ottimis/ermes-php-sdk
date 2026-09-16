# ermes-php-sdk

PHP SDK for the Ermes notification platform. Handles event ingestion, live data events, presence,
inbox proxy, user token generation, and JWKS exposure.

## Requirements

- PHP 8.1+
- ext-curl, ext-openssl, ext-json, ext-mbstring
- Ermes core 0.2.0+ for `sendLiveEvents()` and `getPresence()`
- `firebase/php-jwt` `^6.0 || ^7.0`. Both are accepted so the SDK can live next to
  `ottimis/phplibs:^8`, but **prefer `^7.0`**: every release below 7.0.0 is covered by
  [CVE-2025-45769](https://github.com/advisories/GHSA-2x45-7fc3-mxwq) (low severity, weak encryption),
  and Composer's audit blocks them by default.

## Installation

```bash
composer require ottimis/ermes-php-sdk:^1.3
```

The package lives on GitHub, so add the repository to your `composer.json`:

```json
{
    "require": {
        "ottimis/ermes-php-sdk": "^1.3"
    },
    "repositories": [
        { "type": "vcs", "url": "git@github.com:ottimis/ermes-php-sdk.git" }
    ]
}
```

---

## Configuration

### Option A — explicit (recommended for multi-project setups)

```php
use Ottimis\Ermes\NotificationConfig;
use Ottimis\Ermes\NotificationClient;

$config = new NotificationConfig(
    coreUrl:       'https://ermes.yourcompany.com',
    tenantKey:     'myapp',
    applicationId: 'my-backoffice',
    issuer:        'https://auth.yourcompany.com',
    apiKey:        'ak_xxxxxxxxxxxxxxx',      // from POST /api/v1/admin/tenants
    apiSecret:     'as_yyyyyyyyyyyyyyy',      // from POST /api/v1/admin/tenants
    privateKeyPem: file_get_contents('/path/to/private.pem'),
    kid:           'myapp-key-1',
);

$client = new NotificationClient($config);
```

The constructor validates what it gets: an empty tenant key or a `coreUrl` that is not an absolute
http(s) URL throws `InvalidArgumentException` at boot, instead of turning into a 401 or a silent cURL
error in production.

### Option B — from environment variables

```php
$client = new NotificationClient(NotificationConfig::fromEnv());
```

`fromEnv()` throws a `RuntimeException` naming the variable that is missing.

| Env var | Required | Description |
|---|---|---|
| `NOTIFICATION_CORE_URL` | yes | Base URL of the Ermes core server |
| `NOTIFICATION_TENANT_KEY` | yes | Logical tenant identifier |
| `NOTIFICATION_APPLICATION_ID` | yes | Producer application identifier |
| `NOTIFICATION_ISSUER` | yes | `iss` claim expected in JWTs |
| `NOTIFICATION_API_KEY` | yes | Basic auth key for event ingestion |
| `NOTIFICATION_API_SECRET` | yes | Basic auth secret for event ingestion |
| `NOTIFICATION_RSA_PRIVATE_KEY` | yes* | RSA private key PEM (inline, `\n` escaped) |
| `NOTIFICATION_RSA_PRIVATE_KEY_PATH` | yes* | Path to RSA private key PEM file |
| `NOTIFICATION_KID` | no | Key ID for JWKS (default: `key-1`) |
| `NOTIFICATION_USER_TOKEN_TTL` | no | User token lifetime in seconds (default: `3600`) |
| `NOTIFICATION_ENABLED` | no | `false` turns every network call off (default: `true`) |
| `ERMES_DISABLED` | no | Kill switch, equivalent to `NOTIFICATION_ENABLED=false` |

*Either `NOTIFICATION_RSA_PRIVATE_KEY` or `NOTIFICATION_RSA_PRIVATE_KEY_PATH` is required.

### Turning Ermes off

In environments without an Ermes core, set `ERMES_DISABLED=1` (or `enabled: false`). No call leaves the
process, nothing pays a timeout, and every method returns `['success' => true, 'skipped' => true, ...]`.
Check `skipped` when you need to tell "nothing was sent" from "sent successfully". Token issuing and
`getJwks()` keep working: they are local operations.

### Secrets

`apiSecret` and `privateKeyPem` are not object properties: they are closed over, so `var_dump`,
`print_r`, `var_export` and `json_encode` of the config show `***redacted***` instead of the real
values. Read them with `$config->apiSecret()` and `$config->privateKeyPem()`.

---

## Tenant registration

Before using the SDK, your tenant must be registered on the Ermes core. This is a one-time operation done
by the platform operator:

```bash
curl -X POST https://ermes.yourcompany.com/api/v1/admin/tenants \
  -H "x-admin-secret: <admin_secret>" \
  -H "Content-Type: application/json" \
  -d '{
    "tenant_key": "myapp",
    "name": "My Application",
    "issuer": "https://auth.yourcompany.com",
    "jwks_url": "https://myapp.yourcompany.com/.well-known/jwks.json",
    "audience": "notification-platform"
  }'
```

The response returns `credentials.api_key` and `credentials.api_secret`. **Store them immediately — they
are shown only once.**

Your backend must expose `/.well-known/jwks.json` so the Ermes core can validate user JWTs. Use
`getJwks()` for this:

```php
// GET /.well-known/jwks.json
$client->getJwks(); // returns the JWKS array
```

`getJwks()` never throws. If the private key is missing or unreadable it returns `['keys' => []]` and logs
the problem through the PSR-3 logger, if you passed one: a 500 on this endpoint would stop Ermes from
validating any token of your tenant, which is worse than an empty key set.

---

## Sending events

> **Pass a deterministic `event_id`.** It is the core's idempotency key. When you omit it the SDK
> generates a random one, so **every application-level retry creates a duplicate notification**. Use an
> identifier of the domain fact — `contract-1234-terminated`, not `uniqid()`.

```php
$result = $client->sendEvent([
    'event_id'        => 'contract-C-1234-terminated',   // idempotency key
    'topic'           => 'contract.termination.completed',
    'title'           => 'Cessazione completata',
    'body'            => "La cessazione del contratto C-1234 è stata elaborata.",
    'severity'        => 'info',          // info | warning | error | success
    'entity_type'     => 'contract',      // optional
    'entity_id'       => 'C-1234',        // optional
    'recipient_users' => ['user_42'],     // array of user IDs (1–500)
    'payload'         => ['contract_id' => 'C-1234'],  // optional custom JSON
]);

// $result['success']  — true on 202 (accepted) and on 200 (already_processed: safe retry)
// $result['event_id'] — the id actually used; retry with this one
```

`tenant_key` and `application_id` are injected automatically by the SDK.

Sending the same `event_id` twice is safe: the core answers `200 already_processed` and the SDK reports it
as a success, so a retrying caller does not keep retrying.

The payload is validated locally before the request leaves: missing `topic`/`title`, `recipient_users`
outside 1–500, an unknown `severity` or an over-long field raise `InvalidArgumentException` naming the
field, instead of a `400 invalid_payload` you have to decode.

---

## Live data events (not persisted)

Requires Ermes core >= 0.2.0. Live events are delivered over Socket.IO to the recipients that are
**online at that moment** and are never stored: they do not appear in the inbox, do not count as unread
and cannot be recovered through `/sync`. They carry no `event_id`, because there is nothing to
deduplicate — a lost event is recovered by the client re-reading its own data on reconnect. It is a
doorbell, not a record.

```php
$result = $client->sendLiveEvent([
    'topic'           => 'monitor.session',
    'event_name'      => 'session.updated',   // anything but 'notification.new'
    'recipient_users' => ['user_42'],
    'payload'         => ['session_id' => 'S-9', 'state' => 'running'],
]);

// batch: 1–100 events per request
$client->sendLiveEvents([$eventA, $eventB]);

// $result['body'] — ['accepted' => 2, 'published' => 1, 'skipped_offline' => 1, 'failed' => 0]
```

`emitted_at` belongs in your `payload` if you need it: it is a fact of your domain, not of the platform.

### Presence

```php
$result = $client->getPresence();
// $result['body']['online']  — [['user_id' => 'user_42', 'focus' => [...]], ...]
// $result['body']['ttl_sec'] — how long a presence entry survives without a refresh
```

Use it to decide whether sending live events is worth it at all: nobody connected, nothing to deliver.
The `focus` payload is opaque to the platform — it is whatever your own client emitted with the `focus`
socket event.

---

## User token (Socket.IO + inbox API)

The frontend needs a signed JWT to connect to the Ermes WebSocket and call the inbox HTTP API directly.
Issue it from your backend:

```php
// Short form — token string only
$token = $client->createUserToken('user_42');

// Full form — token + claims (use info.exp to know expiry)
$result = $client->createUserTokenWithInfo('user_42');
// $result['token']       — JWT string
// $result['info']['exp'] — Unix timestamp
// $result['info']['tenant_id'], ['iss'], ['aud'], ['sub'], ['iat'], ['roles']

// Custom roles (default: ['operator'])
$token = $client->createUserToken('user_42', ['operator', 'admin']);
```

**Tokens last one hour by default.** An RS256 token federated through JWKS cannot be revoked, so a stolen
token is valid until it expires — keep that window short and re-issue at every login or refresh. Change
the default with `userTokenTtl` in the config, or ask for a longer one per call when you really need it:

```php
$token = $client->createUserToken('user_42', ['operator'], ttl: 86400);
```

The private key is encapsulated: the only way to obtain a valid signed token is through these methods.

**Frontend Socket.IO connection:**
```js
const socket = io('wss://ermes.yourcompany.com', {
    auth: { token: '<jwt from createUserToken>' }
});
// or via query string: ?token=<jwt>
```

### Rotating the signing key

Publish the old public key next to the new one for as long as the tokens signed with it can still be
alive (one hour, with the default TTL). Tokens already issued keep validating while new ones are signed
with the new key:

```php
$config = new NotificationConfig(
    // ...
    privateKeyPem:        file_get_contents('/path/to/new-private.pem'),
    kid:                  'myapp-key-2',
    additionalPublicKeys: ['myapp-key-1' => file_get_contents('/path/to/old-public.pem')],
);
```

Once the last token signed with `myapp-key-1` has expired, drop it from `additionalPublicKeys`.

---

## Inbox proxy methods

These methods act as a proxy: your backend generates a user-scoped JWT internally and forwards the request
to the Ermes core. The frontend never calls the Ermes core HTTP API directly.

### List notifications

```php
$result = $client->getNotifications('user_42', [
    'status' => 'unread',  // 'unread' | 'all' (default: 'all')
    'page'   => 1,
    'limit'  => 20,        // 1–100 (default: 20)
    'topic'  => 'contract.termination.completed',  // optional
]);
// $result['body']['items']       — array of InboxItem
// $result['body']['pagination']  — page, limit, total, nextCursor
```

Forwarded: `status`, `topic`, `application_id`, `created_after`, `created_before`, `deleted`, `page`, `limit` — every filter the core accepts. Anything else is dropped. You can hand this
method a raw query string without auditing it first.

### Unread count

```php
$result = $client->getUnreadCount('user_42');
// $result['body']['count'] — integer
```

### Sync (cursor-based, preferred for incremental updates)

```php
$result = $client->syncNotifications('user_42', [
    'after' => 'notif_1250',  // cursor from previous sync response
    'limit' => 50,            // 1–200 (default: 50)
]);
// $result['body']['items']  — new items since cursor
// $result['body']['cursor'] — new cursor for next sync, null if no more
```

Forwarded: `after`, `limit`, `created_after`, `created_before`, `deleted`.

### Mark as read

```php
// Single
$client->markAsRead('1b2c3d4e-5f60-4718-9abc-def012345678', 'user_42');

// Bulk (1-200 UUIDs; outside that range raises InvalidArgumentException)
$client->markBulkRead([
    '1b2c3d4e-5f60-4718-9abc-def012345678',
    '2c3d4e5f-6071-4829-abcd-ef0123456789',
], 'user_42');

// All unread
$client->markAllAsRead('user_42');
```

---

## InboxItem shape

All notification items (from `getNotifications`, `syncNotifications`, Socket.IO event `notification.new`)
share the same shape:

| Field | Type | Notes |
|---|---|---|
| `notification_uuid` | string (UUID) | Public identifier. Use for deduplication. |
| `topic` | string | Logical category (e.g. `contracts.approved`) |
| `title` | string | Short display title |
| `body` | string\|null | Notification body, markdown or plain text |
| `severity` | string\|null | `info` \| `warning` \| `error` \| `success` |
| `entity_type` | string\|null | Domain entity type (e.g. `contract`) |
| `entity_id` | string\|null | Domain entity identifier |
| `payload` | object\|null | Custom JSON metadata from producer |
| `read_at` | string\|null | ISO 8601. null if unread |
| `created_at` | string | ISO 8601 creation timestamp |

---

## Results and error handling

Every network method returns the same shape:

```php
[
    'success'     => bool,          // the core answered with an expected status
    'core_status' => int,           // HTTP status, 0 when the request never got an answer
    'statusCode'  => int,           // same value, kept for older callers
    'body'        => array|null,    // decoded response body
    'error'       => string|null,   // transport failure (DNS, TLS, timeout), null otherwise
    'skipped'     => bool,          // true only when the client is disabled
]
```

`error` and `core_status` answer two different questions. A `429` is an answer: `error` is `null` and the
status tells you what happened. A core that is unreachable has no status at all: `core_status` is `0` and
`error` says whether it was DNS, TLS or a timeout.

```php
$result = $client->sendEvent([...]);

if ($result['error'] !== null) {
    // never reached the core — retry later with the same event_id
    $logger->warning('ermes unreachable', ['error' => $result['error']]);
} elseif (!$result['success']) {
    // the core refused it — $result['body']['error'] says why
    $logger->error('ermes refused the event', ['status' => $result['core_status'], 'body' => $result['body']]);
}
```

Common error codes from the core:

| Code | HTTP | Meaning |
|---|---|---|
| `unauthorized` | 401 | Missing or invalid credentials |
| `invalid_token` | 401 | JWT invalid (signature, claims, expiry) |
| `invalid_payload` | 400 | Request body does not match schema |
| `invalid_recipient_user` | 400 | A user ID looks like a room name instead of a user ID |
| `tenant_mismatch` | 403 | `tenant_key` in payload does not match authenticated tenant |
| `rate_limited` | 429 | Rate limit exceeded (events: 100/min, read: 300/min per user) |
| `unknown_tenant` | 400 | Tenant key not found or inactive |

---

## Timeouts

Defaults are **2 s** for the response and **1 s** for the connection, because most of these calls sit on
the path of your own HTTP response: an unreachable core must not become a page that never loads.

```php
// per call
$client->sendEvent($event, ['timeout_ms' => 500, 'connect_timeout_ms' => 200]);

// or for every call, from the config
$config = new NotificationConfig(/* ... */, timeoutMs: 10_000, connectTimeoutMs: 2_000);
```

Raise them for batch jobs that run outside a request cycle.

---

## Testing against the SDK

`NotificationClient` takes any `Ottimis\Ermes\Http\HttpClientInterface`, so your tests never need the
network:

```php
use Ottimis\Ermes\Http\HttpClientInterface;

$http = new class implements HttpClientInterface {
    public array $sent = [];

    public function get(string $url, array $headers = [], array $opts = []): array
    {
        return ['body' => '{"count":0}', 'statusCode' => 200, 'error' => null];
    }

    public function post(string $url, array $data, array $headers = [], array $opts = []): array
    {
        $this->sent[] = $data;
        return ['body' => '{"status":"accepted"}', 'statusCode' => 202, 'error' => null];
    }
};

$client = new NotificationClient($config, $http);
```

The third constructor argument is an optional PSR-3 logger.

---

## Upgrading from 1.1

No public signature changed. Two behaviours did, and both are in
[CHANGELOG.md](CHANGELOG.md):

- **User tokens now last one hour** instead of nine years. If some flow of yours relied on a token
  surviving for days, pass an explicit `$ttl` or set `userTokenTtl` — but prefer re-issuing the token at
  login and refresh.
- **Timeouts are 2 s / 1 s** instead of 10 s and no connect limit. Batch senders should raise them
  explicitly.

If you read `$config->apiSecret` or `$config->privateKeyPem` as properties, call the methods with the same
names instead.

---

## Development

```bash
composer install
composer test    # phpunit
composer stan    # phpstan, level 6
```


## Batches the core will accept

The core caps a live request at 100 events, and each event at 500 recipients. The SDK used to
raise when you crossed either limit, which told you *that* you had a problem but left you to
solve it. `planLiveBatches()` solves it:

```php
foreach (NotificationClient::planLiveBatches($events) as $batch) {
    $result = $client->sendLiveEvents($batch);
    if ($result['partial']) {
        // The core accepted the batch but could not publish all of it.
        $log->warning('live events partially published', ['failed' => $result['failed']]);
    }
}
```

Order matters and the helper gets it right: recipients are split first — which *increases* the
number of events — and only then are events grouped.

`$result['success']` is false when `failed > 0`. A `202` means the core accepted the batch, not
that it published all of it: `202 {published: 0, failed: 100}` means nothing arrived.
`skipped_offline` does not count — nobody being connected is how live events are meant to work.

## Publishing your JWKS before you are registered

A tenant has to publish its JWKS *before* Ermes can verify any of its tokens — that is, before
it has producer credentials. `Jwks` does that without a client:

```php
use Ottimis\Ermes\Jwks;

$jwks = Jwks::fromPrivateKey(
    $privateKeyPem,
    'key-1',
    Jwks::publicKeysFromDirectory('/etc/ermes/keys'), // rotation, optional
);
```

`publicKeysFromDirectory()` reads `*.pem` and uses each filename as the `kid`. During a
rotation, keep the outgoing key there: the core still accepts tokens signed with it, and so
does `verifyUserToken()`.

## Verifying a token you issued

```php
$claims = $client->verifyUserToken($jwt);   // null when it is not ours, expired or malformed
if ($claims !== null) {
    $userId = $claims['sub'];
    $sessionId = $claims['claims']['sid'] ?? null;
}
```

Application claims go in at issue time, and cannot overwrite the reserved ones:

```php
$token = $client->createUserToken($userId, ['operator'], null, ['sid' => $sessionId]);
```
