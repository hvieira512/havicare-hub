<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use PDO;

/**
 * O inventário capturado do hub de produção: dispositivos, modelos, fornecedores, licenças e
 * ligações aos gateways.
 *
 * Fora do plano de migrações de propósito: o `DatabaseMigrator` corre também na base-modelo
 * que os testes clonam, e uma migração que insere inventário fazia cada teste começar com
 * vinte e seis dispositivos. O esquema é migração; os dados de arranque são um passo à parte.
 *
 * As imagens dos modelos viajam em `database/seed-model-images` porque o `var/` está no
 * gitignore, e são copiadas para o lugar aqui.
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

    /**
     * Devolve false quando já havia inventário e não fez nada.
     *
     * O seed em si é idempotente -- resolve os ids por chave natural --, mas verificar antes
     * evita reescrever o que o painel possa ter mudado desde então.
     */
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
            // Nunca substituir uma imagem que o painel ja tenha trocado.
            if (!file_exists($target) && copy($image, $target)) {
                $copied++;
            }
        }

        return $copied;
    }
}
