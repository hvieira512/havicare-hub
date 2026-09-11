<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\DatabaseMigrator;
use Hub\Infrastructure\Persistence\Migration\CatalogMf91Model;
use PDO;
use Tests\Support\MysqlDashboardTestCase;

/**
 * A pulseira Veepoo entrou nas bases que já existiam pelo painel, e por isso sem imagem; nas
 * novas não entra de todo, porque não está nos semeadores.
 */
final class CatalogMf91ModelTest extends MysqlDashboardTestCase
{
    private const MODEL = 'MF91';
    private const VERSION = '2026_09_11_catalog_mf91_model';

    /** A pulseira como o painel a deixou: sem imagem, e sem a migração aplicada. */
    private function braceletWithoutImage(PDO $pdo): void
    {
        $pdo->exec("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT s.id, '" . self::MODEL . "', '" . self::MODEL . "', 'bracelet', '' FROM suppliers s
            WHERE s.name = 'Wonlex'
        ");
        $pdo->exec("UPDATE models SET image_path = '' WHERE internal_model = '" . self::MODEL . "'");
        $pdo->exec("DELETE FROM schema_migrations WHERE version = '" . self::VERSION . "'");
    }

    private function imagePath(PDO $pdo): string
    {
        $stmt = $pdo->prepare("
            SELECT m.image_path FROM models m JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Wonlex' AND m.internal_model = ? AND m.device_type = 'bracelet'
        ");
        $stmt->execute([self::MODEL]);

        return (string)$stmt->fetchColumn();
    }

    private function braceletRows(PDO $pdo): int
    {
        return (int)$pdo
            ->query("SELECT COUNT(*) FROM models WHERE internal_model = '" . self::MODEL . "'")
            ->fetchColumn();
    }

    public function testItGivesTheBraceletItsImage(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->braceletWithoutImage($pdo);

        (new DatabaseMigrator($pdo))->migrate();

        self::assertMatchesRegularExpression('#^/model-images/[a-f0-9]{32}\.jpg$#', $this->imagePath($pdo));
    }

    /** A imagem tem de viajar no repositório, senão a dashboard mostra o cartão quebrado. */
    public function testTheImageTravelsWithTheRepository(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->braceletWithoutImage($pdo);

        (new DatabaseMigrator($pdo))->migrate();

        self::assertFileExists(
            __DIR__ . '/../../../../database/seed-model-images/' . basename($this->imagePath($pdo))
        );
    }

    /** Uma base que nunca teve a pulseira fica com ela, e com a imagem. */
    public function testItAddsTheBraceletWhenItIsMissing(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->braceletWithoutImage($pdo);
        $pdo->exec("DELETE FROM models WHERE internal_model = '" . self::MODEL . "'");

        (new DatabaseMigrator($pdo))->migrate();

        self::assertSame(1, $this->braceletRows($pdo));
        self::assertNotSame('', $this->imagePath($pdo));
    }

    /** Uma imagem trocada pelo painel é a que fica: a migração só preenche o vazio. */
    public function testItDoesNotOverwriteAnImageChosenInTheDashboard(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->braceletWithoutImage($pdo);
        $pdo->exec("
            UPDATE models SET image_path = '/model-images/outra.jpg'
            WHERE internal_model = '" . self::MODEL . "'
        ");

        (new DatabaseMigrator($pdo))->migrate();

        self::assertSame('/model-images/outra.jpg', $this->imagePath($pdo));
    }

    /** Correr outra vez não duplica a pulseira. */
    public function testItIsIdempotent(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $this->braceletWithoutImage($pdo);

        (new DatabaseMigrator($pdo))->migrate();
        $pdo->exec("DELETE FROM schema_migrations WHERE version = '" . self::VERSION . "'");
        (new DatabaseMigrator($pdo))->migrate();

        self::assertSame(1, $this->braceletRows($pdo));
    }

    /**
     * Numa base vazia a migração não pode escrever nada, senão a guarda do semeador de
     * referência salta e uma instalação de raiz nasce sem fornecedores nem modelos.
     */
    public function testItDoesNothingOnAnEmptyDatabaseSoTheBaselineSeederStillRuns(): void
    {
        $pdo = $this->pdoForDatabase($this->createEmptyDatabase());
        $pdo->exec((string)file_get_contents(__DIR__ . '/../../../../database/schema.sql'));

        (new CatalogMf91Model())->up($pdo);

        self::assertSame(
            0,
            (int)$pdo->query('SELECT COUNT(*) FROM models')->fetchColumn(),
            'a migração escreveu numa base vazia e vai fazer o semeador saltar'
        );
    }
}
