<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\DeviceService;
use Hub\Dashboard\DashboardStoreContract;
use Hub\Device\DeviceHubServer;
use Hub\Registry\Whitelist;
use Tests\Support\MysqlDashboardTestCase;

/** O IMEI é a chave por onde as ligações e a planta apontam: renomeá-lo leva-as com ele. */
final class DeviceImeiRenameTest extends MysqlDashboardTestCase
{
    public function testRenamingTheImeiKeepsTheGatewayLinkAndTheRadarLayout(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $whitelist = new Whitelist(null, $db->whitelist);

        $gateway = '0000000000000001';
        $radar = '861265061009822';
        $renamed = '861265061009899';

        $whitelist->register($gateway, 'MOKO', 'MKGW3', 'gateway', 1001, '', $gateway, 'hitcare');
        $whitelist->register($radar, 'Qinglanst', 'RD-V1', 'radar', 1001, '', $radar, 'hitcare');
        $db->gatewayDeviceLinks->upsert($gateway, $radar);
        $db->radarLayouts->store($radar, $this->layout(), '{}', '2026-10-02 12:00:00');

        $service = new DeviceService(
            $this->createStub(DashboardStoreContract::class),
            $whitelist,
            $this->createStub(DeviceHubServer::class),
            $db,
        );

        $result = $service->update($radar, [
            'imei' => $renamed,
            'supplier' => 'Qinglanst',
            'model' => 'RD-V1',
            'licenseId' => '1001',
            'company' => 'hitcare',
        ]);

        self::assertSame('ok', $result['status'] ?? null, 'o renomear devia ter corrido');
        self::assertTrue(
            $db->gatewayDeviceLinks->isEnabled($gateway, $renamed),
            'a ligação ao gateway tem de seguir o IMEI novo',
        );
        self::assertNotNull(
            $db->radarLayouts->findByImei($renamed),
            'a planta do radar tem de seguir o IMEI novo',
        );
    }

    /** @return array<string, mixed> */
    private function layout(): array
    {
        return [
            'room' => ['x_min_dm' => -20, 'y_min_dm' => 0, 'x_max_dm' => 20, 'y_max_dm' => 40],
            'areas' => [],
        ];
    }
}
