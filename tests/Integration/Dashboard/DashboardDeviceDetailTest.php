<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Device\PendingDownlink;
use Hub\Device\PendingDownlinkQueue;
use Tests\Support\DashboardHttpTestCase;
use Tests\Support\Doubles\WavFixture;

/**
 * A forma do detalhe de um dispositivo: as capacidades que ele declara, os valores guardados,
 * e o estado de sincronização de cada configuração.
 *
 * É a resposta mais larga da API e a que mais clientes lêem campo a campo, e por isso os
 * testes comparam estruturas inteiras em vez de espreitarem uma chave.
 */
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
            'device_password',
        ]);
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'call_whitelist',
            'vivistar',
            'Vivistar',
            'L08 Pro',
            'CALL_WHITELIST',
            ['fields' => ['|+351922222222']]
        );
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'whitelist_enabled',
            'vivistar',
            'Vivistar',
            'L08 Pro',
            'WHITELIST_ENABLED',
            ['enabled' => true]
        );
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'devicePassword',
            'four-p-touch',
            'Vivistar',
            'L08 Pro',
            'PASSWORD',
            ['password' => '2468']
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
        self::assertSame(
            ['password' => '2468'],
            $body['capabilities']['settings_system']['device_password']['value'] ?? null
        );
        self::assertArrayNotHasKey('blood_pressure', $body['capabilities']['telemetry'] ?? []);
        self::assertArrayNotHasKey('auto_vitals_interval', $body['capabilities']['health'] ?? []);
        self::assertSame('never_reported', $body['configurationSync']['entries']['contacts']['call_whitelist']['status'] ?? null);
        self::assertSame('never_reported', $body['configurationSync']['entries']['contacts']['whitelist_enabled']['status'] ?? null);
        self::assertSame('never_reported', $body['configurationSync']['entries']['settings_system']['device_password']['status'] ?? null);
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
        $queue = new class implements PendingDownlinkQueue {
            public function enqueue(string $imei, string $bytes, ?array $command, int $ttlSeconds): PendingDownlink
            {
                return new PendingDownlink($imei, 'dedupe', $bytes, $command, time(), time() + $ttlSeconds);
            }

            public function pendingFor(string $imei): array
            {
                return [
                    new PendingDownlink($imei, 'cfg:BP76', 'IWBP76,...', ['command' => 'BP76'], 1719650000, 1719650300),
                ];
            }

            public function remove(PendingDownlink $downlink): void
            {
            }
        };
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

    public function testDeviceDetailExposesTakePillsMetaForFourPTouch(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);
        $db->deviceConfigurations->saveDesired(
            '868017032159118',
            'takePills',
            'four-p-touch',
            '4P Touch',
            'D46',
            'TAKEPILLS',
            [
                'reminderSettings' => '11:25-1-3-1010101',
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
                'reminderSettings' => [
                    [
                        'time' => '11:25',
                        'enabled' => true,
                        'frequency' => 3,
                        'custom' => '1010101',
                    ],
                ],
                'number' => 1,
                'reminderText' => 'meds',
                'voiceData' => 'data:audio/wav;base64,' . WavFixture::silenceBase64(),
                'voiceMimeType' => 'audio/wav',
            ],
            $body['capabilities']['alarms']['medication_reminders']['value'] ?? null
        );
        self::assertArrayNotHasKey('_nativeKey', $body['capabilities']['alarms']['medication_reminders']);
        self::assertSame(3, $body['capabilities']['alarms']['medication_reminders']['_meta']['limit'] ?? null);
        self::assertSame(
            [
                ['value' => 1, 'label' => 'Uma vez'],
                ['value' => 2, 'label' => 'Diariamente'],
                ['value' => 3, 'label' => 'Personalizado'],
            ],
            $body['capabilities']['alarms']['medication_reminders']['_meta']['frequency']['options'] ?? null
        );
    }

    public function testDeviceDetailExposesMultipleTakePillsRemindersForFourPTouch(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);
        $db->deviceConfigurations->saveDesired(
            '868017032159118',
            'takePills',
            'four-p-touch',
            '4P Touch',
            'D46',
            'TAKEPILLS',
            [
                'reminderSettings' => '11:25-1-2-14:30-0-1-18:00-1-3-1010101',
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
                'reminderSettings' => [
                    ['time' => '11:25', 'enabled' => true, 'frequency' => 2, 'custom' => ''],
                    ['time' => '14:30', 'enabled' => false, 'frequency' => 1, 'custom' => ''],
                    ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '1010101'],
                ],
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => '',
                'voiceMimeType' => '',
            ],
            $body['capabilities']['alarms']['medication_reminders']['value'] ?? null
        );
        self::assertArrayNotHasKey('_nativeKey', $body['capabilities']['alarms']['medication_reminders']);
    }
}
