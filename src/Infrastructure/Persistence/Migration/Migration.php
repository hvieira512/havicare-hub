<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

interface Migration
{
    public function version(): string;

    public function up(PDO $pdo): void;
}
