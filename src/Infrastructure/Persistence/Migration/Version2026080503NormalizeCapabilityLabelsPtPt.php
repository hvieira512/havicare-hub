<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

final class Version2026080503NormalizeCapabilityLabelsPtPt implements Migration
{
    public function version(): string
    {
        return '2026080503_normalize_capability_labels_pt_pt';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->syncCapabilities($pdo);
    }
}
