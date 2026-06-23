<?php

namespace Hub;

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
        $dashboardEnabledRaw = strtolower(trim((string)(getenv('DASHBOARD_ENABLED') ?: 'true')));
        $dashboardEnabled = in_array($dashboardEnabledRaw, ['1', 'true', 'yes', 'on'], true);
        $downlinkQueueTtlRaw = getenv('DOWNLINK_QUEUE_TTL_SECONDS');
        $downlinkQueueTtl = $downlinkQueueTtlRaw === false || trim((string)$downlinkQueueTtlRaw) === ''
            ? 300
            : max(1, (int)$downlinkQueueTtlRaw);

        return new self([
            'tcp_ingress' => [
                'host' => getenv('TCP_INGRESS_HOST') ?: '0.0.0.0',
                'port' => (int)(getenv('TCP_INGRESS_PORT') ?: 9000),
            ],
            'dashboard' => [
                'enabled' => $dashboardEnabled,
                'host' => getenv('DASHBOARD_HOST') ?: '0.0.0.0',
                'port' => (int)(getenv('DASHBOARD_PORT') ?: 8081),
                'username' => getenv('DASHBOARD_USERNAME') ?: '',
                'password' => getenv('DASHBOARD_PASSWORD') ?: '',
                'api_token_ttl_seconds' => (int)(getenv('DASHBOARD_API_TOKEN_TTL_SECONDS') ?: 3600),
                'history_limit' => (int)(getenv('DASHBOARD_HISTORY_LIMIT') ?: 100),
                'command_timeout_seconds' => (int)(getenv('DASHBOARD_COMMAND_TIMEOUT_SECONDS') ?: 3600),
                'device_idle_timeout_seconds' => (int)(getenv('DASHBOARD_DEVICE_IDLE_TIMEOUT_SECONDS') ?: 1800),
            ],
            'hub' => [
                'downlink_queue_ttl_seconds' => $downlinkQueueTtl,
                'whitelist_file' => getenv('WHITELIST_FILE') ?: '',
            ],
            'ncs' => [
                'enabled' => !in_array(strtolower(trim((string)(getenv('NCS_ENABLED') ?: 'true'))), ['0', 'false', 'no', 'off'], true),
                'topic_filter' => getenv('NCS_TOPIC_FILTER') ?: '/voerka/#',
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
            'redis' => [
                'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('REDIS_PORT') ?: 6379),
                'password' => getenv('REDIS_PASSWORD') ?: '',
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
