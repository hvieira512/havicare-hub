<?php

namespace App\Http\Controller;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use App\Registry\DeviceCapabilities;

class CommandController extends Controller
{
    public function sendCommand(string $imei, ServerRequestInterface $request): Response
    {
        $all = $this->whitelist()->all();
        if (!isset($all[$imei])) {
            return $this->errorResponse('device_not_found', 'Device not found', 404);
        }

        $model = $this->whitelist()->getModel($imei);
        $caps = $model ? DeviceCapabilities::forModel($model) : null;

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->errorResponse('invalid_request', 'Invalid JSON body', 400);
        }

        $type = trim((string)($body['type'] ?? ''));
        $data = $body['data'] ?? [];

        if ($type === '') {
            return $this->errorResponse('invalid_request', 'Command type is required', 400);
        }

        if ($caps !== null && !$caps->supportsActive($type)) {
            return $this->errorResponse('unsupported_command', "Command '$type' not supported for model '$model'", 400, [
                'supported' => $caps->getActive(),
            ]);
        }

        $requestId = bin2hex(random_bytes(8));
        $result = $this->sendCommandToDevice($imei, $type, $data, $requestId);

        if (!$result) {
            return $this->errorResponse('device_offline', 'Device is offline or not available', 503);
        }

        $deviceInfo = $this->deviceResourceInfo($imei);

        return $this->jsonResponse([
            'status' => 'sent',
            'device' => $deviceInfo,
            'command' => [
                'feature' => $caps?->featureForPassive($type) ?? null,
                'nativeType' => $type,
                'payload' => $data,
                'requestId' => $requestId,
            ],
        ]);
    }

    public function deviceFeatures(string $imei): Response
    {
        $model = $this->whitelist()->getModel($imei);
        if ($model === null) {
            return $this->errorResponse('device_not_found', 'Device not found', 404);
        }

        $caps = DeviceCapabilities::forModel($model);
        if ($caps === null) {
            return $this->errorResponse('model_not_found', "Capabilities not found for model '$model'", 404);
        }

        return $this->jsonResponse([
            'data' => [
                'model' => $model,
                'supplier' => $caps->getSupplier(),
                'protocol' => $caps->getProtocol(),
                'passive' => $caps->getPassive(),
                'active' => $caps->getActive(),
                'features' => $this->featureResources($caps->getFeatures()),
            ],
        ]);
    }

    public function sendFeatureCommand(string $imei, string $feature, ServerRequestInterface $request): Response
    {
        $model = $this->whitelist()->getModel($imei);
        if ($model === null) {
            return $this->errorResponse('device_not_found', 'Device not found', 404);
        }

        $caps = DeviceCapabilities::forModel($model);
        if ($caps === null) {
            return $this->errorResponse('model_not_found', "Capabilities not found for model '$model'", 404);
        }

        if (!$caps->supportsFeature($feature)) {
            return $this->errorResponse('unsupported_feature', "Feature '$feature' not supported for model '$model'", 400, [
                'supported' => $caps->getFeatureNames(),
            ]);
        }

        $type = $caps->resolveFeatureActiveCommand($feature);
        if ($type === null) {
            return $this->errorResponse('no_active_command', "Feature '$feature' has no active command for model '$model'", 400);
        }

        $body = json_decode((string)$request->getBody(), true);
        $data = $body['data'] ?? [];

        $requestId = bin2hex(random_bytes(8));
        $result = $this->sendCommandToDevice($imei, $type, $data, $requestId);

        if (!$result) {
            return $this->errorResponse('device_offline', 'Device is offline or not available', 503);
        }

        return $this->jsonResponse([
            'status' => 'sent',
            'command' => [
                'feature' => $feature,
                'nativeType' => $type,
                'payload' => $data,
                'requestId' => $requestId,
            ],
        ]);
    }

    public function sendCommandToDevice(string $imei, string $type, array $data = [], ?string $requestId = null): bool
    {
        if ($this->watchServer !== null) {
            return $this->watchServer->sendCommand($imei, $type, $data, $requestId);
        }

        if ($this->redis !== null) {
            $this->redis->commandPublish([
                'imei' => $imei,
                'type' => $type,
                'data' => $data,
                'requestId' => $requestId ?? '',
                'source' => 'api',
            ]);
            return true;
        }

        return false;
    }

    private function deviceResourceInfo(string $imei): ?array
    {
        $info = $this->whitelist()->all()[$imei] ?? null;
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
            'online' => $this->deviceIsOnline($imei),
            'enabled' => $info['enabled'] ?? true,
            'registeredAt' => $info['registered_at'] ?? null,
        ];
    }

    private function featureResources(array $features): array
    {
        $resources = [];
        foreach ($features as $feature => $commands) {
            $resources[] = [
                'name' => $feature,
                'passive' => array_values($commands['passive'] ?? []),
                'active' => array_values($commands['active'] ?? []),
            ];
        }

        usort($resources, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $resources;
    }
}
