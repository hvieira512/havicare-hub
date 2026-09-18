<?php

namespace Hub\Protocol\Adapter;

/**
 * Descodificador binário do dispensador Zayata/ZoomCare M228 (série M2, device type 0x02).
 *
 * Trama: 0xAA · Length(2) · Status · Version · Serial(2) · Subserial · Subtotal · Flag ·
 * DeviceType · DeviceNumber(8) · PacketType · dados TFLV · CRC16(2). Os inteiros vão em
 * ordem do anfitrião — little-endian no x86 em que o hub corre. O CRC é o de MODBUS,
 * calculado de Length ao fim dos dados.
 */
class PillDispenserAdapter implements DeviceAdapterInterface
{
    private const HEADER = 0xAA;
    private const DEVICE_TYPE = 0x02;

    /** Offsets fixos da trama. */
    private const OFF_STATUS = 3;
    private const OFF_DEVICE_TYPE = 10;
    private const OFF_DEVICE_NUMBER = 11;
    private const OFF_PACKET_TYPE = 19;
    private const OFF_APP_DATA = 20;

    /** Bytes fixos entre o campo Length e os dados (Status..PacketType). */
    private const HEADER_BODY_BYTES = 17;
    private const MIN_FRAME_BYTES = 22;

    public function protocol(): string
    {
        return 'zayata-m228';
    }

    public function canDecode(string $raw): bool
    {
        $length = strlen($raw);
        if ($length < self::MIN_FRAME_BYTES || ord($raw[0]) !== self::HEADER) {
            return false;
        }

        $declared = unpack('v', substr($raw, 1, 2))[1];
        if ($length !== 3 + $declared + 2) {
            return false;
        }

        if (ord($raw[self::OFF_DEVICE_TYPE]) !== self::DEVICE_TYPE) {
            return false;
        }

        $region = substr($raw, 1, $length - 3);
        return self::crc16Modbus($region) === unpack('v', substr($raw, -2))[1];
    }

    public function decodeIncoming(string $raw, array $context = []): ?array
    {
        if (!$this->canDecode($raw)) {
            return null;
        }

        $length = unpack('v', substr($raw, 1, 2))[1];
        $serial = unpack('v', substr($raw, 5, 2))[1];
        $flag = ord($raw[9]);
        $deviceNumber = unpack('P', substr($raw, self::OFF_DEVICE_NUMBER, 8))[1];
        $packetType = ord($raw[self::OFF_PACKET_TYPE]);
        $appData = substr($raw, self::OFF_APP_DATA, $length - self::HEADER_BODY_BYTES);

        $identity = self::decodeDeviceNumber($deviceNumber);
        $type = self::packetTypeName($packetType);
        $tlv = self::parseTlv($appData);

        return [
            'type' => $type,
            'packetType' => $packetType,
            'imei' => $identity['id'],
            'idKind' => $identity['kind'],
            'ident' => (string)$serial,
            'ref' => 'pill:' . $type,
            'deviceNumber' => $deviceNumber,
            'status' => ord($raw[self::OFF_STATUS]),
            'flag' => $flag,
            // Bit 1 do Flag dispensa a resposta do servidor; o bit 0 é reservado e o bit 2
            // marca cifra AES128-CFB. A lógica de ACK vive na camada de protocolo, não aqui.
            'waivesReply' => ($flag & 0x02) === 0x02,
            'tlv' => $tlv,
            'data' => ['idKind' => $identity['kind'], 'tlv' => $tlv],
            'timestamp' => $this->now(),
        ];
    }

    public function encodeOutgoing(array $payload, array $context = []): string
    {
        $deviceNumber = isset($payload['deviceNumber'])
            ? (int)$payload['deviceNumber']
            : self::encodeDeviceNumber(
                (string)($payload['imei'] ?? $payload['mac'] ?? ''),
                isset($payload['imei']) ? 'imei' : 'mac',
            );

        $appData = isset($payload['appDataRaw'])
            ? (string)$payload['appDataRaw']
            : self::packTlv($payload['tlv'] ?? []);

        $body = pack('C', $payload['status'] ?? 0)
            . pack('C', $payload['version'] ?? 0x01)
            . pack('v', $payload['serial'] ?? 0)
            . pack('C', $payload['subserial'] ?? 0)
            . pack('C', $payload['subtotal'] ?? 1)
            . pack('C', $payload['flag'] ?? 0)
            . pack('C', $payload['deviceType'] ?? self::DEVICE_TYPE)
            . pack('P', $deviceNumber)
            . pack('C', $payload['packetType'] ?? 0x02)
            . $appData;

        $region = pack('v', strlen($body)) . $body;

        return pack('C', self::HEADER) . $region . pack('v', self::crc16Modbus($region));
    }

