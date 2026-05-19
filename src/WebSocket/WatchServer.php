<?php

namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\Registry\Whitelist;
use App\Registry\DeviceCapabilities;
use App\Repository\EventRepository;
use App\Log\Logger;
use App\Redis\Client as RedisClient;
use App\Protocol\AdapterRegistry;
use App\Protocol\Adapter\DeviceAdapterInterface;
use App\Services\CommandService;
use App\Services\EventService;

class WatchServer implements MessageComponentInterface
{
    private \SplObjectStorage $connections;
    private array $sessions;      // resourceId => session
    private array $deviceMap;     // imei => ConnectionInterface
    private array $deviceData;    // imei => latest health data
    private array $eventHistory;   // recent passive events
    private int $nextEventId;
    private int $commandAckTimeoutMs;
    private array $pendingCommands; // requestId => metadata
    private Whitelist $whitelist;
    private ?EventRepository $eventsRepo;
    private ?RedisClient $redis;
    private AdapterRegistry $adapters;
    private CommandService $commandService;
    private EventService $eventService;

    public function __construct(
        ?\PDO $pdo = null,
        ?RedisClient $redis = null,
        ?CommandService $commandService = null,
        ?EventService $eventService = null,
    )
    {
        DeviceCapabilities::setDatabasePdo($pdo);
        DeviceCapabilities::setCacheTtl((int)(getenv('MODEL_CACHE_TTL_SECONDS') ?: 5));
        $this->eventsRepo = $pdo ? new EventRepository($pdo) : null;
        $this->redis = $redis;
        $this->connections = new \SplObjectStorage();
        $this->sessions = [];
        $this->deviceMap = [];
        $this->deviceData = [];
        $this->eventHistory = [];
        $this->nextEventId = 1;
        $this->commandAckTimeoutMs = max(1000, (int)(getenv('COMMAND_ACK_TIMEOUT_MS') ?: 15000));
        $this->pendingCommands = [];
        $this->whitelist = new Whitelist(pdo: $pdo);
        $this->adapters = new AdapterRegistry();
        $this->commandService = $commandService
            ?? new CommandService(
                $this->whitelist,
                $this,
                $this->redis,
                fn(string $imei): bool => $this->isOnline($imei),
            );
        $this->eventService = $eventService ?? new EventService($this->eventsRepo, $this->redis);

        if ($this->eventsRepo) {
            $this->loadDeviceDataFromDatabase();
        }
    }

    private function loadDeviceDataFromDatabase(): void
    {
        $this->deviceData = $this->eventsRepo->latestForAllImeis();
        if (!empty($this->deviceData)) {
            Logger::channel('watch')->info("Loaded " . count($this->deviceData)
                . " recent events from the database");
        }
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->connections->offsetSet($conn, $conn->resourceId);
        $this->sessions[$conn->resourceId] = [
            'authenticated' => false,
            'imei' => null,
            'model' => null,
            'caps' => null,
            'protocol' => null,
            'adapter' => null,
            'lastCommandType' => null,
            'lastCommandIdent' => null,
            'lastCommandRequestId' => null,
            'lastCommandFeature' => null,
        ];
        Logger::channel('watch')->info("New connection: {$conn->resourceId}");
    }

