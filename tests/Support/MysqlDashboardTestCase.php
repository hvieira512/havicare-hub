<?php

declare(strict_types=1);

namespace Tests\Support;

use Hub\Infrastructure\Persistence\DashboardDatabase;
use Hub\Infrastructure\Persistence\DatabaseMigrator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

abstract class MysqlDashboardTestCase extends TestCase
{
    /**
     * Replaying every migration per test costs ~820ms and dominates the suite,
     * so the schema is built once per process into a template database and each
     * test clones it instead (~180ms).
     */
    private static ?string $templateDatabaseName = null;

    /** @var list<string>|null */
    private static ?array $templateTableNames = null;

    /** @var list<string> */
    private array $temporaryDatabaseNames = [];

    private ?PDO $adminPdo = null;

    /** @var array{host: string, port: int, username: string, password: string, charset: string} */
    private array $adminConfig;

    protected function createDashboardDatabase(): DashboardDatabase
    {
        $databaseName = $this->createEmptyDatabase();
        $this->cloneTemplateInto($databaseName);

        return new DashboardDatabase($this->dashboardDatabaseConfig($databaseName));
    }

    /**
     * Builds the migrated template once and copies it into a fresh database.
     *
     * The clone is a plain structure + data copy, so a test still gets its own
     * isolated database and can run DDL or open extra connections against it.
     */
    private function cloneTemplateInto(string $databaseName): void
    {
        $admin = $this->adminPdo();

        if (self::$templateDatabaseName === null) {
            $templateName = 'hub_test_tpl_' . bin2hex(random_bytes(6));
            $config = $this->mysqlAdminConfig();
            $admin->exec(sprintf(
                'CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s',
                $templateName,
                $config['charset'],
                $config['charset'] . '_unicode_ci'
            ));

            $template = new DashboardDatabase($this->dashboardDatabaseConfig($templateName));
            (new DatabaseMigrator($template->pdo()))->migrate();

            self::$templateDatabaseName = $templateName;
            self::$templateTableNames = $template->pdo()
                ->query(sprintf(
                    "SELECT table_name FROM information_schema.tables WHERE table_schema = '%s'",
                    $templateName
                ))
                ->fetchAll(PDO::FETCH_COLUMN);

            // The template outlives every test, so drop it when the process ends
            // rather than in tearDown.
            register_shutdown_function(static function () use ($config, $templateName): void {
                try {
                    (new PDO(
                        sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $config['port'], $config['charset']),
                        $config['username'],
                        $config['password']
                    ))->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $templateName));
                } catch (\Throwable) {
                }
            });
        }

        // CREATE TABLE ... LIKE drops foreign keys, and gateway_device_links
        // depends on ON DELETE CASCADE, so replay the real DDL instead.
        $admin->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $admin->exec(sprintf('USE `%s`', $databaseName));
            foreach (self::$templateTableNames ?? [] as $table) {
                $ddl = (string)$admin
                    ->query(sprintf('SHOW CREATE TABLE `%s`.`%s`', self::$templateDatabaseName, $table))
                    ->fetch(PDO::FETCH_NUM)[1];
                $admin->exec($ddl);
                $admin->exec(sprintf(
                    'INSERT INTO `%s` SELECT * FROM `%s`.`%s`',
                    $table,
                    self::$templateDatabaseName,
                    $table
                ));
            }
        } finally {
            $admin->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    protected function reopenDashboardDatabase(string $databaseName): DashboardDatabase
    {
        $database = new DashboardDatabase($this->dashboardDatabaseConfig($databaseName));
        (new DatabaseMigrator($database->pdo()))->migrate();
        return $database;
    }

    protected function createEmptyDatabase(): string
    {
        $config = $this->mysqlAdminConfig();
        $databaseName = 'hub_test_' . bin2hex(random_bytes(6));
        $this->adminPdo()->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s',
            $databaseName,
            $config['charset'],
            $config['charset'] . '_unicode_ci'
        ));
        $this->temporaryDatabaseNames[] = $databaseName;
        return $databaseName;
    }

    protected function databaseName(PDO $pdo): string
    {
        return (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    }

    /**
     * @return array{driver: string, host: string, port: int, name: string, username: string, password: string, charset: string}
     */
    protected function dashboardDatabaseConfig(string $databaseName): array
    {
        $config = $this->mysqlAdminConfig();
        return [
            'driver' => 'mysql',
            'host' => $config['host'],
            'port' => $config['port'],
            'name' => $databaseName,
            'username' => $config['username'],
            'password' => $config['password'],
            'charset' => $config['charset'],
        ];
    }

    protected function pdoForDatabase(string $databaseName): PDO
    {
        $config = $this->dashboardDatabaseConfig($databaseName);
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['name'],
                $config['charset']
            ),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDatabaseNames) as $databaseName) {
            try {
                if ($this->adminPdo instanceof PDO) {
                    $this->adminPdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $databaseName));
                }
            } catch (\Throwable $e) {
            }
        }

        $this->temporaryDatabaseNames = [];
        $this->adminPdo = null;
        parent::tearDown();
    }

    private function adminPdo(): PDO
    {
        if ($this->adminPdo instanceof PDO) {
            return $this->adminPdo;
        }

        $config = $this->mysqlAdminConfig();

        try {
            $this->adminPdo = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $config['port'], $config['charset']),
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('MySQL-backed dashboard tests require an accessible MySQL server: ' . $e->getMessage());
        }

        return $this->adminPdo;
    }

    /**
     * A estrutura de uma base de dados, para se poder comparar duas.
     *
     * Vive aqui e não num teste porque há mais do que uma pergunta a fazer com ela: se o
     * `schema.sql` sozinho descreve a base actual, e se uma base legada actualizada fica
     * igual a uma nova. As duas comparam estrutura e nenhuma quer saber dos dados.
     *
     * @return list<string>
     */
    protected function tableNames(PDO $pdo): array
    {
        $tables = $pdo->query('
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'
            ORDER BY TABLE_NAME
        ');

        return array_map('strval', $tables === false ? [] : $tables->fetchAll(PDO::FETCH_COLUMN));
    }

    protected function columnStructure(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('
            SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ');
        $stmt->execute([$table]);
        return $stmt->fetchAll();
    }

    /**
     * Os índices pela forma e não pelo nome.
     *
     * Uma chave única em `(a, b)` é a mesma restrição venha ela do `schema.sql` com um
     * nome escolhido ou de um `ALTER TABLE` que deixou o MySQL nomeá-la. Comparar nomes
     * dava diferenças que não são diferenças.
     */
    protected function indexStructure(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('
            SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ');
        $stmt->execute([$table]);
        $indexes = [];
        foreach ($stmt->fetchAll() as $row) {
            $name = (string)$row['INDEX_NAME'];
            $indexes[$name]['unique'] = (int)$row['NON_UNIQUE'] === 0;
            $indexes[$name]['columns'][] = (string)$row['COLUMN_NAME'];
        }

        $normalized = [];
        foreach ($indexes as $name => $index) {
            $key = $name === 'PRIMARY'
                ? 'PRIMARY'
                : (($index['unique'] ? 'UNIQUE:' : 'INDEX:') . implode(',', $index['columns']));
            $normalized[$key] = $index;
        }
        ksort($normalized);
        return $normalized;
    }

    protected function foreignKeyStructure(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('
            SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        ');
        $stmt->execute([$table]);
        return $stmt->fetchAll();
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, charset: string}
     */
    private function mysqlAdminConfig(): array
    {
        if (isset($this->adminConfig)) {
            return $this->adminConfig;
        }

        $this->adminConfig = [
            'host' => (string)(getenv('TEST_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('TEST_DB_PORT') ?: getenv('DB_PORT') ?: 3306),
            'username' => (string)(getenv('TEST_DB_ADMIN_USER') ?: getenv('DB_ROOT_USER') ?: 'root'),
            'password' => (string)(getenv('TEST_DB_ADMIN_PASSWORD') ?: getenv('DB_ROOT_PASSWORD') ?: 'root_pass'),
            'charset' => (string)(getenv('TEST_DB_CHARSET') ?: getenv('DB_CHARSET') ?: 'utf8mb4'),
        ];

        return $this->adminConfig;
    }
}
