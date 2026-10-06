<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Gateway\GatewayTopic;
use Hub\Support\Values;

/**
 * Descodifica uma MOKO W6B (BXP-B) retransmitida: já interpretada pelo gateway (`bxp-button`),
 * ou em bytes crus pelo "MOKO Beacon - ADV Format Summary Sheet", com o mesmo resultado.
 */
final class W6bDecoder
{
    /** Os UUID de serviço como aparecem no fio (little endian). */
    private const ALARM_SERVICE = 'e0fe';
    private const INFO_SERVICE = '00ea';

    /** O gateway tira a base 0x20 ao tipo de frame de alarme: o toque simples chega como 0. */
    private const PRESS_MODES = [
        0 => 'single',
        1 => 'double',
        2 => 'long',
        3 => 'inactivity',
    ];

    private const RAW_FRAME_BASE = 0x20;

    /**
     * @param array<string, mixed> $observation a single entry of a 3070 scan report
     * @return array<string, mixed>|null null when the payload is not a W6B
     */
    public function decode(array $observation): ?array
    {
        $mac = GatewayTopic::normalizeMac((string)($observation['mac'] ?? ''));
        if ($mac === null) {
            return null;
        }

        $decoded = $this->fromGatewayFields($observation) ?? $this->fromAdvertisingData($observation);
        if ($decoded === null) {
            return null;
        }

        // O RSSI é medido pelo gateway e só existe na observação.
        return Values::withoutNulls(
            ['mac' => $mac, 'rssiDbm' => is_numeric($observation['rssi'] ?? null) ? (int)$observation['rssi'] : null] + $decoded,
        );
    }

    /**
     * @param array<string, mixed> $observation
     * @return array<string, mixed>|null
     */
    private function fromGatewayFields(array $observation): ?array
    {
        if ((string)($observation['type'] ?? '') !== 'bxp-button') {
            return null;
        }

        $pressMode = self::PRESS_MODES[(int)($observation['frame_type'] ?? -1)] ?? null;
        if ($pressMode === null || !isset($observation['trigger_count'])) {
            return null;
        }

        $alarm = [
            'pressMode' => $pressMode,
            'triggerCount' => (int)$observation['trigger_count'],
            'triggered' => (int)($observation['alarm_status'] ?? 0) === 1,
            'deviceId' => (string)($observation['device_id'] ?? ''),
        ];

        return Values::withoutNulls([
            'alarm' => $alarm,
            // Só chegam nos avistamentos em que a resposta ao scan foi capturada.
            'info' => $this->gatewayInfo($observation),
        ]);
    }

    /**
     * @param array<string, mixed> $observation
     * @return array<string, mixed>|null
     */
    private function gatewayInfo(array $observation): ?array
    {
        $hasAcceleration = isset($observation['x_axis_data'], $observation['y_axis_data'], $observation['z_axis_data']);
        if (!$hasAcceleration && !isset($observation['batt_vol'])) {
            return null;
        }

        $info = [];
        if ($hasAcceleration) {
            $info['accelerationMg'] = [
                'x' => (int)$observation['x_axis_data'],
                'y' => (int)$observation['y_axis_data'],
                'z' => (int)$observation['z_axis_data'],
            ];
        }
        if (isset($observation['batt_vol'])) {
            $info += $this->battery((int)$observation['batt_vol']);
        }

        return $info;
    }

    /**
     * @param array<string, mixed> $observation
     * @return array<string, mixed>|null
     */
    private function fromAdvertisingData(array $observation): ?array
    {
        $structures = array_merge(
            $this->adStructures((string)($observation['adv_data'] ?? '')),
            $this->adStructures((string)($observation['rsp_data'] ?? '')),
        );

        $alarm = $this->rawAlarm($this->serviceData($structures, self::ALARM_SERVICE));
        $info = $this->rawInfo($this->serviceData($structures, self::INFO_SERVICE));
        if ($alarm === null && $info === null) {
            return null;
        }

        return Values::withoutNulls([
            'alarm' => $alarm,
            'info' => $info,
        ]);
    }

    /**
     * @param list<int>|null $data service data payload with the UUID stripped
     * @return array<string, mixed>|null
     */
    private function rawAlarm(?array $data): ?array
    {
        // tipo de frame, flag de estado, contagem em 2 bytes, id em 6 bytes, tipo de firmware
        if ($data === null || count($data) < 11) {
            return null;
        }

        $pressMode = self::PRESS_MODES[$data[0] - self::RAW_FRAME_BASE] ?? null;
        if ($pressMode === null) {
            return null;
        }

        return [
            'pressMode' => $pressMode,
            'triggerCount' => ($data[2] << 8) | $data[3],
            // O bit 1 é o estado de alarme do botão principal; o bit 2 é um botão secundário
            // que só o firmware BXP-B03-D tem.
            'triggered' => ($data[1] & 0x02) !== 0,
            'deviceId' => implode('', array_map(
                static fn(int $byte): string => sprintf('%02x', $byte),
                array_slice($data, 4, 6)
            )),
        ];
    }

    /**
     * @param list<int>|null $data service data payload with the UUID stripped
     * @return array<string, mixed>|null
     */
    private function rawInfo(?array $data): ?array
    {
        // tipo de frame, escala, limiar, x, y, z, temperatura, alcance, bateria
        if ($data === null || count($data) < 15 || $data[0] !== 0x00) {
            return null;
        }

        return [
            'accelerationMg' => [
                'x' => $this->signed16($data[4], $data[5]),
                'y' => $this->signed16($data[6], $data[7]),
                'z' => $this->signed16($data[8], $data[9]),
            ],
        ] + $this->battery(($data[13] << 8) | $data[14]);
    }

    /**
     * Acima de 100 o campo traz milivolts em vez de percentagem.
     *
     * @return array<string, int>
     */
    private function battery(int $value): array
    {
        return $value > 100 ? ['batteryVoltageMv' => $value] : ['batteryPercent' => $value];
    }

    /**
     * @param list<array{type: int, data: list<int>}> $structures
     * @return list<int>|null
     */
    private function serviceData(array $structures, string $wireUuid): ?array
    {
        foreach ($structures as $structure) {
            if ($structure['type'] !== 0x16 || count($structure['data']) < 2) {
                continue;
            }
            $uuid = sprintf('%02x%02x', $structure['data'][0], $structure['data'][1]);
            if ($uuid === $wireUuid) {
                return array_slice($structure['data'], 2);
            }
        }

        return null;
    }

    private function signed16(int $high, int $low): int
    {
        $value = ($high << 8) | $low;

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    /** @return list<array{type: int, data: list<int>}> */
    private function adStructures(string $hex): array
    {
        $hex = strtolower(trim($hex));
        if ($hex === '' || strlen($hex) % 2 !== 0 || preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
            return [];
        }

        $bytes = array_values(unpack('C*', hex2bin($hex) ?: ''));
        $structures = [];
        $offset = 0;
        $count = count($bytes);
        while ($offset < $count) {
            $length = $bytes[$offset];
            if ($length === 0 || $offset + $length >= $count + 1) {
                break;
            }
            $structures[] = [
                'type' => $bytes[$offset + 1] ?? 0,
                'data' => array_values(array_slice($bytes, $offset + 2, $length - 1)),
            ];
            $offset += $length + 1;
        }

        return $structures;
    }
}
