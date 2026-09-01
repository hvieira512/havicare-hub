<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

/**
 * Reconhece uma MOKO W6 retransmitida por um gateway.
 *
 * O firmware é o BXP Nordic, que não tem frame de alarme. Tem seis slots de anúncio, cada um
 * com um trigger opcional:
 *
 *   SLOT1  3-axis Acc   sempre         -> presença, aceleração e bateria
 *   SLOT2  TLM          sempre
 *   SLOT4  UID  ...0001 sempre         -> identidade
 *   SLOT3  UID  ...0011 clique simples -> anuncia 30s
 *   SLOT5  UID  ...0012 clique duplo   -> anuncia 30s
 *   SLOT6  UID  ...0013 clique triplo  -> anuncia 30s
 *
 * O toque não vem no payload: identifica-o *qual* slot apareceu. Sem contador cumulativo e
 * com a frame a repetir-se 30 segundos, quem chama tem de estrangular por tempo.
 *
 * ponytail: os Instance ID são uma convenção que a pulseira tem de ser configurada para
 * cumprir. Uma W6 configurada de outra maneira é vista, mas os toques dela não são lidos.
 */
final class W6Decoder
{
    /** Como o gateway classifica cada frame que nos interessa. */
    private const ACC_TYPE = 'bxp-acc';
    private const UID_TYPE = 'eddystone-uid';

    /** Os Instance ID que a nossa configuração atribui a cada modo de toque. */
    private const PRESS_MODES = [
        '000000000011' => 'single',
        '000000000012' => 'double',
        '000000000013' => 'triple',
    ];

    /**
     * O namespace que a nossa configuração escreve: oito zeros e o próprio MAC. É isto que
     * impede uma W6 de ser confundida com qualquer outro beacon Eddystone em alcance -- e há
     * vários, com namespaces que nada têm a ver.
     */
    private const NAMESPACE_PREFIX = '00000000';

    /**
     * @param array<string, mixed> $observation uma entrada de um relatório de scan
     * @return array<string, mixed>|null null quando a observação não é uma W6
     */
    public function decode(array $observation): ?array
    {
        $mac = Topic::normalizeMac((string)($observation['mac'] ?? ''));
        if ($mac === null) {
            return null;
        }

        $type = (string)($observation['type'] ?? '');
        $decoded = match ($type) {
            self::ACC_TYPE => $this->fromAcceleration($observation),
            self::UID_TYPE => $this->fromUid($observation, $mac),
            default => null,
        };
        if ($decoded === null) {
            return null;
        }

        // O RSSI é medido pelo gateway, não pela pulseira, por isso só existe na observação
        // -- tal como em W6bDecoder e MonitMecsProDecoder.
        return array_filter(
            [
                'mac' => $mac,
                'rssiDbm' => is_numeric($observation['rssi'] ?? null) ? (int)$observation['rssi'] : null,
            ] + $decoded,
            static fn(mixed $value): bool => $value !== null,
        );
    }

    /**
     * A frame do acelerómetro chega do gateway já interpretada, e traz a bateria com ela.
     *
     * @param array<string, mixed> $observation
     * @return array<string, mixed>
     */
    private function fromAcceleration(array $observation): array
    {
        $hasAxes = isset($observation['x_axis_data'], $observation['y_axis_data'], $observation['z_axis_data']);
        if (!$hasAxes && !isset($observation['batt_vol'])) {
            return [];
        }

        $info = [];
        if ($hasAxes) {
            $info['accelerationMg'] = [
                'x' => (int)$observation['x_axis_data'],
                'y' => (int)$observation['y_axis_data'],
                'z' => (int)$observation['z_axis_data'],
            ];
        }
        if (isset($observation['batt_vol'])) {
            $voltage = (int)$observation['batt_vol'];
            // Acima de 100 o campo traz milivolts em vez de percentagem, como na W6B.
            $info += $voltage > 100 ? ['batteryVoltageMv' => $voltage] : ['batteryPercent' => $voltage];
        }

        return ['info' => $info];
    }

    /**
     * Um slot UID é ou a identidade permanente da pulseira, ou um toque. Um UID de outro
     * dispositivo qualquer não é reclamado.
     *
     * @param array<string, mixed> $observation
     * @return array<string, mixed>|null
     */
    private function fromUid(array $observation, string $mac): ?array
    {
        $namespace = strtolower(trim((string)($observation['namespace'] ?? '')));
        if ($namespace !== self::NAMESPACE_PREFIX . $mac) {
            return null;
        }

        $instance = strtolower(trim((string)($observation['instance'] ?? '')));
        $pressMode = self::PRESS_MODES[$instance] ?? null;

        // A identidade não é toque nenhum, mas continua a valer como avistamento.
        return $pressMode === null ? [] : ['alarm' => ['pressMode' => $pressMode]];
    }
}
