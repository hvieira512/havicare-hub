<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

/**
 * Reconhece uma MOKO W6 retransmitida por um gateway.
 *
 * A W6 é a irmã sem botão da W6R: em vez da frame de alarme anuncia uma frame BXP
 * com acelerómetro, que o gateway já classifica como `bxp-acc`:
 *
 *   {"type":"bxp-acc","rssi":-33,"mac":"fa05c2c70fc6",
 *    "adv_data":"020106020a031816abfe60000a0100013e40edc007c00b2800fa05c2c70fc6"}
 *
 * O corpo da frame não é lido. A folha "MOKO Beacon - ADV Format Summary Sheet" que
 * documenta o formato 0xFEAB não está no repositório, e inferir os campos a partir de
 * amostras daria uma bateria e um acelerómetro plausíveis mas por confirmar. O que o
 * hub precisa desta observação -- saber que a pulseira existe, e com que sinal chega --
 * lê-se do que o gateway já entrega.
 */
final class W6Decoder
{
    /** Como o gateway classifica uma BXP com acelerómetro. */
    private const GATEWAY_TYPE = 'bxp-acc';

    /**
     * @param array<string, mixed> $observation uma entrada de um relatório de scan
     * @return array<string, mixed>|null null quando a observação não é uma W6
     */
    public function decode(array $observation): ?array
    {
        if ((string)($observation['type'] ?? '') !== self::GATEWAY_TYPE) {
            return null;
        }

        $mac = Topic::normalizeMac((string)($observation['mac'] ?? ''));
        if ($mac === null) {
            return null;
        }

        // O RSSI é medido pelo gateway, não pela pulseira, por isso só existe na
        // observação -- tal como em W6rDecoder e MonitMecsProDecoder.
        return array_filter(
            [
                'mac' => $mac,
                'rssiDbm' => is_numeric($observation['rssi'] ?? null) ? (int)$observation['rssi'] : null,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
