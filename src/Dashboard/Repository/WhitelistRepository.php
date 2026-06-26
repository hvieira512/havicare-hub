<?php

namespace Hub\Dashboard\Repository;

use Hub\Dashboard\DeviceMetadata;
use PDO;

final class WhitelistRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo
            ->query('SELECT imei, supplier, model, device_type, license_id, sim_number, device_id, company FROM whitelist ORDER BY imei')
            ->fetchAll();
    }

    public function get(string $imei): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whitelist WHERE imei = ?');
        $stmt->execute([$imei]);

        return $stmt->fetch() ?: null;
    }

    public function getDevice(string $imei): ?array
    {
        $stmt = $this->pdo->prepare($this->deviceSelectSql() . ' WHERE w.imei = ?');
        $stmt->execute([$imei]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->normalizeDeviceRow($row) : null;
    }

    /**
     * @param array{deviceType?: string, licenseId?: string, supplier?: string, model?: string, q?: string} $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, available: array<string, array<int, string>>}
     */
    public function listPage(array $filters, int $page, int $limit, ?string $licenseScope = null): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);
        $offset = ($page - 1) * $limit;

        [$whereSql, $params] = $this->buildWhereClause($filters, $licenseScope);

        $stmt = $this->pdo->prepare($this->deviceSelectSql() . $whereSql . ' ORDER BY w.imei LIMIT ? OFFSET ?');
        $bindIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param);
        }
        $stmt->bindValue($bindIndex++, (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue($bindIndex++, (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = array_map([$this, 'normalizeDeviceRow'], $stmt->fetchAll() ?: []);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM whitelist w' . $this->deviceJoinSql() . $whereSql);
        $count->execute($params);

        return [
            'items' => $items,
            'total' => (int)$count->fetchColumn(),
            'available' => [
                'deviceType' => $this->distinctValues('w.device_type', 'deviceType', $filters, 'deviceType', $licenseScope),
                'licenseId' => $this->distinctValues('w.license_id', 'licenseId', $filters, 'licenseId', $licenseScope),
                'supplier' => $this->distinctValues('w.supplier', 'supplier', $filters, 'supplier', $licenseScope),
                'model' => $this->distinctValues('w.model', 'model', $filters, 'model', $licenseScope),
                'company' => $this->distinctValues('w.company', 'company', $filters, 'company', $licenseScope),
            ],
        ];
    }

    public function register(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        string $licenseId = '0',
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null'
    ): void {
        $deviceType = DeviceMetadata::normalizeDeviceType($deviceType);
        $licenseId = DeviceMetadata::normalizeLicenseId($licenseId);
        $company = trim($company);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $existing = $this->get($imei);
        if ($existing === null) {
            $stmt = $this->pdo->prepare('
                INSERT INTO whitelist (imei, supplier, model, device_type, license_id, sim_number, device_id, company, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company, $now, $now]);
            return;
        }

        $stmt = $this->pdo->prepare('
            UPDATE whitelist
            SET supplier = ?, model = ?, device_type = ?, license_id = ?, sim_number = ?, device_id = ?, company = ?, updated_at = ?
            WHERE imei = ?
        ');
        $stmt->execute([$supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company, $now, $imei]);
    }

    public function unregister(string $imei): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM whitelist WHERE imei = ?');
        $stmt->execute([$imei]);
    }

    private function deviceSelectSql(): string
    {
        return '
            SELECT
                w.imei,
                w.supplier,
                w.model,
                w.device_type AS deviceType,
                w.license_id AS licenseId,
                w.sim_number AS simNumber,
                w.device_id AS deviceId,
                w.company,
                l.name AS licenseName
            FROM whitelist w
        ' . $this->deviceJoinSql();
    }

    private function deviceJoinSql(): string
    {
        return '
            LEFT JOIN companies c ON c.name = w.company
            LEFT JOIN licenses l ON l.license_id = w.license_id AND l.company_id = c.id
        ';
    }

    /**
     * @param array{deviceType?: string, licenseId?: string, supplier?: string, model?: string, q?: string} $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhereClause(array $filters, ?string $licenseScope = null): array
    {
        $clauses = [];
        $params = [];

        if ($licenseScope !== null && trim($licenseScope) !== '') {
            $clauses[] = 'w.license_id = ?';
            $params[] = DeviceMetadata::normalizeLicenseId($licenseScope);
        }

        $deviceType = trim((string)($filters['deviceType'] ?? 'all'));
        if ($deviceType !== '' && $deviceType !== 'all') {
            $clauses[] = 'w.device_type = ?';
            $params[] = DeviceMetadata::normalizeDeviceType($deviceType);
        }

        $licenseId = trim((string)($filters['licenseId'] ?? 'all'));
        if ($licenseId !== '' && $licenseId !== 'all') {
            $clauses[] = 'w.license_id = ?';
            $params[] = DeviceMetadata::normalizeLicenseId($licenseId);
        }

        $supplier = trim((string)($filters['supplier'] ?? 'all'));
        if ($supplier !== '' && $supplier !== 'all') {
            $clauses[] = 'w.supplier = ?';
            $params[] = $supplier;
        }

        $model = trim((string)($filters['model'] ?? 'all'));
        if ($model !== '' && $model !== 'all') {
            $clauses[] = 'w.model = ?';
            $params[] = $model;
        }

        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $tokens = preg_split('/\s+/u', $query) ?: [];
            foreach ($tokens as $token) {
                $token = trim((string)$token);
                if ($token === '') {
                    continue;
                }

                $clauses[] = '(LOWER(w.imei) LIKE LOWER(?) OR LOWER(w.supplier) LIKE LOWER(?) OR LOWER(w.model) LIKE LOWER(?) OR LOWER(w.sim_number) LIKE LOWER(?) OR LOWER(w.company) LIKE LOWER(?))';
                $needle = '%' . $token . '%';
                array_push($params, $needle, $needle, $needle, $needle, $needle);
            }
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array{deviceType?: string, licenseId?: string, supplier?: string, model?: string, q?: string} $filters
     * @return list<string>
     */
    private function distinctValues(string $column, string $alias, array $filters, string $excludeKey, ?string $licenseScope = null): array
    {
        $candidateFilters = $filters;
        unset($candidateFilters[$excludeKey]);
        [$whereSql, $params] = $this->buildWhereClause($candidateFilters, $licenseScope);
        $stmt = $this->pdo->prepare("SELECT DISTINCT {$column} AS {$alias} FROM whitelist w" . $this->deviceJoinSql() . $whereSql . " ORDER BY {$column}");
        $stmt->execute($params);
        $values = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $value = trim((string)($row[$alias] ?? ''));
            if ($value === '') {
                continue;
            }
            $values[] = $value;
        }

        return array_values(array_unique($values));
    }

    private function normalizeDeviceRow(array $row): array
    {
        $row['deviceType'] = DeviceMetadata::normalizeDeviceType((string)($row['deviceType'] ?? $row['device_type'] ?? 'watch'));
        $row['licenseId'] = DeviceMetadata::normalizeLicenseId((string)($row['licenseId'] ?? $row['license_id'] ?? '0'));
        $row['simNumber'] = (string)($row['simNumber'] ?? $row['sim_number'] ?? '');
        $row['deviceId'] = (string)($row['deviceId'] ?? $row['device_id'] ?? '');
        $row['licenseName'] = (string)($row['licenseName'] ?? '');

        return $row;
    }
}
