<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Request\DeviceWriteRequest;
use Hub\Api\Services\DeviceService;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Ingress\Tcp\DeviceHubServer;
use Hub\Registry\Whitelist;
use Hub\State\DeviceStore;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\MysqlDashboardTestCase;

/** O IMEI é a chave por onde as ligações, a planta e a configuração apontam: renomeá-lo leva-as com ele. */
final class DeviceImeiRenameTest extends MysqlDashboardTestCase
{
    public function testRenamingTheImeiKeepsTheGatewayLinkTheRadarLayoutAndTheConfiguration(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $whitelist = new Whitelist($db->whitelist);

        $gateway = '0000000000000001';
        $radar = '861265061009822';
        $renamed = '861265061009899';

        $whitelist->register($gateway, 'MOKO', 'MKGW3', 'gateway', 1001, '', $gateway, 'hitcare');
        $whitelist->register($radar, 'Qinglanst', 'HC2', 'radar', 1001, '', $radar, 'hitcare');
        $db->gatewayDeviceLinks->upsert($gateway, $radar);
        $db->radarLayouts->store($radar, $this->layout(), '2026-10-02 12:00:00');
        $database->pdo()->prepare("
            INSERT INTO device_configurations (imei, config_key, native_key, protocol, desired_payload, reported_payload)
            VALUES (?, 'timezone', 'timezone', 'qinglanst-radar', '{}', '{}')
        ")->execute([$radar]);
        $database->pdo()->prepare("
            INSERT INTO device_configuration_changes (change_id, imei, config_key, desired_revision, desired_payload, created_at, updated_at)
            VALUES ('change-1', ?, 'timezone', 1, '{}', NOW(), NOW())
        ")->execute([$radar]);

        $service = new DeviceService(
            new DeviceStore(new InMemoryRedisClient()),
            $whitelist,
            $this->createStub(DeviceHubServer::class),
            $db,
        );

        $result = $service->update($radar, new DeviceWriteRequest(
            imei: $renamed,
            supplier: 'Qinglanst',
            model: 'HC2',
            licenseId: 1001,
            company: 'hitcare',
        ));

        self::assertSame('ok', $result['status'] ?? null, 'o renomear devia ter corrido');
        self::assertTrue(
            $db->gatewayDeviceLinks->isEnabled($gateway, $renamed),
            'a ligação ao gateway tem de seguir o IMEI novo',
        );
        self::assertCount(1, $db->radarLayouts->findByImei($renamed)['areas'] ?? [], 'as áreas da planta seguem o IMEI novo');
        foreach (['device_configurations', 'device_configuration_changes'] as $table) {
            $imeis = $database->pdo()->query("SELECT DISTINCT imei FROM {$table}")->fetchAll(\PDO::FETCH_COLUMN);
            self::assertSame([$renamed], $imeis, "{$table} segue o IMEI novo");
        }
    }

    /** @return array<string, mixed> */
    private function layout(): array
    {
        return [
            'room' => ['xMinDm' => -20, 'yMinDm' => 0, 'xMaxDm' => 20, 'yMaxDm' => 40],
            'areas' => [['key' => 1, 'type' => 'bed', 'name' => 'Cama', 'xMinDm' => -10, 'yMinDm' => 0, 'xMaxDm' => 10, 'yMaxDm' => 20]],
        ];
    }
}
