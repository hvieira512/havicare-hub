<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A fotografia do dispensador M228.
 *
 * A imagem de um modelo é um caminho para um ficheiro em `var/dashboard/model-images`, que
 * está no gitignore: por isso esta migração copia o ficheiro além de escrever a linha. O
 * ficheiro viaja em `database/seed-model-images`, guardado como a dashboard guardaria um
 * upload -- JPEG de qualidade 78, no máximo 640 píxeis de lado, achatado sobre branco.
 */
final class PillDispenserImage implements Migration
{
    private const FILE = '464e9b90a30f30aee389cd9de5926977.jpg';

    public function version(): string
    {
        return '2026_09_22_pill_dispenser_image';
    }

    public function up(PDO $pdo): void
    {
        $this->copyImage();

        $stmt = $pdo->prepare("
            UPDATE models m
            JOIN suppliers s ON s.id = m.supplier_id
            SET m.image_path = ?
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228' AND m.image_path = ''
        ");
        // O nome e mais nada: a rota por onde a imagem se serve é constante e vive no código.
        $stmt->execute([self::FILE]);
    }

    /**
     * Sem apagar nem substituir: se o ficheiro já lá está, é o mesmo, porque o nome é o
     * resumo do conteúdo.
     */
    private function copyImage(): void
    {
        $source = __DIR__ . '/../../../../database/seed-model-images/' . self::FILE;
        $targetDir = __DIR__ . '/../../../../var/dashboard/model-images';
        $target = $targetDir . '/' . self::FILE;

        if (!is_file($source) || is_file($target)) {
            return;
        }
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return;
        }

        @copy($source, $target);
    }
}