    public function isRedisAvailable(): bool
    {
        return $this->redis !== null && $this->redis->isAvailable();
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $rid = $from->resourceId;
        $session = $this->sessions[$rid] ?? [];
        $raw = (string)$msg;

        $payload = null;
        $adapter = $session['adapter'] ?? null;
        if ($adapter instanceof DeviceAdapterInterface) {
            $payload = $adapter->decodeIncoming($raw, ['session' => $session]);
            if ($payload !== null) {
                $payload['_protocol'] = $session['protocol'] ?? $adapter->protocol();
            }
        }

        if ($payload === null) {
            $payload = $this->adapters->decodeAny($raw, ['session' => $session]);
        }

        if (!$payload || !isset($payload['type'])) {
            Logger::channel('watch')->warning('Invalid packet (unrecognized protocol payload)');
            return;
        }

        $type = $payload['type'];
        $detectedProtocol = $payload['_protocol'] ?? null;
        if ($detectedProtocol !== null && ($this->sessions[$rid]['protocol'] ?? null) === null) {
            $this->sessions[$rid]['protocol'] = $detectedProtocol;
            $this->sessions[$rid]['adapter'] = $this->adapters->get($detectedProtocol);
        }

        // Unauthenticated: only accepts "login".
        if (!($this->sessions[$rid]['authenticated'] ?? false)) {
            if ($type === 'login') {
                $this->handleLogin($from, $payload, $detectedProtocol);
            } elseif (in_array($type, ['login_error', 'login_ok'], true)) {
                return;
            } elseif (($this->sessions[$rid]['protocol'] ?? '') === 'vivistar-iw') {
                // Vivistar doc: reply to login/heartbeat for unregistered IMEI,
                // do not disconnect to avoid reconnect loops.
                return;
            } else {
                $this->sendError($from, $payload, 'authentication_required',
                    'You must send login first');
            }
            return;
        }

        // Authenticated: verify the session token.
        $session = $this->sessions[$rid];
        $caps = $session['caps'];
        $imei = $session['imei'];

        // Rate limiting via Redis
        if ($this->isRedisAvailable() && !$this->redis->rateLimitMessage($imei)) {
            $this->sendError($from, $payload, 'rate_limited',
                'Too many messages. Please wait a moment.');
            return;
        }

        $ref = $payload['ref'] ?? '';
        $isReplyToServerCommand = $ref === 'w:reply'
            || $this->isVivistarCommandReply($session, $payload);
        $isPassiveUpdate = !$isReplyToServerCommand;

        if ($isPassiveUpdate && !$caps->supportsPassive($type)) {
            $this->sendError($from, $payload, 'capability_not_supported',
                "Model {$session['model']} does not support $type");
            return;
        }

        // Store the latest passive event received, regardless of native protocol.
        if ($isPassiveUpdate) {
            $this->storeDeviceEvent($imei, [
                'imei' => $imei,
                'nativeType' => $type,
                'feature' => $caps->featureForPassive($type),
                'nativePayload' => $this->sanitizePayload($payload['data'] ?? []),
                'receivedAt' => $this->now(),
            ]);
        }

        // If it is a reply to one of our commands (w:reply), always accept it.
        if ($isReplyToServerCommand) {
            $requestId = $this->resolveRequestIdForAck($rid, $session, $payload);
            if ($this->isRedisAvailable() && $requestId) {
                $this->pushCommandState(
                    imei: $imei,
                    state: 'ack',
                    type: (string)($session['lastCommandType'] ?? ''),
                    feature: isset($session['lastCommandFeature']) ? (string)$session['lastCommandFeature'] : null,
                    requestId: $requestId,
                    ident: isset($payload['ident']) ? (string)$payload['ident'] : null,
                    reason: 'device_reply',
                    protocol: isset($session['protocol']) ? (string)$session['protocol'] : null,
                    timestamp: $this->now(),
                );
            }
            if (($session['protocol'] ?? '') === 'vivistar-iw') {
                $this->sessions[$rid]['lastCommandType'] = null;
                $this->sessions[$rid]['lastCommandIdent'] = null;
                $this->sessions[$rid]['lastCommandRequestId'] = null;
                $this->sessions[$rid]['lastCommandFeature'] = null;
            }
            if ($requestId !== null) {
                unset($this->pendingCommands[$requestId]);
            }
            Logger::channel('watch')->info("reply IMEI=$imei, type=$type");
            $this->sendPayload($from, $this->buildReply($payload, $payload['data'] ?? []));
            return;
        }

        // Generic command accepted (w:update).
        $this->routeCommand($from, $payload);
    }

