<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    private function factory(string $prefix = 'hub', bool $verifyPeer = true): ConnectionFactory
    {
        return new ConnectionFactory(new BrokerSettings(
            'broker.internal',
            1883,
            'user',
            'pass',
            $prefix,
            keepalive: 30,
            connectTimeout: 5,
            socketTimeout: 5,
            tlsEnabled: true,
            tlsVerifyPeer: $verifyPeer,
            tlsCaFile: '/etc/ssl/ca.pem',
        ));
    }

    public function testStableClientIdOmitsThePid(): void
    {
        $client = $this->factory()->create('sub', true);

        self::assertSame('hub-sub', $client->getClientId());
        self::assertSame('broker.internal', $client->getHost());
        self::assertSame(1883, $client->getPort());
    }

    public function testNonStableClientIdIncludesThePid(): void
    {
        $client = $this->factory()->create('pub');

        self::assertSame('hub-pub-' . getmypid(), $client->getClientId());
    }

    public function testClientIdIsTruncatedToTheMqttLimit(): void
    {
        $client = $this->factory('a-very-long-client-prefix')->create('subscriber', true);

        self::assertSame(23, strlen($client->getClientId()));
        self::assertSame('a-very-long-client-pref', $client->getClientId());
    }

    public function testConnectionSettingsCarryCredentialsAndTls(): void
    {
        $settings = $this->factory()->connectionSettings();

        self::assertSame('user', $settings->getUsername());
        self::assertSame('pass', $settings->getPassword());
        self::assertSame(30, $settings->getKeepAliveInterval());
        self::assertTrue($settings->shouldUseTls());
        self::assertTrue($settings->shouldTlsVerifyPeer());
        self::assertFalse($settings->isTlsSelfSignedAllowed());
        self::assertSame('/etc/ssl/ca.pem', $settings->getTlsCertificateAuthorityFile());
    }

    public function testDisablingPeerVerificationAllowsSelfSignedCertificates(): void
    {
        $settings = $this->factory(verifyPeer: false)->connectionSettings();

        self::assertFalse($settings->shouldTlsVerifyPeer());
        self::assertTrue($settings->isTlsSelfSignedAllowed());
    }

    public function testBlankCredentialsBecomeNull(): void
    {
        $factory = new ConnectionFactory(new BrokerSettings(
            'broker.internal',
            1883,
            '',
            '',
            'hub',
            keepalive: 30,
            connectTimeout: 5,
            socketTimeout: 5,
        ));

        $settings = $factory->connectionSettings();

        self::assertNull($settings->getUsername());
        self::assertNull($settings->getPassword());
        self::assertNull($settings->getTlsCertificateAuthorityFile());
    }
}