    /** CRC-16/MODBUS: polinómio 0xA001 reflectido, valor inicial 0xFFFF. */
    public static function crc16Modbus(string $data): int
    {
        $crc = 0xFFFF;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]);
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 1) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
            }
        }

        return $crc;
    }

    /**
     * O DeviceNumber de 64 bits codifica um MAC ou um IMEI. Bits 63-62: 00 MAC, 01 IMEI.
     * O IMEI vem em BCD 8421 nos 60 bits inferiores, dígito mais significativo primeiro.
     *
     * @return array{kind: string, id: string}
     */
    public static function decodeDeviceNumber(int $deviceNumber): array
    {
        $kind = ($deviceNumber >> 62) & 0x03;

        if ($kind === 0) {
            return ['kind' => 'mac', 'id' => sprintf('%012X', $deviceNumber & 0xFFFFFFFFFFFF)];
        }

        if ($kind === 1) {
            $value = $deviceNumber & ((1 << 60) - 1);
            $imei = '';
            for ($shift = 56; $shift >= 0; $shift -= 4) {
                $imei .= (string)(($value >> $shift) & 0xF);
            }

            return ['kind' => 'imei', 'id' => $imei];
        }

        return ['kind' => 'unknown', 'id' => ''];
    }

    /**
     * A identidade tal como a whitelist a guarda, de volta ao inteiro de 64 bits. Quinze
     * dígitos são um IMEI; doze hexadecimais são um MAC.
     */
    public static function deviceNumberFor(string $id): int
    {
        $id = trim($id);

        return self::encodeDeviceNumber($id, preg_match('/^\d{15}$/', $id) === 1 ? 'imei' : 'mac');
    }

    public static function encodeDeviceNumber(string $id, string $kind): int
    {
        if ($kind === 'imei') {
            $value = 0;
            $digits = str_pad($id, 15, '0', STR_PAD_LEFT);
            for ($i = 0; $i < 15; $i++) {
                $value = ($value << 4) | (ord($digits[$i]) - 48);
            }

            return (1 << 62) | $value;
        }

        return hexdec($id) & 0xFFFFFFFFFFFF;
    }

    /**
     * Os dados são uma sequência de TFLV: Tag(2) · Flag(1) · Length(1) · Value(Length).
     * Sendo auto-descritivo, o resultado é um mapa de TAG para o valor cru e o seu estado.
     *
     * @return array<int, array{type: int, state: int, value: string}>
     */
    public static function parseTlv(string $data): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($data);

        while ($offset + 4 <= $length) {
            $tag = unpack('v', substr($data, $offset, 2))[1];
            $flag = ord($data[$offset + 2]);
            $valueLength = ord($data[$offset + 3]);
            $value = substr($data, $offset + 4, $valueLength);
            if (strlen($value) < $valueLength) {
                break;
            }

            $out[$tag] = ['type' => $flag & 0x1F, 'state' => ($flag >> 5) & 0x07, 'value' => $value];
            $offset += 4 + $valueLength;
        }

        return $out;
    }

    /** @param array<int, array{type?: int, state?: int, value?: string}> $tlv */
    public static function packTlv(array $tlv): string
    {
        $out = '';
        foreach ($tlv as $tag => $entry) {
            $value = (string)($entry['value'] ?? '');
            $flag = (($entry['type'] ?? 0) & 0x1F) | ((($entry['state'] ?? 0) & 0x07) << 5);
            $out .= pack('v', $tag) . pack('C', $flag) . pack('C', strlen($value)) . $value;
        }

        return $out;
    }

    private static function packetTypeName(int $packetType): string
    {
        return match ($packetType) {
            0x01 => 'register',
            0x02 => 'heartbeat',
            0x03 => 'event',
            0x04 => 'change',
            0x81 => 'register_ack',
            0x82 => 'heartbeat_ack',
            0x83 => 'event_ack',
            0x84 => 'change_ack',
            default => 'unknown',
        };
    }

    protected function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