    private function handleLogin(ConnectionInterface $conn, array $payload, ?string $detectedProtocol = null): void
    {
        $rid = $conn->resourceId;
        $protocol = $detectedProtocol ?? ($this->sessions[$rid]['protocol'] ?? null);
        $imei = $payload['imei'] ?? '';
        $data = $payload['data'] ?? [];
        $model = $data['deviceModel'] ?? '';
        $ident = $payload['ident'] ?? '';

        $isVivistar = $protocol === 'vivistar-iw';

        // 1. Check whitelist — Vivistar doc permits unknown IMEI login to avoid reconnect loops.
        if (!$this->whitelist->isAuthorized($imei)) {
            if ($isVivistar) {
                Logger::channel('watch')->warning("Vivistar unknown IMEI=$imei — accepting per protocol spec");
            } else {
                $this->sendLoginError($conn, $ident, $imei, 'IMEI not authorized or disabled');
                return;
            }
        }

        // 2. Determine model.
        $expectedModel = $this->whitelist->getModel($imei);
        if ($model === '' && $expectedModel) {
            $model = $expectedModel;
        }

        if ($expectedModel && $model !== '' && $expectedModel !== $model) {
            $this->sendLoginError($conn, $ident, $imei,
                "Model mismatch: expected $expectedModel, got $model");
            return;
        }

        // For Vivistar with no model declared, default to the most comprehensive profile.
        if ($model === '' && $isVivistar) {
            $model = 'VIVISTAR-CARE';
        }

        // 3. Load capabilities.
        $caps = $model !== '' ? DeviceCapabilities::forModel($model) : null;
        if (!$caps) {
            if ($isVivistar) {
                $model = 'VIVISTAR-CARE';
                $caps = DeviceCapabilities::forModel($model);
            }
            if (!$caps) {
                $this->sendLoginError($conn, $ident, $imei,
                    "Unknown device model: $model");
                return;
            }
        }

        $modelAdapter = $this->adapters->resolveForModel($model);
        if ($modelAdapter === null) {
            if ($isVivistar) {
                $modelAdapter = $this->adapters->get('vivistar-iw');
            }
            if ($modelAdapter === null) {
                $this->sendLoginError($conn, $ident, $imei,
                    "No protocol adapter configured for model: $model");
                return;
            }
        }

        if ($protocol !== null && $modelAdapter->protocol() !== $protocol) {
            $this->sendLoginError(
                $conn,
                $ident,
                $imei,
                "Protocol mismatch: expected {$modelAdapter->protocol()}, got $protocol"
            );
            return;
        }

        // 3b. Wonlex key verification (optional).
        if ($protocol === 'wonlex-json') {
            $deviceKey = $payload['key'] ?? $data['key'] ?? '';
            $storedSecret = $this->whitelist->getDeviceSecret($imei);
            if ($storedSecret !== null) {
                if ($deviceKey === '' || $deviceKey !== $storedSecret) {
                    $this->sendLoginError($conn, $ident, $imei, 'Device key mismatch');
                    return;
                }
            }
        }

        // 4. Accept login.
        $this->sessions[$rid]['authenticated'] = true;
        $this->sessions[$rid]['imei'] = $imei;
        $this->sessions[$rid]['model'] = $model;
        $this->sessions[$rid]['caps'] = $caps;
        $this->sessions[$rid]['protocol'] = $modelAdapter->protocol();
        $this->sessions[$rid]['adapter'] = $modelAdapter;

        $previousConn = $this->deviceMap[$imei] ?? null;
        $this->deviceMap[$imei] = $conn;

        if ($this->isRedisAvailable()) {
            $this->redis->deviceSetOnline($imei);
            $this->redis->statusPush([
                'imei' => $imei,
                'state' => 'online',
                'reason' => 'login_ok',
                'protocol' => $this->sessions[$rid]['protocol'] ?? '',
                'timestamp' => $this->now(),
            ]);
        }

        $loginTimestamp = $this->now();
        $protocol = $this->sessions[$rid]['protocol'] ?? '';
        if ($protocol === 'wonlex-json') {
            // Wonlex protocol docs define login reply payload under type=login.
            $this->sendPayload($conn, [
                'type' => 'login',
                'ident' => $ident,
                'ref' => 's:reply',
                'imei' => $imei,
                'data' => [
                    'type' => 'login',
                    'imei' => $imei,
                    'deviceModel' => $model,
                    'bindStatus' => 1,
                    'timestamp' => $loginTimestamp,
                ],
                'timestamp' => $loginTimestamp,
            ]);
        } else {
            // Vivistar adapter maps login_ok to BP00.
            $this->sendPayload($conn, [
                'type' => 'login_ok',
                'ident' => $ident,
                'ref' => 's:reply',
                'imei' => $imei,
                'data' => [
                    'serverTime' => $loginTimestamp,
                    'capabilities' => $caps->toArray(),
                ],
                'timestamp' => $loginTimestamp,
            ]);
        }

        Logger::channel('watch')->info("Login OK: IMEI=$imei, model=$model, protocol={$protocol}");

        if ($previousConn !== null && $previousConn !== $conn) {
            Logger::channel('watch')->warning("Duplicate login for IMEI=$imei; the new connection took over routing");
        }
    }

