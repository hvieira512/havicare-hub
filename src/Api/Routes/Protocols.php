<?php

namespace Hub\Api\Routes;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Dashboard\GenericModelCapabilityCatalog;
use Psr\Http\Message\ServerRequestInterface;

final class Protocols
{
    private const SUPPORTED_PROTOCOLS = ['wonlex-json', 'vivistar-iw', 'four-p-touch'];

    public function configCatalog(ServerRequestInterface $request, array $params): array
    {
        $protocol = (string)($params['protocol'] ?? '');

        if (!in_array($protocol, self::SUPPORTED_PROTOCOLS, true)) {
            return ['error' => ['code' => 'protocol_not_found', 'message' => 'Unsupported protocol']];
        }

        $catalog = array_map(
            fn(array $entry): array => $entry + ['capabilityKey' => GenericModelCapabilityCatalog::mapConfigurationKey($entry['key'] ?? '')],
            DeviceConfigurationCatalog::configsForProtocol($protocol)
        );

        return ['data' => $catalog];
    }
}
