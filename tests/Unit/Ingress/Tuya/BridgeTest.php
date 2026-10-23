<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tuya;

use Hub\Ingress\Tuya\TuyaBridge;
use Hub\State\DeviceStore;
use Hub\State\RedisKeys;
use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

final class BridgeTest extends TestCase
{
    private const BUTTON = 'bf7cbe84a6199e98168qvb';
    private const ACCESS_ID = 'test-access-id';
    private const ACCESS_SECRET = '0123456789abcdef0123456789abcdef';

    private InMemoryRedisClient $redis;
    private DeviceStore $store;
    private RecordingHubMqttBridge $mqtt;
    private TuyaBridge $bridge;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedisClient();
        $this->store = new DeviceStore($this->redis);
        $this->mqtt = new RecordingHubMqttBridge();
        $this->bridge = $this->bridge(IngressFixtures::whitelist([
            self::BUTTON => IngressFixtures::device('PST', 'CF103W', 'bracelet'),
        ]), $this->store);
    }

    public function testAPressedButtonPublishesAHelpCallAtTheTimeTheDeviceReported(): void
    {
        $this->bridge->handleReceivedMessage('', $this->frame($this->property('sos_state', 23, true, 1790778037435)));

        $events = $this->events('help_call');
        self::assertCount(1, $events);
        self::assertSame(self::BUTTON, $events[0]['imei']);
        self::assertSame('bracelet', $events[0]['deviceType']);
        self::assertSame(1001, $events[0]['licenseId']);
        self::assertSame('2026-09-30T14:20:37Z', $events[0]['payload']['occurredAt']);
        self::assertSame(['id' => self::BUTTON, 'supplier' => 'PST', 'model' => 'CF103W'], $events[0]['payload']['device']);
        self::assertSame(['protocol' => 'tuya-cloud', 'nativeType' => 'sos_state'], $events[0]['payload']['source']);
        self::assertCount(1, $this->mqtt->raw);
    }

    /** O `sos_state` a falso é o botão a voltar sozinho ao repouso uns segundos depois. */
    public function testTheButtonReturningToRestIsNotAnEvent(): void
    {
        $this->bridge->handleReceivedMessage('', $this->frame($this->property('sos_state', 23, false, 1790778047805)));

        self::assertSame([], $this->events('help_call'));
    }

    public function testTheBatteryIsTelemetryInPercent(): void
    {
        $this->bridge->handleReceivedMessage('', $this->frame($this->property('battery_percentage', 3, 29, 1790778047968)));

        self::assertSame(['battery'], $this->mqtt->telemetryTypes());
        self::assertSame(['percent' => 29], $this->mqtt->lastTelemetry()['payload']['data'] ?? null);
    }

    /** O projeto cifra em GCM; uma trama noutro modo não se lê, e não sai nada. */
    public function testAFrameWithoutGcmIsNotRead(): void
    {
        $this->bridge->handleReceivedMessage('', $this->frame($this->property('sos_state', 23, true, 1790778037435), 'aes_ecb'));

        self::assertSame([], $this->mqtt->events);
    }

    public function testAnUnregisteredButtonIsRecordedForTheRegistrationWizard(): void
    {
        $store = $this->createMock(DeviceStoreContract::class);
        $store->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(self::BUTTON, 'tuya-cloud', '', self::BUTTON, 'device_not_authorized', 0);

        $this->bridge(IngressFixtures::whitelist(), $store)
            ->handleReceivedMessage('', $this->frame($this->property('sos_state', 23, true, 1790778037435)));

        self::assertSame([], $this->mqtt->events);
    }

    /** Como nos ingressos MQTT, o aviso regista-se uma vez por minuto e não a cada mensagem. */
    public function testAnUnregisteredButtonIsRecordedOncePerMinute(): void
    {
        $store = $this->createMock(DeviceStoreContract::class);
        $store->expects(self::once())->method('recordRejectedDevice');
        $bridge = $this->bridge(IngressFixtures::whitelist(), $store);

        $bridge->handleReceivedMessage('', $this->frame($this->property('sos_state', 23, true, 1790778037435)));
        $bridge->handleReceivedMessage('', $this->frame($this->property('battery_percentage', 3, 29, 1790778047968)));
    }

    public function testAWakingButtonIsAnnouncedConnectedOnce(): void
    {
        $this->bridge->handleReceivedMessage('', $this->frame($this->online()));
        $this->bridge->handleReceivedMessage('', $this->frame($this->property('battery_percentage', 3, 17, 1790778047968)));

        self::assertSame(['device.connected'], array_column(array_column($this->events(), 'payload'), 'type'));
        self::assertSame('online', $this->mqtt->statuses[0]['payload']['state'] ?? null);
    }

    /** Adormecido, o botão não se alcança: o `deviceOffline` da cloud é a verdade, até ao próximo despertar. */
    public function testTheCloudsOfflinePutsTheButtonToSleepWithoutADisconnect(): void
    {
        $this->bridge->handleReceivedMessage('', $this->frame($this->online()));
        $this->bridge->handleReceivedMessage('', $this->frame($this->offline()));
        $this->bridge->handleReceivedMessage('', $this->frame($this->offline()));

        self::assertSame(['online', 'idle'], array_column(array_column($this->mqtt->statuses, 'payload'), 'state'));
        self::assertSame(['device.connected'], array_column(array_column($this->events(), 'payload'), 'type'));
        self::assertTrue($this->store->runtime->device(self::BUTTON)['online']);
    }

    public function testTheNextWakeUpIsOnlineAgainWithoutAConnect(): void
    {
        $this->bridge->handleReceivedMessage('', $this->frame($this->online()));
        $this->bridge->handleReceivedMessage('', $this->frame($this->offline()));
        $this->bridge->handleReceivedMessage('', $this->frame($this->online()));

        self::assertSame(['online', 'idle', 'online'], array_column(array_column($this->mqtt->statuses, 'payload'), 'state'));
        self::assertSame(['device.connected'], array_column(array_column($this->events(), 'payload'), 'type'));
    }

    /** O `deviceOffline` diz que adormeceu, e não que acordou: não adia o prazo de dois despertares. */
    public function testFallingAsleepDoesNotCountAsAWakeUp(): void
    {
        $this->bridge->handleReceivedMessage('', $this->frame($this->online()));
        $this->redis->zadd(RedisKeys::onlineDevices(), [self::BUTTON => time() - 2 * 28800 - 30]);
        $this->bridge->handleReceivedMessage('', $this->frame($this->offline()));

        self::assertSame([self::BUTTON], $this->store->runtime->expireStaleDevices());
    }

    public function testAHandledMessageIsAcknowledged(): void
    {
        self::assertSame('CAAQARgAIAA=', $this->bridge->receive($this->frame($this->online())));
    }

    /** Uma trama que não se decifra voltaria para sempre: confirma-se e larga-se. */
    public function testAnUnreadableFrameIsAcknowledgedAndDropped(): void
    {
        $frame = json_encode(['messageId' => 'CAAQARgAIAA=', 'payload' => base64_encode('{"data":"bGl4bw=="}')], JSON_THROW_ON_ERROR);

        self::assertSame('CAAQARgAIAA=', $this->bridge->receive($frame));
        self::assertSame([], $this->mqtt->events);
    }

    /** Uma falha do lado do hub não confirma: o Pulsar volta a entregar o alarme. */
    public function testAFailureOnTheHubSideLeavesTheMessageForRedelivery(): void
    {
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('deviceSeen')->willThrowException(new \RuntimeException('Redis unavailable'));
        $bridge = $this->bridge(IngressFixtures::whitelist([
            self::BUTTON => IngressFixtures::device('PST', 'CF103W', 'bracelet'),
        ]), $store);

        self::assertNull($bridge->receive($this->frame($this->property('sos_state', 23, true, 1790778037435))));
    }

    /** Uma falha que se repete não pode voltar para sempre, a repetir o alarme de três em três segundos. */
    public function testAFailureThatKeepsHappeningIsGivenUpOnTheFifthDelivery(): void
    {
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('deviceSeen')->willThrowException(new \RuntimeException('Unknown column'));
        $bridge = $this->bridge(IngressFixtures::whitelist([
            self::BUTTON => IngressFixtures::device('PST', 'CF103W', 'bracelet'),
        ]), $store);
        $frame = $this->frame($this->property('sos_state', 23, true, 1790778037435));

        $acks = array_map(static fn(): ?string => $bridge->receive($frame), range(1, 5));

        self::assertSame([null, null, null, null, 'CAAQARgAIAA='], $acks);
    }

    /** Mudar o nome ou apagar um botão na app não é o aparelho a falar. */
    public function testCloudMessagesAboutAnUnregisteredButtonAreNotRecorded(): void
    {
        $store = $this->createMock(DeviceStoreContract::class);
        $store->expects(self::never())->method('recordRejectedDevice');

        $this->bridge(IngressFixtures::whitelist(), $store)->handleReceivedMessage('', $this->frame([
            'bizCode' => 'deviceNameUpdate',
            'bizData' => ['devId' => self::BUTTON, 'name' => 'Botão da sala'],
        ]));
    }

    private function bridge(\Hub\Registry\Whitelist $whitelist, DeviceStoreContract $store): TuyaBridge
    {
        return new TuyaBridge(
            self::ACCESS_ID,
            self::ACCESS_SECRET,
            'wss://mqe.tuyaeu.com:8285/',
            self::ACCESS_ID . '-sub',
            $whitelist,
            $this->mqtt,
            $store,
        );
    }

    /** @return list<array<string, mixed>> */
    private function events(?string $type = null): array
    {
        return array_values(array_filter(
            $this->mqtt->events,
            static fn(array $event): bool => $type === null
                ? str_starts_with((string)($event['payload']['type'] ?? ''), 'device.')
                : ($event['payload']['type'] ?? null) === $type,
        ));
    }

    /** @return array<string, mixed> */
    private function property(string $code, int $dpId, mixed $value, int $time): array
    {
        return [
            'bizCode' => 'devicePropertyMessage',
            'bizData' => [
                'devId' => self::BUTTON,
                'productId' => 'b2g1t0w1r6x0msse',
                'properties' => [['code' => $code, 'dpId' => $dpId, 'time' => $time, 'value' => $value]],
            ],
            'ts' => $time,
        ];
    }

    /** @return array<string, mixed> */
    private function offline(): array
    {
        return ['bizCode' => 'deviceOffline', 'bizData' => ['devId' => self::BUTTON, 'time' => 1790778200000]];
    }

    /** @return array<string, mixed> */
    private function online(): array
    {
        return ['bizCode' => 'deviceOnline', 'bizData' => ['devId' => self::BUTTON, 'time' => 1790778037232]];
    }

    /**
     * A trama como o Pulsar a entrega: a mensagem cifrada com a chave do projeto, num envelope em base64.
     *
     * @param array<string, mixed> $business
     */
    private function frame(array $business, string $mode = 'aes_gcm'): string
    {
        $plain = json_encode($business, JSON_THROW_ON_ERROR);
        $key = substr(self::ACCESS_SECRET, 8, 16);
        if ($mode === 'aes_gcm') {
            $iv = random_bytes(12);
            $cipher = openssl_encrypt($plain, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
            $data = base64_encode($iv . $cipher . $tag);
        } else {
            $data = base64_encode((string)openssl_encrypt($plain, 'aes-128-ecb', $key, OPENSSL_RAW_DATA));
        }

        return json_encode([
            'messageId' => 'CAAQARgAIAA=',
            'payload' => base64_encode(json_encode(['protocol' => 1000, 'pv' => '2.0', 't' => 1790778037, 'data' => $data], JSON_THROW_ON_ERROR)),
            'properties' => $mode === 'aes_gcm' ? ['em' => 'aes_gcm'] : [],
            'publishTime' => '2026-09-30T14:20:37.457Z',
        ], JSON_THROW_ON_ERROR);
    }
}
