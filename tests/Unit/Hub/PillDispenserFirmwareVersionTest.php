<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Ingress\Tcp\DeviceEventDecoder;
use Hub\Ingress\Tcp\Zayata\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\PillDispenserFixtures;

/** A versão do firmware chega no pacote de registo e sai pela capacidade que os relógios e as pulseiras já usam. */
final class PillDispenserFirmwareVersionTest extends TestCase
{
    public function testTheRegistrationPacketCarriesTheFirmwareVersion(): void
    {
        $events = $this->decodeAndNormalize([
            'packetType' => 0x01,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x8002 => ['value' => pack('v', 1282)],
                0x8004 => ['value' => pack('v', 60)],
            ],
        ]);

        self::assertSame(['firmware_version'], array_column($events, 'feature'));
        self::assertSame(['version' => '0x0502'], $events[0]['value']);
    }

    /** Sem a TAG não há leitura: um registo antigo não passa a dizer «versão 0». */
    public function testARegistrationWithoutTheTagSaysNothing(): void
    {
        $events = $this->decodeAndNormalize([
            'packetType' => 0x01,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [0x8004 => ['value' => pack('v', 60)]],
        ]);

        self::assertNotContains('firmware_version', array_column($events, 'feature'));
    }

    /** E o catálogo tem de a declarar, senão é um tipo publicado que ninguém anunciou. */
    public function testTheCatalogueDeclaresIt(): void
    {
        $keys = array_column(CapabilityCatalog::definitionsForDeviceType('pill_dispenser'), 'key');

        self::assertContains('firmware_version', $keys);
    }

    /** Só o registo do aparelho a traz, e um botão sem downlink prometeria uma leitura que nunca chega. */
    public function testItIsNotRequestable(): void
    {
        foreach (CapabilityCatalog::definitionsForDeviceType('pill_dispenser') as $definition) {
            if ((string)$definition['key'] === 'firmware_version') {
                self::assertFalse((bool)$definition['isRequestable']);
                return;
            }
        }

        self::fail('firmware_version não está declarada');
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{feature: string, nativeType: string, value: array<string, mixed>}>
     */
    private function decodeAndNormalize(array $payload): array
    {
        $adapter = new PillDispenserAdapter();
        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing($payload));
        self::assertIsArray($decoded);

        return (new DeviceEventDecoder())->decode(PillDispenserFixtures::session(), $decoded);
    }
}
