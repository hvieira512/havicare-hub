<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Para onde vai cada leitura do estado, e porquê: reutiliza-se a capacidade que o hub já tem,
 * junta-se o que é a mesma pergunta, um alerta só fala quando dispara, e o que ninguém lê não
 * se publica.
 */
final class PillDispenserStatusSeparationTest extends TestCase
{
    /** O sinal sai como a `connectivity` dos gateways, e não num formato só deste aparelho. */
    public function testTheSignalIsPublishedAsConnectivity(): void
    {
        $byFeature = $this->telemetry([
            0x810B => pack('s', 25),
            0x810D => "\x03",
            0x8107 => "\x01",
            0x8109 => "\x01",
        ]);

        self::assertSame(
            ['interface' => 'cellular', 'signalStrengthDbm' => -25],
            $byFeature['connectivity'] ?? null,
        );
        self::assertArrayNotHasKey('device_status', $byFeature);
    }

    /** Esta unidade é 4G, mas a série tem modelos com WiFi e a interface tem de o dizer. */
    public function testAWifiOnlyUnitSaysSo(): void
    {
        self::assertSame(
            ['interface' => 'wifi', 'signalStrengthDbm' => -60],
            $this->telemetry([0x810A => pack('s', 60)])['connectivity'] ?? null,
        );
    }

    /** «Ligado à corrente» e «a carregar» são a mesma pergunta, e vivem no mesmo cartão. */
    public function testTheMainsSupplyTravelsWithTheBattery(): void
    {
        $battery = $this->telemetry([
            0x8103 => "\x64",
            0x8104 => "\x03",
            0x8109 => "\x01",
        ])['battery'] ?? [];

        self::assertSame(100, $battery['percent'] ?? null);
        self::assertSame('charging', $battery['chargingState'] ?? null);
        self::assertTrue($battery['mainsPowered'] ?? null);
    }

    /** O `0x8101` é o juízo do aparelho sobre a contagem do `0x811D`: viaja como campo dela. */
    public function testTheMedicationLevelTravelsWithTheCellCount(): void
    {
        $byFeature = $this->telemetry([0x8101 => "\x02", 0x811D => "\x00", 0x811B => "\x1D"]);

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
            $this->telemetry([0x8101 => "\x01"])['cells_remaining'] ?? null,
        );
    }

    /**
     * O `0x8107` do tipo 02 é o trinco do prato, e não a tampa do tipo 01.
     *
     * A tabela do tipo 01 chama-lhe «Lid Status», com `0` fechada e `1` aberta. Lida assim
     * num M228, a dashboard anunciava «Aberta» exactamente quando o prato estava trancado.
     */
    public function testTheTrayLockIsItsOwnCapability(): void
    {
        self::assertSame(['locked' => true], $this->telemetry([0x8107 => "\x01"])['tray_lock'] ?? null);
        self::assertSame(['locked' => false], $this->telemetry([0x8107 => "\x00"])['tray_lock'] ?? null);
    }

    /**
     * O copo fecha o ciclo físico da toma.
     *
     * Sem ele sabe-se que a dose saiu do compartimento e não se sabe se havia copo onde ela
     * caísse. O aparelho respondeu `0x01` com estado `ok` quando se lhe perguntou.
     */
    public function testTheMedicationCupIsItsOwnCapability(): void
    {
        self::assertSame(['inserted' => true], $this->telemetry([0x8106 => "\x01"])['medication_cup'] ?? null);
        self::assertSame(['inserted' => false], $this->telemetry([0x8106 => "\x00"])['medication_cup'] ?? null);
    }

    /** O «não incomodar» tem três estados, e o terceiro é o que a configuração não diz. */
    public function testDoNotDisturbHasAThirdStateTheConfigurationCannotTell(): void
    {
        self::assertSame(['state' => 'off'], $this->telemetry([0x8105 => "\x00"])['do_not_disturb_state'] ?? null);
        self::assertSame(['state' => 'on'], $this->telemetry([0x8105 => "\x01"])['do_not_disturb_state'] ?? null);
        self::assertSame(['state' => 'silencing'], $this->telemetry([0x8105 => "\x02"])['do_not_disturb_state'] ?? null);
    }

    /** Um alerta só se publica quando dispara, como a avaria aqui ao lado. */
    public function testTheStorageEnvironmentOnlySpeaksWhenItIsOutOfRange(): void
    {
        self::assertSame(
            ['outOfRange' => true],
            $this->telemetry([0x8111 => "\x01"])['storage_environment'] ?? null,
        );
        self::assertArrayNotHasKey('storage_environment', $this->telemetry([0x8111 => "\x00"]));
    }

    /** O CCID nunca muda e ninguém o consulta: descodifica-se, não se publica. */
    public function testTheSimCardIsNotPublished(): void
    {
        $byFeature = $this->telemetry([0x8009 => "8935103211501958977F\x00", 0x810D => "\x03"]);

        self::assertArrayNotHasKey('sim_card', $byFeature);
        self::assertArrayNotHasKey('simCcid', $byFeature['connectivity'] ?? []);
    }

    /** O bloqueio é uma configuração: o que o aparelho reporta é o valor dela, não telemetria. */
    public function testTheChildLockIsNotTelemetry(): void
    {
        $byFeature = $this->telemetry([0x8102 => "\x01", 0x810D => "\x03"]);

        self::assertArrayNotHasKey('childLockEngaged', $byFeature['connectivity'] ?? []);
        self::assertSame(['enabled' => true], $byFeature['device_config']['settings']['child_lock'] ?? null);
    }

    /** Uma trama sem nada disto não publica capacidade nenhuma vazia. */
    public function testNothingIsPublishedWithoutReadings(): void
    {
        $byFeature = $this->telemetry([0x8103 => "\x64"]);

        self::assertArrayNotHasKey('sim_card', $byFeature);
        self::assertArrayNotHasKey('device_status', $byFeature);
    }

    /** Uma TAG recusada volta com os zeros que lhe mandámos: o estado no Flag é que decide. */
    public function testARefusedTagIsNotPublished(): void
    {
        $adapter = new PillDispenserAdapter();
        $byFeature = [];
        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x87,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x8107 => ['value' => "\x00", 'state' => 1],
                0x810B => ['value' => pack('s', 25), 'state' => 0],
            ],
        ]));
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        self::assertArrayNotHasKey('tray_lock', $byFeature);
        self::assertSame(-25, $byFeature['connectivity']['signalStrengthDbm'] ?? null);
    }

    /**
     * @param array<int, string> $tlv
     * @return array<string, array<string, mixed>>
     */
    private function telemetry(array $tlv): array
    {
        $adapter = new PillDispenserAdapter();
        $entries = [];
        foreach ($tlv as $tag => $value) {
            $entries[$tag] = ['value' => $value];
        }

        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x87,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => $entries,
        ]));

        $byFeature = [];
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        return $byFeature;
    }

    private function session(): DeviceSession
    {
        return new DeviceSession(
            new PillFakeConnection(),
            'tcp',
            true,
            'AABBCCDDEEFF',
            'zayata-m228',
            'Zayata',
            'M228',
            'Zayata M228',
            'pill_dispenser',
        );
    }
}
