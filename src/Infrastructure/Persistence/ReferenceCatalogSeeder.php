<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Domain\Capability\CapabilityCatalog;
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
        ['MONIT', 'MECS-PRO', 'MONIT MECS Pro', 'diaper_sensor', ''],
        ['MOKO', 'W6R', 'MOKO W6R', 'bracelet', ''],
    ];

    private const SUPPLIER_DEVICE_TYPES = [
        ['Wonlex', 'watch'],
        ['Vivistar', 'watch'],
        ['4P Touch', 'watch'],
        ['Voerka', 'ncs'],
        ['Qinglanst', 'radar'],
        ['MOKO', 'gateway'],
        ['MONIT', 'diaper_sensor'],
        ['MOKO', 'bracelet'],
    ];

    public function seedReferenceData(PDO $pdo): void
    {
        $this->seedSuppliersAndModels($pdo);
        $this->seedCompanies($pdo);
        $this->seedSupplierDeviceTypes($pdo);
        $this->seedCapabilities($pdo);
    }

    public function seedMissingModelCapabilities(PDO $pdo): void
    {
        $this->seedModelCapabilities($pdo);
    }

    public function syncCapabilities(PDO $pdo): void
    {
        $this->seedCapabilities($pdo);
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

    private function seedSupplierDeviceTypes(PDO $pdo): void
    {
        $nameToId = $pdo
            ->query("SELECT name, id FROM suppliers WHERE name IN ('" . implode("','", self::SUPPLIERS) . "')")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $insert = $pdo->prepare('INSERT IGNORE INTO supplier_device_types (supplier_id, device_type) VALUES (?, ?)');
        foreach (self::SUPPLIER_DEVICE_TYPES as [$supplierName, $deviceType]) {
            $supplierId = (int)($nameToId[$supplierName] ?? 0);
            if ($supplierId > 0) {
                $insert->execute([$supplierId, $deviceType]);
            }
        }
    }

    private function seedCapabilities(PDO $pdo): void
    {
        $insert = $pdo->prepare('
            INSERT INTO capabilities (
                device_type, section, capability_key, label,
                is_telemetry, is_configurable, is_requestable, sort_order
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                section = VALUES(section),
                label = VALUES(label),
                is_telemetry = VALUES(is_telemetry),
                is_configurable = VALUES(is_configurable),
                is_requestable = VALUES(is_requestable),
                sort_order = VALUES(sort_order)
        ');
        foreach (CapabilityCatalog::definitions() as $definition) {
            $insert->execute([
                (string)$definition['deviceType'],
                (string)$definition['section'],
                (string)$definition['key'],
                (string)$definition['label'],
                !empty($definition['isTelemetry']) ? 1 : 0,
                !empty($definition['isConfigurable']) ? 1 : 0,
                !empty($definition['isRequestable']) ? 1 : 0,
                (int)$definition['sortOrder'],
            ]);
        }
    }

    /**
     * Dá a cada modelo as capacidades que o protocolo dele suporta e que ainda não tem.
     *
     * Preenche lacuna a lacuna e não modelo a modelo. Saltar os modelos que já tinham
     * linhas -- que era o que isto fazia -- deixava uma capacidade acrescentada ao catálogo
     * depois da semeadura nunca chegar aos modelos já semeados: o aparelho suporta
     * claramente a coisa e a API recusa-se a configurá-la porque a matriz diz que o modelo
     * não a tem. Era preciso escrever uma migração de cada vez que isso acontecia.
     *
     * Só insere o que falta. Uma capacidade desligada à mão fica com a linha dela e
     * continua desligada, porque o `setEnabledCapabilities` põe `enabled = 0` em vez de
     * apagar a linha, e o `INSERT IGNORE` não lhe toca. Isto preenche buracos; não volta a
     * ligar nada.
     */
    private function seedModelCapabilities(PDO $pdo): void
    {
        $models = $pdo->query('
            SELECT m.id, m.internal_model, m.device_type, s.name AS supplier_name
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
        ')->fetchAll(PDO::FETCH_ASSOC);
        $existing = $pdo->prepare('
            SELECT c.capability_key
            FROM model_capabilities mc
            JOIN capabilities c ON c.id = mc.capability_id
            WHERE mc.model_id = ?
        ');
        $capability = $pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $insert = $pdo->prepare('INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled) VALUES (?, ?, 1)');

        foreach ($models as $model) {
            $modelId = (int)$model['id'];
            $existing->execute([$modelId]);
            $have = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));

            foreach (SupplierCapabilityTemplate::keysForModel(
                (string)$model['supplier_name'],
                (string)$model['internal_model'],
                (string)$model['device_type']
            ) as $key) {
                if (isset($have[$key])) {
                    continue;
                }
                $capability->execute([(string)$model['device_type'], $key]);
                $capabilityId = (int)($capability->fetchColumn() ?: 0);
                if ($capabilityId > 0) {
                    $insert->execute([$modelId, $capabilityId]);
                }
            }
        }
    }
}
