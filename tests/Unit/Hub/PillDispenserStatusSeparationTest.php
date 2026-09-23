<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Cada coisa na sua capacidade, e o que não serve a ninguém não é capacidade nenhuma.
 *
 * O `device_status` tinha virado uma gaveta. Lá dentro iam o sinal — que muda de minuto a
 * minuto e enche o histórico —, o número do cartão SIM, e o estado do bloqueio de criança,
 * que já é uma configuração com valor reportado. Na lista de eventos saíam todos com a mesma
 * etiqueta, «Estado do dispositivo».
 *
 * A separação segue o que cada coisa é: o que muda ao minuto é telemetria, o que se configura
 * tem o seu valor reportado e não uma segunda verdade ao lado, e o CCID não é nem uma nem
 * outra — é um identificador que ninguém vai ler ali.
 */
final class PillDispenserStatusSeparationTest extends TestCase
{
    /** O estado do aparelho fica com o que muda e interessa operar. */
    public function testDeviceStatusKeepsWhatChanges(): void
    {
        $status = $this->telemetry([
            0x810B => pack('s', 25),
            0x810D => "\x03",
            0x8107 => "\x01",
            0x8109 => "\x01",
            0x8111 => "\x00",
        ])['device_status'] ?? [];

        self::assertSame(-25, $status['gsmSignalDbm'] ?? null);
        self::assertSame(3, $status['signalLevel'] ?? null);
        self::assertTrue($status['lidOpen'] ?? null);
        self::assertTrue($status['mainsPowered'] ?? null);
        self::assertFalse($status['environmentAlarm'] ?? null);
    }

    /**
     * O cartão SIM não sai de todo.
     *
     * Primeiro foi separado do estado do dispositivo para capacidade própria, e o argumento
     * estava certo — uma coisa é o sinal, outra é o cartão. A conclusão é que não é nenhuma
     * das duas: o CCID é um identificador que nunca muda, ninguém o consulta na dashboard, e
     * cada leitura de estado deixava mais uma linha na lista de eventos a repetir o mesmo
     * número. O adaptador continua a descodificá-lo; o contrato não o publica.
     */
    public function testTheSimCardIsNotPublished(): void
    {
        $eventos = $this->telemetry([0x8009 => "8935103211501958977F\x00", 0x810D => "\x03"]);

        self::assertArrayNotHasKey('sim_card', $eventos);
        self::assertArrayNotHasKey('simCcid', $eventos['device_status'] ?? []);
    }

    /**
     * O bloqueio de criança é uma configuração, e o que o aparelho reporta é o valor dela.
     *
     * Publicá-lo também como telemetria dava duas verdades sobre a mesma coisa, e nada as
     * obrigava a concordar.
     */
    public function testTheChildLockIsNotTelemetry(): void
    {
        $eventos = $this->telemetry([0x8102 => "\x01", 0x810D => "\x03"]);

        self::assertArrayNotHasKey('childLockEngaged', $eventos['device_status'] ?? []);
        self::assertSame(['enabled' => true], $eventos['device_config']['settings']['child_lock'] ?? null);
    }

    /** Uma trama sem nada disto não publica capacidade nenhuma vazia. */
    public function testNothingIsPublishedWithoutReadings(): void
    {
        $eventos = $this->telemetry([0x8103 => "\x64"]);

        self::assertArrayNotHasKey('sim_card', $eventos);
        self::assertArrayNotHasKey('device_status', $eventos);
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

        $porCapacidade = [];
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            $porCapacidade[$event['feature']] = $event['value'];
        }

        return $porCapacidade;
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
