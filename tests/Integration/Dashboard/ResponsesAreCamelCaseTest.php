<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Tests\Support\DashboardHttpTestCase;

/**
 * Os nomes de campo da API são camelCase. As chaves que são valores — capacidades, configurações,
 * facetas — e os campos do token congelados pelo hitCare ficam de fora.
 */
final class ResponsesAreCamelCaseTest extends DashboardHttpTestCase
{
    /** Mapas cujas chaves são identificadores do domínio, e não nomes de campo. */
    private const VALUE_KEYED = [
        'capabilities',
        'configurations',
        'effectiveConfigurations',
        'entries',
        'available',
        'counts',
        'codes',
        '_meta',
    ];

    /** hitcare: o token de login sai com estes nomes enquanto o hitCare os ler assim. */
    private const FROZEN = ['access_token', 'expires_in', 'refresh_token', 'refresh_expires_in'];

    public function testNoResponseCarriesASnakeCaseFieldName(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $db->denylist->add('860000000000001', 'tcp', null, 'admin');
        $db->whitelist->register('594B3CF100A7', 'Qinglanst', 'HC2', 'radar', 1001, '', '594B3CF100A7', 'hitcare');
        $db->radarLayouts->store('594B3CF100A7', [
            'room' => ['xMinDm' => -20, 'yMinDm' => 0, 'xMaxDm' => 20, 'yMaxDm' => 30],
            'areas' => [['key' => 0, 'type' => 'monitoring_bed', 'name' => 'Cama', 'xMinDm' => -5, 'yMinDm' => 5, 'xMaxDm' => 5, 'yMaxDm' => 20]],
        ], '2026-10-21 10:00:00');
        $db->whitelist->register('C80000000001', 'MOKO', 'MKGW', 'gateway', 1001, '', '', 'hitcare');
        $db->gatewayDeviceLinks->upsert('C80000000001', '861265061009822');
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);
        $login = $this->loginPayload($server, 'admin', 'secret');
        $token = (string)$login['token']['access_token'];

        $responses = ['POST /api/auth/login' => $login];
        foreach (
            [
                '/api/licenses',
                '/api/companies',
                '/api/users',
                '/api/suppliers',
                '/api/denylist',
                '/api/models',
                '/api/models/' . $model['id'],
                '/api/models/template?supplierId=' . $model['supplier_id'] . '&deviceType=watch',
                '/api/device-types/suppliers',
                '/api/device-types/suppliers/models',
                '/api/devices',
                '/api/devices/861265061009822',
                '/api/devices/861265061009822/links',
                '/api/devices/594B3CF100A7/radar-layout',
                '/api/licenses/1/radar-credentials',
            ] as $path
        ) {
            $response = $server(new ServerRequest('GET', $path, ['Authorization' => "Bearer {$token}"]));
            $responses["GET {$path}"] = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        }

        $snakeCase = [];
        foreach ($responses as $route => $body) {
            self::collectSnakeCase($body, $route, $snakeCase);
        }

        self::assertSame([], $snakeCase);
    }

    /** @param list<string> $found */
    private static function collectSnakeCase(mixed $value, string $path, array &$found): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $name = (string)$key;
            if (!is_int($key) && str_contains($name, '_') && !in_array($name, self::FROZEN, true)) {
                $found[] = "{$path}.{$name}";
            }
            // O nome de uma coluna e o parâmetro que a filtra também são nomes de campo.
            if (in_array($name, ['field', 'param'], true) && is_string($child) && str_contains($child, '_')) {
                $found[] = "{$path}.{$name}={$child}";
            }
            if (!in_array($name, self::VALUE_KEYED, true)) {
                self::collectSnakeCase($child, "{$path}.{$name}", $found);
            }
        }
    }
}
