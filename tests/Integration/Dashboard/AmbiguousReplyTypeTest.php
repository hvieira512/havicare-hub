<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Dashboard\DeviceConfigurationProjection;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Um tipo de resposta que várias configurações declaram não nomeia nenhuma.
 *
 * A projeção resolvia a chave percorrendo o catálogo e ficando pela **primeira** configuração
 * que declarasse aquele tipo de resposta. Num protocolo onde uma resposta confirma uma
 * configuração isso é exacto; no dispensador, onde nove configurações partilham o
 * `write_config_ack`, era escolher à sorte — e o valor de uma escrita ia parar à linha de
 * outra configuração qualquer, que passava a mostrar na dashboard um reportado que nunca foi
 * dela.
 *
 * Quando o tipo identifica exactamente uma configuração, nomeia-a. Quando identifica várias,
 * não nomeia nenhuma, e é melhor não guardar do que guardar na linha errada.
 */
final class AmbiguousReplyTypeTest extends MysqlDashboardTestCase
{
    private const IMEI = '869243062262262';

    public function testAnAmbiguousReplyTypeStoresNothing(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $projection = new DeviceConfigurationProjection();
        $projection->setDataAccess($db);

        // Nove configurações do dispensador declaram este tipo de resposta.
        $projection->saveReported(self::IMEI, 'zayata-m228', 'write_config_ack', [
            'type' => 'device_config',
            'data' => ['refusedTags' => ['0x1031']],
        ]);

        self::assertSame([], $this->reportedKeys($db));
    }

    /** Um tipo que identifica uma só configuração continua a nomeá-la. */
    public function testAnUnambiguousReplyTypeStillNamesIt(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $projection = new DeviceConfigurationProjection();
        $projection->setDataAccess($db);

        $projection->saveReported('861265061009822', 'vivistar-iw', 'AP76', [
            'type' => 'device_config',
            'data' => ['status' => 'ok'],
        ]);

        self::assertNotSame([], $this->reportedKeys($db, '861265061009822'));
    }

    /** @return list<string> */
    private function reportedKeys(ApiDataAccess $db, string $imei = self::IMEI): array
    {
        $chaves = [];
        foreach ($db->deviceConfigurations->allForImei($imei) as $row) {
            $payload = $row['reported_payload'] ?? null;
            if (is_array($payload) && $payload !== []) {
                $chaves[] = (string)$row['config_key'];
            }
        }

        return $chaves;
    }
}
