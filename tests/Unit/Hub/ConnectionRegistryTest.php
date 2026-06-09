<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use App\Hub\ConnectionRegistry;
use App\Hub\DeviceIdentity;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

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

        $identity = new DeviceIdentity('865028000000306', 'wonlex-json', 'WONLEX-PRO');
        $authenticated = $registry->authenticate($connection, $identity, 'WONLEX-PRO');

        self::assertTrue($authenticated->authenticated);
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

    public function send($data)
    {
        $this->sent[] = $data;
        return $this;
    }

    public function close()
    {
        $this->closed = true;
        return $this;
    }
}
