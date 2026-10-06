<?php

declare(strict_types=1);

namespace Hub\Device;

use Hub\Infrastructure\Persistence\Repository\ModelRepository;

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

    /**
     * O nome comercial acrescentado ao dispositivo, omitido quando não se sabe.
     *
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    public function enrich(array $device): array
    {
        $name = $this->resolveCommercialName(
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
        );
        if ($name !== '') {
            $device['commercialName'] = $name;
        }

        return $device;
    }
}
