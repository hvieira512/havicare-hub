<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Domain\DeviceMetadata;
use Hub\HubMqttBridge;
use PHPUnit\Framework\TestCase;

/**
 * Os tópicos publicados são um contrato externo -- quem consome subscreve estas strings. O
 * `licenseId` é um inteiro no domínio e só se torna texto aqui, e por isso isto prende a
 * escrita dele nessa fronteira.
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
        // Os tópicos distinguem maiúsculas: para quem subscreve, "hitCare" e "hitcare" eram
        // dois clientes diferentes.
        self::assertSame('hitcare', DeviceMetadata::normalizeCompany('hitCare'));
        self::assertSame('havicare', DeviceMetadata::normalizeCompany(' haviCare '));
        self::assertSame('null', DeviceMetadata::normalizeCompany(''));
        self::assertSame('null', DeviceMetadata::normalizeCompany(null));
    }

    public function testAMixedCaseCompanyCannotReachATopic(): void
    {
        // O ficheiro da whitelist é editável à mão, e por isso o normalizador de entradas é o
        // último portão antes de um nome de empresa fazer parte de um tópico.
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
