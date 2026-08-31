<?php

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Command\DeviceCommandCatalog;
use Hub\Dashboard\DashboardStoreContract;
use Hub\Domain\DeviceMetadata;
use Hub\Domain\DeviceProtocol;
use Hub\Registry\Whitelist;

/**
 * Encontrar um dispositivo e decidir quem o pode ver.
 *
 * Todas as partes da API de dispositivos precisam disto -- ler um, pedir-lhe uma
 * funcionalidade, projectar a sua configuração --, e por isso vive num colaborador em vez de
 * só se alcançar de dentro do `DeviceService`.
 */
final class DeviceDirectory
{
    public function __construct(
        private DashboardStoreContract $store,
        private Whitelist $whitelist,
        private ApiDataAccess $db,
    ) {
    }

    public function protocolForModel(string $supplier, string $model): string
    {
        return DeviceProtocol::forModel($supplier, $model);
    }

    public function normalizeDeviceId(string $imei, string $supplier, string $model, string $deviceType, string $deviceId): string
    {
        $deviceType = DeviceMetadata::normalizeDeviceType($deviceType);
        if ($this->protocolForModel($supplier, $model) !== 'four-p-touch') {
            return $deviceType === 'watch' ? '' : $deviceId;
        }

        if ($deviceType !== 'watch') {
            return $deviceId;
        }

        $derived = DeviceCommandCatalog::deriveFourPTouchDeviceId($imei);
        return $derived !== '' ? $derived : $deviceId;
    }

    public function modelForDevice(array $device): ?array
    {
        return $this->modelForSupplierAndName((string)($device['supplier'] ?? ''), (string)($device['model'] ?? ''));
    }

    public function modelForSupplierAndName(string $supplier, string $model): ?array
    {
        if (trim($supplier) === '' || trim($model) === '') {
            return null;
        }

        return $this->db->models->find($supplier, $model);
    }


    public function canAccessDevice(string $imei, ?ApiAuthContext $auth, ?array $device = null): bool
    {
        if ($auth === null || $auth->isAdmin()) {
            return true;
        }

        $device ??= $this->deviceSnapshot($imei);
        $licenseId = $this->deviceLicenseId($imei, $device);
        $company = $this->deviceCompany($imei, $device);

        return $auth->canAccessTenant($company, $licenseId);
    }

    private function deviceLicenseId(string $imei, array $device): int
    {
        $licenseId = trim((string)($device['licenseId'] ?? ''));
        if ($licenseId !== '') {
            return DeviceMetadata::normalizeLicenseId($licenseId);
        }

        return $this->whitelist->getMetadata($imei)?->licenseId ?? 0;
    }

    private function deviceCompany(string $imei, array $device): string
    {
        $company = trim((string)($device['company'] ?? ''));
        if ($company !== '') {
            return $company;
        }

        return $this->whitelist->getMetadata($imei)?->company ?? '';
    }

    public function normalizeLicenseId(int|string $licenseId, string $deviceType): int
    {
        $normalized = trim($licenseId);

        if ($normalized === '' && $deviceType === 'watch') {
            return 0;
        }

        return $normalized !== '' ? (int)$normalized : 0;
    }


    public function deviceSnapshot(string $imei): array
    {
        $device = $this->db->whitelist->getDevice($imei) ?? ['imei' => $imei];
        $storeDevice = array_intersect_key(
            $this->store->device($imei),
            array_flip([
                'imei',
                'supplier',
                'model',
                'deviceType',
                'licenseId',
                'simNumber',
                'deviceId',
                'company',
                'online',
                'lastSeenAt',
                'lastStateAt',
                'protocol',
                'transport',
                'lastConnectionId',
            ])
        );
        $metadata = $this->whitelist->getMetadata($imei);
        $device = array_merge(
            $device,
            array_filter($storeDevice, static fn (mixed $value): bool => $value !== '' && $value !== null)
        );
        $device += [
            'supplier' => $metadata?->supplier ?? '',
            'model' => $metadata?->model ?? '',
            'deviceType' => $metadata?->deviceType ?? 'watch',
            'licenseId' => $metadata?->licenseId ?? 0,
            'simNumber' => $metadata?->simNumber ?? '',
            'deviceId' => $metadata?->deviceId ?? '',
            'company' => $metadata?->company ?? 'null',
        ];
        $runtimeStates = $this->store->runtimeStates([$imei]);

        return $this->overlayRuntimeState($device, $runtimeStates);
    }

    /**
     * @param array<string, array<string, mixed>> $runtimeStates
     */
    public function overlayRuntimeState(array $device, array $runtimeStates): array
    {
        $imei = (string)($device['imei'] ?? '');
        if ($imei === '' || !isset($runtimeStates[$imei])) {
            $device['online'] = (bool)($device['online'] ?? false);
            return $device;
        }

        $runtime = $runtimeStates[$imei];
        foreach (
            [
            'online',
            'lastSeenAt',
            'lastStateAt',
            'protocol',
            'transport',
            'lastConnectionId',
            ] as $field
        ) {
            if (array_key_exists($field, $runtime)) {
                $device[$field] = $runtime[$field];
            }
        }

        return $device;
    }
}
