<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Repository;

use Hub\Api\Repository\ApiDataAccess;
use Tests\Support\MysqlDashboardTestCase;

final class DeviceConfigurationLifecycleRepositoryTest extends MysqlDashboardTestCase
{
    public function testNewRevisionSupersedesOldOperationAndOnlyCurrentAckConfirms(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $operation = static fn(string $id): array => [
            'operationId' => $id,
            'nativeKey' => 'rejectUnknownCalls',
            'nativeType' => 'BP84',
            'protocol' => 'vivistar-iw',
            'bytes' => "IWBP84,861728087056333,123456,1#",
            'expectedReplyTypes' => ['AP84'],
            'confirmationMode' => 'execution_ack',
            'label' => 'Rejeitar chamadas desconhecidas',
        ];
        $row = static fn(bool $enabled, string $operationId): array => [
            'nativeKey' => 'rejectUnknownCalls',
            'protocol' => 'vivistar-iw',
            'supplier' => 'Vivistar',
            'model' => 'L08 Pro',
            'command' => 'BP84',
            'payload' => ['enabled' => $enabled],
            'confirmationMode' => 'execution_ack',
            'operationId' => $operationId,
        ];

        $first = $db->configurationLifecycle->stage(
            '861728087056333',
            'whitelist_enabled',
            ['enabled' => false],
            [$row(false, 'op-one')],
            [$operation('op-one')],
        );
        self::assertSame(1, $first['revision']);
        self::assertTrue($db->configurationLifecycle->isCurrentOperation('op-one'));

        $second = $db->configurationLifecycle->stage(
            '861728087056333',
            'whitelist_enabled',
            ['enabled' => true],
            [$row(true, 'op-two')],
            [$operation('op-two')],
        );
        self::assertSame(2, $second['revision']);
        self::assertFalse($db->configurationLifecycle->isCurrentOperation('op-one'));
        self::assertFalse($db->configurationLifecycle->updateOperation('op-one', 'acked'));
        self::assertTrue($db->configurationLifecycle->updateOperation('op-two', 'acked'));

        $current = $db->configurationLifecycle->currentForImei('861728087056333');
        self::assertCount(1, $current);
        self::assertSame('confirmed', $current[0]['sync_status']);
        self::assertSame(['enabled' => true], $current[0]['effective_payload']);

        $stored = $db->deviceConfigurations->allForImei('861728087056333');
        self::assertSame(2, (int)$stored[0]['desired_revision']);
        self::assertSame(2, (int)$stored[0]['confirmed_revision']);
        self::assertSame('confirmed', $stored[0]['last_status']);
        self::assertNotSame('', $stored[0]['applied_at']);
    }

    public function testFailureKeepsDetailedErrorWithoutClaimingEffectiveValue(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $db->configurationLifecycle->stage(
            '861728087056333',
            'auto_vitals_interval',
            ['enabled' => true, 'intervalMinutes' => 15],
            [[
                'nativeKey' => 'deviceMeasuringFrequency',
                'protocol' => 'vivistar-iw',
                'supplier' => 'Vivistar',
                'model' => 'L08 Pro',
                'command' => 'BP86',
                'payload' => ['enabled' => true, 'intervalMinutes' => 15],
                'confirmationMode' => 'ack_only',
                'operationId' => 'op-failed',
            ]],
            [[
                'operationId' => 'op-failed',
                'nativeKey' => 'deviceMeasuringFrequency',
                'nativeType' => 'BP86',
                'protocol' => 'vivistar-iw',
                'bytes' => "IWBP86,861728087056333,123456,15#",
                'expectedReplyTypes' => ['AP86'],
                'confirmationMode' => 'ack_only',
                'label' => 'Medição automática',
            ]],
        );

        self::assertTrue($db->configurationLifecycle->updateOperation(
            'op-failed',
            'failed',
            'retry_exhausted',
        ));
        $current = $db->configurationLifecycle->currentForImei('861728087056333')[0];
        self::assertSame('failed', $current['sync_status']);
        self::assertNull($current['effective_payload']);
        self::assertSame('retry_exhausted', $current['operations'][0]['error_code']);
    }
}
