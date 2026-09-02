<?php

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\ApiUserColumns;
use Hub\Api\Http\CollectionPresenter;
use Hub\Api\Http\CollectionResponder;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Request\ApiUserWriteRequest;
use Hub\Api\Request\RequestBinder;
use Hub\Domain\DeviceMetadata;

class ApiUserService
{
    private const DEFAULT_COLLECTION_LIMIT = 20;

    /**
     * Os campos cuja recusa tem código próprio na API. A validação por constraints quer
     * devolver `invalid_request` para tudo, e isso apagava o `invalid_role`.
     *
     * @var array<string, string>
     */
    private const ERROR_CODE_BY_FIELD = ['role' => 'invalid_role'];

    private CollectionQuery $query;
    private CollectionResponder $collection;
    private CollectionPresenter $presenter;
    private RequestBinder $binder;

    public function __construct(
        private ApiDataAccess $db,
        ?CollectionQuery $query = null,
        ?CollectionResponder $collection = null,
        ?RequestBinder $binder = null,
    ) {
        $this->query = $query ?? new CollectionQuery();
        $this->collection = $collection ?? new CollectionResponder();
        $this->presenter = new CollectionPresenter($this->query, $this->collection);
        $this->binder = $binder ?? new RequestBinder();
    }

    public function list(string $query = ''): array
    {
        $params = $this->query->params($query);
        return $this->presenter->present(
            $this->db->apiUsers->all(),
            ApiUserColumns::definition(),
            $params,
            self::DEFAULT_COLLECTION_LIMIT,
        );
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
     * A licença a que o utilizador fica preso, resolvida contra a base. Fica aqui e não numa
     * constraint: isto é uma pergunta ao MySQL, e escondê-la numa regra sobre o corpo punha
     * uma consulta onde ninguém a procura.
     *
     * Um `hub_admin` não tem licença, e é aqui que os dois campos voltam a zero.
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
