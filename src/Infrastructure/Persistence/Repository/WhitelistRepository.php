<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use Hub\Domain\DeviceMetadata;
use PDO;

final class WhitelistRepository
{
    use FetchesRows;

    /** O dispositivo com os nomes do modelo e do dono, que vivem nas tabelas deles. */
    private const DEVICES = '
        whitelist w
        JOIN models m ON m.id = w.model_id
        JOIN suppliers s ON s.id = m.supplier_id
        LEFT JOIN licenses l ON l.id = w.license_ref_id
        LEFT JOIN companies c ON c.id = l.company_id
    ';

    private const ROW_COLUMNS = '
        w.imei, s.name AS supplier, m.internal_model AS model, w.device_type, l.license_id,
        w.license_ref_id, w.sim_number, w.device_id, c.name AS company
    ';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo
            ->query('SELECT ' . self::ROW_COLUMNS . ' FROM ' . self::DEVICES . ' ORDER BY w.imei')
            ->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function get(string $imei): ?array
    {
        return $this->fetchOne('SELECT ' . self::ROW_COLUMNS . ' FROM ' . self::DEVICES . ' WHERE w.imei = ?', [$imei]);
    }

    /** @return array<string, mixed>|null */
    public function getDevice(string $imei): ?array
    {
        $stmt = $this->pdo->prepare($this->deviceSelectSql() . ' WHERE w.imei = ?');
        $stmt->execute([$imei]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->normalizeDeviceRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, available: array<string, mixed>, counts: array<string, mixed>}
     */
    public function listPage(array $filters, int $page, int $limit, ?int $licenseScope = null, ?string $companyScope = null): array
    {
        $page = max(1, $page);
        $limit = max(1, $limit);
        $offset = ($page - 1) * $limit;

        [$whereSql, $params] = $this->buildWhereClause($filters, $licenseScope, $companyScope);

        $stmt = $this->pdo->prepare($this->deviceSelectSql() . $whereSql . ' ORDER BY w.imei LIMIT ? OFFSET ?');
        $bindIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param);
        }
        $stmt->bindValue($bindIndex++, (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue($bindIndex++, (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = array_map([$this, 'normalizeDeviceRow'], $stmt->fetchAll() ?: []);

        // Três destes filtros pedem os mesmos valores com e sem contagem: sai uma consulta
        // por filtro e a lista simples é a projecção da contada.
        $deviceTypes = $this->countedValues('w.device_type', 'deviceType', $filters, 'deviceType', $licenseScope, $companyScope);
        $suppliers = $this->countedValues('s.name', 'supplier', $filters, 'supplier', $licenseScope, $companyScope);
        $models = $this->countedValues('m.internal_model', 'model', $filters, 'model', $licenseScope, $companyScope);

        return [
            'items' => $items,
            'total' => $this->countDevices($filters, $licenseScope, $companyScope),
            // `available` é a lista de valores e `counts` os mesmos com o número de dispositivos de
            // cada um; os dois, porque o primeiro é contrato público.
            'available' => [
                'deviceType' => self::optionValues($deviceTypes),
                'licenseId' => $this->distinctValues('l.license_id', 'licenseId', $filters, 'licenseId', $licenseScope, $companyScope),
                'supplier' => self::optionValues($suppliers),
                'model' => self::optionValues($models),
                'company' => $this->distinctValues('c.name', 'company', $filters, 'company', $licenseScope, $companyScope),
            ],
            'counts' => [
                'deviceType' => $deviceTypes,
                'supplier' => $suppliers,
                'model' => $models,
                // O fornecedor com os modelos por baixo, para a árvore de filtros. As listas planas ficam
                // porque dão a contagem de cada caixa com o outro filtro aplicado.
                'supplierModels' => $this->supplierTree($filters, $licenseScope, $companyScope),
                'license' => $this->licenseTree($filters, $licenseScope, $companyScope),
            ],
        ];
    }

    /**
     * Os dispositivos de um tipo dentro de um inquilino, sem as facetas de filtro: quem só
     * quer a lista não paga as oito consultas que a coluna de filtros da dashboard precisa.
     *
     * @return list<array<string, mixed>>
     */
    public function listByDeviceType(string $deviceType, int $licenseScope, string $companyScope, int $limit): array
    {
        [$whereSql, $params] = $this->buildWhereClause(
            ['deviceType' => $deviceType],
            $licenseScope,
            $companyScope,
        );

        $stmt = $this->pdo->prepare($this->deviceSelectSql() . $whereSql . ' ORDER BY w.imei LIMIT ?');
        $bindIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param);
        }
        $stmt->bindValue($bindIndex, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'normalizeDeviceRow'], $stmt->fetchAll() ?: []);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countDevices(array $filters, ?int $licenseScope = null, ?string $companyScope = null): int
    {
        [$whereSql, $params] = $this->buildWhereClause($filters, $licenseScope, $companyScope);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::DEVICES . $whereSql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function register(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int|string $licenseId = 0,
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null'
    ): void {
        $deviceType = DeviceMetadata::normalizeDeviceType($deviceType);
        $modelId = $this->modelId($supplier, $model)
            ?? throw new \InvalidArgumentException("Unknown model {$supplier}/{$model}");
        $stmt = $this->pdo->prepare('
            INSERT INTO whitelist (imei, model_id, device_type, license_ref_id, sim_number, device_id)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE model_id = VALUES(model_id), device_type = VALUES(device_type),
                license_ref_id = VALUES(license_ref_id), sim_number = VALUES(sim_number), device_id = VALUES(device_id)
        ');
        $stmt->execute([$imei, $modelId, $deviceType, $this->licenseRef($company, $licenseId), $simNumber, $deviceId]);
    }

    private function modelId(string $supplier, string $model): ?int
    {
        $row = $this->fetchOne('
            SELECT m.id FROM models m JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = ? AND m.internal_model = ?
        ', [$supplier, $model]);

        return $row === null ? null : (int)$row['id'];
    }

    public function countForModel(int $modelId): int
    {
        return $this->countWhere('w.model_id = ?', $modelId);
    }

    public function countForLicense(int $licenseRef): int
    {
        return $this->countWhere('w.license_ref_id = ?', $licenseRef);
    }

    public function countForCompany(int $companyId): int
    {
        return $this->countWhere('l.company_id = ?', $companyId);
    }

    private function countWhere(string $condition, int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::DEVICES . ' WHERE ' . $condition);
        $stmt->execute([$id]);

        return (int)$stmt->fetchColumn();
    }

    /** A licença que o par empresa e número nomeia; sem número, o dispositivo não tem dono. */
    public function licenseRef(string $company, int|string $licenseId): ?int
    {
        $licenseId = self::storedLicenseId(DeviceMetadata::normalizeLicenseId($licenseId));
        $company = self::storedCompany(DeviceMetadata::normalizeCompany($company));
        if ($licenseId === null || $company === null) {
            return null;
        }
        $row = $this->fetchOne('
            SELECT l.id FROM licenses l JOIN companies c ON c.id = l.company_id
            WHERE c.name = ? AND l.license_id = ?
        ', [$company, $licenseId]);

        return $row === null
            ? throw new \InvalidArgumentException("Unknown license {$company}/{$licenseId}")
            : (int)$row['id'];
    }

    public function licenseExists(string $company, int|string $licenseId): bool
    {
        try {
            $this->licenseRef($company, $licenseId);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** A mesma linha com outra chave, para o `ON UPDATE CASCADE` levar o que lhe aponta. */
    public function rename(string $imei, string $newImei): void
    {
        $stmt = $this->pdo->prepare('UPDATE whitelist SET imei = ? WHERE imei = ?');
        $stmt->execute([$newImei, $imei]);
    }

    /** A fronteira entre o `NULL` da tabela e a sentinela em memória. */
    private static function storedCompany(string $company): ?string
    {
        return $company === 'null' ? null : $company;
    }

    private static function storedLicenseId(int $licenseId): ?int
    {
        return $licenseId === 0 ? null : $licenseId;
    }

    /**
     * A mesma fronteira do `storedLicenseId()`, do lado da leitura: a sentinela `0` pergunta
     * pelos dispositivos sem licença, que na coluna são `NULL` e nunca casam por igualdade.
     *
     * @return array{0: string, 1: list<int>}
     */
    private static function licenseCondition(int|string $licenseId): array
    {
        $stored = self::storedLicenseId(DeviceMetadata::normalizeLicenseId($licenseId));

        return $stored === null ? ['w.license_ref_id IS NULL', []] : ['l.license_id = ?', [$stored]];
    }

    /**
     * Tira um dispositivo do registo com as suas configurações, para um IMEI registado outra vez
     * não herdar as do dono anterior. Sem `ON DELETE CASCADE`, que rebentaria no caminho do MQTT.
     */
    public function unregister(string $imei): void
    {
        // As três remoções são uma só operação: sem cascata, nada repunha a coerência se a
        // segunda falhasse. O PDO não aninha transacções, e quem a abriu é que a fecha.
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        // Só as remoções vão no `try`, onde a transacção está aberta com certeza; uma falha do
        // `commit()` já não deixa nada para desfazer.
        try {
            foreach (['device_configurations', 'device_configuration_changes'] as $table) {
                $this->pdo->prepare("DELETE FROM `{$table}` WHERE imei = ?")->execute([$imei]);
            }

            $this->pdo->prepare('DELETE FROM whitelist WHERE imei = ?')->execute([$imei]);
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        if ($ownsTransaction) {
            $this->pdo->commit();
        }
    }

    public function updateAssociation(string $imei, string $company, int $licenseId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE whitelist SET license_ref_id = ? WHERE imei = ?');
        $stmt->execute([$this->licenseRef($company, $licenseId), $imei]);

        return $stmt->rowCount() > 0;
    }

    private function deviceSelectSql(): string
    {
        return '
            SELECT
                w.imei,
                s.name AS supplier,
                m.internal_model AS model,
                w.device_type AS deviceType,
                l.license_id AS licenseId,
                w.sim_number AS simNumber,
                w.device_id AS deviceId,
                c.name AS company,
                l.name AS licenseName
            FROM ' . self::DEVICES;
    }

    /**
     * A condição da listagem. O `imeiIn`/`imeiNotIn` traz o estado de ligação, que é presença
     * em runtime e não uma coluna, e entra como lista para a paginação sair certa.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhereClause(array $filters, ?int $licenseScope = null, ?string $companyScope = null): array
    {
        $clauses = [];
        $params = [];

        if ($licenseScope !== null) {
            [$sql, $licenseParams] = self::licenseCondition($licenseScope);
            $clauses[] = $sql;
            $params = array_merge($params, $licenseParams);
        }

        if ($companyScope !== null && trim($companyScope) !== '') {
            // Sem `LOWER()`: a coluna é `utf8mb4_unicode_ci` e já compara sem distinguir maiúsculas.
            $clauses[] = 'c.name = ?';
            $params[] = trim($companyScope);
        }

        $deviceTypes = $this->filterValues($filters, 'deviceType');
        if ($deviceTypes !== []) {
            $clauses[] = 'w.device_type IN (' . $this->placeholders(count($deviceTypes)) . ')';
            array_push($params, ...array_map(DeviceMetadata::normalizeDeviceType(...), $deviceTypes));
        }

        $suppliers = $this->filterValues($filters, 'supplier');
        if ($suppliers !== []) {
            $clauses[] = 's.name IN (' . $this->placeholders(count($suppliers)) . ')';
            array_push($params, ...$suppliers);
        }

        // O modelo compara por igualdade: as opções vêm da lista de modelos, e um `LIKE` com "L08"
        // traria também "L08 Pro Max".
        $models = $this->filterValues($filters, 'model');
        if ($models !== []) {
            $clauses[] = 'm.internal_model IN (' . $this->placeholders(count($models)) . ')';
            array_push($params, ...$models);
        }

        // A licença sozinha, da forma antiga do endpoint: sem empresa não há par para formar,
        // e por isso é uma condição independente.
        $legacyLicenseId = trim((string)($filters['licenseId'] ?? ''));
        if ($legacyLicenseId !== '' && $legacyLicenseId !== 'all') {
            [$sql, $licenseParams] = self::licenseCondition($legacyLicenseId);
            $clauses[] = $sql;
            $params = array_merge($params, $licenseParams);
        }

        // Pares, e não dois filtros independentes: cada licença vale só com a sua empresa.
        $pairs = $this->licensePairs($filters);
        if ($pairs !== []) {
            $pairClauses = [];
            foreach ($pairs as $pair) {
                if ($pair['company'] === null) {
                    // Sem empresa é sem licença: uma não existe sem a outra.
                    $pairClauses[] = 'w.license_ref_id IS NULL';
                    continue;
                }
                if ($pair['licenseId'] === null) {
                    $pairClauses[] = 'c.name = ?';
                    $params[] = $pair['company'];
                    continue;
                }
                [$sql, $licenseParams] = self::licenseCondition($pair['licenseId']);
                $pairClauses[] = '(c.name = ? AND ' . $sql . ')';
                $params[] = $pair['company'];
                $params = array_merge($params, $licenseParams);
            }
            $clauses[] = '(' . implode(' OR ', $pairClauses) . ')';
        }

        // Os IMEI que o estado de ligação deixa passar: a presença vive em runtime, e entra como lista
        // nesta cláusula para a paginação, o total e as opções saírem certos.
        if (array_key_exists('imeiIn', $filters) && is_array($filters['imeiIn'])) {
            $imeis = array_values(array_filter(array_map('strval', $filters['imeiIn'])));
            if ($imeis === []) {
                // Filtrar por "ligados" quando nenhum está ligado não é "sem filtro".
                $clauses[] = '1 = 0';
            } else {
                $clauses[] = 'w.imei IN (' . $this->placeholders(count($imeis)) . ')';
                array_push($params, ...$imeis);
            }
        }

        if (array_key_exists('imeiNotIn', $filters) && is_array($filters['imeiNotIn'])) {
            $imeis = array_values(array_filter(array_map('strval', $filters['imeiNotIn'])));
            if ($imeis !== []) {
                $clauses[] = 'w.imei NOT IN (' . $this->placeholders(count($imeis)) . ')';
                array_push($params, ...$imeis);
            }
        }

        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $tokens = preg_split('/\s+/u', $query) ?: [];
            foreach ($tokens as $token) {
                $token = trim((string)$token);
                if ($token === '') {
                    continue;
                }

                $clauses[] = '(w.imei LIKE ? OR s.name LIKE ? OR m.internal_model LIKE ? OR w.sim_number LIKE ? OR c.name LIKE ?)';
                $needle = '%' . $token . '%';
                array_push($params, $needle, $needle, $needle, $needle, $needle);
            }
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * Lista ou valor único: um cliente que ainda envie `deviceType=watch` continua a
     * funcionar em vez de ser ignorado em silêncio.
     *
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    private function filterValues(array $filters, string $key): array
    {
        $raw = $filters[$key] ?? null;
        if ($raw === null) {
            return [];
        }

        $values = is_array($raw) ? $raw : [$raw];
        $clean = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value === '' || $value === 'all') {
                continue;
            }
            $clean[] = $value;
        }

        return array_values(array_unique($clean));
    }

    /**
     * Cada entrada é `empresa`, `empresa:licença`, ou `none` para os que não têm dono. A
     * empresa sozinha quer dizer todas as licenças dela.
     *
     * @param array<string, mixed> $filters
     * @return list<array{company: ?string, licenseId: ?int}>
     */
    private function licensePairs(array $filters): array
    {
        $pairs = [];
        foreach ($this->filterValues($filters, 'license') as $entry) {
            if (strtolower($entry) === 'none') {
                $pairs[] = ['company' => null, 'licenseId' => null];
                continue;
            }

            $separator = strrpos($entry, ':');
            if ($separator === false) {
                $pairs[] = ['company' => $entry, 'licenseId' => null];
                continue;
            }

            $company = substr($entry, 0, $separator);
            $license = substr($entry, $separator + 1);
            $pairs[] = [
                'company' => $company,
                'licenseId' => DeviceMetadata::normalizeLicenseId($license),
            ];
        }

        return $pairs;
    }

    private function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, max(1, $count), '?'));
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<string>
     */
    private function distinctValues(string $column, string $alias, array $filters, string $excludeKey, ?int $licenseScope = null, ?string $companyScope = null): array
    {
        return self::optionValues(
            $this->countedValues($column, $alias, $filters, $excludeKey, $licenseScope, $companyScope)
        );
    }

    /**
     * @param list<array{value: string, count: int}> $options
     * @return list<string>
     */
    private static function optionValues(array $options): array
    {
        return array_map(static fn (array $option): string => (string)$option['value'], $options);
    }

    /**
     * As opções de um filtro, com a contagem de cada uma. O próprio filtro fica de fora da
     * condição: marcar `hitcare` estreita os modelos mas mantém `haviCare` à vista.
     *
     * @param array<string, mixed> $filters
     * @return list<array{value: string, count: int}>
     */
    private function countedValues(string $column, string $alias, array $filters, string $excludeKey, ?int $licenseScope = null, ?string $companyScope = null): array
    {
        $candidateFilters = $filters;
        unset($candidateFilters[$excludeKey]);
        [$whereSql, $params] = $this->buildWhereClause($candidateFilters, $licenseScope, $companyScope);
        $stmt = $this->pdo->prepare(
            "SELECT {$column} AS {$alias}, COUNT(*) AS total FROM " . self::DEVICES
            . $whereSql
            . " GROUP BY {$column} ORDER BY total DESC, {$column}"
        );
        $stmt->execute($params);
        $options = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $value = trim((string)($row[$alias] ?? ''));
            if ($value === '') {
                continue;
            }
            $options[] = ['value' => $value, 'count' => (int)($row['total'] ?? 0)];
        }

        return $options;
    }

    /**
     * Os modelos agrupados pelo fornecedor. O par sai do `GROUP BY` e não de juntar duas
     * listas planas: o mesmo nome de modelo pode existir em dois fornecedores.
     *
     * @param array<string, mixed> $filters
     * @return array{suppliers: list<array{supplier: string, count: int, models: list<array{model: string, count: int}>}>}
     */
    private function supplierTree(array $filters, ?int $licenseScope = null, ?string $companyScope = null): array
    {
        $candidateFilters = $filters;
        unset($candidateFilters['supplier'], $candidateFilters['model']);
        [$whereSql, $params] = $this->buildWhereClause($candidateFilters, $licenseScope, $companyScope);
        $stmt = $this->pdo->prepare(
            'SELECT s.name AS supplier, m.internal_model AS model, COUNT(*) AS total FROM ' . self::DEVICES
            . $whereSql
            . ' GROUP BY s.name, m.internal_model ORDER BY s.name, m.internal_model'
        );
        $stmt->execute($params);

        $suppliers = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $supplier = trim((string)($row['supplier'] ?? ''));
            if ($supplier === '') {
                continue;
            }

            $total = (int)($row['total'] ?? 0);
            // A chave ignora maiúsculas, pela mesma razão que a das empresas: com "MOKO" e
            // "Moko" como duas entradas, a árvore mostrava a contagem partida em duas.
            $key = mb_strtolower($supplier);
            if (!isset($suppliers[$key])) {
                $suppliers[$key] = ['supplier' => $supplier, 'count' => 0, 'models' => []];
            }
            $suppliers[$key]['count'] += $total;

            $model = trim((string)($row['model'] ?? ''));
            if ($model === '') {
                continue;
            }
            $suppliers[$key]['models'][] = ['model' => $model, 'count' => $total];
        }

        usort(
            $suppliers,
            static fn (array $left, array $right): int => $right['count'] <=> $left['count']
                ?: strcasecmp($left['supplier'], $right['supplier'])
        );

        foreach ($suppliers as $index => $supplier) {
            usort(
                $suppliers[$index]['models'],
                static fn (array $left, array $right): int => $right['count'] <=> $left['count']
                    ?: strcasecmp($left['model'], $right['model'])
            );
        }

        return ['suppliers' => $suppliers];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function licenseTree(array $filters, ?int $licenseScope = null, ?string $companyScope = null): array
    {
        $candidateFilters = $filters;
        unset($candidateFilters['license']);
        [$whereSql, $params] = $this->buildWhereClause($candidateFilters, $licenseScope, $companyScope);
        $stmt = $this->pdo->prepare(
            'SELECT c.name AS company, l.license_id AS license_id, COUNT(*) AS total FROM ' . self::DEVICES
            . $whereSql
            . ' GROUP BY c.name, l.license_id ORDER BY c.name, l.license_id'
        );
        $stmt->execute($params);

        $companies = [];
        $none = 0;
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $company = trim((string)($row['company'] ?? ''));
            $total = (int)($row['total'] ?? 0);
            if (($row['company'] ?? null) === null || $company === '') {
                $none += $total;
                continue;
            }

            $licenseId = DeviceMetadata::normalizeLicenseId($row['license_id'] ?? 0);
            // A chave ignora maiúsculas como a comparação do filtro, para a contagem da árvore bater com
            // o resultado do clique. O nome é a primeira grafia vista.
            $key = mb_strtolower($company);
            if (!isset($companies[$key])) {
                $companies[$key] = ['company' => $company, 'count' => 0, 'licenses' => []];
            }
            $companies[$key]['count'] += $total;
            // A ausência de licença não é um nó da árvore: um dispositivo com empresa e sem
            // licença conta para a empresa e mais nada.
            if (($row['license_id'] ?? null) === null) {
                continue;
            }
            $companies[$key]['licenses'][] = ['licenseId' => $licenseId, 'count' => $total];
        }

        usort(
            $companies,
            static fn (array $left, array $right): int => $right['count'] <=> $left['count']
                ?: strcasecmp($left['company'], $right['company'])
        );

        return ['companies' => $companies, 'none' => $none];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeDeviceRow(array $row): array
    {
        $row['deviceType'] = DeviceMetadata::normalizeDeviceType((string)($row['deviceType'] ?? 'watch'));
        $row['licenseId'] = DeviceMetadata::normalizeLicenseId((string)($row['licenseId'] ?? '0'));
        $row['company'] = DeviceMetadata::normalizeCompany($row['company'] ?? null);
        return $row;
    }
}
