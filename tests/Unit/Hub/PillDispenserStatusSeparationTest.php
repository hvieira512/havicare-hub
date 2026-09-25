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
     * Os dois sensores de estado físico não saem do descodificador: nenhum lê a peça que diz
     * ler, e cada um responde sempre o mesmo.
     *
     * O `0x8107` deu `0` com o prato trancado, com a fechadura de chave trancada e com o
     * prato fora do aparelho; o `0x8106` deu `1` com o copo fora. Um cartão que só sabe dizer
     * um valor ensina a não confiar nos outros.
     */
    public function testThePhysicalStateTagsDoNotBecomeTelemetry(): void
    {
        self::assertArrayNotHasKey('tray_lock', $this->telemetry([0x8107 => "\x01"]));
        self::assertArrayNotHasKey('tray_lock', $this->telemetry([0x8107 => "\x00"]));
        self::assertArrayNotHasKey('medication_cup', $this->telemetry([0x8106 => "\x01"]));
        self::assertArrayNotHasKey('medication_cup', $this->telemetry([0x8106 => "\x00"]));
    }

    /**
     * O «não incomodar» é configuração reportada, e não uma leitura.
     *
     * Dos três valores do `0x8105`, dois são o eco do que o hub escreveu na janela de
     * silêncio. Teve mosaico próprio durante umas horas e não devia: vai no `device_config`,
     * ao lado do bloqueio de criança, que é o outro interruptor que o aparelho reporta.
     */
    public function testDoNotDisturbIsReportedConfigurationAndNotTelemetry(): void
    {
        self::assertArrayNotHasKey('do_not_disturb_state', $this->telemetry([0x8105 => "\x01"]));

        self::assertSame(
            ['settings' => ['do_not_disturb' => ['enabled' => true]]],
            $this->telemetry([0x8105 => "\x01"])['device_config'] ?? null,
        );
        self::assertSame(
            ['settings' => ['do_not_disturb' => ['enabled' => false]]],
            $this->telemetry([0x8105 => "\x00"])['device_config'] ?? null,
        );
        // «Ligado e a silenciar agora» continua a ser ligado: o minuto em que a janela está
        // a produzir efeito deduz-se dela e do relógio, e ninguém age sobre ele.
        self::assertSame(
            ['settings' => ['do_not_disturb' => ['enabled' => true]]],
            $this->telemetry([0x8105 => "\x02"])['device_config'] ?? null,
        );
    }

    /** E os dois interruptores reportados chegam juntos, num `device_config` só. */
    public function testTheReportedSwitchesTravelTogether(): void
    {
        self::assertSame(
            ['settings' => [
                'do_not_disturb' => ['enabled' => true],
                'child_lock' => ['enabled' => true],
            ]],
            $this->telemetry([0x8105 => "\x01", 0x8102 => "\x01"])['device_config'] ?? null,
        );
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
                0x810E => ['value' => "\x18", 'state' => 1],
                0x810B => ['value' => pack('s', 25), 'state' => 0],
            ],
        ]));
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        self::assertArrayNotHasKey('temperature', $byFeature);
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
