<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\CallbackLocationProvider;
use Hub\Location\ConcurrentLocationProvider;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;

final class ConcurrentLocationProviderTest extends TestCase
{
    public function testQueuesRequestsAboveConcurrencyLimit(): void
    {
        $calls = 0;
        $deferred = [];
        $provider = new ConcurrentLocationProvider(
            new CallbackLocationProvider(function () use (&$calls, &$deferred) {
                $calls++;
                $current = new Deferred();
                $deferred[] = $current;
                return $current->promise();
            }),
            maxConcurrent: 2,
            maxQueue: 2,
        );

        $results = [];
        $provider->resolve(['id' => 1])->then(function ($value) use (&$results): void {
            $results[] = $value;
        });
        $provider->resolve(['id' => 2])->then(function ($value) use (&$results): void {
            $results[] = $value;
        });
        $provider->resolve(['id' => 3])->then(function ($value) use (&$results): void {
            $results[] = $value;
        });
        self::assertSame(2, $calls);

        $deferred[0]->resolve(['httpStatus' => 200, 'body' => ['id' => 1]]);
        self::assertSame(3, $calls);
        $deferred[1]->resolve(['httpStatus' => 200, 'body' => ['id' => 2]]);
        $deferred[2]->resolve(['httpStatus' => 200, 'body' => ['id' => 3]]);

        self::assertCount(3, $results);
    }

    public function testRejectsWhenQueueIsFull(): void
    {
        $deferred = new Deferred();
        $provider = new ConcurrentLocationProvider(
            new CallbackLocationProvider(static fn () => $deferred->promise()),
            maxConcurrent: 1,
            maxQueue: 1,
        );
        $provider->resolve(['id' => 1]);
        $provider->resolve(['id' => 2]);
        $rejected = false;
        $provider->resolve(['id' => 3])->then(null, function () use (&$rejected): void {
            $rejected = true;
        });

        self::assertTrue($rejected);
    }
}
