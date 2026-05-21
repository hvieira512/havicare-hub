<?php

namespace App\Services;

use App\Log\Logger;
use App\Redis\Client as RedisClient;
use App\Registry\DeviceCapabilities;
use App\Registry\Whitelist;
use App\WebSocket\WatchServer;

class CommandService
{
    private Whitelist $whitelist;
    private ?WatchServer $watchServer;
    private ?RedisClient $redis;
    /** @var callable(string):bool */
    private $onlineResolver;

    public function __construct(
        Whitelist $whitelist,
        ?WatchServer $watchServer,
        ?RedisClient $redis,
        ?callable $onlineResolver = null,
    ) {
        $this->whitelist = $whitelist;
        $this->watchServer = $watchServer;
        $this->redis = $redis;
        $this->onlineResolver = $onlineResolver ?? static fn(string $imei): bool => false;
    }

    public function sendCommand(string $imei, array $body): array
    {
        $all = $this->whitelist->all();
        if (!isset($all[$imei])) {
            throw new ServiceException('device_not_found', 'Device not found', 404);
        }

        $model = $this->whitelist->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;

        $type = trim((string)($body['type'] ?? ''));
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if ($type === '') {
            throw new ServiceException('invalid_request', 'Command type is required', 400);
        }

        if ($caps !== null && !$caps->supportsActive($type)) {
            throw new ServiceException(
                'unsupported_command',
                "Command '$type' not supported for model '$model'",
                400,
                ['supported' => $caps->getActive()]
            );
        }

        $requestId = $this->newRequestId();
        $result = $this->dispatchCommandToDevice($imei, $type, $data, $requestId);

        if (!$result) {
            throw new ServiceException('device_offline', 'Device is offline or not available', 503);
        }

        return [
            'status' => 'sent',
            'device' => $this->deviceResourceInfo($imei),
            'command' => [
                'feature' => $caps?->featureForPassive($type) ?? null,
                'nativeType' => $type,
                'payload' => $data,
                'requestId' => $requestId,
            ],
        ];
    }

    public function deviceFeatures(string $imei): array
    {
        $model = $this->whitelist->getModel($imei);
        if ($model === null) {
            throw new ServiceException('device_not_found', 'Device not found', 404);
        }

        $caps = DeviceCapabilities::forModel($model);
        if ($caps === null) {
            throw new ServiceException('model_not_found', "Capabilities not found for model '$model'", 404);
        }

        return [
            'data' => [
                'model' => $model,
                'supplier' => $caps->getSupplier(),
                'protocol' => $caps->getProtocol(),
                'passive' => $caps->getPassive(),
                'active' => $caps->getActive(),
                'features' => $this->featureResources($caps),
                'commandMetadata' => $caps->getCommandMetadata(),
                'nativeMappings' => $caps->getNativeMappings(),
                'commandStateHints' => [
                    'dispatched' => 'Command queued/sent to active device session.',
                    'ack' => 'Device replied and command was acknowledged.',
                    'timeout' => 'Device did not reply within timeout window.',
                    'failed' => 'Command dispatch failed before device delivery.',
                ],
            ],
        ];
    }

