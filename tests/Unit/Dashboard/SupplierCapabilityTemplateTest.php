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

    /**
     * Nenhum relógio 4P mostrou o alarme de frequência cardíaca, e o protocolo não diz como se liga: o
     * modelo não o anuncia, mas um disparo real continua a ser acontecimento.
     */
    public function testTheUnconfirmedHeartRateAlarmIsNotAnnouncedButStaysAnEvent(): void
    {
        self::assertNotContains('heart_rate_abnormal', SupplierCapabilityTemplate::keysForModel('4P Touch', 'Y6L', 'watch'));
        self::assertTrue(CapabilityCatalog::isEventType('heart_rate_abnormal'));
    }
}
