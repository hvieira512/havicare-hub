<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Repository\DeviceConfigurationLifecycleRepository;
use Hub\Domain\Capability\CapabilityCatalog;

/**
 * Reconcilia as duas eras de armazenamento de configuração num só retrato.
 *
 * As linhas escritas depois do ciclo de vida trazem revisões, estado de sincronização e as
 * operações que as tentaram entregar. As escritas antes dele não trazem nada disso, e
 * continuam legíveis até o próximo PATCH lhes criar a primeira revisão -- portanto as duas
 * têm de sair pela mesma porta, com a mesma forma, sem quem consome saber a diferença.
 *
 * São 160 linhas sobre um assunto só, e por isso saíram do `DeviceService`: a fachada decide
 * *quando* montar este retrato, e não *como*.
 */
final class ConfigurationLifecyclePresenter
{
    public function __construct(
        private readonly DeviceConfigurationLifecycleRepository $lifecycle,
        private readonly DeviceCapabilityPresenter $capabilities,
        private readonly ConfigurationSyncStatus $configurationSync,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $configRows
     * @return array{effectiveConfigurations:array<string,mixed>,configurationSync:array<string,mixed>}
     */
    public function present(
        string $imei,
        ?array $model,
        string $protocol,
        array $configRows,
        array $desired
    ): array {
        $changes = $this->lifecycle->currentForImei($imei);
        $entries = [];
        $effective = [];
        foreach ($changes as $change) {
            $key = (string)$change['config_key'];
            $section = CapabilityCatalog::sectionForCapabilityKey($key) ?? 'settings_system';
            $operations = array_map(static fn(array $operation): array => [
                'operationId' => (string)$operation['operation_id'],
                'nativeKey' => (string)$operation['native_key'],
                'command' => (string)$operation['native_type'],
                'confirmationMode' => (string)$operation['confirmation_mode'],
                'deliveryStatus' => (string)$operation['delivery_status'],
                'error' => (string)$operation['error_code'],
                'attempts' => (int)$operation['attempts'],
                'maxAttempts' => (int)$operation['max_attempts'],
                'updatedAt' => (string)$operation['updated_at'],
            ], (array)$change['operations']);
            $nativeKey = (string)($operations[0]['nativeKey'] ?? '');
            $desiredValue = $change['desired_payload'];
            if (is_array($desiredValue)) {
                $desiredValue = $this->capabilities->normalizeCapabilityValue(
                    $protocol,
                    $key,
                    $nativeKey,
                    $desiredValue
                );
            }
            $effectiveValue = $change['effective_payload'];
            if (is_array($effectiveValue)) {
                $effectiveValue = $this->capabilities->normalizeCapabilityValue(
                    $protocol,
                    $key,
                    $nativeKey,
                    $effectiveValue
                );
                $effective[$key] = $effectiveValue;
            }
            $entries[$section][$key] = [
                'status' => (string)$change['sync_status'],
                'changeId' => (string)$change['change_id'],
                'desiredRevision' => (int)$change['desired_revision'],
                'desired' => $desiredValue,
                'effective' => $effectiveValue,
                'hasUnconfirmedChanges' => (string)$change['sync_status'] !== 'confirmed',
                'desiredUpdatedAt' => (string)$change['created_at'],
                'confirmedAt' => (string)$change['confirmed_at'],
                'operations' => $operations,
            ];
        }

        // As linhas escritas antes do ciclo de vida continuam legíveis até o próximo PATCH
        // lhes criar a primeira revisão.
        $legacyPending = $this->pendingConfiguration($model, $protocol, $configRows);
        foreach ($desired as $key => $value) {
            $section = CapabilityCatalog::sectionForCapabilityKey((string)$key) ?? 'settings_system';
            if (isset($entries[$section][$key])) {
                continue;
            }
            $pending = $legacyPending[$section][$key] ?? null;
            $status = is_array($pending) ? (string)($pending['status'] ?? 'awaiting_confirmation') : 'confirmed';
            $legacyEffective = $status === 'confirmed' || $status === 'applied' ? $value : null;
            if ($legacyEffective !== null) {
                $effective[$key] = $legacyEffective;
            }
            $entries[$section][$key] = [
                'status' => $status === 'applied' ? 'confirmed' : $status,
                'changeId' => '',
                'desiredRevision' => 0,
                'desired' => $value,
                'effective' => $legacyEffective,
                'hasUnconfirmedChanges' => $legacyEffective === null,
                'operations' => [],
            ];
        }

        $flat = [];
        foreach ($entries as $section) {
            array_push($flat, ...array_values($section));
        }
        $pendingCount = count(array_filter($flat, static fn(array $entry): bool =>
            !in_array($entry['status'], ['confirmed', 'failed'], true)));
        $failedCount = count(array_filter($flat, static fn(array $entry): bool =>
            $entry['status'] === 'failed'));

        return [
            'effectiveConfigurations' => $effective,
            'configurationSync' => [
                'status' => $failedCount > 0 ? 'failed' : ($pendingCount > 0 ? 'pending' : 'confirmed'),
                'hasUnconfirmedChanges' => $pendingCount > 0 || $failedCount > 0,
                'pendingCount' => $pendingCount,
                'failedCount' => $failedCount,
                'entries' => $entries,
            ],
        ];
    }

    private function pendingConfiguration(?array $model, string $protocol, array $configRows): array
    {
        $desiredCapabilities = $this->capabilities->deviceCapabilitiesFromPayloadKey($model, $protocol, $configRows, 'desired_payload', false);
        $reportedCapabilities = $this->capabilities->deviceCapabilitiesFromPayloadKey(
            $model,
            $protocol,
            $this->configurationValueReportRows($configRows),
            'reported_payload',
            false
        );
        return $this->configurationSync->pendingEntries(
            $protocol,
            $desiredCapabilities,
            $reportedCapabilities,
            $configRows,
        );
    }

    /**
     * As confirmações de entrega ficam no `reported_payload` para continuarem
     * inspeccionáveis, mas não são valores reportados e não se comparam com o pretendido.
     *
     * @param list<array<string, mixed>> $configRows
     * @return list<array<string, mixed>>
     */
    private function configurationValueReportRows(array $configRows): array
    {
        return array_map(function (array $row): array {
            $reported = is_array($row['reported_payload'] ?? null)
                ? $row['reported_payload']
                : [];
            if ($this->isAcknowledgementOnlyConfigurationReport($reported)) {
                $row['reported_payload'] = [];
            }

            return $row;
        }, $configRows);
    }

    /**
     * @param array<string, mixed> $reported
     */
    private function isAcknowledgementOnlyConfigurationReport(array $reported): bool
    {
        if ((string)($reported['type'] ?? '') !== 'device_config') {
            return false;
        }

        $data = $reported['data'] ?? null;
        if (!is_array($data) || array_diff(array_keys($data), ['status']) !== []) {
            return false;
        }

        return in_array(strtolower(trim((string)($data['status'] ?? ''))), [
            'ok',
            'success',
            'acked',
        ], true);
    }
}
