<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http\Middleware;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Http\CorsPolicy;
use Hub\Api\Http\Middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/** Onde a política do CORS se aplica na cadeia, e não que origens ela deixa passar. */
final class CorsMiddlewareTest extends TestCase
{
    /** O preflight responde aqui e não desce: é por isso que o `OPTIONS` não chega ao canal `api`. */
    public function testAPreflightIsAnsweredWithoutReachingTheNextHandler(): void
    {
        $reached = false;
        $next = static function (ServerRequestInterface $request) use (&$reached): ResponseInterface {
            $reached = true;

            return new Response(200);
        };

        $response = (new CorsMiddleware(new CorsPolicy()))(self::request('OPTIONS'), $next);

        self::assertFalse($reached, 'o preflight não pode descer a cadeia');
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    /** O método vem do fio e um browser não é obrigado a gritá-lo. */
    public function testAPreflightInLowercaseIsAlsoShortCircuited(): void
    {
        $reached = false;
        $next = static function (ServerRequestInterface $request) use (&$reached): ResponseInterface {
            $reached = true;

            return new Response(200);
        };

        $response = (new CorsMiddleware(new CorsPolicy()))(self::request('options'), $next);

        self::assertFalse($reached);
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function downstreamResponses(): array
    {
        return [
            'uma leitura da API' => [200, '/api/devices'],
            'um erro da API' => [500, '/api/devices'],
            'um recurso estático' => [200, '/assets/app.js'],
            'uma página que não existe' => [404, '/nao-existe'],
        ];
    }

    /**
     * Uma regra só, sem excepções por caminho: o estático leva os mesmos cabeçalhos que o `/api/`.
     *
     * @dataProvider downstreamResponses
     */
    public function testEveryResponseThatComesBackCarriesTheHeaders(int $status, string $path): void
    {
        $middleware = new CorsMiddleware(new CorsPolicy(['https://app.havicare.com']));

        $response = $middleware(
            self::request('GET', $path),
            static fn(ServerRequestInterface $request): ResponseInterface => new Response($status)
        );

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame($status, $response->getStatusCode(), 'a resposta de baixo não muda');
        self::assertSame('https://app.havicare.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    /** Uma rota que responde mais tarde leva os mesmos cabeçalhos quando a resposta chega. */
    public function testAPromisedResponseCarriesTheHeaders(): void
    {
        $returned = (new CorsMiddleware(new CorsPolicy()))(
            self::request('POST'),
            static fn(ServerRequestInterface $request): PromiseInterface => resolve(new Response(200))
        );

        self::assertInstanceOf(PromiseInterface::class, $returned);
        $response = null;
        $returned->then(static function (ResponseInterface $resolved) use (&$response): void {
            $response = $resolved;
        });
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    private static function request(string $method, string $path = '/api/devices'): ServerRequest
    {
        return new ServerRequest(
            $method,
            'http://localhost:8081' . $path,
            ['Origin' => 'https://app.havicare.com'],
        );
    }
}
