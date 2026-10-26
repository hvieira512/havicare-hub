<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tcp;

use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ZayataFixtures;

/** Reutiliza-se a capacidade existente, junta-se a mesma pergunta, um alerta só fala quando dispara, e o que ninguém lê não sai. */
final class ZayataStatusSeparationTest extends TestCase
{
    /** O `0x810B` de uma unidade 4G é o CSQ do módulo, não dBm, e converte-se pela tabela do 3GPP: `−113 + 2 × CSQ`. */
    public function testTheCellularSignalIsConvertedFromCsq(): void
    {
        $byFeature = ZayataFixtures::features(0x87, [
            0x810B => pack('s', 25),
            0x810D => "\x03",
            0x8109 => "\x01",
        ]);

        self::assertSame(
            ['interface' => 'cellular', 'signalStrengthDbm' => -63],
            $byFeature['connectivity'] ?? null,
        );
        self::assertArrayNotHasKey('device_status', $byFeature);
    }

    /** As pontas da tabela do fornecedor, que é onde uma conversão errada se denuncia. */
    public function testTheEndsOfTheCsqTableMatchTheSupplier(): void
    {
        foreach ([0 => -113, 1 => -111, 2 => -109, 30 => -53, 31 => -51] as $csq => $dbm) {
            self::assertSame(
                $dbm,
                ZayataFixtures::features(0x87, [0x810B => pack('s', $csq)])['connectivity']['signalStrengthDbm'] ?? null,
                "CSQ {$csq}",
            );
        }
    }

    /** Um valor já negativo é dBm e passa como está: o CSQ nunca o é, e por isso não colidem. */
    public function testASignedReadingIsTakenAsDbm(): void
    {
        self::assertSame(
            -85,
            ZayataFixtures::features(0x87, [0x810B => pack('s', -85)])['connectivity']['signalStrengthDbm'] ?? null,
        );
    }

    /** O `99` do CSQ é «not known or not detectable» no 3GPP TS 27.007, e não o melhor sinal. */
    public function testAnUnknownCsqIsNotPublished(): void
    {
        self::assertArrayNotHasKey('connectivity', ZayataFixtures::features(0x87, [0x810B => pack('s', 99)]));
        self::assertArrayNotHasKey('connectivity', ZayataFixtures::features(0x87, [0x810B => pack('s', 32)]));
    }

    /** Esta unidade é 4G, mas a série tem modelos com WiFi e a interface tem de o dizer. */
    public function testAWifiOnlyUnitSaysSo(): void
    {
        self::assertSame(
            ['interface' => 'wifi', 'signalStrengthDbm' => -60],
            ZayataFixtures::features(0x87, [0x810A => pack('s', 60)])['connectivity'] ?? null,
        );
    }

    /** «Ligado à corrente» e «a carregar» são a mesma pergunta, e vivem no mesmo cartão. */
    public function testTheMainsSupplyTravelsWithTheBattery(): void
    {
        $battery = ZayataFixtures::features(0x87, [
            0x8103 => "\x64",
            0x8104 => "\x03",
            0x8109 => "\x01",
        ])['battery'] ?? [];

        self::assertSame(100, $battery['percent'] ?? null);
        self::assertTrue($battery['charging'] ?? null);
        self::assertFalse(ZayataFixtures::features(0x87, [0x8104 => "\x01"])['battery']['charging'] ?? null);
        self::assertArrayNotHasKey('charging', ZayataFixtures::features(0x87, [0x8104 => "\x04"])['battery'] ?? []);
        self::assertTrue($battery['mainsPowered'] ?? null);
    }

    /** A bateria fraca é a mesma bandeira em todos os aparelhos; sem bateria não é bateria fraca. */
    public function testALowBatteryRaisesTheCommonFlag(): void
    {
        self::assertTrue(ZayataFixtures::features(0x87, [0x8103 => "\x0C", 0x8104 => "\x02"])['battery']['lowBattery'] ?? null);
        self::assertFalse(ZayataFixtures::features(0x87, [0x8103 => "\x50", 0x8104 => "\x00"])['battery']['lowBattery'] ?? null);
        self::assertArrayNotHasKey('lowBattery', ZayataFixtures::features(0x87, [0x8104 => "\x04"])['battery'] ?? []);
    }

