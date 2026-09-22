<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Dashboard\DeviceConfigurationProjection;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Uma leitura que traz várias configurações de uma vez guarda-se uma a uma.
 *
 * A projeção resolvia a chave a partir do tipo da resposta, o que serve quando cada resposta
 * confirma uma configuração — é assim nos relógios. O dispensador M228 responde ao `0x05` com
 * **todas** de uma vez, e o mapeamento por tipo punha o bloco inteiro debaixo de uma chave só:
 * a dashboard continuava sem saber o valor de nenhuma delas, que era exactamente o problema
 * que ler a configuração ao aparelho devia resolver.
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
     * Sem mapa de configurações, o comportamento antigo fica de pé: uma linha só.
     *
     * É o dos relógios — uma resposta confirma uma configuração, e a chave sai do tipo dela.
     * Que chave é essa, quando várias declaram o mesmo tipo de resposta, é uma escolha
     * arbitrária que este caminho sempre teve; o que aqui se prende é que continua a ser uma
     * e não várias, para o caminho novo não ficar a escrever por cima do antigo.
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

        self::assertCount(1, $this->reportedByKey($db, '869243062262999'));
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
