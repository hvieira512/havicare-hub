<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Hub\Api\Request\CompanyWriteRequest;

/** O descritor da listagem de empresas. */
final class CompanyColumns
{
    public static function definition(): CollectionColumns
    {
        return new CollectionColumns(
            sortable: ['name' => 'name', 'license_count' => 'license_count'],
            writable: CompanyWriteRequest::class,
            textFilters: ['name' => 'name'],
            extra: ['id'],
        );
    }
}
