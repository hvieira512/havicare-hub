<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Qinglanst;

use Hub\Device\DeviceDescriptor;

final class MessageNormalizer
{
    /** O que cada postura e cada movimento levantam. A ordem é a da procura: a postura ganha. */
    private const POSITION_DETECTIONS = [
        'posture_state' => [
            'fall_confirmation' => ['fall', ['confirmed' => true, 'posture' => 'lying']],
            'suspected_fall' => ['fall', ['confirmed' => false, 'posture' => 'lying']],
            'confirmed_sitting_on_ground' => ['fall', ['confirmed' => true, 'posture' => 'sitting_on_ground']],
        ],
        'last_event' => [
            'enter_room' => ['zone_entry', ['zone' => 'room']],
            'leave_room' => ['zone_exit', ['zone' => 'room']],
            'enter_area' => ['zone_entry', ['zone' => 'area']],
            'leave_area' => ['zone_exit', ['zone' => 'area']],
        ],
    ];

    /** Campo descodificado, o evento que cada estado levanta, e a média que o justifica. */
    private const VITALS_STATUS_DETECTIONS = [
        ['breathing_status_per_minute', [
            'apnea' => ['apnea', null],
            'hyperpnea' => ['breath_rate_high', 'breathsPerMinute'],
            'hypopnea' => ['breath_rate_low', 'breathsPerMinute'],
        ]],
        ['heart_rate_status_per_minute', [
            'high' => ['heart_rate_high', 'bpm'],
            'low' => ['heart_rate_low', 'bpm'],
        ]],
        ['vital_signs_status', [
            'weak' => ['weak_vital_signs', null],
        ]],
    ];

    /** A média do minuto que acompanha cada campo do `data`. */
    private const VITALS_AVERAGES = [
        'breathsPerMinute' => 'avg_breathing_per_minute',
        'bpm' => 'avg_heart_rate_per_minute',
    ];

    /**
     * Uma mensagem do fabricante dá uma ou mais telemetrias, e zero ou mais alarmes.
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

        // A postura e o último evento ficam dentro de cada pessoa. Não é o `location` canónico:
        // as coordenadas são decímetros relativos ao radar.
        $telemetry = [
            'presence' => $this->envelope($topic, $device, 'presence', 'position', [
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

        return [
            'telemetry' => $telemetry,
            'events' => $this->detectPositionEvents($topic, $device, $people),
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
     * Uma detecção por pessoa: a postura ganha ao movimento da mesma pessoa, e uma entrada não
     * esconde a queda de outra.
     *
     * @param array<string, mixed> $device
     * @param array<int, array<string, mixed>> $people
     * @return list<array<string, mixed>>
     */
    private function detectPositionEvents(QinglanstTopic $topic, array $device, array $people): array
    {
        $events = [];
        foreach ($people as $person) {
            foreach (self::POSITION_DETECTIONS as $field => $detections) {
                $detection = $detections[(string)($person[$field] ?? '')] ?? null;
                if ($detection === null) {
                    continue;
                }

                [$type, $data] = $detection;
                $data['personIndex'] = $person['person_index'];
                if (($data['zone'] ?? null) === 'area') {
                    $data['areaId'] = $person['region_id'];
                }
                $events[] = $this->envelope($topic, $device, $type, 'position', $data);
                break;
            }
        }

        return $events;
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

        // As formas são as do `FeatureNormalizer`. Um zero é o radar a não medir ninguém, e não
        // uma leitura.
        $telemetry = [];
        if ($heartRate > 0) {
            $telemetry['heart_rate'] = $this->envelope($topic, $device, 'heart_rate', 'heartbreath', [
                'bpm' => $heartRate,
            ]);
        }
        if ($breathing > 0) {
            $telemetry['breath_rate'] = $this->envelope($topic, $device, 'breath_rate', 'heartbreath', [
                'breathsPerMinute' => $breathing,
            ]);
        }

        $sleepState = (string)($decoded['sleep_state'] ?? 'undefined');
        if ($sleepState !== 'undefined') {
            $telemetry['sleep_state'] = $this->envelope($topic, $device, 'sleep_state', 'heartbreath', [
                'state' => $sleepState,
            ]);
        }

        // Os limites de 120 e 40 são do hub, e quão grave é decide-o a `severity`.
        $events = [];
        if ($heartRate > 120) {
            $events[] = $this->envelope($topic, $device, 'heart_rate_high', 'heartbreath', ['bpm' => $heartRate]);
        } elseif ($heartRate > 0 && $heartRate < 40) {
            $events[] = $this->envelope($topic, $device, 'heart_rate_low', 'heartbreath', ['bpm' => $heartRate]);
        }

        return ['telemetry' => $telemetry, 'events' => $events];
    }

    /**
     * O envelope comum de uma leitura ou de um evento: só o `type` e o `data` mudam.
     *
     * @param array<string, mixed> $device
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function envelope(QinglanstTopic $topic, array $device, string $capability, string $nativeType, array $data): array
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
                'position_minute_stats' => $this->envelope(
                    $topic,
                    $device,
                    'position_minute_stats',
                    'posstatics',
                    [
                        'version' => $decoded['version'],
                        'people' => $decoded['people'],
                        // Sem sufixo: a unidade da distância não está confirmada.
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
        // Sem `PerMinute` nos campos: a capacidade já se chama `vitals_minute_stats`.
        $telemetry = $this->envelope($topic, $device, 'vitals_minute_stats', 'hbstatics', [
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
        foreach (self::VITALS_STATUS_DETECTIONS as [$field, $detections]) {
            $detection = $detections[(string)($decoded[$field] ?? '')] ?? null;
            if ($detection === null) {
                continue;
            }

            [$type, $valueKey] = $detection;
            $average = $valueKey === null ? 0 : (int)($decoded[self::VITALS_AVERAGES[$valueKey]] ?? 0);
            $events[] = $this->envelope(
                $topic,
                $device,
                $type,
                'hbstatics',
                $average > 0 ? [$valueKey => $average] : [],
            );
        }

        return ['telemetry' => ['vitals_minute_stats' => $telemetry], 'events' => $events];
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
