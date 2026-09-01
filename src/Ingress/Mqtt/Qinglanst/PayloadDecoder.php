<?php

namespace Hub\Ingress\Mqtt\Qinglanst;

final class PayloadDecoder
{
    /**
     * Os códigos do documento do fabricante, já na enumeração que sai no MQTT. Traduzir para
     * o idioma de quem lê é trabalho de quem desenha o ecrã.
     */
    private const POSTURE = [
        0 => 'initialization',
        1 => 'walking',
        2 => 'suspected_fall',
        3 => 'squatting',
        4 => 'standing',
        5 => 'fall_confirmation',
        6 => 'lying_down',
        7 => 'suspected_sitting_on_ground',
        8 => 'confirmed_sitting_on_ground',
        9 => 'sitting_up_bed',
        10 => 'suspected_sitting_up_bed',
        11 => 'confirmed_sitting_up_bed',
    ];

    private const LAST_EVENT = [
        0 => 'no_event',
        1 => 'enter_room',
        2 => 'leave_room',
        3 => 'enter_area',
        4 => 'leave_area',
    ];

    private const SLEEP_STATE = [
        0 => 'undefined',
        1 => 'light_sleep',
        2 => 'deep_sleep',
        3 => 'awake',
    ];

    private const BREATHING_STATUS = [
        0 => 'normal',
        1 => 'hypopnea',
        2 => 'hyperpnea',
        3 => 'apnea',
    ];

    private const HEART_STATUS = [
        0 => 'normal',
        1 => 'low',
        2 => 'high',
        3 => 'undefined',
    ];

    private const VITAL_STATUS = [
        0 => 'normal',
        1 => 'undefined',
        2 => 'undefined',
        3 => 'weak',
    ];

    /**
     * @return array{type: string, device_code: string, ...}|null
     */
    public function decode(string $messageType, string $base64, ?string $deviceCode): ?array
    {
        $raw = base64_decode($base64, true);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoder = match ($messageType) {
            'position' => $this->decodePosition(...),
            'heartbreath' => $this->decodeHeartBreath(...),
            'posstatics' => $this->decodePosStatics(...),
            'hbstatics' => $this->decodeHbStatics(...),
            default => null,
        };

        if ($decoder === null) {
            return null;
        }

        return $decoder($raw, $deviceCode);
    }

    /**
     * @return array{type: string, device_code: string, people: array}
     */
    private function decodePosition(string $raw, ?string $deviceCode): array
    {
        $length = strlen($raw);
        $count = $length / 16;
        $people = [];

        for ($i = 0; $i < $count; $i++) {
            $offset = $i * 16;
            $personIndex = ord($raw[$offset]);
            $xByte = ord($raw[$offset + 1]);
            $yByte = ord($raw[$offset + 2]);
            $x = $xByte > 127 ? $xByte - 256 : $xByte;
            $y = $yByte > 127 ? $yByte - 256 : $yByte;
            $postureCode = ord($raw[$offset + 13]);
            $eventCode = ord($raw[$offset + 14]);

            $people[] = [
                'person_index' => $personIndex,
                'x_position_dm' => $x,
                'y_position_dm' => $y,
                'z_position_cm' => ord($raw[$offset + 3]),
                'time_left_s' => ord($raw[$offset + 12]),
                'posture_state' => self::POSTURE[$postureCode] ?? 'unknown',
                'last_event' => self::LAST_EVENT[$eventCode] ?? 'unknown',
                'region_id' => ord($raw[$offset + 15]),
            ];
        }

        return [
            'type' => 'position',
            'device_code' => $deviceCode,
            'people' => $people,
        ];
    }

    /**
     * @return array{type: string, device_code: string, ...}
     */
    private function decodeHeartBreath(string $raw, ?string $deviceCode): array
    {
        $statusByte = ord($raw[13]);
        $sleepStateBits = ($statusByte & 0b11000000) >> 6;

        return [
            'type' => 'heartbreath',
            'device_code' => $deviceCode,
            'breathing' => ord($raw[1]),
            'heart_rate' => ord($raw[2]),
            'sleep_state' => self::SLEEP_STATE[$sleepStateBits] ?? 'undefined',
        ];
    }

    /**
     * @return array{type: string, device_code: string, ...}
     */
    private function decodePosStatics(string $raw, ?string $deviceCode): array
    {
        $version = ord($raw[1]);
        $breathingActive = ($version >= 2) ? ((ord($raw[10]) & 0b00000001) !== 0) : false;

        return [
            'type' => 'posstatics',
            'device_code' => $deviceCode,
            'version' => $version,
            'people' => ord($raw[2]),
            'walking_distance' => (ord($raw[3]) << 8) + ord($raw[4]),
            'walking_time' => ord($raw[5]),
            'meditation_time' => ord($raw[6]),
            'in_bed_time' => ord($raw[7]),
            'standing_time' => ord($raw[8]),
            'multiplayer_time' => ord($raw[9]),
            'breathing_active' => $breathingActive,
        ];
    }

    /**
     * @return array{type: string, device_code: string, ...}
     */
    private function decodeHbStatics(string $raw, ?string $deviceCode): array
    {
        $statusByte = ord($raw[13]);

        return [
            'type' => 'hbstatics',
            'device_code' => $deviceCode,
            'real_time_breathing' => ord($raw[1]),
            'real_time_heart_rate' => ord($raw[2]),
            'avg_breathing_per_minute' => ord($raw[5]),
            'avg_heart_rate_per_minute' => ord($raw[6]),
            'breathing_status_per_minute' => self::BREATHING_STATUS[$statusByte & 0b00000011] ?? 'normal',
            'heart_rate_status_per_minute' => self::HEART_STATUS[($statusByte & 0b00001100) >> 2] ?? 'normal',
            'vital_signs_status' => self::VITAL_STATUS[($statusByte & 0b00110000) >> 4] ?? 'normal',
            'sleep_state_status' => self::SLEEP_STATE[($statusByte & 0b11000000) >> 6] ?? 'undefined',
        ];
    }
}
