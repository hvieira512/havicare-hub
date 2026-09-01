<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Hub\Api\Repository\DiaperSensitivityRepository;
use Hub\Domain\DiaperSensitivity;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A sensibilidade por sensor, guardada em MySQL. O contrato de que a ingestão depende: há
 * sempre um par utilizável para devolver, e uma alteração pela API é vista dentro do TTL da
 * cache sem reiniciar nada.
 */
final class DiaperSensitivityRepositoryTest extends MysqlDashboardTestCase
{
    private const SENSOR = 'eec5000202f9';

    private function pdoWithSensor(): PDO
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->prepare('
            INSERT INTO whitelist (imei, supplier, model, device_type, license_id, company)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([self::SENSOR, 'MONIT', 'MECS-PRO', 'diaper_sensor', 1001, 'hitcare']);

        return $pdo;
    }

    public function testAnUnconfiguredSensorReadsTheNormalPreset(): void
    {
        // Não há backfill: a ausência de linha significa o preset normal, que é o
        // comportamento com que todos os sensores em produção correm.
        $repository = new DiaperSensitivityRepository($this->pdoWithSensor());

        self::assertSame(DiaperSensitivity::normal(), $repository->forDevice(self::SENSOR));
    }

    public function testAnUnknownSensorAlsoReadsTheNormalPreset(): void
    {
        // A ingestao chama isto antes de qualquer garantia de que a linha existe. Devolver
        // null obrigaria o `Bridge` a decidir limiares, que é onde eles não devem viver.
        $repository = new DiaperSensitivityRepository($this->createDashboardDatabase()->pdo());

        self::assertSame(DiaperSensitivity::normal(), $repository->forDevice('nao-existe'));
    }

    public function testAWrittenValueIsReadBack(): void
    {
        // A escrita e do `PATCH /configurations`, que passa pelo ciclo de vida das
        // configurações. O que este repositório faz é a leitura no caminho quente.
        $pdo = $this->pdoWithSensor();
        $this->storeSensitivity($pdo, 3, 7);

        self::assertSame(
            ['pollutionRange' => 3, 'pollutionValue' => 7],
            (new DiaperSensitivityRepository($pdo))->forDevice(self::SENSOR)
        );
    }

    public function testTheCacheHoldsForItsTtlAndTheDatabaseIsTheSourceOfTruth(): void
    {
        // A escrita é feita por fora do repositório, que é o caso real: a API escreve num
        // processo e a ingestão lê noutro. Uma instância com TTL longo não vê a escrita --
        // essa é a latência que o TTL define -- e uma instância nova vê-a.
        $pdo = $this->pdoWithSensor();
        $cached = new DiaperSensitivityRepository($pdo, 3600);

        $cached->forDevice(self::SENSOR);
        $this->storeSensitivity($pdo, 3, 7);

        self::assertSame(DiaperSensitivity::normal(), $cached->forDevice(self::SENSOR));
        self::assertSame(
            ['pollutionRange' => 3, 'pollutionValue' => 7],
            (new DiaperSensitivityRepository($pdo))->forDevice(self::SENSOR)
        );
    }

    /** A linha que o ciclo de vida das configurações deixa, escrita à mão. */
    private function storeSensitivity(PDO $pdo, int $range, int $value): void
    {
        $pdo->prepare('
            INSERT INTO device_configurations (
                imei, config_key, native_key, protocol, desired_payload, reported_payload,
                confirmation_mode, last_status
            ) VALUES (?, ?, ?, ?, ?, \'{}\', \'local\', \'acked\')
        ')->execute([
            self::SENSOR,
            'diaper_sensitivity',
            'diaper_sensitivity',
            'monit-mecs-pro-ble',
            json_encode(['pollutionRange' => $range, 'pollutionValue' => $value]),
        ]);
    }
}
