<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\LifecycleEvents;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Os desfechos saem por `events`, no tópico do dono do aparelho, com o pedido identificado. */
final class LifecycleEventsTest extends TestCase
{
    private const DEVICE = '861728087056333';

    public function testAFailedCommandSaysWhichOneAndWhy(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $events = new LifecycleEvents($mqtt, IngressFixtures::whitelist([self::DEVICE => IngressFixtures::device('Vivistar', 'L08 Pro', 'watch')]));

        $events->command(self::DEVICE, 'device.downlink.failed', [
            'id' => 'op-1', 'nativeType' => 'BP84', 'protocol' => 'vivistar-iw', 'configKey' => 'rejectUnknownCalls',
            'genericConfigKey' => 'whitelist_enabled', 'status' => 'failed', 'error' => 'response_timeout',
            'attempts' => 3, 'sentAt' => '2026-10-20T09:00:00Z', 'bytes' => 'IWBP84#',
        ]);

        $event = $mqtt->events[0]['payload'];
        self::assertSame('device.downlink.failed', $event['type']);
        self::assertSame(['code' => 'response_timeout'], $event['error']);
        self::assertSame([
            'id' => 'op-1', 'nativeType' => 'BP84', 'protocol' => 'vivistar-iw', 'configKey' => 'whitelist_enabled',
            'status' => 'failed', 'attempts' => 3, 'sentAt' => '2026-10-20T09:00:00Z',
        ], $event['command']);
        self::assertSame('watch', $mqtt->events[0]['deviceType']);
    }

    public function testAConfirmedConfigurationCarriesTheAppliedValue(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $events = new LifecycleEvents($mqtt, IngressFixtures::whitelist([self::DEVICE => IngressFixtures::device('Vivistar', 'L08 Pro', 'watch')]));

        $events->configuration([
            'imei' => self::DEVICE, 'configKey' => 'whitelist_enabled', 'changeId' => 'ch-1', 'desiredRevision' => 2,
            'status' => 'confirmed', 'effective' => ['enabled' => true], 'error' => '',
        ]);

        $event = $mqtt->events[0]['payload'];
        self::assertSame('device.configuration.confirmed', $event['type']);
        self::assertArrayNotHasKey('error', $event);
        self::assertSame(
            ['key' => 'whitelist_enabled', 'changeId' => 'ch-1', 'desiredRevision' => 2, 'status' => 'confirmed', 'effective' => ['enabled' => true]],
            $event['configuration'],
        );
    }

    public function testAFailedConfigurationSaysWhy(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $events = new LifecycleEvents($mqtt, IngressFixtures::whitelist([self::DEVICE => IngressFixtures::device('Vivistar', 'L08 Pro', 'watch')]));

        $events->configuration([
            'imei' => self::DEVICE, 'configKey' => 'whitelist_enabled', 'changeId' => 'ch-1', 'desiredRevision' => 2,
            'status' => 'failed', 'effective' => null, 'error' => 'device_rejected',
        ]);

        self::assertSame('device.configuration.failed', $mqtt->events[0]['payload']['type']);
        self::assertSame(['code' => 'device_rejected'], $mqtt->events[0]['payload']['error']);
        self::assertArrayNotHasKey('effective', $mqtt->events[0]['payload']['configuration']);
    }

    public function testAFeatureRequestSaysWhichFeature(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        (new LifecycleEvents($mqtt, IngressFixtures::whitelist([self::DEVICE => IngressFixtures::device('Vivistar', 'L08 Pro', 'watch')])))
            ->command(self::DEVICE, 'device.downlink.acked', ['id' => 'c-1', 'feature' => 'find_device', 'status' => 'acked']);

        self::assertSame('find_device', $mqtt->events[0]['payload']['command']['feature']);
    }

    /** Publicar o desfecho nunca parte quem regista o comando. */
    public function testAFailureToPublishDoesNotEscape(): void
    {
        $mqtt = new class extends RecordingHubMqttBridge {
            public function publishEvent(string $imei, array $payload, string $deviceType, int $licenseId = 0, string $company = 'null'): void
            {
                throw new \RuntimeException('broker unavailable');
            }
        };
        $whitelist = $this->createStub(\Hub\Registry\Whitelist::class);
        $whitelist->method('getMetadata')->willThrowException(new \RuntimeException('database unavailable'));

        (new LifecycleEvents($mqtt, $whitelist))->command(self::DEVICE, 'device.downlink.acked', ['id' => 'c-1', 'status' => 'acked']);
        (new LifecycleEvents($mqtt, IngressFixtures::whitelist([])))->command(self::DEVICE, 'device.downlink.acked', ['id' => 'c-1', 'status' => 'acked']);

        $this->addToAssertionCount(1);
    }

    /** O `confirmedAt` é o mesmo que a API devolve, e é por ele que se data a versão. */
    public function testAConfirmedConfigurationCarriesWhenItWasConfirmed(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        (new LifecycleEvents($mqtt, IngressFixtures::whitelist([self::DEVICE => IngressFixtures::device('Vivistar', 'L08 Pro', 'watch')])))->configuration([
            'imei' => self::DEVICE, 'configKey' => 'whitelist_enabled', 'changeId' => 'ch-1', 'desiredRevision' => 2,
            'status' => 'confirmed', 'effective' => ['enabled' => true], 'error' => '', 'confirmedAt' => '2026-10-20T09:01:12Z',
        ]);

        self::assertSame('2026-10-20T09:01:12Z', $mqtt->events[0]['payload']['configuration']['confirmedAt']);
    }

    public function testAFailureWithAReasonCarriesIt(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        (new LifecycleEvents($mqtt, IngressFixtures::whitelist([self::DEVICE => IngressFixtures::device('Vivistar', 'L08 Pro', 'watch')])))
            ->command(self::DEVICE, 'device.downlink.failed', ['id' => 'c-1', 'status' => 'failed', 'error' => 'invalid_payload', 'errorMessage' => 'unit must be one of kg, lb']);

        self::assertSame(['code' => 'invalid_payload', 'message' => 'unit must be one of kg, lb'], $mqtt->events[0]['payload']['error']);
    }
}
