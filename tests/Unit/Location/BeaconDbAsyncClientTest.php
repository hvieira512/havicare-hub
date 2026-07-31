<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\BeaconDbAsyncClient;
use Hub\Location\LocationProviderException;
use PHPUnit\Framework\TestCase;
use React\Http\Browser;
use React\Http\Message\Response;
use React\Http\Message\ResponseException;

use function React\Promise\reject;

final class BeaconDbAsyncClientTest extends TestCase
{
    public function testClassifiesRateLimitAndReadsRetryAfter(): void
    {
        $client = new BeaconDbAsyncClient($this->browserRejecting(
            new ResponseException(new Response(429, ['Retry-After' => '120']))
        ));
        $failure = null;
        $client->resolve(['considerIp' => false])->then(null, function ($error) use (&$failure): void {
            $failure = $error;
        });

        self::assertInstanceOf(LocationProviderException::class, $failure);
        self::assertSame(429, $failure->httpStatus);
        self::assertTrue($failure->retryable);
        self::assertSame(120, $failure->retryAfterSeconds);
    }

    public function testClassifiesNotFoundAsNonRetryable(): void
    {
        $client = new BeaconDbAsyncClient($this->browserRejecting(
            new ResponseException(new Response(404))
        ));
        $failure = null;
        $client->resolve(['considerIp' => false])->then(null, function ($error) use (&$failure): void {
            $failure = $error;
        });

        self::assertInstanceOf(LocationProviderException::class, $failure);
        self::assertSame(404, $failure->httpStatus);
        self::assertFalse($failure->retryable);
    }

    private function browserRejecting(\Throwable $error): Browser
    {
        $browser = $this->getMockBuilder(Browser::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withTimeout', 'withResponseBuffer', 'post'])
            ->getMock();
        $browser->method('withTimeout')->willReturnSelf();
        $browser->method('withResponseBuffer')->willReturnSelf();
        $browser->method('post')->willReturn(reject($error));

        return $browser;
    }
}
