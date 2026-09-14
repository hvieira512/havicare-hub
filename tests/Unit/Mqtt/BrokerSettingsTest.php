<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt;

use Hub\Mqtt\BrokerSettings;
use PHPUnit\Framework\TestCase;

final class BrokerSettingsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function hubConfig(array $overrides = []): array
    {
        return array_merge([
            'host' => 'broker.internal',
            'port' => 8883,
            'username' => 'hub',
            'password' => 'secret',
            'topic_prefix' => 'havicare-hub',
            'client_id_prefix' => 'health-mqtt',
            'keepalive' => 45,
            'timeout' => 5.0,
            'tls_enabled' => true,
            'tls_verify_peer' => false,
            'tls_ca_file' => '/etc/ssl/ca.pem',
            'tls_cert_file' => '/etc/ssl/cert.pem',
            'tls_key_file' => '/etc/ssl/key.pem',
        ], $overrides);
    }

    public function testMapsHubConfig(): void
    {
        $settings = BrokerSettings::fromHubConfig($this->hubConfig());

        self::assertSame('broker.internal', $settings->host);
        self::assertSame(8883, $settings->port);
        self::assertSame('hub', $settings->username);
        self::assertSame('secret', $settings->password);
        self::assertSame('health-mqtt', $settings->clientIdPrefix);
        self::assertSame(45, $settings->keepalive);
        self::assertTrue($settings->tlsEnabled);
        self::assertFalse($settings->tlsVerifyPeer);
        self::assertSame('/etc/ssl/ca.pem', $settings->tlsCaFile);
        self::assertSame('/etc/ssl/cert.pem', $settings->tlsCertFile);
        self::assertSame('/etc/ssl/key.pem', $settings->tlsKeyFile);
    }

    public function testRoundsFractionalTimeoutUpToWholeSeconds(): void
    {
        $settings = BrokerSettings::fromHubConfig($this->hubConfig(['timeout' => 2.1]));

        self::assertSame(3, $settings->connectTimeout);
        self::assertSame(3, $settings->socketTimeout);
    }

    public function testClampsSubSecondTimeoutToOne(): void
    {
        $settings = BrokerSettings::fromHubConfig($this->hubConfig(['timeout' => 0.2]));

        self::assertSame(1, $settings->connectTimeout);
    }

    public function testSanitizesClientIdPrefix(): void
    {
        $settings = BrokerSettings::fromHubConfig($this->hubConfig(['client_id_prefix' => 'health mqtt/hub']));

        self::assertSame('health-mqtt-hub', $settings->clientIdPrefix);
    }

    public function testFallsBackWhenClientIdPrefixIsEmpty(): void
    {
        $settings = BrokerSettings::fromHubConfig($this->hubConfig(['client_id_prefix' => '']));

        self::assertSame('havicare-hub', $settings->clientIdPrefix);
    }

    public function testRejectsEmptyHubHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MQTT_HOST is required');

        BrokerSettings::fromHubConfig($this->hubConfig(['host' => '  ']));
    }

    /** Sem secção de TLS configurada, a ligação é em texto simples -- como qualquer outra. */
    public function testQinglanstDefaultsToPlainTcpWithFixedTimeouts(): void
    {
        $settings = BrokerSettings::fromQinglanstConfig([
            'host' => 'radar.internal',
            'port' => 1883,
            'username' => ' radar ',
            'password' => ' pass ',
            'client_id_prefix' => 'qinglanst radar',
        ]);

        self::assertSame('radar.internal', $settings->host);
        self::assertSame('radar', $settings->username);
        self::assertSame('pass', $settings->password);
        self::assertSame('qinglanst-radar', $settings->clientIdPrefix);
        self::assertSame(60, $settings->keepalive);
        self::assertSame(5, $settings->connectTimeout);
        self::assertSame(5, $settings->socketTimeout);
        self::assertFalse($settings->tlsEnabled);
    }

    /**
     * O radar publica no mesmo broker que o hub -- o `QINGLANST_MQTT_HOST` e o `MQTT_HOST`
     * apontam ambos para o mesmo servidor, e o que os separa são os tópicos e as credenciais.
     *
     * Enquanto se acreditou que eram dois brokers, esta ligação nunca ter TLS lia-se como uma
     * diferença legítima entre um servidor nosso e um de terceiros. Não é: ligar o TLS no hub
     * deixava a subscrição do radar em texto simples contra o mesmo servidor, a mandar
     * utilizador e password pelo fio.
     */
    public function testQinglanstHonoursTheTlsItIsGiven(): void
    {
        $settings = BrokerSettings::fromQinglanstConfig([
            'host' => 'mqtt.example.com',
            'port' => 8883,
            'tls_enabled' => true,
            'tls_verify_peer' => true,
            'tls_ca_file' => '/etc/ssl/ca.pem',
        ]);

        self::assertTrue($settings->tlsEnabled, 'o radar partilha o broker do hub e tem de poder partilhar o TLS');
        self::assertTrue($settings->tlsVerifyPeer);
        self::assertSame('/etc/ssl/ca.pem', $settings->tlsCaFile);
    }

    public function testRejectsEmptyQinglanstHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('QINGLANST_MQTT_HOST is required');

        BrokerSettings::fromQinglanstConfig(['host' => '']);
    }
}
