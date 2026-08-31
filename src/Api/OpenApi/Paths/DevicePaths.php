<?php

namespace Hub\Api\OpenApi\Paths;

use Hub\Api\OpenApi\Parameters;
use Hub\Api\OpenApi\Requests;
use Hub\Api\OpenApi\Responses;

/**
 * Registo, configuração, ligações, telemetria e comandos de dispositivos.
 */
final class DevicePaths
{
    private const TAG = 'Devices';

    public static function paths(): array
    {
        $imei = Parameters::imei();
        $linkedImei = Parameters::linkedImei();

        return [
            '/api/devices' => [
                'get' => [
                    'tags' => [self::TAG],
                    'summary' => 'List devices',
                    // Os filtros de conjunto aceitam vários valores, como `deviceType[]=a&
                    // deviceType[]=b` ou `deviceType=a,b`. `license` escolhe pares empresa e
                    // licença -- `empresa`, `empresa:número`, ou `none` para os dispositivos
                    // sem uma nem outra -- porque uma licença pertence sempre a uma empresa.
                    // `company` e `licenseId` são a forma anterior e continuam a funcionar.
                    'parameters' => array_merge(Parameters::pagination(5), [
                        Parameters::stringList('deviceType'),
                        Parameters::stringList('supplier'),
                        Parameters::stringList('model'),
                        Parameters::stringList('license'),
                        Parameters::query('online', ['type' => 'string', 'enum' => ['online', 'offline']]),
                        Parameters::stringQuery('company'),
                        Parameters::stringQuery('licenseId'),
                        Parameters::query('q', ['type' => 'string', 'default' => '']),
                    ]),
                    'responses' => [
                        '200' => Responses::json('Paginated device collection', 'DeviceListResponse'),
                    ],
                ],
                'post' => [
                    'tags' => [self::TAG],
                    'summary' => 'Register device',
                    'requestBody' => Requests::json('DeviceCreateRequest'),
                    'responses' => [
                        '201' => Responses::json('Device registered', 'DeviceCreateResponse'),
                        '400' => Responses::error(),
                        '409' => Responses::error(),
                    ],
                ],
            ],
            '/api/devices/{imei}' => [
                'get' => [
                    'tags' => [self::TAG],
                    'summary' => 'Get device detail',
                    'parameters' => [$imei],
                    'responses' => [
                        '200' => Responses::json(
                            'Device detail with desired/effective configuration and synchronization lifecycle',
                            'DeviceDetailResponse',
                        ),
                        '400' => Responses::error(),
                        '403' => Responses::error(),
                        '404' => Responses::error(),
                    ],
                ],
                'put' => [
                    'tags' => [self::TAG],
                    'summary' => 'Update device metadata',
                    'parameters' => [$imei],
                    'requestBody' => Requests::json('DeviceUpdateRequest'),
                    'responses' => [
                        '200' => Responses::json('Device updated', 'DeviceUpdateResponse'),
                        '400' => Responses::error(),
                        '403' => Responses::error(),
                        '409' => Responses::error(),
                    ],
                ],
                'delete' => [
                    'tags' => [self::TAG],
                    'summary' => 'Delete device (unregister from whitelist)',
                    'parameters' => [$imei],
                    'responses' => [
                        '200' => Responses::json('Device deleted', 'DeviceDeleteResponse'),
                    ],
                ],
            ],
            '/api/devices/{imei}/configurations' => [
                'patch' => [
                    'tags' => [self::TAG],
                    'summary' => 'Partially update device configurations',
                    'parameters' => [$imei],
                    'requestBody' => Requests::json('DeviceConfigurationsUpdateRequest'),
                    'responses' => [
                        '200' => Responses::json('Device configurations updated', 'DeviceConfigurationsUpdateResponse'),
                        '400' => Responses::error(),
                        '403' => Responses::error(),
                    ],
                ],
            ],
            '/api/devices/{imei}/links' => [
                'get' => [
                    'tags' => [self::TAG],
                    'summary' => 'List devices linked to a gateway or sensor',
                    'parameters' => [$imei],
                    'responses' => [
                        '200' => Responses::json('Linked devices', 'DeviceLinkListResponse'),
                        '404' => Responses::error(),
                    ],
                ],
            ],
            '/api/devices/{imei}/links/{linkedImei}' => [
                'post' => [
                    'tags' => [self::TAG],
                    'summary' => 'Link a diaper sensor to a gateway',
                    'parameters' => [$imei, $linkedImei],
                    'responses' => [
                        '201' => Responses::json('Device link created', 'DeviceLinkMutationResponse'),
                        '400' => Responses::error(),
                        '404' => Responses::error(),
                    ],
                ],
                'delete' => [
                    'tags' => [self::TAG],
                    'summary' => 'Remove a gateway-device link',
                    'parameters' => [$imei, $linkedImei],
                    'responses' => [
                        '200' => Responses::json('Device link removed', 'DeviceLinkMutationResponse'),
                        '404' => Responses::error(),
                    ],
                ],
            ],
            '/api/devices/{imei}/requests' => [
                'post' => [
                    'tags' => [self::TAG],
                    'summary' => 'Request generic telemetry feature from device',
                    'parameters' => [$imei],
                    'requestBody' => Requests::json('TelemetryRequest'),
                    'responses' => [
                        '200' => Responses::json('Telemetry request result', 'TelemetryRequestResponse'),
                        '400' => Responses::error(),
                    ],
                ],
            ],
            '/api/devices/{imei}/association' => [
                'patch' => [
                    'tags' => [self::TAG],
                    'summary' => 'Associate a registered device to a company and license',
                    'parameters' => [$imei],
                    'requestBody' => Requests::json('DeviceAssociationRequest'),
                    'responses' => [
                        '200' => Responses::json(
                            'Device association updated. If the company exists but the requested license does not, the license is created automatically for that company.',
                            'DeviceAssociationResponse',
                        ),
                        '400' => Responses::error(),
                        '403' => Responses::error(),
                        '404' => Responses::error(),
                    ],
                ],
                'delete' => [
                    'tags' => [self::TAG],
                    'summary' => 'Remove the current company and license association from a device',
                    'parameters' => [$imei],
                    'responses' => [
                        '200' => Responses::json('Device association removed', 'DeviceAssociationResponse'),
                        '400' => Responses::error(),
                        '404' => Responses::error(),
                    ],
                ],
            ],
            // O `/api/devices/{imei}/stream` não está aqui de propósito: serve a dashboard e
            // mais ninguém. Ver a lista de rotas internas no `OpenApiSpecRoutesTest`.
            '/api/commands/{id}' => [
                'get' => [
                    'tags' => [self::TAG],
                    'summary' => 'Get command lifecycle by command ID',
                    'parameters' => [Parameters::id('Command ID', 'string', '9f395f4f04fe589e')],
                    'responses' => [
                        '200' => Responses::json('Command detail with associated device', 'CommandStatusResponse'),
                        '404' => Responses::error(),
                    ],
                ],
            ],
        ];
    }
}
