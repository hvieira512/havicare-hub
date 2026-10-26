<?php

declare(strict_types=1);

namespace Tests\Unit\State;

use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/** Um comando à espera de um aparelho que não volta desiste ao fim de sete dias, em vez de ficar pendente para sempre. */
final class QueuedCommandLifetimeTest extends TestCase
{
    private const IMEI = '861265061009830';

    public function testAQueuedCommandOlderThanSevenDaysFails(): void
    {
        $store = $this->storeWithQueuedCommand(8 * 86400);

        $store->expireWaitingCommands(3600);

        $command = $store->commands(self::IMEI)[0];
        self::assertSame('failed', $command['status']);
        self::assertSame('retry_exhausted', $command['error']);
    }

    public function testAQueuedCommandYoungerThanSevenDaysKeepsWaiting(): void
    {
        $store = $this->storeWithQueuedCommand(6 * 86400);

        $store->expireWaitingCommands(3600);

        self::assertSame('queued', $store->commands(self::IMEI)[0]['status']);
    }

    private function storeWithQueuedCommand(int $ageSeconds): DeviceStore
    {
        $store = new DeviceStore(new InMemoryRedisClient());
        $store->runtime->registerDevice(self::IMEI, 'Vivistar', 'VIVISTAR-CARE');
        $store->recordCommand(self::IMEI, 'cmd-1', [
            'status' => 'queued',
            'retryable' => true,
            'protocol' => 'vivistar-iw',
            'nativeType' => 'BP14',
            'bytes' => 'IWBP14,861265061009830,080835#',
            'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - $ageSeconds),
            'lastAttemptAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 60),
        ]);

        return $store;
    }
}
