<?php

namespace Hub\Api\Repository;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Infrastructure\Persistence\TimestampFormatter;
use PDO;

final class GenericCapabilityRepository
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $catalogs = [];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * A chave por que uma etiqueta se ordena: sem caixa e sem acentos, que é como o
     * `utf8mb4_unicode_ci` da consulta as compara.
     *
     * Comparar os bytes em cru punha as maiúsculas antes das minúsculas -- o "VFC" aparecia
     * antes de "Versão do firmware" --, e comparar com o `intl` obrigava a extensão que a
     * imagem não traz. Isto acompanha o SQL sem depender de nenhuma.
     */
    private static function sortKey(string $label): string
    {
        return strtr(mb_strtolower($label, 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);
    }

    // Memo permanente: o único escritor destas linhas é o semeador, noutro processo.
    public function all(?string $deviceType = null): array
    {
        return $this->catalogs[(string)$deviceType] ??= $this->load($deviceType);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function load(?string $deviceType): array
    {
        if ($deviceType === null || trim($deviceType) === '') {
            $rows = TimestampFormatter::normalizeRows($this->pdo
                ->query('SELECT id, device_type, section, capability_key, label, (section = \'telemetry\') AS is_telemetry, is_configurable, is_requestable, created_at, updated_at FROM capabilities ORDER BY FIELD(device_type, \'watch\', \'ncs\', \'radar\'), FIELD(section, \'telemetry\', \'health\', \'contacts\', \'alarms\', \'settings_system\'), label, capability_key')
                ->fetchAll());

            return $this->appendMissingDefinitions($rows, null);
        }

        $stmt = $this->pdo->prepare('SELECT id, device_type, section, capability_key, label, (section = \'telemetry\') AS is_telemetry, is_configurable, is_requestable, created_at, updated_at FROM capabilities WHERE device_type = ? ORDER BY FIELD(section, \'telemetry\', \'health\', \'contacts\', \'alarms\', \'settings_system\'), label, capability_key');
        $stmt->execute([$deviceType]);
        return $this->appendMissingDefinitions(TimestampFormatter::normalizeRows($stmt->fetchAll()), $deviceType);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, device_type, section, capability_key, label, (section = \'telemetry\') AS is_telemetry, is_configurable, is_requestable, created_at, updated_at FROM capabilities WHERE id = ?');
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
                // Derivado da secção, como na base: um sítio só a decidir o que é telemetria.
                'is_telemetry' => ($definition['section'] ?? '') === 'telemetry',
                'is_configurable' => (bool)($definition['isConfigurable'] ?? false),
                'is_requestable' => (bool)($definition['isRequestable'] ?? false),
                'is_event' => (bool)($definition['isEvent'] ?? false),
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        usort($filtered, static function (array $left, array $right): int {
            $deviceTypeOrder = ['watch', 'ncs', 'radar', 'gateway', 'diaper_sensor', 'bracelet'];
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

            // Pela etiqueta, que é o que quem lê tem à frente: a ordem da lista explica-se
            // pela própria lista. A chave desempata para duas etiquetas iguais não trocarem
            // de lugar entre pedidos.
            $comparison = strcmp(
                self::sortKey((string)($left['label'] ?? '')),
                self::sortKey((string)($right['label'] ?? '')),
            );

            return $comparison !== 0
                ? $comparison
                : strcmp((string)($left['capability_key'] ?? ''), (string)($right['capability_key'] ?? ''));
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
