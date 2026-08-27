<?php

declare(strict_types=1);

namespace Hub\Mqtt;

/**
 * Os parâmetros de ligação de um broker MQTT.
 *
 * O hub fala com dois: o seu (com TLS, configurado pelas `MQTT_*`) e o broker de radares da
 * Qinglanst (TCP simples, tempos fixos). Os dois são descritos por este objecto, para o
 * código de ligação ter uma fonte só.
 */
final class BrokerSettings
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $clientIdPrefix,
        public readonly int $keepalive,
        public readonly int $connectTimeout,
        public readonly int $socketTimeout,
        public readonly bool $tlsEnabled = false,
        public readonly bool $tlsVerifyPeer = true,
        public readonly string $tlsCaFile = '',
        public readonly string $tlsCertFile = '',
        public readonly string $tlsKeyFile = '',
    ) {
        if (trim($this->host) === '') {
            throw new \InvalidArgumentException('MQTT broker host is required');
        }
    }

    /**
     * @param array<string, mixed> $mqttConfig the `mqtt` section of the hub config
     */
    public static function fromHubConfig(array $mqttConfig): self
    {
        $host = trim((string)($mqttConfig['host'] ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('MQTT_HOST is required for the devices hub');
        }

        $timeout = max(1, (int)ceil((float)($mqttConfig['timeout'] ?? 5.0)));

        return new self(
            $host,
            (int)($mqttConfig['port'] ?? 1883),
            (string)($mqttConfig['username'] ?? ''),
            (string)($mqttConfig['password'] ?? ''),
            self::sanitizeClientIdPrefix((string)($mqttConfig['client_id_prefix'] ?? ''), 'havicare-hub'),
            max(1, (int)($mqttConfig['keepalive'] ?? 60)),
            $timeout,
            $timeout,
            (bool)($mqttConfig['tls_enabled'] ?? false),
            (bool)($mqttConfig['tls_verify_peer'] ?? true),
            (string)($mqttConfig['tls_ca_file'] ?? ''),
            (string)($mqttConfig['tls_cert_file'] ?? ''),
            (string)($mqttConfig['tls_key_file'] ?? ''),
        );
    }

    /**
     * O broker dos radares é TCP simples, com keepalive e tempos fixos. Num construtor
     * nomeado, isso fica uma diferença de configuração em vez de uma segunda cópia do código
     * de ligação.
     *
     * @param array<string, mixed> $qinglanstConfig the `qinglanst` section of the hub config
     */
    public static function fromQinglanstConfig(array $qinglanstConfig): self
    {
        $host = trim((string)($qinglanstConfig['host'] ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('QINGLANST_MQTT_HOST is required when QINGLANST_ENABLED=true');
        }

        return new self(
            $host,
            (int)($qinglanstConfig['port'] ?? 1883),
            trim((string)($qinglanstConfig['username'] ?? '')),
            trim((string)($qinglanstConfig['password'] ?? '')),
            self::sanitizeClientIdPrefix((string)($qinglanstConfig['client_id_prefix'] ?? ''), 'qinglanst-radar'),
            60,
            5,
            5,
        );
    }

    private static function sanitizeClientIdPrefix(string $prefix, string $fallback): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '-', $prefix) ?: $fallback;
    }
}
