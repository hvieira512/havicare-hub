<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Hub\Api\Request\ModelWriteRequest;
use Hub\Domain\Capability\CapabilityCatalog;

/** O descritor da listagem de modelos. */
final class ModelColumns
{
    /** @param list<array<string, mixed>> $models As linhas apresentadas, de onde saem as escolhas. */
    public static function definition(array $models): CollectionColumns
    {
        return new CollectionColumns(
            sortable: [
                'supplier' => 'supplier',
                'internalModel' => 'internal_model',
                'commercialName' => 'commercial_name',
                'deviceType' => 'device_type',
                'protocol' => 'protocol',
            ],
            writable: ModelWriteRequest::class,
            textFilters: ['model' => ['internalModel', 'commercialName']],
            fixedOptions: [
                'supplier' => self::valuesOf($models, 'supplier'),
                'protocol' => self::valuesOf($models, 'protocol'),
                // Os tipos vêm do catálogo e não das linhas: um tipo ainda sem modelos
                // continua a ser uma escolha válida, e sai com zero.
                'deviceType' => CapabilityCatalog::deviceTypes(),
            ],
            extra: ['id'],
        );
    }

    /**
     * Os valores distintos de uma coluna, por ordem natural.
     *
     * @param list<array<string, mixed>> $models
     * @return list<string>
     */
    private static function valuesOf(array $models, string $field): array
    {
        $values = [];
        foreach ($models as $model) {
            $value = trim((string)($model[$field] ?? ''));
            if ($value !== '') {
                $values[$value] = true;
            }
        }

        $values = array_keys($values);
        usort($values, static fn (string $left, string $right): int => strnatcasecmp($left, $right));

        return $values;
    }
}
