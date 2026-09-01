<?php

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Domain\DiaperSensitivity;

final class MonitNormalizer
{
    /**
     * Banda do índice por estado: [mínimo, máximo]. O 39 do `attention` é um 39 e não um 40
     * de propósito -- os 40 são a marca de alerta e pertencem só ao `change_required`.
     *
     * @var array<string, array{int, int}>
     */
    private const MOISTURE_INDEX_BANDS = [
        'clean' => [0, 25],
        'attention' => [25, 39],
        'change_required' => [40, 100],
    ];

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $device
     * @param array{pollutionRange: int, pollutionValue: int} $sensitivity limiares em vigor para este sensor
     * @return array{telemetry: array<string, array<string, mixed>>, condition: string}
     */
    public function normalize(array $decoded, array $device, string $gatewayId, array $sensitivity): array
    {
        // Sem valor por omissão de propósito: quem se esqueça do lookup falha na análise
        // estática, em vez de decidir alarmes com limiares que ninguém escolheu.
        $pollutionValue = $sensitivity['pollutionValue'];
        $pollutionRange = $sensitivity['pollutionRange'];
        $cleanMaxDelta = DiaperSensitivity::cleanMaxDelta($pollutionValue);

        $occurredAt = gmdate('Y-m-d\TH:i:s\Z');
        $common = [
            'occurredAt' => $occurredAt,
            'device' => $this->device($device),
            'source' => array_filter([
                'protocol' => 'monit-mecs-pro-ble',
                'nativeType' => 'manufacturer_data',
                'gatewayId' => $gatewayId,
                'rssiDbm' => $decoded['rssiDbm'] ?? null,
            ], static fn(mixed $value): bool => $value !== null && $value !== ''),
        ];

        $channels = [];
        foreach ($decoded['baseline'] as $index => $baseline) {
            $channels[] = [
                'index' => $index + 1,
                'baseline' => $baseline,
                'value' => $decoded['raw'][$index],
                'delta' => $decoded['normalized'][$index],
            ];
        }
        $affected = count(array_filter($decoded['normalized'], static fn(int $value): bool => $value >= $pollutionValue));
        $condition = max($decoded['normalized']) < $cleanMaxDelta
            ? 'clean'
            : ($affected >= $pollutionRange ? 'change_required' : 'attention');

        return [
            'condition' => $condition,
            'telemetry' => [
                'battery' => ['type' => 'battery', 'data' => ['percent' => $decoded['batteryPercent']]] + $common,
                'diaper_moisture' => ['type' => 'diaper_moisture', 'data' => [
                    'channels' => $channels,
                    'affectedChannelCount' => $affected,
                    'maximumDelta' => max($decoded['normalized']),
                    // Viajam com a leitura: quem mostra "3 de 4 canais" precisa do 4, e ele
                    // é por sensor.
                    'requiredChannelCount' => $pollutionRange,
                    'wetDelta' => $pollutionValue,
                ]] + $common,
                // Capacidade própria e não um campo da `diaper_moisture`: o nível é o
                // contrato genérico, os dez canais são o detalhe do MONIT.
                'diaper_moisture_level' => ['type' => 'diaper_moisture_level', 'data' => [
                    'index' => $this->buildMoistureIndex($decoded['normalized'], $condition, $pollutionValue),
                    'alertIndex' => self::MOISTURE_INDEX_BANDS['change_required'][0],
                ]] + $common,
                'diaper_condition' => ['type' => 'diaper_condition', 'data' => ['state' => $condition]] + $common,
            ],
        ];
    }

    /**
     * Índice 0-100 de quanta humidade o sensor vê. **Não é uma percentagem física** e não
     * pode ser apresentada como tal -- ver `docs/17-sensor-de-fralda.md` §2 para a derivação
     * e para os dois invariantes que o `MonitMoistureIndexTest` prova.
     *
     * @param list<int> $deltas delta por canal, já normalizado contra a linha de base
     */
    private function buildMoistureIndex(array $deltas, string $condition, int $pollutionValue): int
    {
        if ($deltas === []) {
            return 0;
        }

        $saturation = array_sum(array_map(
            static fn(int $delta): float => min($delta / $pollutionValue, 1.0),
            $deltas
        )) / count($deltas);

        [$floor, $ceiling] = self::MOISTURE_INDEX_BANDS[$condition];

        if ($condition === 'attention') {
            $reachable = ($pollutionValue - 1) / $pollutionValue;
            $index = $floor + (int)round(($saturation / $reachable) * ($ceiling - $floor));
        } else {
            $index = (int)round($saturation * 100);
        }

        return max($floor, min($ceiling, $index));
    }

    /** @param array<string, mixed> $device */
    private function device(array $device): array
    {
        return array_filter([
            'id' => (string)$device['imei'],
            'supplier' => (string)($device['supplier'] ?? ''),
            'model' => (string)($device['model'] ?? ''),
            'commercialName' => (string)($device['commercialName'] ?? ''),
        ], static fn(string $value): bool => $value !== '');
    }
}
