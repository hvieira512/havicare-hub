<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O que o aparelho diz de si e o hub deitava fora.
 *
 * A descoberta de parâmetros (`0x0B`) mostrou que o M228 anuncia quarenta e três TAGs de
 * estado e o hub lia dezassete. Destas, quatro dizem coisas que um cuidador quer saber e que
 * não havia outra maneira de descobrir: se a tampa está aberta, se o aparelho está ligado à
 * corrente, se a temperatura ou a humidade saíram da gama, e que cartão SIM lá está dentro.
 *
 * Os valores vêm da especificação do fornecedor, e a leitura de cada um foi confirmada contra
 * o aparelho: a tampa a `0` fechada, a alimentação a `1` ligada, o alarme de ambiente a `0`.
 */
final class PillDispenserDeviceStatusTest extends TestCase
{
    public function testTheLidStateIsPublished(): void
    {
        self::assertSame(false, $this->deviceStatus([0x8107 => "\x00"])['lidOpen'] ?? null);
        self::assertSame(true, $this->deviceStatus([0x8107 => "\x01"])['lidOpen'] ?? null);
    }

    /** Um dispensador sem corrente fica a viver da bateria, e isso é uma condição a mostrar. */
    public function testTheMainsPowerIsPublished(): void
    {
        self::assertSame(true, $this->deviceStatus([0x8109 => "\x01"])['mainsPowered'] ?? null);
        self::assertSame(false, $this->deviceStatus([0x8109 => "\x00"])['mainsPowered'] ?? null);
    }

    /**
     * O aparelho tem gama para os comprimidos, e diz quando sai dela.
     *
     * A temperatura e a humidade já eram publicadas em bruto; o que faltava era o próprio
     * aparelho a dizer que o valor é impróprio, que é um juízo que ele faz e nós não.
     */
    public function testTheEnvironmentAlarmIsPublished(): void
    {
        self::assertSame(true, $this->deviceStatus([0x8111 => "\x01"])['environmentAlarm'] ?? null);
        self::assertSame(false, $this->deviceStatus([0x8111 => "\x00"])['environmentAlarm'] ?? null);
    }

    /** Saber que SIM está dentro de cada aparelho poupa abrir a tampa para ver. */
    public function testTheSimCcidIsPublished(): void
    {
        $status = $this->deviceStatus([0x8009 => "8935101900123456789\x00"]);

        self::assertSame('8935101900123456789', $status['simCcid'] ?? null);
    }

    /** Uma TAG que o aparelho recusa não vira valor, que era como se publicava zero. */
    public function testARefusedTagIsNotPublished(): void
    {
        // O estado da leitura viaja nos bits 5--7 do Flag, e a trama leva-o tal como o
        // aparelho o manda: uma TAG recusada volta com os bytes que lhe mandámos, zeros.
        $adapter = new PillDispenserAdapter();
        $status = $this->statusOf($adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x87,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x8109 => ['value' => "\x00", 'state' => 1],
                0x810D => ['value' => "\x03", 'state' => 0],
            ],
        ])));

        self::assertArrayNotHasKey('mainsPowered', $status);
        self::assertSame(3, $status['signalLevel'] ?? null);
    }

    /**
     * @param array<int, string> $tlv
     * @return array<string, mixed>
     */
    private function deviceStatus(array $tlv): array
    {
        $adapter = new PillDispenserAdapter();
        $entries = [];
        foreach ($tlv as $tag => $value) {
            $entries[$tag] = ['value' => $value];
        }

        return $this->statusOf($adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x87,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => $entries,
        ])));
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function statusOf(array $decoded): array
    {
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            if ($event['feature'] === 'device_status') {
                return $event['value'];
            }
        }

        return [];
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
