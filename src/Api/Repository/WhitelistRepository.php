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

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM whitelist w' . $this->deviceJoinSql() . $whereSql);
        $count->execute($params);

        return [
            'items' => $items,
            'total' => (int)$count->fetchColumn(),
            // `available` continua a ser a lista de valores que sempre foi, para não partir
            // quem já a lê; `counts` traz os mesmos valores com o número de dispositivos de
            // cada um, que é o que a coluna de filtros mostra ao lado de cada caixa.
            'available' => [
                'deviceType' => $this->distinctValues('w.device_type', 'deviceType', $filters, 'deviceType', $licenseScope, $companyScope),
                'licenseId' => $this->distinctValues('w.license_id', 'licenseId', $filters, 'licenseId', $licenseScope, $companyScope),
                'supplier' => $this->distinctValues('w.supplier', 'supplier', $filters, 'supplier', $licenseScope, $companyScope),
                'model' => $this->distinctValues('w.model', 'model', $filters, 'model', $licenseScope, $companyScope),
                'company' => $this->distinctValues('w.company', 'company', $filters, 'company', $licenseScope, $companyScope),
            ],
            'counts' => [
                'deviceType' => $this->countedValues('w.device_type', 'deviceType', $filters, 'deviceType', $licenseScope, $companyScope),
                'supplier' => $this->countedValues('w.supplier', 'supplier', $filters, 'supplier', $licenseScope, $companyScope),
                'model' => $this->countedValues('w.model', 'model', $filters, 'model', $licenseScope, $companyScope),
                'license' => $this->licenseTree($filters, $licenseScope, $companyScope),
            ],
        ];
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
        $licenseId = DeviceMetadata::normalizeLicenseId($licenseId);
        $company = DeviceMetadata::normalizeCompany($company);
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

    public function unregister(string $imei): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM whitelist WHERE imei = ?');
        $stmt->execute([$imei]);
    }

    public function updateAssociation(string $imei, string $company, int $licenseId): bool
    {
        $company = DeviceMetadata::normalizeCompany($company);
        $licenseId = DeviceMetadata::normalizeLicenseId($licenseId);
        $stmt = $this->pdo->prepare('
            UPDATE whitelist
            SET company = ?, license_id = ?
            WHERE imei = ?
        ');
        $stmt->execute([$company, $licenseId, $imei]);

        return $stmt->rowCount() > 0;
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
     * A condição da listagem.
     *
     * As chaves não são uma forma fixa: `deviceType`, `supplier` e `model` aceitam uma lista
     * ou um valor, `license` traz pares empresa-licença, e `imeiIn`/`imeiNotIn` trazem os
     * dispositivos que o estado de ligação deixa passar -- essa é presença em runtime e não
     * uma coluna, e entra aqui como lista para a paginação e as contagens continuarem certas.
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

        // O modelo passa a comparar por igualdade e não por semelhança: as opções vêm da
        // própria lista de modelos existentes, logo o que chega é um nome inteiro. O `LIKE`
        // servia uma caixa de texto que já não existe, e ao aceitar vários valores
        // significava que escolher "L08" trazia também "L08 Pro Max".
        $models = $this->filterValues($filters, 'model');
        if ($models !== []) {
            $clauses[] = 'w.model IN (' . $this->placeholders(count($models)) . ')';
            foreach ($models as $model) {
                $params[] = $model;
            }
        }

        // A licença sozinha, da forma antiga do endpoint: sem empresa não há par para formar,
        // e continua a ser a condição independente que sempre foi.
        $legacyLicenseId = trim((string)($filters['licenseId'] ?? ''));
        if ($legacyLicenseId !== '' && $legacyLicenseId !== 'all') {
            $clauses[] = 'w.license_id = ?';
            $params[] = DeviceMetadata::normalizeLicenseId($legacyLicenseId);
        }

        // A empresa e a licença são um filtro só, e o que ele escolhe são pares.
        //
        // Como duas condições independentes ligadas por "e", escolher {hitcare, haviCare} e
        // {1001, 2002} trazia também um dispositivo da hitcare com a licença 2002 -- o
        // filtro prometia uma coisa e devolvia outra. Aqui cada par é uma condição, e os
        // pares ligam-se por "ou". Um par sem licença é a empresa toda.
        $pairs = $this->licensePairs($filters);
        if ($pairs !== []) {
            $pairClauses = [];
            foreach ($pairs as $pair) {
                if ($pair['company'] === null) {
                    // Sem empresa é sem licença: uma não existe sem a outra.
                    $pairClauses[] = "(w.company = '' OR w.company IS NULL OR LOWER(w.company) = 'null')";
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
        // base de dados, por isso entra como uma lista -- mas entra nesta mesma cláusula, e
        // é isso que mantém a paginação, o total e as listas de opções certos.
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
     * Os valores de um filtro que aceita vários, seja como lista ou como valor único.
     *
     * Aceita as duas formas para que um cliente que ainda envie `deviceType=watch` continue
     * a funcionar, em vez de a listagem passar a ignorá-lo em silêncio.
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
     * Os pares empresa-licença escolhidos.
     *
     * Cada entrada é `empresa`, `empresa:licença`, ou `none` para os dispositivos sem
     * empresa nem licença. A empresa sozinha quer dizer "todas as licenças desta empresa",
     * que é o que marcar a caixa da empresa faz.
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
        return array_map(
            static fn (array $option): string => (string)$option['value'],
            $this->countedValues($column, $alias, $filters, $excludeKey, $licenseScope, $companyScope)
        );
    }

    /**
     * As opções de um filtro, cada uma com quantos dispositivos tem.
     *
     * A contagem é o que diz o que se ganha ao marcar mais uma caixa, e sai por agrupamento
     * na mesma consulta que já dava os valores distintos -- não custa um pedido a mais.
     *
     * O próprio filtro fica de fora da condição, como já ficava: marcar `hitcare` estreita a
     * lista de modelos mas mantém `haviCare` à vista, que é o que a escolha múltipla precisa.
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
     * A árvore de empresas e licenças, para o filtro que junta as duas.
     *
     * Vem em árvore e não em duas listas porque é assim que o domínio é: uma licença
     * pertence a uma empresa, e um dispositivo tem as duas ou nenhuma. Os dispositivos sem
     * empresa saem à parte, em `none`, e não como um nó com licenças por baixo -- não há
     * licença fora de uma empresa para lá pôr.
     *
     * @param array<string, mixed> $filters
     * @return array{companies: list<array{company: string, count: int, licenses: list<array{licenseId: int, count: int}>}>, none: int}
     */
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
            if ($company === '' || strtolower($company) === 'null') {
                $none += $total;
                continue;
            }

            $licenseId = DeviceMetadata::normalizeLicenseId($row['license_id'] ?? 0);
            // A chave ignora maiúsculas, porque a comparação que o filtro faz também as
            // ignora: com "hitcare" e "hitCare" como duas entradas, a árvore mostrava 9 numa
            // delas e clicar-lhe devolvia 10. Fica a primeira grafia vista como nome.
            $key = mb_strtolower($company);
            if (!isset($companies[$key])) {
                $companies[$key] = ['company' => $company, 'count' => 0, 'licenses' => []];
            }
            $companies[$key]['count'] += $total;
            // Uma licença 0 é a ausência de licença, e essa não é um nó da árvore: um
            // dispositivo com empresa e sem licença conta para a empresa e mais nada.
            if ($licenseId === 0) {
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
        return $row;
    }
}
