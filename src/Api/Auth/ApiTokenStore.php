<?php

namespace Hub\Api\Auth;

use Predis\ClientInterface;

final class ApiTokenStore
{
    private const TOKEN_TYPE_ACCESS = 'access';
    private const TOKEN_TYPE_REFRESH = 'refresh';
    private const TOKEN_TYPE_STREAM = 'stream';

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
    ): array {
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

    /**
     * Um bilhete de vida curta e uso único, para abrir um stream.
     *
     * O `EventSource` do browser não deixa pôr cabeçalhos, e por isso a credencial do stream
     * viaja no URL. Um URL não é um cabeçalho: fica no registo de acessos de qualquer proxy
     * pelo caminho e no histórico do browser. Enquanto o que ali ia era o token de acesso, era
     * uma credencial de uma hora, boa para toda a API, a ficar escrita nesses sítios.
     *
     * O bilhete serve para uma ligação e vale segundos. Se escapar, já não abre nada.
     *
     * @return array{ticket: string, expires_in: int, expires_at: string}
     */
    public function issueStreamTicket(ApiAuthContext $context, int $ttlSeconds): array
    {
        [$ticket, $payload] = $this->issueStoredToken(
            $context->username,
            $context->role,
            $ttlSeconds,
            $context->userId,
            $context->licenseId,
            $context->licenseRefId,
            $context->companyId,
            $context->company,
            self::TOKEN_TYPE_STREAM
        );

        return [
            'ticket' => $ticket,
            'expires_in' => $ttlSeconds,
            'expires_at' => $payload['expiresAt'],
        ];
    }

    /**
     * Resolve o bilhete e queima-o: serve uma ligação e mais nenhuma.
     *
     * A remoção vem antes de devolver o contexto de propósito -- um bilhete repetido não pode
     * abrir um segundo stream, nem que chegue no mesmo instante.
     */
    public function consumeStreamTicket(string $ticket): ?ApiAuthContext
    {
        $payload = $this->payload($ticket);
        if (!is_array($payload) || (string)($payload['tokenType'] ?? '') !== self::TOKEN_TYPE_STREAM) {
            return null;
        }

        $this->redis->del($this->key($ticket));

        return $this->contextFromPayload($payload);
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
        return "{$this->prefix}:{$token}";
    }
}
