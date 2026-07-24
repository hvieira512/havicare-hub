<?php

namespace Tests\Unit\Api\Services;

use Hub\Api\Services\ProtocolService;
use PHPUnit\Framework\TestCase;

final class ProtocolServiceTest extends TestCase
{
    public function testListReturnsTheKnownProtocolsWithDashboardMetadata(): void
    {
        $service = new ProtocolService();
        $response = $service->list();

        self::assertIsArray($response['data'] ?? null);
        self::assertNotEmpty($response['data']);
        self::assertContains('four-p-touch', array_column($response['data'], 'protocol'));

        $fourPTouch = null;
        foreach ($response['data'] as $entry) {
            if (($entry['protocol'] ?? '') === 'four-p-touch') {
                $fourPTouch = $entry;
                break;
            }
        }

        self::assertIsArray($fourPTouch);
        self::assertSame('4P Touch', $fourPTouch['label'] ?? null);
        self::assertSame(['intervals', 'contacts', 'alerts', 'health', 'system'], $fourPTouch['dashboard']['categoryOrder'] ?? null);
    }

    public function testConfigCatalogRejectsUnsupportedProtocols(): void
    {
        $service = new ProtocolService();
        $response = $service->configCatalog(['protocol' => 'does-not-exist']);

        self::assertSame('protocol_not_found', $response['error']['code'] ?? null);
    }

    public function testConfigCatalogReturnsEntriesForFourPTouch(): void
    {
        $service = new ProtocolService();
        $response = $service->configCatalog(['protocol' => 'four-p-touch']);

        self::assertIsArray($response['data'] ?? null);
        self::assertNotEmpty($response['data']);
        self::assertContains('phonebook', array_column($response['data'], 'key'));
    }

    public function testConfigCatalogMapsFourPTouchRemoveWatchAndSoundProfileKeys(): void
    {
        $service = new ProtocolService();
        $response = $service->configCatalog(['protocol' => 'four-p-touch']);

        $entries = $response['data'] ?? [];
        $byKey = [];
        foreach ($entries as $entry) {
            $byKey[$entry['key'] ?? ''] = $entry;
        }

        self::assertSame('remove_watch_alarm', $byKey['removeWatchAlarm']['capabilityKey'] ?? null);
        self::assertSame('remove_watch_sms_alert', $byKey['removeWatchSmsAlerts']['capabilityKey'] ?? null);
        self::assertSame('sound_profile', $byKey['profile']['capabilityKey'] ?? null);
    }

    public function testConfigCatalogMapsVivistarFallSensitivityToThePublicCapabilityKey(): void
    {
        $service = new ProtocolService();
        $response = $service->configCatalog(['protocol' => 'vivistar-iw']);

        $fallSensitivity = null;
        foreach (($response['data'] ?? []) as $entry) {
            if (($entry['key'] ?? '') === 'fallSensitivity') {
                $fallSensitivity = $entry;
                break;
            }
        }

        self::assertIsArray($fallSensitivity);
        self::assertSame('fall_sensitivity', $fallSensitivity['capabilityKey'] ?? null);
    }
}
