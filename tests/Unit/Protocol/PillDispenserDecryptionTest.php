<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O M228 cifra tudo o que envia por iniciativa própria, e a chave sai do Device Number.
 *
 * O aparelho liga-se a mandar um heartbeat por minuto com o **bit 2 do Flag** ligado:
 * cabeçalho legível, identidade correcta, CRC válido, e o corpo em AES128-CFB. Durante
 * semanas isso deixou a dashboard sem telemetria e o evento de toma de medicação — a
 * funcionalidade central do aparelho — completamente ilegível.
 *
 * O fornecedor acabou por dizer onde estava a chave: «Both the key and the random IV are
 * based on the device's Device Number». São a mesma coisa, e são o Device Number escrito
 * como string hexadecimal de 16 caracteres: o número de 64 bits deste aparelho é
 * `0x4869243062262262`, e a chave é o texto `4869243062262262`. Dezasseis caracteres, que é
 * exactamente o que o AES-128 precisa.
 *
 * As tramas deste teste são reais, capturadas do aparelho de ensaio a 22 de setembro de 2026.
 * O `0x03` é a toma que alguém fez à mão, com o alarme a tocar em cima da mesa.
 */
final class PillDispenserDecryptionTest extends TestCase
{
    /** Heartbeat cifrado: bateria, sinal, temperatura e humidade. */
    private const HEARTBEAT = 'AA30000001031F000004026222266230246948021EB35A0509D9B171F6D2BB1C'
        . 'EAEA70649DE296A02754B4507058B69C05F08AEF35';

    /** O evento de toma de medicação, cifrado, tal como o aparelho o mandou. */
    private const INTAKE = 'AA55000001C71E000004026222266230246948031CF05A056FDBF271F6D3B25F'
        . 'E2FC5B54DFE6B4BE413A69CBD368BD964E43F81F121A5C6C6518C2A2B0ECE99A'
        . 'DDC28D49E1BA0E24781E513A88DC37ED79CE63A4F8904AF71B31';

    public function testTheHeartbeatBodyIsReadable(): void
    {
        $tlv = $this->decode(self::HEARTBEAT)['tlv'];

        self::assertSame(100, ord($tlv[0x8103]['value']), 'bateria a 100%');
        self::assertSame(1, ord($tlv[0x8104]['value']), 'bateria cheia');
        self::assertSame(25, unpack('v', $tlv[0x810B]['value'])[1], 'sinal GSM');
        self::assertSame(3, ord($tlv[0x810D]['value']), 'nível de sinal');
        self::assertSame(25, ord($tlv[0x810E]['value']), '25 °C');
        self::assertSame(48, ord($tlv[0x810F]['value']), '48 %RH');
    }

    /**
     * O evento de toma, por inteiro.
     *
     * É o que as TAGs de estado dos alarmes não conseguem dar: a hora prevista, a hora real e
     * o compartimento de onde saiu o comprimido.
     */
    public function testTheMedicationEventIsReadable(): void
    {
        $tlv = $this->decode(self::INTAKE)['tlv'];

        self::assertSame(2, ord($tlv[0xC201]['value']), 'alarme 3, contado de zero');
        self::assertSame('2026-09-22T09:35:00', $tlv[0xC202]['value'], 'hora prevista');
        self::assertSame('2026-09-22T09:36:00', $tlv[0xC203]['value'], 'hora da toma');
        self::assertSame(9, ord($tlv[0xC204]['value']), 'compartimento 9');
        self::assertSame(0, ord($tlv[0xC205]['value']), 'a horas');
        self::assertSame(0, ord($tlv[0xC206]['value']), 'tomada');
    }

    /** A cifra anuncia-se no bit 2 do Flag, e o que vem sem ele não se toca. */
    public function testAClearFrameIsNotTouched(): void
    {
        $clear = (new PillDispenserAdapter())->encodeOutgoing([
            'packetType' => 0x87,
            'serial' => 1,
            'deviceNumber' => PillDispenserAdapter::deviceNumberFor('869243062262262'),
            'tlv' => [0x8101 => ['value' => "\x01"]],
        ]);

        self::assertSame("\x01", $this->decode(bin2hex($clear))['tlv'][0x8101]['value']);
    }

    /**
     * Uma trama cifrada que não abra não pode passar por telemetria vazia.
     *
     * Publicar um corpo ilegível como se não trouxesse nada era o que já acontecia, e é a
     * falha calada que este protocolo torna fácil: identidade certa, CRC válido, e nenhum
     * erro em lado nenhum.
     */
    public function testAFrameThatDoesNotOpenIsMarked(): void
    {
        // Trama bem formada — CRC válido, identidade certa — com o bit da cifra ligado e um
        // corpo que a chave deste aparelho não abre.
        $broken = (new PillDispenserAdapter())->encodeOutgoing([
            'packetType' => 0x02,
            'serial' => 1,
            'flag' => 0x04,
            'deviceNumber' => PillDispenserAdapter::deviceNumberFor('869243062262262'),
            'appDataRaw' => random_bytes(24),
        ]);
        $decoded = $this->decode(bin2hex($broken));

        self::assertSame([], $decoded['tlv']);
        self::assertTrue($decoded['encrypted']);
        self::assertFalse($decoded['decrypted']);
    }

    /** @return array<string, mixed> */
    private function decode(string $hex): array
    {
        $decoded = (new PillDispenserAdapter())->decodeIncoming((string)hex2bin($hex));
        self::assertIsArray($decoded);

        return $decoded;
    }
}
