<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Api\Request\DeviceAssociationRequest;
use Tests\Support\DevicesApiFixture;
use Tests\Support\MysqlDashboardTestCase;

/** Um dispositivo sem dono sai com `null` na empresa e na licença, e não com `'null'` e `0`. */
final class DeviceWithoutOwnerApiTest extends MysqlDashboardTestCase
{
    use DevicesApiFixture;

    private const IMEI = '861265061009822';

    public function testTheDetailSaysNull(): void
    {
        [$api] = $this->makeApi();

        $device = $api->show(self::IMEI, null, '')['device'] ?? [];

        self::assertArrayHasKey('company', $device);
        self::assertNull($device['company']);
        self::assertArrayHasKey('licenseId', $device);
        self::assertNull($device['licenseId']);
    }

    public function testTheListSaysNull(): void
    {
        [$api] = $this->makeApi();

        $items = array_column($api->list('page=1&limit=10', null, '')['data'] ?? [], null, 'imei');

        self::assertArrayHasKey('company', $items[self::IMEI]);
        self::assertNull($items[self::IMEI]['company']);
        self::assertArrayHasKey('licenseId', $items[self::IMEI]);
        self::assertNull($items[self::IMEI]['licenseId']);
    }

    public function testRemovingTheOwnerSaysNull(): void
    {
        [$api] = $this->makeApi();
        self::assertSame('ok', $api->associations->associate(self::IMEI, new DeviceAssociationRequest('hitcare', 1001))['status'] ?? null);

        $removed = $api->associations->remove(self::IMEI);

        self::assertSame(['company' => null, 'licenseId' => null], $removed['association'] ?? null);
    }
}
