<?php

namespace Hub\Ingress\Mqtt\Qinglanst;

final class MessageNormalizer
{
    private const DETECTION_TYPE_MAP = [
        'fall_confirmed' => RadarValueMapper::DETECTION_TYPE_FALL_CONFIRMED,
        'heart_rate_high_critical' => RadarValueMapper::DETECTION_TYPE_HEART_RATE_HIGH_CRITICAL,
        'heart_rate_high' => RadarValueMapper::DETECTION_TYPE_HEART_RATE_HIGH,
        'heart_rate_low_critical' => RadarValueMapper::DETECTION_TYPE_HEART_RATE_LOW_CRITICAL,
        'heart_rate_low' => RadarValueMapper::DETECTION_TYPE_HEART_RATE_LOW,
        'apnea' => RadarValueMapper::DETECTION_TYPE_APNEA,
        'breathing_high' => RadarValueMapper::DETECTION_TYPE_BREATHING_HIGH,
        'breathing_low' => RadarValueMapper::DETECTION_TYPE_BREATHING_LOW,
        'vitals_signal_lost' => RadarValueMapper::DETECTION_TYPE_VITALS_SIGNAL_LOST,
        'room_entry' => RadarValueMapper::DETECTION_TYPE_ROOM_ENTRY,
        'room_exit' => RadarValueMapper::DETECTION_TYPE_ROOM_EXIT,
        'area_entry' => RadarValueMapper::DETECTION_TYPE_AREA_ENTRY,
        'area_exit' => RadarValueMapper::DETECTION_TYPE_AREA_EXIT,
        'sitting_confirmed' => RadarValueMapper::DETECTION_TYPE_SITTING_CONFIRMED,
        'on_floor' => RadarValueMapper::DETECTION_TYPE_ON_FLOOR,
    ];

    /**
     * @param array{type: string, device_code: string, ...} $decoded
     * @param array{imei: string, supplier: string, model: string, deviceType: string, licenseId: string, company?: string} $device
     * @return array{telemetry?: array, event?: array}
     */
    public function normalize(array $decoded, Topic $topic, array $device): array
    {
        return match ($decoded['type']) {
            'position' => $this->normalizePosition($decoded, $topic, $device),
            'heartbreath' => $this->normalizeVitals($decoded, $topic, $device),
            'posstatics' => $this->normalizeMinuteStats($decoded, $topic, $device),
            'hbstatics' => $this->normalizeHbStatics($decoded, $topic, $device),
            default => [],
        };
    }

    /**
     * @param array $decoded
     * @param array $device
     * @return array{telemetry: array, event?: array}
     */
    private function normalizePosition(array $decoded, Topic $topic, array $device): array
    {
        $telemetry = [
            'schemaVersion' => 2,
            'type' => 'radar.position',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->deviceInfo($topic, $device),
            'source' => $this->source($topic, 'position'),
            'data' => [
                'people' => array_map(function (array $person): array {
                    return [
                        'person_index' => $person['person_index'],
                        'x_position_dm' => $person['x_position_dm'],
                        'y_position_dm' => $person['y_position_dm'],
                        'z_position_cm' => $person['z_position_cm'],
                        'time_left_s' => $person['time_left_s'],
                        'posture_state' => $person['posture_state'],
                        'last_event' => $person['last_event'],
                        'region_id' => $person['region_id'],
                    ];
                }, $decoded['people']),
            ],
        ];

        $result = ['telemetry' => $telemetry];

        $event = $this->detectPositionEvent($topic, $device, $decoded['people']);
        if ($event !== null) {
            $result['event'] = $event;
        }

        return $result;
    }

    /**
     * @param array $people
     * @return array|null
     */
    private function detectPositionEvent(Topic $topic, array $device, array $people): ?array
    {
        foreach ($people as $person) {
            $posture = (string)($person['posture_state'] ?? '');
            $eventCode = (string)($person['last_event'] ?? '');

            if ($posture === 'Fall Confirmation') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'fall_confirmed',
                    RadarValueMapper::DETECTION_LEVEL_DANGER,
                    RadarValueMapper::DETECTION_SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }

            if ($posture === 'Suspected Fall') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'fall_confirmed',
                    RadarValueMapper::DETECTION_LEVEL_WARNING,
                    RadarValueMapper::DETECTION_SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }

