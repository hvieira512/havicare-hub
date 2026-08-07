<?php

declare(strict_types=1);

namespace Hub\Mqtt;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Contracts\Repository;
use PhpMqtt\Client\MqttClient;

/**
 * Builds and connects MQTT clients for one broker.
 *
 * Not final so tests can substitute a factory that records clients instead of
 * opening sockets.
 */
class ConnectionFactory
{
    private const MAX_CLIENT_ID_LENGTH = 23;

    public function __construct(protected readonly BrokerSettings $settings)
    {
    }

    public function settings(): BrokerSettings
    {
        return $this->settings;
    }

    /**
     * A stable client id omits the pid, so the broker recognises the same
     * session across process restarts (required for persistent subscriptions).
     */
    public function create(string $suffix, bool $stableClientId = false, ?Repository $repository = null): MqttClient
    {
        $clientId = $stableClientId
            ? $this->settings->clientIdPrefix . '-' . $suffix
            : $this->settings->clientIdPrefix . '-' . $suffix . '-' . getmypid();

        return new MqttClient(
            $this->settings->host,
            $this->settings->port,
            substr($clientId, 0, self::MAX_CLIENT_ID_LENGTH),
            MqttClient::MQTT_3_1_1,
            $repository,
        );
    }

    public function connect(MqttClient $client, bool $cleanSession = true): MqttClient
    {
        $client->connect($this->connectionSettings(), $cleanSession);

        return $client;
    }

    public function build(string $suffix, bool $cleanSession = true, bool $stableClientId = false): MqttClient
    {
        return $this->connect($this->create($suffix, $stableClientId), $cleanSession);
    }

    public function connectionSettings(): ConnectionSettings
    {
        return (new ConnectionSettings())
            ->setUsername($this->settings->username !== '' ? $this->settings->username : null)
            ->setPassword($this->settings->password !== '' ? $this->settings->password : null)
            ->setKeepAliveInterval($this->settings->keepalive)
            ->setConnectTimeout($this->settings->connectTimeout)
            ->setSocketTimeout($this->settings->socketTimeout)
            ->setUseTls($this->settings->tlsEnabled)
            ->setTlsVerifyPeer($this->settings->tlsVerifyPeer)
            ->setTlsVerifyPeerName($this->settings->tlsVerifyPeer)
            ->setTlsSelfSignedAllowed(!$this->settings->tlsVerifyPeer)
            ->setTlsCertificateAuthorityFile($this->settings->tlsCaFile !== '' ? $this->settings->tlsCaFile : null)
            ->setTlsClientCertificateFile($this->settings->tlsCertFile !== '' ? $this->settings->tlsCertFile : null)
            ->setTlsClientCertificateKeyFile($this->settings->tlsKeyFile !== '' ? $this->settings->tlsKeyFile : null);
    }
}
