<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use PDO;

/**
 * O inventário capturado do hub de produção: dispositivos, modelos, fornecedores, licenças e
 * as ligações aos gateways.
 *
 * Vive fora do plano de migrações de propósito. O `DatabaseMigrator` corre também no modelo
 * de base de dados que os testes de integração clonam com dados e tudo, por isso uma
 * migração que insere inventário faz cada teste começar com vinte e seis dispositivos -- e um
 * teste que conta quatro encontra vinte e nove. O esquema é migração; os dados de arranque
 * são um passo que só o arranque chama.
 *
 * As imagens dos modelos vivem em `var/`, que está no gitignore, por isso viajam em
 * `database/seed-model-images` e são copiadas para o lugar aqui.
 */
final class InventorySeeder
{
    private const SEED_FILE = __DIR__ . '/../../../database/seed.sql';
    private const IMAGE_SOURCE = __DIR__ . '/../../../database/seed-model-images';
    private const IMAGE_TARGET = __DIR__ . '/../../../var/dashboard/model-images';

    /**
     * Devolve false quando já havia inventário e não fez nada.
     *
     * O seed em si é idempotente -- resolve os ids por chave natural --, mas verificar antes
     * evita reescrever o que o painel possa ter mudado desde então.
     */
    public function seed(PDO $pdo): bool
    {
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

        $this->copyModelImages();

        return true;
    }

    private function hasInventory(PDO $pdo): bool
    {
        return (int)$pdo->query('SELECT COUNT(*) FROM whitelist')->fetchColumn() > 0;
    }

    private function copyModelImages(): void
    {
        if (!is_dir(self::IMAGE_SOURCE)) {
            return;
        }

        if (!is_dir(self::IMAGE_TARGET) && !mkdir(self::IMAGE_TARGET, 0o775, true) && !is_dir(self::IMAGE_TARGET)) {
            throw new \RuntimeException('could not create ' . self::IMAGE_TARGET);
        }

        foreach (glob(self::IMAGE_SOURCE . '/*.jpg') ?: [] as $image) {
            $target = self::IMAGE_TARGET . '/' . basename($image);
            // Nunca substituir uma imagem que o painel ja tenha trocado.
            if (!file_exists($target)) {
                copy($image, $target);
            }
        }
    }
}
