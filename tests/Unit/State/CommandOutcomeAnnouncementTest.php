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
        $this->store->deviceSeen(self::DEVICE, ['deviceType' => 'watch', 'online' => '1']);
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

    /** Um comando acabado não volta à vida, nem tem um segundo desfecho. */
    public function testAFinishedCommandStaysFinished(): void
    {
        $this->store->markCommand(self::DEVICE, 'c-1', ['status' => 'failed', 'error' => 'response_timeout']);
        $this->store->markCommand(self::DEVICE, 'c-1', ['status' => 'waiting', 'sentAt' => '2026-10-20T09:10:00Z']);
        $this->store->markCommand(self::DEVICE, 'c-1', ['status' => 'acked']);

        self::assertSame([['device.downlink.failed', 'c-1', 'response_timeout']], $this->announced);
        self::assertSame('failed', $this->store->commands(self::DEVICE)[0]['status']);
    }

    /** O `dropped` já saiu de quem tentou pôr na fila: a repetição regista-o, e não lhe junta um `failed`. */
    public function testARetryThatCannotBeQueuedEndsDropped(): void
    {
        $this->store->recordCommand(self::DEVICE, 'c-2', ['status' => 'queued', 'retryable' => true, 'nativeType' => 'BP84', 'bytes' => 'IWBP84#']);

        $this->store->retryWaitingCommands(60, 3600, 3, static fn (): string => 'dropped');

        self::assertSame('dropped', $this->store->findCommand('c-2')['command']['status'] ?? null);
        self::assertSame([], $this->announced);
    }

    /** Por gateway, cada entrega sem resposta conta como tentativa: à terceira, acaba. */
    public function testAGatewayCommandWithoutReplyRunsOutOfAttempts(): void
    {
        $this->store->recordCommand(self::DEVICE, 'c-3', [
            'status' => 'waiting', 'retryable' => true, 'nativeType' => 'weightUnit', 'bytes' => 'weightUnit',
            'attempts' => 1, 'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'), 'nextRetryAt' => '2000-01-01T00:00:00Z',
        ]);

        for ($cycle = 0; $cycle < 5; $cycle++) {
            $this->store->retryWaitingCommands(60, 3600, 3, static fn (): string => 'queued');
            $this->store->markCommand(self::DEVICE, 'c-3', ['status' => 'waiting', 'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'), 'nextRetryAt' => '2000-01-01T00:00:00Z']);
        }

        self::assertSame(['failed', 'retry_exhausted'], [$this->store->findCommand('c-3')['command']['status'] ?? null, $this->store->findCommand('c-3')['command']['error'] ?? null]);
    }

    /** A lista antiga que substitui a nova sai com o seu próprio id, e não com o do comando que substitui. */
    public function testTheLegacyPhonebookLeavesWithItsOwnId(): void
    {
        $contact = ['nativeType' => 'PHBX2', 'protocol' => 'four-p-touch', 'nativeKey' => 'phonebook', 'status' => 'failed', 'error' => 'response_timeout',
            'payload' => ['fields' => ['1', '004800750067006F', '+351938854803']], 'bytes' => '[3G*1234567890*0010*PHBX2,1]'];
        $this->store->recordCommand(self::DEVICE, 'old', $contact);
        $sentWith = [];

        $this->store->retryWaitingCommands(60, 3600, 3, static function (string $imei, string $bytes, array $command) use (&$sentWith): string {
            $sentWith[] = (string)($command['id'] ?? '');
            return 'dropped';
        });

        $legacy = array_values(array_filter($this->store->commands(self::DEVICE), static fn (array $command): bool => in_array($command['nativeType'] ?? '', ['PHB', 'PHB2'], true)));
        self::assertSame(array_column($legacy, 'id'), $sentWith);
        self::assertSame(['dropped'], array_values(array_unique(array_column($legacy, 'status'))));
    }
}
