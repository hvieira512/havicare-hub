<?php

declare(strict_types=1);

namespace Hub\Mqtt;

/**
 * Os parâmetros de ligação de um broker MQTT. O hub abre duas sessões no mesmo broker, a sua
 * (`MQTT_*`) e a dos radares Qinglanst, e por isso partilham a postura de TLS por omissão.
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
     * @param array<string, mixed> $mqttConfig a secção `mqtt` da configuração do hub
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
     * A ligação de ingestão dos radares: keepalive e tempos fixos, e o resto da configuração. O
     * TLS não está preso a `false` porque o radar publica no mesmo broker que o hub.
     *
     * @param array<string, mixed> $qinglanstConfig a secção `qinglanst` da configuração do hub
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
            (bool)($qinglanstConfig['tls_enabled'] ?? false),
            (bool)($qinglanstConfig['tls_verify_peer'] ?? true),
            (string)($qinglanstConfig['tls_ca_file'] ?? ''),
            (string)($qinglanstConfig['tls_cert_file'] ?? ''),
            (string)($qinglanstConfig['tls_key_file'] ?? ''),
        );
    }

    private static function sanitizeClientIdPrefix(string $prefix, string $fallback): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '-', $prefix) ?: $fallback;
    }
}
