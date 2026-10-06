<?php

declare(strict_types=1);

namespace Hub\Api\Http;

/** O descritor da listagem de bloqueios. */
final class DenylistColumns
{
    public static function definition(): CollectionColumns
    {
        // Sem escrita: um bloqueio cria-se e remove-se inteiro, e não se edita no sítio.
        return new CollectionColumns(
            sortable: ['identity' => 'identity', 'protocol' => 'protocol', 'created_at' => 'created_at'],
            textFilters: ['identity' => ['identity', 'note']],
            extra: ['note', 'created_by'],
        );
    }
}
