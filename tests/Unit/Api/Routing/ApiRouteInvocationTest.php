<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Routing;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Request\CompanyWriteRequest;
use Hub\Api\Request\RadarCredentialsWriteRequest;
use Hub\Api\Routing\ApiRoute;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/** O handler recebe os parâmetros do caminho e depois o pedido, e pode declarar menos. */
final class ApiRouteInvocationTest extends TestCase
{
    public function testTheHandlerGetsTheParametersAndThenTheRequest(): void
    {
        $route = new ApiRoute(
            'GET',
            '/api/things/{imei}',
            static fn(array $params, ServerRequestInterface $request): string
                => $params['imei'] . '@' . $request->getMethod(),
        );

        self::assertSame('123@GET', $route->invoke(['imei' => '123'], self::request()));
    }

    public function testAHandlerThatDeclaresFewerArgumentsIgnoresTheRest(): void
    {
        $route = new ApiRoute('GET', '/api/things/{imei}', static fn(array $params): string => $params['imei']);

        self::assertSame('865028000000306', $route->invoke(['imei' => '865028000000306'], self::request()));
    }

    public function testARouteThatDeclaresItsRequestHandsTheHandlerTheBoundObject(): void
    {
        $route = new ApiRoute(
            'POST',
            '/api/companies',
            static fn(array $params, ServerRequestInterface $request, CompanyWriteRequest $input): string => $input->name,
            body: ApiRoute::JSON_BODY,
            request: CompanyWriteRequest::class,
        );

        $input = $route->bind([], ['name' => 'hitcare']);

        self::assertInstanceOf(CompanyWriteRequest::class, $input);
        self::assertSame('hitcare', $route->invoke([], self::request(), $input));
    }

    public function testABodyThatFailsItsRequestComesBackAsTheRefusal(): void
    {
        $route = new ApiRoute('POST', '/api/companies', static fn(): array => [], request: CompanyWriteRequest::class);

        $refusal = $route->bind([], []);

        self::assertSame(['name' => ['name is required']], is_array($refusal) ? $refusal['error']['fields'] : null);
    }

    /** Os grupos podem depender do recurso: as credenciais só exigem os segredos à primeira. */
    public function testTheValidationGroupsCanDependOnThePathParameters(): void
    {
        $route = new ApiRoute(
            'PUT',
            '/api/licenses/{id:\\d+}/radar-credentials',
            static fn(): array => [],
            request: RadarCredentialsWriteRequest::class,
            groups: static fn(array $params): array => $params['id'] === '1' ? [RadarCredentialsWriteRequest::GROUP_CREATE] : [],
        );
        $body = ['baseUrl' => 'https://radarconsole.com', 'username' => 'u', 'appId' => 'a'];

        self::assertIsArray($route->bind(['id' => '1'], $body));
        self::assertInstanceOf(RadarCredentialsWriteRequest::class, $route->bind(['id' => '2'], $body));
    }

    public function testARouteWithoutARequestBindsNothing(): void
    {
        self::assertNull((new ApiRoute('GET', '/api/things', static fn(): array => []))->bind([], ['x' => 1]));
    }

    private static function request(string $path = '/api/thing'): ServerRequest
    {
        return new ServerRequest('GET', 'http://localhost:8081' . $path);
    }
}
