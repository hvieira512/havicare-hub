<?php

declare(strict_types=1);

namespace Hub\Device;

use Hub\Ingress\Tcp\DeviceAuthorizer;
use Hub\Registry\Whitelist;

/**
 * O desfecho de um comando e de uma alteração de configuração, publicado em `events` no tópico do
 * dono do aparelho, para quem integra não ter de perguntar à API.
 */
final class LifecycleEvents
{
    private DeviceAuthorizer $devices;

    public function __construct(
        private readonly HubMqttBridge $mqtt,
        Whitelist $whitelist,
        ?CommercialModelResolver $commercialModelResolver = null,
    ) {
        $this->devices = new DeviceAuthorizer($whitelist, $commercialModelResolver);
    }

    /** @param array<string, mixed> $record o registo do comando, como a API o devolve */
    public function command(string $imei, string $type, array $record): void
    {
        $command = self::present([
            'id' => $record['id'] ?? null,
            'nativeType' => $record['nativeType'] ?? null,
            'protocol' => $record['protocol'] ?? null,
            'configKey' => $record['genericConfigKey'] ?? null,
            'feature' => $record['capability'] ?? $record['feature'] ?? null,
            'status' => $record['status'] ?? null,
            'attempts' => $record['attempts'] ?? null,
            'sentAt' => $record['sentAt'] ?? null,
            'ackedAt' => $record['ackedAt'] ?? null,
        ]);
        $error = $type === 'device.downlink.failed'
            ? self::present(['code' => (string)($record['error'] ?? '') ?: 'failed', 'message' => $record['errorMessage'] ?? null])
            : null;

        $this->publish($imei, $type, $error, ['command' => $command]);
    }

    /**
     * @param array{imei: string, configKey: string, changeId: string, desiredRevision: int, status: string, effective: mixed, error: string, confirmedAt?: string} $change
     */
    public function configuration(array $change): void
    {
        $this->publish(
            $change['imei'],
            'device.configuration.' . $change['status'],
            $change['error'] !== '' ? ['code' => $change['error']] : null,
            ['configuration' => self::present([
                'key' => $change['configKey'],
                'changeId' => $change['changeId'],
                'desiredRevision' => $change['desiredRevision'],
                'status' => $change['status'],
                'effective' => $change['effective'],
                'confirmedAt' => $change['confirmedAt'] ?? null,
            ])],
        );
    }

    /**
     * @param array<string, mixed>|null $error
     * @param array<string, mixed> $body
     */
    private function publish(string $imei, string $type, ?array $error, array $body): void
    {
        try {
            $device = $this->devices->metadataFor($imei);
            $this->mqtt->publishEvent(
                $imei,
                TelemetryEnvelope::event($imei, $device['supplier'], $device['model'], $type, $error, null, $device['commercialName']) + $body,
                $device['deviceType'],
                $device['licenseId'],
                $device['company'],
            );
        } catch (\Throwable $e) {
            $this->mqtt->logPublishFailure('hub', $imei, $e);
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function present(array $fields): array
    {
        return array_filter($fields, static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
