<?php

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Protocol\Adapter\VivistarAdapter;
use Hub\Protocol\Adapter\WonlexAdapter;
use PHPUnit\Framework\TestCase;

final class DeviceConfigurationCatalogTest extends TestCase
{
    public function testVivistarFallDetectionBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('vivistar-iw', 'fallDetection', ['enabled' => true]);
        self::assertSame('BP76', $payload['command']);
        self::assertSame(['fields' => ['1']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('vivistar-iw', '861265061009822', $payload['command'], $payload['payload']);
        self::assertStringStartsWith('IWBP76,861265061009822,', $wire);
        self::assertStringEndsWith(',1#', $wire);
        self::assertNotNull((new VivistarAdapter())->decodeIncoming(str_replace('BP76', 'AP76', preg_replace('/^IWBP76,\d{15},/', 'IWAP76,', $wire))));
    }

    public function testVivistarSOSContactsMetadataIncludesCategoryAndLimit(): void
    {
        $config = DeviceConfigurationCatalog::configForProtocol('vivistar-iw', 'sosContacts');
        self::assertIsArray($config);
        self::assertSame('contacts', $config['category'] ?? null);
        self::assertSame(3, $config['limit'] ?? null);
    }

    public function testWonlexLocationIntervalBuildsJsonPayload(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'locationInterval', ['intervalTime' => 300]);
        self::assertSame('locationInterval', $payload['command']);

        $wire = DeviceCommandCatalog::buildDownlink('wonlex-json', '865028000000308', $payload['command'], $payload['payload']);
        $decoded = (new WonlexAdapter())->decodeIncoming($wire);

        self::assertIsArray($decoded);
        self::assertSame('locationInterval', $decoded['type'] ?? null);
        self::assertSame(300, $decoded['data']['intervalTime'] ?? null);
    }

    public function testInvalidConfigIsRejected(): void
    {
        self::assertSame(
            'intervalSeconds must be at least 30 for mode 8',
            DeviceConfigurationCatalog::validate('vivistar-iw', 'workingMode', ['mode' => 8, 'intervalSeconds' => 10, 'gpsEnabled' => true])
        );
    }
}
