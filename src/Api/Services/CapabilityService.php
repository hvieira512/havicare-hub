<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Domain\DeviceMetadata;

class CapabilityService
{
    public function __construct(private ApiDataAccess $db)
    {
    }

    /** @return array<string, mixed> */
    public function list(string $query = ''): array
    {
        parse_str($query, $params);
        $deviceType = trim((string)($params['deviceType'] ?? ''));
        if ($deviceType !== '') {
            $deviceType = DeviceMetadata::normalizeDeviceType($deviceType);
        }

        return [
            'data' => array_map(
                fn(array $row): array => $this->serializeCapability($row),
                $this->db->genericCapabilities->all($deviceType !== '' ? $deviceType : null)
            ),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeCapability(array $row): array
    {
        return [
            'deviceType' => (string)($row['device_type'] ?? 'watch'),
            'section' => (string)($row['section'] ?? ''),
            'key' => (string)($row['capability_key'] ?? ''),
            'isTelemetry' => (bool)($row['is_telemetry'] ?? false),
            'isConfigurable' => (bool)($row['is_configurable'] ?? false),
            'isRequestable' => (bool)($row['is_requestable'] ?? false),
            'isEvent' => (bool)($row['is_event'] ?? false),
        ];
    }
}
