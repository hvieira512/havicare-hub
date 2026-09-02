<?php

namespace Hub\Api\Repository;

use Hub\Domain\DeviceMetadata;
use Hub\Infrastructure\Persistence\TimestampFormatter;
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

        $row = $stmt->fetch();
        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

    public function findByDeviceId(string $deviceId, ?string $deviceType = null): ?array
    {
        $sql = 'SELECT * FROM whitelist WHERE device_id = ?';
        $params = [$deviceId];
        if ($deviceType !== null) {
            $sql .= ' AND device_type = ?';
            $params[] = DeviceMetadata::normalizeDeviceType($deviceType);
        }
        $sql .= ' ORDER BY imei LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : TimestampFormatter::normalizeRow($row);
    }

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
    public function listPage(
        array $filters,
        int $page,
        int $limit,
        ?int $licenseScope = null,
        ?string $companyScope = null,
        ?array $sort = null
    ): array {
        $page = max(1, $page);
        $limit = max(1, $limit);
        $offset = ($page - 1) * $limit;

        [$whereSql, $params] = $this->buildWhereClause($filters, $licenseScope, $companyScope);

        $stmt = $this->pdo->prepare($this->deviceSelectSql() . $whereSql . $this->orderBySql($sort) . ' LIMIT ? OFFSET ?');
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
        $suppliers = $this->countedValues('w.supplier', 'supplier', $filters, 'supplier', $licenseScope, $companyScope);
        $models = $this->countedValues('w.model', 'model', $filters, 'model', $licenseScope, $companyScope);

        return [
            'items' => $items,
            'total' => $this->countDevices($filters, $licenseScope, $companyScope),
            // `available` é a lista de valores, e `counts` os mesmos valores com o número de
            // dispositivos de cada um -- que é o que a coluna de filtros mostra ao lado de
            // cada caixa. Os dois, porque o primeiro é contrato público.
            'available' => [
                'deviceType' => self::optionValues($deviceTypes),
                'licenseId' => $this->distinctValues('w.license_id', 'licenseId', $filters, 'licenseId', $licenseScope, $companyScope),
                'supplier' => self::optionValues($suppliers),
                'model' => self::optionValues($models),
                'company' => $this->distinctValues('w.company', 'company', $filters, 'company', $licenseScope, $companyScope),
            ],
            'counts' => [
                'deviceType' => $deviceTypes,
                'supplier' => $suppliers,
                'model' => $models,
                // O fornecedor com os seus modelos por baixo, para a coluna de filtros os
                // desenhar numa árvore só. As duas listas planas ficam: são elas que dão a
                // contagem de cada caixa quando o outro filtro está aplicado.
                'supplierModels' => $this->supplierTree($filters, $licenseScope, $companyScope),
                'license' => $this->licenseTree($filters, $licenseScope, $companyScope),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countDevices(array $filters, ?int $licenseScope = null, ?string $companyScope = null): int
    {
        [$whereSql, $params] = $this->buildWhereClause($filters, $licenseScope, $companyScope);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM whitelist w' . $this->deviceJoinSql() . $whereSql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function register(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int $licenseId = 0,
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null'
    ): void {
        $deviceType = DeviceMetadata::normalizeDeviceType($deviceType);
        $licenseId = self::storedLicenseId(DeviceMetadata::normalizeLicenseId($licenseId));
        $company = self::storedCompany(DeviceMetadata::normalizeCompany($company));
        $existing = $this->get($imei);
        if ($existing === null) {
            $stmt = $this->pdo->prepare('
                INSERT INTO whitelist (imei, supplier, model, device_type, license_id, sim_number, device_id, company)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company]);
            return;
        }

        $stmt = $this->pdo->prepare('
            UPDATE whitelist
            SET supplier = ?, model = ?, device_type = ?, license_id = ?, sim_number = ?, device_id = ?, company = ?
            WHERE imei = ?
        ');
        $stmt->execute([$supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company, $imei]);
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
     * Tira um dispositivo do registo e com ele as suas configurações, senão um IMEI registado
     * outra vez herdava os valores do dono anterior. Sem `ON DELETE CASCADE`, que
     * transformaria uma mensagem em voo numa excepção no caminho quente do MQTT.
     */
    public function unregister(string $imei): void
    {
        // As três remoções são uma só operação: sem cascata, nada repunha a coerência se a
        // segunda falhasse. O PDO não aninha transacções, e quem a abriu é que a fecha.
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        // Só as remoções vão dentro do `try`: aqui, se falhar, a transacção está aberta com
        // certeza, e o `rollBack()` dispensa perguntar. O `commit()` fica de fora porque uma
        // falha *dele* já não deixa nada para desfazer.
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
        $company = self::storedCompany(DeviceMetadata::normalizeCompany($company));
        $licenseId = self::storedLicenseId(DeviceMetadata::normalizeLicenseId($licenseId));
        $stmt = $this->pdo->prepare('
            UPDATE whitelist
            SET company = ?, license_id = ?
            WHERE imei = ?
        ');
        $stmt->execute([$company, $licenseId, $imei]);

        return $stmt->rowCount() > 0;
    }

    /** As colunas por que a listagem se deixa ordenar, e a expressão SQL de cada uma. */
    public const SORTABLE_COLUMNS = [
        'imei' => 'w.imei',
        'supplier' => 'w.supplier',
        'model' => 'w.model',
        'deviceType' => 'w.device_type',
        'licenseId' => 'w.license_id',
        'company' => 'w.company',
        'licenseName' => 'l.name',
    ];

    /**
     * @param array{column?: string, descending?: bool}|null $sort
     */
    private function orderBySql(?array $sort): string
    {
        $column = self::SORTABLE_COLUMNS[$sort['column'] ?? 'imei'] ?? 'w.imei';
        $direction = ($sort['descending'] ?? false) ? ' DESC' : '';

        if ($column === 'w.imei') {
            return ' ORDER BY w.imei' . $direction;
        }

        // Um dispositivo sem dono tem `NULL` na empresa e na licença, e o MariaDB põe `NULL`
        // à frente em ascendente. A falta de valor vai para o fim nos dois sentidos, como no
        // `sortRows` da dashboard: não ter empresa não é ser a primeira delas.
        //
        // O IMEI fecha sempre. Sem ele, ordenar por uma coluna com valores repetidos deixa a
        // ordem por decidir, e percorrer as páginas repete umas linhas e perde outras.
        return ' ORDER BY ' . $column . ' IS NULL, ' . $column . $direction . ', w.imei';
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
            $clauses[] = 'w.license_id = ?';
            $params[] = DeviceMetadata::normalizeLicenseId($licenseScope);
        }

        if ($companyScope !== null && trim($companyScope) !== '') {
            $clauses[] = 'LOWER(w.company) = LOWER(?)';
            $params[] = trim($companyScope);
        }

        $deviceTypes = $this->filterValues($filters, 'deviceType');
        if ($deviceTypes !== []) {
            $clauses[] = 'w.device_type IN (' . $this->placeholders(count($deviceTypes)) . ')';
            foreach ($deviceTypes as $deviceType) {
                $params[] = DeviceMetadata::normalizeDeviceType($deviceType);
            }
        }

        $suppliers = $this->filterValues($filters, 'supplier');
        if ($suppliers !== []) {
            $clauses[] = 'w.supplier IN (' . $this->placeholders(count($suppliers)) . ')';
            foreach ($suppliers as $supplier) {
                $params[] = $supplier;
            }
        }

        // O modelo compara por igualdade e não por semelhança: as opções vêm da própria lista
        // de modelos existentes, logo o que chega é um nome inteiro, e com `LIKE` escolher
        // "L08" trazia também "L08 Pro Max".
        $models = $this->filterValues($filters, 'model');
        if ($models !== []) {
            $clauses[] = 'w.model IN (' . $this->placeholders(count($models)) . ')';
            foreach ($models as $model) {
                $params[] = $model;
            }
        }

        // A licença sozinha, da forma antiga do endpoint: sem empresa não há par para formar,
        // e por isso é uma condição independente.
        $legacyLicenseId = trim((string)($filters['licenseId'] ?? ''));
        if ($legacyLicenseId !== '' && $legacyLicenseId !== 'all') {
            $clauses[] = 'w.license_id = ?';
            $params[] = DeviceMetadata::normalizeLicenseId($legacyLicenseId);
        }

        // Pares, e não dois filtros independentes: {hitcare, haviCare} com {1001, 2002}
        // trazia um dispositivo da hitcare com a licença 2002.
        $pairs = $this->licensePairs($filters);
        if ($pairs !== []) {
            $pairClauses = [];
            foreach ($pairs as $pair) {
                if ($pair['company'] === null) {
                    // Sem empresa é sem licença: uma não existe sem a outra.
                    $pairClauses[] = 'w.company IS NULL';
                    continue;
                }
                if ($pair['licenseId'] === null) {
                    $pairClauses[] = 'w.company = ?';
                    $params[] = $pair['company'];
                    continue;
                }
                $pairClauses[] = '(w.company = ? AND w.license_id = ?)';
                $params[] = $pair['company'];
                $params[] = $pair['licenseId'];
            }
            $clauses[] = '(' . implode(' OR ', $pairClauses) . ')';
        }

        // Os IMEI que o estado de ligação deixa passar. A presença vive em runtime e não na
        // base de dados, por isso entra como lista -- mas nesta mesma cláusula, que é o que
        // mantém a paginação, o total e as listas de opções certos.
        if (array_key_exists('imeiIn', $filters) && is_array($filters['imeiIn'])) {
            $imeis = array_values(array_filter(array_map('strval', $filters['imeiIn'])));
            if ($imeis === []) {
                // Filtrar por "ligados" quando nenhum está ligado não é "sem filtro".
                $clauses[] = '1 = 0';
            } else {
                $clauses[] = 'w.imei IN (' . $this->placeholders(count($imeis)) . ')';
                foreach ($imeis as $imei) {
                    $params[] = $imei;
                }
            }
        }

        if (array_key_exists('imeiNotIn', $filters) && is_array($filters['imeiNotIn'])) {
            $imeis = array_values(array_filter(array_map('strval', $filters['imeiNotIn'])));
            if ($imeis !== []) {
                $clauses[] = 'w.imei NOT IN (' . $this->placeholders(count($imeis)) . ')';
                foreach ($imeis as $imei) {
                    $params[] = $imei;
                }
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

                $clauses[] = '(LOWER(w.imei) LIKE LOWER(?) OR LOWER(w.supplier) LIKE LOWER(?) OR LOWER(w.model) LIKE LOWER(?) OR LOWER(w.sim_number) LIKE LOWER(?) OR LOWER(w.company) LIKE LOWER(?))';
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
            "SELECT {$column} AS {$alias}, COUNT(*) AS total FROM whitelist w"
            . $this->deviceJoinSql() . $whereSql
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
            'SELECT w.supplier AS supplier, w.model AS model, COUNT(*) AS total FROM whitelist w'
            . $this->deviceJoinSql() . $whereSql
            . ' GROUP BY w.supplier, w.model ORDER BY w.supplier, w.model'
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

        return ['suppliers' => array_values($suppliers)];
    }

    private function licenseTree(array $filters, ?int $licenseScope = null, ?string $companyScope = null): array
    {
        $candidateFilters = $filters;
        unset($candidateFilters['license']);
        [$whereSql, $params] = $this->buildWhereClause($candidateFilters, $licenseScope, $companyScope);
        $stmt = $this->pdo->prepare(
            'SELECT w.company AS company, w.license_id AS license_id, COUNT(*) AS total FROM whitelist w'
            . $this->deviceJoinSql() . $whereSql
            . ' GROUP BY w.company, w.license_id ORDER BY w.company, w.license_id'
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
            // A chave ignora maiúsculas, porque a comparação que o filtro faz também as
            // ignora: com "hitcare" e "hitCare" como duas entradas, a árvore mostrava 9 numa
            // delas e clicar-lhe devolvia 10. O nome é a primeira grafia vista.
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

        return ['companies' => array_values($companies), 'none' => $none];
    }

    private function normalizeDeviceRow(array $row): array
    {
        $row['deviceType'] = DeviceMetadata::normalizeDeviceType((string)($row['deviceType'] ?? 'watch'));
        $row['licenseId'] = DeviceMetadata::normalizeLicenseId((string)($row['licenseId'] ?? '0'));
        $row['company'] = DeviceMetadata::normalizeCompany($row['company'] ?? null);
        return $row;
    }
}
