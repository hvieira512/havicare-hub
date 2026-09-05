<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\DeviceTypeCatalog;
use Hub\Domain\SupplierCapabilityTemplate;
use PDO;

final class ReferenceCatalogSeeder
{
    private const SUPPLIERS = ['Wonlex', 'Vivistar', '4P Touch', 'Voerka', 'Qinglanst', 'MOKO', 'MONIT'];

    private const MODELS = [
        ['Wonlex', 'HW20PRO', 'HW20PRO', 'watch', ''],
        ['Vivistar', 'L08 Pro', 'L08 Pro', 'watch', ''],
        ['4P Touch', 'D46', 'D46', 'watch', ''],
        ['Voerka', 'W812', 'W812', 'ncs', ''],
        ['Qinglanst', 'RD-V1', 'RD-V1', 'radar', ''],
        ['MOKO', 'MKGW3', 'MOKOSmart MKGW3', 'gateway', ''],
        ['MOKO', 'MKGW4', 'MOKOSmart MKGW4', 'gateway', ''],
        ['MOKO', 'MKGW-mini 03-20D', 'MOKOSmart MKGW-mini 03-20D', 'gateway', ''],
        ['MONIT', 'MECS-PRO', 'MONIT MECS Pro', 'diaper_sensor', ''],
        ['MOKO', 'W6B', 'MOKO W6B', 'bracelet', ''],
        ['MOKO', 'W6', 'MOKO W6', 'bracelet', ''],
    ];

    public function seedReferenceData(PDO $pdo): void
    {
        $this->seedDeviceTypes($pdo);
        $this->seedSuppliersAndModels($pdo);
        $this->seedCompanies($pdo);
        $this->seedCapabilities($pdo);
    }

    /**
     * A `device_types` é o destino das chaves estrangeiras, e o `config/device-types.json` é
     * a origem. Vem primeiro que tudo: sem ela, nem um modelo nem uma capacidade entram.
     */
    private function seedDeviceTypes(PDO $pdo): void
    {
        $insert = $pdo->prepare('INSERT IGNORE INTO device_types (device_type) VALUES (?)');
        foreach (DeviceTypeCatalog::keys() as $deviceType) {
            $insert->execute([$deviceType]);
        }
    }

    public function seedMissingModelCapabilities(PDO $pdo): void
    {
        $this->seedModelCapabilities($pdo);
    }

    private function seedSuppliersAndModels(PDO $pdo): void
    {
        $supplier = $pdo->prepare('INSERT IGNORE INTO suppliers (name) VALUES (?)');
        foreach (self::SUPPLIERS as $name) {
            $supplier->execute([$name]);
        }

        $nameToId = $pdo
            ->query("SELECT name, id FROM suppliers WHERE name IN ('" . implode("','", self::SUPPLIERS) . "')")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $model = $pdo->prepare('
            INSERT IGNORE INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            VALUES (?, ?, ?, ?, ?)
        ');
        foreach (self::MODELS as [$supplierName, $internal, $commercial, $type, $image]) {
            $supplierId = (int)($nameToId[$supplierName] ?? 0);
            if ($supplierId > 0) {
                $model->execute([$supplierId, $internal, $commercial, $type, $image]);
            }
        }
    }

    private function seedCompanies(PDO $pdo): void
    {
        $company = $pdo->prepare('INSERT IGNORE INTO companies (name) VALUES (?)');
        foreach (['hitcare', 'havicare'] as $name) {
            $company->execute([$name]);
        }

        $stmt = $pdo->prepare('SELECT id FROM companies WHERE name = ?');
        $stmt->execute(['hitcare']);
        $companyId = (int)($stmt->fetchColumn() ?: 0);
        if ($companyId > 0) {
            $license = $pdo->prepare('
                INSERT IGNORE INTO licenses (company_id, license_id, name)
                VALUES (?, 1001, ?)
            ');
            $license->execute([$companyId, 'gucc.dev']);
        }
    }

    private function seedCapabilities(PDO $pdo): void
    {
        $insert = $pdo->prepare('
            INSERT INTO capabilities (
                device_type, section, capability_key, label,
                is_configurable, is_requestable
            )
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                section = VALUES(section),
                label = VALUES(label),
                is_configurable = VALUES(is_configurable),
                is_requestable = VALUES(is_requestable)
        ');
        foreach (CapabilityCatalog::definitions() as $definition) {
            $insert->execute([
                (string)$definition['deviceType'],
                (string)$definition['section'],
                (string)$definition['key'],
                (string)$definition['label'],
                !empty($definition['isConfigurable']) ? 1 : 0,
                !empty($definition['isRequestable']) ? 1 : 0,
            ]);
        }
    }

    /**
     * Dá a cada modelo as capacidades que o protocolo suporta e que ainda não tem. Lacuna a
     * lacuna e não modelo a modelo, senão uma capacidade nova nunca chegava aos já semeados.
     *
     * Só insere o que falta: uma desligada à mão tem `enabled = 0` e não uma linha ausente,
     * e o `INSERT IGNORE` não lhe toca. Preenche buracos, não liga nada.
     */
    private function seedModelCapabilities(PDO $pdo): void
    {
        $models = $pdo->query('
            SELECT m.id, m.internal_model, m.device_type, s.name AS supplier_name
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
        ')->fetchAll(PDO::FETCH_ASSOC);
        $existing = $pdo->prepare('SELECT capability_key FROM model_capabilities WHERE model_id = ?');
        // A capacidade tem de existir no catálogo: a chave estrangeira recusa o resto.
        $capability = $pdo->prepare('
            SELECT COUNT(*) FROM capabilities WHERE device_type = ? AND capability_key = ?
        ');
        $insert = $pdo->prepare('
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            VALUES (?, ?, ?, 1)
        ');

        foreach ($models as $model) {
            $modelId = (int)$model['id'];
            $existing->execute([$modelId]);
            $have = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));

            foreach (
                SupplierCapabilityTemplate::keysForModel(
                    (string)$model['supplier_name'],
                    (string)$model['internal_model'],
                    (string)$model['device_type']
                ) as $key
            ) {
                if (isset($have[$key])) {
                    continue;
                }
                $capability->execute([(string)$model['device_type'], $key]);
                if ((int)$capability->fetchColumn() > 0) {
                    $insert->execute([$modelId, (string)$model['device_type'], $key]);
                }
            }
        }
    }
}
