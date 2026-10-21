<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Domain\DeviceTypeCatalog;
use PHPUnit\Framework\TestCase;

final class LoginConstellationTest extends TestCase
{
    /** Dois tipos no mesmo sítio desenham-se um por cima do outro. */
    public function testEveryDeviceTypeHasItsOwnSpotOnTheLoginScreen(): void
    {
        $root = dirname(__DIR__, 3) . '/src/Dashboard';
        require_once $root . '/components/helpers.php';
        ob_start();
        require $root . '/components/login.php';
        $page = (string)ob_get_clean();

        preg_match_all('/--x: ([^;]+); --y: ([^;]+);/', $page, $spots, PREG_SET_ORDER);
        $distinct = array_unique(array_map(static fn (array $spot): string => $spot[1] . '|' . $spot[2], $spots));

        $this->assertCount(count(DeviceTypeCatalog::all()), $spots);
        $this->assertCount(count($spots), $distinct);
    }
}
