<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * «De quanto em quanto tempo o relógio mede ou envia X» é sempre a mesma pergunta, e por isso
 * vive sempre na mesma secção do painel. O intervalo da localização estava em Sistema, ao lado
 * da palavra-passe e do idioma, enquanto o dos passos estava com os outros dez.
 */
final class IntervalSectionTest extends TestCase
{
    public function testEveryReportingAndMeasurementIntervalSharesOneSection(): void
    {
        $sections = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            $key = (string) $definition['key'];
            if (!str_ends_with($key, '_reporting_interval') && !str_ends_with($key, '_measurement_interval')) {
                continue;
            }

            $sections[(string) $definition['section']][] = $definition['deviceType'] . '/' . $key;
        }

        $this->assertNotEmpty($sections, 'Não se encontrou intervalo nenhum -- a varredura falhou.');
        $this->assertCount(1, $sections, sprintf(
            "Os intervalos estão repartidos por secções:\n%s",
            implode("\n", array_map(
                static fn (string $section, array $keys): string => "  {$section}: " . implode(', ', $keys),
                array_keys($sections),
                $sections,
            )),
        ));
    }
}
