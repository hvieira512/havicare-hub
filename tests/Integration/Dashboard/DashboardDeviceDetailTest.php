<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Device\RedisPendingDownlinkQueue;
use Tests\Support\ConfigurationFixtures;
use Tests\Support\DashboardHttpTestCase;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\WavFixture;

/** A forma do detalhe de um dispositivo, comparada por estruturas inteiras em vez de chave a chave. */
final class DashboardDeviceDetailTest extends DashboardHttpTestCase
{
    public function testDeviceDetailEndpointReturnsSparseCapabilitiesAndStoredValues(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], [
            'heart_rate',
            'location',
            'call_whitelist',
            'whitelist_enabled',
        ]);
        ConfigurationFixtures::desired(
            $db,
            '861265061009822',
            'call_whitelist',
            'vivistar',
            'CALL_WHITELIST',
            ['fields' => ['|+351922222222']]
        );
        ConfigurationFixtures::desired(
            $db,
            '861265061009822',
            'whitelist_enabled',
            'vivistar',
            'WHITELIST_ENABLED',
            ['enabled' => true]
        );

        $token = $this->loginToken($server, 'tenant', 'tenant-secret');
        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            ['supported' => true, 'requestable' => true],
            $body['capabilities']['telemetry']['heart_rate'] ?? null
        );
        self::assertSame(
            ['supported' => true, 'requestable' => true],
            $body['capabilities']['telemetry']['location'] ?? null
        );
        self::assertSame([], $body['capabilities']['alarms'] ?? null);
        self::assertSame(
            [
                ['name' => '', 'phone' => '+351922222222'],
            ],
            $body['capabilities']['contacts']['call_whitelist']['value'] ?? null
        );
        self::assertSame(10, $body['capabilities']['contacts']['call_whitelist']['_meta']['limit'] ?? null);
        self::assertArrayNotHasKey(
            'maxLength',
            $body['capabilities']['contacts']['call_whitelist']['_meta']['name'] ?? []
        );
        self::assertArrayNotHasKey(
            'maxLength',
            $body['capabilities']['contacts']['call_whitelist']['_meta']['phone'] ?? []
        );
        self::assertTrue($body['capabilities']['contacts']['call_whitelist']['_meta']['phone']['asciiOnly'] ?? false);
        self::assertTrue($body['capabilities']['contacts']['whitelist_enabled']['value']['enabled'] ?? false);
        self::assertArrayNotHasKey('_nativeKey', $body['capabilities']['contacts']['whitelist_enabled']);
        self::assertArrayNotHasKey('blood_pressure', $body['capabilities']['telemetry'] ?? []);
        self::assertArrayNotHasKey('auto_vitals_interval', $body['capabilities']['health'] ?? []);
        self::assertSame('never_reported', $body['configurationSync']['entries']['contacts']['call_whitelist']['status'] ?? null);
        self::assertSame('never_reported', $body['configurationSync']['entries']['contacts']['whitelist_enabled']['status'] ?? null);
        self::assertArrayNotHasKey('pending', $body);
        self::assertArrayNotHasKey('transportPending', $body);
    }

    public function testDeviceDetailAndGenericConfigurationPutExposeNewPendingShape(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\Device\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue('861265061009822', 'IWBP76,...', ['command' => 'BP76'], 300);
        [$server, $db] = $this->makeServerWithDatabase($hub, $queue);
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_detection']);
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $put = $server(new ServerRequest(
            'PATCH',
            '/api/devices/861265061009822/configurations',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode([
                'configurations' => [
                    'fall_detection' => ['enabled' => true],
                ],
            ], JSON_THROW_ON_ERROR)
        ));
        $putBody = json_decode((string)$put->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $put->getStatusCode(), (string)$put->getBody());
        self::assertCount(1, $submitted);
        self::assertStringContainsString('BP76', $submitted[0]['bytes']);
        self::assertSame('awaiting_ack', $putBody['configurationSync']['entries']['alarms']['fall_detection']['status'] ?? null);
        self::assertArrayNotHasKey('transportPending', $putBody);

        $detail = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $detailBody = json_decode((string)$detail->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $detail->getStatusCode(), (string)$detail->getBody());
        self::assertSame(
            ['value' => ['enabled' => true], '_meta' => []],
            $detailBody['capabilities']['alarms']['fall_detection'] ?? null
        );
        self::assertSame('awaiting_ack', $detailBody['configurationSync']['entries']['alarms']['fall_detection']['status'] ?? null);
        self::assertArrayNotHasKey('transportPending', $detailBody);
    }

    /** Um aparelho que perdeu a configuração recebe-a de novo: o `resync` não confia no registo do hub. */
    public function testResyncSendsAnUnchangedValueAgain(): void
    {
        $submitted = 0;
        $hub = $this->createMock(\Hub\Device\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function () use (&$submitted): string {
            $submitted++;
            return 'sent';
        });
        [$server, $db] = $this->makeServerWithDatabase($hub);
        $model = $db->models->find('Vivistar', 'L08 Pro');
        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_detection']);
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');
        $patch = fn (array $value) => $server(new ServerRequest(
            'PATCH',
            '/api/devices/861265061009822/configurations',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode(['configurations' => ['fall_detection' => $value]], JSON_THROW_ON_ERROR)
        ));

        $patch(['enabled' => true]);
        $patch(['enabled' => true]);
        self::assertSame(1, $submitted);

        $resync = $patch(['enabled' => true, 'resync' => true]);
        self::assertSame(200, $resync->getStatusCode(), (string)$resync->getBody());
        self::assertSame(2, $submitted);
    }

    public function testDeviceDetailExposesTakePillsMetaForFourPTouch(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);
        ConfigurationFixtures::desired(
            $db,
            '868017032159118',
            'takePills',
            'four-p-touch',
            'TAKEPILLS',
            [
                'reminderSettings' => [['time' => '11:25', 'enabled' => true, 'frequency' => 3, 'custom' => '1010101']],
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => 'data:audio/wav;base64,' . WavFixture::silenceBase64(),
                'voiceMimeType' => 'audio/wav',
            ]
        );

        $token = $this->loginToken($server, 'tenant', 'tenant-secret');
        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/868017032159118',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            [
                'plans' => [
                    ['times' => [[
                        'time' => '11:25',
                        'enabled' => true,
                        'recurrence' => ['kind' => 'custom', 'days' => [7, 2, 4, 6]],
                    ]]],
                ],
                'reminderText' => 'meds',
                'voiceData' => 'data:audio/wav;base64,' . WavFixture::silenceBase64(),
                'voiceMimeType' => 'audio/wav',
            ],
            $body['capabilities']['reminders']['medication_reminders']['value'] ?? null
        );
        self::assertArrayNotHasKey('_nativeKey', $body['capabilities']['reminders']['medication_reminders']);
        self::assertSame(3, $body['capabilities']['reminders']['medication_reminders']['_meta']['limit'] ?? null);
        self::assertArrayNotHasKey('frequency', $body['capabilities']['reminders']['medication_reminders']['_meta']);
    }

    public function testDeviceDetailExposesMultipleTakePillsRemindersForFourPTouch(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);
        ConfigurationFixtures::desired(
            $db,
            '868017032159118',
            'takePills',
            'four-p-touch',
            'TAKEPILLS',
            [
                'reminderSettings' => [
                    ['time' => '11:25', 'enabled' => true, 'frequency' => 2, 'custom' => ''],
                    ['time' => '14:30', 'enabled' => false, 'frequency' => 1, 'custom' => ''],
                    ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '1010101'],
                ],
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => '',
                'voiceMimeType' => '',
            ]
        );

        $token = $this->loginToken($server, 'tenant', 'tenant-secret');
        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/868017032159118',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            [
                'plans' => [
                    ['times' => [['time' => '11:25', 'enabled' => true, 'recurrence' => ['kind' => 'daily']]]],
                    ['times' => [['time' => '14:30', 'enabled' => false, 'recurrence' => ['kind' => 'once']]]],
                    ['times' => [[
                        'time' => '18:00',
                        'enabled' => true,
                        'recurrence' => ['kind' => 'custom', 'days' => [7, 2, 4, 6]],
                    ]]],
                ],
                'reminderText' => 'meds',
                'voiceData' => '',
                'voiceMimeType' => '',
            ],
            $body['capabilities']['reminders']['medication_reminders']['value'] ?? null
        );
        self::assertArrayNotHasKey('_nativeKey', $body['capabilities']['reminders']['medication_reminders']);
    }
}
