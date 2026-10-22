<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\OpenApiSpec;
use PHPUnit\Framework\TestCase;

/** A especificação é mantida à mão, e isto prende-a às rotas nos dois sentidos. */
final class OpenApiSpecRoutesTest extends TestCase
{
    private const ROUTES_DIR = __DIR__ . '/../../../src/Api/Routes';

    /**
     * Rotas sem documentação: o stream por aparelho é da dashboard, e quem integra usa o `/api/stream`.
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
            'rotas que existem e faltam na especificação OpenAPI'
        );
        self::assertSame(
            [],
            array_values(array_diff($documented, $registered)),
            'caminhos documentados na especificação OpenAPI que nenhuma rota serve'
        );
    }

    /** Uma rota interna não pode reaparecer na especificação sem que alguém repare. */
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

    /** O 401 e o 500 nascem no kernel, e cada operação declara-os. */
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

    public function testOpenApiSpecAdvertisesBearerAuth(): void
    {
        $spec = OpenApiSpec::get();

        self::assertSame(['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Use the bearer token returned by /api/auth/login.']], $spec['components']['securitySchemes'] ?? null);
        self::assertSame([['bearerAuth' => []]], $spec['security'] ?? null);
        self::assertSame([], $spec['paths']['/api/auth/login']['post']['security'] ?? null);
        self::assertArrayNotHasKey('/api/auth/refresh', $spec['paths']);
        self::assertSame([], $spec['paths']['/api/openapi.json']['get']['security'] ?? null);
        self::assertSame([], $spec['paths']['/api/docs']['get']['security'] ?? null);
        self::assertArrayHasKey('/api/devices/{imei}/configurations', $spec['paths']);
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
