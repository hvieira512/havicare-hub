<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * As capacidades que o hub serve como um cartão só declaram o campo que esse cartão usa.
 *
 * São capacidades em que várias entradas nativas se fundem -- as duas listas brancas de
 * cinco números do 4P Touch dão um cartão de dez --, e por isso o editor não é o do campo
 * nativo: é um por capacidade, que sabe juntar e voltar a separar.
 *
 * A dashboard chegou a corrigir isto sozinha, com uma tabela destes cinco nomes que
 * substituía o campo declarado. Funcionava no ecrã e deixava o catálogo da API a publicar um
 * campo que ninguém honrava: a lista telefónica saía como `contacts`, os números SOS como
 * `list`, e os alarmes como `alarms`, `reminders` ou `json` conforme o fornecedor. Agora há
 * uma fonte só -- a definição --, e é este teste que a prende.
 */
final class MergedCapabilityInputTest extends TestCase
{
    private const FUNDIDAS = [
        'alarm_clock',
        'phonebook',
        'sos_contacts',
        'call_whitelist',
        'whitelist_enabled',
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

    /**
     * O nome do campo é lido em dois sítios -- aqui, para desenhar, e na tabela de payloads
     * por omissão, para semear o formulário. Renomeá-lo num e esquecer o outro não parte
     * nada à vista: o formulário abre vazio em vez de abrir com as linhas por preencher.
     */
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
