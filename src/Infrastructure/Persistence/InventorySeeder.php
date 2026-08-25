<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use PDO;

/**
 * O inventario capturado do hub de producao: dispositivos, modelos, fornecedores, licencas
 * e as ligacoes aos gateways.
 *
 * Vive fora do plano de migracoes de proposito. O `DatabaseMigrator` corre tambem no
 * modelo de base de dados que os testes de integracao clonam com dados e tudo, por isso
 * uma migracao que insere inventario faz cada teste comecar com vinte e seis dispositivos
 * -- e um teste que conta quatro encontra vinte e nove. O esquema e migracao; os dados de
 * arranque sao um passo que so o arranque chama.
 *
 * As imagens dos modelos vivem em var/, que esta no gitignore, por isso viajam em
 * database/seed-model-images e sao copiadas para o lugar aqui.
 */
final class InventorySeeder
{
    private const SEED_FILE = __DIR__ . '/../../../database/seed.sql';
    private const IMAGE_SOURCE = __DIR__ . '/../../../database/seed-model-images';
    private const IMAGE_TARGET = __DIR__ . '/../../../var/dashboard/model-images';

    /**
     * Devolve false quando ja havia inventario e nao fez nada.
     *
     * O seed em si e idempotente -- resolve os ids por chave natural -- mas verificar
     * antes evita reescrever o que o painel possa ter mudado desde entao.
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

        // Os modelos que o seed acrescenta entram depois de as migracoes de capacidades
        // terem corrido, logo nada lhes deu um template e os cartoes ficariam vazios.
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