    private function sendLoginError(ConnectionInterface $conn, string $ident, string $imei, string $msg): void
    {
        $rid = $conn->resourceId;
        $session = $this->sessions[$rid] ?? [];
        if ($this->isRedisAvailable() && $imei !== '') {
            $this->redis->errorPush([
                'imei' => $imei,
                'code' => 'login_error',
                'message' => $msg,
                'command' => 'login',
                'protocol' => $session['protocol'] ?? '',
                'timestamp' => $this->now(),
            ]);
        }

        $timestamp = $this->now();
        if (($session['protocol'] ?? '') === 'wonlex-json') {
            $this->sendPayload($conn, [
                'type' => 'login',
                'ident' => $ident,
                'ref' => 's:reply',
                'imei' => $imei,
                'data' => [
                    'type' => 'login',
                    'imei' => $imei,
                    'bindStatus' => 0,
                    'error' => $msg,
                    'timestamp' => $timestamp,
                ],
                'timestamp' => $timestamp,
            ]);
        } else {
            $this->sendPayload($conn, [
                'type' => 'login_error',
                'ident' => $ident,
                'ref' => 's:reply',
                'imei' => $imei,
                'data' => ['error' => $msg],
                'timestamp' => $timestamp,
            ]);
        }
        Logger::channel('watch')->warning("Login rejected: IMEI=$imei ($msg)");
    }

    private function routeCommand(ConnectionInterface $conn, array $payload): void
    {
        $type = $payload['type'];
        $imei = $payload['imei'] ?? '';

        Logger::channel('watch')->info("data IMEI=$imei, type=$type");

        $rid = $conn->resourceId;
        $session = $this->sessions[$rid] ?? [];
        $replyData = $this->buildPassiveReplyData($session, $payload);
        $this->sendPayload($conn, $this->buildReply($payload, $replyData));
    }

    private function buildPassiveReplyData(array $session, array $payload): array
    {
        if (($session['protocol'] ?? '') !== 'vivistar-iw') {
            return [];
        }

        $type = (string)($payload['type'] ?? '');
        $fields = $payload['data']['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }

        if ($type === 'AP02') {
            $replyFlag = (string)($fields[1] ?? '0');
            if ($replyFlag === '1') {
                $lang = (string)($fields[0] ?? '');
                $coords = $this->resolveVivistarReplyCoordinates($session, $payload);
                $text = $this->buildVivistarAddressText($coords, $lang, false);
                return ['unicodeHex' => $this->toUnicodeHex($text)];
            }
            return [];
        }

        if ($type === 'AP10') {
            $flags = $this->resolveAp10ReplyFlags($fields);
            $needsAddress = str_starts_with($flags, '1');
            if ($needsAddress) {
                $includeMapLink = strlen($flags) >= 2 && $flags[1] === '1';
                $lang = (string)($fields[6] ?? '');
                $coords = $this->resolveVivistarReplyCoordinates($session, $payload);
                $text = $this->buildVivistarAddressText($coords, $lang, $includeMapLink);
                return ['unicodeHex' => $this->toUnicodeHex($text)];
            }
            return [];
        }

        return [];
    }

    private function resolveAp10ReplyFlags(array $fields): string
    {
        $candidate = (string)($fields[7] ?? '');
        if (preg_match('/^[01]{2}$/', $candidate) === 1) {
            return $candidate;
        }

        foreach ($fields as $field) {
            $value = trim((string)$field);
            if (preg_match('/^[01]{2}$/', $value) === 1) {
                return $value;
            }
        }

        return '00';
    }

    private function resolveVivistarReplyCoordinates(array $session, array $payload): ?array
    {
        $coords = $this->extractVivistarCoordinatesFromPayload($payload['data'] ?? []);
        if ($coords !== null) {
            return $coords;
        }

        $imei = (string)($session['imei'] ?? $payload['imei'] ?? '');
        if ($imei === '') {
            return null;
        }

        $latest = $this->deviceData[$imei] ?? null;
        if (!is_array($latest)) {
            return null;
        }

        $native = $latest['nativePayload'] ?? $latest['nativeData'] ?? [];
        return $this->extractVivistarCoordinatesFromPayload(is_array($native) ? $native : []);
    }

    private function extractVivistarCoordinatesFromPayload(array $payload): ?array
    {
        $raw = (string)($payload['raw'] ?? '');
        if ($raw === '' && isset($payload['fields'][0])) {
            $raw = (string)$payload['fields'][0];
        }

        if ($raw !== '') {
            if (preg_match('/([0-9]{4}\.[0-9]+)([NS])([0-9]{5}\.[0-9]+)([EW])/', $raw, $m) === 1) {
                $lat = $this->parseNmeaCoordinate($m[1], true, $m[2]);
                $lng = $this->parseNmeaCoordinate($m[3], false, $m[4]);
                if ($lat !== null && $lng !== null) {
                    return ['lat' => $lat, 'lng' => $lng];
                }
            }
        }

        $lat = $payload['lat'] ?? $payload['latitude'] ?? $payload['upLocation']['lat'] ?? null;
        $lng = $payload['lng'] ?? $payload['lon'] ?? $payload['longitude'] ?? $payload['upLocation']['lon'] ?? null;
        if (is_numeric((string)$lat) && is_numeric((string)$lng)) {
            return ['lat' => (float)$lat, 'lng' => (float)$lng];
        }

        return null;
    }

    private function parseNmeaCoordinate(string $value, bool $isLatitude, string $hemisphere): ?float
    {
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $digits = $isLatitude ? 2 : 3;
        if (strlen($value) < ($digits + 3)) {
            return null;
        }

        $degrees = (int)substr($value, 0, $digits);
        $minutes = (float)substr($value, $digits);
        if ($degrees === 0 && $minutes == 0.0) {
            return null;
        }

        $decimal = $degrees + ($minutes / 60.0);
        $hemisphere = strtoupper($hemisphere);
        if ($hemisphere === 'S' || $hemisphere === 'W') {
            $decimal *= -1;
        }

        return $decimal;
    }

    private function buildVivistarAddressText(?array $coords, string $language, bool $includeMapLink): string
    {
        $lang = strtolower(str_replace('_', '-', trim($language)));
        $isChinese = str_starts_with($lang, 'zh');

        if ($coords !== null) {
            $lat = number_format((float)$coords['lat'], 6, '.', '');
            $lng = number_format((float)$coords['lng'], 6, '.', '');
            $address = $isChinese
                ? "纬度{$lat}，经度{$lng}"
                : "Lat {$lat}, Lng {$lng}";
        } else {
            $address = $isChinese ? '位置不可用' : 'Location unavailable';
            $lat = null;
            $lng = null;
        }

        if ($includeMapLink && $lat !== null && $lng !== null) {
            $address .= "\nhttp://www.gps.com/map.aspx?lat={$lat}&lng={$lng}";
        }

        return $address;
    }

    private function toUnicodeHex(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            $utf16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
            return strtoupper(bin2hex($utf16));
        }

        if (function_exists('iconv')) {
            $utf16 = iconv('UTF-8', 'UTF-16BE//IGNORE', $text);
            if ($utf16 !== false) {
                return strtoupper(bin2hex($utf16));
            }
        }

        $out = '';
        foreach (str_split($text) as $char) {
            $out .= sprintf('%04X', ord($char));
        }
        return $out;
    }

