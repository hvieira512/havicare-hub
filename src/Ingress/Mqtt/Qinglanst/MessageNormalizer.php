<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Qinglanst;

use Hub\Device\DeviceDescriptor;

final class MessageNormalizer
{
    /** O que cada postura e cada movimento levantam. A ordem é a da procura: a postura ganha. */
    private const POSITION_DETECTIONS = [
        'posture' => [
            'fallen' => ['fall', ['confirmed' => true, 'posture' => 'lying_down']],
            'suspected_fall' => ['fall', ['confirmed' => false, 'posture' => 'lying_down']],
            'sitting_on_ground' => ['fall', ['confirmed' => true, 'posture' => 'sitting_on_ground']],
        ],
        'lastEvent' => [
            'enter_room' => ['zone_entry', ['zone' => 'room']],
            'leave_room' => ['zone_exit', ['zone' => 'room']],
            'enter_area' => ['zone_entry', ['zone' => 'area']],
            'leave_area' => ['zone_exit', ['zone' => 'area']],
        ],
    ];

    /** Campo descodificado, o evento que cada estado levanta, e a média que o justifica. */
    private const VITALS_STATUS_DETECTIONS = [
        ['breathingStatus', [
            'apnea' => ['apnea', null],
            'hyperpnea' => ['breath_rate_high', 'breathsPerMinute'],
            'hypopnea' => ['breath_rate_low', 'breathsPerMinute'],
        ]],
        ['heartRateStatus', [
            'high' => ['heart_rate_high', 'bpm'],
            'low' => ['heart_rate_low', 'bpm'],
        ]],
        ['vitalSignsStatus', [
            'weak' => ['weak_vital_signs', null],
        ]],
    ];

    /** A média do minuto que acompanha cada campo do `data`. */
    private const VITALS_AVERAGES = [
        'breathsPerMinute' => 'avgBreathsPerMinute',
        'bpm' => 'avgHeartRateBpm',
    ];

    /**
     * Uma mensagem do fabricante dá uma ou mais telemetrias, e zero ou mais alarmes.
     *
     * @param array{type: string, deviceCode: string|null, ...} $decoded
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

        // Não é o `location` canónico: as coordenadas são decímetros relativos ao radar.
        $telemetry = [
            'presence' => $this->envelope($topic, $device, 'presence', 'position', [
                'peopleCount' => count($people),
                'people' => $people,
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
            return (int)($person['personIndex'] ?? 0) !== 88;
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
                $data['personIndex'] = $person['personIndex'];
                if (($data['zone'] ?? null) === 'area') {
                    $data['areaId'] = $person['areaId'];
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
        $breathing = (int)($decoded['breathsPerMinute'] ?? 0);
        $heartRate = (int)($decoded['heartRateBpm'] ?? 0);

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

        $sleepState = (string)($decoded['sleepState'] ?? 'undefined');
        if ($sleepState !== 'undefined') {
            $telemetry['sleep_state'] = $this->envelope($topic, $device, 'sleep_state', 'heartbreath', [
                'state' => $sleepState,
            ]);
        }

        // O alarme alto ou baixo é o estado de cada minuto do `hbstatics`, com os limites do radar.
        return ['telemetry' => $telemetry, 'events' => []];
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
            // O IMEI canónico, o mesmo que vai no tópico publicado.
            'device' => DeviceDescriptor::of((string)($device['imei'] ?? $topic->deviceUid), $device),
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
                'position_minute_stats' => $this->envelope($topic, $device, 'position_minute_stats', 'posstatics', self::fields($decoded)),
            ],
            'events' => [],
        ];
    }

    /**
     * Os campos descodificados, que já têm os nomes do contrato.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private static function fields(array $decoded): array
    {
        return array_diff_key($decoded, ['type' => true, 'deviceCode' => true]);
    }

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @return array{telemetry: array<string, array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function normalizeHbStatics(array $decoded, QinglanstTopic $topic, array $device): array
    {
        $telemetry = $this->envelope($topic, $device, 'vitals_minute_stats', 'hbstatics', self::fields($decoded));

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
