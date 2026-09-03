<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Repository;

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
        self::assertTrue($db->configurationLifecycle->updateOperation('op-one', 'acked'));

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
        $oldStatus = $database->pdo()->query("
            SELECT delivery_status
            FROM device_configuration_operations
            WHERE operation_id = 'op-one'
        ")->fetchColumn();
        self::assertSame('acked', $oldStatus);
        self::assertTrue($db->configurationLifecycle->updateOperation('op-two', 'acked'));

        $current = $db->configurationLifecycle->currentForImei('861728087056333');
        self::assertCount(1, $current);
        self::assertSame('confirmed', $current[0]['sync_status']);
        self::assertSame(['enabled' => true], $current[0]['effective_payload']);

        $stored = $db->deviceConfigurations->allForImei('861728087056333');
        self::assertSame(2, (int)$stored[0]['desired_revision']);
        // Sem `confirmed_revision`: quem diz que a alteração aterrou é o `sync_status` da
        // alteração, afirmado acima, mais o `last_status` e o `applied_at` desta linha.
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

    /**
     * O histórico guarda que houve gravação, não a gravação.
     *
     * Um aviso de medicação de 42 s são 978 KB de base64, e o histórico guardava uma cópia
     * por revisão -- eram 69% da base de produção. O estado corrente mantém o áudio porque é
     * a base de fusão de uma alteração parcial: sem ele, mudar só a hora apagava a voz do
     * relógio.
     */
    public function testTheHistoryKeepsTheMarkerAndTheCurrentStateKeepsTheAudio(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $audio = "ID3\x03\x00\x00\x00\x00\x00\x00" . "\xFF\xFB\x90\x64" . str_repeat("\x00", 4000);
        $voiceData = base64_encode($audio);
        $desired = [
            'reminderSettings' => [['time' => '09:00', 'enabled' => true]],
            'reminderText' => 'Comprimido',
            'voiceData' => $voiceData,
            'voiceMimeType' => 'audio/mpeg',
        ];

        $db->configurationLifecycle->stage(
            '861728087056333',
            'medication_reminders',
            $desired,
            [[
                'nativeKey' => 'takePills',
                'protocol' => 'four-p-touch',
                'supplier' => '4P Touch',
                'model' => 'D41',
                'command' => 'TAKEPILLS',
                'payload' => $desired,
                'confirmationMode' => 'execution_ack',
                'operationId' => 'op-pills',
            ]],
            [[
                'operationId' => 'op-pills',
                'nativeKey' => 'takePills',
                'nativeType' => 'TAKEPILLS',
                'protocol' => 'four-p-touch',
                'bytes' => '[3G*2808706046*15E5F*TAKEPILLS,09:00-1-1,1]',
                'expectedReplyTypes' => ['TAKEPILLS'],
                'confirmationMode' => 'execution_ack',
                'label' => 'Aviso de medicação',
            ]],
        );

        $history = $db->configurationLifecycle->currentForImei('861728087056333')[0]['desired_payload'];
        self::assertArrayNotHasKey('voiceData', $history);
        self::assertTrue($history['voiceDataAvailable']);
        self::assertSame(strlen($audio), $history['voiceDataBytes']);
        self::assertSame('Comprimido', $history['reminderText']);

        $stored = $db->deviceConfigurations->allForImei('861728087056333')[0]['desired_payload'];
        self::assertSame($voiceData, $stored['voiceData']);
    }

    /** Ao confirmar, o `effective_payload` copia o desejado -- que já é a marca. */
    public function testTheConfirmedHistoryDoesNotResurrectTheAudio(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $desired = ['voiceData' => base64_encode(str_repeat('a', 3000)), 'reminderText' => 'X'];

        $db->configurationLifecycle->stage(
            '861728087056333',
            'medication_reminders',
            $desired,
            [[
                'nativeKey' => 'takePills',
                'protocol' => 'four-p-touch',
                'supplier' => '4P Touch',
                'model' => 'D41',
                'command' => 'TAKEPILLS',
                'payload' => $desired,
                'confirmationMode' => 'execution_ack',
                'operationId' => 'op-pills',
            ]],
            [[
                'operationId' => 'op-pills',
                'nativeKey' => 'takePills',
                'nativeType' => 'TAKEPILLS',
                'protocol' => 'four-p-touch',
                'bytes' => '[3G*2808706046*15E5F*TAKEPILLS,09:00-1-1,1]',
                'expectedReplyTypes' => ['TAKEPILLS'],
                'confirmationMode' => 'execution_ack',
                'label' => 'Aviso de medicação',
            ]],
        );
        self::assertTrue($db->configurationLifecycle->updateOperation('op-pills', 'acked'));

        $effective = $db->configurationLifecycle->currentForImei('861728087056333')[0]['effective_payload'];
        self::assertArrayNotHasKey('voiceData', $effective);
        self::assertTrue($effective['voiceDataAvailable']);
    }
}
