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
}
