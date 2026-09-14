<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Http\CorsPolicy;
use PHPUnit\Framework\TestCase;
use React\Http\Message\Response;

/**
 * Que origens podem falar com a API a partir de um browser.
 *
 * O `*` de sempre continua a ser o valor por omissão, e é seguro enquanto a autenticação for
 * `Bearer` em cabeçalho: o browser não anexa credenciais sozinho, portanto não há CSRF a
 * partir de uma página de terceiros. O que faltava era essa decisão estar declarada em vez de
 * ser uma propriedade acidental de um ficheiro que ninguém abre.
 */
final class CorsPolicyTest extends TestCase
{
    public function testAnEmptyListKeepsTheOpenPolicy(): void
    {
        $response = (new CorsPolicy())->apply(new Response(200), self::from('https://qualquer.example'));

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $response->getHeaderLine('Vary'), 'sem restrição a resposta não depende da origem');
    }

    public function testAnExplicitStarAlsoKeepsTheOpenPolicy(): void
    {
        $response = (new CorsPolicy(['*']))->apply(new Response(200), self::from('https://qualquer.example'));

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testAnAllowedOriginIsReflectedBack(): void
    {
        $policy = new CorsPolicy(['https://hub.havicare.com', 'https://app.havicare.com']);

        $response = $policy->apply(new Response(200), self::from('https://app.havicare.com'));

        self::assertSame('https://app.havicare.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        // Sem isto, uma cache pelo meio servia a origem de outra pessoa.
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    /** Uma origem de fora não recebe autorização nenhuma para si. */
    public function testAnOriginOutsideTheListIsNotReflected(): void
    {
        $policy = new CorsPolicy(['https://hub.havicare.com']);

        $response = $policy->apply(new Response(200), self::from('https://intruso.example'));

        self::assertNotSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertNotSame('https://intruso.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    /** A barra final de uma origem não a torna outra. */
    public function testATrailingSlashDoesNotMakeItADifferentOrigin(): void
    {
        $policy = new CorsPolicy(['https://hub.havicare.com/']);

        $response = $policy->apply(new Response(200), self::from('https://hub.havicare.com'));

        self::assertSame('https://hub.havicare.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    private static function from(string $origin): ServerRequest
    {
        return new ServerRequest('GET', 'http://localhost:8081/api/devices', ['Origin' => $origin]);
    }
}
