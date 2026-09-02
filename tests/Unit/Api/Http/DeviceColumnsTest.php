<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use Hub\Api\Http\DeviceColumns;
use Hub\Api\Repository\WhitelistRepository;
use Hub\Api\Request\DeviceWriteRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * O descritor de colunas é o que diz a quem consome a API o que pode ordenar, filtrar e
 * editar. Escrevê-lo à mão deixava-o divergir do que o servidor aceita, e por isso cada
 * campo dele sai de onde a capacidade vive mesmo.
 */
final class DeviceColumnsTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function describedByField(array $available = [], array $counts = []): array
    {
        $byField = [];
        foreach (DeviceColumns::describe($available, $counts) as $column) {
            $byField[$column['field']] = $column;
        }

        return $byField;
    }

    public function testTheSortableColumnsAreExactlyTheOnesTheRepositoryCanOrderBy(): void
    {
        $declared = array_keys(array_filter(
            $this->describedByField(),
            static fn(array $column): bool => $column['sortable'] === true,
        ));
        $supported = array_keys(WhitelistRepository::SORTABLE_COLUMNS);

        sort($declared);
        sort($supported);

        self::assertSame($supported, $declared);
    }

    public function testTheEditableColumnsAreExactlyTheFieldsTheWriteRequestAccepts(): void
    {
        $declared = array_keys(array_filter(
            $this->describedByField(),
            static fn(array $column): bool => $column['editable'] === true,
        ));

        $accepted = array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionClass(DeviceWriteRequest::class))->getConstructor()?->getParameters() ?? [],
        );

        sort($declared);
        sort($accepted);

        self::assertSame($accepted, $declared);
    }

    public function testATextColumnDeclaresTheQueryParameterItFiltersBy(): void
    {
        $imei = $this->describedByField()['imei'];

        self::assertSame('text', $imei['filter']['type']);
        self::assertSame('imei', $imei['filter']['param']);
    }

    /** As opções de uma coluna de escolha vêm dos valores que a listagem já apurou. */
    public function testASelectColumnCarriesTheValuesAndCountsTheListingFound(): void
    {
        $columns = $this->describedByField(
            ['deviceType' => ['watch', 'radar']],
            ['deviceType' => [['value' => 'watch', 'count' => 17], ['value' => 'radar', 'count' => 20]]],
        );

        self::assertSame('select', $columns['deviceType']['filter']['type']);
        self::assertSame('deviceType', $columns['deviceType']['filter']['param']);
        self::assertTrue($columns['deviceType']['filter']['multiple']);
        self::assertSame(
            [['value' => 'watch', 'count' => 17], ['value' => 'radar', 'count' => 20]],
            $columns['deviceType']['filter']['options'],
        );
    }

    /** Sem contagem conhecida a opção sai na mesma, para o dropdown não perder valores. */
    public function testAnOptionWithoutACountStillAppears(): void
    {
        $columns = $this->describedByField(['supplier' => ['MOKO', 'Vivistar']], []);

        self::assertSame(
            [['value' => 'MOKO', 'count' => null], ['value' => 'Vivistar', 'count' => null]],
            $columns['supplier']['filter']['options'],
        );
    }

    /**
     * O estado de ligação não é coluna da base de dados: não se ordena nem se edita, e o
     * filtro dele é uma escolha de um só valor.
     */
    public function testTheOnlineColumnIsFilterableButNeitherSortableNorEditable(): void
    {
        $online = $this->describedByField()['online'];

        self::assertFalse($online['sortable']);
        self::assertFalse($online['editable']);
        self::assertSame('select', $online['filter']['type']);
        self::assertFalse($online['filter']['multiple']);
        self::assertSame(
            [['value' => 'online', 'count' => null], ['value' => 'offline', 'count' => null]],
            $online['filter']['options'],
        );
    }

    /**
     * O descritor descreve estrutura e não apresentação: as etiquetas são traduzidas por
     * quem desenha a interface, como no resto dos contratos deste hub.
     */
    public function testTheDescriptorCarriesNoUserFacingLabels(): void
    {
        foreach (DeviceColumns::describe() as $column) {
            self::assertArrayNotHasKey('title', $column);
            self::assertArrayNotHasKey('label', $column);
            foreach ($column['filter']['options'] ?? [] as $option) {
                self::assertArrayNotHasKey('label', $option);
            }
        }
    }
}
