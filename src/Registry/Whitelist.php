<?php

declare(strict_types=1);

namespace Hub\Registry;

use Hub\Domain\DeviceMetadata;
use Hub\Infrastructure\Persistence\Repository\WhitelistRepository;
use Hub\Log\Logger;

class Whitelist
{
    /** O tipo de aparelho em que um protocolo procura o alias; um frame não se autentica como outro tipo. */
    private const ALIAS_DEVICE_TYPES = [
        'four-p-touch' => 'watch',
        'ncs' => 'ncs',
        'qinglanst-radar' => 'radar',
        'qinglanst' => 'radar',
    ];

    /** @var array<string, DeviceMetadata> */
    private array $devices = [];
    private int $databaseCacheLoadedAt = 0;

    /**
     * Sem base de dados, a lista vive só em memória, semeada pelos `$devices`.
     *
     * @param array<array-key, array<string, mixed>> $devices
     */
    public function __construct(
        private ?WhitelistRepository $db = null,
        private int $databaseCacheTtlSeconds = 60,
        array $devices = [],
    ) {
        $this->databaseCacheTtlSeconds = max(0, $this->databaseCacheTtlSeconds);
        if ($this->db !== null) {
            $this->loadDatabaseCache();
            return;
        }
        foreach ($devices as $imei => $value) {
            $this->loadEntry((string)$imei, $value);
        }
    }

    /** @param array<string, mixed> $value */
    private function loadEntry(string $imei, array $value): void
    {
        $imei = trim($imei);
        $metadata = DeviceMetadata::fromArray($value);
        if ($imei === '' || $metadata->supplier === '' || $metadata->model === '') {
            return;
        }

        $this->devices[$imei] = $metadata;
    }

    public function isAuthorized(string $imei): bool
    {
        return $this->getMetadata($imei) !== null;
    }

    public function getMetadata(string $imei): ?DeviceMetadata
    {
        if ($this->db !== null) {
            $this->refreshDatabaseCacheIfStale();
        }

        return $this->devices[$imei] ?? null;
    }

    /**
     * @return array<string, DeviceMetadata>
     */
    public function all(): array
    {
        if ($this->db !== null) {
            $this->refreshDatabaseCacheIfStale();
        }

        return $this->devices;
    }

    public function register(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int|string $licenseId = 0,
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null',
    ): void {
        $deviceType = DeviceMetadata::normalizeDeviceType($deviceType);
        $licenseId = DeviceMetadata::normalizeLicenseId($licenseId);
        $company = DeviceMetadata::normalizeCompany($company);
        $this->devices[$imei] = new DeviceMetadata($supplier, $model, $deviceType, $licenseId, $company, $simNumber, $deviceId);
        $this->db?->register($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
    }

    /** Outro IMEI na mesma linha, que é o que leva com ele o resto. */
    public function rename(string $imei, string $newImei): void
    {
        $metadata = $this->devices[$imei] ?? null;
        if ($metadata !== null) {
            unset($this->devices[$imei]);
            $this->devices[$newImei] = $metadata;
        }

        $this->db?->rename($imei, $newImei);
    }

    public function unregister(string $imei): void
    {
        unset($this->devices[$imei]);
        $this->db?->unregister($imei);
    }

    public function updateAssociation(string $imei, string $company, int|string $licenseId): bool
    {
        $current = $this->getMetadata($imei);
        if ($current === null) {
            return false;
        }

        $company = DeviceMetadata::normalizeCompany($company);
        $licenseId = DeviceMetadata::normalizeLicenseId($licenseId);
        $this->devices[$imei] = new DeviceMetadata(
            $current->supplier,
            $current->model,
            $current->deviceType,
            $licenseId,
            $company,
            $current->simNumber,
            $current->deviceId,
        );
        $this->db?->updateAssociation($imei, $company, $licenseId);

        return true;
    }
    /**
     * Devolve um array, e não um `DeviceMetadata`, porque acrescenta o `imei` -- que é a chave
     * do mapa, não um campo da entrada.
     *
     * @return array{imei: string, supplier: string, model: string, deviceType: string, licenseId: int, company: string, simNumber: string, deviceId: string}|null
     */
    public function resolve(string $imei, string $protocol = '', string $ident = ''): ?array
    {
        $exact = $this->getMetadata($imei);
        if ($exact !== null) {
            return ['imei' => $imei] + $exact->toArray();
        }

        $alias = trim($ident !== '' ? $ident : $imei);
        $deviceType = self::ALIAS_DEVICE_TYPES[$protocol] ?? null;
        if ($alias === '' || $deviceType === null) {
            return null;
        }

        if ($this->db !== null) {
            $this->refreshDatabaseCacheIfStale();
        }
        foreach ($this->devices as $canonicalImei => $metadata) {
            if ($metadata->deviceType !== $deviceType) {
                continue;
            }
            // O radar também se anuncia pelo próprio IMEI.
            if ($metadata->deviceId === $alias || ($deviceType === 'radar' && $canonicalImei === $alias)) {
                return ['imei' => $canonicalImei] + $metadata->toArray();
            }
        }

        return null;
    }

    private function refreshDatabaseCacheIfStale(): void
    {
        if (
            $this->db !== null
            && ($this->databaseCacheTtlSeconds === 0
                || (time() - $this->databaseCacheLoadedAt) >= $this->databaseCacheTtlSeconds)
        ) {
            try {
                $this->loadDatabaseCache();
            } catch (\Throwable $e) {
                // Fica a lista anterior: um dispositivo conhecido não passa a desconhecido por uma falha da base.
                Logger::channel('hub')->error("Whitelist reload failed: {$e->getMessage()}");
            }
        }
    }

    private function loadDatabaseCache(): void
    {
        if ($this->db === null) {
            return;
        }

        $rows = $this->db->all();
        $this->devices = [];
        foreach ($rows as $row) {
            $this->loadEntry((string)$row['imei'], $row);
        }
        $this->databaseCacheLoadedAt = time();
    }
}
