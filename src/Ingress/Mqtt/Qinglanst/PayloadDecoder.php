<?php

namespace Hub\Ingress\Mqtt\Qinglanst;

final class PayloadDecoder
{
    /**
     * @return array{type: string, device_code: string, ...}|null
     */
    public function decode(string $base64, ?string $deviceCode): ?array
    {
        $raw = base64_decode($base64, true);
        if ($raw === false || $raw === '') {
            return null;
        }

        $length = strlen($raw);

        $decoder = match (true) {
            $length === 16 && $this->isHeartBreath($raw) => $this->decodeHeartBreath(...),
            $length === 16 && $this->isHbStatics($raw) => $this->decodeHbStatics(...),
            $length === 16 => $this->decodePosStatics(...),
            $length % 16 === 0 && $length >= 16 => $this->decodePosition(...),
            default => null,
        };

        if ($decoder === null) {
            return null;
        }

        return $decoder($raw, $deviceCode);
    }

    private function isHeartBreath(string $raw): bool
    {
        $typeField = ord($raw[0]);
        return $typeField >= 0x30 && $typeField <= 0x39;
    }

    private function isHbStatics(string $raw): bool
    {
        $statusByte = ord($raw[13]);
        return ($statusByte & 0b00000011) !== 0 || (($statusByte & 0b00001100) >> 2) !== 0;
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
                'posture_state' => RadarValueMapper::decodePostureState($postureCode),
                'last_event' => RadarValueMapper::decodeLastEvent($eventCode),
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
            'type' => 'vitals',
            'device_code' => $deviceCode,
            'breathing' => ord($raw[1]),
            'heart_rate' => ord($raw[2]),
            'sleep_state' => RadarValueMapper::decodeSleepState($sleepStateBits),
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
            'type' => 'minute_stats',
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
            'breathing_status_per_minute' => RadarValueMapper::decodeBreathingStatus($statusByte & 0b00000011),
            'heart_rate_status_per_minute' => RadarValueMapper::decodeHeartStatus(($statusByte & 0b00001100) >> 2),
            'vital_signs_status' => RadarValueMapper::decodeVitalStatus(($statusByte & 0b00110000) >> 4),
            'sleep_state_status' => RadarValueMapper::decodeSleepState(($statusByte & 0b11000000) >> 6),
        ];
    }
}
