<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\Definition\CapabilityDefinitions;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * O papel é o que os ficheiros de definições escrevem, e a base expande-o nas quatro
 * bandeiras do contrato.
 */
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
                        'reading' => ['a' => 'A'],
                        'readingOnRequest' => ['b' => 'B'],
                    ],
                    'health' => ['setting' => ['c' => 'C']],
                    'settings_system' => ['action' => ['d' => 'D']],
                    'alarms' => ['event' => ['e' => 'E']],
                ];
            }
        };

        self::assertSame([
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'a', 'label' => 'A', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'telemetry', 'key' => 'b', 'label' => 'B', 'isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'health', 'key' => 'c', 'label' => 'C', 'isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
            ['deviceType' => 'watch', 'section' => 'settings_system', 'key' => 'd', 'label' => 'D', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
            ['deviceType' => 'watch', 'section' => 'alarms', 'key' => 'e', 'label' => 'E', 'isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
        ], $subject::all());
    }

    /** Um papel mal escrito dava uma capacidade com as bandeiras todas a false. */
    public function testAnUnknownRoleIsRefusedInsteadOfSilentlyLosingTheFlags(): void
    {
        $subject = new class extends CapabilityDefinitions {
            protected static function deviceType(): string
            {
                return 'watch';
            }

            protected static function rows(): array
            {
                return ['health' => ['settings' => ['step_goal' => 'Meta de passos']]];
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
