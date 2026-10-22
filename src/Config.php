<?php

declare(strict_types=1);

namespace Hub;

/**
 * @phpstan-type TcpIngressConfig array{host: string, port: int}
 * @phpstan-type DashboardConfig array{cors_allowed_origins: list<string>, host: string, port: int, api_auth_required: bool, api_token_ttl_seconds: int, api_refresh_token_ttl_seconds: int, history_limit: int, command_timeout_seconds: int, device_idle_timeout_seconds: int, max_open_streams: int, max_open_streams_per_user: int, login_max_per_address: int, login_max_per_username: int, login_max_global: int}
 * @phpstan-type HubRuntimeConfig array{downlink_queue_ttl_seconds: int}
 * @phpstan-type LocationResolutionConfig array{enabled: bool, endpoint: string, user_agent: string, radio_map_enabled: bool, radio_map_hash_key: string}
 * @phpstan-type NcsConfig array{enabled: bool, topic_filter: string}
 * @phpstan-type GatewayConfig array{enabled: bool, topic_filter: string, dedupe_ttl_seconds: int, telemetry_refresh_seconds: int, idle_timeout_seconds: int}
 * @phpstan-type QinglanstConfig array{enabled: bool, host: string, port: int, username: string, password: string, topic_filter: string, client_id_prefix: string, tls_enabled: bool, tls_verify_peer: bool, tls_ca_file: string, tls_cert_file: string, tls_key_file: string, dashboard_seen_min_interval_ms: int, idle_timeout_seconds: int}
 * @phpstan-type TuyaConfig array{enabled: bool, access_id: string, access_secret: string, pulsar_url: string, subscription: string, idle_timeout_seconds: int}
 * @phpstan-type MqttConfig array{host: string, port: int, username: string, password: string, topic_prefix: string, client_id_prefix: string, keepalive: int, timeout: float, tls_enabled: bool, tls_verify_peer: bool, tls_ca_file: string, tls_cert_file: string, tls_key_file: string}
 * @phpstan-type RedisConfig array{host: string, port: int, password: string}
 * @phpstan-type DatabaseConfig array{driver: string, host: string, port: int, name: string, username: string, password: string, charset: string}
 * @phpstan-type HubConfig array{tcp_ingress: TcpIngressConfig, dashboard: DashboardConfig, hub: HubRuntimeConfig, location_resolution: LocationResolutionConfig, ncs: NcsConfig, gateway: GatewayConfig, qinglanst: QinglanstConfig, tuya: TuyaConfig, mqtt: MqttConfig, redis: RedisConfig, database: DatabaseConfig}
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

    /** Um booleano do ambiente; vazio ou ilegível vale o `$default`. */
    public static function flag(string $name, bool $default): bool
    {
        return match (strtolower(trim((string)getenv($name)))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    /** Um valor do ambiente; vazio vale o `$default`, mas o `'0'` conta. */
    private static function env(string $name, string $default): string
    {
        $value = trim((string)getenv($name));

        return $value === '' ? $default : $value;
    }

    public static function load(): self
    {
        $mqttTlsEnabled = self::flag('MQTT_TLS_ENABLED', false);
        $mqttTlsVerifyPeer = self::flag('MQTT_TLS_VERIFY_PEER', true);
        // O radar publica no mesmo broker que o hub e herda a postura de TLS dele; o
        // `QINGLANST_MQTT_TLS_ENABLED` é para quando deixarem de ser o mesmo servidor.
        $qinglanstTlsEnabled = self::flag('QINGLANST_MQTT_TLS_ENABLED', $mqttTlsEnabled);
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
                'api_auth_required' => self::flag('DASHBOARD_API_AUTH_REQUIRED', true),
                'api_token_ttl_seconds' => (int)(getenv('DASHBOARD_API_TOKEN_TTL_SECONDS') ?: 3600),
                'api_refresh_token_ttl_seconds' => (int)(getenv('DASHBOARD_API_REFRESH_TOKEN_TTL_SECONDS') ?: 2592000),
                'history_limit' => (int)(getenv('DASHBOARD_HISTORY_LIMIT') ?: 100),
                // Vazio mantém a política aberta, segura enquanto a autenticação for `Bearer` em cabeçalho
                // e não cookie.
                'cors_allowed_origins' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string)(getenv('CORS_ALLOWED_ORIGINS') ?: '')),
                ))),
                'command_timeout_seconds' => (int)(getenv('DASHBOARD_COMMAND_TIMEOUT_SECONDS') ?: 3600),
                'device_idle_timeout_seconds' => (int)(getenv('DASHBOARD_DEVICE_IDLE_TIMEOUT_SECONDS') ?: 1800),
                // Sem `ev`, `event` ou `uv` o loop é `select(2)`, com `FD_SETSIZE` 1024 partilhado com TCP, MQTT e
                // HTTP: 400 streams deixam ~600. O teto por inquilino, a 25% do global, não deixa um esfomear os outros.
                'max_open_streams' => max(1, (int)(getenv('DASHBOARD_MAX_OPEN_STREAMS') ?: 400)),
                'max_open_streams_per_user' => max(1, (int)(getenv('DASHBOARD_MAX_OPEN_STREAMS_PER_USER') ?: 100)),
                // O bcrypt a custo 12 bloqueia o loop ~175 ms por tentativa de login. O teto global fixa
                // quanto desse tempo se gasta: 10 por 10 s deixa o pior caso em ~18% do loop.
                'login_max_per_address' => max(1, (int)(getenv('DASHBOARD_LOGIN_MAX_PER_ADDRESS') ?: 20)),
                'login_max_per_username' => max(1, (int)(getenv('DASHBOARD_LOGIN_MAX_PER_USERNAME') ?: 10)),
                'login_max_global' => max(1, (int)(getenv('DASHBOARD_LOGIN_MAX_GLOBAL') ?: 10)),
            ],
            'hub' => [
                'downlink_queue_ttl_seconds' => $downlinkQueueTtl,
            ],
            'location_resolution' => [
                'enabled' => self::flag('LOCATION_RESOLUTION_ENABLED', true),
                'endpoint' => getenv('BEACONDB_ENDPOINT') ?: 'https://api.beacondb.net/v1/geolocate',
                'user_agent' => getenv('BEACONDB_USER_AGENT') ?: 'Havicare Hub/1.0',
                'radio_map_enabled' => self::flag('RADIO_MAP_ENABLED', true),
                'radio_map_hash_key' => getenv('RADIO_MAP_HASH_KEY') ?: '',
            ],
            'ncs' => [
                'enabled' => self::flag('NCS_ENABLED', true),
                'topic_filter' => getenv('NCS_TOPIC_FILTER') ?: '/voerka/#',
            ],
            // A ingestão de gateways e não de um fornecedor: o espaço de tópicos `.../gw/` é do hub, e
            // leem-no a ingestão MOKO e a das pulseiras Veepoo.
            'gateway' => [
                'enabled' => self::flag('GATEWAY_ENABLED', true),
                'topic_filter' => self::env('GATEWAY_TOPIC_FILTER', 'havicare-hub/null/0/gw/+/raw'),
                // A dev lê os gateways reais: com isto a `false`, não lhes escreve.
                'commands_enabled' => self::flag('GATEWAY_COMMANDS_ENABLED', true),
                'dedupe_ttl_seconds' => max(1, (int)self::env('GATEWAY_DEDUPE_TTL_SECONDS', '5')),
                'telemetry_refresh_seconds' => max(1, (int)self::env('GATEWAY_TELEMETRY_REFRESH_SECONDS', '60')),
                'idle_timeout_seconds' => max(10, (int)self::env('GATEWAY_IDLE_TIMEOUT_SECONDS', '1500')),
                'raw_history_sample_seconds' => max(0, (int)self::env('GATEWAY_RAW_HISTORY_SAMPLE_SECONDS', '30')),
            ],
            'qinglanst' => [
                'enabled' => self::flag('QINGLANST_ENABLED', false),
                'host' => getenv('QINGLANST_MQTT_HOST') ?: '',
                'port' => (int)(getenv('QINGLANST_MQTT_PORT') ?: 1883),
                'username' => getenv('QINGLANST_MQTT_USERNAME') ?: '',
                'password' => getenv('QINGLANST_MQTT_PASSWORD') ?: '',
                'topic_filter' => getenv('QINGLANST_TOPIC_FILTER') ?: 'radar/1001/#',
                'client_id_prefix' => getenv('QINGLANST_CLIENT_ID_PREFIX') ?: 'qinglanst-radar',
                'tls_enabled' => $qinglanstTlsEnabled,
                'tls_verify_peer' => $mqttTlsVerifyPeer,
                'tls_ca_file' => getenv('MQTT_TLS_CA_FILE') ?: '',
                'tls_cert_file' => getenv('MQTT_TLS_CERT_FILE') ?: '',
                'tls_key_file' => getenv('MQTT_TLS_KEY_FILE') ?: '',
                'dashboard_seen_min_interval_ms' => max(0, (int)(getenv('QINGLANST_DASHBOARD_SEEN_MIN_INTERVAL_MS') ?: 5000)),
                'raw_history_sample_ms' => max(0, (int)(getenv('QINGLANST_RAW_HISTORY_SAMPLE_MS') ?: 30000)),
                // O radar fala a cada poucos segundos e não abre sessão: calado este tempo, está desligado.
                'idle_timeout_seconds' => max(10, (int)(getenv('QINGLANST_IDLE_TIMEOUT_SECONDS') ?: 180)),
                // Quanto se espera pela cloud do fabricante ao sincronizar uma planta. A
                // sincronização acontece a pedido e mais nada: não há relógio nenhum atrás.
                'layout_sync_timeout_seconds' => max(1, (int)(getenv('QINGLANST_LAYOUT_SYNC_TIMEOUT_SECONDS') ?: 15)),
            ],
            'tuya' => [
                'enabled' => self::flag('TUYA_ENABLED', false),
                'access_id' => getenv('TUYA_ACCESS_ID') ?: '',
                'access_secret' => getenv('TUYA_ACCESS_SECRET') ?: '',
                'pulsar_url' => getenv('TUYA_PULSAR_URL') ?: 'wss://mqe.tuyaeu.com:8285/',
                // Uma por instância, sem valor por omissão: duas instâncias na mesma repartem as mensagens.
                'subscription' => getenv('TUYA_SUBSCRIPTION') ?: '',
                // A pulseira acorda de oito em oito horas; calada mais do que isto, está desligada.
                'idle_timeout_seconds' => max(60, (int)(getenv('TUYA_IDLE_TIMEOUT_SECONDS') ?: 30600)),
            ],
            'mqtt' => [
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
            'redis' => [
                'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('REDIS_PORT') ?: 6379),
                'password' => getenv('REDIS_PASSWORD') ?: '',
                // Antepõe-se a todas as chaves do hub, para uma segunda instância partilhar o Redis; vazio
                // é produção. A raiz `hub:` já é partilhada com o reencaminhador.
                'prefix' => getenv('REDIS_PREFIX') ?: '',
            ],
            'database' => [
                'driver' => getenv('DB_DRIVER') ?: 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('DB_PORT') ?: 3306),
                'name' => getenv('DB_NAME') ?: 'havicare_hub',
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
