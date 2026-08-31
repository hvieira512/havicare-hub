<?php

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\DeviceTypeCatalog;
use PHPUnit\Framework\TestCase;

/**
 * O descritor dos tipos de dispositivo, que o PHP e o JavaScript lêem do mesmo ficheiro.
 *
 * Estava escrito quatro vezes -- a lista em PHP e em `domain.js`, os tipos retransmitidos por
 * gateway em PHP e na mesma tabela do JS, e o `sim` na tabela do JS e outra vez como um
 * `deviceType !== "watch"` no `saveDevice`. O que se prende aqui é que voltou a haver uma
 * fonte só, e que a forma que os dois lados esperam se mantém.
 */
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

    /** O catálogo de capacidades deixou de ter a sua própria lista de tipos. */
    public function testCapabilityCatalogReadsTheSameList(): void
    {
        self::assertSame(DeviceTypeCatalog::keys(), CapabilityCatalog::deviceTypes());
    }

    public function testOnlyTheRelayedTypesLinkToAGateway(): void
    {
        self::assertSame(['diaper_sensor', 'bracelet'], DeviceTypeCatalog::linkedToGateway());
    }

    /**
     * O gateway identifica-se por MAC e leva SIM: é o cartão dele que faz o backhaul. As duas
     * coisas andaram juntas enquanto o `sim` foi um `deviceType !== "watch"`, e por isso
     * guardar um gateway apagava-lhe o número.
     */
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
