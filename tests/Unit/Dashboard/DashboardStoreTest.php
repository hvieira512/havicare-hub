<?php

namespace Tests\Unit\Dashboard;

use Hub\Dashboard\DashboardStore;
use Hub\Command\DeviceCommandCatalog;
use Hub\Protocol\Adapter\WonlexAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

final class DashboardStoreTest extends TestCase
{
    public function testDeleteDeviceRemovesDeviceListEntryAndDeviceData(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');

        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE');
        $store->append('861265061009822', 'raw', ['payload' => 'IWAP00']);
        $store->append('861265061009822', 'telemetry', ['type' => 'heartbeat']);
        $store->append('861265061009822', 'events', ['type' => 'fall']);
        $store->recordCommand('861265061009822', 'cmd-1', ['status' => 'waiting']);

        self::assertCount(1, $store->devices());
        self::assertNotSame([], $store->recent('861265061009822', 'raw'));
        self::assertNotSame([], $store->commands('861265061009822'));

        $store->deleteDevice('861265061009822');

        self::assertSame([], $store->devices());
        self::assertSame([], $store->recent('861265061009822', 'raw'));
        self::assertSame([], $store->recent('861265061009822', 'telemetry'));
        self::assertSame([], $store->recent('861265061009822', 'events'));
        self::assertSame([], $store->commands('861265061009822'));
    }

    public function testWaitingCommandWithoutSentAtStillExpires(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE');

        // Sem `sentAt`: nada mais o tiraria de "à espera", e ficava pendente na dashboard
        // para sempre.
        $store->recordCommand('861265061009822', 'cmd-1', [
            'status' => 'waiting',
            'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 7200),
        ]);

        $store->expireWaitingCommands(3600);

        self::assertSame('failed', $store->commands('861265061009822')[0]['status'] ?? null);
    }

    public function testNonRetryableQueuedCommandEventuallyFails(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE');

        // A varredura de repetição salta o que não é repetível, e por isso sem isto o
        // command has no path to a terminal state at all.
        $store->recordCommand('861265061009822', 'cmd-1', [
            'status' => 'queued',
            'retryable' => false,
            'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 7200),
        ]);

        $store->expireWaitingCommands(3600);

        self::assertSame('failed', $store->commands('861265061009822')[0]['status'] ?? null);
    }

    public function testRetryableQueuedCommandIsNotExpiredWhileItAwaitsAnOfflineDevice(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE');

        // Em fila e repetível quer dizer "à espera de o dispositivo voltar", o que é
        // deliberado e tem de sobreviver à varredura.
        $store->recordCommand('861265061009822', 'cmd-1', [
            'status' => 'queued',
            'retryable' => true,
            'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 7200),
        ]);

        $store->expireWaitingCommands(3600);

        self::assertSame('queued', $store->commands('861265061009822')[0]['status'] ?? null);
    }

    public function testRecentCommandsAreLeftAloneBySweep(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE');

        $store->recordCommand('861265061009822', 'cmd-1', [
            'status' => 'waiting',
            'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 5),
        ]);

        $store->expireWaitingCommands(3600);

        self::assertSame('waiting', $store->commands('861265061009822')[0]['status'] ?? null);
    }

    public function testCommandRetentionRemovesEvictedRecordsAndGlobalIndexEntries(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, limit: 2, prefix: 'test:dashboard');
        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE');

        $store->recordCommand('861265061009822', 'cmd-1', ['status' => 'waiting']);
        $store->recordCommand('861265061009822', 'cmd-2', ['status' => 'waiting']);
        $store->recordCommand('861265061009822', 'cmd-3', ['status' => 'waiting']);

