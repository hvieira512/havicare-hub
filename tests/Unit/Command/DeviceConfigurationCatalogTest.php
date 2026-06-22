<?php

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Protocol\Adapter\FourPTouchAdapter;
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

    public function testFourPTouchUploadIntervalBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'uploadInterval', ['intervalSeconds' => 600]);
        self::assertSame('UPLOAD', $payload['command']);
        self::assertSame(['fields' => ['600']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '637507597567372', $payload['command'], $payload['payload'], ['deviceId' => '7597567372']);
        self::assertSame('[3G*7597567372*000A*UPLOAD,600]', $wire);
    }

    public function testFourPTouchUploadIntervalRequiresProtocolMinimum(): void
    {
        self::assertSame(
            'intervalSeconds must be between 60 and 65535',
            DeviceConfigurationCatalog::validate('four-p-touch', 'uploadInterval', ['intervalSeconds' => 59])
        );
    }

    public function testFourPTouchFallsBackToCanonicalImeiOnlyWhenNoDeviceIdIsProvided(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'uploadInterval', ['intervalSeconds' => 600]);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        self::assertSame('[3G*8800000015*000A*UPLOAD,600]', $wire);
    }

    public function testFourPTouchIgnoresEmptyDeviceIdContext(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'uploadInterval', ['intervalSeconds' => 600]);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '7597567372', $payload['command'], $payload['payload'], ['deviceId' => '']);
        self::assertSame('[3G*7597567372*000A*UPLOAD,600]', $wire);
    }

    public function testFourPTouchWhitelistSupportsFiveNumbers(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'whitelistGroup1', [
            'numbers' => ['111', '222', '333', '444', '555'],
        ]);

        self::assertSame('WHITELIST1', $payload['command']);
        self::assertSame(['fields' => ['111', '222', '333', '444', '555']], $payload['payload']);

        $wire = DeviceCommandCatalog::buildDownlink('four-p-touch', '8800000015', $payload['command'], $payload['payload']);
        $decoded = (new FourPTouchAdapter())->decodeIncoming($wire);

        self::assertIsArray($decoded);
        self::assertSame('WHITELIST1', $decoded['type'] ?? null);
        self::assertSame(['111', '222', '333', '444', '555'], $decoded['data']['fields'] ?? null);
    }

    public function testFourPTouchLanguageTimezoneBuildsNativeFields(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'languageTimezone', [
            'language' => 3,
            'timeZone' => '0',
        ]);

        self::assertSame('LZ', $payload['command']);
        self::assertSame(['fields' => ['3', '0']], $payload['payload']);
    }

    public function testFourPTouchFallDownSensitivityBuildsFirmwareAwarePayload(): void
    {
        $payload = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'fallDownSensitivity', [
            'sensitivityLevel' => 5,
            'totalLevels' => 8,
        ]);

        self::assertSame('LSSET', $payload['command']);
        self::assertSame(['fields' => ['5+8']], $payload['payload']);
    }

    public function testFourPTouchWalkTimeAndTemperatureIntervalBuildNativeFields(): void
    {
        $walkTime = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'walkTime', [
            'ranges' => ['08:10-09:30', '10:10-11:30'],
        ]);
        self::assertSame('WALKTIME', $walkTime['command']);
        self::assertSame(['fields' => ['08:10-09:30', '10:10-11:30']], $walkTime['payload']);

        $temperature = DeviceConfigurationCatalog::commandPayload('four-p-touch', 'bodyTemperatureInterval', [
            'enabled' => true,
            'intervalHours' => 2,
        ]);
        self::assertSame('bodytemp', $temperature['command']);
        self::assertSame(['fields' => ['1', '2']], $temperature['payload']);
    }

    public function testFourPTouchCommandsExposeSplitHealthRequests(): void
    {
        $commands = DeviceCommandCatalog::commandsForProtocol('four-p-touch');

        self::assertCount(5, $commands);
        self::assertSame('CR', $commands[0]['command']);
        self::assertSame('fourPHeartRate', $commands[1]['id']);
        self::assertSame('hrtstart', $commands[1]['command']);
        self::assertSame('fourPSystolicPressure', $commands[2]['id']);
        self::assertSame('fourPDiastolicPressure', $commands[3]['id']);
        self::assertSame('fourPBodyTemperature', $commands[4]['id']);
        self::assertContains('bphrt', $commands[1]['expectedReplyTypes']);
        self::assertContains('btemp2', $commands[4]['expectedReplyTypes']);
    }
}
