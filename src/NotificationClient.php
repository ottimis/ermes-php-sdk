<?php

namespace Ottimis\Ermes;

use Firebase\JWT\JWT;
use Ottimis\Ermes\Internal\HttpClient;

class NotificationClient
{
    private HttpClient $http;

    public function __construct(private readonly NotificationConfig $config)
    {
        $this->http = new HttpClient();
    }

    public function getJwks(): array
    {
        if (empty($this->config->privateKeyPem)) {
            return ['keys' => []];
        }
        $privKey = openssl_pkey_get_private($this->config->privateKeyPem);
        $details = openssl_pkey_get_details($privKey);
        $n = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
        $e = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');
        return [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $this->config->kid,
                'n'   => $n,
                'e'   => $e,
            ]],
        ];
    }

    /**
     * Creates a signed RS256 JWT for a user.
     * Claims structure is fixed by the SDK to match Ermes server expectations.
     * Returns the token string only.
     */
    public function createUserToken(string $userId, array $roles = ['operator']): string
    {
        return $this->createUserTokenWithInfo($userId, $roles)['token'];
    }

    /**
     * Creates a signed RS256 JWT for a user.
     * Returns ['token' => string, 'info' => array] where info contains all claims.
     * Use info.exp (Unix timestamp) to know when the token expires.
     */
    public function createUserTokenWithInfo(string $userId, array $roles = ['operator']): array
    {
        $now = time();
        $claims = [
            'tenant_id' => $this->config->tenantKey,
            'roles'     => $roles,
            'iss'       => $this->config->issuer,
            'aud'       => 'notification-platform',
            'sub'       => $userId,
            'iat'       => $now,
            'exp'       => $now + 3600,
        ];
        return [
            'token' => JWT::encode($claims, $this->config->privateKeyPem, 'RS256', $this->config->kid),
            'info'  => $claims,
        ];
    }

    /**
     * Sends a business event to the notification core (POST /api/v1/events).
     * Auth: HTTP Basic. Injects tenant_key, application_id, event_id automatically.
     * Returns ['success' => bool, 'core_status' => int, 'body' => array|null].
     */
    public function sendEvent(array $event): array
    {
        $payload = array_merge([
            'event_id'       => 'evt-' . (int)(microtime(true) * 1000) . '-' . bin2hex(random_bytes(3)),
            'tenant_key'     => $this->config->tenantKey,
            'application_id' => $this->config->applicationId,
        ], $event);

        $res = $this->http->post(
            $this->config->coreUrl . '/api/v1/events',
            $payload,
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode("{$this->config->apiKey}:{$this->config->apiSecret}"),
            ]
        );

        $statusCode = $res['statusCode'];
        return [
            'success'     => $statusCode === 202,
            'core_status' => $statusCode,
            'body'        => json_decode($res['body'], true),
        ];
    }

    public function getNotifications(string $userId, array $params = []): array
    {
        $url = $this->config->coreUrl . '/api/v1/notifications';
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        $res = $this->http->get($url, $this->bearerHeaders($userId));
        return [
            'success'    => $res['statusCode'] === 200,
            'statusCode' => $res['statusCode'],
            'body'       => json_decode($res['body'], true),
        ];
    }

    public function getUnreadCount(string $userId): array
    {
        $res = $this->http->get(
            $this->config->coreUrl . '/api/v1/notifications/unread-count',
            $this->bearerHeaders($userId)
        );
        return [
            'success'    => $res['statusCode'] === 200,
            'statusCode' => $res['statusCode'],
            'body'       => json_decode($res['body'], true),
        ];
    }

    public function syncNotifications(string $userId, array $params = []): array
    {
        $url = $this->config->coreUrl . '/api/v1/notifications/sync';
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        $res = $this->http->get($url, $this->bearerHeaders($userId));
        return [
            'success'    => $res['statusCode'] === 200,
            'statusCode' => $res['statusCode'],
            'body'       => json_decode($res['body'], true),
        ];
    }

    public function markAsRead(string $uuid, string $userId): array
    {
        $res = $this->http->post(
            $this->config->coreUrl . "/api/v1/notifications/{$uuid}/read",
            [],
            $this->bearerHeaders($userId)
        );
        return [
            'success'    => in_array($res['statusCode'], [200, 204]),
            'statusCode' => $res['statusCode'],
        ];
    }

    public function markBulkRead(array $uuids, string $userId): array
    {
        $res = $this->http->post(
            $this->config->coreUrl . '/api/v1/notifications/read',
            ['notification_uuids' => $uuids],
            $this->bearerHeaders($userId)
        );
        return [
            'success'    => in_array($res['statusCode'], [200, 204]),
            'statusCode' => $res['statusCode'],
        ];
    }

    public function markAllAsRead(string $userId): array
    {
        $res = $this->http->post(
            $this->config->coreUrl . '/api/v1/notifications/read-all',
            [],
            $this->bearerHeaders($userId)
        );
        return [
            'success'    => in_array($res['statusCode'], [200, 204]),
            'statusCode' => $res['statusCode'],
        ];
    }

    private function bearerHeaders(string $userId): array
    {
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->createUserToken($userId),
        ];
    }
}
