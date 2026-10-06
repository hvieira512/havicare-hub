<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use PDO;

/**
 * O inventário capturado da produção, fora das migrações porque os testes clonam a base
 * migrada. As imagens viajam em `database/seed-model-images`, porque o `var/` é ignorado.
 */
final class InventorySeeder
{
    private const SEED_FILE = __DIR__ . '/../../../database/seed.sql';
    private const IMAGE_SOURCE = __DIR__ . '/../../../database/seed-model-images';
    private const IMAGE_TARGET = __DIR__ . '/../../../var/dashboard/model-images';

    private string $imageSource;
    private string $imageTarget;

    public function __construct(?string $imageSource = null, ?string $imageTarget = null)
    {
        $this->imageSource = $imageSource ?? self::IMAGE_SOURCE;
        $this->imageTarget = $imageTarget ?? self::IMAGE_TARGET;
    }

    /** Devolve false quando já havia inventário, para não reescrever o que o painel mudou. */
    public function seed(PDO $pdo): bool
    {
        // Fora da guarda do inventário: as imagens são um passo idempotente, e prendê-las à
        // primeira semeadura deixa no repositório as que entraram depois dela.
        $this->copyMissingModelImages();

        if ($this->hasInventory($pdo)) {
            return false;
        }

        $seed = file_get_contents(self::SEED_FILE);
        if (!is_string($seed) || trim($seed) === '') {
            throw new \RuntimeException('database seed file is missing or empty');
        }

        $pdo->exec($seed);

        // Os modelos que o seed acrescenta entram depois de o catálogo ter sido semeado, logo
        // nada lhes deu um template e os cartões ficariam vazios.
        (new ReferenceCatalogSeeder())->seedMissingModelCapabilities($pdo);

        return true;
    }

    private function hasInventory(PDO $pdo): bool
    {
        return (int)$pdo->query('SELECT COUNT(*) FROM whitelist')->fetchColumn() > 0;
    }

    /** Devolve quantas copiou. */
    public function copyMissingModelImages(): int
    {
        if (!is_dir($this->imageSource)) {
            return 0;
        }

        if (!is_dir($this->imageTarget) && !mkdir($this->imageTarget, 0o775, true) && !is_dir($this->imageTarget)) {
            throw new \RuntimeException('could not create ' . $this->imageTarget);
        }

        $copied = 0;
        foreach (glob($this->imageSource . '/*.jpg') ?: [] as $image) {
            $target = $this->imageTarget . '/' . basename($image);
            // Nunca substituir uma imagem que o painel já tenha trocado.
            if (!file_exists($target) && copy($image, $target)) {
                $copied++;
            }
        }

        return $copied;
    }
}
