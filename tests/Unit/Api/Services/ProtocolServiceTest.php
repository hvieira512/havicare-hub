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
}
