<?php

namespace App;

class Config
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(): self
    {
        $mqttTlsEnabledRaw = strtolower(trim((string)(getenv('MQTT_TLS_ENABLED') ?: 'false')));
        $mqttTlsEnabled = in_array($mqttTlsEnabledRaw, ['1', 'true', 'yes', 'on'], true);
        $mqttTlsVerifyPeerRaw = strtolower(trim((string)(getenv('MQTT_TLS_VERIFY_PEER') ?: 'true')));
        $mqttTlsVerifyPeer = in_array($mqttTlsVerifyPeerRaw, ['1', 'true', 'yes', 'on'], true);

        return new self([
            'websocket' => [
                'host' => getenv('WS_HOST') ?: '0.0.0.0',
                'port' => (int)(getenv('WS_PORT') ?: 8080),
            ],
            'vivistar_tcp' => [
                'host' => getenv('VIVISTAR_TCP_HOST') ?: '0.0.0.0',
                'port' => (int)(getenv('VIVISTAR_TCP_PORT') ?: 9000),
            ],
            'hub' => [
                'wonlex_heartbeat_interval' => max(0, (int)(getenv('WONLEX_HEARTBEAT_INTERVAL') ?: 30)),
            ],
            'mqtt' => [
                'enabled' => true,
                'host' => getenv('MQTT_HOST') ?: '',
                'port' => (int)(getenv('MQTT_PORT') ?: 1883),
                'username' => getenv('MQTT_USERNAME') ?: (getenv('MQTT_PUBLISHER_USERNAME') ?: ''),
                'password' => getenv('MQTT_PASSWORD') ?: (getenv('MQTT_PUBLISHER_PASSWORD') ?: ''),
                'topic_prefix' => trim((string)(getenv('MQTT_TOPIC_PREFIX') ?: ''), '/'),
                'client_id_prefix' => getenv('MQTT_CLIENT_ID_PREFIX') ?: 'health-mqtt',
                'keepalive' => (int)(getenv('MQTT_KEEPALIVE') ?: 60),
                'timeout' => (float)(getenv('MQTT_TIMEOUT') ?: 5.0),
                'tls_enabled' => $mqttTlsEnabled,
                'tls_verify_peer' => $mqttTlsVerifyPeer,
                'tls_ca_file' => getenv('MQTT_TLS_CA_FILE') ?: '',
                'tls_cert_file' => getenv('MQTT_TLS_CERT_FILE') ?: '',
                'tls_key_file' => getenv('MQTT_TLS_KEY_FILE') ?: '',
            ],
            'logging' => [
                'level' => getenv('LOG_LEVEL') ?: 'info',
                'file' => getenv('LOG_FILE') ?: 'var/log/server.log',
            ],
        ]);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
