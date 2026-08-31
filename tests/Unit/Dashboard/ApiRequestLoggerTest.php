<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Http\Middleware\ApiLogContext;
use Hub\Api\Http\Middleware\ApiRequestLogger;
use Hub\Log\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class ApiRequestLoggerTest extends TestCase
{
    private string $logPath;
    private string|false $originalLogFile;
    private string|false $originalLogFileApi;
    private string|false $originalLogLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLogFile = getenv('LOG_FILE');
        $this->originalLogFileApi = getenv('LOG_FILE_API');
        $this->originalLogLevel = getenv('LOG_LEVEL');
        $this->logPath = sys_get_temp_dir() . '/hub-api-logger-' . bin2hex(random_bytes(4)) . '.log';
        putenv('LOG_FILE=' . $this->logPath);
        putenv('LOG_FILE_API=' . $this->logPath);
        putenv('LOG_LEVEL=info');
        Logger::reset();
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
        putenv($this->originalLogFile === false ? 'LOG_FILE' : 'LOG_FILE=' . $this->originalLogFile);
        putenv($this->originalLogFileApi === false ? 'LOG_FILE_API' : 'LOG_FILE_API=' . $this->originalLogFileApi);
        putenv($this->originalLogLevel === false ? 'LOG_LEVEL' : 'LOG_LEVEL=' . $this->originalLogLevel);
        Logger::reset();
        parent::tearDown();
    }

    /**
     * A dashboard serve o JS e o CSS inteiros a cada carregamento. Registá-los enchia o
     * ficheiro do canal `api` sem nada em troca, e é isto que cai se o filtro desaparecer.
     */
    public function testOnlyApiPathsReachTheApiChannel(): void
    {
        $logger = new ApiRequestLogger();

        foreach (['/', '/dashboard', '/main.js', '/assets/logo.svg', '/model-images/abc.jpg'] as $path) {
            $logger(new ServerRequest('GET', $path), static fn(): Response => new Response(200));
        }

        self::assertSame('', $this->logContents());

        $logger(new ServerRequest('GET', '/api/devices'), static fn(): Response => new Response(200));

        self::assertStringContainsString('API request completed', $this->logContents());
    }

    public function testRecordCarriesTheKernelFieldsAndRedactsBodyAndQuery(): void
    {
        $logger = new ApiRequestLogger();
        $request = new ServerRequest(
            'POST',
            '/api/auth/login?access_token=t0ps3cret&company=hitcare',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'admin', 'password' => 'secret'], JSON_THROW_ON_ERROR)
        );

        $response = $logger($request, static function (ServerRequestInterface $inner): Response {
            $context = $inner->getAttribute(ApiLogContext::ATTRIBUTE);
            self::assertInstanceOf(ApiLogContext::class, $context);
            $context->describe(
                '/api/auth/login',
                new ApiAuthContext(1, 'admin', ApiAuthContext::ROLE_LICENSE_CLIENT, 1001),
                'bearer'
            );

            return new Response(200, ['Content-Type' => 'application/json'], '{"token":{"access_token":"abc"}}');
        });

        self::assertInstanceOf(Response::class, $response);
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));

        $log = $this->logContents();
        self::assertStringContainsString('"route":"/api/auth/login"', $log);
        self::assertStringContainsString('"auth_state":"bearer"', $log);
        self::assertStringContainsString('"username":"admin"', $log);
        self::assertStringContainsString('"role":"license_client"', $log);
        self::assertStringContainsString('"license_id":1001', $log);
        self::assertStringContainsString('"query":"access_token=********&company=hitcare"', $log);
        self::assertStringContainsString('"password":"********"', $log);
        self::assertStringContainsString('"access_token":"********"', $log);
        self::assertStringNotContainsString('secret', $log);
    }

    public function testLevelFollowsTheStatusAndTheRequestIdIsPropagated(): void
    {
        $logger = new ApiRequestLogger();

        foreach ([200 => 'api.INFO', 404 => 'api.WARNING', 500 => 'api.ERROR'] as $status => $expected) {
            $logger(
                new ServerRequest('GET', '/api/devices', ['X-Request-Id' => 'req-' . $status]),
                static fn(): Response => new Response($status)
            );
            self::assertStringContainsString($expected, $this->logContents());
            self::assertStringContainsString('"request_id":"req-' . $status . '"', $this->logContents());
        }
    }

    // Uma falha a registar não pode derrubar um pedido que já foi respondido.
    public function testLoggingFailureDoesNotFailTheRequest(): void
    {
        $logger = new ApiRequestLogger();
        $exploding = new class ('GET', '/api/devices') extends ServerRequest {
            public function getServerParams(): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $response = $logger($exploding, static fn(): Response => new Response(200));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Failed to log API request completion', $this->logContents());
    }

    private function logContents(): string
    {
        clearstatcache(true, $this->logPath);
        return is_file($this->logPath) ? (string)file_get_contents($this->logPath) : '';
    }
}
