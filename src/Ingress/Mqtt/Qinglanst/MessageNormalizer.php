<?php

namespace Hub\Ingress\Mqtt\Qinglanst;

final class MessageNormalizer
{
    private const LEVEL_INFO = 'info';
    private const LEVEL_WARNING = 'warning';
    private const LEVEL_DANGER = 'danger';

    private const SOURCE_POSITION = 'position';
    private const SOURCE_HEARTBREATH = 'heartbreath';

    /**
     * As detecções que contam como alarme e não como acontecimento. As restantes -- entradas
     * e saídas de divisão ou de área -- descrevem movimento e não perigo.
     */
    private const ALARM_DETECTION_TYPES = [
        'fall_confirmed',
        'heart_rate_high_critical',
        'heart_rate_high',
        'heart_rate_low_critical',
        'heart_rate_low',
        'apnea',
        'breathing_high',
        'breathing_low',
        'vitals_signal_lost',
        'sitting_confirmed',
        'on_floor',
    ];

    /**
     * A capacidade a que cada detecção pertence. Três e não quinze: cada evento leva o tipo
     * específico dentro, e o separador das Capacidades não ganha quinze linhas.
     */
    private const DETECTION_CAPABILITY = [
        'fall_confirmed' => 'fall',
        'sitting_confirmed' => 'fall',
        'on_floor' => 'fall',
        'heart_rate_high_critical' => 'vitals_alarm',
        'heart_rate_high' => 'vitals_alarm',
        'heart_rate_low_critical' => 'vitals_alarm',
        'heart_rate_low' => 'vitals_alarm',
        'apnea' => 'vitals_alarm',
        'breathing_high' => 'vitals_alarm',
        'breathing_low' => 'vitals_alarm',
        'vitals_signal_lost' => 'vitals_alarm',
        'room_entry' => 'presence_event',
        'room_exit' => 'presence_event',
        'area_entry' => 'presence_event',
        'area_exit' => 'presence_event',
    ];

    /**
     * Uma mensagem do fabricante dá uma ou mais telemetrias, e zero ou mais alarmes: o
     * `heartbreath` traz frequência cardíaca, respiratória e estado de sono, e uma apneia com
     * uma taquicardia no mesmo minuto são dois alarmes.
     *
     * @param array{type: string, device_code: string, ...} $decoded
     * @param array{imei: string, supplier: string, model: string, deviceType: string, licenseId: int, company?: string} $device
     * @return array{telemetry: array<string, array>, events: list<array>}
     */
    public function normalize(array $decoded, Topic $topic, array $device): array
    {
        return match ($decoded['type']) {
            'position' => $this->normalizePosition($decoded, $topic, $device),
            'heartbreath' => $this->normalizeVitals($decoded, $topic, $device),
            'posstatics' => $this->normalizeMinuteStats($decoded, $topic, $device),
            'hbstatics' => $this->normalizeHbStatics($decoded, $topic, $device),
            default => ['telemetry' => [], 'events' => []],
        };
    }

    /**
     * @param array $decoded
     * @param array $device
     * @return array{telemetry: array<string, array>, events: list<array>}
     */
    private function normalizePosition(array $decoded, Topic $topic, array $device): array
    {
        $people = $this->occupiedPeople($decoded['people']);

        // A postura e o último evento são de cada pessoa e ficam dentro dela, senão era
        // preciso escolher uma entre as presentes.
        //
        // Não é o `location` canónico: as coordenadas do radar são em decímetros relativos a
        // si próprio, e só valem dentro da divisão onde está montado.
        $telemetry = [
            'presence' => $this->telemetry($topic, $device, 'presence', 'position', [
                'count' => count($people),
                'people' => array_map(static function (array $person): array {
                    return [
                        'personIndex' => $person['person_index'],
                        'xPositionDm' => $person['x_position_dm'],
                        'yPositionDm' => $person['y_position_dm'],
                        'zPositionCm' => $person['z_position_cm'],
                        'timeLeftS' => $person['time_left_s'],
                        'regionId' => $person['region_id'],
                        'posture' => (string)($person['posture_state'] ?? 'unknown'),
                        'lastEvent' => (string)($person['last_event'] ?? 'unknown'),
                    ];
                }, $people),
            ]),
        ];

        $event = $this->detectPositionEvent($topic, $device, $people);

        return [
            'telemetry' => $telemetry,
            'events' => $event === null ? [] : [$event],
        ];
    }

