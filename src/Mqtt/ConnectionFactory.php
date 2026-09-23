<?php

declare(strict_types=1);

namespace Hub\Mqtt;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Contracts\Repository;
use PhpMqtt\Client\MqttClient;

/**
 * Constrói e liga os clientes MQTT de um broker.
 *
 * Não é `final` para os testes poderem substituir por uma fábrica que registe os clientes em
 * vez de abrir sockets.
 */
class ConnectionFactory
{
    public function __construct(protected readonly BrokerSettings $settings)
    {
    }

    /**
     * Um id de cliente estável não leva o pid, para o broker reconhecer a mesma sessão
     * através dos reinícios do processo -- que é o que as subscrições persistentes exigem.
     *
     * O identificador vai inteiro. Houve aqui um corte aos 23 caracteres, que é o limite do
     * MQTT 3.1; nós falamos 3.1.1, onde 23 é só o mínimo que um servidor conforme tem de
     * aceitar. O corte comprava compatibilidade com um broker estrito que não temos, e pagava
     * com o sufixo e a cauda do prefixo -- a parte escolhida para distinguir uma máquina das
     * outras. Um broker que recuse um identificador comprido recusa a ligação, e isso vê-se.
     */
    public function create(string $suffix, bool $stableClientId = false, ?Repository $repository = null): MqttClient
    {
        $clientId = $stableClientId
            ? $this->settings->clientIdPrefix . '-' . $suffix
            : $this->settings->clientIdPrefix . '-' . $suffix . '-' . getmypid();

        return new MqttClient(
            $this->settings->host,
            $this->settings->port,
            $clientId,
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
