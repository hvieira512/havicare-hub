<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * Dá a fotografia à pulseira Veepoo, e acrescenta-a onde ela ainda não exista.
 *
 * A MF91 entrou nas bases pelo painel e não pelos semeadores, e por isso ficou sem imagem: o
 * cartão do dispositivo e o catálogo mostravam o ícone genérico.
 *
 * É idempotente, e não substitui uma imagem escolhida no painel.
 */
final class CatalogMf91Model implements Migration
{
    private const MODEL = 'MF91';
    private const IMAGE_FILE = '4d62bc6aac04e660f06f1de7d0ab6a4f.jpg';
    private const IMAGE_ROUTE = '/model-images/' . self::IMAGE_FILE;

    public function version(): string
    {
        return '2026_09_11_catalog_mf91_model';
    }

    public function up(PDO $pdo): void
    {
        // Numa base vazia não se escreve: as migrações correm antes do semeador, e o semeador
        // só corre com a tabela vazia.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->prepare("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT s.id, ?, ?, 'bracelet', ? FROM suppliers s WHERE s.name = 'Wonlex'
        ")->execute([self::MODEL, self::MODEL, self::IMAGE_ROUTE]);

        $pdo->prepare('UPDATE models SET image_path = ? WHERE internal_model = ? AND image_path = ?')
            ->execute([self::IMAGE_ROUTE, self::MODEL, '']);

        $this->copyImage();

        (new ReferenceCatalogSeeder())->seedMissingModelCapabilities($pdo);
    }

    /**
     * O `var/` está no gitignore e o `make update` não corre o semeador de inventário, por isso
     * a imagem que viaja no repositório só chega ao lugar se a migração a levar.
     */
    private function copyImage(): void
    {
        $source = __DIR__ . '/../../../../database/seed-model-images/' . self::IMAGE_FILE;
        $targetDir = __DIR__ . '/../../../../var/dashboard/model-images';
        $target = $targetDir . '/' . self::IMAGE_FILE;

        if (!is_file($source) || is_file($target)) {
            return;
        }
        if (!is_dir($targetDir) && !mkdir($targetDir, 0o775, true) && !is_dir($targetDir)) {
            return;
        }

        copy($source, $target);
    }
}
