<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Tests\Support\MysqlDashboardTestCase;

/**
 * O M228 tem fotografia, e o ficheiro dela existe.
 *
 * A imagem de um modelo é um caminho para um ficheiro em disco, e não um endereço para fora:
 * a dashboard serve-a de `var/dashboard/model-images`, que está no gitignore. Uma linha na
 * base a apontar para um ficheiro que não foi copiado dá uma imagem partida na dashboard e
 * nenhum erro em lado nenhum.
 */
final class PillDispenserImageTest extends MysqlDashboardTestCase
{
    private const FILE = '464e9b90a30f30aee389cd9de5926977.jpg';

    public function testTheModelHasAnImage(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();

        $path = $pdo->query("
            SELECT m.image_path
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ")->fetchColumn();

        // Só o nome: a rota por onde a imagem se serve é constante e vive no código.
        self::assertSame(self::FILE, $path);
    }

    /** O ficheiro viaja no repositório, senão nunca chega ao servidor. */
    public function testTheImageTravelsWithTheRepository(): void
    {
        $source = __DIR__ . '/../../../../database/seed-model-images/' . self::FILE;

        self::assertFileExists($source);
        self::assertSame('image/jpeg', (string)mime_content_type($source));
    }

    /** E a migração põe-no onde a dashboard o vai buscar. */
    public function testTheImageIsInPlaceAfterMigrating(): void
    {
        $this->createDashboardDatabase();

        self::assertFileExists(__DIR__ . '/../../../../var/dashboard/model-images/' . self::FILE);
    }
}
