<?php

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Auth\LoginThrottle;
use Hub\Api\Http\ApiError;
use Hub\Api\Repository\ApiDataAccess;
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

        // O teto vem antes da verificação, e é isso que o torna útil: o custo que ele existe
        // para travar -- 146 ms de loop bloqueado -- é pago por qualquer tentativa, acerte ou
        // falhe. Verificado depois, já não travava nada.
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

    private function refresh(string $refreshToken, string $requestId = ''): array
    {
        // Consome o token de renovação primeiro -- é de uso único -- e só depois revalida. Um
        // utilizador desactivado, apagado ou com o papel mudado não renova, e o token gasta-se
        // na mesma, por isso uma renovação recusada não fica a poder repetir-se.
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
     * A identidade com que se renova sai de `api_users`, relida agora, e não do que o token
     * guardou: um utilizador desactivado ou com o papel mudado deixa de renovar. Sem `userId`
     * não há linha a reler, e o token de inquilino segue com o contexto que trazia.
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
     * Emite um token de inquilino a pedido de um administrador.
     *
     * Serve para a plataforma de um cliente entregar credenciais do hub às aplicações dela sem
     * guardar uma password por inquilino. O que sai é sempre mais fraco do que aquilo com que
     * se pediu, e fechar a rota a não-administradores é do `RouteAccessPolicy`. Não há teto de
     * tentativas porque não há password para verificar.
     */
    public function licenseToken(array $payload, string $requestId = ''): array
    {
        $company = DeviceMetadata::normalizeCompany(trim((string)($payload['company'] ?? '')));
        $licenseId = DeviceMetadata::normalizeLicenseId((string)($payload['licenseId'] ?? ''));

        if ($company === 'null' || $licenseId <= 0) {
            return ApiError::invalidRequest('company and licenseId are required')->toArray();
        }

        // As duas metades respondem separadamente: uma empresa conhecida sem aquela licença é
        // o engano provável -- o inquilino ainda não foi criado no hub -- e dizê-lo poupa a
        // quem integra a adivinhação.
        $companyRow = $this->db->companies->findByName($company);
        if ($companyRow === null) {
            return ApiError::companyNotFound()->toArray();
        }

        $license = $this->db->licenses->findByCompanyAndLicense((int)$companyRow['id'], $licenseId);
        if ($license === null) {
            return ApiError::licenseNotFound()->toArray();
        }

        // O nome sai do par e não de quem emitiu. O tecto de streams simultâneos conta por
        // `username`, e com o nome do administrador os inquilinos todos partilhavam um balde
        // -- o primeiro a abrir cem ligações fechava a porta aos outros.
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
     * Um hash de referência, para uma tentativa contra uma conta que não existe custar o mesmo
     * que uma contra uma que existe.
     *
     * É gerado e não escrito à mão de propósito: acompanha o custo que o `PASSWORD_DEFAULT`
     * tiver na altura, e um custo menor aqui reabria o oráculo.
     */
    private ?string $referenceHash = null;

    private function referenceHash(): string
    {
        return $this->referenceHash ??= password_hash('', PASSWORD_DEFAULT);
    }

    private function identityForCredentials(string $username, string $password): ?array
    {
        $user = $this->db->apiUsers->findByUsername($username);
        $storedHash = is_array($user) ? (string)($user['password_hash'] ?? '') : '';

        // A verificação corre **sempre**, e sempre uma vez, aconteça o que acontecer a seguir.
        // Um curto-circuito antes do `password_verify` responde em 0,5 ms em vez de ~175 ms, e
        // essa diferença diz a quem perguntar que contas existem e quais estão saudáveis.
        $passwordMatches = password_verify($password, $storedHash !== '' ? $storedHash : $this->referenceHash());

        if (!is_array($user) || $storedHash === '' || !$passwordMatches) {
            return null;
        }

        return $this->identityFromUserRow($user);
    }

    /**
     * A linha de `api_users` transformada na identidade que emite um token, ou `null` se a conta
     * não serve: desactivada, com um papel que não existe, ou um inquilino sem licença completa.
     *
     * É o mesmo molde no login e na renovação -- as duas têm de aceitar exactamente as mesmas
     * contas, e uma regra escrita duas vezes divergiria.
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
