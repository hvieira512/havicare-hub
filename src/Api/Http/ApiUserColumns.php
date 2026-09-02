<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Request\ApiUserWriteRequest;

/** O descritor da listagem de utilizadores da API. */
final class ApiUserColumns
{
    public static function definition(): CollectionColumns
    {
        return new CollectionColumns(
            sortable: [
                'username' => 'username',
                'role' => 'role',
                'company_name' => 'company_name',
                'license_id' => 'license_id',
                'enabled' => 'enabled',
            ],
            writable: ApiUserWriteRequest::class,
            textFilters: ['username' => 'username'],
            // Os dois são conjuntos fechados e não saem dos dados: com todos os
            // utilizadores admin e activos, um dropdown alimentado pelas linhas nunca
            // ofereceria o outro valor, e o filtro ficava inalcançável.
            fixedOptions: [
                'role' => ApiAuthContext::roles(),
                'enabled' => ['1', '0'],
            ],
            extra: ['company_name'],
        );
    }
}
