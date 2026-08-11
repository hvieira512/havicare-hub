<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Domain\DeviceMetadata;
use Hub\HubMqttBridge;
use PHPUnit\Framework\TestCase;

/**
 * Published topics are an external contract -- consumers subscribe to these
 * strings. licenseId is an int in the domain and only becomes text here, so
 * this pins the rendering across that boundary.
 */
final class DeviceTopicShapeTest extends TestCase
{
    private function bridge(): HubMqttBridge
    {
        return (new \ReflectionClass(HubMqttBridge::class))->newInstanceWithoutConstructor();
    }

    public function testAnAssignedDeviceRendersCompanyAndLicense(): void
    {
        self::assertSame(
            'havicare/1/watch/861265061009822/telemetry',
            $this->bridge()->deviceTopic('havicare', 1, 'watch', '861265061009822', 'telemetry')
        );
    }

    public function testAnUnassignedDeviceRendersNullAndZero(): void
    {
        self::assertSame(
            'null/0/gw/d48c49f7909c/raw',
            $this->bridge()->deviceTopic('null', 0, 'gw', 'd48c49f7909c', 'raw')
        );
    }

    public function testLargerLicenseIdsAreNotReformatted(): void
    {
        self::assertSame(
            'hitcare/1001/watch/861265061009822/events',
            $this->bridge()->deviceTopic('hitcare', 1001, 'watch', '861265061009822', 'events')
        );
    }

    public function testCompanyCasingIsNormalisedSoOneTenantIsOneTopicSpace(): void
    {
        // Topics are case sensitive: "hitCare" and "hitcare" would be two
        // different tenants to a subscriber.
        self::assertSame('hitcare', DeviceMetadata::normalizeCompany('hitCare'));
        self::assertSame('havicare', DeviceMetadata::normalizeCompany(' haviCare '));
        self::assertSame('null', DeviceMetadata::normalizeCompany(''));
        self::assertSame('null', DeviceMetadata::normalizeCompany(null));
    }

    public function testAMixedCaseCompanyCannotReachATopic(): void
    {
        // The whitelist file is hand-editable, so the entry normaliser is the
        // last gate before a company name becomes part of a topic.
        $whitelistPath = sys_get_temp_dir() . '/casing-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($whitelistPath, json_encode([
            '861265061009822' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '1001', 'company' => 'HitCare'],
        ], JSON_THROW_ON_ERROR));

        try {
            $metadata = (new \Hub\Registry\Whitelist($whitelistPath))->getMetadata('861265061009822');

            self::assertSame('hitcare', $metadata['company'] ?? null);
            self::assertSame(
                'hitcare/1001/watch/861265061009822/status',
                $this->bridge()->deviceTopic(
                    (string)$metadata['company'],
                    (int)$metadata['licenseId'],
                    'watch',
                    '861265061009822',
                    'status'
                )
            );
        } finally {
            @unlink($whitelistPath);
        }
    }

    public function testSlashesInTheCompanyCannotSplitTheTopic(): void
    {
        self::assertSame(
            'havicare/1/watch/861265061009822/status',
            $this->bridge()->deviceTopic('/havicare/', 1, 'watch', '861265061009822', 'status')
        );
    }
}
