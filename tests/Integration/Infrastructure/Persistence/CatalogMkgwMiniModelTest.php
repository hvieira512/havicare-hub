<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\DatabaseMigrator;
use Hub\Infrastructure\Persistence\Migration\CatalogMkgwMiniModel;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * O destino de uma base nova está no `ModelsApiTest`; aqui prova-se o caminho de uma base que
 * já existia, que é o único que o semeador não percorre.
 */
final class CatalogMkgwMiniModelTest extends MysqlDashboardTestCase
{
    private const MODEL = 'MKGW-mini 03-20D';

    /** Uma base como estava antes: sem o modelo, e sem a migração aplicada. */
    private function rollBackToPreMigrationState(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM models WHERE internal_model = '" . self::MODEL . "'");
        $pdo->exec("DELETE FROM schema_migrations WHERE version = '2026_09_02_catalog_mkgw_mini_model'");
    }

    private function modelRows(PDO $pdo): int
    {
        return (int)$pdo->query("
            SELECT COUNT(*) FROM models m JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'MOKO' AND m.internal_model = '" . self::MODEL . "' AND m.device_type = 'gateway'
        ")->fetchColumn();
    }

    public function testItAddsTheModelToADatabaseThatAlreadyExisted(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->rollBackToPreMigrationState($pdo);
        self::assertSame(0, $this->modelRows($pdo), 'o estado inicial tem de ser o de antes');

        (new DatabaseMigrator($pdo))->migrate();

        self::assertSame(1, $this->modelRows($pdo));
    }

    /**
     * Herda as capacidades do MKGW3, e é só `connectivity`: uma tomada de 230 V não tem
     * bateria nem GPS, ao contrário do MKGW4.
     */
    public function testTheNewModelGetsTheGatewayCapabilities(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->rollBackToPreMigrationState($pdo);

        (new DatabaseMigrator($pdo))->migrate();

        $stmt = $pdo->prepare("
            SELECT c.capability_key
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            JOIN models m ON m.id = mc.model_id
            WHERE m.internal_model = ? AND mc.enabled = 1
        ");
        $stmt->execute([self::MODEL]);
        $keys = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        self::assertSame(['connectivity'], $keys);
    }

    /**
     * O `image_path` tem de apontar para um ficheiro que viaje no repositório, senão a
     * dashboard mostra o cartão com a imagem quebrada.
     */
    public function testTheModelCarriesAnImageThatTravelsWithTheRepository(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->rollBackToPreMigrationState($pdo);

        (new DatabaseMigrator($pdo))->migrate();

        $stmt = $pdo->prepare('SELECT image_path FROM models WHERE internal_model = ?');
        $stmt->execute([self::MODEL]);
        $path = (string)$stmt->fetchColumn();

        self::assertMatchesRegularExpression('#^/model-images/[a-f0-9]{32}\.jpg$#', $path);
        self::assertFileExists(
            __DIR__ . '/../../../../database/seed-model-images/' . basename($path)
        );
    }

    /** Correr outra vez não muda nada. */
    public function testItIsIdempotent(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->rollBackToPreMigrationState($pdo);

        (new DatabaseMigrator($pdo))->migrate();
        $pdo->exec("DELETE FROM schema_migrations WHERE version = '2026_09_02_catalog_mkgw_mini_model'");
        (new DatabaseMigrator($pdo))->migrate();

        self::assertSame(1, $this->modelRows($pdo));
    }

    /**
     * Numa base vazia a migração não pode escrever nada, senão a guarda do semeador de
     * referência salta e uma instalação de raiz nasce sem fornecedores nem modelos.
     */
    public function testItDoesNothingOnAnEmptyDatabaseSoTheBaselineSeederStillRuns(): void
    {
        $pdo = $this->pdoForDatabase($this->createEmptyDatabase());
        $pdo->exec((string)file_get_contents(__DIR__ . '/../../../../database/schema.sql'));

        (new CatalogMkgwMiniModel())->up($pdo);

        self::assertSame(
            0,
            (int)$pdo->query('SELECT COUNT(*) FROM models')->fetchColumn(),
            'a migração escreveu numa base vazia e vai fazer o semeador saltar'
        );
    }
}
