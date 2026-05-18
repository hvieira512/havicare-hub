<?php

namespace App\Http\Controller;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;

class SupplierController extends Controller
{
    public function listSuppliers(ServerRequestInterface $request): Response
    {
        if ($this->pdo === null || $this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $params = $request->getQueryParams();
        $page = $this->parsePage($params['page'] ?? null);
        $limit = $this->parseLimit($params['limit'] ?? null);

        $filters = [
            'name' => $params['name'] ?? null,
            'enabled' => $this->parseNullableBool($params['enabled'] ?? null),
        ];

        $suppliers = array_map(fn(array $row): array => $this->supplierResource($row), $this->supplierRepo->list($filters, $page, $limit));
        $total = $this->supplierRepo->countFiltered($filters);

        return $this->jsonResponse([
            'data' => $suppliers,
            'pagination' => $this->paginationResource($page, $limit, $total),
            'filters' => [
                'name' => $params['name'] ?? null,
                'enabled' => $params['enabled'] ?? null,
            ],
        ]);
    }

    public function getSupplier(int $id): Response
    {
        if ($this->pdo === null || $this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $row = $this->supplierRepo->find($id);
        if ($row === null) {
            return $this->errorResponse('supplier_not_found', 'Supplier not found', 404);
        }

        return $this->jsonResponse(['data' => $this->supplierResource($row)]);
    }

    public function createSupplier(ServerRequestInterface $request): Response
    {
        if ($this->pdo === null || $this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        $name = trim((string)($body['name'] ?? ''));
        $enabled = (bool)($body['enabled'] ?? true);

        if ($name === '') {
            return $this->errorResponse('invalid_request', 'Supplier name is required', 400);
        }

        $existing = $this->supplierRepo->findByName($name);
        if ($existing !== null) {
            return $this->errorResponse('duplicate_supplier', 'Supplier already exists', 409);
        }

        $id = $this->supplierRepo->insert(['name' => $name, 'enabled' => $enabled]);
        $row = $this->supplierRepo->find($id);

        return $this->jsonResponse(['data' => $this->supplierResource($row)], 201);
    }

    public function updateSupplier(int $id, ServerRequestInterface $request): Response
    {
        if ($this->pdo === null || $this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $existing = $this->supplierRepo->find($id);
        if ($existing === null) {
            return $this->errorResponse('supplier_not_found', 'Supplier not found', 404);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        $data = [];
        if (isset($body['name'])) {
            $name = trim((string)$body['name']);
            if ($name === '') {
                return $this->errorResponse('invalid_request', 'Supplier name cannot be empty', 400);
            }
            $data['name'] = $name;

            $duplicate = $this->supplierRepo->findByName($name);
            if ($duplicate !== null && $duplicate['id'] !== $id) {
                return $this->errorResponse('duplicate_supplier', 'Another supplier with this name already exists', 409);
            }
        }
        if (isset($body['enabled'])) {
            if (!is_bool($body['enabled'])) {
                return $this->errorResponse('invalid_request', 'enabled must be a boolean', 400);
            }
            $data['enabled'] = $body['enabled'];
        }

        if ($data === []) {
            return $this->errorResponse('no_data', 'No fields to update', 400);
        }

        $this->supplierRepo->update($id, $data);
        $row = $this->supplierRepo->find($id);

        return $this->jsonResponse(['data' => $this->supplierResource($row)]);
    }

    public function deleteSupplier(int $id): Response
    {
        if ($this->pdo === null || $this->supplierRepo === null) {
            return $this->errorResponse('mysql_unavailable', 'MySQL is not available', 503);
        }

        $existing = $this->supplierRepo->find($id);
        if ($existing === null) {
            return $this->errorResponse('supplier_not_found', 'Supplier not found', 404);
        }

        $modelCount = $this->supplierRepo->countModelsUsingSupplier($id);
        if ($modelCount > 0) {
            return $this->errorResponse('supplier_in_use', "Supplier is used by $modelCount model(s). Remove or reassign them first.", 409);
        }

        $this->supplierRepo->delete($id);

        return $this->jsonResponse(['status' => 'deleted', 'data' => $this->supplierResource($existing)]);
    }

    private function supplierResource(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'enabled' => $row['enabled'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }
}
