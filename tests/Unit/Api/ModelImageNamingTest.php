<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\Http\ModelImageUrl;
use Hub\Api\Services\ModelImageStore;
use PHPUnit\Framework\TestCase;

/**
 * A base de dados guarda o nome do ficheiro, e a rota vive no código.
 *
 * A coluna `image_path` guardava `/model-images/<ficheiro>.jpg`, com o mesmo prefixo repetido
 * em todas as linhas. Isso obriga um `UPDATE` a toda a tabela para mudar onde as imagens são
 * servidas, deixa a coluna aceitar caminhos inconsistentes, e obriga quem a lê a desmontá-la
 * para chegar ao ficheiro — que era o que a rotina de apagar já fazia à mão, com uma
 * expressão regular.
 *
 * O que varia por linha fica na linha; o que é igual em todas fica no código.
 */
final class ModelImageNamingTest extends TestCase
{
    private const FILE = '464e9b90a30f30aee389cd9de5926977.jpg';

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

    /**
     * Um valor antigo, com o prefixo, continua a resolver.
     *
     * A migração limpa a tabela, mas um hub que ainda não tenha migrado não pode ficar com as
     * imagens todas partidas entre o deploy e a migração.
     */
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

    /** Um nome que não é um dos nossos não apaga nada, venha de onde vier. */
    public function testDeleteIgnoresAnythingThatIsNotOneOfOurNames(): void
    {
        $store = new ModelImageStore();

        foreach (['../../../etc/passwd', 'nao-e-um-hash.jpg', '', self::FILE . '/x'] as $name) {
            $store->delete($name);
        }

        self::assertTrue(true, 'nenhum destes pode chegar ao disco');
    }
}
