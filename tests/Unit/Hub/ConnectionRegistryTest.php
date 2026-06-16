<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\ConnectionRegistry;
use Hub\DeviceIdentity;
use Hub\ConnectionInterface;
use PHPUnit\Framework\TestCase;

final class ConnectionRegistryTest extends TestCase
{
    public function testTracksSessionLifecycle(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeHubConnection(10);

        $session = $registry->open($connection);

        self::assertFalse($session->authenticated);
        self::assertSame('websocket', $session->transport);
        self::assertFalse($registry->isOnline('865028000000306'));

        $identity = new DeviceIdentity('865028000000306', 'wonlex-json', 'DEVICE-CLAIMED-MODEL');
        $authenticated = $registry->authenticate($connection, $identity, 'Wonlex', 'HW20PRO');

        self::assertTrue($authenticated->authenticated);
        self::assertSame('Wonlex', $authenticated->supplier);
        self::assertSame('HW20PRO', $authenticated->model);
        self::assertSame($connection, $registry->connectionFor('865028000000306'));
        self::assertTrue($registry->isOnline('865028000000306'));

        $closed = $registry->close($connection);

        self::assertSame('865028000000306', $closed?->imei);
        self::assertFalse($registry->isOnline('865028000000306'));
        self::assertNull($registry->connectionFor('865028000000306'));
    }
}

final class FakeHubConnection implements ConnectionInterface
{
    public int $resourceId;
    public array $sent = [];
    public bool $closed = false;

    public function __construct(int $resourceId)
    {
        $this->resourceId = $resourceId;
    }

    public function send($data): static
    {
        $this->sent[] = $data;
        return $this;
    }

    public function close(): static
    {
        $this->closed = true;
        return $this;
    }
}
