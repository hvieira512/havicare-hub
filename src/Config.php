<?php

namespace Hub;

/**
 * @phpstan-type TcpIngressConfig array{host: string, port: int}
 * @phpstan-type DashboardConfig array{host: string, port: int, api_auth_required: bool, api_token_ttl_seconds: int, api_refresh_token_ttl_seconds: int, history_limit: int, command_timeout_seconds: int, device_idle_timeout_seconds: int}
 * @phpstan-type HubRuntimeConfig array{downlink_queue_ttl_seconds: int, whitelist_file: string}
 * @phpstan-type LocationResolutionConfig array{enabled: bool, endpoint: string, user_agent: string, timeout_seconds: float, max_accuracy_meters: float, cache_ttl_seconds: int, failure_cache_ttl_seconds: int, max_concurrency: int, max_queue: int, circuit_failure_threshold: int, circuit_open_seconds: int, rate_limit_open_seconds: int, radio_map_enabled: bool, radio_map_hash_key: string, radio_map_minimum_matches: int, radio_map_maximum_learning_accuracy_meters: float, radio_map_default_gps_accuracy_meters: float, radio_map_minimum_satellites: int, radio_map_maximum_observation_distance_meters: float, radio_map_cluster_radius_meters: float, radio_map_cache_ttl_seconds: int}
 * @phpstan-type NcsConfig array{enabled: bool, topic_filter: string}
 * @phpstan-type MokoConfig array{enabled: bool, topic_filter: string, dedupe_ttl_seconds: int, telemetry_refresh_seconds: int, idle_timeout_seconds: int}
 * @phpstan-type QinglanstConfig array{enabled: bool, host: string, port: int, username: string, password: string, topic_filter: string, client_id_prefix: string, dashboard_seen_min_interval_ms: int, position_history_sample_ms: int}
 * @phpstan-type MqttConfig array{host: string, port: int, username: string, password: string, topic_prefix: string, client_id_prefix: string, keepalive: int, timeout: float, tls_enabled: bool, tls_verify_peer: bool, tls_ca_file: string, tls_cert_file: string, tls_key_file: string}
 * @phpstan-type RedisConfig array{host: string, port: int, password: string}
 * @phpstan-type DatabaseConfig array{driver: string, host: string, port: int, name: string, username: string, password: string, charset: string}
 * @phpstan-type HubConfig array{tcp_ingress: TcpIngressConfig, dashboard: DashboardConfig, hub: HubRuntimeConfig, location_resolution: LocationResolutionConfig, ncs: NcsConfig, moko: MokoConfig, qinglanst: QinglanstConfig, mqtt: MqttConfig, redis: RedisConfig, database: DatabaseConfig}
 */
class Config
{
    /** @var HubConfig */
    private array $data;

    /** @param HubConfig $data */
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
        $locationResolutionEnabledEnv = getenv('LOCATION_RESOLUTION_ENABLED');
        $locationResolutionEnabledRaw = strtolower(trim((string)(
            $locationResolutionEnabledEnv === false || trim((string)$locationResolutionEnabledEnv) === ''
                ? 'true'
                : $locationResolutionEnabledEnv
        )));
        $locationResolutionEnabled = in_array($locationResolutionEnabledRaw, ['1', 'true', 'yes', 'on'], true);
        $beaconDbCacheTtlEnv = getenv('BEACONDB_CACHE_TTL_SECONDS');
        $beaconDbCacheTtl = $beaconDbCacheTtlEnv === false || trim((string)$beaconDbCacheTtlEnv) === ''
            ? 86400
            : max(0, (int)$beaconDbCacheTtlEnv);
        $beaconDbFailureCacheTtlEnv = getenv('BEACONDB_FAILURE_CACHE_TTL_SECONDS');
        $beaconDbFailureCacheTtl = $beaconDbFailureCacheTtlEnv === false || trim((string)$beaconDbFailureCacheTtlEnv) === ''
            ? 60
            : max(0, (int)$beaconDbFailureCacheTtlEnv);
        $locationProviderMaxConcurrency = max(1, (int)(getenv('LOCATION_PROVIDER_MAX_CONCURRENCY') ?: 5));
        $locationProviderMaxQueue = max(0, (int)(getenv('LOCATION_PROVIDER_MAX_QUEUE') ?: 1000));
        $locationCircuitFailureThreshold = max(1, (int)(getenv('LOCATION_CIRCUIT_FAILURE_THRESHOLD') ?: 3));
        $locationCircuitOpenSeconds = max(1, (int)(getenv('LOCATION_CIRCUIT_OPEN_SECONDS') ?: 300));
        $locationRateLimitOpenSeconds = max(1, (int)(getenv('LOCATION_RATE_LIMIT_OPEN_SECONDS') ?: 3600));

