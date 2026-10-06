<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Auth\LoginThrottle;
use Hub\Api\Http\ApiError;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Domain\DeviceMetadata;
use Hub\Log\Logger;

class AuthService
{
    public function __construct(
        private ApiTokenStore $tokens,
        private ApiDataAccess $db,
        private int $tokenTtlSeconds = 3600,
        private int $refreshTokenTtlSeconds = 2592000,
        private ?LoginThrottle $throttle = null,
    ) {
    }


    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function login(array $payload, string $requestId = '', string $remoteAddress = ''): array
    {
        $refreshToken = trim((string)($payload['refresh_token'] ?? ''));
        if ($refreshToken !== '') {
            // A renovação não passa pelo teto: não chama `password_verify`, e travá-la punia o
            // cliente que se porta bem -- o que guarda o par e renova em vez de reautenticar.
            return $this->refresh($refreshToken, $requestId);
        }

        $username = trim((string)($payload['username'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        if ($username === '' || $password === '') {
            Logger::channel('api')->warning('API login rejected', [
                'request_id' => $requestId,
                'username' => $username,
                'error_code' => 'invalid_request',
                'reason' => 'missing_credentials',
            ]);
            return ApiError::invalidRequest('username and password are required')->toArray();
        }

        // O teto vem antes da verificação: o custo que ele trava, 146 ms de loop bloqueado, paga-se
        // acerte ou falhe.
        if ($this->throttle !== null && !$this->throttle->allows($remoteAddress, $username)) {
            Logger::channel('api')->warning('API login throttled', [
                'request_id' => $requestId,
                'username' => $username,
                'error_code' => 'too_many_attempts',
            ]);
            return ApiError::tooManyAttempts()->toArray();
        }

        $identity = $this->identityForCredentials($username, $password);
        if ($identity === null) {
            Logger::channel('api')->warning('API login rejected', [
                'request_id' => $requestId,
                'username' => $username,
                'error_code' => 'invalid_credentials',
            ]);
            return ApiError::invalidCredentials()->toArray();
        }

        Logger::channel('api')->info('API login accepted', [
            'request_id' => $requestId,
            'username' => (string)$identity['username'],
            'role' => (string)$identity['role'],
            'license_id' => $identity['licenseId'],
            'auth_source' => 'db_user',
        ]);

        return [
            'status' => 'ok',
            'token' => $this->tokens->issueTokenPair(
                (string)$identity['username'],
                (string)$identity['role'],
                $this->tokenTtlSeconds,
                $this->refreshTokenTtlSeconds,
                $identity['userId'],
                $identity['licenseId'],
                $identity['licenseRefId'],
                $identity['companyId'],
                $identity['company'],
            ),
        ];
    }

    /**
     * Fecha a sessão: as duas credenciais deixam de valer já. Revogar o token de acesso é o que
     * distingue isto de apagar o cookie.
     */
    public function logout(string $refreshToken, string $accessToken, string $requestId = ''): void
    {
        $context = $accessToken !== '' ? $this->tokens->context($accessToken) : null;
        $this->tokens->revoke($refreshToken);
        $this->tokens->revoke($accessToken);

        Logger::channel('api')->info('API session closed', [
            'request_id' => $requestId,
            'username' => $context?->username ?? '',
        ]);
    }

    /** @return array<string, mixed> */
    private function refresh(string $refreshToken, string $requestId = ''): array
    {
        // Consome o token primeiro, por ser de uso único, e só depois revalida: uma renovação recusada
        // não se pode repetir.
        $context = $this->tokens->consumeRefreshToken($refreshToken);
        $identity = $context !== null ? $this->identityForRefresh($context) : null;
        if ($identity === null) {
            Logger::channel('api')->warning('API token refresh rejected', [
                'request_id' => $requestId,
                'error_code' => 'invalid_refresh_token',
            ]);

            return ApiError::invalidRefreshToken()->toArray();
        }

        $token = $this->tokens->issueTokenPair(
            (string)$identity['username'],
            (string)$identity['role'],
            $this->tokenTtlSeconds,
            $this->refreshTokenTtlSeconds,
            $identity['userId'],
            $identity['licenseId'],
            $identity['licenseRefId'],
            $identity['companyId'],
            $identity['company'],
        );

        Logger::channel('api')->info('API token refreshed', [
            'request_id' => $requestId,
            'role' => (string)($token['role'] ?? ''),
            'license_id' => $token['license_id'] ?? null,
        ]);

        return [
            'status' => 'ok',
            'token' => $token,
        ];
    }

    /**
     * A identidade relê-se de `api_users`, e não do token: um utilizador desactivado ou com o papel
     * mudado deixa de renovar. Sem `userId`, segue o contexto que o token trazia.
     *
     * @return array<string, mixed>|null
     */
    private function identityForRefresh(ApiAuthContext $context): ?array
    {
        if ($context->userId === null || $context->userId <= 0) {
            return [
                'userId' => null,
                'username' => $context->username,
                'role' => $context->role,
                'licenseId' => $context->licenseId,
                'licenseRefId' => $context->licenseRefId,
                'companyId' => $context->companyId,
                'company' => $context->company,
            ];
        }

        $user = $this->db->apiUsers->findById($context->userId);
        if (!is_array($user)) {
            return null;
        }

        $identity = $this->identityFromUserRow($user);
        // O papel a mudar obriga a reautenticar: um token não muda de privilégios por baixo.
        if ($identity === null || $identity['role'] !== $context->role) {
            return null;
        }

        return $identity;
    }

    /**
     * Emite um token de inquilino a pedido de um administrador, para a plataforma de um cliente
     * entregar credenciais às aplicações dela. Sai sempre mais fraco do que quem o pediu.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function licenseToken(array $payload, string $requestId = ''): array
    {
        $company = DeviceMetadata::normalizeCompany(trim((string)($payload['company'] ?? '')));
        $licenseId = DeviceMetadata::normalizeLicenseId((string)($payload['licenseId'] ?? ''));

        if ($company === 'null' || $licenseId <= 0) {
            return ApiError::invalidRequest('company and licenseId are required')->toArray();
        }

        // As duas metades respondem separadamente: uma empresa conhecida sem aquela licença é o
        // engano provável.
        $companyRow = $this->db->companies->findByName($company);
        if ($companyRow === null) {
            return ApiError::companyNotFound()->toArray();
        }

        $license = $this->db->licenses->findByCompanyAndLicense((int)$companyRow['id'], $licenseId);
        if ($license === null) {
            return ApiError::licenseNotFound()->toArray();
        }

        // O nome sai do par e não de quem emitiu: o teto de streams conta por `username`, e cada
        // inquilino tem de ter o seu.
        $username = $company . '/' . $licenseId;

        Logger::channel('api')->info('API license token issued', [
            'request_id' => $requestId,
            'username' => $username,
            'role' => ApiAuthContext::ROLE_LICENSE_CLIENT,
            'license_id' => $licenseId,
        ]);

        return [
            'status' => 'ok',
            'token' => $this->tokens->issueTokenPair(
                $username,
                ApiAuthContext::ROLE_LICENSE_CLIENT,
                $this->tokenTtlSeconds,
                $this->refreshTokenTtlSeconds,
                // Sem `userId`: não há linha em `api_users` por trás deste token, e inventar
                // uma referência fazia-o parecer uma conta que ninguém pode desactivar.
                null,
                $licenseId,
                (int)$license['id'],
                (int)$companyRow['id'],
                $company,
            ),
        ];
    }

    /**
     * Um hash de referência, para uma conta que não existe custar o mesmo que uma que existe. É
     * gerado para acompanhar o custo do `PASSWORD_DEFAULT`.
     */
    private ?string $referenceHash = null;

    private function referenceHash(): string
    {
        return $this->referenceHash ??= password_hash('', PASSWORD_DEFAULT);
    }

    /** @return array<string, mixed>|null */
    private function identityForCredentials(string $username, string $password): ?array
    {
        $user = $this->db->apiUsers->findByUsername($username);
        $storedHash = is_array($user) ? (string)($user['password_hash'] ?? '') : '';

        // A verificação corre sempre, e uma vez: um curto-circuito responde em 0,5 ms em vez de
        // ~175 ms, e diz a quem pergunta que contas existem.
        $passwordMatches = password_verify($password, $storedHash !== '' ? $storedHash : $this->referenceHash());

        if (!is_array($user) || $storedHash === '' || !$passwordMatches) {
            return null;
        }

        return $this->identityFromUserRow($user);
    }

    /**
     * A linha de `api_users` como identidade que emite um token, ou `null` se a conta não serve.
     * O mesmo molde no login e na renovação, que têm de aceitar as mesmas contas.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    private function identityFromUserRow(array $user): ?array
    {
        $enabled = ((int)($user['enabled'] ?? 0)) === 1;
        $role = trim((string)($user['role'] ?? ''));
        $licenseId = $role === ApiAuthContext::ROLE_LICENSE_CLIENT
            ? DeviceMetadata::normalizeLicenseId((string)($user['license_id'] ?? ''))
            : null;
        $licenseRefId = $role === ApiAuthContext::ROLE_LICENSE_CLIENT ? (int)($user['license_ref_id'] ?? 0) : null;
        $companyId = $role === ApiAuthContext::ROLE_LICENSE_CLIENT ? (int)($user['company_id'] ?? 0) : null;
        $company = $role === ApiAuthContext::ROLE_LICENSE_CLIENT ? trim((string)($user['company_name'] ?? '')) : null;

        $tenantIsValid = $role !== ApiAuthContext::ROLE_LICENSE_CLIENT
            || ($licenseId > 0 && $licenseRefId > 0 && $companyId > 0 && $company !== '');
        if (!$enabled || !$tenantIsValid || !in_array($role, ApiAuthContext::roles(), true)) {
            return null;
        }

        return [
            'userId' => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'role' => $role,
            'licenseId' => $licenseId,
            'licenseRefId' => $licenseRefId,
            'companyId' => $companyId,
            'company' => $company,
        ];
    }
}
