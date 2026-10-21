<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/** O que não se desfaz a partir da dashboard sai como destrutivo, e é isso que lhe pede confirmação. */
final class DestructiveConfirmationTest extends TestCase
{
    /**
     * As capacidades cujo efeito não se desfaz a partir da dashboard: o aparelho deixa de
     * comunicar, perde o que estava a fazer, ou liga a um número sem avisar quem o traz.
     *
     * @var list<string>
     */
    private const IRREVERSIBLE = [
        'reset_device',
        'restart_device',
        'power_off',
        'monitor_number',
    ];

    public function testEveryIrreversibleActionIsDestructive(): void
    {
        $normal = [];

        foreach (ProtocolRegistry::protocolsWithConfigCatalog() as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                $key = (string)($entry['key'] ?? '');
                if (
                    in_array(CapabilityCatalog::mapConfigurationKey($key), self::IRREVERSIBLE, true)
                    && ($entry['risk'] ?? '') !== 'destructive'
                ) {
                    $normal[] = $protocol . '/' . $key;
                }
            }
        }

        self::assertSame([], $normal, 'estas acções não pedem confirmação');
    }
}