    public function sendCommand(
        string $imei,
        string $type,
        array $data = [],
        ?string $requestId = null,
        ?string $feature = null,
    ): bool
    {
        $requestId = $requestId ?: bin2hex(random_bytes(8));

        if (!isset($this->deviceMap[$imei])) {
            Logger::channel('watch')->warning("sendCommand: IMEI=$imei offline (not on this node)");
            if ($this->isRedisAvailable()) {
                $node = $this->redis->deviceGetNode($imei);
                if ($node === null) {
                    Logger::channel('watch')->warning("sendCommand: IMEI=$imei not found in Redis");
                } elseif ($node !== $this->redis->getNodeId()) {
                    Logger::channel('watch')->warning("sendCommand: IMEI=$imei is on node $node (future: reroute via Pub/Sub)");
                }
                $this->pushCommandState(
                    imei: $imei,
                    state: 'failed',
                    type: $type,
                    feature: $feature,
                    requestId: $requestId,
                    ident: null,
                    reason: 'offline_or_not_routable',
                    protocol: null,
                    timestamp: $this->now(),
                );
            }
            return false;
        }

        $conn = $this->deviceMap[$imei];
        $session = $this->sessions[$conn->resourceId] ?? null;

        if (!$session || !$session['caps']->supportsActive($type)) {
            Logger::channel('watch')->warning("sendCommand: $type is not supported for $imei");
            if ($this->isRedisAvailable()) {
                $this->pushCommandState(
                    imei: $imei,
                    state: 'failed',
                    type: $type,
                    feature: $feature,
                    requestId: $requestId,
                    ident: null,
                    reason: 'command_not_supported',
                    protocol: isset($session['protocol']) ? (string)$session['protocol'] : null,
                    timestamp: $this->now(),
                );
            }
            return false;
        }

        $ident = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $this->sendPayload($conn, [
            'type' => $type,
            'ident' => $ident,
            'ref' => 's:down',
            'imei' => $imei,
            'data' => $data,
            'timestamp' => $this->now(),
        ]);

        Logger::channel('watch')->info("cmd IMEI=$imei, type=$type, ident=$ident");
        $this->sessions[$conn->resourceId]['lastCommandType'] = $type;
        $this->sessions[$conn->resourceId]['lastCommandIdent'] = $ident;
        $this->sessions[$conn->resourceId]['lastCommandRequestId'] = $requestId;
        $this->sessions[$conn->resourceId]['lastCommandFeature'] = $feature;
        $this->pendingCommands[$requestId] = [
            'resourceId' => $conn->resourceId,
            'imei' => $imei,
            'type' => $type,
            'feature' => $feature,
            'ident' => $ident,
            'protocol' => $session['protocol'] ?? '',
            'deadlineAt' => $this->now() + $this->commandAckTimeoutMs,
        ];
        if ($this->isRedisAvailable()) {
            $this->pushCommandState(
                imei: $imei,
                state: 'dispatched',
                type: $type,
                feature: $feature,
                requestId: $requestId,
                ident: $ident,
                reason: 'sent_to_device',
                protocol: isset($session['protocol']) ? (string)$session['protocol'] : null,
                timestamp: $this->now(),
            );
        }
        return true;
    }

