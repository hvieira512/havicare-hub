<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Persistence;

use Hub\Infrastructure\Persistence\InventorySeeder;
use PHPUnit\Framework\TestCase;

/**
 * As imagens dos modelos viajam em `database/seed-model-images` porque o `var/` está no
 * gitignore, e é o seeder que as põe onde o painel as serve. Numa instalação que já tem
 * inventário o seeder não semeia -- mas as imagens que entraram no repositório entretanto
 * continuam a ter de chegar ao destino.
 */
final class InventorySeederImagesTest extends TestCase
{
    private string $source = '';
    private string $target = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/inventory-seeder-' . bin2hex(random_bytes(6));
        $this->source = $base . '/seed-model-images';
        $this->target = $base . '/model-images';
        mkdir($this->source, 0o775, true);
        mkdir($this->target, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->source, $this->target] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        $base = dirname($this->source);
        if (is_dir($base)) {
            rmdir($base);
        }
    }

    public function testCopiesMissingImagesEvenWhenTheInventoryIsAlreadyThere(): void
    {
        file_put_contents($this->source . '/nova.jpg', 'imagem nova');

        (new InventorySeeder($this->source, $this->target))->seed($this->databaseWithInventory());

        $this->assertFileExists(
            $this->target . '/nova.jpg',
            'Uma imagem que entrou no repositório depois da primeira semeadura nunca chega ao painel.',
        );
    }

    public function testNeverOverwritesAnImageThatIsAlreadyInPlace(): void
    {
        file_put_contents($this->source . '/trocada.jpg', 'a do repositório');
        file_put_contents($this->target . '/trocada.jpg', 'a que o painel carregou');

        (new InventorySeeder($this->source, $this->target))->seed($this->databaseWithInventory());

        $this->assertSame('a que o painel carregou', file_get_contents($this->target . '/trocada.jpg'));
    }

    public function testSaysItDidNotSeedWhenTheInventoryIsAlreadyThere(): void
    {
        $this->assertFalse((new InventorySeeder($this->source, $this->target))->seed($this->databaseWithInventory()));
    }

    /** Uma base com a `whitelist` preenchida: é o que o seeder lê para decidir que já há inventário. */
    private function databaseWithInventory(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE whitelist (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO whitelist (id) VALUES (1)');

        return $pdo;
    }
}
