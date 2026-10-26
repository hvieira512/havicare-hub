<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\OpenApiSpec;
use Hub\Api\Services\ApiUserService;
use Hub\Api\Services\CompanyService;
use Hub\Api\Services\DenylistService;
use Hub\Api\Services\LicenseService;
use Hub\Api\Services\ModelService;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MysqlDashboardTestCase;

/** As listagens descrevem em `columns` o que se ordena e filtra, e em `filters.counts` quantas linhas tem cada valor. */
final class CollectionDescriptorApiTest extends MysqlDashboardTestCase
{
    /** @return iterable<string, array{0: callable(ApiDataAccess): array<string, mixed>, 1: string}> */
    public static function listings(): iterable
    {
        yield 'models' => [static fn (ApiDataAccess $db): array => (new ModelService($db))->list('', ''), '/api/models'];
        yield 'companies' => [static fn (ApiDataAccess $db): array => (new CompanyService($db))->list(), '/api/companies'];
        yield 'licenses' => [static fn (ApiDataAccess $db): array => (new LicenseService($db))->list(), '/api/licenses'];
        yield 'suppliers' => [static fn (ApiDataAccess $db): array => (new ModelService($db))->suppliers(''), '/api/suppliers'];
        yield 'users' => [static fn (ApiDataAccess $db): array => (new ApiUserService($db))->list(), '/api/users'];
        yield 'denylist' => [static fn (ApiDataAccess $db): array => (new DenylistService($db))->list(), '/api/denylist'];
    }

    /** Um filtro que o descritor anuncia e a especificação não documenta é um parâmetro que ninguém conhece. */
    #[DataProvider('listings')]
    public function testEveryFilterTheDescriptorAnnouncesIsDocumented(callable $list, string $path): void
    {
        $response = $list(ApiDataAccess::fromDatabase($this->createDashboardDatabase()));
        $documented = array_column(OpenApiSpec::get()['paths'][$path]['get']['parameters'] ?? [], 'name');

        foreach ($response['columns'] as $column) {
            if ($column['filter'] !== null) {
                self::assertContains($column['filter']['param'], $documented);
            }
        }
    }

    #[DataProvider('listings')]
    public function testEveryListingCarriesItsColumnDescriptor(callable $list, string $path): void
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
    public function testEveryListingCountsTheValuesOfItsChoiceFilters(callable $list, string $path): void
    {
        $response = $list(ApiDataAccess::fromDatabase($this->createDashboardDatabase()));

        self::assertArrayHasKey('counts', $response['filters']);
    }

    public function testTheModelFiltersKeepTheirParameterNamesAndRows(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $models = new ModelService($db);

        $response = $models->list('supplier=Wonlex&deviceType=watch', '');
        $suppliers = array_column($response['data'], 'supplier');

        self::assertNotSame([], $suppliers);
        self::assertSame(['Wonlex'], array_values(array_unique($suppliers)));
        self::assertSame(
            ['id', 'supplierId', 'supplier', 'internalModel', 'commercialName', 'deviceType', 'protocol', 'image', 'capabilities'],
            array_keys($response['data'][0]),
        );
    }

    /** O `model` continua a apanhar tanto o nome interno como o comercial, por pedaço. */
    public function testTheModelTextFilterStillMatchesEitherName(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        $response = (new ModelService($db))->list('model=HW20', '');
        $names = array_column($response['data'], 'internalModel');

        self::assertContains('HW20PRO', $names);
    }

    public function testTheCompanyIdParameterNarrowsTheLicenses(): void
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
                static fn (array $row): int => $row['companyId'],
                $response['data'],
            ))),
        );
    }
}
