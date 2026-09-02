<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use Hub\Api\Http\CollectionColumns;
use Hub\Api\Request\ApiUserWriteRequest;
use PHPUnit\Framework\TestCase;

/**
 * O descritor que qualquer listagem usa para dizer a quem consome o que pode ordenar,
 * filtrar e editar.
 *
 * Cada listagem declara-o uma vez a partir de onde a capacidade vive mesmo -- as colunas
 * que o repositório sabe ordenar, os campos que o pedido de escrita aceita -- em vez de o
 * escrever à mão e o deixar divergir.
 */
final class CollectionColumnsTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function describedByField(CollectionColumns $columns, array $counts = []): array
    {
        $byField = [];
        foreach ($columns->describe($counts) as $column) {
            $byField[$column['field']] = $column;
        }

        return $byField;
    }

    private function columns(): CollectionColumns
    {
        return new CollectionColumns(
            sortable: ['username' => 'username', 'role' => 'role'],
            writable: ApiUserWriteRequest::class,
            textFilters: ['username' => 'username'],
            fixedOptions: ['role' => ['hub_admin', 'license_client']],
        );
    }

    public function testTheSortableColumnsAreTheOnesTheListingCanOrderBy(): void
    {
        $described = $this->describedByField($this->columns());

        self::assertTrue($described['username']['sortable']);
        self::assertTrue($described['role']['sortable']);
    }

    public function testTheEditableColumnsComeFromTheWriteRequestAndNotFromAList(): void
    {
        $described = $this->describedByField($this->columns());

        self::assertTrue($described['username']['editable']);
        self::assertTrue($described['role']['editable']);
    }

    /**
     * O pedido de escrita diz o que se *edita*, e não que colunas existem. Deriva-lo para
     * as duas coisas punha a password, o `licenseRefId` e a matriz de capacidades como
     * colunas de uma tabela -- campos que a resposta nem traz.
     */
    public function testAWriteOnlyFieldDoesNotBecomeAColumn(): void
    {
        $fields = array_column($this->columns()->describe(), 'field');

        self::assertNotContains('password', $fields);
        self::assertNotContains('companyId', $fields);
        self::assertSame(['username', 'role'], $fields);
    }

    public function testATextColumnDeclaresTheParameterItFiltersBy(): void
    {
        $filter = $this->describedByField($this->columns())['username']['filter'];

        self::assertSame('text', $filter['type']);
        self::assertSame('username', $filter['param']);
    }

    /**
     * O conjunto é fechado e sai inteiro; a contagem é que vem dos dados. Um valor que o
     * filtro actual não deixou em nenhuma linha sai com zero em vez de desaparecer -- senão
     * ficava inalcançável, que é a razão de o conjunto ser declarado e não descoberto.
     */
    public function testAClosedSetOffersEveryValueAndZeroForTheOnesTheDataLacks(): void
    {
        $described = $this->describedByField(
            $this->columns(),
            ['role' => [['value' => 'hub_admin', 'count' => 4]]],
        );

        self::assertSame('role', $described['role']['filter']['param']);
        self::assertFalse($described['role']['filter']['multiple']);
        self::assertSame(
            [['value' => 'hub_admin', 'count' => 4], ['value' => 'license_client', 'count' => 0]],
            $described['role']['filter']['options'],
        );
    }

    /** Uma listagem sem campos editáveis declara-o, em vez de mentir que tudo se edita. */
    public function testAReadOnlyListingDeclaresNothingEditable(): void
    {
        $columns = new CollectionColumns(sortable: ['label' => 'c.label'], writable: null);
        $described = $this->describedByField($columns);

        self::assertFalse($described['label']['editable']);
    }

    /** Uma coluna que não se filtra diz `null`, e não um filtro vazio. */
    public function testAColumnWithoutAFilterSaysSo(): void
    {
        $columns = new CollectionColumns(sortable: ['createdAt' => 'u.created_at'], writable: null);

        self::assertNull($this->describedByField($columns)['createdAt']['filter']);
    }

    /** O descritor descreve estrutura: traduzir é de quem desenha a interface. */
    public function testItCarriesNoUserFacingLabels(): void
    {
        foreach ($this->columns()->describe() as $column) {
            self::assertArrayNotHasKey('title', $column);
            self::assertArrayNotHasKey('label', $column);
        }
    }
}
