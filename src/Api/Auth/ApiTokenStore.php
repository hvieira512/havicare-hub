<?php

namespace Hub\Api\Auth;

use Predis\ClientInterface;

final class ApiTokenStore
{
    private const TOKEN_TYPE_ACCESS = 'access';
    private const TOKEN_TYPE_REFRESH = 'refresh';

    public function __construct(
        private ClientInterface $redis,
        private string $prefix = 'hub:api-tokens',
    ) {
        $this->prefix = trim($this->prefix, ':');
    }

    public function issue(
        string $username,
        string $role,
        int $ttlSeconds,
        ?int $userId = null,
        int|string|null $licenseId = null,
        ?int $licenseRefId = null,
        ?int $companyId = null,
        ?string $company = null,
    ): array
    {
        [$token, $payload] = $this->issueStoredToken(
            $username,
            $role,
            $ttlSeconds,
            $userId,
            $licenseId,
            $licenseRefId,
            $companyId,
            $company,
            self::TOKEN_TYPE_ACCESS
        );

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'username' => $username,
            'role' => $role,
            'license_id' => $licenseId,
            'license_ref_id' => $licenseRefId,
            'company_id' => $companyId,
            'company' => $company,
            'expires_in' => $ttlSeconds,
            'expires_at' => $payload['expiresAt'],
        ];
    }

    public function issueTokenPair(
        string $username,
        string $role,
        int $accessTtlSeconds,
        int $refreshTtlSeconds,
        ?int $userId = null,
        int|string|null $licenseId = null,
        ?int $licenseRefId = null,
        ?int $companyId = null,
        ?string $company = null,
    ): array {
        $access = $this->issue($username, $role, $accessTtlSeconds, $userId, $licenseId, $licenseRefId, $companyId, $company);
        [$refreshToken, $refreshPayload] = $this->issueStoredToken(
            $username,
            $role,
            $refreshTtlSeconds,
            $userId,
            $licenseId,
            $licenseRefId,
            $companyId,
            $company,
            self::TOKEN_TYPE_REFRESH
        );

        $access['refresh_token'] = $refreshToken;
        $access['refresh_expires_in'] = max(1, $refreshTtlSeconds);
        $access['refresh_expires_at'] = $refreshPayload['expiresAt'];

        return $access;
    }

    public function refreshAccessToken(string $refreshToken, int $accessTtlSeconds, int $refreshTtlSeconds): ?array
    {
        $payload = $this->payload($refreshToken);
        if (!is_array($payload) || ($payload['tokenType'] ?? null) !== self::TOKEN_TYPE_REFRESH) {
            return null;
        }

        $context = $this->contextFromPayload($payload);
        if (!$context instanceof ApiAuthContext) {
            return null;
        }

        $this->redis->del($this->key(trim($refreshToken)));

        return $this->issueTokenPair(
            $context->username,
            $context->role,
            $accessTtlSeconds,
            $refreshTtlSeconds,
            $context->userId,
            $context->licenseId,
            $context->licenseRefId,
            $context->companyId,
            $context->company,
        );
    }

    public function context(string $token): ?ApiAuthContext
    {
        $payload = $this->payload($token);
        if (!is_array($payload)) {
            return null;
        }

        $tokenType = (string)($payload['tokenType'] ?? self::TOKEN_TYPE_ACCESS);
        if ($tokenType !== self::TOKEN_TYPE_ACCESS) {
            return null;
        }

        return $this->contextFromPayload($payload);
    }

    public function validate(string $token): bool
    {
        return $this->context($token) instanceof ApiAuthContext;
    }

    private function issueStoredToken(
        string $username,
        string $role,
        int $ttlSeconds,
        ?int $userId,
        int|string|null $licenseId,
        ?int $licenseRefId,
        ?int $companyId,
        ?string $company,
        string $tokenType
    ): array {
        $ttlSeconds = max(1, $ttlSeconds);
        $issuedAt = time();
        $expiresAt = $issuedAt + $ttlSeconds;
        $token = bin2hex(random_bytes(32));
        $payload = [
            'tokenType' => $tokenType,
            'userId' => $userId,
            'username' => $username,
            'role' => $role,
            'licenseId' => $licenseId,
            'licenseRefId' => $licenseRefId,
            'companyId' => $companyId,
            'company' => $company,
            'issuedAt' => gmdate('Y-m-d\\TH:i:s\\Z', $issuedAt),
            'expiresAt' => gmdate('Y-m-d\\TH:i:s\\Z', $expiresAt),
        ];

        $this->redis->setex($this->key($token), $ttlSeconds, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [$token, $payload];
    }

    private function payload(string $token): ?array
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

        return $payload;
    }

    private function contextFromPayload(array $payload): ?ApiAuthContext
    {
        $username = trim((string)($payload['username'] ?? ''));
        $role = trim((string)($payload['role'] ?? ''));
        if ($username === '' || $role === '') {
            return null;
        }

        $userId = isset($payload['userId']) && $payload['userId'] !== null ? (int)$payload['userId'] : null;
        $licenseId = isset($payload['licenseId']) && $payload['licenseId'] !== null
            ? (int)$payload['licenseId']
            : null;
        $licenseRefId = isset($payload['licenseRefId']) && $payload['licenseRefId'] !== null
            ? (int)$payload['licenseRefId']
            : null;
        $companyId = isset($payload['companyId']) && $payload['companyId'] !== null
            ? (int)$payload['companyId']
            : null;
        $company = isset($payload['company']) && trim((string)$payload['company']) !== ''
            ? trim((string)$payload['company'])
            : null;

        return new ApiAuthContext($userId, $username, $role, $licenseId, $licenseRefId, $companyId, $company);
    }

    private function key(string $token): string
    {
        return "{$this->prefix}:{$token}";
    }
}
