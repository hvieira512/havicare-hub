<?php

declare(strict_types=1);

namespace Tests\Support;

use Hub\Api\Services\DeviceService;
use Hub\Device\DeviceHubServer;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Registry\Whitelist;
use Hub\State\DeviceStore;
use Tests\Support\Doubles\InMemoryRedisClient;

/**
 * A API de dispositivos sobre uma base clonada, com um relógio Vivistar e um 4P Touch registados.
 *
 * @phpstan-require-extends MysqlDashboardTestCase
 */
trait DevicesApiFixture
{
    /**
     * @return array{0: DeviceService, 1: ApiDataAccess, 2: DeviceStore}
     */
    private function makeApi(?DeviceHubServer $hub = null): array
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro');
        $db->whitelist->register('868017032159118', '4P Touch', 'D46', 'watch', 0, '', '1703215911');
        $store = new DeviceStore(new InMemoryRedisClient());
        $store->setDataAccess($db);
        $store->runtime->registerDevice('861265061009822', 'Vivistar', 'L08 Pro');
        $whitelist = new Whitelist($db->whitelist);

        $submitted = [];
        $api = new DeviceService($store, $whitelist, $hub ?? $this->recordingHub($submitted), $db);

        return [$api, $db, $store];
    }

    /**
     * Um hub que aceita tudo e guarda o que foi para o fio.
     *
     * @param list<array{imei: string, bytes: string}> $submitted
     */
    private function recordingHub(array &$submitted, string $result = 'sent'): DeviceHubServer
    {
        $hub = $this->createMock(DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(
            function (string $imei, string $bytes) use (&$submitted, $result): string {
                $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
                return $result;
            }
        );

        return $hub;
    }
}