        return new self([
            'tcp_ingress' => [
                'host' => getenv('TCP_INGRESS_HOST') ?: '0.0.0.0',
                'port' => (int)(getenv('TCP_INGRESS_PORT') ?: 9000),
            ],
            'dashboard' => [
                'host' => getenv('DASHBOARD_HOST') ?: '0.0.0.0',
                'port' => (int)(getenv('DASHBOARD_PORT') ?: 8081),
                'api_auth_required' => $dashboardApiAuthRequired,
                'api_token_ttl_seconds' => (int)(getenv('DASHBOARD_API_TOKEN_TTL_SECONDS') ?: 3600),
                'api_refresh_token_ttl_seconds' => (int)(getenv('DASHBOARD_API_REFRESH_TOKEN_TTL_SECONDS') ?: 2592000),
                'history_limit' => (int)(getenv('DASHBOARD_HISTORY_LIMIT') ?: 100),
                'command_timeout_seconds' => (int)(getenv('DASHBOARD_COMMAND_TIMEOUT_SECONDS') ?: 3600),
                'device_idle_timeout_seconds' => (int)(getenv('DASHBOARD_DEVICE_IDLE_TIMEOUT_SECONDS') ?: 1800),
            ],
            'hub' => [
                'downlink_queue_ttl_seconds' => $downlinkQueueTtl,
                'whitelist_file' => getenv('WHITELIST_FILE') ?: '',
            ],
            'location_resolution' => [
                'enabled' => $locationResolutionEnabled,
                'endpoint' => getenv('BEACONDB_ENDPOINT') ?: 'https://api.beacondb.net/v1/geolocate',
                'user_agent' => getenv('BEACONDB_USER_AGENT') ?: 'HaviCare Devices Hub/1.0',
                'timeout_seconds' => max(0.1, (float)(getenv('BEACONDB_TIMEOUT_SECONDS') ?: 2.0)),
                'max_accuracy_meters' => max(0.0, (float)(getenv('BEACONDB_MAX_ACCURACY_METERS') ?: 500.0)),
                'cache_ttl_seconds' => $beaconDbCacheTtl,
                'failure_cache_ttl_seconds' => $beaconDbFailureCacheTtl,
                'max_concurrency' => $locationProviderMaxConcurrency,
                'max_queue' => $locationProviderMaxQueue,
                'circuit_failure_threshold' => $locationCircuitFailureThreshold,
                'circuit_open_seconds' => $locationCircuitOpenSeconds,
                'rate_limit_open_seconds' => $locationRateLimitOpenSeconds,
                'radio_map_enabled' => !in_array(strtolower(trim((string)(getenv('RADIO_MAP_ENABLED') ?: 'true'))), ['0', 'false', 'no', 'off'], true),
                'radio_map_hash_key' => getenv('RADIO_MAP_HASH_KEY') ?: '',
                'radio_map_minimum_matches' => max(2, (int)(getenv('RADIO_MAP_MINIMUM_MATCHES') ?: 2)),
                'radio_map_maximum_learning_accuracy_meters' => max(1.0, (float)(getenv('RADIO_MAP_MAXIMUM_LEARNING_ACCURACY_METERS') ?: 100.0)),
                'radio_map_default_gps_accuracy_meters' => max(1.0, (float)(getenv('RADIO_MAP_DEFAULT_GPS_ACCURACY_METERS') ?: 50.0)),
                'radio_map_minimum_satellites' => max(3, (int)(getenv('RADIO_MAP_MINIMUM_SATELLITES') ?: 4)),
                'radio_map_maximum_observation_distance_meters' => max(25.0, (float)(getenv('RADIO_MAP_MAXIMUM_OBSERVATION_DISTANCE_METERS') ?: 250.0)),
                'radio_map_cluster_radius_meters' => max(10.0, (float)(getenv('RADIO_MAP_CLUSTER_RADIUS_METERS') ?: 150.0)),
                'radio_map_cache_ttl_seconds' => max(0, (int)(getenv('RADIO_MAP_CACHE_TTL_SECONDS') ?: 60)),
            ],
            'ncs' => [
                'enabled' => !in_array(strtolower(trim((string)(getenv('NCS_ENABLED') ?: 'true'))), ['0', 'false', 'no', 'off'], true),
                'topic_filter' => getenv('NCS_TOPIC_FILTER') ?: '/voerka/#',
            ],
            'moko' => [
                'enabled' => !in_array(strtolower(trim((string)(getenv('MOKO_GATEWAY_ENABLED') ?: 'true'))), ['0', 'false', 'no', 'off'], true),
                'topic_filter' => getenv('MOKO_GATEWAY_TOPIC_FILTER') ?: 'havicare-hub/null/0/gw/+/raw',
                'dedupe_ttl_seconds' => max(1, (int)(getenv('MOKO_GATEWAY_DEDUPE_TTL_SECONDS') ?: 5)),
                'telemetry_refresh_seconds' => max(1, (int)(getenv('MOKO_GATEWAY_TELEMETRY_REFRESH_SECONDS') ?: 60)),
                'idle_timeout_seconds' => max(10, (int)(getenv('MOKO_GATEWAY_IDLE_TIMEOUT_SECONDS') ?: 180)),
            ],
            'qinglanst' => [
                'enabled' => in_array(strtolower(trim((string)(getenv('QINGLANST_ENABLED') ?: 'false'))), ['1', 'true', 'yes', 'on'], true),
                'host' => getenv('QINGLANST_MQTT_HOST') ?: '',
                'port' => (int)(getenv('QINGLANST_MQTT_PORT') ?: 1883),
                'username' => getenv('QINGLANST_MQTT_USERNAME') ?: '',
                'password' => getenv('QINGLANST_MQTT_PASSWORD') ?: '',
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

    /** @return HubConfig */
    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
