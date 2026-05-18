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
        $mqttEnabledRaw = strtolower(trim((string)(getenv('MQTT_ENABLED') ?: 'false')));
        $mqttEnabled = in_array($mqttEnabledRaw, ['1', 'true', 'yes', 'on'], true);
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
            'api' => [
                'host' => getenv('API_HOST') ?: '0.0.0.0',
                'port' => (int)(getenv('API_PORT') ?: 8081),
            ],
            'database' => [
                'host' => getenv('DB_HOST') ?: 'localhost',
                'port' => (int)(getenv('DB_PORT') ?: 3306),
                'name' => getenv('DB_NAME') ?: 'health_watches',
                'user' => getenv('DB_USER') ?: '',
                'pass' => getenv('DB_PASS') ?: '',
            ],
            'redis' => [
                'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('REDIS_PORT') ?: 6379),
                'database' => 0,
            ],
            'mqtt' => [
                'enabled' => $mqttEnabled,
                'host' => getenv('MQTT_HOST') ?: '',
                'port' => (int)(getenv('MQTT_PORT') ?: 1883),
                'username' => getenv('MQTT_USERNAME') ?: '',
                'password' => getenv('MQTT_PASSWORD') ?: '',
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
            'public_ws_url' => getenv('WS_SERVER_URL') ?: 'ws://127.0.0.1:8080',
            'device_defaults' => [
                'allow_unknown_models' => false,
                'default_model' => null,
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
