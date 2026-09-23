<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A tampa e o ambiente de armazenamento saem do estado do dispositivo.
 *
 * O `device_status` tinha virado uma gaveta: o sinal, que muda ao minuto, ao lado da tampa
 * aberta, da corrente e do juízo que o aparelho faz sobre a temperatura e a humidade. Na
 * lista de eventos saía tudo numa linha só — «Tampa aberta: Não · Ligado à corrente: Sim ·
 * Ambiente fora da gama: Não» — com o nome de nenhuma das quatro coisas.
 *
 * Cada uma foi para onde alguém a procura. A corrente juntou-se à bateria, que é a mesma
 * pergunta feita de dois lados e já tem cartão. A tampa e o ambiente ganham capacidade
 * própria: a primeira porque uma tampa aberta é um estado sobre que se age, a segunda porque
 * «alarme de ambiente» não dizia a ninguém que o aparelho estava a avisar que a medicação
 * pode estar mal guardada.
 */
final class PillDispenserLidAndEnvironment implements Migration
{
    private const ADDED = [
        ['lid_state', 'Tampa'],
        ['storage_environment', 'Ambiente de armazenamento'],
    ];

    public function version(): string
    {
        return '2026_09_23_pill_dispenser_lid_and_environment';
    }

    public function up(PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM capabilities')->fetchColumn() === 0) {
            return;
        }

        $capability = $pdo->prepare("
            INSERT INTO capabilities (device_type, section, capability_key, label, is_configurable, is_requestable)
            VALUES ('pill_dispenser', 'telemetry', ?, ?, 0, 0)
            ON DUPLICATE KEY UPDATE section = VALUES(section), label = VALUES(label)
        ");
        $model = $pdo->prepare("
            INSERT IGNORE INTO model_capabilities (model_id, device_type, capability_key, enabled)
            SELECT m.id, 'pill_dispenser', ?, 1
            FROM models m
            JOIN suppliers s ON s.id = m.supplier_id
            WHERE s.name = 'Zayata' AND m.internal_model = 'M228'
        ");

        foreach (self::ADDED as [$key, $label]) {
            $capability->execute([$key, $label]);
            $model->execute([$key]);
        }
    }
}
