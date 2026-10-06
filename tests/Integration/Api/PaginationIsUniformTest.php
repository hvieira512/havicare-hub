<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use Hub\Api\Services\CapabilityDiscoveryService;
use Hub\Api\Services\DenylistService;
use Hub\Api\Services\SupplierService;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Infrastructure\Persistence\Repository\CapabilityDiscoveryRepository;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Toda a colecção que cresce com o uso devolve o mesmo envelope; ficam de fora os catálogos
 * fixos, o feed de notificações e os `links` de um dispositivo.
 */
final class PaginationIsUniformTest extends MysqlDashboardTestCase
{
    public function testEveryGrowingCollectionReturnsTheSameEnvelope(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $discovery = new CapabilityDiscoveryRepository(
            sys_get_temp_dir() . '/pagination-uniform-' . bin2hex(random_bytes(4)),
        );
        $discovery->save(['id' => 'disc_1', 'status' => 'draft', 'createdAt' => '2026-10-06T10:00:00Z']);

        $db->denylist->add('860000000000001', 'tcp', null, 'admin');

        $listings = [
            'suppliers' => (new SupplierService($db))->list(''),
            'denylist' => (new DenylistService($db))->list(''),
            'capability-discovery' => (new CapabilityDiscoveryService(
                $db,
                $this->createStub(\Hub\Api\Services\DeviceService::class),
                $discovery,
            ))->list(''),
        ];

        $envelopes = [];
        foreach ($listings as $name => $response) {
            $envelopes[$name] = [
                'top' => self::keysOf($response),
                'pagination' => self::keysOf($response['pagination'] ?? []),
                'filters' => self::keysOf($response['filters'] ?? []),
            ];
        }

        self::assertSame(
            array_fill_keys(array_keys($listings), $envelopes['suppliers']),
            $envelopes,
        );
    }

    public function testAGrowingCollectionCutsAtTheLimitAndSaysHowManyThereAre(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        foreach (range(1, 3) as $index) {
            $db->denylist->add('86000000000000' . $index, 'tcp', null, 'admin');
        }

        $page = (new DenylistService($db))->list('limit=2&page=2');

        self::assertCount(1, $page['data']);
        self::assertSame(
            ['limit' => 2, 'page' => 2, 'total_pages' => 2, 'total' => 3],
            $page['pagination'],
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function keysOf(mixed $value): array
    {
        $array = is_object($value) ? get_object_vars($value) : (array)$value;
        $keys = array_map('strval', array_keys($array));
        sort($keys);

        return $keys;
    }
}
