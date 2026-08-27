<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\ArrayProviderCircuitStateStore;
use Hub\Location\CallbackLocationProvider;
use Hub\Location\CircuitBreakingLocationProvider;
use Hub\Location\LocationProviderException;
use PHPUnit\Framework\TestCase;

use function React\Promise\reject;
use function React\Promise\resolve;

final class CircuitBreakingLocationProviderTest extends TestCase
{
    public function testOpensAfterConsecutiveRetryableFailures(): void
    {
        $calls = 0;
        $store = new ArrayProviderCircuitStateStore();
        $provider = new CircuitBreakingLocationProvider(
            new CallbackLocationProvider(function () use (&$calls) {
                $calls++;
                return reject(new LocationProviderException('timeout', 'test'));
            }, 'test'),
            $store,
            failureThreshold: 3,
            openSeconds: 60,
        );

        for ($i = 0; $i < 4; $i++) {
            $provider->resolve([])->then(null, static function (): void {
            });
        }

        self::assertSame(3, $calls);
        self::assertGreaterThan(time(), $store->get('test')['openUntil']);
    }

    public function testRateLimitOpensImmediatelyAndHonorsRetryAfter(): void
    {
        $calls = 0;
        $store = new ArrayProviderCircuitStateStore();
        $provider = new CircuitBreakingLocationProvider(
            new CallbackLocationProvider(function () use (&$calls) {
                $calls++;
                return reject(new LocationProviderException('rate limited', 'test', 429, true, 120));
            }, 'test'),
            $store,
        );

        $provider->resolve([])->then(null, static function (): void {
        });
        $provider->resolve([])->then(null, static function (): void {
        });

        self::assertSame(1, $calls);
        self::assertGreaterThanOrEqual(time() + 119, $store->get('test')['openUntil']);
    }

    public function testNonRetryableFailureDoesNotOpenCircuit(): void
    {
        $calls = 0;
        $store = new ArrayProviderCircuitStateStore();
        $provider = new CircuitBreakingLocationProvider(
            new CallbackLocationProvider(function () use (&$calls) {
                $calls++;
                return reject(new LocationProviderException('not found', 'test', 404, false));
            }, 'test'),
            $store,
            failureThreshold: 1,
        );

        $provider->resolve([])->then(null, static function (): void {
        });
        $provider->resolve([])->then(null, static function (): void {
        });

        self::assertSame(2, $calls);
        self::assertSame(0, $store->get('test')['openUntil']);
    }

    public function testSuccessResetsFailureCounter(): void
    {
        $calls = 0;
        $store = new ArrayProviderCircuitStateStore();
        $provider = new CircuitBreakingLocationProvider(
            new CallbackLocationProvider(function () use (&$calls) {
                $calls++;
                return $calls === 1
                    ? reject(new LocationProviderException('timeout', 'test'))
                    : resolve(['httpStatus' => 200, 'body' => []]);
            }, 'test'),
            $store,
        );

        $provider->resolve([])->then(null, static function (): void {
        });
        $provider->resolve([]);

        self::assertSame(['consecutiveFailures' => 0, 'openUntil' => 0], $store->get('test'));
    }
}
