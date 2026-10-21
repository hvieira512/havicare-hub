<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Http\CollectionColumns;
use Hub\Api\Http\CollectionPresenter;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Api\Request\ApiUserWriteRequest;
use Hub\Api\Request\RequestBinder;

class ApiUserService
{
    /**
     * Os campos cuja recusa tem código próprio, em vez do `invalid_request` das constraints.
     *
     * @var array<string, string>
     */
    private const ERROR_CODE_BY_FIELD = ['role' => 'invalid_role'];

    private CollectionPresenter $presenter;
    private RequestBinder $binder;

    public function __construct(private ApiDataAccess $db)
    {
        $this->presenter = new CollectionPresenter();
        $this->binder = new RequestBinder();
    }

    /** @return array<string, mixed> */
    public function list(string $query = ''): array
    {
        return $this->presenter->present(
            array_map(static fn (array $row): array => [
                'id' => (int)$row['id'],
                'username' => (string)$row['username'],
                'role' => (string)$row['role'],
                'enabled' => (int)$row['enabled'],
                'licenseRefId' => $row['license_ref_id'] === null ? null : (int)$row['license_ref_id'],
                'licenseId' => $row['license_id'] === null ? null : (int)$row['license_id'],
                'licenseName' => $row['license_name'],
                'companyId' => $row['company_id'] === null ? null : (int)$row['company_id'],
                'companyName' => $row['company_name'],
                'createdAt' => (string)$row['created_at'],
                'updatedAt' => (string)$row['updated_at'],
            ], $this->db->apiUsers->all()),
            new CollectionColumns(
                sortable: ['username', 'role', 'companyName', 'licenseId', 'enabled'],
                writable: ApiUserWriteRequest::class,
                textFilters: ['username' => 'username'],
                // Conjuntos fechados e não tirados dos dados: com todos os utilizadores admin e
                // activos, o outro valor ficaria inalcançável.
                fixedOptions: ['role' => ApiAuthContext::roles(), 'enabled' => ['1', '0']],
                extra: ['companyName'],
            ),
            $query,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
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
            $request->enabled,
            $license['licenseRefId'],
        );

        return ['status' => 'ok', 'id' => $id];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
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
            $request->enabled,
            $passwordHash,
            $license['licenseRefId'],
        );

        return ['status' => 'ok', 'id' => $id];
    }

    /** @return array<string, mixed> */
    public function delete(int $id): array
    {
        if ($this->db->apiUsers->findById($id) === null) {
            return ApiError::userNotFound()->toArray();
        }

        $this->db->apiUsers->delete($id);

        return ['status' => 'ok'];
    }

    /**
     * A licença a que o utilizador fica preso, resolvida contra a base e não numa constraint. Um
     * `hub_admin` não tem licença, e é aqui que os dois campos voltam a zero.
     *
     * @return array{licenseRefId: int|null}|array{error: array<string, mixed>}
     */
    private function resolveLicense(ApiUserWriteRequest $request): array
    {
        if (!$request->isLicenseClient()) {
            return ['licenseRefId' => null];
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

        return ['licenseRefId' => (int)$license['id']];
    }
}
