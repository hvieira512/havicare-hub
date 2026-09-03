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

    /** Segundos que um bilhete de stream vale. Chega para abrir a ligação e mais nada. */
    private const STREAM_TICKET_TTL_SECONDS = 30;

    /**
     * Um bilhete para o `EventSource`, que não deixa pôr cabeçalhos e obriga a credencial a
     * viajar no URL. Ver a nota no `ApiTokenStore::issueStreamTicket()`.
     */
    public function streamTicket(?ApiAuthContext $auth): array
    {
        // Defensivo: esta rota exige autenticação, e por isso o kernel já rejeitou quem não
        // a tem antes de chegar aqui. Fica na mesma, para nunca se emitir um bilhete sem
        // saber a quem.
        if ($auth === null) {
            return ApiError::forbidden()->toArray();
        }

        return ['data' => $this->tokens->issueStreamTicket($auth, self::STREAM_TICKET_TTL_SECONDS)];
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
        $token = $this->tokens->refreshAccessToken($refreshToken, $this->tokenTtlSeconds, $this->refreshTokenTtlSeconds);
        if ($token === null) {
            Logger::channel('api')->warning('API token refresh rejected', [
                'request_id' => $requestId,
                'error_code' => 'invalid_refresh_token',
            ]);

            return ApiError::invalidRefreshToken()->toArray();
        }

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
     * Um hash de referência, para uma tentativa contra uma conta que não existe custar o mesmo
     * que uma contra uma que existe.
     *
     * É gerado e não escrito à mão de propósito: assim acompanha o custo que o
     * `PASSWORD_DEFAULT` tiver na altura, em vez de ficar preso ao que era no dia em que a
     * linha foi escrita -- e um custo menor aqui reabria o oráculo.
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
        //
        // Medido antes: uma tentativa contra uma conta real custava ~175 ms de bcrypt e uma
        // contra uma conta inexistente 0,5 ms, porque o `&&` fazia curto-circuito antes do
        // `password_verify` quando não havia hash. Essa diferença de ~350× dizia a quem
        // perguntasse que contas existem, sem ser preciso acertar em nenhuma password. E o
        // curto-circuito ia mais longe do que isso: uma conta desactivada, ou um
        // `license_client` mal ligado, também respondiam depressa -- e portanto também se
        // distinguiam de uma conta saudável pelo relógio.
        $passwordMatches = password_verify($password, $storedHash !== '' ? $storedHash : $this->referenceHash());

        if (is_array($user)) {
            $enabled = ((int)($user['enabled'] ?? 0)) === 1;
            $hash = $storedHash;
            $role = trim((string)($user['role'] ?? ''));
            $licenseId = $role === ApiAuthContext::ROLE_LICENSE_CLIENT
                ? DeviceMetadata::normalizeLicenseId((string)($user['license_id'] ?? ''))
                : null;
            $licenseRefId = $role === ApiAuthContext::ROLE_LICENSE_CLIENT ? (int)($user['license_ref_id'] ?? 0) : null;
            $companyId = $role === ApiAuthContext::ROLE_LICENSE_CLIENT ? (int)($user['company_id'] ?? 0) : null;
            $company = $role === ApiAuthContext::ROLE_LICENSE_CLIENT ? trim((string)($user['company_name'] ?? '')) : null;

            $tenantIsValid = $role !== ApiAuthContext::ROLE_LICENSE_CLIENT
                || ($licenseId > 0 && $licenseRefId > 0 && $companyId > 0 && $company !== '');
            if ($enabled && $tenantIsValid && $hash !== '' && $passwordMatches && in_array($role, ApiAuthContext::roles(), true)) {
                return [
                    'userId' => (int)($user['id'] ?? 0),
                    'username' => (string)($user['username'] ?? $username),
                    'role' => $role,
                    'licenseId' => $licenseId,
                    'licenseRefId' => $licenseRefId,
                    'companyId' => $companyId,
                    'company' => $company,
                ];
            }
        }

        return null;
    }
}