    /** O `0x8101` é o juízo do aparelho sobre a contagem do `0x811D`: viaja como campo dela. */
    public function testTheMedicationLevelTravelsWithTheCellCount(): void
    {
        $byFeature = ZayataFixtures::features(0x87, [0x8101 => "\x02", 0x811D => "\x00", 0x811B => "\x1D"]);

        self::assertArrayNotHasKey('medication_level', $byFeature);
        self::assertSame(
            ['remaining' => 0, 'total' => 28, 'level' => 'empty'],
            $byFeature['cells_remaining'] ?? null,
        );
    }

    /** Sem contagem, o juízo do aparelho continua a valer por si. */
    public function testTheLevelAloneIsStillPublished(): void
    {
        self::assertSame(
            ['level' => 'low'],
            ZayataFixtures::features(0x87, [0x8101 => "\x01"])['cells_remaining'] ?? null,
        );
    }

    /** Os dois sensores de estado físico não saem do descodificador: nenhum lê a peça que diz ler, e respondem sempre o mesmo. */
    public function testThePhysicalStateTagsDoNotBecomeTelemetry(): void
    {
        self::assertArrayNotHasKey('tray_lock', ZayataFixtures::features(0x87, [0x8107 => "\x01"]));
        self::assertArrayNotHasKey('tray_lock', ZayataFixtures::features(0x87, [0x8107 => "\x00"]));
        self::assertArrayNotHasKey('medication_cup', ZayataFixtures::features(0x87, [0x8106 => "\x01"]));
        self::assertArrayNotHasKey('medication_cup', ZayataFixtures::features(0x87, [0x8106 => "\x00"]));
    }

    /** O `saveReported` substitui o payload inteiro, e o `0x8105` só com o ligado apagaria a janela vinda do `0x05`. */
    public function testTheStatusPacketDoesNotReportTheQuietHoursItOnlyHalfKnows(): void
    {
        foreach (["\x00", "\x01", "\x02"] as $state) {
            $settings = ZayataFixtures::features(0x87, [0x8105 => $state])['device_config']['settings'] ?? [];

            self::assertArrayNotHasKey('do_not_disturb', $settings);
        }

        self::assertArrayNotHasKey('do_not_disturb_state', ZayataFixtures::features(0x87, [0x8105 => "\x01"]));
    }

    /** O bloqueio de criança é um interruptor inteiro, e por isso vai no `device_config`. */
    public function testTheChildLockIsReportedConfigurationAndNotTelemetry(): void
    {
        self::assertSame(
            ['settings' => ['child_lock' => ['enabled' => true]]],
            ZayataFixtures::features(0x87, [0x8102 => "\x01"])['device_config'] ?? null,
        );
        self::assertSame(
            ['settings' => ['child_lock' => ['enabled' => false]]],
            ZayataFixtures::features(0x87, [0x8102 => "\x00"])['device_config'] ?? null,
        );
    }

    /** Um alerta só se publica quando dispara, como a avaria aqui ao lado. */
    public function testTheStorageEnvironmentOnlySpeaksWhenItIsOutOfRange(): void
    {
        self::assertSame(
            ['outOfRange' => true],
            ZayataFixtures::features(0x87, [0x8111 => "\x01"])['storage_environment'] ?? null,
        );
        self::assertArrayNotHasKey('storage_environment', ZayataFixtures::features(0x87, [0x8111 => "\x00"]));
    }

    /** O CCID nunca muda e ninguém o consulta: descodifica-se, não se publica. */
    public function testTheSimCardIsNotPublished(): void
    {
        $byFeature = ZayataFixtures::features(0x87, [0x8009 => "8935103211501958977F\x00", 0x810D => "\x03"]);

        self::assertArrayNotHasKey('sim_card', $byFeature);
        self::assertArrayNotHasKey('simCcid', $byFeature['connectivity'] ?? []);
    }

    /** Uma TAG recusada volta com os zeros que lhe mandámos: o estado no Flag é que decide. */
    public function testARefusedTagIsNotPublished(): void
    {
        $byFeature = ZayataFixtures::features(0x87, [
            0x810E => ['value' => "\x18", 'state' => 1],
            0x810B => ['value' => pack('s', 25), 'state' => 0],
        ]);

        self::assertArrayNotHasKey('ambient_temperature', $byFeature);
        self::assertSame(-63, $byFeature['connectivity']['signalStrengthDbm'] ?? null);
    }
}
