<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Tests\Support\MysqlDashboardTestCase;

/**
 * A linha na base e o ficheiro em `var/dashboard/model-images` são semeados por caminhos
 * diferentes, e quando um deles não corre a dashboard mostra uma imagem partida sem erro.
 */
final class ModelImagesAreOnDiskTest extends MysqlDashboardTestCase
{
    private const SOURCE = __DIR__ . '/../../../../database/seed-model-images';
    private const TARGET = __DIR__ . '/../../../../var/dashboard/model-images';

    public function testEveryModelImageIsInPlaceAfterSeeding(): void
    {
        $missing = [];
        foreach ($this->seededImages() as $model => $file) {
            if (!file_exists(self::TARGET . '/' . $file)) {
                $missing[] = "{$model} -> {$file}";
            }
        }

        self::assertSame([], $missing);
    }

    /** O ficheiro viaja no repositório, senão não há de onde o copiar no servidor. */
    public function testEveryModelImageTravelsWithTheRepository(): void
    {
        $wrong = [];
        foreach ($this->seededImages() as $model => $file) {
            $source = self::SOURCE . '/' . $file;
            if (!file_exists($source)) {
                $wrong[] = "{$model}: sem ficheiro em database/seed-model-images";
                continue;
            }
            if (mime_content_type($source) !== 'image/jpeg') {
                $wrong[] = "{$model}: não é JPEG";
            }
        }

        self::assertSame([], $wrong);
    }

    /**
     * O nome do ficheiro por modelo, numa base acabada de semear.
     *
     * @return array<string, string>
     */
    private function seededImages(): array
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $rows = $pdo->query("
            SELECT CONCAT(s.name, ' ', m.internal_model) AS model, m.image_path
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE m.image_path <> ''
        ")->fetchAll();

        $images = [];
        foreach ($rows as $row) {
            $images[(string)$row['model']] = (string)$row['image_path'];
        }

        // Sem modelos não há invariante nenhum a prender, e o teste passava por vazio.
        self::assertNotSame([], $images);

        return $images;
    }
}
