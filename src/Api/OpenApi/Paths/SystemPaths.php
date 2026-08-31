<?php

namespace Hub\Api\OpenApi\Paths;

use Hub\Api\OpenApi\Parameters;
use Hub\Api\OpenApi\Requests;
use Hub\Api\OpenApi\Responses;

/**
 * Autenticação, endpoints de documentação e notificações da dashboard.
 */
final class SystemPaths
{
    public static function paths(): array
    {
        return array_merge(self::auth(), self::notifications(), self::documentation());
    }

    private static function auth(): array
    {
        return [
            '/api/auth/login' => [
                'post' => [
                    'tags' => ['System'],
                    'summary' => 'Issue bearer token for API access',
                    'security' => [],
                    'requestBody' => Requests::inline([
                        'type' => 'object',
                        'properties' => [
                            'username' => ['type' => 'string'],
                            'password' => ['type' => 'string'],
                            'refresh_token' => ['type' => 'string'],
                        ],
                        'description' => 'Provide username and password for initial login, or refresh_token to issue a new token pair.',
                    ]),
                    // A única rota cujos estados não saem do `Responses::map()`. O
                    // `AuthController::login()` responde 401 a qualquer erro, e não o estado
                    // que o código do erro declara -- um `invalid_request` por faltar a
                    // password sai daqui como 401 e não como 400. Documenta-se o que a rota
                    // faz, não o que faria se derivasse; mudar isso muda o que os clientes
                    // recebem a autenticar-se, e é decisão à parte desta.
                    'responses' => [
                        '200' => Responses::json('Bearer and refresh tokens issued', 'AuthTokenResponse'),
                        '401' => Responses::error(),
                    ],
                ],
            ],
            // O `/api/auth/stream-ticket` não está aqui de propósito: existe só para a
            // dashboard abrir o seu stream, que também não é público. Ver a lista de rotas
            // internas no `OpenApiSpecRoutesTest`.
        ];
    }

    private static function notifications(): array
    {
        return [
            '/api/notifications' => [
                'get' => [
                    'tags' => ['Notifications'],
                    'summary' => 'List dashboard notifications',
                    'parameters' => [
                        Parameters::query('limit', ['type' => 'integer', 'default' => 20, 'maximum' => 100]),
                    ],
                    'responses' => [
                        '200' => Responses::json(
                            'Latest dashboard notifications and global unread count',
                            'DashboardNotificationListResponse',
                        ),
                    ],
                ],
            ],
            '/api/notifications/read' => [
                'patch' => [
                    'tags' => ['Notifications'],
                    'summary' => 'Mark dashboard notifications as globally read',
                    'requestBody' => Requests::json('DashboardNotificationReadRequest'),
                    'responses' => Responses::map(
                        ['200' => Responses::json('Notifications marked read', 'DashboardNotificationReadResponse')],
                        'invalid_request',
                    ),
                ],
            ],
            '/api/notifications/{id}' => [
                'delete' => [
                    'tags' => ['Notifications'],
                    'summary' => 'Delete a dashboard notification',
                    'parameters' => [
                        Parameters::pathSchema('id', ['type' => 'integer', 'minimum' => 1]),
                    ],
                    // O 400 é o id que não é um inteiro positivo, recusado antes de se ir
                    // procurar a notificação.
                    'responses' => Responses::map(
                        ['200' => Responses::json('Notification deleted', 'DashboardNotificationReadResponse')],
                        'invalid_request',
                        'notification_not_found',
                    ),
                ],
            ],
        ];
    }

    private static function documentation(): array
    {
        return [
            '/api/openapi.json' => [
                'get' => [
                    'tags' => ['System'],
                    'summary' => 'OpenAPI specification',
                    'security' => [],
                    'responses' => ['200' => ['description' => 'OpenAPI document']],
                ],
            ],
            '/api/docs' => [
                'get' => [
                    'tags' => ['System'],
                    'summary' => 'Swagger UI',
                    'security' => [],
                    'responses' => ['200' => ['description' => 'Swagger UI page']],
                ],
            ],
        ];
    }
}
