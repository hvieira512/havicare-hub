<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

final class Version2026072404SeedModelCapabilities implements Migration
{
    public function version(): string
    {
        return '2026072404_seed_model_capabilities';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->seedMissingModelCapabilities($pdo);
    }
}
