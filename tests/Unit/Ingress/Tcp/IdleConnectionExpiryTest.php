<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tcp;

use Hub\Ingress\Tcp\DeviceHubServer;
use Hub\Ingress\Tcp\Wonlex\WonlexAdapter;
use Hub\Ingress\Tcp\Zayata\ZayataAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeConnection;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Uma sessão TCP cai ao fim de dois heartbeats falhados do protocolo dela, mais 30 s de margem. */
final class IdleConnectionExpiryTest extends TestCase
{
    private int $now = 1_000;

    /** @return iterable<string, array{string, string, int}> */
    public static function protocols(): iterable
    {
        yield 'wonlex' => ['868705080300697', (new WonlexAdapter())->encodeOutgoing(['type' => 'login', 'imei' => '868705080300697']), 300];
        yield 'four p touch' => ['637507597567372', '[3G*7597567372*000D*LK,50,100,100]', 300];
        yield 'vivistar' => ['865028000000308', 'IWAP00865028000000308#', 480];
        yield 'pill dispenser' => ['AABBCCDDEEFF', (new ZayataAdapter())->encodeOutgoing(['packetType' => 0x01, 'serial' => 1, 'mac' => 'AABBCCDDEEFF']), 60];
    }

    #[DataProvider('protocols')]
    public function testASessionSilentForTwoHeartbeatsAndTheGraceGoesOffline(string $imei, string $login, int $heartbeat): void
    {
        [$hub, $mqtt, $connection] = $this->connected($login);

        $this->now += 2 * $heartbeat + 30;
        $hub->expireIdleConnections();

        self::assertTrue($connection->closed);
        self::assertSame('offline', $this->lastState($mqtt, $imei));
    }

    #[DataProvider('protocols')]
    public function testASessionSilentForLessStaysOnline(string $imei, string $login, int $heartbeat): void
    {
        [$hub, $mqtt, $connection] = $this->connected($login);

        $this->now += 2 * $heartbeat - 1;
        $hub->expireIdleConnections();

        self::assertFalse($connection->closed);
        self::assertSame('online', $this->lastState($mqtt, $imei));
    }

    public function testAConfirmedPowerSavingModeStretchesTheVivistarInterval(): void
    {
        $configurations = ['working_mode' => ['mode' => 'power_saving']];
        [$hub, $mqtt, $connection] = $this->connected('IWAP00865028000000308#', $configurations);

        $this->now += 2 * 480 + 30;
        $hub->expireIdleConnections();
        self::assertFalse($connection->closed, 'o modo de poupança só reporta de hora a hora');

        $this->now += 2 * 3600 - 2 * 480;
        $hub->expireIdleConnections();
        self::assertTrue($connection->closed);
        self::assertSame('offline', $this->lastState($mqtt, '865028000000308'));
    }

    public function testAConfirmedCustomIntervalStretchesTheVivistarInterval(): void
    {
        $configurations = ['working_mode' => ['mode' => 'custom', 'intervalSeconds' => 900, 'gpsEnabled' => true]];
        [$hub, , $connection] = $this->connected('IWAP00865028000000308#', $configurations);

        $this->now += 2 * 900 + 29;
        $hub->expireIdleConnections();
        self::assertFalse($connection->closed);

        $this->now += 1;
        $hub->expireIdleConnections();
        self::assertTrue($connection->closed);
    }

    /**
     * @param array<string, mixed> $configurations
     * @return array{DeviceHubServer, RecordingHubMqttBridge, FakeConnection}
     */
    private function connected(string $login, array $configurations = []): array
    {
        $mqtt = new RecordingHubMqttBridge();
        $hub = new DeviceHubServer(
            IngressFixtures::whitelist([
                '868705080300697' => ['supplier' => 'Wonlex', 'model' => 'HW20PRO'],
                '637507597567372' => ['supplier' => '4P Touch', 'model' => '4P-TOUCH', 'deviceId' => '7597567372'],
                '865028000000308' => ['supplier' => 'Vivistar', 'model' => 'VIVISTAR-CARE'],
                'AABBCCDDEEFF' => IngressFixtures::device('Zayata', 'M228', 'pill_dispenser'),
            ]),
            $mqtt,
            effectiveConfiguration: static fn (string $imei): array => $configurations,
            clock: fn (): int => $this->now,
        );
        $connection = new FakeConnection();
        $hub->onOpen($connection);
        $hub->onMessage($connection, $login);

        return [$hub, $mqtt, $connection];
    }

    private function lastState(RecordingHubMqttBridge $mqtt, string $imei): ?string
    {
        $states = array_column(array_filter($mqtt->statuses, static fn (array $status): bool => $status['imei'] === $imei), 'payload');

        return $states === [] ? null : (string)(end($states)['state'] ?? '');
    }
}
