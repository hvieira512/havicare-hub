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

    /** O alarme cardíaco da 4P não se anuncia no modelo, mas um disparo real é acontecimento. */
    public function testTheUnconfirmedHeartRateAlarmIsNotAnnouncedButStaysAnEvent(): void
    {
        self::assertNotContains('heart_rate_abnormal', SupplierCapabilityTemplate::keysForModel('4P Touch', 'Y6L', 'watch'));
        self::assertTrue(CapabilityCatalog::isEventType('heart_rate_abnormal'));
    }
}
