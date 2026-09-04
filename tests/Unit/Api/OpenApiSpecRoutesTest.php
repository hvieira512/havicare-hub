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
     * Rotas que existem e não se documentam. O stream por aparelho serve a dashboard e não
     * tem o compromisso de estabilidade do resto da API; quem integra usa o `/api/stream`.
     *
     * @var list<string>
     */
    private const INTERNAL_ROUTES = [
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

    /**
     * O 401 e o 500 nascem no `ApiKernel` e não numa rota, e por isso escapavam às definições
     * -- um cliente gerado a partir do documento ficava sem ramo para o token expirado.
     */
    public function testEveryOperationDeclaresTheErrorsTheKernelCanReturn(): void
    {
        $missingUnauthorized = [];
        $missingServerError = [];

        foreach (OpenApiSpec::get()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (!is_array($operation) || !isset($operation['responses'])) {
                    continue;
                }

                $where = strtoupper((string)$method) . ' ' . $path;
                if (!isset($operation['responses']['500'])) {
                    $missingServerError[] = $where;
                }
                // O `security: []` da operação é o que marca as públicas, e essas não podem
                // responder 401 por falta de credencial porque não pedem nenhuma.
                if (($operation['security'] ?? null) !== [] && !isset($operation['responses']['401'])) {
                    $missingUnauthorized[] = $where;
                }
            }
        }

        self::assertSame([], $missingServerError, 'operações que podem responder 500 sem o dizer');
        self::assertSame([], $missingUnauthorized, 'operações autenticadas que podem responder 401 sem o dizer');
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
