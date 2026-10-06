<?php

declare(strict_types=1);

namespace Hub\Api\Http;

/** O descritor da listagem de rascunhos de descoberta. */
final class DiscoveryColumns
{
    public static function definition(): CollectionColumns
    {
        // O estado é conjunto fechado: um rascunho nasce `draft` e o `apply` passa-o a
        // `applied`. Sem escrita -- aplica-se pela rota própria, e não editando a linha.
        return new CollectionColumns(
            sortable: ['id' => 'id', 'status' => 'status', 'createdAt' => 'createdAt'],
            textFilters: ['id' => 'id'],
            fixedOptions: ['status' => ['draft', 'applied']],
            extra: ['device', 'model', 'changes'],
        );
    }
}
