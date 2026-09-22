<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Dashboard\DeviceConfigurationProjection;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A calibração do relógio encontra o fuso que o próprio aparelho reportou.
 *
 * A hora que se manda ao M228 é local, e o fuso vem do que o hub tem guardado. A procura
 * olhava para `$payload['timeZone']`, mas um valor **reportado** fica debaixo de `data` —
 * é a forma com que a projeção guarda qualquer leitura. Só o **desejado** é um mapa simples.
 *
 * Quer dizer que num aparelho cujo fuso nunca tenha sido escrito pelo hub — só lido dele —
 * a calibração continuava a mandar UTC e o aparelho ficava uma hora atrasado, que é
 * exactamente o defeito que ela devia corrigir.
 */
final class StoredTimeZoneTest extends MysqlDashboardTestCase
{
    private const IMEI = '869243062262262';

    public function testTheZoneTheDeviceReportedIsFound(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $projection = new DeviceConfigurationProjection();
        $projection->setDataAccess($db);

        // Como uma leitura da configuração do aparelho a guarda: nunca ninguém escreveu o
        // fuso por aqui, só se leu o que lá estava.
        $projection->saveReported(self::IMEI, 'zayata-m228', 'read_config_ack', [
            'type' => 'device_config',
            'data' => ['settings' => ['time_zone' => ['timeZone' => 100]]],
        ]);

        self::assertSame(100, $this->storedZone($db));
    }

    /** E o desejado continua a valer quando é o único que há. */
    public function testTheDesiredZoneStillCounts(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->deviceConfigurations->saveDesired(
            self::IMEI,
            'time_zone',
            'zayata-m228',
            'timeZone',
            ['timeZone' => -300],
        );

        self::assertSame(-300, $this->storedZone($db));
    }

    /** Sem fuso guardado fica `null`, e quem calibra usa UTC — errado por um valor conhecido. */
    public function testWithoutAnyZoneItIsNull(): void
    {
        self::assertNull($this->storedZone(ApiDataAccess::fromDatabase($this->createDashboardDatabase())));
    }

    private function storedZone(ApiDataAccess $db): ?int
    {
        $service = new \ReflectionMethod(
            \Hub\Api\Services\DeviceFeatureRequestService::class,
            'storedTimeZone',
        );

        return $service->invoke($this->serviceWith($db), self::IMEI);
    }

    private function serviceWith(ApiDataAccess $db): \Hub\Api\Services\DeviceFeatureRequestService
    {
        $class = new \ReflectionClass(\Hub\Api\Services\DeviceFeatureRequestService::class);
        $service = $class->newInstanceWithoutConstructor();
        $property = $class->getProperty('db');
        $property->setValue($service, $db);

        return $service;
    }
}
