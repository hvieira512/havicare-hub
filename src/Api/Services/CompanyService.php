<?php

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\CollectionQuery;
use Hub\Api\Http\CollectionResponder;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Request\CompanyWriteRequest;
use Hub\Api\Request\RequestBinder;

class CompanyService
{
    private const DEFAULT_COLLECTION_LIMIT = 20;

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
        $items = $this->db->companies->all();

        return $this->collection->respond($items, $page, $limit, [], []);
    }

    /**
     * O nome repetido responde 409, que é o que a especificação sempre prometeu.
     *
     * Prometia e nunca enviava. O serviço perguntava-o assim:
     *
     *     $id = $this->db->companies->create($name);
     *     if ($id <= 0) { return ApiError::duplicateCompany()->toArray(); }
     *
     * e o `CompanyRepository::create()` nunca devolve zero para um nome repetido -- devolve o
     * id da linha que já existe. O `if` era código morto, o `duplicateCompany()` tinha ali o
     * seu único chamador e nunca era construído, e criar duas vezes a mesma empresa respondia
     * sucesso com o mesmo id das duas.
     *
     * A pergunta passa a ser feita antes, a quem sabe responder. O `create()` do repositório
     * fica como está: é idempotente de propósito para quem o chama por dentro, e há testes
     * que contam com isso.
     */
    public function create(array $payload): array
    {
        $request = $this->binder->bind($payload, CompanyWriteRequest::class);
        if (is_array($request)) {
            return $request;
        }

        $name = $request->normalizedName();
        if ($this->db->companies->findByName($name) !== null) {
            return ApiError::duplicateCompany()->toArray();
        }

        return ['status' => 'ok', 'id' => $this->db->companies->create($name)];
    }

    /**
     * Renomear para um nome que já é de outra empresa é 409, e não 500.
     *
     * O `companies.name` é `UNIQUE`, e por isso a base recusava-o -- mas só depois, com uma
     * excepção do PDO a subir até ao kernel e a sair como `server_error`. É uma recusa
     * previsível e tem código próprio; não tem de derrubar o pedido para o dizer.
     */
    public function update(int $id, array $payload): array
    {
        $existing = $this->db->companies->findById($id);
        if ($existing === null) {
            return ApiError::companyNotFound()->toArray();
        }

        $request = $this->binder->bind($payload, CompanyWriteRequest::class);
        if (is_array($request)) {
            return $request;
        }

        $name = $request->normalizedName();
        $taken = $this->db->companies->findByName($name);
        if ($taken !== null && (int)$taken['id'] !== $id) {
            return ApiError::duplicateCompany()->toArray();
        }

        $this->db->companies->update($id, $name);

        return ['status' => 'ok'];
    }

    public function delete(int $id): array
    {
        $existing = $this->db->companies->findById($id);
        if ($existing === null) {
            return ApiError::companyNotFound()->toArray();
        }
        $this->db->companies->delete($id);

        return ['status' => 'ok'];
    }
}
