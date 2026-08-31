<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Device\HubMqttBridge;

/**
 * Captures what an ingress publishes instead of talking to a broker.
 *
 * Cada publicação é registada na mesma forma, para uma alteração às assinaturas do
 * `HubMqttBridge` só ter de ser reflectida aqui e não numa cópia por teste.
 */
final class RecordingHubMqttBridge extends HubMqttBridge
{
    /** @var list<array{company: string, licenseId: int, deviceType: string, imei: string}> */
    public array $clearedRetainedStatus = [];

    /** @var list<array{type: ?string, imei: string, payload: array<string, mixed>, deviceType: string, licenseId: string, company: string}> */
    public array $raw = [];

    /** @var list<array{type: ?string, imei: string, payload: array<string, mixed>, deviceType: string, licenseId: string, company: string}> */
    public array $telemetry = [];

    /** @var list<array{type: ?string, imei: string, payload: array<string, mixed>, deviceType: string, licenseId: string, company: string}> */
    public array $events = [];

    /** @var list<array{type: ?string, imei: string, payload: array<string, mixed>, deviceType: string, licenseId: string, company: string, retain: bool}> */
    public array $statuses = [];

    public function __construct()
    {
        // Não chama o pai de propósito: não há broker a que ligar.
    }

    public function publishRaw(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
    {
        $this->raw[] = self::entry($imei, $payload, $deviceType, $licenseId, $company);
    }

    public function publishTelemetry(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
    {
        $this->telemetry[] = self::entry($imei, $payload, $deviceType, $licenseId, $company);
    }

    public function publishEvent(string $imei, array $payload, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
    {
        $this->events[] = self::entry($imei, $payload, $deviceType, $licenseId, $company);
    }

    public function publishStatus(string $imei, array $payload, bool $retain = true, string $deviceType = 'watch', int $licenseId = 0, string $company = 'null'): void
    {
        $this->statuses[] = self::entry($imei, $payload, $deviceType, $licenseId, $company) + ['retain' => $retain];
    }

    /** @return array<string, mixed>|null */
    public function lastTelemetry(): ?array
    {
        $last = end($this->telemetry);

        return $last === false ? null : $last;
    }

    /** @return list<string> */
    public function telemetryTypes(): array
    {
        return array_map(static fn (array $entry): string => (string)$entry['type'], $this->telemetry);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{type: ?string, imei: string, payload: array<string, mixed>, deviceType: string, licenseId: string, company: string}
     */
    private static function entry(string $imei, array $payload, string $deviceType, int $licenseId, string $company): array
    {
        return [
            'type' => isset($payload['type']) ? (string)$payload['type'] : null,
            'imei' => $imei,
            'payload' => $payload,
            'deviceType' => $deviceType,
            'licenseId' => $licenseId,
            'company' => $company,
        ];
    }

    public function clearRetainedStatus(string $company, int $licenseId, string $deviceType, string $imei): void
    {
        $this->clearedRetainedStatus[] = compact('company', 'licenseId', 'deviceType', 'imei');
    }
}
