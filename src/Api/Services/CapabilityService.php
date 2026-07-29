<?php

namespace Hub\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\DeviceMetadata;

class CapabilityService
{
    public function __construct(private ApiDataAccess $db)
    {
    }

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

    public function show(int $id): array
    {
        $row = $this->db->genericCapabilities->findById($id);
        if ($row === null) {
            return ['error' => ['code' => 'capability_not_found', 'message' => 'Capability not found']];
        }

        return $this->serializeCapability($row);
    }

    private function serializeCapability(array $row): array
    {
        $section = (string)($row['section'] ?? '');

        return [
            'id' => (int)($row['id'] ?? 0),
            'deviceType' => (string)($row['device_type'] ?? 'watch'),
            'section' => $section,
            'sectionLabel' => CapabilityCatalog::sections()[$section] ?? $section,
            'key' => (string)($row['capability_key'] ?? ''),
            'label' => (string)($row['label'] ?? ''),
            'sortOrder' => (int)($row['sort_order'] ?? 0),
            'isTelemetry' => (bool)($row['is_telemetry'] ?? false),
            'isConfigurable' => (bool)($row['is_configurable'] ?? false),
            'isRequestable' => (bool)($row['is_requestable'] ?? false),
            'isEvent' => (bool)($row['is_event'] ?? false),
        ];
    }
}
