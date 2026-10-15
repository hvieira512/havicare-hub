<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\UniqueScalesImages;
use Tests\Support\MysqlDashboardTestCase;

/** As balanças da Unique Scales ganham a fotografia nas bases que as tinham sem nenhuma. */
final class UniqueScalesImagesTest extends MysqlDashboardTestCase
{
    private const MODEL = "SELECT m.image_path FROM models m JOIN suppliers s ON s.id = m.supplier_id
        WHERE s.name = 'Unique Scales' AND m.internal_model = ?";

    public function testEachScaleWithoutImageGetsItsPhoto(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("UPDATE models SET image_path = '' WHERE internal_model IN ('HC-CF632BLE', 'HC-CF621BLE')");

        (new UniqueScalesImages())->up($pdo);

        foreach (['HC-CF632BLE' => '50754bfc972b5d94a5af67695b01fffb.jpg', 'HC-CF621BLE' => '1d024ba99d692a5fefbc8dad0dce563d.jpg'] as $model => $image) {
            $stmt = $pdo->prepare(self::MODEL);
            $stmt->execute([$model]);
            self::assertSame($image, $stmt->fetchColumn());
            self::assertFileExists(dirname(__DIR__, 4) . '/database/seed-model-images/' . $image);
        }
    }

    /** Uma imagem carregada à mão na dashboard fica. */
    public function testAnImageAlreadyChosenStays(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("UPDATE models SET image_path = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg' WHERE internal_model = 'HC-CF632BLE'");

        (new UniqueScalesImages())->up($pdo);

        $stmt = $pdo->prepare(self::MODEL);
        $stmt->execute(['HC-CF632BLE']);
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg', $stmt->fetchColumn());
    }
}
