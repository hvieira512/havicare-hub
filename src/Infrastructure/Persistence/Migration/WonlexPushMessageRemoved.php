<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Domain\ProtocolRegistry;
use PDO;

/** O `wonlex-json` deixa de oferecer o `push_message`: sai dos modelos que falam esse protocolo. */
final class WonlexPushMessageRemoved implements Migration
{
    public function version(): string
    {
        return '2026_10_22_wonlex_push_message_removed';
    }

    public function up(PDO $pdo): void
    {
        $models = $pdo->query('
            SELECT m.id, m.internal_model, s.name AS supplier_name
            FROM models m JOIN suppliers s ON s.id = m.supplier_id
        ')->fetchAll(PDO::FETCH_ASSOC);
        $delete = $pdo->prepare("DELETE FROM model_capabilities WHERE capability_key = 'push_message' AND model_id = ?");
        foreach ($models as $model) {
            if (ProtocolRegistry::forModel((string)$model['supplier_name'], (string)$model['internal_model']) === 'wonlex-json') {
                $delete->execute([(int)$model['id']]);
            }
        }
    }
}
