<?php

namespace Hub\Device;

use Hub\Api\Repository\ModelRepository;

class CommercialModelResolver
{
    public function __construct(private ?ModelRepository $models = null)
    {
    }

    public function resolveCommercialName(string $supplier, string $model): string
    {
        if ($this->models === null || trim($supplier) === '' || trim($model) === '') {
            return '';
        }

        $row = $this->models->find($supplier, $model);
        if (!is_array($row)) {
            return '';
        }

        return trim((string)($row['commercial_name'] ?? ''));
    }
}
