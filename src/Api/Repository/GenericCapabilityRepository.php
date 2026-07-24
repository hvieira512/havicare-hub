<?php

namespace Hub\Api\Repository;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

final class GenericCapabilityRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(?string $deviceType = null): array
    {
        if ($deviceType === null || trim($deviceType) === '') {
            $rows = TimestampFormatter::normalizeRows($this->pdo
                ->query('SELECT id, device_type, section, capability_key, label, is_telemetry, is_configurable, is_requestable, sort_order, created_at, updated_at FROM capabilities ORDER BY FIELD(device_type, \'watch\', \'ncs\', \'radar\'), FIELD(section, \'telemetry\', \'health\', \'contacts\', \'alarms\', \'settings_system\'), sort_order, capability_key')
                ->fetchAll());

            return $this->appendMissingDefinitions($rows, null);
        }

        $stmt = $this->pdo->prepare('SELECT id, device_type, section, capability_key, label, is_telemetry, is_configurable, is_requestable, sort_order, created_at, updated_at FROM capabilities WHERE device_type = ? ORDER BY FIELD(section, \'telemetry\', \'health\', \'contacts\', \'alarms\', \'settings_system\'), sort_order, capability_key');
        $stmt->execute([$deviceType]);
        return $this->appendMissingDefinitions(TimestampFormatter::normalizeRows($stmt->fetchAll()), $deviceType);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, device_type, section, capability_key, label, is_telemetry, is_configurable, is_requestable, sort_order, created_at, updated_at FROM capabilities WHERE id = ?');
        $stmt->execute([$id]);

        $row = $stmt->fetch();
        return $row === false ? null : $this->enrichRow(TimestampFormatter::normalizeRow($row));
    }

    /**
     * @return list<string>
     */
    public function keysForDeviceType(string $deviceType): array
    {
        return CapabilityCatalog::keysForDeviceType($deviceType);
    }

    public function findIdByDeviceTypeAndKey(string $deviceType, string $key): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $stmt->execute([$deviceType, $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int)$value;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function appendMissingDefinitions(array $rows, ?string $deviceType = null): array
    {
        $definitions = $deviceType !== null && trim($deviceType) !== ''
            ? CapabilityCatalog::definitionsForDeviceType($deviceType)
            : CapabilityCatalog::definitions();
        $allowed = [];
        foreach ($definitions as $definition) {
            $definitionDeviceType = trim((string)($definition['deviceType'] ?? ''));
            $key = trim((string)($definition['key'] ?? ''));
            if ($definitionDeviceType === '' || $key === '') {
                continue;
            }
            $allowed[$definitionDeviceType . ':' . $key] = $definition;
        }

        $existing = [];
        $filtered = [];
        foreach ($rows as $row) {
            $key = trim((string)($row['capability_key'] ?? ''));
            $rowDeviceType = trim((string)($row['device_type'] ?? ''));
            if ($key === '' || $rowDeviceType === '' || !isset($allowed[$rowDeviceType . ':' . $key])) {
                continue;
            }
            $existing[$rowDeviceType . ':' . $key] = true;
            $filtered[] = $this->enrichRow($row);
        }

        foreach ($allowed as $dedupeKey => $definition) {
            $key = trim((string)($definition['key'] ?? ''));
            $definitionDeviceType = trim((string)($definition['deviceType'] ?? ''));
            if ($key === '' || $definitionDeviceType === '') {
                continue;
            }

            if (isset($existing[$dedupeKey])) {
                continue;
            }

            $filtered[] = [
                'id' => null,
                'device_type' => $definitionDeviceType,
                'section' => (string)($definition['section'] ?? ''),
                'capability_key' => $key,
                'label' => (string)($definition['label'] ?? $key),
                'is_telemetry' => (bool)($definition['isTelemetry'] ?? false),
                'is_configurable' => (bool)($definition['isConfigurable'] ?? false),
                'is_requestable' => (bool)($definition['isRequestable'] ?? false),
                'is_event' => (bool)($definition['isEvent'] ?? false),
                'sort_order' => (int)($definition['sortOrder'] ?? 0),
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        usort($filtered, static function (array $left, array $right): int {
            $deviceTypeOrder = ['watch', 'ncs', 'radar'];
            $sectionOrder = ['telemetry', 'health', 'contacts', 'alarms', 'settings_system'];
            $deviceIndex = static function (string $deviceType) use ($deviceTypeOrder): int {
                $index = array_search($deviceType, $deviceTypeOrder, true);
                return $index === false ? count($deviceTypeOrder) : (int)$index;
            };
            $sectionIndex = static function (string $section) use ($sectionOrder): int {
                $index = array_search($section, $sectionOrder, true);
                return $index === false ? count($sectionOrder) : (int)$index;
            };

            $leftDeviceType = (string)($left['device_type'] ?? '');
            $rightDeviceType = (string)($right['device_type'] ?? '');
            if ($leftDeviceType !== $rightDeviceType) {
                return $deviceIndex($leftDeviceType) <=> $deviceIndex($rightDeviceType);
            }

            $leftSection = (string)($left['section'] ?? '');
            $rightSection = (string)($right['section'] ?? '');
            if ($leftSection !== $rightSection) {
                return $sectionIndex($leftSection) <=> $sectionIndex($rightSection);
            }

            $leftSort = (int)($left['sort_order'] ?? 0);
            $rightSort = (int)($right['sort_order'] ?? 0);
            if ($leftSort !== $rightSort) {
                return $leftSort <=> $rightSort;
            }

            return strcmp((string)($left['capability_key'] ?? ''), (string)($right['capability_key'] ?? ''));
        });

        return $filtered;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichRow(array $row): array
    {
        $deviceType = trim((string)($row['device_type'] ?? ''));
        $key = trim((string)($row['capability_key'] ?? ''));
        $definition = null;
        if ($deviceType !== '' && $key !== '') {
            foreach (CapabilityCatalog::definitionsForDeviceType($deviceType) as $candidate) {
                if ((string)($candidate['key'] ?? '') === $key) {
                    $definition = $candidate;
                    break;
                }
            }
        }

        $row['is_event'] = (bool)($definition['isEvent'] ?? false);
        return $row;
    }
}
