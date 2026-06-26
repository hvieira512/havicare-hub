<?php

namespace Hub\Dashboard\Repository;

use PDO;

final class GenericCapabilityRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function all(): array
    {
        return $this->pdo
            ->query('SELECT id, section, capability_key, label, sort_order, created_at, updated_at FROM generic_capabilities ORDER BY FIELD(section, \'telemetry\', \'health\', \'contacts\', \'alarms\', \'settings_system\'), sort_order, capability_key')
            ->fetchAll();
    }
}
