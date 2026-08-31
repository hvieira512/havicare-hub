<?php

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\ProtocolRegistry;

class ProtocolService
{
    public function list(): array
    {
        return [
            'data' => array_map(
                static fn(string $protocol): array => ProtocolRegistry::describe($protocol),
                ProtocolRegistry::keys()
            ),
        ];
    }

    public function configCatalog(array $params): array
    {
        $protocol = (string)($params['protocol'] ?? '');

        if (!ProtocolRegistry::exists($protocol) || !ProtocolRegistry::supportsConfigCatalog($protocol)) {
            return ApiError::protocolNotFound()->toArray();
        }

        $catalog = array_map(
            fn(array $entry): array => $entry + ['capabilityKey' => CapabilityCatalog::mapConfigurationKey($entry['key'] ?? '')],
            DeviceConfigurationCatalog::configsForProtocol($protocol)
        );

        return ['data' => $catalog];
    }
}
