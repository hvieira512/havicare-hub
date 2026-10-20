<?php

declare(strict_types=1);

namespace Tests\Unit\State;

use Hub\State\DeviceStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/** O desfecho de um comando sai uma vez, quando o estado dele muda. */
final class CommandOutcomeAnnouncementTest extends TestCase
{
    private const DEVICE = '861728087056333';

    /** @var list<array{string, string, string}> */
    private array $announced = [];

    private DeviceStore $store;

    protected function setUp(): void
    {
        $this->store = new DeviceStore(new InMemoryRedisClient());
        $this->store->announceCommandsTo(function (string $imei, string $type, array $command): void {
            $this->announced[] = [$type, (string)$command['id'], (string)($command['error'] ?? '')];
        });
        $this->store->recordCommand(self::DEVICE, 'c-1', ['status' => 'waiting', 'nativeType' => 'BP84', 'sentAt' => '2026-10-20T09:00:00Z']);
    }

    public function testAnAckIsAnnouncedOnce(): void
    {
        $this->store->markCommand(self::DEVICE, 'c-1', ['status' => 'acked', 'ackedAt' => '2026-10-20T09:00:05Z']);
        $this->store->markCommand(self::DEVICE, 'c-1', ['status' => 'acked']);

        self::assertSame([['device.downlink.acked', 'c-1', '']], $this->announced);
    }

    public function testAFailureCarriesItsReason(): void
    {
        $this->store->markCommand(self::DEVICE, 'c-1', ['status' => 'failed', 'error' => 'retry_exhausted']);

        self::assertSame([['device.downlink.failed', 'c-1', 'retry_exhausted']], $this->announced);
    }

    public function testASupersededCommandIsAnnounced(): void
    {
        $this->store->markCommand(self::DEVICE, 'c-1', ['status' => 'superseded']);

        self::assertSame([['device.downlink.superseded', 'c-1', '']], $this->announced);
    }

    /** Quem entrega por gateway ou pela cloud diz que a ordem saiu; cada tentativa é um envio. */
    public function testASendThroughAGatewayIsAnnouncedOnEveryAttempt(): void
    {
        $this->store->markCommand(self::DEVICE, 'c-1', ['status' => 'waiting', 'sentAt' => '2026-10-20T09:00:40Z', 'announceSent' => true]);
        $this->store->markCommand(self::DEVICE, 'c-1', ['status' => 'waiting', 'sentAt' => '2026-10-20T09:01:20Z', 'announceSent' => true]);

        self::assertSame([['device.downlink.sent', 'c-1', ''], ['device.downlink.sent', 'c-1', '']], $this->announced);
        self::assertArrayNotHasKey('announceSent', $this->store->commands(self::DEVICE)[0]);
    }

    public function testStayingInFlightIsNotAnnounced(): void
    {
        $this->store->markCommand(self::DEVICE, 'c-1', ['status' => 'waiting', 'attempts' => 2]);

        self::assertSame([], $this->announced);
    }
}
