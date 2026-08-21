<?php

namespace Hub\Api\OpenApi\Paths;

use Hub\Api\OpenApi\Parameters;
use Hub\Api\OpenApi\Requests;
use Hub\Api\OpenApi\Responses;

/**
 * Device registration, configuration, links, telemetry and commands.
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
                    'parameters' => array_merge(Parameters::pagination(5), [
                        Parameters::stringQuery('deviceType'),
                        Parameters::stringQuery('licenseId'),
                        Parameters::stringQuery('supplier'),
                        Parameters::stringQuery('model'),
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
            '/api/devices/{imei}/diaper-sensitivity' => [
                'get' => [
                    'tags' => [self::TAG],
                    'summary' => 'Read the alert sensitivity of a diaper sensor',
                    'parameters' => [$imei],
                    'responses' => [
                        '200' => Responses::json('Diaper sensor sensitivity', 'DiaperSensitivityResponse'),
                        '400' => Responses::error(),
                        '404' => Responses::error(),
                    ],
                ],
                'put' => [
                    'tags' => [self::TAG],
                    'summary' => 'Set the alert sensitivity of a diaper sensor',
                    'description' => 'Applied by the hub when deriving the diaper condition. Nothing is sent to '
                        . 'the sensor, which is a non-connectable BLE beacon, so there is no delivery to confirm.',
                    'parameters' => [$imei],
                    'requestBody' => Requests::json('DiaperSensitivityRequest'),
                    'responses' => [
                        '200' => Responses::json('Diaper sensor sensitivity', 'DiaperSensitivityResponse'),
                        '400' => Responses::error(),
                        '404' => Responses::error(),
                    ],
                ],
                'delete' => [
                    'tags' => [self::TAG],
                    'summary' => 'Reset a diaper sensor to the default sensitivity',
                    'parameters' => [$imei],
                    'responses' => [
                        '200' => Responses::json('Diaper sensor sensitivity', 'DiaperSensitivityResponse'),
                        '400' => Responses::error(),
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
            '/api/devices/{imei}/stream' => [
                'get' => [
                    'tags' => [self::TAG],
                    'summary' => 'Open a server-sent events stream for recent device activity',
                    'parameters' => [$imei],
                    'responses' => [
                        '200' => Responses::content(
                            'SSE stream emitting snapshot and update events',
                            Responses::ref('DeviceStreamResponse'),
                            'text/event-stream',
                        ),
                        '403' => Responses::error(),
                        '404' => Responses::error(),
                    ],
                ],
            ],
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
