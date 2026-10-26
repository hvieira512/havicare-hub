<?php

declare(strict_types=1);

namespace Tests\Support;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Infrastructure\Persistence\Repository\WhitelistRepository;
use PDO;

/** Regista um dispositivo de teste, criando a empresa e a licença que ele nomeia se ainda não existirem. */
final class TestInventory
{
    public static function register(
        PDO $pdo,
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int $licenseId = 0,
        string $company = 'null',
        string $simNumber = '',
        string $deviceId = '',
    ): void {
        if ($licenseId > 0 && $company !== 'null' && $company !== '') {
            self::ensureLicense($pdo, $company, $licenseId);
        }
        (new WhitelistRepository($pdo))->register($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
    }

    /** O mesmo, pelos repositórios, com os argumentos pela ordem do `WhitelistRepository::register()`. */
    public static function registerVia(
        ApiDataAccess $db,
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int|string $licenseId = 0,
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null',
    ): void {
        if ((int)$licenseId > 0 && $company !== 'null' && $company !== '') {
            $db->licenses->create($db->companies->create($company), (int)$licenseId, "{$company}-{$licenseId}");
        }
        $db->whitelist->register($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
    }

    public static function ensureLicense(PDO $pdo, string $company, int $licenseId): int
    {
        $pdo->prepare('INSERT IGNORE INTO companies (name) VALUES (?)')->execute([$company]);
        $pdo->prepare('
            INSERT IGNORE INTO licenses (company_id, license_id, name)
            SELECT id, ?, ? FROM companies WHERE name = ?
        ')->execute([$licenseId, "{$company}-{$licenseId}", $company]);
        $stmt = $pdo->prepare('SELECT l.id FROM licenses l JOIN companies c ON c.id = l.company_id WHERE c.name = ? AND l.license_id = ?');
        $stmt->execute([$company, $licenseId]);

        return (int)$stmt->fetchColumn();
    }
}
