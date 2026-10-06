<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\Definition\CapabilityDefinitions;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Quem publica uma capacidade é facto da capacidade, e sai do ficheiro de definições do tipo
 * de aparelho dela.
 */
final class PublishedKeysComeFromTheDefinitionsTest extends TestCase
{
    /**
     * O que distingue os três relógios: o `TS` é da 4P Touch, as ondas são da Wonlex, e o
     * `device_status` não é anunciado por nenhum -- chega como resposta a um pedido.
     */
    public function testEachProtocolPublishesWhatItsCapabilityDeclares(): void
    {
        self::assertSame(['four-p-touch'], self::protocolsPublishing('connectivity', 'watch'));
        self::assertSame(['wonlex-json'], self::protocolsPublishing('ppg', 'watch'));
        self::assertSame([], self::protocolsPublishing('device_status', 'watch'));
        self::assertSame(
            ['wonlex-json', 'vivistar-iw', 'four-p-touch'],
            self::protocolsPublishing('heart_rate', 'watch'),
        );
    }

    /** O MKGW3 só diz por onde está ligado; a bateria e o GPS são do MKGW4. */
    public function testTheTwoGatewaysDifferInWhatTheyPublish(): void
    {
        self::assertSame(['moko-mkgw4'], self::protocolsPublishing('battery', 'gateway'));
        self::assertSame(
            ['moko-mkgw3', 'moko-mkgw4'],
            self::protocolsPublishing('connectivity', 'gateway'),
        );
    }

    /** Declarar quem publica uma chave que o ficheiro não define é engano, e não silêncio. */
    public function testDeclaringAPublisherForAnUndefinedKeyFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('capacidade_que_nao_existe');

        DefinitionsDeclaringAnUndefinedKey::publishers();
    }

    /** @return list<string> */
    private static function protocolsPublishing(string $key, string $deviceType): array
    {
        $protocols = [];
        foreach (ProtocolRegistry::protocolsForDeviceType($deviceType) as $protocol) {
            $published = array_merge(
                CapabilityCatalog::telemetryKeysForProtocol($protocol),
                CapabilityCatalog::protocolSpecificKeys($protocol),
            );
            if (in_array($key, $published, true)) {
                $protocols[] = $protocol;
            }
        }

        return $protocols;
    }
}

final class DefinitionsDeclaringAnUndefinedKey extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'watch';
    }

    protected static function publishedBy(): array
    {
        return ['capacidade_que_nao_existe' => ['wonlex-json']];
    }

    protected static function rows(): array
    {
        return ['telemetry' => ['measurement' => ['battery' => 'Bateria']]];
    }
}
