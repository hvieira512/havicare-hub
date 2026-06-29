<?php

namespace Hub\Dashboard;

use Predis\ClientInterface;

final class ApiTokenStore
{
    public function __construct(
        private ClientInterface $redis,
        private string $prefix = 'hub:api-tokens',
    ) {
        $this->prefix = trim($this->prefix, ':');
    }

    public function issue(string $username, string $role, int $ttlSeconds, ?int $userId = null, int|string|null $licenseId = null): array
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $issuedAt = time();
        $expiresAt = $issuedAt + $ttlSeconds;
        $token = bin2hex(random_bytes(32));
        $payload = [
            'userId' => $userId,
            'username' => $username,
            'role' => $role,
            'licenseId' => $licenseId,
            'issuedAt' => gmdate('Y-m-d\\TH:i:s\\Z', $issuedAt),
            'expiresAt' => gmdate('Y-m-d\\TH:i:s\\Z', $expiresAt),
        ];

        $this->redis->setex($this->key($token), $ttlSeconds, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'role' => $role,
            'license_id' => $licenseId,
            'expires_in' => $ttlSeconds,
            'expires_at' => $payload['expiresAt'],
        ];
    }

    public function context(string $token): ?ApiAuthContext
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $raw = $this->redis->get($this->key($token));
        if (!is_string($raw)) {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }

        $username = trim((string)($payload['username'] ?? ''));
        $role = trim((string)($payload['role'] ?? ''));
        if ($username === '' || $role === '') {
            return null;
        }

        $userId = isset($payload['userId']) && $payload['userId'] !== null ? (int)$payload['userId'] : null;
        $licenseId = isset($payload['licenseId']) && $payload['licenseId'] !== null
            ? (int)$payload['licenseId']
            : null;

        return new ApiAuthContext($userId, $username, $role, $licenseId);
    }

    public function validate(string $token): bool
    {
        return $this->context($token) instanceof ApiAuthContext;
    }

    private function key(string $token): string
    {
        return "{$this->prefix}:{$token}";
    }
}
