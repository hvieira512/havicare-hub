<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\DeviceTypeCatalog;
use PHPUnit\Framework\TestCase;

/** O descritor dos tipos de dispositivo, que o PHP e o JavaScript lêem do mesmo ficheiro. */
final class DeviceTypeCatalogTest extends TestCase
{
    public function testEveryTypeDeclaresTheShapeBothSidesRead(): void
    {
        $all = DeviceTypeCatalog::all();
        self::assertNotSame([], $all);

        foreach ($all as $type => $descriptor) {
            self::assertIsString($type);
            self::assertArrayHasKey('label', $descriptor, $type);
            self::assertArrayHasKey('sim', $descriptor, $type);
            self::assertArrayHasKey('gatewayLinks', $descriptor, $type);
            self::assertIsBool($descriptor['sim'], $type);
            self::assertIsBool($descriptor['gatewayLinks'], $type);

            foreach (['field', 'label', 'help', 'placeholder'] as $key) {
                self::assertArrayHasKey($key, $descriptor['identity'], "{$type}.identity.{$key}");
                self::assertNotSame('', $descriptor['identity'][$key], "{$type}.identity.{$key}");
            }

            // Só duas formas de identificar: por IMEI ou pelo identificador do protocolo.
            self::assertContains($descriptor['identity']['field'], ['imei', 'deviceId'], $type);
        }
    }

    /** O catálogo de capacidades não tem lista de tipos própria. */
    public function testCapabilityCatalogReadsTheSameList(): void
    {
        self::assertSame(DeviceTypeCatalog::keys(), CapabilityCatalog::deviceTypes());
    }

    public function testOnlyTheRelayedTypesLinkToAGateway(): void
    {
        self::assertSame(['diaper_sensor', 'bracelet'], DeviceTypeCatalog::linkedToGateway());
    }

    /** O gateway identifica-se por MAC e leva SIM: é o cartão dele que faz o backhaul. */
    public function testTheGatewayHasASimEvenThoughItIsNotIdentifiedByImei(): void
    {
        self::assertTrue(DeviceTypeCatalog::hasSim('gateway'));
        self::assertSame('deviceId', DeviceTypeCatalog::all()['gateway']['identity']['field']);

        self::assertTrue(DeviceTypeCatalog::hasSim('watch'));
        foreach (['ncs', 'radar', 'diaper_sensor', 'bracelet'] as $type) {
            self::assertFalse(DeviceTypeCatalog::hasSim($type), $type);
        }
    }

    /** Um tipo desconhecido não tem SIM, em vez de rebentar. */
    public function testAnUnknownTypeSimplyHasNoSim(): void
    {
        self::assertFalse(DeviceTypeCatalog::hasSim('nao_existe'));
    }

    public function testTheJsonIsWhatGoesToTheBrowser(): void
    {
        $decoded = json_decode(DeviceTypeCatalog::asJson(), true);

        self::assertSame(DeviceTypeCatalog::all(), $decoded);
    }
}
