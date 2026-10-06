<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Qinglanst;

use Hub\Device\DeviceDescriptor;

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

    /** O que cada postura e cada movimento levantam. A ordem é a da procura: a postura ganha. */
    private const POSITION_DETECTIONS = [
        'posture_state' => [
            'fall_confirmation' => ['fall_confirmed', self::LEVEL_DANGER],
            'suspected_fall' => ['fall_confirmed', self::LEVEL_WARNING],
            'confirmed_sitting_on_ground' => ['sitting_confirmed', self::LEVEL_WARNING],
        ],
        'last_event' => [
            'enter_room' => ['room_entry', self::LEVEL_INFO],
            'leave_room' => ['room_exit', self::LEVEL_INFO],
            'enter_area' => ['area_entry', self::LEVEL_INFO],
            'leave_area' => ['area_exit', self::LEVEL_INFO],
        ],
    ];

    /** Campo descodificado, chave no `details`, e o estado que levanta cada alarme. */
    private const VITALS_STATUS_DETECTIONS = [
        ['breathing_status_per_minute', 'breathingStatus', [
            'apnea' => ['apnea', self::LEVEL_DANGER],
            'hyperpnea' => ['breathing_high', self::LEVEL_WARNING],
            'hypopnea' => ['breathing_low', self::LEVEL_WARNING],
        ]],
        ['heart_rate_status_per_minute', 'heartRateStatus', [
            'high' => ['heart_rate_high', self::LEVEL_WARNING],
            'low' => ['heart_rate_low', self::LEVEL_WARNING],
        ]],
        ['vital_signs_status', 'vitalSignsStatus', [
            'weak' => ['vitals_signal_lost', self::LEVEL_WARNING],
        ]],
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
     * @return array{telemetry: array<string, array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public function normalize(array $decoded, QinglanstTopic $topic, array $device): array
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
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @return array{telemetry: array<string, array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function normalizePosition(array $decoded, QinglanstTopic $topic, array $device): array
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
     * @param array<int, array<string, mixed>> $people
     * @return list<array<string, mixed>>
     */
    private function occupiedPeople(array $people): array
    {
        return array_values(array_filter($people, static function (array $person): bool {
            return (int)($person['person_index'] ?? 0) !== 88;
        }));
    }

    /**
     * Uma detecção por mensagem, da primeira pessoa que acertar.
     *
     * @param array<string, mixed> $device
     * @param array<int, array<string, mixed>> $people
     * @return array<string, mixed>|null
     */
    private function detectPositionEvent(QinglanstTopic $topic, array $device, array $people): ?array
    {
        foreach ($people as $person) {
            foreach (self::POSITION_DETECTIONS as $field => $detections) {
                $detection = $detections[(string)($person[$field] ?? '')] ?? null;
                if ($detection === null) {
                    continue;
                }

                [$type, $level] = $detection;

                return $this->detectionEvent(
                    $topic,
                    $device,
                    $type,
                    $level,
                    self::SOURCE_POSITION,
                    ['personIndex' => $person['person_index']]
                );
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @return array{telemetry: array<string, array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function normalizeVitals(array $decoded, QinglanstTopic $topic, array $device): array
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
                ['heartRate' => $heartRate]
            );
        } elseif ($heartRate > 120) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_high',
                self::LEVEL_WARNING,
                self::SOURCE_HEARTBREATH,
                ['heartRate' => $heartRate]
            );
        }

        if ($heartRate > 0 && $heartRate < 20) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_low_critical',
                self::LEVEL_DANGER,
                self::SOURCE_HEARTBREATH,
                ['heartRate' => $heartRate]
            );
        } elseif ($heartRate > 0 && $heartRate < 40) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'heart_rate_low',
                self::LEVEL_WARNING,
                self::SOURCE_HEARTBREATH,
                ['heartRate' => $heartRate]
            );
        }

        if ($breathing === 0 && $heartRate === 0) {
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                'vitals_signal_lost',
                self::LEVEL_DANGER,
                self::SOURCE_HEARTBREATH,
                ['breathsPerMinute' => $breathing, 'heartRate' => $heartRate]
            );
        }

        return ['telemetry' => $telemetry, 'events' => $events];
    }

    /**
     * O envelope comum de uma leitura: só o `type` e o `data` mudam entre capacidades.
     *
     * @param array<string, mixed> $device
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function telemetry(QinglanstTopic $topic, array $device, string $capability, string $nativeType, array $data): array
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
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @return array{telemetry: array<string, array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function normalizeMinuteStats(array $decoded, QinglanstTopic $topic, array $device): array
    {
        return [
            'telemetry' => [
                'position_minute_stats' => $this->telemetry(
                    $topic,
                    $device,
                    'position_minute_stats',
                    'posstatics',
                    [
                        'version' => $decoded['version'],
                        'people' => $decoded['people'],
                        // A unidade da distância não está confirmada: o documento do
                        // fabricante não está no repositório, e em produção o valor foi
                        // sempre zero. Fica sem sufixo até alguém a poder confirmar.
                        'walkingDistance' => $decoded['walking_distance'],
                        'walkingTimeS' => $decoded['walking_time'],
                        'meditationTimeS' => $decoded['meditation_time'],
                        'inBedTimeS' => $decoded['in_bed_time'],
                        'standingTimeS' => $decoded['standing_time'],
                        'multiplayerTimeS' => $decoded['multiplayer_time'],
                        'breathingActive' => $decoded['breathing_active'],
                    ],
                ),
            ],
            'events' => [],
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @return array{telemetry: array<string, array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function normalizeHbStatics(array $decoded, QinglanstTopic $topic, array $device): array
    {
        // Sem `PerMinute` no nome de cada campo: a capacidade já se chama
        // `vitals_minute_stats`, e nenhuma outra repete o próprio nome dentro dos campos.
        $telemetry = $this->telemetry($topic, $device, 'vitals_minute_stats', 'hbstatics', [
            'realTimeBreathing' => $decoded['real_time_breathing'],
            'realTimeHeartRate' => $decoded['real_time_heart_rate'],
            'avgBreathing' => $decoded['avg_breathing_per_minute'],
            'avgHeartRate' => $decoded['avg_heart_rate_per_minute'],
            'breathingStatus' => $decoded['breathing_status_per_minute'],
            'heartRateStatus' => $decoded['heart_rate_status_per_minute'],
            'vitalSignsStatus' => $decoded['vital_signs_status'],
            'sleepState' => $decoded['sleep_state_status'],
        ]);

        $events = [];
        foreach (self::VITALS_STATUS_DETECTIONS as [$field, $detailKey, $detections]) {
            $status = (string)($decoded[$field] ?? '');
            $detection = $detections[$status] ?? null;
            if ($detection === null) {
                continue;
            }

            [$type, $level] = $detection;
            $events[] = $this->detectionEvent(
                $topic,
                $device,
                $type,
                $level,
                self::SOURCE_HEARTBREATH,
                [$detailKey => $status]
            );
        }

        return ['telemetry' => ['vitals_minute_stats' => $telemetry], 'events' => $events];
    }

    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function detectionEvent(QinglanstTopic $topic, array $device, string $type, string $level, string $source, array $data): array
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
    private function deviceInfo(QinglanstTopic $topic, array $device): array
    {
        // O IMEI canónico, o mesmo que vai no tópico publicado.
        return DeviceDescriptor::of((string)($device['imei'] ?? $topic->deviceUid), $device);
    }

    /**
     * @return array{protocol: string, nativeType: string, topic: string}
     */
    private function source(QinglanstTopic $topic, string $messageType): array
    {
        return [
            'protocol' => 'qinglanst-radar',
            'nativeType' => $messageType,
            'topic' => $topic->original,
        ];
    }
}
