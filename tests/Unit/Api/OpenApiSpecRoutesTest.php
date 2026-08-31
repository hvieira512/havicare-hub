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

    /**
     * Rotas que existem e não se documentam, porque não são para ninguém de fora.
     *
     * O stream serve a dashboard e mais nada: é o `EventSource` de uma página que corre neste
     * mesmo servidor. Documentá-lo convidava a que se dependesse dele, e ele não tem o
     * compromisso de estabilidade que o resto da API tem -- a forma do que ali viaja mudou
     * quando passou a mandar diferenças em vez do histórico todo, e há-de voltar a mudar.
     *
     * O bilhete existe apenas para abrir esse stream, e por isso segue-o.
     *
     * @var list<string>
     */
    private const INTERNAL_ROUTES = [
        '/api/auth/stream-ticket',
        '/api/devices/{imei}/stream',
    ];

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

    /**
     * E o outro sentido da mesma regra: uma rota interna não pode reaparecer na especificação
     * sem que alguém repare. Sem isto, bastava alguém documentá-la de boa fé para ela voltar
     * a ser pública.
     */
    public function testInternalRoutesStayOutOfTheSpecification(): void
    {
        $documented = $this->documentedPaths();

        foreach (self::INTERNAL_ROUTES as $internal) {
            self::assertNotContains(
                $internal,
                $documented,
                "A rota `{$internal}` é interna à dashboard e não se documenta."
            );
        }
    }

    /** A lista de internas não pode ganhar rotas que já não existem. */
    public function testEveryInternalRouteStillExists(): void
    {
        $registered = [];
        foreach (glob(self::ROUTES_DIR . '/*.php') ?: [] as $file) {
            preg_match_all(
                "/new ApiRoute\(\s*'[A-Z]+'\s*,\s*'([^']+)'/",
                (string)file_get_contents($file),
                $matches
            );
            foreach ($matches[1] as $pattern) {
                $registered[] = preg_replace('/\{([A-Za-z]+):[^}]*\}/', '{$1}', $pattern);
            }
        }

        foreach (self::INTERNAL_ROUTES as $internal) {
            self::assertContains($internal, $registered, "A rota interna `{$internal}` já não existe.");
        }
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

        $paths = array_values(array_diff(array_unique($paths), self::INTERNAL_ROUTES));
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
