<?php

namespace Hub\Api\Services;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityHelpers;

/**
 * Decide se um dispositivo aplicou o que o hub lhe pediu.
 *
 * As árvores de capacidades pretendida e reportada são achatadas em caminhos comparáveis
 * `secção.chave`, comparadas, e cada diferença é reportada com o estado do ciclo de vida do
 * comando que a devia ter entregado.
 *
 * Vive à parte do `DeviceCapabilityPresenter`, que projectava capacidades e classificava a
 * entrega delas na mesma classe. Esta metade não precisa do registo nem da base de dados.
 */
final class ConfigurationSyncStatus
{
    use CapabilityHelpers;

    private const IN_FLIGHT_STATUSES = ['queued', 'waiting', 'sent'];

    private const FAILURE_STATUSES = [
        'failed',
        'dropped',
        'delivery_failed',
        'retry_exhausted',
        'response_timeout',
    ];

    /**
     * As capacidades que o dispositivo não confirmou, agrupadas por secção e chave.
     *
     * @param array<string, mixed> $desiredCapabilities
     * @param array<string, mixed> $reportedCapabilities
     * @param list<array<string, mixed>> $configRows
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function pendingEntries(
        string $protocol,
        array $desiredCapabilities,
        array $reportedCapabilities,
        array $configRows,
    ): array {
        $desiredValues = $this->flattenWritableCapabilities($protocol, $desiredCapabilities);
        $reportedValues = $this->flattenWritableCapabilities($protocol, $reportedCapabilities);
        $rowMeta = $this->genericCapabilityRowMeta($configRows);
        $pending = [];

        foreach ($desiredValues as $path => $desiredValue) {
            $reportedExists = array_key_exists($path, $reportedValues);
            $reportedValue = $reportedExists ? $reportedValues[$path] : null;
            if ($reportedExists && $this->capabilityValuesEqual($desiredValue, $reportedValue)) {
                continue;
            }

            [$section, $key] = explode('.', $path, 2);
            $meta = $rowMeta[$key] ?? [];
            $lastStatus = (string)($meta['last_status'] ?? '');
            $pending[$section][$key] = [
                'status' => $this->pendingStatus($lastStatus, $reportedExists),
                'error' => $this->pendingFailureCode($lastStatus),
                'desired' => $desiredValue,
                'reported' => $reportedValue,
                'updatedAt' => $meta['updated_at'] ?? '',
                'lastCommandId' => $meta['last_command_id'] ?? '',
            ];
        }

        return $pending;
    }

    /**
     * @param array<string, mixed> $capabilities
     * @return array<string, mixed>
     */
    public function flattenWritableCapabilities(string $protocol, array $capabilities): array
    {
        $flattened = [];
        foreach ($capabilities as $section => $entries) {
            if ($section === 'telemetry' || !is_array($entries)) {
                continue;
            }
            foreach ($entries as $key => $value) {
                if (is_array($value) && array_key_exists('supported', $value) && !array_key_exists('value', $value)) {
                    continue;
                }
                $flattened["{$section}.{$key}"] = $this->publicConfigurationValueForGenericKey(
                    $protocol,
                    $key,
                    $this->extractCapabilityValue($value)
                );
            }
        }

        return $flattened;
    }

    public function capabilityValuesEqual(mixed $left, mixed $right): bool
    {
        return $this->normalizeComparableValue($left) === $this->normalizeComparableValue($right);
    }

    /**
     * A linha de ciclo de vida mais recente por chave genérica, com a falha a ganhar quando
     * duas linhas partilham a mesma hora.
     *
     * @param list<array<string, mixed>> $configRows
     * @return array<string, array{updated_at: string, last_status: string, last_command_id: string}>
     */
    public function genericCapabilityRowMeta(array $configRows): array
    {
        $meta = [];
        foreach ($configRows as $row) {
            $nativeKey = trim((string)($row['native_key'] ?? $row['config_key'] ?? ''));
            if ($nativeKey === '') {
                continue;
            }
            $genericKey = CapabilityCatalog::normalizeStoredCapabilityKey(
                (string)($row['config_key'] ?? $nativeKey)
            );
            if ($genericKey === null) {
                continue;
            }
            $updatedAt = (string)($row['desired_updated_at'] ?? '');
            $lastStatus = (string)($row['last_status'] ?? '');
            $existing = $meta[$genericKey] ?? null;
            $isNewer = $existing === null
                || strcmp($updatedAt, (string)$existing['updated_at']) > 0;
            $sameUpdateWithStrongerStatus = $existing !== null
                && $updatedAt === (string)$existing['updated_at']
                && $this->configurationStatusPriority($lastStatus)
                    > $this->configurationStatusPriority((string)$existing['last_status']);
            if ($isNewer || $sameUpdateWithStrongerStatus) {
                $meta[$genericKey] = [
                    'updated_at' => $updatedAt,
                    'last_status' => $lastStatus,
                    'last_command_id' => (string)($row['last_command_id'] ?? ''),
                ];
            }
        }

        return $meta;
    }

