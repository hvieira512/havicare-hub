<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\Http\ModelImageUrl;
use Hub\Api\Services\ModelImageStore;
use PHPUnit\Framework\TestCase;

/**
 * A base de dados guarda o nome do ficheiro, e a rota vive no código: o que é igual em todas
 * as linhas não se repete em cada uma.
 */
final class ModelImageNamingTest extends TestCase
{
    private const FILE = '464e9b90a30f30aee389cd9de5926977.jpg';

    /** @var list<string> */
    private array $plantedFiles = [];

    /** @var list<string> */
    private array $createdDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->plantedFiles as $path) {
            @unlink($path);
        }
        foreach (array_reverse($this->createdDirectories) as $directory) {
            @rmdir($directory);
        }
        $this->plantedFiles = [];
        $this->createdDirectories = [];
    }

    public function testTheUrlIsBuiltFromTheRouteAndTheFilename(): void
    {
        self::assertSame(
            'https://hub.havicare.com/model-images/' . self::FILE,
            (new ModelImageUrl())->resolve(self::FILE, 'https://hub.havicare.com'),
        );
    }

    public function testAModelWithoutAnImageHasNoUrl(): void
    {
        self::assertNull((new ModelImageUrl())->resolve('', 'https://hub.havicare.com'));
    }

    /** Um hub ainda por migrar tem o prefixo nas linhas, e as imagens não podem partir até lá. */
    public function testAStoredValueWithTheOldPrefixStillResolves(): void
    {
        self::assertSame(
            'https://hub.havicare.com/model-images/' . self::FILE,
            (new ModelImageUrl())->resolve('/model-images/' . self::FILE, 'https://hub.havicare.com'),
        );
    }

    /** E apagar recebe o nome do ficheiro, sem expressão regular a desmontar caminhos. */
    public function testDeleteAcceptsABareFilename(): void
    {
        $path = ModelImageStore::pathFor(self::FILE);
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, 'x');

        (new ModelImageStore())->delete(self::FILE);

        self::assertFileDoesNotExist($path);
    }

    /**
     * Cada nome recusado tem um isco no caminho que atingiria: sem ele, um `delete` que apagasse
     * tudo também não deixava rasto.
     */
    public function testDeleteIgnoresAnythingThatIsNotOneOfOurNames(): void
    {
        $store = new ModelImageStore();
        $decoys = [];
        foreach (['../../../etc/passwd', 'nao-e-um-hash.jpg', self::FILE . '/x'] as $name) {
            $decoys[$name] = $this->plantDecoy(ModelImageStore::pathFor($name));
        }

        // O nome vazio aponta para a própria pasta, e o isco dela é ela mesma.
        $directory = dirname(ModelImageStore::pathFor(self::FILE));
        $this->makeDirectory($directory);

        foreach ([...array_keys($decoys), ''] as $name) {
            $store->delete($name);
        }

        foreach ($decoys as $name => $path) {
            self::assertFileExists($path, "`{$name}` chegou ao disco");
        }
        self::assertDirectoryExists($directory, 'o nome vazio apontava para a pasta das imagens');
    }

    private function plantDecoy(string $path): string
    {
        $this->makeDirectory(dirname($path));
        file_put_contents($path, 'isco');
        $this->plantedFiles[] = $path;

        return $path;
    }

    /** Só as pastas que este teste criou é que ele larga no fim. */
    private function makeDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        $this->makeDirectory(dirname($directory));
        mkdir($directory, 0755);
        $this->createdDirectories[] = $directory;
    }
}
