<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\Definition\CapabilityDefinitions;
use LogicException;
use PHPUnit\Framework\TestCase;

/** O papel é o que os ficheiros de definições escrevem, e a base expande-o nas quatro bandeiras do contrato. */
final class CapabilityDefinitionRolesTest extends TestCase
{
    /** As cinco combinações que existem no catálogo, e nenhuma outra. */
    public function testEveryRoleExpandsToItsOwnFlags(): void
    {
        $subject = new class extends CapabilityDefinitions {
            protected static function deviceType(): string
            {
                return 'watch';
            }

            protected static function rows(): array
            {
                return [
                    'telemetry' => [
                        'measurement' => ['a'],
                        'measurementOnRequest' => ['b'],
                        'sighting' => ['c'],
                    ],
                    'health' => ['setting' => ['d'], 'hubSetting' => ['e']],
                    'settings_system' => ['action' => ['f']],
                    'alarms' => ['event' => ['g']],
                ];
            }
        };

        self::assertSame([
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'a', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'b', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            // O avistamento é indistinguível de uma medição no fio: o que o separa é o
            // conceito, e nunca é pedível porque não há a quem pedir.
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'c', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'd', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'e', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'f', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'g', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
        ], $subject::all());
    }

    /** O `isTelemetry` sai da secção, e um papel de leitura fora dela seria uma contradição. */
    public function testATelemetryRoleOutsideTheTelemetrySectionIsRefused(): void
    {
        $subject = new class extends CapabilityDefinitions {
            protected static function deviceType(): string
            {
                return 'watch';
            }

            protected static function rows(): array
            {
                return ['health' => ['measurement' => ['heart_rate']]];
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/o papel "measurement" não pertence à secção "health"/');

        $subject::all();
    }

    /** Um papel mal escrito daria uma capacidade com as bandeiras todas a false. */
    public function testAnUnknownRoleIsRefusedInsteadOfSilentlyLosingTheFlags(): void
    {
        $subject = new class extends CapabilityDefinitions {
            protected static function deviceType(): string
            {
                return 'watch';
            }

            protected static function rows(): array
            {
                return ['health' => ['settings' => ['step_goal']]];
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/papel desconhecido "settings" na secção "health"/');

        $subject::all();
    }

    /** O tipo vem do ficheiro, e não de cada linha: é o que impede uma cópia mal feita. */
    public function testEveryRowCarriesTheDeviceTypeOfItsOwnFile(): void
    {
        foreach (CapabilityCatalog::deviceTypes() as $deviceType) {
            foreach (CapabilityCatalog::definitionsForDeviceType($deviceType) as $definition) {
                self::assertSame($deviceType, $definition['deviceType'], $definition['key']);
            }
        }
    }
}
