<?php

declare(strict_types=1);

namespace Hub\Api\Auth;

use Predis\ClientInterface;

final class ApiTokenStore
{
    private const PREFIX = 'hub:api-tokens';

    /** As sessões de cada utilizador; a mais antiga sai quando entra uma além destas. */
    private const SESSIONS_PER_USER = 20;

    private const TOKEN_TYPE_ACCESS = 'access';
    private const TOKEN_TYPE_REFRESH = 'refresh';

    public function __construct(
        private ClientInterface $redis,
    ) {
    }

    /**
     * O par de acesso e renovação para uma identidade.
     *
     * @return array<string, mixed>
     */
    public function issueTokenPair(ApiAuthContext $identity, int $accessTtlSeconds, int $refreshTtlSeconds): array
    {
        [$accessToken, $access] = $this->issueStoredToken($identity, $accessTtlSeconds, self::TOKEN_TYPE_ACCESS);
        [$refreshToken, $refresh] = $this->issueStoredToken($identity, $refreshTtlSeconds, self::TOKEN_TYPE_REFRESH);
        $this->trackSession($identity->username, $accessToken, $refreshToken, max($accessTtlSeconds, $refreshTtlSeconds));

        // hitcare: os quatro nomes em snake_case são os que o HubApiRepository do hitCare lê; passam a
        // camelCase quando ele mudar.
        return [
            'access_token' => $accessToken,
            'tokenType' => 'Bearer',
            'username' => $identity->username,
            'role' => $identity->role,
            'licenseId' => $identity->licenseId,
            'licenseRefId' => $identity->licenseRefId,
            'companyId' => $identity->companyId,
            'company' => $identity->company,
            'expires_in' => max(1, $accessTtlSeconds),
            'expiresAt' => $access['expiresAt'],
            'refresh_token' => $refreshToken,
            'refresh_expires_in' => max(1, $refreshTtlSeconds),
            'refreshExpiresAt' => $refresh['expiresAt'],
        ];
    }

    /**
     * Valida um token de renovação e consome-o, porque é de uso único. Reler o utilizador e
     * reemitir é de quem chama.
     */
    public function consumeRefreshToken(string $refreshToken): ?ApiAuthContext
    {
        $payload = $this->payload($refreshToken);
        if (!is_array($payload) || ($payload['tokenType'] ?? null) !== self::TOKEN_TYPE_REFRESH) {
            return null;
        }

        $context = $this->contextFromPayload($payload);
        if (!$context instanceof ApiAuthContext) {
            return null;
        }

        $this->revoke($refreshToken);

        return $context;
    }

    /** Apaga um token, seja de acesso ou de renovação. Um token que não existe não é erro. */
    public function revoke(string $token): void
    {
        $token = trim($token);
        if ($token !== '') {
            $this->redis->del($this->key($token));
        }
    }

    /** Fecha todas as sessões de um utilizador: o acesso que já tinha não espera pelo fim do prazo. */
    public function revokeUser(string $username): void
    {
        $key = self::PREFIX . '-by-user:' . $username;
        foreach (array_map(strval(...), $this->redis->zrangebyscore($key, '-inf', '+inf')) as $pair) {
            [$accessToken, $refreshToken] = explode(':', $pair) + ['', ''];
            $this->revoke($accessToken);
            $this->revoke($refreshToken);
        }
        $this->redis->del([$key]);
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

    /** Guarda o par na lista do utilizador e apaga os pares que passem do limite. */
    private function trackSession(string $username, string $accessToken, string $refreshToken, int $ttlSeconds): void
    {
        $key = self::PREFIX . '-by-user:' . $username;
        $this->redis->zadd($key, ["{$accessToken}:{$refreshToken}" => (int)(microtime(true) * 1_000_000)]);
        $this->redis->expire($key, max(1, $ttlSeconds));

        $sessions = array_map(strval(...), $this->redis->zrangebyscore($key, '-inf', '+inf'));
        $evicted = array_slice($sessions, 0, max(0, count($sessions) - self::SESSIONS_PER_USER));
        if ($evicted === []) {
            return;
        }
        $this->redis->del(array_map(fn (string $pair): string => $this->key(explode(':', $pair)[0]), $evicted));
        $this->redis->del(array_map(fn (string $pair): string => $this->key(explode(':', $pair)[1] ?? ''), $evicted));
        $this->redis->zrem($key, ...array_values($evicted));
    }

    /** @return array{string, array<string, mixed>} */
    private function issueStoredToken(ApiAuthContext $identity, int $ttlSeconds, string $tokenType): array
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $issuedAt = time();
        $token = bin2hex(random_bytes(32));
        $payload = [
            'tokenType' => $tokenType,
            'userId' => $identity->userId,
            'username' => $identity->username,
            'role' => $identity->role,
            'licenseId' => $identity->licenseId,
            'licenseRefId' => $identity->licenseRefId,
            'companyId' => $identity->companyId,
            'company' => $identity->company,
            'issuedAt' => gmdate('Y-m-d\\TH:i:s\\Z', $issuedAt),
            'expiresAt' => gmdate('Y-m-d\\TH:i:s\\Z', $issuedAt + $ttlSeconds),
        ];

        $this->redis->setex($this->key($token), $ttlSeconds, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [$token, $payload];
    }

    /** @return array<string, mixed>|null */
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

    /** @param array<string, mixed> $payload */
    private function contextFromPayload(array $payload): ?ApiAuthContext
    {
        $username = trim((string)($payload['username'] ?? ''));
        $role = trim((string)($payload['role'] ?? ''));
        if ($username === '' || $role === '') {
            return null;
        }

        $userId = isset($payload['userId']) ? (int)$payload['userId'] : null;
        $licenseId = isset($payload['licenseId'])
            ? (int)$payload['licenseId']
            : null;
        $licenseRefId = isset($payload['licenseRefId'])
            ? (int)$payload['licenseRefId']
            : null;
        $companyId = isset($payload['companyId'])
            ? (int)$payload['companyId']
            : null;
        $company = isset($payload['company']) && trim((string)$payload['company']) !== ''
            ? trim((string)$payload['company'])
            : null;

        return new ApiAuthContext($userId, $username, $role, $licenseId, $licenseRefId, $companyId, $company);
    }

    private function key(string $token): string
    {
        return self::PREFIX . ':' . $token;
    }
}
