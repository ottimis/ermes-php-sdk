# ermes-php-sdk

PHP SDK for the Ermes notification platform. Handles event ingestion, inbox proxy, user token generation, and JWKS exposure.

## Requirements

- PHP 8.1+
- ext-curl
- ext-openssl

## Installation

```bash
composer require ottimis/ermes-php-sdk
```

Via company VCS repository, add to your `composer.json`:

```json
{
    "require": {
        "ottimis/ermes-php-sdk": "^1.0"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://git.yourcompany.com/ottimis/ermes-php-sdk"
        }
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

### Option B — from environment variables

```php
$client = new NotificationClient(NotificationConfig::fromEnv());
```

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

*Either `NOTIFICATION_RSA_PRIVATE_KEY` or `NOTIFICATION_RSA_PRIVATE_KEY_PATH` is required.

---

## Tenant registration

Before using the SDK, your tenant must be registered on the Ermes core. This is a one-time operation done by the platform operator:

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

The response returns `credentials.api_key` and `credentials.api_secret`. **Store them immediately — they are shown only once.**

Your backend must expose `/.well-known/jwks.json` so the Ermes core can validate user JWTs. Use `getJwks()` for this:

```php
// GET /.well-known/jwks.json
$client->getJwks(); // returns the JWKS array
```

---

## Sending events

```php
$result = $client->sendEvent([
    'topic'           => 'contract.termination.completed',
    'title'           => 'Cessazione completata',
    'body'            => "La cessazione del contratto C-1234 è stata elaborata.",
    'severity'        => 'info',          // info | warning | error | success
    'entity_type'     => 'contract',      // optional
    'entity_id'       => 'C-1234',        // optional
    'recipient_users' => ['user_42'],     // array of user IDs (1–500)
    'payload'         => ['contract_id' => 'C-1234'],  // optional custom JSON
]);

// $result['success']     — true if core returned 202
// $result['core_status'] — HTTP status from core
// $result['body']        — decoded response body
```

`tenant_key`, `application_id`, and `event_id` are injected automatically by the SDK.

---

## User token (Socket.IO + inbox API)

The frontend needs a signed JWT to connect to the Ermes WebSocket and call the inbox HTTP API directly. Issue it from your backend:

```php
// Short form — token string only
$token = $client->createUserToken('user_42');

// Full form — token + claims (use info.exp to know expiry)
$result = $client->createUserTokenWithInfo('user_42');
// $result['token']      — JWT string
// $result['info']['exp'] — Unix timestamp, token valid for 1 hour
// $result['info']['tenant_id'], ['iss'], ['aud'], ['sub'], ['iat']

// Custom roles (default: ['operator'])
$token = $client->createUserToken('user_42', ['operator', 'admin']);
```

The JWT claims structure is fixed by the SDK to match Ermes server expectations. The private key is encapsulated — the only way to obtain a valid signed token is through these methods.

**Frontend Socket.IO connection:**
```js
const socket = io('wss://ermes.yourcompany.com', {
    auth: { token: '<jwt from createUserToken>' }
});
// or via query string: ?token=<jwt>
```

---

## Inbox proxy methods

These methods act as a proxy: your backend generates a user-scoped JWT internally and forwards the request to the Ermes core. The frontend never calls the Ermes core HTTP API directly.

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

### Mark as read

```php
// Single
$client->markAsRead('1b2c3d4e-5f60-4718-9abc-def012345678', 'user_42');

// Bulk (up to 200 UUIDs)
$client->markBulkRead([
    '1b2c3d4e-5f60-4718-9abc-def012345678',
    '2c3d4e5f-6071-4829-abcd-ef0123456789',
], 'user_42');

// All unread
$client->markAllAsRead('user_42');
```

All mark methods return `['success' => bool, 'statusCode' => int]`.

---

## InboxItem shape

All notification items (from `getNotifications`, `syncNotifications`, Socket.IO event `notification.new`) share the same shape:

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

## Error handling

All methods return an array. Check `success` before using `body`:

```php
$result = $client->sendEvent([...]);
if (!$result['success']) {
    // $result['core_status'] — HTTP status (400, 401, 429, 500, ...)
    // $result['body']['error'] — error code string
    // $result['body']['details'] — validation details (on 400)
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
