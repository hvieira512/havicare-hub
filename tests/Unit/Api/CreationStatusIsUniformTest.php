<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

/**
 * Criar um recurso devolve sempre `201`, declarado na rota: um handler que devolve uma
 * `Response` já traz o seu estado, e o da rota deixa de valer.
 */
final class CreationStatusIsUniformTest extends TestCase
{
    private const ROUTES_DIR = __DIR__ . '/../../../src/Api/Routes';

    /**
     * As rotas que criam um recurso endereçável. O critério é o método do serviço: um
     * `create()`. O `block()` da denylist e o `preview()` da descoberta são acções.
     *
     * @var list<string>
     */
    private const CREATIONS = [
        '/api/companies',
        '/api/devices',
        '/api/devices/{imei}/links/{linkedImei}',
        '/api/licenses',
        '/api/models',
        '/api/users',
    ];

    public function testEveryCreationDeclaresTwoHundredAndOne(): void
    {
        $declared = $this->postRoutesWithStatus();

        $wrong = [];
        foreach (self::CREATIONS as $path) {
            if (!array_key_exists($path, $declared)) {
                $wrong[] = "{$path}: não há rota POST para este caminho";
                continue;
            }
            if ($declared[$path] !== 201) {
                $wrong[] = "{$path}: devolve {$declared[$path]} e devia devolver 201";
            }
        }

        self::assertSame([], $wrong);
    }

    /** E o inverso: uma rota que não cria não anuncia que criou. */
    public function testNothingElseDeclaresTwoHundredAndOne(): void
    {
        $unexpected = [];
        foreach ($this->postRoutesWithStatus() as $path => $status) {
            if ($status === 201 && !in_array($path, self::CREATIONS, true)) {
                $unexpected[] = $path;
            }
        }

        self::assertSame([], $unexpected);
    }

    /**
     * O caminho de cada rota `POST` e o estado de sucesso que ela declara.
     *
     * @return array<string, int>
     */
    private function postRoutesWithStatus(): array
    {
        $routes = [];
        foreach (glob(self::ROUTES_DIR . '/*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            foreach (explode('new ApiRoute(', $source) as $index => $block) {
                if ($index === 0) {
                    continue;
                }
                $block = substr($block, 0, strpos($block . 'new ApiRoute(', 'new ApiRoute('));
                if (preg_match("/^\s*'POST'\s*,\s*'([^']+)'/", $block, $match) !== 1) {
                    continue;
                }
                $routes[$match[1]] = preg_match('/status:\s*(\d+)/', $block, $status) === 1
                    ? (int)$status[1]
                    : 200;
            }
        }

        self::assertGreaterThan(10, count($routes), 'a extracção de rotas POST encontrou poucas de mais');

        return $routes;
    }
}
