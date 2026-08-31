<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use Hub\Api\Http\ProtocolDashboardMeta;
use Hub\Api\Services\ProtocolService;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * As asserções que estavam no `ProtocolRegistryTest` sobre a chave `dashboard`, agora onde
 * essa informação vive. O que se prova aqui é o mesmo de antes -- os valores não mudaram, só
 * mudaram de camada -- mais uma garantia nova: a resposta da API tem de continuar a sair com
 * a mesma forma, porque a dashboard lê-a tal e qual.
 */
final class ProtocolDashboardMetaTest extends TestCase
{
    public function testFourPTouchContactGroupsAndFieldLimits(): void
    {
        $dashboard = ProtocolDashboardMeta::forProtocol('four-p-touch');

        self::assertSame('Contactos SOS', $dashboard['groupedCapabilities']['sos_contacts']['label']);
        self::assertSame(10, $dashboard['groupedCapabilities']['call_whitelist']['limit']);
        self::assertSame(10, $dashboard['fieldConstraints']['phonebook']['name']['maxLength']);
        self::assertSame(20, $dashboard['fieldConstraints']['phonebook']['phone']['maxLength']);
    }

    public function testWonlexContactMetadataUsesTheGenericContactContract(): void
    {
        $dashboard = ProtocolDashboardMeta::forProtocol('wonlex-json');

        self::assertSame(10, $dashboard['groupedCapabilities']['phonebook']['limit'] ?? null);
        self::assertSame(10, $dashboard['groupedCapabilities']['sos_contacts']['limit'] ?? null);
        self::assertArrayHasKey('whitelist_enabled', $dashboard['groupedCapabilities']);
        self::assertArrayNotHasKey('call_whitelist', $dashboard['groupedCapabilities']);
        self::assertSame(4, $dashboard['fieldConstraints']['phonebook']['name']['maxLength'] ?? null);
    }

    public function testTheDashboardMetadataDoesNotDefineASecondCapabilityTaxonomy(): void
    {
        foreach (['wonlex-json', 'vivistar-iw', 'four-p-touch'] as $protocol) {
            $dashboard = ProtocolDashboardMeta::forProtocol($protocol);
            self::assertArrayNotHasKey('categoryLabels', $dashboard);
            self::assertArrayNotHasKey('categoryOrder', $dashboard);
        }
    }

    public function testTheBraceletsKeepTheirPressModes(): void
    {
        self::assertSame(
            ['single', 'double', 'long'],
            ProtocolDashboardMeta::forProtocol('moko-w6b')['helpCallPressModes']
        );
        // A W6 não tem toque longo: o firmware dá simples, duplo e triplo.
        self::assertSame(
            ['single', 'double', 'triple'],
            ProtocolDashboardMeta::forProtocol('moko-w6')['helpCallPressModes']
        );
    }

    /** Um protocolo desconhecido tem de dar a mesma forma vazia, e não rebentar. */
    public function testAnUnknownProtocolGetsTheEmptyShape(): void
    {
        self::assertSame(
            ['groupedCapabilities' => [], 'fieldConstraints' => [], 'helpCallPressModes' => []],
            ProtocolDashboardMeta::forProtocol('nao-existe')
        );
    }

    /**
     * A forma na resposta é o contrato com a dashboard, e a mudança de camada não lhe pode
     * tocar: cada protocolo continua a sair com os metadados do domínio mais a chave
     * `dashboard`.
     */
    public function testTheApiResponseStillCarriesBothHalves(): void
    {
        $data = (new ProtocolService())->list()['data'];

        self::assertCount(count(ProtocolRegistry::keys()), $data);
        foreach ($data as $entry) {
            self::assertSame(
                ['protocol', 'label', 'deviceType', 'supportsConfigCatalog', 'dashboard'],
                array_keys($entry)
            );
            self::assertArrayHasKey('groupedCapabilities', $entry['dashboard']);
            self::assertArrayHasKey('fieldConstraints', $entry['dashboard']);
            self::assertArrayHasKey('helpCallPressModes', $entry['dashboard']);
        }
    }
}
