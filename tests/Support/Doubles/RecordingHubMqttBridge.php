<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\HubMqttBridge;

/**
 * Captures what an ingress publishes instead of talking to a broker.
 *
 * Every publish is recorded in the same shape, so a change to HubMqttBridge's
 * signatures only has to be reflected here rather than in a copy per test.
 */
final class RecordingHubMqttBridge extends HubMqttBridge
{
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
        // Deliberately does not call parent: there is no broker to connect to.
    }

    public function publishRaw(string $imei, array $payload, string $deviceType = 'watch', string $licenseId = '0', string $company = 'null'): void
    {
        $this->raw[] = self::entry($imei, $payload, $deviceType, $licenseId, $company);
    }

    public function publishTelemetry(string $imei, array $payload, string $deviceType = 'watch', string $licenseId = '0', string $company = 'null'): void
    {
        $this->telemetry[] = self::entry($imei, $payload, $deviceType, $licenseId, $company);
    }

    public function publishEvent(string $imei, array $payload, string $deviceType = 'watch', string $licenseId = '0', string $company = 'null'): void
    {
        $this->events[] = self::entry($imei, $payload, $deviceType, $licenseId, $company);
    }

    public function publishStatus(string $imei, array $payload, bool $retain = true, string $deviceType = 'watch', string $licenseId = '0', string $company = 'null'): void
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
    private static function entry(string $imei, array $payload, string $deviceType, string $licenseId, string $company): array
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
}
