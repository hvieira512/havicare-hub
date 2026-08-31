<?php

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\ProtocolDashboardMeta;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\ProtocolRegistry;

class ProtocolService
{
    public function list(): array
    {
        return [
            // O domínio diz o que o protocolo é; a camada de apresentação diz o que a
            // dashboard precisa para o desenhar. A resposta junta as duas e mantém a forma
            // que sempre teve.
            'data' => array_map(
                static fn(string $protocol): array => ProtocolRegistry::describe($protocol) + [
                    'dashboard' => ProtocolDashboardMeta::forProtocol($protocol),
                ],
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
