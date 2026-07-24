<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

final class Version2026072402SeedReferenceCatalog implements Migration
{
    public function version(): string
    {
        return '2026072402_seed_reference_catalog';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->seedReferenceData($pdo);
    }
}
