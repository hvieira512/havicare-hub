<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use React\Http\Message\ServerRequest;
use Tests\Support\DashboardHttpTestCase;

/** Os utilizadores da balança pela API: o hub dá-lhes identificador e escreve só a diferença. */
final class ScaleUsersApiTest extends DashboardHttpTestCase
{
    private const SCALE = 'cfe908067329';

    public function testTheListIsStoredWithIdentifiersAndChangesBecomeRegistersAndDeletes(): void
    {
        $sent = [];
        $hub = $this->createMock(\Hub\Ingress\Tcp\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $_imei, string $bytes) use (&$sent): string {
            $sent[] = $bytes;
            return 'queued';
        });
        [$server, $db, $store] = $this->makeServerWithDatabase($hub);
        $db->whitelist->register(self::SCALE, 'Unique Scales', 'HC-CF632BLE', 'scale', 1001, '', '', 'hitcare');
        $store->runtime->registerDevice(self::SCALE, 'Unique Scales', 'HC-CF632BLE', 'scale', 1001, '', '', 'hitcare');
        $model = $db->models->find('Unique Scales', 'HC-CF632BLE');
        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['scale_users']);
        $token = $this->loginToken($server, 'admin', 'secret');
        $patch = fn (array $items) => json_decode((string)$server(new ServerRequest(
            'PATCH',
            '/api/devices/' . self::SCALE . '/configurations',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode(['configurations' => ['scale_users' => ['items' => $items]]], JSON_THROW_ON_ERROR)
        ))->getBody(), true);

        $first = $patch([
            ['name' => 'Hugo', 'birthDate' => '2003-05-10', 'sex' => 'male', 'heightCm' => 181, 'weightKg' => 96],
            ['name' => 'Maria', 'birthDate' => '1965-02-01', 'sex' => 'female', 'heightCm' => 158, 'weightKg' => 64],
        ]);
        $users = $first['configurationSync']['entries']['health']['scale_users']['desired']['items'] ?? [];
        self::assertCount(2, $users, json_encode($first));
        self::assertMatchesRegularExpression('/^registerScaleUser:u-[0-9a-f]{8}$/', $sent[0]);
        self::assertSame(['registerScaleUser:' . $users[0]['id'], 'registerScaleUser:' . $users[1]['id']], $sent);
        // Cada operação diz de quem é: é o que dá o estado de cada pessoa na lista.
        self::assertSame(
            [$users[0]['id'], $users[1]['id']],
            array_column($first['configurationSync']['entries']['health']['scale_users']['operations'], 'target'),
        );

        // Cada operação espera só a resposta dela: a de outra pessoa não a fecha.
        self::assertSame(
            [['torre_user_02'], ['torre_user_02']],
            array_column(array_reverse($store->commands(self::SCALE)), 'expectedReplyTypes'),
        );

        $sent = [];
        $patch([$users[0]]);
        self::assertSame(['deleteScaleUser:' . $users[1]['id']], $sent);
        self::assertSame(['torre_user_04'], $store->commands(self::SCALE)[0]['expectedReplyTypes']);
    }
}
