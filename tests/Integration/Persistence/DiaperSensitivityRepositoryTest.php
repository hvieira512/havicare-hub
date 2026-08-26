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

    public function testAWrittenValueIsReadBack(): void
    {
        // A escrita e do `PATCH /configurations`, que passa pelo ciclo de vida das
        // configuracoes. O que este repositorio faz e a leitura no caminho quente.
        $pdo = $this->pdoWithSensor();
        $this->storeSensitivity($pdo, 3, 7);

        self::assertSame(
            ['pollutionRange' => 3, 'pollutionValue' => 7],
            (new DiaperSensitivityRepository($pdo))->forDevice(self::SENSOR)
        );
    }

    public function testTheCacheHoldsForItsTtlAndTheDatabaseIsTheSourceOfTruth(): void
    {
        // Duas metades do mesmo contrato, com a escrita feita por FORA do repositorio --
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
        $this->storeSensitivity($pdo, 3, 7);

        self::assertSame(DiaperSensitivity::normal(), $cached->forDevice(self::SENSOR));
        self::assertSame(
            ['pollutionRange' => 3, 'pollutionValue' => 7],
            (new DiaperSensitivityRepository($pdo))->forDevice(self::SENSOR)
        );
    }

    /** A linha que o ciclo de vida das configuracoes deixa, escrita a mao. */
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