        self::assertSame(['cmd-3', 'cmd-2'], array_column($store->commands('861265061009822'), 'id'));
        self::assertNull($store->findCommand('cmd-1'));
        self::assertSame('cmd-2', $store->findCommand('cmd-2')['command']['id'] ?? null);
    }

    public function testExpireStaleDevicesMarksOldOnlineDevicesOffline(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');

        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE');
        $store->deviceSeen('861265061009822', ['online' => '1']);
        $redis->hmset('test:dashboard:device:861265061009822', [
            'lastSeenAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 7200),
        ]);
        $redis->zadd('test:dashboard:online-devices-by-last-seen', ['861265061009822' => time() - 7200]);

        $store->expireStaleDevices(60);

        self::assertFalse($store->device('861265061009822')['online']);
    }

    public function testRegisterDevicePersistsDeviceTypeAndLicenseId(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');

        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE', 'radar', 12);

        $device = $store->device('861265061009822');
        self::assertSame('radar', $device['deviceType']);
        self::assertSame(12, $device['licenseId']);
    }

    public function testRetryWaitingCommandsResendsRetryableCommands(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861265061009822', 'Vivistar', 'VIVISTAR-CARE');
        $store->recordCommand('861265061009822', 'cmd-1', [
            'status' => 'waiting',
            'retryable' => true,
            'bytes' => 'IWBP76,1',
            'attempts' => 1,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]);

        $calls = [];
        $store->retryWaitingCommands(60, 3600, 3, function (string $imei, string $bytes, array $command) use (&$calls): string {
            $calls[] = [$imei, $bytes, $command['id'] ?? null];
            return 'sent';
        });

        self::assertCount(1, $calls);
        self::assertSame(['861265061009822', 'IWBP76,1', 'cmd-1'], $calls[0]);

        $command = $store->commands('861265061009822')[0] ?? [];
        self::assertSame('waiting', $command['status'] ?? null);
        self::assertSame(2, $command['attempts'] ?? null);
        self::assertNotEmpty($command['lastAttemptAt'] ?? null);
        self::assertNotEmpty($command['nextRetryAt'] ?? null);
    }

    public function testBinaryCommandBytesAreStoredAsBase64AndDecodedForRetry(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('868705080304962', 'Wonlex', 'HW20PRO');
        $wireBytes = "\xfc\xaf\x00\x05hello";
        $store->recordCommand('868705080304962', 'cmd-wonlex', [
            'status' => 'waiting',
            'retryable' => true,
            'bytes' => $wireBytes,
            'attempts' => 1,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]);

        $command = $store->commands('868705080304962')[0] ?? [];
        self::assertSame(base64_encode($wireBytes), $command['bytes'] ?? null);
        self::assertSame('base64', $command['bytesEncoding'] ?? null);
        self::assertIsString(json_encode($command));

        $calls = [];
        $store->retryWaitingCommands(60, 3600, 3, static function (string $imei, string $bytes) use (&$calls): string {
            $calls[] = [$imei, $bytes];
            return 'sent';
        });

        self::assertSame([['868705080304962', $wireBytes]], $calls);
    }

    public function testWonlexRepliesAreCorrelatedByIdentAndRef(): void
    {
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard');
        $imei = '868705080304962';
        $store->registerDevice($imei, 'Wonlex', 'HW20PRO');

        $first = DeviceCommandCatalog::buildDownlink('wonlex-json', $imei, 'dnHeartRate', [], ['ident' => 111111]);
        $second = DeviceCommandCatalog::buildDownlink('wonlex-json', $imei, 'dnHeartRate', [], ['ident' => 222222]);
        foreach ([['one', $first], ['two', $second]] as [$id, $bytes]) {
            $store->recordCommand($imei, $id, [
                'status' => 'waiting',
                'protocol' => 'wonlex-json',
                'nativeType' => 'dnHeartRate',
                'expectedReplyTypes' => ['upHeartRate', 'upBatch'],
                'bytes' => $bytes,
            ]);
        }

        $store->markCommandReply($imei, 'upHeartRate', 222222, 'w:update');
        $commands = array_column($store->commands($imei), null, 'id');

        self::assertSame('waiting', $commands['one']['status']);
        self::assertSame('acked', $commands['two']['status']);
        self::assertSame(222222, $commands['two']['replyIdent']);
    }

    public function testWonlexReplyFallsBackToSemanticMatchWhenFirmwareChangesIdent(): void
    {
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard');
        $imei = '868705080304962';
        $store->registerDevice($imei, 'Wonlex', 'HW20PRO');

        $bytes = DeviceCommandCatalog::buildDownlink('wonlex-json', $imei, 'dnBO', [], ['ident' => 220365]);
        $store->recordCommand($imei, 'blood-oxygen', [
            'status' => 'waiting',
            'protocol' => 'wonlex-json',
            'nativeType' => 'dnBO',
            'expectedReplyTypes' => ['upBO', 'upBatch'],
            'bytes' => $bytes,
        ]);

        $store->markCommandReply($imei, 'upBO', 747418, 'w:update');
        $command = $store->commands($imei)[0] ?? [];

        self::assertSame('acked', $command['status'] ?? null);
        self::assertSame(220365, $command['ident'] ?? null);
        self::assertSame('upBO', $command['replyNativeType'] ?? null);
        self::assertSame(747418, $command['replyIdent'] ?? null);
        self::assertSame('w:update', $command['replyRef'] ?? null);
    }

    public function testWonlexSameTypeReceiptDoesNotCompleteMeasurementRequest(): void
    {
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard');
        $imei = '868705080304962';
        $store->registerDevice($imei, 'Wonlex', 'HW20PRO');

        $bytes = DeviceCommandCatalog::buildDownlink('wonlex-json', $imei, 'dnBO', [], ['ident' => 220365]);
        $store->recordCommand($imei, 'blood-oxygen', [
            'status' => 'waiting',
            'protocol' => 'wonlex-json',
            'nativeType' => 'dnBO',
            'expectedReplyTypes' => ['upBO', 'upBatch'],
            'bytes' => $bytes,
        ]);

        $store->markCommandReply($imei, 'dnBO', 642787, 'w:reply');
        $command = $store->commands($imei)[0] ?? [];

        self::assertSame('waiting', $command['status'] ?? null);
        self::assertArrayNotHasKey('replyNativeType', $command);

        $store->markCommandReply($imei, 'upBO', 747418, 'w:update');
        $command = $store->commands($imei)[0] ?? [];

        self::assertSame('acked', $command['status'] ?? null);
        self::assertSame('upBO', $command['replyNativeType'] ?? null);
        self::assertSame(747418, $command['replyIdent'] ?? null);
        self::assertSame('w:update', $command['replyRef'] ?? null);
    }

    public function testFourPTouchLssetReplyAcknowledgesSensitivityCommand(): void
    {
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard');
        $imei = '864504816144000';
        $store->registerDevice($imei, '4P Touch', 'D46', deviceId: '4504816144');
        $store->recordCommand($imei, 'lsset-command', [
            'status' => 'waiting',
            'protocol' => 'four-p-touch',
            'nativeType' => 'LSSET',
            'expectedReplyTypes' => ['LSSET'],
            'bytes' => '[3G*4504816144*0009*LSSET,5+6]',
        ]);

        $store->markCommandReply($imei, 'LSSET', '4504816144', 'w:update');
        $command = $store->commands($imei)[0] ?? [];

        self::assertSame('acked', $command['status'] ?? null);
        self::assertSame('LSSET', $command['replyNativeType'] ?? null);
        self::assertSame('4504816144', $command['replyIdent'] ?? null);
    }

    public function testFourPTouchRejectedTakePillsReplyFailsCommand(): void
    {
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard');
        $imei = '351266770073676';
        $store->registerDevice($imei, '4P Touch', 'Y6M', deviceId: '6677007367');
        $store->recordCommand($imei, 'take-pills-command', [
            'status' => 'waiting',
            'protocol' => 'four-p-touch',
            'nativeType' => 'TAKEPILLS',
            'expectedReplyTypes' => ['TAKEPILLS'],
            'bytes' => '[3G*6677007367*002A*TAKEPILLS,11:25-1-2,1,006D006500640073,]',
        ]);

        $store->markCommandReply($imei, 'TAKEPILLS', '6677007367', 'w:update', false);
        $command = $store->commands($imei)[0] ?? [];

        self::assertSame('failed', $command['status'] ?? null);
        self::assertSame('device_rejected', $command['error'] ?? null);
        self::assertSame('TAKEPILLS', $command['replyNativeType'] ?? null);
    }

    public function testRetryWaitingCommandsDispatchesQueuedRetryableCommands(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861728087743062', '4P Touch', 'D41');
        $store->recordCommand('861728087743062', 'cmd-queued', [
            'status' => 'queued',
            'retryable' => true,
            'bytes' => '[3G*2808774306*0009*LSSET,3+6]',
            'attempts' => 1,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]);

        $calls = [];
        $store->retryWaitingCommands(60, 3600, 3, function (string $imei, string $bytes, array $command) use (&$calls): string {
            $calls[] = [$imei, $bytes, $command['id'] ?? null];
            return 'sent';
        });

        self::assertSame([
            ['861728087743062', '[3G*2808774306*0009*LSSET,3+6]', 'cmd-queued'],
        ], $calls);

        $command = $store->commands('861728087743062')[0] ?? [];
        self::assertSame('waiting', $command['status'] ?? null);
        self::assertSame(1, $command['attempts'] ?? null);
        self::assertNotEmpty($command['sentAt'] ?? null);
        self::assertGreaterThan(time(), strtotime((string)($command['nextRetryAt'] ?? '')));
    }

    public function testQueuedRedispatchDoesNotConsumeAttemptsWhileDeviceRemainsOffline(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861728087743062', '4P Touch', 'D41');
        $store->recordCommand('861728087743062', 'cmd-queued', [
            'status' => 'queued',
            'retryable' => true,
            'bytes' => '[3G*2808774306*0009*LSSET,3+6]',
            'attempts' => 1,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]);

        $store->retryWaitingCommands(60, 3600, 3, static fn(): string => 'queued');

        $command = $store->commands('861728087743062')[0] ?? [];
        self::assertSame('queued', $command['status'] ?? null);
        self::assertSame(1, $command['attempts'] ?? null);
        self::assertNotEmpty($command['lastAttemptAt'] ?? null);
        self::assertGreaterThan(time(), strtotime((string)($command['nextRetryAt'] ?? '')));
    }

    public function testQueuedWonlexWaveformRequestIsNormalizedBeforeRedispatch(): void
    {
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard');
        $imei = '868705080300697';
        $store->registerDevice($imei, 'Wonlex', 'HW20PRO');
        $legacyWire = DeviceCommandCatalog::buildDownlink(
            'wonlex-json',
            $imei,
            'dnECG',
            [
                'fields' => [],
            ],
            ['ident' => 123456]
        );
        $store->recordCommand($imei, 'legacy-wonlex-request', [
            'status' => 'queued',
            'protocol' => 'wonlex-json',
            'nativeType' => 'dnECG',
            'retryable' => true,
            'bytes' => $legacyWire,
            'attempts' => 1,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]);

        $dispatched = [];
        $store->retryWaitingCommands(
            60,
            3600,
            3,
            static function (string $dispatchedImei, string $bytes) use (&$dispatched): string {
                $dispatched[] = [$dispatchedImei, $bytes];
                return 'sent';
            }
        );

        self::assertCount(1, $dispatched);
        $decoded = (new WonlexAdapter())->decodeIncoming($dispatched[0][1]);
        self::assertSame($imei, $dispatched[0][0]);
        self::assertSame(123456, $decoded['ident'] ?? null);
        self::assertSame(
            ['type', 'imei', 'timestamp', 'frequency', 'oneTime', 'collectionLogo'],
            array_keys($decoded['data'] ?? [])
        );
        self::assertSame('500', $decoded['data']['frequency'] ?? null);
        self::assertSame(30, $decoded['data']['oneTime'] ?? null);
        self::assertMatchesRegularExpression('/^\d{8}$/', (string)($decoded['data']['collectionLogo'] ?? ''));

        $stored = $store->commands($imei)[0] ?? [];
        self::assertSame('waiting', $stored['status'] ?? null);
        $storedData = (new WonlexAdapter())->decodeIncoming(
            \Hub\Dashboard\DeviceCommandRecord::wireBytes($stored)
        )['data'] ?? [];
        self::assertSame(
            $decoded['data']['collectionLogo'] ?? null,
            $storedData['collectionLogo'] ?? null
        );
    }

    public function testQueuedRedispatchIgnoresSentAttemptLimitUntilFirstDelivery(): void
    {
        $redis = new InMemoryRedisClient();
        $store = new DashboardStore($redis, prefix: 'test:dashboard');
        $store->registerDevice('861728087743062', '4P Touch', 'D41');
        $store->recordCommand('861728087743062', 'cmd-queued', [
            'status' => 'queued',
            'retryable' => true,
            'bytes' => '[3G*2808774306*0009*LSSET,3+6]',
            'attempts' => 3,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]);

        $calls = 0;
        $store->retryWaitingCommands(60, 3600, 3, static function () use (&$calls): string {
            $calls++;
            return 'sent';
        });

        self::assertSame(1, $calls);
        $command = $store->commands('861728087743062')[0] ?? [];
        self::assertSame('waiting', $command['status'] ?? null);
        self::assertSame(3, $command['attempts'] ?? null);
    }
}
