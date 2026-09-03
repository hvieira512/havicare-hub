<?php

namespace Hub\Api\OpenApi\Paths;

use Hub\Api\OpenApi\Parameters;
use Hub\Api\OpenApi\Responses;

/**
 * O stream de um inquilino inteiro.
 *
 * Ao contrário do stream de um dispositivo, este é público: é a via pela qual uma aplicação
 * externa lê em tempo real o que o MQTT transporta da sua empresa e licença, sem precisar de
 * uma credencial de broker no código do cliente.
 */
final class StreamPaths
{
    public static function paths(): array
    {
        return [
            '/api/stream' => [
                'get' => [
                    'tags' => ['Devices'],
                    'summary' => 'Stream this tenant\'s device messages as server-sent events',
                    'description' => <<<'TEXT'
                        Delivers every message the hub publishes for the caller's own company and
                        licence, as `text/event-stream`. The scope comes from the token and cannot
                        be widened by any parameter, so a licence client only ever receives its own
                        devices.

                        Each event name is the channel (`telemetry`, `events`, `status`). The `data`
                        line is one JSON object per message: the fields that live in the MQTT topic
                        are restored at the root, and `payload` is byte-for-byte the same document
                        published to MQTT, so existing MQTT parsing can be reused unchanged.

                        The `raw` channel is not served here — it carries unparsed vendor frames for
                        debugging a single device, not a tenant-wide feed.

                        There is no history and no `id:` field: server-sent event resumption via
                        `Last-Event-ID` would promise a replay buffer that does not exist. Fetch the
                        current state from `GET /api/devices` after opening the stream.

                        `EventSource` cannot set headers; those clients pass a single-use ticket from
                        `POST /api/auth/stream-ticket` as `?ticket=`. Any client that can set headers
                        should send `Authorization` instead and skip the ticket entirely.
                        TEXT,
                    'parameters' => [
                        Parameters::query('channels', [
                            'type' => 'string',
                            'default' => 'telemetry,events,status',
                            'example' => 'telemetry,events',
                            'description' => 'Comma-separated subset of the served channels. Narrowing this is how a client avoids paying for traffic it does not read.',
                        ]),
                    ],
                    'responses' => Responses::map(
                        [
                            '200' => Responses::content(
                                'An open event stream of this tenant\'s messages',
                                [
                                    'type' => 'object',
                                    'required' => ['topic', 'company', 'licenseId', 'deviceType', 'deviceId', 'channel', 'payload'],
                                    'properties' => [
                                        'topic' => [
                                            'type' => 'string',
                                            'example' => 'havicare-hub/hitcare/1001/watch/861265061009822/telemetry',
                                        ],
                                        'company' => ['type' => 'string', 'example' => 'hitcare'],
                                        'licenseId' => ['type' => 'integer', 'example' => 1001],
                                        'deviceType' => ['type' => 'string', 'example' => 'watch'],
                                        'deviceId' => ['type' => 'string', 'example' => '861265061009822'],
                                        'channel' => [
                                            'type' => 'string',
                                            'enum' => ['telemetry', 'events', 'status'],
                                        ],
                                        'payload' => [
                                            'type' => 'object',
                                            'description' => 'The MQTT document, unchanged. Its shape depends on the channel.',
                                            'additionalProperties' => true,
                                        ],
                                    ],
                                ],
                                'text/event-stream',
                            ),
                        ],
                        'invalid_request',
                        'too_many_streams',
                    ),
                ],
            ],
        ];
    }
}
