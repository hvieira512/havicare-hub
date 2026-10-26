<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tcp;

use Hub\Ingress\Tcp\ConnectionRegistry;
use Hub\Ingress\Tcp\DeviceIdentity;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeConnection;

final class ConnectionRegistryTest extends TestCase
{
    public function testTracksSessionLifecycle(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeConnection(10);

        $session = $registry->open($connection);

        self::assertFalse($session->authenticated);
        self::assertNull($registry->connectionFor('865028000000306'));

        $identity = new DeviceIdentity('865028000000306', 'wonlex-json', 'DEVICE-CLAIMED-MODEL');
        $authenticated = $registry->authenticate($connection, $identity, 'Wonlex', 'HW20PRO');

        self::assertTrue($authenticated->authenticated);
        self::assertSame('Wonlex', $authenticated->supplier);
        self::assertSame('HW20PRO', $authenticated->model);
        self::assertSame($connection, $registry->connectionFor('865028000000306'));

        $closed = $registry->close($connection);

        self::assertSame('865028000000306', $closed?->imei);
        self::assertNull($registry->connectionFor('865028000000306'));
    }

    /** Um relógio celular reconecta antes de a ligação antiga fechar, e reautenticar o mesmo IMEI fecha a anterior. */
    public function testReauthenticatingAnImeiClosesThePreviousConnection(): void
    {
        $registry = new ConnectionRegistry();
        $imei = '865028000000320';

        $first = new FakeConnection(20);
        $registry->open($first);
        $registry->authenticate($first, new DeviceIdentity($imei, 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');

        $second = new FakeConnection(21);
        $registry->open($second);
        $registry->authenticate($second, new DeviceIdentity($imei, 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');

        self::assertTrue($first->closed, 'a ligação anterior fica fechada');
        self::assertSame($second, $registry->connectionFor($imei), 'o mapa aponta para a nova');
    }

    /** Uma ligação diferente, de outro IMEI, não é tocada quando um IMEI reautentica. */
    public function testReauthenticationLeavesOtherDevicesAlone(): void
    {
        $registry = new ConnectionRegistry();

        $other = new FakeConnection(40);
        $registry->open($other);
        $registry->authenticate($other, new DeviceIdentity('111122223333444', 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');

        $first = new FakeConnection(41);
        $registry->open($first);
        $registry->authenticate($first, new DeviceIdentity('865028000000322', 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');
        $second = new FakeConnection(42);
        $registry->open($second);
        $registry->authenticate($second, new DeviceIdentity('865028000000322', 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');

        self::assertFalse($other->closed, 'o outro dispositivo mantém a ligação');
        self::assertNotNull($registry->connectionFor('111122223333444'));
    }

    public function testExpiresIdleAuthenticatedConnections(): void
    {
        $now = 1_000;
        $registry = new ConnectionRegistry(static function () use (&$now): int {
            return $now;
        });
        $connection = new FakeConnection(11);
        $registry->open($connection);
        $identity = new DeviceIdentity('865028000000307', 'wonlex-json', 'DEVICE');
        $registry->authenticate($connection, $identity, 'Wonlex', 'HW20PRO');

        $now += 2;
        $expired = $registry->expireIdleConnections(static fn ($session, int $idleSeconds): bool => $idleSeconds >= 1);

        self::assertCount(1, $expired);
        self::assertSame('865028000000307', $expired[0]->imei);
        self::assertTrue($connection->closed);
        self::assertNull($registry->connectionFor('865028000000307'));
    }
}
