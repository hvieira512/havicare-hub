<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\ConnectionRegistry;
use Hub\Device\DeviceIdentity;
use Hub\Device\ConnectionInterface;
use PHPUnit\Framework\TestCase;

final class ConnectionRegistryTest extends TestCase
{
    public function testTracksSessionLifecycle(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeHubConnection(10);

        $session = $registry->open($connection);

        self::assertFalse($session->authenticated);
        self::assertSame('tcp', $session->transport);
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

    /**
     * O caso que os logs de produção revelaram: um relógio celular reconecta com uma ligação
     * nova antes de a antiga fechar, e a antiga fica órfã com o socket aberto até expirar por
     * inatividade. Ao reautenticar o mesmo IMEI, a ligação anterior tem de ser fechada.
     */
    public function testReauthenticatingAnImeiClosesThePreviousConnection(): void
    {
        $registry = new ConnectionRegistry();
        $imei = '865028000000320';

        $first = new FakeHubConnection(20);
        $registry->open($first);
        $registry->authenticate($first, new DeviceIdentity($imei, 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');

        $second = new FakeHubConnection(21);
        $registry->open($second);
        $registry->authenticate($second, new DeviceIdentity($imei, 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');

        self::assertTrue($first->closed, 'a ligação anterior fica fechada');
        self::assertSame($second, $registry->connectionFor($imei), 'o mapa aponta para a nova');
        self::assertTrue($registry->isOnline($imei), 'o dispositivo continua online');
    }

    /**
     * O que não pode quebrar: fechar a ligação anterior não pode arrastar a nova. A anterior já
     * não é devolvida como expirável -- foi encerrada em cima, não por inatividade --, senão o
     * `DeviceHubServer` publicava um `device.disconnected` para um IMEI que está online.
     */
    public function testTheSupersededConnectionDoesNotResurfaceAsAnExpiredSession(): void
    {
        $registry = new ConnectionRegistry();
        $imei = '865028000000321';

        $first = new FakeHubConnection(30);
        $registry->open($first);
        $registry->authenticate($first, new DeviceIdentity($imei, 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');

        $second = new FakeHubConnection(31);
        $registry->open($second);
        $registry->authenticate($second, new DeviceIdentity($imei, 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');

        // A nova mantém-se activa; só a antiga estaria inactiva -- e essa já não existe.
        sleep(2);
        $registry->touch($second);
        $expired = $registry->expireIdleConnections(1);

        self::assertSame([], $expired, 'nenhuma sessão expira: a antiga foi fechada, a nova está activa');
        self::assertTrue($registry->isOnline($imei));
        self::assertSame($second, $registry->connectionFor($imei));
    }

    /** Uma ligação diferente, de outro IMEI, não é tocada quando um IMEI reautentica. */
    public function testReauthenticationLeavesOtherDevicesAlone(): void
    {
        $registry = new ConnectionRegistry();

        $other = new FakeHubConnection(40);
        $registry->open($other);
        $registry->authenticate($other, new DeviceIdentity('111122223333444', 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');

        $first = new FakeHubConnection(41);
        $registry->open($first);
        $registry->authenticate($first, new DeviceIdentity('865028000000322', 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');
        $second = new FakeHubConnection(42);
        $registry->open($second);
        $registry->authenticate($second, new DeviceIdentity('865028000000322', 'wonlex-json', 'D'), 'Wonlex', 'HW20PRO');

        self::assertFalse($other->closed, 'o outro dispositivo mantém a ligação');
        self::assertTrue($registry->isOnline('111122223333444'));
    }

    public function testExpiresIdleAuthenticatedConnections(): void
    {
        $registry = new ConnectionRegistry();
        $connection = new FakeHubConnection(11);
        $registry->open($connection);
        $identity = new DeviceIdentity('865028000000307', 'wonlex-json', 'DEVICE');
        $registry->authenticate($connection, $identity, 'Wonlex', 'HW20PRO');

        sleep(2);
        $expired = $registry->expireIdleConnections(1);

        self::assertCount(1, $expired);
        self::assertSame('865028000000307', $expired[0]->imei);
        self::assertTrue($connection->closed);
        self::assertFalse($registry->isOnline('865028000000307'));
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

    public function remoteAddress(): ?string
    {
        return null;
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
