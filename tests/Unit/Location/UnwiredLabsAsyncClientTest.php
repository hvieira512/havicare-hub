<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\LocationProviderException;
use Hub\Location\UnwiredLabsAsyncClient;
use Hub\Location\UnwiredLabsRequestBuilder;
use PHPUnit\Framework\TestCase;
use React\Http\Browser;
use React\Http\Message\Response;

use function React\Promise\resolve;

final class UnwiredLabsAsyncClientTest extends TestCase
{
    public function testNormalizesSuccessfulResponse(): void
    {
        $client = new UnwiredLabsAsyncClient(
            $this->browserResponding(['status' => 'ok', 'lat' => 41.7, 'lon' => -8.8, 'accuracy' => 120]),
            new UnwiredLabsRequestBuilder(),
            'secret',
        );
        $result = null;
        $client->resolve($this->request())->then(function ($value) use (&$result): void { $result = $value; });

        self::assertSame('unwired_labs', $result['provider']);
        self::assertSame(41.7, $result['body']['location']['lat']);
        self::assertSame(-8.8, $result['body']['location']['lng']);
        self::assertSame(120, $result['body']['accuracy']);
    }

    /** @dataProvider degradedResponseProvider */
    public function testRejectsDegradedResponses(array $extra): void
    {
        $client = new UnwiredLabsAsyncClient(
            $this->browserResponding(['status' => 'ok', 'lat' => 41.7, 'lon' => -8.8, 'accuracy' => 120] + $extra),
            new UnwiredLabsRequestBuilder(),
            'secret',
        );
        $failure = null;
        $client->resolve($this->request())->then(null, function ($error) use (&$failure): void { $failure = $error; });

        self::assertInstanceOf(LocationProviderException::class, $failure);
        self::assertFalse($failure->retryable);
    }

    public static function degradedResponseProvider(): array
    {
        return [[['aged' => 91]], [['fallback' => 'lacf']], [['fallback' => ['cidf']]]];
    }

    public function testClassifiesBalanceExhaustionAsRateLimit(): void
    {
        $client = new UnwiredLabsAsyncClient(
            $this->browserResponding(['status' => 'error', 'message' => 'Token balance over; used all requests']),
            new UnwiredLabsRequestBuilder(),
            'secret',
        );
        $failure = null;
        $client->resolve($this->request())->then(null, function ($error) use (&$failure): void { $failure = $error; });

        self::assertInstanceOf(LocationProviderException::class, $failure);
        self::assertSame(429, $failure->httpStatus);
        self::assertTrue($failure->retryable);
        self::assertGreaterThan(0, $failure->retryAfterSeconds);
        self::assertLessThanOrEqual(86400, $failure->retryAfterSeconds);
    }

    private function browserResponding(array $body): Browser
    {
        $browser = $this->getMockBuilder(Browser::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withTimeout', 'withResponseBuffer', 'post'])
            ->getMock();
        $browser->method('withTimeout')->willReturnSelf();
        $browser->method('withResponseBuffer')->willReturnSelf();
        $browser->method('post')->willReturn(resolve(new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($body),
        )));
        return $browser;
    }

    private function request(): array
    {
        return ['wifiAccessPoints' => [
            ['macAddress' => '00:11:22:33:44:55'],
            ['macAddress' => '00:11:22:33:44:66'],
        ]];
    }
}
