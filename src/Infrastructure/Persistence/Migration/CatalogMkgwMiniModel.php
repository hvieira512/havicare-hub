<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * Acrescenta o gateway-tomada MOKO ao catálogo de uma base que já existia.
 *
 * O aparelho publica o mesmo JSON que o MKGW3 e por isso não traz protocolo novo; o que lhe
 * faltava era o modelo, sem o qual não há nada para lhe atribuir e a ingestão descarta o que
 * ele manda.
 *
 * É idempotente.
 */
final class CatalogMkgwMiniModel implements Migration
{
    private const MODEL = 'MKGW-mini 03-20D';
    private const IMAGE_FILE = 'a8b0f419d117411508270b342869add0.jpg';
    private const IMAGE_ROUTE = '/model-images/' . self::IMAGE_FILE;

    public function version(): string
    {
        return '2026_09_02_catalog_mkgw_mini_model';
    }

    public function up(PDO $pdo): void
    {
        // Numa base vazia não se escreve: as migrações correm antes do semeador, e o semeador
        // só corre com a tabela vazia. Uma linha aqui fazia uma instalação de raiz nascer sem
        // fornecedores nem modelos.
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $pdo->prepare("
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT s.id, ?, ?, 'gateway', ? FROM suppliers s WHERE s.name = 'MOKO'
        ")->execute([self::MODEL, 'MOKOSmart MKGW-mini 03-20D', self::IMAGE_ROUTE]);

        // Só quando está vazio: uma imagem trocada pelo painel não se substitui.
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