    /**
     * @param array<int, array> $people
     * @return array<int, array>
     */
    private function occupiedPeople(array $people): array
    {
        return array_values(array_filter($people, static function (array $person): bool {
            return (int)($person['person_index'] ?? 0) !== 88;
        }));
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

            if ($posture === 'fall_confirmation') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'fall_confirmed',
                    self::LEVEL_DANGER,
                    self::SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }

            if ($posture === 'suspected_fall') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'fall_confirmed',
                    self::LEVEL_WARNING,
                    self::SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }

            if ($eventCode === 'enter_room') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'room_entry',
                    self::LEVEL_INFO,
                    self::SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }

            if ($eventCode === 'leave_room') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'room_exit',
                    self::LEVEL_INFO,
                    self::SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }

            if ($eventCode === 'enter_area') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'area_entry',
                    self::LEVEL_INFO,
                    self::SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }

            if ($eventCode === 'leave_area') {
                return $this->detectionEvent(
                    $topic,
                    $device,
                    'area_exit',
                    self::LEVEL_INFO,
                    self::SOURCE_POSITION,
                    ['person_index' => $person['person_index']]
                );
            }
        }

        return null;
    }

    /**
     * @return array{telemetry: array<string, array>, events: list<array>}
     */
    private function normalizeVitals(array $decoded, Topic $topic, array $device): array
    {
        $breathing = (int)($decoded['breathing'] ?? 0);
        $heartRate = (int)($decoded['heart_rate'] ?? 0);

        // As formas são as do `FeatureNormalizer`, para o radar e o relógio partilharem os
        // cartões. Um zero não é leitura: é o radar a dizer que não mediu ninguém, e "0 bpm"
        // lê-se como um coração parado.
        $telemetry = [];
        if ($heartRate > 0) {
            $telemetry['heart_rate'] = $this->telemetry($topic, $device, 'heart_rate', 'heartbreath', [
                'bpm' => $heartRate,
            ]);
        }
        if ($breathing > 0) {
            $telemetry['breath_rate'] = $this->telemetry($topic, $device, 'breath_rate', 'heartbreath', [
                'breathsPerMinute' => $breathing,
            ]);
        }

        $sleepState = (string)($decoded['sleep_state'] ?? 'undefined');
        if ($sleepState !== 'undefined') {
            $telemetry['sleep_state'] = $this->telemetry($topic, $device, 'sleep_state', 'heartbreath', [
                'state' => $sleepState,
            ]);
        }

        $events = [];

        if ($heartRate > 160) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_high_critical',
                self::LEVEL_DANGER,
                self::SOURCE_HEARTBREATH,
                ['heart_rate' => $heartRate]
            );
        } elseif ($heartRate > 120) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_high',
                self::LEVEL_WARNING,
                self::SOURCE_HEARTBREATH,
                ['heart_rate' => $heartRate]
            );
        }

        if ($heartRate > 0 && $heartRate < 20) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_low_critical',
                self::LEVEL_DANGER,
                self::SOURCE_HEARTBREATH,
                ['heart_rate' => $heartRate]
            );
        } elseif ($heartRate > 0 && $heartRate < 40) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_low',
                self::LEVEL_WARNING,
                self::SOURCE_HEARTBREATH,
                ['heart_rate' => $heartRate]
            );
        }

        if ($breathing === 0 && $heartRate === 0) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'vitals_signal_lost',
                self::LEVEL_DANGER,
                self::SOURCE_HEARTBREATH,
                ['breathing' => $breathing, 'heart_rate' => $heartRate]
            );
        }

        return ['telemetry' => $telemetry, 'events' => $events];
    }

    /**
     * O envelope comum de uma leitura: só o `type` e o `data` mudam entre capacidades.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function telemetry(Topic $topic, array $device, string $capability, string $nativeType, array $data): array
    {
        return [
            'type' => $capability,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->deviceInfo($topic, $device),
            'source' => $this->source($topic, $nativeType),
            'data' => $data,
        ];
    }

    /**
     * @return array{telemetry: array<string, array>, events: list<array>}
     */
    private function normalizeMinuteStats(array $decoded, Topic $topic, array $device): array
    {
        return [
            'telemetry' => [
                'position_minute_stats' => [
                    'type' => 'minute_stats',
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
            ],
            'events' => [],
        ];
    }

    /**
     * @return array{telemetry: array<string, array>, events: list<array>}
     */
    private function normalizeHbStatics(array $decoded, Topic $topic, array $device): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $telemetry = [
            'type' => 'hbstatics',
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

        $events = [];

        $breathingStatus = (string)($decoded['breathing_status_per_minute'] ?? '');
        $heartStatus = (string)($decoded['heart_rate_status_per_minute'] ?? '');
        $vitalStatus = (string)($decoded['vital_signs_status'] ?? '');

        if ($breathingStatus === 'apnea') {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'apnea',
                self::LEVEL_DANGER,
                self::SOURCE_HEARTBREATH,
                ['breathing_status' => $breathingStatus]
            );
        }

        if ($heartStatus === 'high') {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_high',
                self::LEVEL_WARNING,
                self::SOURCE_HEARTBREATH,
                ['heart_rate_status' => $heartStatus]
            );
        }

        if ($heartStatus === 'low') {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_low',
                self::LEVEL_WARNING,
                self::SOURCE_HEARTBREATH,
                ['heart_rate_status' => $heartStatus]
            );
        }

        if ($vitalStatus === 'weak') {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'vitals_signal_lost',
                self::LEVEL_WARNING,
                self::SOURCE_HEARTBREATH,
                ['vital_signs_status' => $vitalStatus]
            );
        }

        return ['telemetry' => ['vitals_minute_stats' => $telemetry], 'events' => $events];
    }

    /**
     * @param array<string, mixed> $data
     * @return array
     */
    private function detectionEvent(Topic $topic, array $device, string $type, string $level, string $source, array $data): array
    {
        return [
            'type' => self::DETECTION_CAPABILITY[$type] ?? 'vitals_alarm',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => $this->deviceInfo($topic, $device),
            'source' => $this->source($topic, $source),
            'data' => [
                'detectionType' => $type,
                'detectionCategory' => in_array($type, self::ALARM_DETECTION_TYPES, true) ? 'alarm' : 'event',
                'detectionLevel' => $level,
                'detectionSource' => $source,
                'details' => $data,
            ],
        ];
    }

    /**
     * @param array{supplier?: string, model?: string, commercialName?: string, ...} $device
     * @return array{id: string, supplier?: string, model?: string, commercialName?: string}
     */
    private function deviceInfo(Topic $topic, array $device): array
    {
        // O IMEI canónico, o mesmo que vai no tópico publicado.
        $info = ['id' => (string)($device['imei'] ?? $topic->deviceUid)];
        if ((string)($device['supplier'] ?? '') !== '') {
            $info['supplier'] = (string)$device['supplier'];
        }
        if ((string)($device['model'] ?? '') !== '') {
            $info['model'] = (string)$device['model'];
        }
        if ((string)($device['commercialName'] ?? '') !== '') {
            $info['commercialName'] = (string)$device['commercialName'];
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
