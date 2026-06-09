<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use App\Hub\DeviceIdentityExtractor;
use App\Protocol\Adapter\VivistarAdapter;
use App\Protocol\Adapter\WonlexAdapter;
use PHPUnit\Framework\TestCase;

final class DeviceIdentityExtractorTest extends TestCase
{
    public function testIdentifiesWonlexLoginWithoutNormalizingFeatureData(): void
    {
        $adapter = new WonlexAdapter();
        $extractor = new DeviceIdentityExtractor();

        $identity = $extractor->identify($adapter->encodeOutgoing([
            'type' => 'login',
            'ident' => 'abc',
            'imei' => '865028000000306',
            'data' => [
                'deviceModel' => 'WONLEX-PRO',
            ],
        ]));

        self::assertNotNull($identity);
        self::assertSame('865028000000306', $identity->imei);
        self::assertSame('wonlex-json', $identity->protocol);
        self::assertSame('WONLEX-PRO', $identity->model);
        self::assertSame('abc', $identity->ident);
    }

    public function testIdentifiesVivistarLogin(): void
    {
        $extractor = new DeviceIdentityExtractor();

        $identity = $extractor->identify('IWAP00865028000000308#');

        self::assertNotNull($identity);
        self::assertSame('865028000000308', $identity->imei);
        self::assertSame('vivistar-iw', $identity->protocol);
    }

    public function testReturnsNullWhenDeviceCannotBeIdentified(): void
    {
        $extractor = new DeviceIdentityExtractor();

        self::assertNull($extractor->identify('not-a-device-login'));
    }
}
