<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Hub\Api\Repository\DiaperSensitivityRepository;
use Hub\Domain\DiaperSensitivity;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A sensibilidade por sensor, guardada em MySQL.
 *
 * MySQL e a fonte de verdade precisamente por causa dos reinicios: o worker de ingestao
 * le isto no caminho quente com uma cache curta, e ao reiniciar a cache nasce vazia e
 * reenche na primeira observacao. O que estes testes protegem e o contrato de que a
 * ingestao depende -- que ha sempre um par utilizavel para devolver, e que uma alteracao
 * pela API e vista dentro do TTL sem reiniciar nada.
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
        // A migracao nao faz backfill: a ausencia de linha significa o preset normal, que e o
        // comportamento com que todos os sensores em producao ja corriam.
        $repository = new DiaperSensitivityRepository($this->pdoWithSensor());

        self::assertSame(DiaperSensitivity::normal(), $repository->forDevice(self::SENSOR));
    }

    public function testAnUnknownSensorAlsoReadsTheNormalPreset(): void
    {
        // A ingestao chama isto antes de qualquer garantia de que a linha existe. Devolver
        // null obrigaria o Bridge a decidir limiares, que e onde eles nao devem viver.
        $repository = new DiaperSensitivityRepository($this->createDashboardDatabase()->pdo());

        self::assertSame(DiaperSensitivity::normal(), $repository->forDevice('nao-existe'));
    }

    public function testUpsertStoresAndReadsBack(): void
    {
        $repository = new DiaperSensitivityRepository($this->pdoWithSensor());

        $repository->upsert(self::SENSOR, 3, 7);
        self::assertSame(['pollutionRange' => 3, 'pollutionValue' => 7], $repository->forDevice(self::SENSOR));

        $repository->upsert(self::SENSOR, 7, 15);
        self::assertSame(['pollutionRange' => 7, 'pollutionValue' => 15], $repository->forDevice(self::SENSOR));
    }

    public function testUpsertInvalidatesTheCacheImmediately(): void
    {
        // Uma escrita pela API tem de ser visivel de imediato no mesmo processo, sem esperar
        // pelo TTL -- e no dashboard a API e a ingestao vivem no mesmo processo.
        $repository = new DiaperSensitivityRepository($this->pdoWithSensor(), 3600);

        $repository->forDevice(self::SENSOR);
        $repository->upsert(self::SENSOR, 3, 7);

        self::assertSame(['pollutionRange' => 3, 'pollutionValue' => 7], $repository->forDevice(self::SENSOR));
    }

    public function testTheCacheHoldsForItsTtlAndTheDatabaseIsTheSourceOfTruth(): void
    {
        // Duas metades do mesmo contrato, com uma escrita feita por FORA do repositorio --
        // que e o caso real da API num processo a escrever e da ingestao noutro a ler.
        //
        // Uma instancia com TTL longo nao ve a escrita, e e essa a latencia que o TTL de 5s
        // define em producao. Uma instancia nova ve-a, e e por isso que um reinicio do
        // `health-hub` nao perde nada: a cache nasce vazia e reenche da base de dados.
        //
        // Nao se testa aqui um TTL de zero: a comparacao e `<=`, como no repositorio dos
        // links, portanto zero ainda guarda em cache o resto do segundo em curso.
        $pdo = $this->pdoWithSensor();
        $cached = new DiaperSensitivityRepository($pdo, 3600);

        $cached->forDevice(self::SENSOR);
        $pdo->prepare('
            INSERT INTO diaper_sensor_settings (imei, pollution_range, pollution_value) VALUES (?, ?, ?)
        ')->execute([self::SENSOR, 3, 7]);

        self::assertSame(DiaperSensitivity::normal(), $cached->forDevice(self::SENSOR));
        self::assertSame(
            ['pollutionRange' => 3, 'pollutionValue' => 7],
            (new DiaperSensitivityRepository($pdo))->forDevice(self::SENSOR)
        );
    }

    public function testDeleteReturnsTheSensorToTheDefault(): void
    {
        $repository = new DiaperSensitivityRepository($this->pdoWithSensor());

        $repository->upsert(self::SENSOR, 3, 7);
        $repository->delete(self::SENSOR);

        self::assertSame(DiaperSensitivity::normal(), $repository->forDevice(self::SENSOR));
    }

    public function testRemovingTheDeviceRemovesItsSettings(): void
    {
        // Cascata da chave estrangeira: uma configuracao orfa sobreviveria a um IMEI reutilizado
        // e passava limiares de outro utente ao sensor seguinte.
        $pdo = $this->pdoWithSensor();
        (new DiaperSensitivityRepository($pdo))->upsert(self::SENSOR, 3, 7);

        $pdo->prepare('DELETE FROM whitelist WHERE imei = ?')->execute([self::SENSOR]);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM diaper_sensor_settings WHERE imei = ?');
        $stmt->execute([self::SENSOR]);
        self::assertSame(0, (int)$stmt->fetchColumn());
    }
}
