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

    /** O HC-6679B é o mesmo tensiómetro com ECG, e lê a memória como o outro. */
    public function testTheEcgModelAlsoHasTheEcg(): void
    {
        self::assertSame(
            ['blood_pressure', 'heart_rate', 'ecg', 'read_blood_pressure_history'],
            SupplierCapabilityTemplate::keysForModel('JoyTech', 'HC-6679B', 'blood_pressure_monitor'),
        );
        self::assertNull(DeviceConfigurationCatalog::validate('joytech-bp-ecg', 'read_blood_pressure_history', []));
        self::assertSame('readBloodPressureHistory', DeviceCommandCatalog::buildDownlink('joytech-bp-ecg', '748b3483cda4', 'readBloodPressureHistory'));
    }

    /** O HC-6679B só manda a memória com o ecrã MEM aberto, e o botão di-lo. */
    public function testTheEcgModelAsksForTheMemoryScreenBeforeReading(): void
    {
        $help = static fn (string $protocol): string => (string)(array_column(DeviceConfigurationCatalog::configsForProtocol($protocol), 'help', 'key')['read_blood_pressure_history'] ?? '');

        self::assertStringContainsString('Abre a memória no aparelho (MEM) antes de pedir', $help('joytech-bp-ecg'));
        self::assertStringNotContainsString('MEM', $help('joytech-bp'));
    }

    /** O termómetro é do mesmo fornecedor e tem outro protocolo: só mede, sem ações nem configurações. */
    public function testTheThermometerOnlyMeasuresTemperature(): void
    {
        self::assertSame(['temperature'], SupplierCapabilityTemplate::keysForModel('JoyTech', 'HC-3018B', 'thermometer'));
        self::assertSame([], DeviceConfigurationCatalog::configsForProtocol('joytech-et'));
    }

    /** O oxímetro também só mede: o oxigénio e o pulso. */
    public function testTheOximeterOnlyMeasuresOxygenAndPulse(): void
    {
        self::assertSame(['blood_oxygen', 'heart_rate'], SupplierCapabilityTemplate::keysForModel('JoyTech', 'HC-101', 'pulse_oximeter'));
        self::assertSame([], DeviceConfigurationCatalog::configsForProtocol('joytech-spo2'));
    }

    public function testReadingTheMemoryIsAnActionQueuedByItsOperation(): void
    {
        self::assertNull(DeviceConfigurationCatalog::validate('joytech-bp', 'read_blood_pressure_history', []));
        self::assertSame('readBloodPressureHistory', DeviceCommandCatalog::buildDownlink('joytech-bp', '748b34837b1e', 'readBloodPressureHistory'));
        self::assertSame('health', CapabilityCatalog::sectionForCapabilityKey('read_blood_pressure_history'));
    }
}
