<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\Definition\CapabilityDefinitions;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/** Quem publica uma capacidade sai do ficheiro de definições do tipo de aparelho dela. */
final class PublishedKeysComeFromTheDefinitionsTest extends TestCase
{
    /** O `TS` é da 4P Touch, as ondas são da Wonlex, e o `device_status` não é anunciado: chega como resposta a um pedido. */
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

    /** A pulseira Veepoo publica o `find_device`, e nenhuma das definições que se lhe escrevem. */
    public function testTheVeepooBraceletPublishesNoneOfItsSettings(): void
    {
        self::assertSame(['veepoo-ble'], self::protocolsPublishing('find_device', 'bracelet'));
        self::assertSame([], self::protocolsPublishing('heart_rate_continuous', 'bracelet'));
        self::assertSame([], self::protocolsPublishing('skin_tone', 'bracelet'));
        self::assertSame([], self::protocolsPublishing('heart_rate_alert', 'bracelet'));
    }

    /** Declarar quem publica uma chave que o ficheiro não define é engano, e não silêncio. */
    public function testDeclaringAPublisherForAnUndefinedKeyFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('undefined_capability');

        DefinitionsDeclaringAnUndefinedKey::publishers();
    }

    /** E nomear um protocolo de outro tipo de aparelho também: nunca casaria com nada. */
    public function testDeclaringAPublisherFromAnotherDeviceTypeFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('moko-mkgw4');

        DefinitionsDeclaringAForeignProtocol::publishers();
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
        return ['undefined_capability' => ['wonlex-json']];
    }

    protected static function rows(): array
    {
        return ['telemetry' => ['measurement' => ['battery' => 'Bateria']]];
    }
}

final class DefinitionsDeclaringAForeignProtocol extends CapabilityDefinitions
{
    protected static function deviceType(): string
    {
        return 'watch';
    }

    protected static function publishedBy(): array
    {
        return ['battery' => ['moko-mkgw4']];
    }

    protected static function rows(): array
    {
        return ['telemetry' => ['measurement' => ['battery' => 'Bateria']]];
    }
}
