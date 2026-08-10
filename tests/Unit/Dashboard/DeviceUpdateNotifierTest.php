<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Dashboard\DeviceUpdateNotifier;
use PHPUnit\Framework\TestCase;

final class DeviceUpdateNotifierTest extends TestCase
{
    public function testOnlyListenersForTheChangedDeviceAreCalled(): void
    {
        $notifier = new DeviceUpdateNotifier();
        $calls = [];
        $notifier->subscribe('aaa', static function () use (&$calls): void {
            $calls[] = 'aaa';
        });
        $notifier->subscribe('bbb', static function () use (&$calls): void {
            $calls[] = 'bbb';
        });

        $notifier->notify('aaa');

        self::assertSame(['aaa'], $calls);
    }

    public function testDeviceKeysAreMatchedRegardlessOfCaseAndPadding(): void
    {
        $notifier = new DeviceUpdateNotifier();
        $calls = 0;
        $notifier->subscribe('FBD87C59BA8B', static function () use (&$calls): void {
            $calls++;
        });

        $notifier->notify('fbd87c59ba8b');
        $notifier->notify('  FbD87c59Ba8B  ');

        self::assertSame(2, $calls);
    }

    public function testEveryListenerOfADeviceIsCalled(): void
    {
        $notifier = new DeviceUpdateNotifier();
        $calls = 0;
        // Two browser tabs on the same device.
        $notifier->subscribe('aaa', static function () use (&$calls): void {
            $calls++;
        });
        $notifier->subscribe('aaa', static function () use (&$calls): void {
            $calls++;
        });

        $notifier->notify('aaa');

        self::assertSame(2, $calls);
    }

    public function testNotifyAllReachesEveryDevice(): void
    {
        $notifier = new DeviceUpdateNotifier();
        $calls = [];
        $notifier->subscribe('aaa', static function () use (&$calls): void {
            $calls[] = 'aaa';
        });
        $notifier->subscribe('bbb', static function () use (&$calls): void {
            $calls[] = 'bbb';
        });

        // Sweeps such as expiring commands do not name the devices they touch.
        $notifier->notifyAll();

        sort($calls);
        self::assertSame(['aaa', 'bbb'], $calls);
    }

    public function testUnsubscribingStopsDeliveryAndReleasesTheDevice(): void
    {
        $notifier = new DeviceUpdateNotifier();
        $calls = 0;
        $unsubscribe = $notifier->subscribe('aaa', static function () use (&$calls): void {
            $calls++;
        });

        $unsubscribe();
        $notifier->notify('aaa');
        $notifier->notifyAll();

        self::assertSame(0, $calls);
        // A closed stream must not leave its device behind, or the map grows
        // for the lifetime of the process.
        self::assertSame(0, $notifier->listenerCount());
    }

    public function testUnsubscribingOneListenerLeavesTheOther(): void
    {
        $notifier = new DeviceUpdateNotifier();
        $calls = 0;
        $first = $notifier->subscribe('aaa', static function () use (&$calls): void {
            $calls++;
        });
        $notifier->subscribe('aaa', static function () use (&$calls): void {
            $calls++;
        });

        $first();
        $notifier->notify('aaa');

        self::assertSame(1, $calls);
        self::assertSame(1, $notifier->listenerCount());
    }

    public function testNotifyingADeviceNobodyWatchesIsHarmless(): void
    {
        $notifier = new DeviceUpdateNotifier();

        $notifier->notify('nobody-is-watching');

        self::addToAssertionCount(1);
    }
}