            if ($eventCode === 'Enter Room') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'room_entry',
                    RadarValueMapper::DETECTION_LEVEL_INFO,
                    RadarValueMapper::DETECTION_SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }

            if ($eventCode === 'Leave Room') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'room_exit',
                    RadarValueMapper::DETECTION_LEVEL_INFO,
                    RadarValueMapper::DETECTION_SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }

            if ($eventCode === 'Enter Area') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'area_entry',
                    RadarValueMapper::DETECTION_LEVEL_INFO,
                    RadarValueMapper::DETECTION_SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }

            if ($eventCode === 'Leave Area') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'area_exit',
                    RadarValueMapper::DETECTION_LEVEL_INFO,
                    RadarValueMapper::DETECTION_SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }
        }

        return null;
    }

    /**
     * @return array{telemetry: array, event?: array}
     */
    private function normalizeVitals(array $decoded, Topic $topic, array $device): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $breathing = (int)($decoded['breathing'] ?? 0);
        $heartRate = (int)($decoded['heart_rate'] ?? 0);

        $telemetry = [
            'schemaVersion' => 2,
            'type' => 'radar.vitals',
            'occurredAt' => $now,
            'device' => $this->deviceInfo($topic, $device),
            'source' => $this->source($topic, 'heartbreath'),
            'data' => [
                'breathing' => $breathing,
                'heart_rate' => $heartRate,
                'sleep_state' => $decoded['sleep_state'] ?? 'Undefined',
            ],
        ];

        $result = ['telemetry' => $telemetry];

        $events = [];

        if ($heartRate > 160) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_high_critical',
                RadarValueMapper::DETECTION_LEVEL_DANGER,
                RadarValueMapper::DETECTION_SOURCE_HEARTBREATH,
                ['heart_rate' => $heartRate]
            );
        } elseif ($heartRate > 120) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_high',
                RadarValueMapper::DETECTION_LEVEL_WARNING,
                RadarValueMapper::DETECTION_SOURCE_HEARTBREATH,
                ['heart_rate' => $heartRate]
            );
        }

        if ($heartRate > 0 && $heartRate < 20) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_low_critical',
                RadarValueMapper::DETECTION_LEVEL_DANGER,
                RadarValueMapper::DETECTION_SOURCE_HEARTBREATH,
                ['heart_rate' => $heartRate]
            );
        } elseif ($heartRate > 0 && $heartRate < 40) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_low',
                RadarValueMapper::DETECTION_LEVEL_WARNING,
                RadarValueMapper::DETECTION_SOURCE_HEARTBREATH,
                ['heart_rate' => $heartRate]
            );
        }

        if ($breathing === 0 && $heartRate === 0) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'vitals_signal_lost',
                RadarValueMapper::DETECTION_LEVEL_DANGER,
                RadarValueMapper::DETECTION_SOURCE_HEARTBREATH,
                ['breathing' => $breathing, 'heart_rate' => $heartRate]
            );
        }

        if ($events !== []) {
            $result['event'] = $events[0];
        }

        return $result;
    }

    /**
     * @return array{telemetry: array}
     */
    private function normalizeMinuteStats(array $decoded, Topic $topic, array $device): array
    {
        return [
            'telemetry' => [
                'schemaVersion' => 2,
                'type' => 'radar.minute_stats',
                'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'device' => $this->deviceInfo($topic, $device),
                'source' => $this->source($topic, 'posstatics'),
                'data' => [
                    'version' => $decoded['version'],
                    'people' => $decoded['people'],
                    'walking_distance' => $decoded['walking_distance'],
                    'walking_time' => $decoded['walking_time'],
                    'meditation_time' => $decoded['meditation_time'],
                    'in_bed_time' => $decoded['in_bed_time'],
                    'standing_time' => $decoded['standing_time'],
                    'multiplayer_time' => $decoded['multiplayer_time'],
                    'breathing_active' => $decoded['breathing_active'],
                ],
            ],
        ];
    }

    /**
     * @return array{telemetry: array, event?: array}
     */
    private function normalizeHbStatics(array $decoded, Topic $topic, array $device): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $telemetry = [
            'schemaVersion' => 2,
            'type' => 'radar.hbstatics',
            'occurredAt' => $now,
            'device' => $this->deviceInfo($topic, $device),
            'source' => $this->source($topic, 'hbstatics'),
            'data' => [
                'real_time_breathing' => $decoded['real_time_breathing'],
                'real_time_heart_rate' => $decoded['real_time_heart_rate'],
                'avg_breathing_per_minute' => $decoded['avg_breathing_per_minute'],
                'avg_heart_rate_per_minute' => $decoded['avg_heart_rate_per_minute'],
                'breathing_status_per_minute' => $decoded['breathing_status_per_minute'],
                'heart_rate_status_per_minute' => $decoded['heart_rate_status_per_minute'],
                'vital_signs_status' => $decoded['vital_signs_status'],
                'sleep_state_status' => $decoded['sleep_state_status'],
            ],
        ];

        $result = ['telemetry' => $telemetry];
        $events = [];

        $breathingStatus = (string)($decoded['breathing_status_per_minute'] ?? '');
        $heartStatus = (string)($decoded['heart_rate_status_per_minute'] ?? '');
        $vitalStatus = (string)($decoded['vital_signs_status'] ?? '');

        if ($breathingStatus === 'Apnea') {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'apnea',
                RadarValueMapper::DETECTION_LEVEL_DANGER,
                RadarValueMapper::DETECTION_SOURCE_HEARTBREATH,
                ['breathing_status' => $breathingStatus]
            );
        }

        if ($heartStatus === 'High') {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_high',
                RadarValueMapper::DETECTION_LEVEL_WARNING,
                RadarValueMapper::DETECTION_SOURCE_HEARTBREATH,
                ['heart_rate_status' => $heartStatus]
            );
        }

        if ($heartStatus === 'Low') {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_low',
                RadarValueMapper::DETECTION_LEVEL_WARNING,
                RadarValueMapper::DETECTION_SOURCE_HEARTBREATH,
                ['heart_rate_status' => $heartStatus]
            );
        }

        if ($vitalStatus === 'Weak') {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'vitals_signal_lost',
                RadarValueMapper::DETECTION_LEVEL_WARNING,
                RadarValueMapper::DETECTION_SOURCE_HEARTBREATH,
                ['vital_signs_status' => $vitalStatus]
            );
        }

        if ($events !== []) {
            $result['event'] = $events[0];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array
     */
    private function detectionEvent(Topic $topic, array $device, string $type, int $level, int $source, array $data): array
    {
        $typeCode = self::DETECTION_TYPE_MAP[$type] ?? RadarValueMapper::UNKNOWN_CODE;
        $alarmTypes = RadarValueMapper::detectionAlarmTypeCodes();
        $category = in_array($typeCode, $alarmTypes, true)
            ? RadarValueMapper::DETECTION_CATEGORY_ALARM
            : RadarValueMapper::DETECTION_CATEGORY_EVENT;

        return [
            'schemaVersion' => 1,
            'type' => 'radar.detection',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->deviceInfo($topic, $device),
            'source' => $this->source($topic, RadarValueMapper::decodeDetectionSource($source)),
            'data' => [
                'detectionType' => $type,
                'detectionCategory' => RadarValueMapper::decodeDetectionCategory($category),
                'detectionLevel' => RadarValueMapper::decodeDetectionLevel($level),
                'detectionSource' => RadarValueMapper::decodeDetectionSource($source),
                'details' => $data,
            ],
        ];
    }

    /**
     * @param array{supplier?: string, model?: string, ...} $device
     * @return array{id: string, supplier?: string, model?: string}
     */
    private function deviceInfo(Topic $topic, array $device): array
    {
        $info = ['id' => $topic->deviceUid];
        if ((string)($device['supplier'] ?? '') !== '') {
            $info['supplier'] = (string)$device['supplier'];
        }
        if ((string)($device['model'] ?? '') !== '') {
            $info['model'] = (string)$device['model'];
        }
        return $info;
    }

    /**
     * @return array{protocol: string, nativeType: string, topic: string}
     */
    private function source(Topic $topic, string $messageType): array
    {
        return [
            'protocol' => 'qinglanst-radar',
            'nativeType' => $messageType,
            'topic' => $topic->original,
        ];
    }
}
