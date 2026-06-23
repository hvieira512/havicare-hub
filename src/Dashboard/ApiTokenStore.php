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

    public function issue(string $username, int $ttlSeconds): array
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $issuedAt = time();
        $expiresAt = $issuedAt + $ttlSeconds;
        $token = bin2hex(random_bytes(32));
        $payload = [
            'username' => $username,
            'issuedAt' => gmdate('Y-m-d\\TH:i:s\\Z', $issuedAt),
            'expiresAt' => gmdate('Y-m-d\\TH:i:s\\Z', $expiresAt),
        ];

        $this->redis->setex($this->key($token), $ttlSeconds, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttlSeconds,
            'expires_at' => $payload['expiresAt'],
        ];
    }

    public function validate(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        return is_string($this->redis->get($this->key($token)));
    }

    private function key(string $token): string
    {
        return "{$this->prefix}:{$token}";
    }
}
