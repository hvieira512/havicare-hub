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

    /**
     * O identificador chega ao broker inteiro, por mais comprido que seja.
     *
     * Havia aqui um corte aos 23 caracteres, que é o que o MQTT 3.1 exigia. Nós falamos
     * 3.1.1, onde 23 é apenas o mínimo que um servidor conforme tem de aceitar, e o mosquitto
     * aceita muito mais. O corte só garantia compatibilidade com um broker estrito que não
     * temos, e em troca comia o sufixo e a cauda do prefixo -- justamente a parte escolhida
     * para distinguir uma máquina das outras. Duas configurações que se queriam diferentes
     * apresentavam-se ao broker com o mesmo nome e expulsavam-se uma à outra em ciclo, com o
     * registo dos dois lados a dizer só «connection lost».
     *
     * Um broker que recuse um identificador comprido recusa a ligação, e isso já se vê.
     */
    public function testALongClientIdIsSentWhole(): void
    {
        $client = $this->factory('a-very-long-client-prefix')->create('subscriber', true);

        self::assertSame('a-very-long-client-prefix-subscriber', $client->getClientId());
    }

    /** E o `-hugo` de um prefixo local, que era o primeiro a desaparecer, sobrevive. */
    public function testThePartOfThePrefixThatDistinguishesAMachineSurvives(): void
    {
        $hugo = $this->factory('qinglanst-radar-local-hugo')->create('sub', true);
        $maria = $this->factory('qinglanst-radar-local-maria')->create('sub', true);

        self::assertSame('qinglanst-radar-local-hugo-sub', $hugo->getClientId());
        self::assertNotSame($hugo->getClientId(), $maria->getClientId());
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
