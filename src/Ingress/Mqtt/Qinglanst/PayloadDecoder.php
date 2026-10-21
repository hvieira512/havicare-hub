<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Qinglanst;

final class PayloadDecoder
{
    /**
     * Os códigos do documento do fabricante, já na enumeração que sai no MQTT. O 9 e o 11 são o
     * mesmo sentar na cama, numa cama comum e numa de monitorização.
     */
    private const POSTURE = [
        0 => 'initialization',
        1 => 'walking',
        2 => 'suspected_fall',
        3 => 'sitting',
        4 => 'standing',
        5 => 'fallen',
        6 => 'lying_down',
        7 => 'suspected_sitting_on_ground',
        8 => 'sitting_on_ground',
        9 => 'sitting_up_in_bed',
        10 => 'suspected_sitting_up_in_bed',
        11 => 'sitting_up_in_bed',
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
     * Os campos saem já com os nomes do contrato.
     *
     * @return array{type: string, deviceCode: string|null, ...}|null
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
     * Dezasseis bytes por pessoa; um comprimento que não divida não se lê.
     *
     * @return array{type: string, deviceCode: string|null, people: list<array<string, mixed>>}|null
     */
    private function decodePosition(string $raw, ?string $deviceCode): ?array
    {
        $length = strlen($raw);
        if ($length % 16 !== 0) {
            return null;
        }
        $count = intdiv($length, 16);
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
                'personIndex' => $personIndex,
                'xPositionDm' => $x,
                'yPositionDm' => $y,
                'zPositionCm' => ord($raw[$offset + 3]),
                'timeLeftS' => ord($raw[$offset + 12]),
                'posture' => self::POSTURE[$postureCode] ?? 'unknown',
                'lastEvent' => self::LAST_EVENT[$eventCode] ?? 'unknown',
                'areaId' => ord($raw[$offset + 15]),
            ];
        }

        return [
            'type' => 'position',
            'deviceCode' => $deviceCode,
            'people' => $people,
        ];
    }

    /**
     * @return array{type: string, deviceCode: string|null, ...}
     */
    private function decodeHeartBreath(string $raw, ?string $deviceCode): array
    {
        $statusByte = ord($raw[13]);
        $sleepStateBits = ($statusByte & 0b11000000) >> 6;

        return [
            'type' => 'heartbreath',
            'deviceCode' => $deviceCode,
            'breathsPerMinute' => ord($raw[1]),
            'heartRateBpm' => ord($raw[2]),
            'sleepState' => self::SLEEP_STATE[$sleepStateBits] ?? 'undefined',
        ];
    }

    /**
     * @return array{type: string, deviceCode: string|null, ...}
     */
    private function decodePosStatics(string $raw, ?string $deviceCode): array
    {
        $version = ord($raw[1]);
        $breathingActive = ($version >= 2) ? ((ord($raw[10]) & 0b00000001) !== 0) : false;

        return [
            'type' => 'posstatics',
            'deviceCode' => $deviceCode,
            'version' => $version,
            'peopleCount' => ord($raw[2]),
            'walkingDistanceMeters' => (ord($raw[3]) << 8) + ord($raw[4]),
            'walkingTimeS' => ord($raw[5]),
            'meditationTimeS' => ord($raw[6]),
            'inBedTimeS' => ord($raw[7]),
            'standingTimeS' => ord($raw[8]),
            'multiplayerTimeS' => ord($raw[9]),
            'breathingActive' => $breathingActive,
        ];
    }

    /**
     * @return array{type: string, deviceCode: string|null, ...}
     */
    private function decodeHbStatics(string $raw, ?string $deviceCode): array
    {
        $statusByte = ord($raw[13]);

        return [
            'type' => 'hbstatics',
            'deviceCode' => $deviceCode,
            'realTimeBreathsPerMinute' => ord($raw[1]),
            'realTimeHeartRateBpm' => ord($raw[2]),
            'avgBreathsPerMinute' => ord($raw[5]),
            'avgHeartRateBpm' => ord($raw[6]),
            'breathingStatus' => self::BREATHING_STATUS[$statusByte & 0b00000011] ?? 'normal',
            'heartRateStatus' => self::HEART_STATUS[($statusByte & 0b00001100) >> 2] ?? 'normal',
            'vitalSignsStatus' => self::VITAL_STATUS[($statusByte & 0b00110000) >> 4] ?? 'normal',
            'sleepState' => self::SLEEP_STATE[($statusByte & 0b11000000) >> 6] ?? 'undefined',
        ];
    }
}
