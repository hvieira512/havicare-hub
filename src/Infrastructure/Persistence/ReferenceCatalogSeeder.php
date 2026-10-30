<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\DeviceTypeCatalog;
use Hub\Domain\SupplierCapabilityTemplate;
use PDO;

final class ReferenceCatalogSeeder
{
    private const SUPPLIERS = ['Wonlex', 'Vivistar', '4P Touch', 'Voerka', 'Qinglanst', 'MOKO', 'MONIT', 'Zayata', 'Unique Scales', 'Havicare', 'JoyTech'];

    /** O catálogo de modelos completo, tal como a produção o tem a 2026-09-29. */
    private const MODELS = [
        ['4P Touch', 'D41', 'D41', 'watch', '9201181e4f07060bd5ded5e48ca8e20a.jpg'],
        ['4P Touch', 'D44S', 'R05', 'watch', 'be4e5160e602a993f519011e6c9f796c.jpg'],
        ['4P Touch', 'D45 Pro', 'D45 Pro', 'watch', '4a088f59242d03d7023d5e51d4da8e49.jpg'],
        ['4P Touch', 'D46', 'R04', 'watch', '1347f078cd3c213a48495d8f1a366713.jpg'],
        ['4P Touch', 'Y6L', 'Y6L', 'watch', '0d77b2f141f0dea6bde1c03b85271bc1.jpg'],
        ['4P Touch', 'Y6M', 'Y6M', 'watch', '9648d481eb3381148ea91c84aba2687c.jpg'],
        ['4P Touch', 'Y6S', 'R03', 'watch', '3d48c42e589923177a1ac3ed147758e0.jpg'],
        ['MOKO', 'MKGW-mini 03-20D', 'MOKOSmart MKGW-mini 03-20D', 'gateway', 'a8b0f419d117411508270b342869add0.jpg'],
        ['MOKO', 'MKGW3', 'MOKOSmart MKGW3', 'gateway', '45bee5a0028156faa71ff5c6c081b6d7.jpg'],
        ['MOKO', 'MKGW4', 'MOKOSmart MKGW4', 'gateway', '3bbccf9f4d8e4830480adf834cdfd278.jpg'],
        // A W6 e a W6B são a mesma pulseira, com e sem botão macio, e partilham a fotografia.
        ['MOKO', 'W6', 'MOKO W6', 'bracelet', '78888c5376784c64ca05b691c4686ecd.jpg'],
        ['MOKO', 'W6B', 'MOKO W6B', 'bracelet', '78888c5376784c64ca05b691c4686ecd.jpg'],
        ['MONIT', 'MECS-PRO', 'MONIT MECS Pro', 'diaper_sensor', 'c7a8992a69d659ef06e853f6befecd42.jpg'],
        ['Qinglanst', 'HC2', 'W01', 'radar', '2a87616691f4878b9ac4f8cfd816a615.jpg'],
        ['Vivistar', 'L08 Pro', 'R05', 'watch', '019cb6bcc40ef15ffe98a2f4ca1d2679.jpg'],
        ['Vivistar', 'VL16P', 'R04', 'watch', '45465accf3d7b8c10279225d089cf227.jpg'],
        ['Vivistar', 'VL17', 'R03', 'watch', 'c27707e761813389512c25a4050a3b85.jpg'],
        // O W812 não tem fotografia publicada pelo fornecedor.
        ['Voerka', 'W812', 'W812', 'ncs', ''],
        ['Wonlex', 'HW20PRO', 'HW20PRO', 'watch', 'eed091a62f83e3ef03c7090ce09ea262.jpg'],
        ['Wonlex', 'MF91', 'MF91', 'bracelet', '4d62bc6aac04e660f06f1de7d0ab6a4f.jpg'],
        ['Zayata', 'M228', 'M228', 'pill_dispenser', '464e9b90a30f30aee389cd9de5926977.jpg'],
        ['Unique Scales', 'HC-CF632BLE', 'HC-CF632BLE', 'scale', '50754bfc972b5d94a5af67695b01fffb.jpg'],
        // Nunca testada: não se sabe se também fala Torre.
        ['Unique Scales', 'HC-CF621BLE', 'HC-CF621BLE', 'scale', '1d024ba99d692a5fefbc8dad0dce563d.jpg'],
        ['Havicare', 'BLE Gateway', 'Gateway BLE', 'gateway', ''],
        ['JoyTech', 'HC-6292B', 'HC-6292B', 'blood_pressure_monitor', 'cbae0ad7a44f97f1d4993e551e03a28b.jpg'],
        ['JoyTech', 'HC-6679B', 'HC-6679B', 'blood_pressure_monitor', '596253ec02242209fd77291087cc18f6.jpg'],
        ['JoyTech', 'HC-3018B', 'HC-3018B', 'thermometer', '82f686a2fa076ea0bebc7e7cb7a9d676.jpg'],
        ['JoyTech', 'HC-101', 'HC-101', 'pulse_oximeter', '905f5c5c9c5d86b12794db17a4aa682b.jpg'],
    ];

    /**
     * A lista de modelos, para quem precise de a comparar com outra fonte.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function models(): array
    {
        return self::MODELS;
    }

    public function seedReferenceData(PDO $pdo): void
    {
        $this->seedDeviceTypes($pdo);
        $this->seedSuppliersAndModels($pdo);
        $this->seedCompanies($pdo);
    }

    /**
     * A `device_types` é o destino das chaves estrangeiras, e o `config/device-types.json` é
     * a origem. Vem primeiro que tudo: sem ela, nem um modelo nem uma capacidade entram.
     */
    public function seedDeviceTypes(PDO $pdo): void
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

    /**
     * Dá a cada modelo as capacidades do protocolo que ainda não tem, lacuna a lacuna. Uma
     * desligada à mão tem `enabled = 0`, e o `INSERT IGNORE` não lhe toca.
     */
    private function seedModelCapabilities(PDO $pdo): void
    {
        $models = $pdo->query('
            SELECT m.id, m.internal_model, m.device_type, s.name AS supplier_name
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
        ')->fetchAll(PDO::FETCH_ASSOC);
        $existing = $pdo->prepare('SELECT capability_key FROM model_capabilities WHERE model_id = ?');
        $insert = $pdo->prepare('
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            VALUES (?, ?, ?, 1)
        ');

        foreach ($models as $model) {
            $modelId = (int)$model['id'];
            $existing->execute([$modelId]);
            $have = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));

            $declared = array_flip(CapabilityCatalog::keysForDeviceType((string)$model['device_type']));
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
                if (isset($declared[$key])) {
                    $insert->execute([$modelId, (string)$model['device_type'], $key]);
                }
            }
        }
    }
}
