<?php

declare(strict_types=1);

namespace Hub\Device;

/**
 * O envelope de uma leitura normalizada, tal como sai no MQTT.
 *
 * O `RawPayload` já fazia isto para o `status` e para o `event`; a telemetria era o único dos
 * três que cada ingestão montava à mão, e só na bridge Veepoo estava copiado cinco vezes.
 *
 * A forma é contrato público: o `type` é o nome da capacidade em snake_case, tem de coincidir
 * com o que o `CapabilityCatalog` declara, e os campos do `data` são camelCase com a unidade no
 * nome. Cinco cópias eram cinco sítios onde essa coincidência se podia partir em silêncio.
 */
final class TelemetryEnvelope
{
    /**
     * @param array<string, mixed> $device o dispositivo como a whitelist o resolve
     * @param array<string, mixed> $data os campos da leitura, já com os nomes do hub
     *
     * @return array<string, mixed>
     */
    public static function for(
        string $type,
        string $deviceKey,
        array $device,
        string $protocol,
        string $nativeType,
        array $data,
        string $gatewayId = '',
    ): array {
        $source = ['protocol' => $protocol, 'nativeType' => $nativeType];
        // O gateway só entra quando existe: um relógio fala directamente, e uma chave vazia
        // dizia a quem consome que havia uma caixa pelo meio que não há.
        if ($gatewayId !== '') {
            $source['gatewayId'] = $gatewayId;
        }

        return [
            'type' => $type,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => [
                'id' => $deviceKey,
                'supplier' => (string)($device['supplier'] ?? ''),
                'model' => (string)($device['model'] ?? ''),
            ],
            'source' => $source,
            'data' => $data,
        ];
    }
}
