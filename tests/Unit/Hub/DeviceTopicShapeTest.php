<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Domain\DeviceMetadata;
use Hub\Device\HubMqttBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;

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
        $whitelist = IngressFixtures::whitelist([
            '861265061009822' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '1001', 'company' => 'HitCare'],
        ]);

        $metadata = $whitelist->getMetadata('861265061009822');

        self::assertSame('hitcare', $metadata?->company);
        self::assertNotNull($metadata);
        self::assertSame(
            'hitcare/1001/watch/861265061009822/status',
            $this->bridge()->deviceTopic(
                $metadata->company,
                $metadata->licenseId,
                'watch',
                '861265061009822',
                'status'
            )
        );
    }

    public function testSlashesInTheCompanyCannotSplitTheTopic(): void
    {
        self::assertSame(
            'havicare/1/watch/861265061009822/status',
            $this->bridge()->deviceTopic('/havicare/', 1, 'watch', '861265061009822', 'status')
        );
    }
}
