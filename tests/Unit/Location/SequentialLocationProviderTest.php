<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\CallbackLocationProvider;
use Hub\Location\LocationProviderException;
use Hub\Location\LocationResponseValidator;
use Hub\Location\SequentialLocationProvider;
use PHPUnit\Framework\TestCase;

use function React\Promise\reject;
use function React\Promise\resolve;

final class SequentialLocationProviderTest extends TestCase
{
    public function testKeepsTrustedPrimaryWithoutCallingFallback(): void
    {
        $fallbackCalls = 0;
        $provider = $this->provider(
            fn () => resolve($this->response(100, 'primary')),
            function () use (&$fallbackCalls) {
                $fallbackCalls++;
                return resolve($this->response(50, 'fallback'));
            },
        );
        $result = null;
        $provider->resolve([])->then(function ($value) use (&$result): void { $result = $value; });

        self::assertSame('primary', $result['provider']);
        self::assertSame(0, $fallbackCalls);
    }

    public function testFallsBackWhenPrimaryAccuracyIsUnacceptable(): void
    {
        $provider = $this->provider(
            fn () => resolve($this->response(800, 'primary')),
            fn () => resolve($this->response(120, 'fallback')),
        );
        $result = null;
        $provider->resolve([])->then(function ($value) use (&$result): void { $result = $value; });

        self::assertSame('fallback', $result['provider']);
    }

    /** @dataProvider fallbackStatusProvider */
    public function testFallsBackForRecoverablePrimaryFailures(?int $status): void
    {
        $provider = $this->provider(
            static fn () => reject(new LocationProviderException('primary failed', 'primary', $status, true)),
            fn () => resolve($this->response(120, 'fallback')),
        );
        $result = null;
        $provider->resolve([])->then(function ($value) use (&$result): void { $result = $value; });

        self::assertSame('fallback', $result['provider']);
    }

    public static function fallbackStatusProvider(): array
    {
        return [[null], [404], [429], [500], [503]];
    }

    public function testDoesNotFallbackForAuthenticationFailure(): void
    {
        $fallbackCalls = 0;
        $provider = $this->provider(
            static fn () => reject(new LocationProviderException('bad credentials', 'primary', 401, false)),
            function () use (&$fallbackCalls) {
                $fallbackCalls++;
                return resolve($this->response(120, 'fallback'));
            },
        );
        $failure = null;
        $provider->resolve([])->then(null, function ($error) use (&$failure): void { $failure = $error; });

        self::assertInstanceOf(LocationProviderException::class, $failure);
        self::assertSame(0, $fallbackCalls);
    }

    private function provider(callable $primary, callable $fallback): SequentialLocationProvider
    {
        return new SequentialLocationProvider(
            new CallbackLocationProvider($primary, 'primary'),
            new CallbackLocationProvider($fallback, 'fallback'),
            new LocationResponseValidator(500),
        );
    }

    private function response(float $accuracy, string $provider): array
    {
        return [
            'httpStatus' => 200,
            'body' => ['location' => ['lat' => 41.7, 'lng' => -8.8], 'accuracy' => $accuracy],
            'provider' => $provider,
        ];
    }
}
