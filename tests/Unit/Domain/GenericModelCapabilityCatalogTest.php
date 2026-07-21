<?php

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\FourPTouch\FourPTouchGenericHandler;
use Hub\Domain\GenericModelCapabilityCatalog;
use PHPUnit\Framework\TestCase;

final class GenericModelCapabilityCatalogTest extends TestCase
{
    public function testDefinitionsRemainStableAfterBeingSplitByDeviceType(): void
    {
        $expected = [
            'watch' => [68, 'daba3719369c7f43083e3dc3c92028a763a9e07298ff712a4a63301f22549ae8'],
            'ncs' => [1, 'fc019c829255013c927ce1c7bcea7cc0d7fc4e76e70b021a6819e41664c09fdd'],
            'radar' => [4, '20bc94db11ca7dd29da50ea2ba226a5e372e8045a6a3e3aece65aaf5acab97f9'],
        ];

        foreach ($expected as $deviceType => [$count, $hash]) {
            $definitions = GenericModelCapabilityCatalog::definitionsForDeviceType($deviceType);

            self::assertCount($count, $definitions, $deviceType);
            self::assertSame(
                $hash,
                hash('sha256', json_encode($definitions, JSON_UNESCAPED_UNICODE)),
                $deviceType,
            );
        }
    }

    public function testDefinitionsHaveUniqueKeysAndRequiredMetadata(): void
    {
        foreach (GenericModelCapabilityCatalog::deviceTypes() as $deviceType) {
            $definitions = GenericModelCapabilityCatalog::definitionsForDeviceType($deviceType);
            $keys = array_column($definitions, 'key');

            self::assertSame($keys, array_values(array_unique($keys)), $deviceType);

            foreach ($definitions as $definition) {
                self::assertSame($deviceType, $definition['deviceType']);
                self::assertArrayHasKey($definition['section'], GenericModelCapabilityCatalog::sections());
                self::assertNotSame('', trim($definition['key']));
                self::assertNotSame('', trim($definition['label']));
                self::assertIsInt($definition['sortOrder']);
                self::assertIsBool($definition['isTelemetry']);
                self::assertIsBool($definition['isConfigurable']);
                self::assertIsBool($definition['isRequestable']);
            }
        }
    }

    public function testFourPTouchAliasesAreResolvedByTheDedicatedHelper(): void
    {
        self::assertSame('sos_contacts', FourPTouchGenericHandler::nativeKeyToGenericKey('sosNumber1'));
        self::assertSame('call_whitelist', FourPTouchGenericHandler::nativeKeyToGenericKey('whitelistGroup1'));
        self::assertSame('alarm_clock', FourPTouchGenericHandler::nativeKeyToGenericKey('alarmClock'));
        self::assertSame('alarmClock', FourPTouchGenericHandler::publicKeyToNativeKey('alarm_clock'));
        self::assertSame('uploadInterval', FourPTouchGenericHandler::publicKeyToNativeKey('location_reporting_interval'));
    }

    public function testFourPTouchFallbackCanRehydrateFallSensitivity(): void
    {
        $handler = new FourPTouchGenericHandler();

        self::assertSame(
            ['sensitivity' => 6, 'levels' => 8],
            $handler->fromNative('fall_sensitivity', 'fallDownSensitivity', [
                'sensitivityLevel' => 6,
                'totalLevels' => 8,
            ]),
        );
    }
}
