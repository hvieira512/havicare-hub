<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

final class PillDispenserAdapterTest extends TestCase
{
    public function testCrc16ModbusMatchesKnownVector(): void
    {
        // Vetor de referência do CRC-16/MODBUS: "123456789" -> 0x4B37.
        self::assertSame(0x4B37, PillDispenserAdapter::crc16Modbus('123456789'));
    }

    public function testEncodedFrameHasTheWireLayout(): void
    {
        $adapter = new PillDispenserAdapter();
        $frame = $adapter->encodeOutgoing([
            'packetType' => 0x02,
            'mac' => 'AABBCCDDEEFF',
        ]);

        self::assertSame("\xAA", $frame[0], 'começa em 0xAA');
        self::assertSame(0x02, ord($frame[10]), 'tipo de dispositivo M2 no offset 10');
        self::assertSame(0x02, ord($frame[19]), 'tipo de pacote no offset 19');

        // Length conta de Status ao fim dos dados, e a trama total é 3 + Length + 2.
        $length = unpack('v', substr($frame, 1, 2))[1];
        self::assertSame(3 + $length + 2, strlen($frame));

        // O CRC cobre de Length ao fim dos dados e vai em ordem do anfitrião (LE).
        $region = substr($frame, 1, strlen($frame) - 3);
        self::assertSame(
            PillDispenserAdapter::crc16Modbus($region),
            unpack('v', substr($frame, -2))[1],
        );
    }

    public function testCanDecodeAcceptsOwnFrameAndRejectsForeign(): void
    {
        $adapter = new PillDispenserAdapter();
        $frame = $adapter->encodeOutgoing(['packetType' => 0x02, 'mac' => 'AABBCCDDEEFF']);

        self::assertTrue($adapter->canDecode($frame));
        self::assertFalse($adapter->canDecode('IWAP49,72#'));
        self::assertFalse($adapter->canDecode(pack('nn', 0xFCAF, 3) . 'abc'));
    }

    public function testCanDecodeRejectsCorruptedCrc(): void
    {
        $adapter = new PillDispenserAdapter();
        $frame = $adapter->encodeOutgoing(['packetType' => 0x02, 'mac' => 'AABBCCDDEEFF']);

        $frame[strlen($frame) - 1] = chr(ord($frame[strlen($frame) - 1]) ^ 0xFF);

        self::assertFalse($adapter->canDecode($frame));
        self::assertNull($adapter->decodeIncoming($frame));
    }

    public function testDecodesMacIdentityFromDeviceNumber(): void
    {
        $adapter = new PillDispenserAdapter();
        $frame = $adapter->encodeOutgoing(['packetType' => 0x01, 'mac' => 'AABBCCDDEEFF']);

        $decoded = $adapter->decodeIncoming($frame);

        self::assertIsArray($decoded);
        self::assertSame('register', $decoded['type']);
        self::assertSame('AABBCCDDEEFF', $decoded['imei']);
        self::assertSame('mac', $decoded['idKind']);
    }

    public function testDecodesImeiIdentityFromDeviceNumber(): void
    {
        $adapter = new PillDispenserAdapter();
        $frame = $adapter->encodeOutgoing(['packetType' => 0x01, 'imei' => '860123456789012']);

        $decoded = $adapter->decodeIncoming($frame);

        self::assertIsArray($decoded);
        self::assertSame('860123456789012', $decoded['imei']);
        self::assertSame('imei', $decoded['idKind']);
    }

    public function testOnlyFlagBitOneWaivesTheReply(): void
    {
        $adapter = new PillDispenserAdapter();
        $decode = static function (int $flag) use ($adapter): array {
            $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
                'packetType' => 0x02,
                'mac' => 'AABBCCDDEEFF',
                'flag' => $flag,
            ]));
            self::assertIsArray($decoded);

            return $decoded;
        };

        // Bit 0 é reservado pela especificação; quem dispensa a resposta é o bit 1.
        self::assertFalse($decode(0x00)['waivesReply']);
        self::assertFalse($decode(0x01)['waivesReply']);
        self::assertTrue($decode(0x02)['waivesReply']);
        self::assertTrue($decode(0x06)['waivesReply']);
    }

    public function testDecodesMedicationEventTlv(): void
    {
        $adapter = new PillDispenserAdapter();
        $frame = $adapter->encodeOutgoing([
            'packetType' => 0x03,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0xC201 => ['value' => "\x02"],                       // alarme 3 (0-8)
                0xC202 => ['value' => '2026-09-18T20:05:00'],        // hora prevista
                0xC203 => ['value' => '2026-09-18T20:05:04'],        // hora da toma
                0xC204 => ['value' => "\x0C"],                       // célula 12
                0xC205 => ['value' => "\x00"],                       // método: a horas
                0xC206 => ['value' => "\x00"],                       // resultado: a horas
            ],
        ]);

        $decoded = $adapter->decodeIncoming($frame);

        self::assertIsArray($decoded);
        self::assertSame('event', $decoded['type']);
        self::assertArrayHasKey(0xC201, $decoded['tlv']);
        self::assertSame("\x02", $decoded['tlv'][0xC201]['value']);
        self::assertSame('2026-09-18T20:05:04', $decoded['tlv'][0xC203]['value']);
        self::assertSame("\x0C", $decoded['tlv'][0xC204]['value']);
    }
}
