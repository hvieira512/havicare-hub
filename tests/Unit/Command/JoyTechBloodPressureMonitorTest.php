<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\SupplierCapabilityTemplate;
use PHPUnit\Framework\TestCase;

/** O tensiómetro mede e tem uma só ação: o relógio acerta-se sozinho a cada ligação. O termómetro só mede. */
final class JoyTechBloodPressureMonitorTest extends TestCase
{
    public function testTheModelHasTheMeasurementsAndReadingTheMemory(): void
    {
        self::assertSame(
            ['blood_pressure', 'heart_rate', 'read_blood_pressure_history'],
            SupplierCapabilityTemplate::keysForModel('JoyTech', 'HC-6292B', 'blood_pressure_monitor'),
        );
    }

    /** O termómetro é do mesmo fornecedor e tem outro protocolo: só mede, sem ações nem configurações. */
    public function testTheThermometerOnlyMeasuresTemperature(): void
    {
        self::assertSame(['temperature'], SupplierCapabilityTemplate::keysForModel('JoyTech', 'HC-3018B', 'thermometer'));
        self::assertSame([], DeviceConfigurationCatalog::configsForProtocol('joytech-et'));
    }

    public function testReadingTheMemoryIsAnActionQueuedByItsOperation(): void
    {
        self::assertNull(DeviceConfigurationCatalog::validate('joytech-bp', 'read_blood_pressure_history', []));
        self::assertSame('readBloodPressureHistory', DeviceCommandCatalog::buildDownlink('joytech-bp', '748b34837b1e', 'readBloodPressureHistory'));
        self::assertSame('health', CapabilityCatalog::sectionForCapabilityKey('read_blood_pressure_history'));
    }
}
