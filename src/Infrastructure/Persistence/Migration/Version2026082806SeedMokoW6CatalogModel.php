<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Domain\SupplierCapabilityTemplate;
use PDO;

/**
 * Põe a MOKO W6 no catálogo das bases que já existem.
 *
 * O `ReferenceCatalogSeeder` já a conhece, mas só corre em base vazia -- de propósito, porque
 * o catálogo é editável e semear a cada arranque fazia voltar o que alguém apagou. Numa base
 * existente quem o faz evoluir é uma migração, e é esta.
 *
 * Sem a linha do modelo, a W6 é detetada e notificada mas não há como a registar. Sem as
 * capacidades, registra-se e fica sem cartões: a bateria e o movimento chegam e não têm onde
 * aparecer. Só toca na W6, para não ressuscitar nada que tenha sido apagado noutro modelo.
 */
final class Version2026082806SeedMokoW6CatalogModel implements Migration
{
    /** A W6 e a W6R são a mesma pulseira, com e sem botão macio, e partilham a imagem. */
    private const IMAGE = '/model-images/78888c5376784c64ca05b691c4686ecd.jpg';

    public function version(): string
    {
        return '2026082806_seed_moko_w6_catalog_model';
    }

    public function up(PDO $pdo): void
    {
        $model = $pdo->prepare('
            INSERT INTO models (supplier_id, internal_model, commercial_name, device_type, image_path)
            SELECT s.id, ?, ?, ?, ?
            FROM suppliers s WHERE s.name = ?
            ON DUPLICATE KEY UPDATE commercial_name = VALUES(commercial_name),
                device_type = VALUES(device_type), image_path = VALUES(image_path)
        ');
        $model->execute(['W6', 'MOKO W6', 'bracelet', self::IMAGE, 'MOKO']);

        $modelId = $pdo->prepare('
            SELECT m.id FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = ? AND m.internal_model = ?
        ');
        $modelId->execute(['MOKO', 'W6']);
        $id = $modelId->fetchColumn();
        if ($id === false) {
            return;
        }

        $capability = $pdo->prepare('SELECT id FROM capabilities WHERE device_type = ? AND capability_key = ?');
        $enable = $pdo->prepare('INSERT IGNORE INTO model_capabilities (model_id, capability_id, enabled) VALUES (?, ?, 1)');

        foreach (SupplierCapabilityTemplate::keysForModel('MOKO', 'W6', 'bracelet') as $key) {
            $capability->execute(['bracelet', $key]);
            $capabilityId = $capability->fetchColumn();
            if ($capabilityId !== false) {
                $enable->execute([(int)$id, (int)$capabilityId]);
            }
        }
    }
}