    public function resolveFeatureCommand(string $imei, string $feature): ?string
    {
        $model = $this->whitelist->getModel($imei);
        if (!$model) {
            return null;
        }

        $caps = DeviceCapabilities::forModel($model);
        return $caps?->resolveFeatureActiveCommand($feature);
    }

    public function sendFeatureCommand(string $imei, string $feature, array $data = [], ?string $requestId = null): ?string
    {
        $type = $this->resolveFeatureCommand($imei, $feature);
        if (!$type || !$this->sendCommand($imei, $type, $data, $requestId, $feature)) {
            return null;
        }

        return $type;
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $rid = $conn->resourceId;
        $imei = $this->sessions[$rid]['imei'] ?? 'unknown';
        Logger::channel('watch')->info("Disconnected: resourceId=$rid, IMEI=$imei");

        if ($imei && isset($this->deviceMap[$imei]) && $this->deviceMap[$imei] === $conn) {
            $fallback = $this->findConnectionForImei($imei, $rid);
            if ($fallback !== null) {
                $this->deviceMap[$imei] = $fallback;
                Logger::channel('watch')->info("Routing restored: IMEI=$imei, resourceId={$fallback->resourceId}");
            } else {
                unset($this->deviceMap[$imei]);
                if ($this->isRedisAvailable()) {
                    $this->redis->deviceSetOffline($imei);
                    $this->redis->statusPush([
                        'imei' => $imei,
                        'state' => 'offline',
                        'reason' => 'disconnect',
                        'protocol' => $this->sessions[$rid]['protocol'] ?? '',
                        'timestamp' => $this->now(),
                    ]);
                }
            }
        }

        $this->failPendingCommandsForResource($rid, 'device_disconnected_before_ack');
        unset($this->sessions[$rid]);
        $this->connections->offsetUnset($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        Logger::channel('watch')->error($e->getMessage());
        $conn->close();
    }

    // --- Helpers ---

    private function sendPayload(ConnectionInterface $client, array $data): void
    {
        $rid = $client->resourceId;
        $session = $this->sessions[$rid] ?? [];
        $adapter = $session['adapter'] ?? null;

        if (!$adapter instanceof DeviceAdapterInterface) {
            $protocol = $session['protocol'] ?? 'wonlex-json';
            $adapter = $this->adapters->get($protocol) ?? $this->adapters->get('wonlex-json');
        }

        if (!$adapter instanceof DeviceAdapterInterface) {
            return;
        }

        $client->send($adapter->encodeOutgoing($data, ['session' => $session]));
    }

    private function sendError(ConnectionInterface $conn, array $original, string $error, string $msg = ''): void
    {
        $rid = $conn->resourceId;
        $session = $this->sessions[$rid] ?? [];
        $imei = $original['imei'] ?? ($session['imei'] ?? '');
        if ($this->isRedisAvailable() && $imei !== '') {
            $this->redis->errorPush([
                'imei' => $imei,
                'code' => $error,
                'message' => $msg ?: $error,
                'command' => $original['type'] ?? '',
                'protocol' => $session['protocol'] ?? '',
                'timestamp' => $this->now(),
            ]);
        }

        $this->sendPayload($conn, [
            'type' => 'error',
            'ident' => $original['ident'] ?? '',
            'ref' => 's:reply',
            'imei' => $imei,
            'data' => [
                'error' => $error,
                'command' => $original['type'] ?? '',
                'message' => $msg ?: $error,
            ],
            'timestamp' => $this->now(),
        ]);
    }

    private function isVivistarCommandReply(array $session, array $payload): bool
    {
        if (($session['protocol'] ?? '') !== 'vivistar-iw') {
            return false;
        }

        $lastType = $session['lastCommandType'] ?? '';
        $lastIdent = $session['lastCommandIdent'] ?? '';
        $incomingType = $payload['type'] ?? '';
        $incomingIdent = $payload['ident'] ?? '';

        if ($lastType === '' || $lastIdent === '' || $incomingType === '' || $incomingIdent === '') {
            return false;
        }

        if ($incomingIdent !== $lastIdent) {
            return false;
        }

        if (preg_match('/^BP([A-Z0-9]{2})$/', $lastType, $downMatch) !== 1) {
            return false;
        }

        if (preg_match('/^AP([A-Z0-9]{2})$/', $incomingType, $upMatch) !== 1) {
            return false;
        }

        return $downMatch[1] === $upMatch[1];
    }

    private function buildReply(array $payload, ?array $extraData = null): array
    {
        return [
            'type' => $payload['type'],
            'ident' => $payload['ident'] ?? '',
            'ref' => 's:reply',
            'imei' => $payload['imei'] ?? '',
            'data' => $extraData ?? new \stdClass(),
            'timestamp' => $this->now(),
        ];
    }

    private function now(): int
    {
        return (int)round(microtime(true) * 1000);
    }

    private function sanitizePayload(array $payload): array
    {
        unset($payload['sessionToken']);
        unset($payload['encryptionCode']);
        unset($payload['EncryptionCode']);

        return $payload;
    }

    private function findConnectionForImei(string $imei, int $excludeRid): ?ConnectionInterface
    {
        foreach ($this->connections as $conn) {
            $rid = $conn->resourceId;
            if ($rid === $excludeRid) {
                continue;
            }

            $session = $this->sessions[$rid] ?? null;
            if (($session['authenticated'] ?? false) && ($session['imei'] ?? null) === $imei) {
                return $conn;
            }
        }

        return null;
    }

    private function storeDeviceEvent(string $imei, array $event): void
    {
        $stored = $this->eventService->persistWatchIngressEvent($event, $this->nextEventId);
        $this->eventService->ingestInMemory($stored, $this->deviceData, $this->eventHistory);
    }

    public function ingestEvent(array $event, int $dbId): void
    {
        $event['id'] = $dbId;
        $this->eventService->ingestInMemory($event, $this->deviceData, $this->eventHistory);
    }

    public function sweepCommandTimeouts(): int
    {
        if (!$this->isRedisAvailable() || $this->pendingCommands === []) {
            return 0;
        }

        $now = $this->now();
        $timedOut = 0;
        foreach ($this->pendingCommands as $requestId => $pending) {
            $deadlineAt = (int)($pending['deadlineAt'] ?? 0);
            if ($deadlineAt <= 0 || $deadlineAt > $now) {
                continue;
            }

            $imei = (string)($pending['imei'] ?? '');
            if ($imei !== '') {
                $this->pushCommandState(
                    imei: $imei,
                    state: 'timeout',
                    type: (string)($pending['type'] ?? ''),
                    feature: isset($pending['feature']) ? (string)$pending['feature'] : null,
                    requestId: (string)$requestId,
                    ident: isset($pending['ident']) ? (string)$pending['ident'] : null,
                    reason: 'ack_timeout',
                    protocol: isset($pending['protocol']) ? (string)$pending['protocol'] : null,
                    timestamp: $now,
                );
            }

            $resourceId = (int)($pending['resourceId'] ?? 0);
            if ($resourceId > 0 && isset($this->sessions[$resourceId])) {
                if (($this->sessions[$resourceId]['lastCommandRequestId'] ?? null) === $requestId) {
                    $this->sessions[$resourceId]['lastCommandType'] = null;
                    $this->sessions[$resourceId]['lastCommandIdent'] = null;
                    $this->sessions[$resourceId]['lastCommandRequestId'] = null;
                    $this->sessions[$resourceId]['lastCommandFeature'] = null;
                }
            }

            unset($this->pendingCommands[$requestId]);
            $timedOut++;
        }

        if ($timedOut > 0) {
            Logger::channel('watch')->warning("Command ACK timeout count={$timedOut}");
        }

        return $timedOut;
    }

    private function resolveRequestIdForAck(int $resourceId, array $session, array $payload): ?string
    {
        $requestId = $session['lastCommandRequestId'] ?? null;
        if (is_string($requestId) && $requestId !== '') {
            return $requestId;
        }

        $ident = (string)($payload['ident'] ?? '');
        if ($ident === '') {
            return null;
        }

        foreach ($this->pendingCommands as $candidateRequestId => $pending) {
            if (($pending['ident'] ?? null) !== $ident) {
                continue;
            }
            if ((int)($pending['resourceId'] ?? 0) !== $resourceId) {
                continue;
            }
            return (string)$candidateRequestId;
        }

        return null;
    }

    private function failPendingCommandsForResource(int $resourceId, string $reason): void
    {
        if (!$this->isRedisAvailable() || $this->pendingCommands === []) {
            return;
        }

        foreach ($this->pendingCommands as $requestId => $pending) {
            if ((int)($pending['resourceId'] ?? 0) !== $resourceId) {
                continue;
            }

            $imei = (string)($pending['imei'] ?? '');
            if ($imei !== '') {
                $this->pushCommandState(
                    imei: $imei,
                    state: 'failed',
                    type: (string)($pending['type'] ?? ''),
                    feature: isset($pending['feature']) ? (string)$pending['feature'] : null,
                    requestId: (string)$requestId,
                    ident: isset($pending['ident']) ? (string)$pending['ident'] : null,
                    reason: $reason,
                    protocol: isset($pending['protocol']) ? (string)$pending['protocol'] : null,
                    timestamp: $this->now(),
                );
            }
            unset($this->pendingCommands[$requestId]);
        }
    }

    private function pushCommandState(
        string $imei,
        string $state,
        string $type,
        ?string $feature,
        string $requestId,
        ?string $ident,
        string $reason,
        ?string $protocol,
        int $timestamp,
    ): void {
        if (!$this->isRedisAvailable()) {
            return;
        }

        $this->redis->commandStatePush(
            $this->commandService->commandStatePayload(
                imei: $imei,
                state: $state,
                type: $type,
                feature: $feature,
                requestId: $requestId,
                ident: $ident,
                reason: $reason,
                protocol: $protocol,
                timestamp: $timestamp,
            )
        );
    }

    // --- Public API for the HTTP server ---

    public function getWhitelist(): Whitelist
    {
        return $this->whitelist;
    }

    public function getDeviceData(string $imei): ?array
    {
        return $this->deviceData[$imei] ?? null;
    }

    public function getRecentEvents(int $limit = 50, ?int $afterId = null): array
    {
        // Split/Redis mode: prefer ingress-local memory for freshest events, because
        // DB persistence may lag behind stream ingestion. On cold start (no in-memory
        // history yet), fall back to DB if available.
        if ($this->isRedisAvailable()) {
            if ($this->eventHistory !== []) {
                return $this->filterRecentInMemory($limit, $afterId);
            }
            if ($this->eventsRepo) {
                return $this->eventsRepo->findRecent($limit, $afterId);
            }
            return [];
        }

        // Direct DB mode (without Redis): reads are durable and immediately consistent
        // with ingress writes, so prefer DB here.
        if ($this->eventsRepo) {
            return $this->eventsRepo->findRecent($limit, $afterId);
        }

        // No DB available: return the in-memory ring buffer.
        return $this->filterRecentInMemory($limit, $afterId);
    }

    private function filterRecentInMemory(int $limit, ?int $afterId): array
    {
        $events = $this->eventHistory;

        if ($afterId !== null) {
            $events = array_values(array_filter(
                $events,
                static fn (array $event): bool => ($event['id'] ?? 0) > $afterId
            ));
        }

        if ($limit > 0 && count($events) > $limit) {
            $events = array_slice($events, -$limit);
        }

        return array_reverse($events);
    }

    public function isOnline(string $imei): bool
    {
        if (isset($this->deviceMap[$imei])) {
            return true;
        }
        if ($this->isRedisAvailable()) {
            return $this->redis->deviceGetNode($imei) !== null;
        }
        return false;
    }

    public function onlineDeviceCount(): int
    {
        return count($this->deviceMap);
    }
}
