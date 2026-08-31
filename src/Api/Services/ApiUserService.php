<?php

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\CollectionResponder;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Request\ApiUserWriteRequest;
use Hub\Api\Request\RequestBinder;
use Hub\Domain\DeviceMetadata;

class ApiUserService
{
    private const DEFAULT_COLLECTION_LIMIT = 20;

    /**
     * Os campos cuja recusa tem código próprio na API, e sempre teve.
     *
     * A validação por constraints quer devolver `invalid_request` para tudo, e isso apagava
     * o `invalid_role` que os clientes desta API distinguem desde sempre. Enquanto falha um
     * campo só -- que era a única coisa que o serviço antigo sabia devolver --, o código é o
     * de antes.
     *
     * @var array<string, string>
     */
    private const ERROR_CODE_BY_FIELD = ['role' => 'invalid_role'];

    private CollectionQuery $query;
    private CollectionResponder $collection;
    private RequestBinder $binder;

    public function __construct(
        private ApiDataAccess $db,
        ?CollectionQuery $query = null,
        ?CollectionResponder $collection = null,
        ?RequestBinder $binder = null,
    ) {
        $this->query = $query ?? new CollectionQuery();
        $this->collection = $collection ?? new CollectionResponder();
        $this->binder = $binder ?? new RequestBinder();
    }

    public function list(string $query = ''): array
    {
        $params = $this->query->params($query);
        $page = $this->query->page($params);
        $limit = $this->query->limit($params, self::DEFAULT_COLLECTION_LIMIT);
        $filters = [
            'role' => $this->query->filter($params, 'role'),
            'enabled' => $this->query->filter($params, 'enabled'),
        ];
        $users = array_values(array_filter($this->db->apiUsers->all(), static function (array $user) use ($filters): bool {
            $enabled = ((int)($user['enabled'] ?? 0)) === 1 ? 'true' : 'false';
            $role = (string)($user['role'] ?? '');

            return (($filters['role'] ?? null) === null || $role === $filters['role'])
                && (($filters['enabled'] ?? null) === null || $enabled === $filters['enabled']);
        }));

        return $this->collection->respond($users, $page, $limit, $filters, [
            'role' => ApiAuthContext::roles(),
            'enabled' => ['true', 'false'],
        ]);
    }

    public function create(array $payload): array
    {
        $request = $this->binder->bind(
            $payload,
            ApiUserWriteRequest::class,
            [ApiUserWriteRequest::GROUP_CREATE],
            codeByField: self::ERROR_CODE_BY_FIELD,
        );
        if (is_array($request)) {
            return $request;
        }

        $license = $this->resolveLicense($request);
        if (isset($license['error'])) {
            return $license;
        }

        if ($this->db->apiUsers->findByUsername($request->username) !== null) {
            return ApiError::userExists()->toArray();
        }

        $id = $this->db->apiUsers->create(
            $request->username,
            password_hash($request->password, PASSWORD_DEFAULT),
            $request->role,
            (int)$license['licenseId'],
            $request->enabled,
            $license['licenseRefId'],
        );

        return ['status' => 'ok', 'id' => $id];
    }

    public function update(int $id, array $payload): array
    {
        if ($this->db->apiUsers->findById($id) === null) {
            return ApiError::userNotFound()->toArray();
        }

        $request = $this->binder->bind(
            $payload,
            ApiUserWriteRequest::class,
            codeByField: self::ERROR_CODE_BY_FIELD,
        );
        if (is_array($request)) {
            return $request;
        }

        $license = $this->resolveLicense($request);
        if (isset($license['error'])) {
            return $license;
        }

        if ($this->db->apiUsers->usernameExistsForDifferentId($id, $request->username)) {
            return ApiError::userExists()->toArray();
        }

        $passwordHash = $request->password !== ''
            ? password_hash($request->password, PASSWORD_DEFAULT)
            : null;
        $this->db->apiUsers->update(
            $id,
            $request->username,
            $request->role,
            (int)$license['licenseId'],
            $request->enabled,
            $passwordHash,
            $license['licenseRefId'],
        );

        return ['status' => 'ok', 'id' => $id];
    }

    public function delete(int $id): array
    {
        if ($this->db->apiUsers->findById($id) === null) {
            return ApiError::userNotFound()->toArray();
        }

        $this->db->apiUsers->delete($id);

        return ['status' => 'ok'];
    }

    /**
     * A licença a que o utilizador fica preso, resolvida contra a base.
     *
     * Isto não desceu para o `ApiUserWriteRequest` de propósito: o que sobra do `fields()`
     * antigo depois de a forma do pedido sair dele não é uma regra sobre o corpo, é uma
     * pergunta à base de dados. Uma constraint que consulta o MySQL para saber se um corpo é
     * válido esconde uma consulta num sítio onde ninguém a procura.
     *
     * Um `hub_admin` não tem licença nenhuma, e por isso é aqui que os dois campos voltam a
     * zero em vez de ficarem com o que o pedido trouxesse.
     *
     * @return array{licenseId: int, licenseRefId: int|null}|array{error: array<string, mixed>}
     */
    private function resolveLicense(ApiUserWriteRequest $request): array
    {
        if (!$request->isLicenseClient()) {
            return ['licenseId' => 0, 'licenseRefId' => null];
        }

        $licenseRefId = $request->licenseRefId ?? 0;
        $license = $licenseRefId > 0
            ? $this->db->licenses->findById($licenseRefId)
            : ($request->companyId > 0 && $request->licenseId > 0
                ? $this->db->licenses->findByCompanyAndLicense($request->companyId, $request->licenseId)
                : null);
        if ($license === null) {
            return ApiError::invalidLicense()->toArray();
        }

        return [
            'licenseId' => DeviceMetadata::normalizeLicenseId((string)$license['license_id']),
            'licenseRefId' => (int)$license['id'],
        ];
    }
}
