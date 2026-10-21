<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/** Quem junta e separa as entradas nativas de um cartão é o editor da capacidade, como nas duas listas brancas do 4P Touch. */
final class MergedCapabilityInputTest extends TestCase
{
    private const FUNDIDAS = [
        'alarm_clock',
        'phonebook',
        'sos_contacts',
        'call_whitelist',
    ];

    public function testMergedCapabilitiesDeclareTheirOwnInput(): void
    {
        $erradas = [];

        foreach (ProtocolRegistry::protocolsWithConfigCatalog() as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                $key = (string)($entry['key'] ?? '');
                $capability = CapabilityCatalog::mapConfigurationKey($key);
                if (!in_array($capability, self::FUNDIDAS, true)) {
                    continue;
                }

                $input = (string)($entry['input'] ?? '');
                if ($input !== $capability) {
                    $erradas[] = $protocol . '/' . $key . ': declara ' . $input . ', devia declarar ' . $capability;
                }
            }
        }

        self::assertSame([], $erradas);
    }

    /** O nome do campo é lido aqui e na tabela de payloads por omissão, e renomeá-lo só num abre o formulário vazio. */
    public function testMergedCapabilitiesKeepTheirSeedPayload(): void
    {
        $vazias = [];

        foreach (ProtocolRegistry::protocolsWithConfigCatalog() as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                $key = (string)($entry['key'] ?? '');
                $capability = CapabilityCatalog::mapConfigurationKey($key);
                // Os alarmes abrem sem linhas de propósito: quem os desenha parte de zero.
                if (!in_array($capability, self::FUNDIDAS, true) || $capability === 'alarm_clock') {
                    continue;
                }

                if (ConfigurationInputDefaults::forEntry($entry) === []) {
                    $vazias[] = $protocol . '/' . $key;
                }
            }
        }

        self::assertSame([], $vazias, 'estas abrem o formulário vazio em vez de o semear');
    }
}