    public function sendFeatureCommand(string $imei, string $feature, array $body): array
    {
        $model = $this->whitelist->getModel($imei);
        if ($model === null) {
            throw new ServiceException('device_not_found', 'Device not found', 404);
        }

        $caps = DeviceCapabilities::forModel($model);
        if ($caps === null) {
            throw new ServiceException('model_not_found', "Capabilities not found for model '$model'", 404);
        }

        if (!$caps->supportsFeature($feature)) {
            throw new ServiceException(
                'unsupported_feature',
                "Feature '$feature' not supported for model '$model'",
                400,
                ['supported' => $caps->getFeatureNames()]
            );
        }

        $type = $caps->resolveFeatureActiveCommand($feature);
        if ($type === null) {
            throw new ServiceException('no_active_command', "Feature '$feature' has no active command for model '$model'", 400);
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $requestId = $this->newRequestId();
        $result = $this->dispatchCommandToDevice($imei, $type, $data, $requestId, $feature);

        if (!$result) {
            throw new ServiceException('device_offline', 'Device is offline or not available', 503);
        }

        return [
            'status' => 'sent',
            'command' => [
                'feature' => $feature,
                'nativeType' => $type,
                'payload' => $data,
                'requestId' => $requestId,
            ],
        ];
    }

    public function measureFeature(string $imei, string $feature, array $body = []): array
    {
        $result = $this->sendFeatureCommand($imei, $feature, $body);

        $requestId = (string)($result['command']['requestId'] ?? '');
        $nativeType = (string)($result['command']['nativeType'] ?? '');
        $payload = is_array($result['command']['payload'] ?? null) ? $result['command']['payload'] : [];
        $requestedAt = (int)round(microtime(true) * 1000);

        return [
            'status' => 'requested',
            'measurement' => [
                'feature' => $feature,
                'nativeType' => $nativeType,
                'requestId' => $requestId,
                'requestedAt' => $requestedAt,
                'payload' => $payload,
            ],
            'poll' => [
                'latestFeaturePath' => "/devices/{$imei}/features/{$feature}/latest",
            ],
        ];
    }

    public function dispatchCommandToDevice(
        string $imei,
        string $type,
        array $data = [],
        ?string $requestId = null,
        ?string $feature = null,
    ): bool {
        if ($this->watchServer !== null) {
            return $this->watchServer->sendCommand($imei, $type, $data, $requestId, $feature);
        }

        if ($this->redis !== null) {
            $streamId = $this->redis->commandPublish([
                'imei' => $imei,
                'type' => $type,
                'data' => $data,
                'requestId' => $requestId ?? '',
                'feature' => $feature ?? '',
                'source' => 'api',
            ]);
            if ($streamId !== '') {
                return true;
            }

            Logger::channel('api')->error("command enqueue failed: IMEI={$imei}, type={$type}, requestId=" . ($requestId ?? ''));
            return false;
        }

        return false;
    }

    public function commandStatePayload(
        string $imei,
        string $state,
        string $type,
        ?string $feature,
        string $requestId,
        ?string $ident,
        string $reason,
        ?string $protocol,
        ?int $timestamp = null,
    ): array {
        return [
            'imei' => $imei,
            'state' => $state,
            'type' => $type,
            'feature' => $feature ?? '',
            'requestId' => $requestId,
            'ident' => $ident ?? '',
            'reason' => $reason,
            'protocol' => $protocol ?? '',
            'timestamp' => $timestamp ?? (int)round(microtime(true) * 1000),
        ];
    }

    public function newRequestId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function deviceResourceInfo(string $imei): ?array
    {
        $info = $this->whitelist->all()[$imei] ?? null;
        if ($info === null) {
            return null;
        }

        $caps = DeviceCapabilities::forModel($info['model'] ?? '');

        return [
            'imei' => $imei,
            'model' => $info['model'] ?? null,
            'supplier' => $caps?->getSupplier(),
            'protocol' => $caps?->getProtocol(),
            'transport' => $caps?->getTransport(),
            'online' => ($this->onlineResolver)($imei),
            'enabled' => $info['enabled'] ?? true,
            'registeredAt' => $info['registered_at'] ?? null,
        ];
    }

    private function featureResources(DeviceCapabilities $caps): array
    {
        $features = $caps->getFeatures();
        $resources = [];
        $allMetadata = $caps->getCommandMetadata();

        foreach ($features as $feature => $commands) {
            $passive = array_values($commands['passive'] ?? []);
            $active = array_values($commands['active'] ?? []);
            $resources[] = [
                'name' => $feature,
                'passive' => $passive,
                'active' => $active,
                'passiveDetails' => array_values(array_filter(array_map(
                    static fn(string $type): ?array => $allMetadata[$type] ?? null,
                    $passive
                ))),
                'activeDetails' => array_values(array_filter(array_map(
                    static fn(string $type): ?array => $allMetadata[$type] ?? null,
                    $active
                ))),
            ];
        }

        usort($resources, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $resources;
    }
}
