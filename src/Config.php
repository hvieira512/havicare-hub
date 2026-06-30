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
        $dashboardApiAuthRequiredRaw = strtolower(trim((string)(getenv('DASHBOARD_API_AUTH_REQUIRED') ?: 'true')));
        $dashboardApiAuthRequired = in_array($dashboardApiAuthRequiredRaw, ['1', 'true', 'yes', 'on'], true);
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
                'host' => getenv('DASHBOARD_HOST') ?: '0.0.0.0',
                'port' => (int)(getenv('DASHBOARD_PORT') ?: 8081),
                'username' => getenv('DASHBOARD_USERNAME') ?: 'admin',
                'password' => getenv('DASHBOARD_PASSWORD') ?: 'secret',
                'client_username' => getenv('API_CLIENT_USERNAME') ?: '',
                'client_password' => getenv('API_CLIENT_PASSWORD') ?: '',
                'api_auth_required' => $dashboardApiAuthRequired,
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
            'qinglanst' => [
                'enabled' => !in_array(strtolower(trim((string)(getenv('QINGLANST_ENABLED') ?: 'true'))), ['0', 'false', 'no', 'off'], true),
                'host' => getenv('QINGLANST_MQTT_HOST') ?: '88.99.104.197',
                'port' => (int)(getenv('QINGLANST_MQTT_PORT') ?: 1883),
                'username' => getenv('QINGLANST_MQTT_USERNAME') ?: 'havicare',
                'password' => getenv('QINGLANST_MQTT_PASSWORD') ?: 'hitCare',
                'topic_filter' => getenv('QINGLANST_TOPIC_FILTER') ?: 'radar/1001/#',
                'client_id_prefix' => getenv('QINGLANST_CLIENT_ID_PREFIX') ?: 'qinglanst-radar',
                'dashboard_seen_min_interval_ms' => max(0, (int)(getenv('QINGLANST_DASHBOARD_SEEN_MIN_INTERVAL_MS') ?: 5000)),
                'position_history_sample_ms' => max(0, (int)(getenv('QINGLANST_POSITION_HISTORY_SAMPLE_MS') ?: 1000)),
            ],
            'mqtt' => [
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
            'redis' => [
                'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('REDIS_PORT') ?: 6379),
                'password' => getenv('REDIS_PASSWORD') ?: '',
            ],
            'database' => [
                'driver' => getenv('DB_DRIVER') ?: 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('DB_PORT') ?: 3306),
                'name' => getenv('DB_NAME') ?: 'hitecosystem_hub',
                'username' => getenv('DB_USER') ?: 'hub',
                'password' => getenv('DB_PASSWORD') ?: 'hub_pass',
                'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
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
