<?php

namespace Hub\Api\Http;

final class DeviceCollectionFilter
{
    public function filterDevices(array $devices, array $filters): array
    {
        return array_values(array_filter($devices, function (array $device) use ($filters): bool {
            $deviceType = $this->normalizeDeviceType((string)($device['deviceType'] ?? 'watch'));
            $licenseId = $this->normalizeLicenseId((string)($device['licenseId'] ?? '0'));
            $company = trim((string)($device['company'] ?? 'null'));
            $supplier = trim((string)($device['supplier'] ?? ''));
            $model = trim((string)($device['model'] ?? ''));
            $modelFilter = trim((string)($filters['model'] ?? ''));
            $query = trim((string)($filters['q'] ?? ''));

            return (($filters['deviceType'] ?? null) === null || $deviceType === $filters['deviceType'])
                && (($filters['licenseId'] ?? null) === null || $licenseId === $filters['licenseId'])
                && (($filters['company'] ?? null) === null || $company === $filters['company'])
                && (($filters['supplier'] ?? null) === null || $supplier === $filters['supplier'])
                && ($modelFilter === '' || str_contains($this->normalizeSearchText($model), $this->normalizeSearchText($modelFilter)))
                && ($query === '' || $this->matchesDeviceQuery($device, $query));
        }));
    }

    public function filterDevicesForOptions(array $devices, array $filters, string $excludeKey): array
    {
        $candidateFilters = $filters;
        $candidateFilters[$excludeKey] = null;

        return $this->filterDevices($devices, $candidateFilters);
    }

    private function matchesDeviceQuery(array $device, string $query): bool
    {
        $normalizedQuery = $this->normalizeSearchText($query);
        $tokens = array_values(array_filter(preg_split('/\s+/u', $normalizedQuery) ?: [], static fn (string $token): bool => $token !== ''));
        if ($tokens === []) {
            return true;
        }

        $haystack = $this->normalizeSearchText(implode(' ', [
            (string)($device['imei'] ?? ''),
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
            (string)($device['simNumber'] ?? ''),
            (string)($device['company'] ?? ''),
        ]));

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (str_contains($haystack, $token)) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function normalizeSearchText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lower) ?? $lower;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }

    private function normalizeDeviceType(string $value): string
    {
        return \Hub\Domain\DeviceMetadata::normalizeDeviceType($value);
    }

    private function normalizeLicenseId(string $value): string
    {
        return (string)\Hub\Domain\DeviceMetadata::normalizeLicenseId($value);
    }
}
