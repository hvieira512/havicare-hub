<?php

declare(strict_types=1);

namespace Hub\Api\Http;

/** O descritor da listagem de fornecedores. */
final class SupplierColumns
{
    public static function definition(): CollectionColumns
    {
        // Sem escrita: um fornecedor nasce e morre com os modelos, e a API não o edita.
        return new CollectionColumns(
            sortable: ['name' => 'name', 'model_count' => 'model_count'],
            textFilters: ['name' => 'name'],
            extra: ['id'],
        );
    }
}
