<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Domain\SupplierCapabilityTemplate;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

final class SupplierCapabilityTemplateTest extends TestCase
{
    /** Os nomes são os que o normalizador publica: `help_call`, e não `pager_call`, e o `reset` que a fecha. */
    public function testVoerkaNcsTemplateReturnsHelpCallAndReset(): void
    {
        $actual = SupplierCapabilityTemplate::keysForSupplierDeviceType('Voerka', 'ncs');

        self::assertSame(['help_call', 'reset'], $actual);
    }

    /** Um fornecedor com vários protocolos dá a cada tipo de aparelho o modelo do seu. */
    public function testASupplierWithSeveralProtocolsGivesEachDeviceTypeItsOwnTemplate(): void
    {
        self::assertSame(['temperature'], SupplierCapabilityTemplate::keysForSupplierDeviceType('JoyTech', 'thermometer'));
        self::assertContains('blood_oxygen', SupplierCapabilityTemplate::keysForSupplierDeviceType('JoyTech', 'pulse_oximeter'));
        self::assertContains('find_device', SupplierCapabilityTemplate::keysForSupplierDeviceType('Wonlex', 'bracelet'));
    }

    /** O bit 22 do `AL` da 4P Touch é descodificado e publicado, e o catálogo diz que é. */
    public function testTheFourPTouchHeartRateAlarmIsDeclaredWhereItIsPublished(): void
    {
        self::assertContains('heart_rate_abnormal', CapabilityCatalog::protocolSpecificKeys('four-p-touch'));
        self::assertNotContains('heart_rate_abnormal', CapabilityCatalog::protocolSpecificKeys('vivistar-iw'));
    }
}
