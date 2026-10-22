<?php

declare(strict_types=1);

namespace Hub\Device;

/**
 * Os envelopes do contrato MQTT, num sítio só: a leitura normalizada, o acontecimento de ciclo de
 * vida, o estado retido e o `raw`.
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
        ?int $rssiDbm = null,
    ): array {
        $source = ['protocol' => $protocol, 'nativeType' => $nativeType];
        // O gateway só entra quando existe: um relógio fala directamente.
        if ($gatewayId !== '') {
            $source['gatewayId'] = $gatewayId;
        }
        if ($rssiDbm !== null) {
            $source['rssiDbm'] = $rssiDbm;
        }

        return [
            'type' => $type,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => DeviceDescriptor::of($deviceKey, $device),
            'source' => $source,
            'data' => $data,
        ];
    }

    /**
     * A mesma telemetria, sem o que não cabe num histórico de consulta: o traçado de um ECG
     * só sai pelo MQTT, e a dashboard guarda quantas amostras são.
     *
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    public static function forDashboard(array $telemetry): array
    {
        $samples = $telemetry['data']['samples'] ?? null;
        if (!is_array($samples)) {
            return $telemetry;
        }

        $telemetry['data']['sampleCount'] = count($samples);
        unset($telemetry['data']['samples']);

        return $telemetry;
    }

    /** @return array<string, mixed> */
    public static function raw(
        string $imei,
        string $supplier,
        string $model,
        string $transport,
        string $protocol,
        string $raw,
        string $direction,
        ?string $connectionId = null,
        string $commercialName = '',
    ): array {
        $encoding = self::isText($raw) ? 'text' : 'base64';
        $decoded = self::decodedPayload($protocol, $raw);

        $payload = [
            'direction' => $direction,
            'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'device' => DeviceDescriptor::fromParts($imei, $supplier, $model, $commercialName),
            'debug' => [
                'protocol' => $protocol,
                'transport' => $transport,
                'encoding' => $encoding,
                'payload' => $decoded ?? ($encoding === 'text' ? $raw : base64_encode($raw)),
                'size' => strlen($raw),
            ],
        ];

        if ($decoded !== null) {
            $payload['debug']['encoded'] = base64_encode($raw);
        }

        if ($connectionId !== null && $connectionId !== '') {
            $payload['debug']['connectionId'] = $connectionId;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $error
     * @return array<string, mixed>
     */
    public static function status(string $imei, string $supplier, string $model, string $state, ?array $error = null, string $commercialName = ''): array
    {
        $payload = [
            'state' => $state,
            'updatedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'device' => DeviceDescriptor::fromParts($imei, $supplier, $model, $commercialName),
        ];

        if ($error !== null) {
            $payload['error'] = $error;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $command
     * @param array<string, mixed>|null $error
     * @return array<string, mixed>
     */
    public static function event(
        string $imei,
        string $supplier,
        string $model,
        string $type,
        ?array $error = null,
        ?array $command = null,
        string $commercialName = '',
    ): array {
        $payload = [
            'type' => $type,
            'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'device' => DeviceDescriptor::fromParts($imei, $supplier, $model, $commercialName),
        ];

        if ($error !== null) {
            $payload['error'] = $error;
        }
        if ($command !== null && $command !== []) {
            $payload['command'] = $command;
        }

        return $payload;
    }

    private static function isText(string $raw): bool
    {
        if ($raw === '') {
            return true;
        }

        if (preg_match('//u', $raw) !== 1) {
            return false;
        }

        return preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', $raw) !== 1;
    }

    /** @return array<string, mixed>|null */
    private static function decodedPayload(string $protocol, string $raw): ?array
    {
        if ($protocol !== 'wonlex-json' || strlen($raw) < 4) {
            return null;
        }

        $header = @unpack('nstart/nlength', substr($raw, 0, 4));
        if (($header['start'] ?? null) !== 0xFCAF) {
            return null;
        }

        $length = (int)($header['length'] ?? 0);
        if ($length < 1 || strlen($raw) !== 4 + $length) {
            return null;
        }

        $decoded = json_decode(substr($raw, 4, $length), true);
        return is_array($decoded) && isset($decoded['type']) ? $decoded : null;
    }
}