    public function pendingStatus(string $lastStatus, bool $reportedExists): string
    {
        if ($this->pendingFailureCode($lastStatus) !== '') {
            return 'failed';
        }
        if (!$reportedExists) {
            if ($lastStatus === 'acked') {
                return 'applied';
            }
            return in_array($lastStatus, self::IN_FLIGHT_STATUSES, true)
                ? 'waiting_device'
                : 'never_reported';
        }

        return 'diverged';
    }

    public function pendingFailureCode(string $lastStatus): string
    {
        return in_array($lastStatus, self::FAILURE_STATUSES, true) ? $lastStatus : '';
    }

    private function configurationStatusPriority(string $status): int
    {
        if ($this->pendingFailureCode($status) !== '') {
            return 3;
        }
        if (in_array($status, self::IN_FLIGHT_STATUSES, true)) {
            return 2;
        }
        if ($status === 'acked') {
            return 1;
        }

        return 0;
    }

    private function extractCapabilityValue(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value)) {
            return $value['value'];
        }
        if (is_array($value) && array_key_exists('items', $value) && array_key_exists('_meta', $value)) {
            return $value['items'];
        }

        return $value;
    }

    private function publicConfigurationValueForGenericKey(string $protocol, string $genericKey, mixed $value): mixed
    {
        return match ($genericKey) {
            'sos_contacts' => is_array($value)
                ? $this->stringifyPhoneList($value)
                : [],
            'call_whitelist' => is_array($value)
                ? $this->stringifyCallWhitelistValue($protocol, $value)
                : $value,
            default => $value,
        };
    }

    /**
     * O Vivistar guarda as entradas da whitelist como contactos com nome; todos os outros
     * protocolos comparam números de telefone simples.
     *
     * @param array<string|int, mixed> $value
     */
    private function stringifyCallWhitelistValue(string $protocol, array $value): mixed
    {
        if ($protocol === 'vivistar-iw') {
            $normalize = self::normalizePublicContactItem(...);
            if (array_key_exists('contacts', $value) && is_array($value['contacts'])) {
                return array_values(array_filter(array_map(
                    $normalize,
                    $value['contacts']
                )));
            }

            if (array_key_exists('numbers', $value) && is_array($value['numbers'])) {
                return array_values(array_filter(array_map(
                    static fn(mixed $phone): ?array => $normalize(['phone' => $phone]),
                    $value['numbers']
                )));
            }

            if (array_is_list($value)) {
                if ($value !== [] && is_array($value[0] ?? null)) {
                    return array_values(array_filter(array_map(
                        $normalize,
                        $value
                    )));
                }

                return array_values(array_filter(array_map(
                    static fn(mixed $phone): ?array => $normalize(['phone' => $phone]),
                    $value
                )));
            }

            return $value;
        }

        if (array_key_exists('numbers', $value) && is_array($value['numbers'])) {
            return self::stringList($value['numbers']);
        }

        if (array_key_exists('contacts', $value) && is_array($value['contacts'])) {
            return self::stringList(array_map(
                static fn(mixed $contact): string => self::normalizePublicContactPhone($contact),
                $value['contacts']
            ));
        }

        if (!array_is_list($value)) {
            if (array_key_exists('phone', $value)) {
                return self::stringList([(string)$value['phone']]);
            }

            return $value;
        }

        if ($value !== [] && is_array($value[0] ?? null)) {
            return self::stringList(array_map(
                static fn(mixed $contact): string => self::normalizePublicContactPhone($contact),
                $value
            ));
        }

        return self::stringList($value);
    }

    /**
     * @return array{name: string, phone: string}|null
     */
    private static function normalizePublicContactItem(mixed $item): ?array
    {
        if (!is_array($item)) {
            $phone = trim((string)$item);
            if ($phone === '') {
                return null;
            }

            return ['name' => '', 'phone' => $phone];
        }

        $name = trim((string)($item['name'] ?? ''));
        $phone = trim((string)($item['phone'] ?? ''));
        if ($phone === '') {
            return null;
        }

        return ['name' => $name, 'phone' => $phone];
    }

    private static function normalizePublicContactPhone(mixed $item): string
    {
        if (is_array($item)) {
            return trim((string)($item['phone'] ?? ''));
        }

        return trim((string)$item);
    }
}
