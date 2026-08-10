<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use Hub\Infrastructure\Persistence\ReferenceCatalogSeeder;
use PDO;

/**
 * Renames the NCS pager capability to "Chamada de ajuda".
 *
 * "Chamada de enfermagem" is the Portuguese name of the Nurse Call System
 * itself, not of the event, and the bracelet raises the same kind of call
 * without a nurse being involved. One label now covers both.
 */
final class Version2026081002UnifyHelpCallLabel implements Migration
{
    public function version(): string
    {
        return '2026081002_unify_help_call_label';
    }

    public function up(PDO $pdo): void
    {
        (new ReferenceCatalogSeeder())->syncCapabilities($pdo);
    }
}
