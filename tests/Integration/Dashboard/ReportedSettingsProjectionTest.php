<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\State\DeviceConfigurationProjection;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Uma leitura que traz várias configurações de uma vez guarda-se uma a uma: o M228 responde
 * ao `0x05` com todas, e o mapeamento pelo tipo da resposta só serve uma.
 */
final class ReportedSettingsProjectionTest extends MysqlDashboardTestCase
{
    private const IMEI = '869243062262262';

    public function testEachSettingIsStoredUnderItsOwnKey(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $projection = new DeviceConfigurationProjection();
        $projection->setDataAccess($db);

        $projection->saveReported(self::IMEI, 'zayata-m228', 'read_config_ack', [
            'type' => 'device_config',
            'data' => ['settings' => [
                'alarm_volume' => ['volume' => 2],
                'child_lock' => ['enabled' => true],
                'do_not_disturb' => ['enabled' => false, 'startHour' => 22],
            ]],
        ]);

        $reported = $this->reportedByKey($db);

        self::assertSame(['volume' => 2], $reported['alarm_volume'] ?? null);
        self::assertSame(['enabled' => true], $reported['child_lock'] ?? null);
        self::assertSame(['enabled' => false, 'startHour' => 22], $reported['do_not_disturb'] ?? null);
    }

    /**
     * Sem mapa de configurações vale o caminho pelo tipo, que se recusa a adivinhar; a regra
     * está no `AmbiguousReplyTypeTest`, e aqui prende-se que o caminho novo não o sobrepõe.
     */
    public function testAPayloadWithoutSettingsKeepsTheOldBehaviour(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $projection = new DeviceConfigurationProjection();
        $projection->setDataAccess($db);

        // Outro aparelho: as duas verificações partilham a base, e o que a primeira guardou
        // apareceria aqui como se fosse desta.
        $projection->saveReported('869243062262999', 'zayata-m228', 'write_config_ack', [
            'type' => 'device_config',
            'data' => ['status' => 'ok'],
        ]);

        self::assertCount(0, $this->reportedByKey($db, '869243062262999'));
    }

    /** @return array<string, mixed> */
    private function reportedByKey(ApiDataAccess $db, string $imei = self::IMEI): array
    {
        $byKey = [];
        foreach ($db->deviceConfigurations->allForImei($imei) as $row) {
            $payload = $row['reported_payload'] ?? null;
            if (is_array($payload) && $payload !== []) {
                $byKey[(string)$row['config_key']] = $payload['data'] ?? $payload;
            }
        }

        return $byKey;
    }
}
