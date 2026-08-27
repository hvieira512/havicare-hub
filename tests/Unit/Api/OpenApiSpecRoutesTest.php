<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\OpenApiSpec;
use PHPUnit\Framework\TestCase;

/**
 * A especificação é mantida à mão, e por isso nada a impede de divergir das rotas que
 * documenta. Isto prende as duas: uma rota nova fica indocumentada até aparecer na
 * especificação, e uma removida não pode ficar lá a arrastar-se.
 */
final class OpenApiSpecRoutesTest extends TestCase
{
    private const ROUTES_DIR = __DIR__ . '/../../../src/Api/Routes';

    public function testEveryRegisteredRouteIsDocumentedAndViceVersa(): void
    {
        $registered = $this->registeredPaths();
        $documented = $this->documentedPaths();

        self::assertSame(
            [],
            array_values(array_diff($registered, $documented)),
            'routes that exist but are missing from the OpenAPI spec'
        );
        self::assertSame(
            [],
            array_values(array_diff($documented, $registered)),
            'paths documented in the OpenAPI spec that no route serves'
        );
    }

    /** @return list<string> */
    private function registeredPaths(): array
    {
        $paths = [];
        foreach (glob(self::ROUTES_DIR . '/*.php') ?: [] as $file) {
            preg_match_all(
                "/new ApiRoute\(\s*'[A-Z]+'\s*,\s*'([^']+)'/",
                (string)file_get_contents($file),
                $matches
            );
            foreach ($matches[1] as $pattern) {
                // Para a especificação, `/api/models/{id:\d+}` e `/api/models/{id}` são a
                // mesma rota.
                $paths[] = preg_replace('/\{([A-Za-z]+):[^}]*\}/', '{$1}', $pattern);
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        // Se o padrão acima deixar de casar, as duas diferenças ficam vazias e o teste passa
        // sem verificar nada.
        self::assertGreaterThan(20, count($paths), 'route extraction found suspiciously few routes');

        return $paths;
    }

    /** @return list<string> */
    private function documentedPaths(): array
    {
        $paths = array_keys(OpenApiSpec::get()['paths'] ?? []);
        sort($paths);

        return array_map(strval(...), $paths);
    }
}
