<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Hub\Api\Request\LicenseWriteRequest;

/** O descritor da listagem de licenças. */
final class LicenseColumns
{
    /**
     * @param list<string> $companyIds As empresas todas, e não só as que já têm licenças:
     *     um conjunto tirado das linhas deixava inalcançável a empresa ainda sem nenhuma.
     */
    public static function definition(array $companyIds): CollectionColumns
    {
        return new CollectionColumns(
            sortable: [
                'company_name' => 'company_name',
                'license_id' => 'license_id',
                'name' => 'name',
            ],
            writable: LicenseWriteRequest::class,
            textFilters: ['name' => 'name', 'company_name' => 'company_name'],
            fixedOptions: ['company_id' => $companyIds],
            extra: ['id', 'radar_cloud_configured', 'device_count'],
        );
    }
}
