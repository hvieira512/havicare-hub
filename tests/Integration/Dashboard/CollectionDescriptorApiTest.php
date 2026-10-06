<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Api\Services\CompanyService;
use Hub\Api\Services\LicenseService;
use Hub\Api\Services\ModelService;
use Hub\Api\Services\SupplierService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MysqlDashboardTestCase;

/**
 * As quatro listagens descrevem-se como a de utilizadores: `columns` diz o que se ordena e
 * filtra, `filters.counts` quantas linhas cada valor de escolha tem.
 */
final class CollectionDescriptorApiTest extends MysqlDashboardTestCase
{
    /** @return iterable<string, array{0: callable(ApiDataAccess): array<string, mixed>}> */
    public static function listings(): iterable
    {
        yield 'models' => [static fn (ApiDataAccess $db): array => (new ModelService($db))->list()];
        yield 'companies' => [static fn (ApiDataAccess $db): array => (new CompanyService($db))->list()];
        yield 'licenses' => [static fn (ApiDataAccess $db): array => (new LicenseService($db))->list()];
        yield 'suppliers' => [static fn (ApiDataAccess $db): array => (new SupplierService($db))->list()];
    }

    #[DataProvider('listings')]
    public function testEveryListingCarriesItsColumnDescriptor(callable $list): void
    {
        $response = $list(ApiDataAccess::fromDatabase($this->createDashboardDatabase()));

        self::assertArrayHasKey('columns', $response);
        self::assertNotSame([], $response['columns']);
        foreach ($response['columns'] as $column) {
            self::assertArrayHasKey('field', $column);
            self::assertArrayHasKey('sortable', $column);
            self::assertArrayHasKey('editable', $column);
            self::assertArrayHasKey('filter', $column);
        }
    }

    #[DataProvider('listings')]
    public function testEveryListingCountsTheValuesOfItsChoiceFilters(callable $list): void
    {
        $response = $list(ApiDataAccess::fromDatabase($this->createDashboardDatabase()));

        self::assertArrayHasKey('counts', $response['filters']);
    }

    public function testTheModelFiltersKeepTheirParameterNamesAndRows(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $models = new ModelService($db);

        $response = $models->list('supplier=Wonlex&deviceType=watch');
        $suppliers = array_column($response['data'], 'supplier');

        self::assertNotSame([], $suppliers);
        self::assertSame(['Wonlex'], array_values(array_unique($suppliers)));
        self::assertSame(
            ['id', 'supplier_id', 'supplier', 'internalModel', 'commercialName', 'deviceType', 'protocol', 'image', 'capabilities'],
            array_keys($response['data'][0]),
        );
    }

    /** O `model` continua a apanhar tanto o nome interno como o comercial, por pedaço. */
    public function testTheModelTextFilterStillMatchesEitherName(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        $response = (new ModelService($db))->list('model=HW20');
        $names = array_column($response['data'], 'internalModel');

        self::assertContains('HW20PRO', $names);
    }

    /** O `companyId` é o nome antigo do parâmetro das licenças e continua a estreitar. */
    public function testTheLegacyCompanyIdParameterStillNarrowsTheLicenses(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $license = $db->licenses->all()[0] ?? null;

        self::assertIsArray($license);
        $companyId = (int)$license['company_id'];
        $response = (new LicenseService($db))->list('companyId=' . $companyId);

        self::assertNotSame([], $response['data']);
        self::assertSame(
            [$companyId],
            array_values(array_unique(array_map(
                static fn (array $row): int => (int)$row['company_id'],
                $response['data'],
            ))),
        );
    }
}
