<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\Migration\WifiSosButtonImage;
use Tests\Support\MysqlDashboardTestCase;

/** A pulseira SOS Wi-Fi da PST ganha a fotografia nas bases que a tinham sem nenhuma. */
final class WifiSosButtonImageTest extends MysqlDashboardTestCase
{
    private const MODEL = "SELECT m.image_path FROM models m JOIN suppliers s ON s.id = m.supplier_id
        WHERE s.name = 'PST' AND m.internal_model = 'CF103W'";

    public function testTheModelWithoutImageGetsThePhoto(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("UPDATE models SET image_path = '' WHERE internal_model = 'CF103W'");

        (new WifiSosButtonImage())->up($pdo);

        self::assertSame('35256203f0a245d0440995dea4b84177.jpg', $pdo->query(self::MODEL)->fetchColumn());
        self::assertFileExists(dirname(__DIR__, 4) . '/database/seed-model-images/35256203f0a245d0440995dea4b84177.jpg');
    }

    /** Uma imagem carregada à mão na dashboard fica. */
    public function testAnImageAlreadyChosenStays(): void
    {
        $pdo = $this->createDashboardDatabase()->pdo();
        $pdo->exec("UPDATE models SET image_path = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg' WHERE internal_model = 'CF103W'");

        (new WifiSosButtonImage())->up($pdo);

        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg', $pdo->query(self::MODEL)->fetchColumn());
    }
}
