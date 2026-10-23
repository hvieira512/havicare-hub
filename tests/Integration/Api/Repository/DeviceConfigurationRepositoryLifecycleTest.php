<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Repository;

use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\State\DeviceStore;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\MysqlDashboardTestCase;

final class DeviceConfigurationRepositoryLifecycleTest extends MysqlDashboardTestCase
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

        $first = $db->deviceConfigurations->stage(
            '861728087056333',
            'whitelist_enabled',
            ['enabled' => false],
            [$row(false, 'op-one')],
            [$operation('op-one')],
        );
        self::assertSame(1, $first['revision']);
        self::assertTrue($db->deviceConfigurations->isCurrentOperation('op-one'));
        self::assertTrue($db->deviceConfigurations->updateOperation('op-one', 'acked'));

        $second = $db->deviceConfigurations->stage(
            '861728087056333',
            'whitelist_enabled',
            ['enabled' => true],
            [$row(true, 'op-two')],
            [$operation('op-two')],
        );
        self::assertSame(2, $second['revision']);
        self::assertFalse($db->deviceConfigurations->isCurrentOperation('op-one'));
        self::assertFalse($db->deviceConfigurations->updateOperation('op-one', 'acked'));
        $oldStatus = $database->pdo()->query("
            SELECT delivery_status
            FROM device_configuration_operations
            WHERE operation_id = 'op-one'
        ")->fetchColumn();
        self::assertSame('acked', $oldStatus);
        self::assertTrue($db->deviceConfigurations->updateOperation('op-two', 'acked'));

        $current = $db->deviceConfigurations->currentForImei('861728087056333');
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

    /** O que se lê de um radar fica como o valor aplicado, para o ecrã mostrar o que ele tem. */
    public function testWhatIsReadFromTheDeviceBecomesTheAppliedValue(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        self::assertTrue($db->deviceConfigurations->recordRead('AD8A613B0493', 'fall_alarm', ['afterSeconds' => 20], 'qinglanst-radar'));

        $current = $db->deviceConfigurations->currentForImei('AD8A613B0493');
        self::assertSame('confirmed', $current[0]['sync_status']);
        self::assertSame(['afterSeconds' => 20], $current[0]['effective_payload']);
        self::assertSame(['fall_alarm' => ['afterSeconds' => 20]], $db->deviceConfigurations->effectivePayloads('AD8A613B0493', ['fall_alarm']));
    }

    /** Ler o mesmo valor outra vez não cria revisão nova. */
    public function testReadingTheSameValueAgainChangesNothing(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->deviceConfigurations->recordRead('AD8A613B0493', 'fall_alarm', ['afterSeconds' => 20], 'qinglanst-radar');

        self::assertFalse($db->deviceConfigurations->recordRead('AD8A613B0493', 'fall_alarm', ['afterSeconds' => 20], 'qinglanst-radar'));
        self::assertSame(1, (int)$db->deviceConfigurations->currentForImei('AD8A613B0493')[0]['desired_revision']);
    }

    /** Mudar o nome de um utilizador da balança não escreve nada nela, e vale logo. */
    public function testAChangeWithoutOperationsIsAppliedAtOnce(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $users = ['items' => [['id' => 'u-1', 'name' => 'Ana']], 'onlineUserId' => 'u-1'];

        $db->deviceConfigurations->stage('cfe908067329', 'scale_users', $users, [], []);

        self::assertSame(['scale_users' => $users], $db->deviceConfigurations->effectivePayloads('cfe908067329', ['scale_users']));
    }

    /** Enquanto a alteração seguinte espera pelo aparelho, vale a que ele já tem. */
    public function testThePreviousValueHoldsWhileTheNextIsOnItsWay(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->deviceConfigurations->recordRead('AD8A613B0493', 'fall_alarm', ['afterSeconds' => 20], 'qinglanst-radar');
        $db->deviceConfigurations->stage('AD8A613B0493', 'fall_alarm', ['afterSeconds' => 60], [[
            'nativeKey' => 'fall_alarm',
            'protocol' => 'qinglanst-radar',
            'command' => 'fall_alarm',
            'payload' => ['afterSeconds' => 60],
            'confirmationMode' => 'execution_ack',
            'operationId' => 'op-fall',
        ]], [[
            'operationId' => 'op-fall',
            'nativeKey' => 'fall_alarm',
            'nativeType' => 'fall_alarm',
            'protocol' => 'qinglanst-radar',
            'confirmationMode' => 'execution_ack',
        ]]);

        self::assertSame(['fall_alarm' => ['afterSeconds' => 20]], $db->deviceConfigurations->effectivePayloads('AD8A613B0493', ['fall_alarm']));
    }

    /** Uma alteração a caminho do aparelho não é apagada pelo que se leu antes de ela chegar. */
    public function testAReadDoesNotOverwriteAChangeOnItsWay(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->deviceConfigurations->stage('AD8A613B0493', 'fall_alarm', ['afterSeconds' => 60], [[
            'nativeKey' => 'fall_alarm',
            'protocol' => 'qinglanst-radar',
            'command' => 'fall_alarm',
            'payload' => ['afterSeconds' => 60],
            'confirmationMode' => 'execution_ack',
            'operationId' => 'op-fall',
        ]], [[
            'operationId' => 'op-fall',
            'nativeKey' => 'fall_alarm',
            'nativeType' => 'fall_alarm',
            'protocol' => 'qinglanst-radar',
            'confirmationMode' => 'execution_ack',
        ]]);

        self::assertFalse($db->deviceConfigurations->recordRead('AD8A613B0493', 'fall_alarm', ['afterSeconds' => 20], 'qinglanst-radar'));
        self::assertSame(['afterSeconds' => 60], $db->deviceConfigurations->currentForImei('AD8A613B0493')[0]['desired_payload']);
    }

    /** Só o que o aparelho confirmou conta, e só das chaves pedidas. */
    public function testEffectivePayloadsReturnsTheConfirmedValueOfTheRequestedKeys(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $stage = static function (string $key, array $payload, string $operationId) use ($db): void {
            $db->deviceConfigurations->stage('869243062262262', $key, $payload, [[
                'nativeKey' => $key,
                'protocol' => 'zayata-m228',
                'supplier' => 'Zayata',
                'model' => 'M228',
                'command' => 'writeConfiguration',
                'payload' => $payload,
                'confirmationMode' => 'execution_ack',
                'operationId' => $operationId,
            ]], [[
                'operationId' => $operationId,
                'nativeKey' => $key,
                'nativeType' => 'write_config',
                'protocol' => 'zayata-m228',
                'bytes' => 'x',
                'expectedReplyTypes' => ['write_config_ack'],
                'confirmationMode' => 'execution_ack',
                'label' => $key,
            ]]);
        };

        $stage('time_zone', ['timeZone' => 100], 'op-zone');
        $stage('medication_period', ['enabled' => true, 'startDate' => '2026-10-01', 'endDate' => ''], 'op-period');
        $stage('child_lock', ['enabled' => true], 'op-lock');
        $db->deviceConfigurations->updateOperation('op-zone', 'acked');
        $db->deviceConfigurations->updateOperation('op-lock', 'acked');

        self::assertSame(
            ['time_zone' => ['timeZone' => 100]],
            $db->deviceConfigurations->effectivePayloads('869243062262262', ['time_zone', 'medication_period']),
        );
    }

    public function testFailureKeepsDetailedErrorWithoutClaimingEffectiveValue(): void
    {
        $database = $this->createDashboardDatabase();
        $db = ApiDataAccess::fromDatabase($database);
        $db->deviceConfigurations->stage(
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

        self::assertTrue($db->deviceConfigurations->updateOperation(
            'op-failed',
            'failed',
            'retry_exhausted',
        ));
        $current = $db->deviceConfigurations->currentForImei('861728087056333')[0];
        self::assertSame('failed', $current['sync_status']);
        self::assertNull($current['effective_payload']);
        self::assertSame('retry_exhausted', $current['operations'][0]['error_code']);
    }

    /** O histórico guarda a marca da gravação; o estado corrente guarda o áudio. */
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

        $db->deviceConfigurations->stage(
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

        $history = $db->deviceConfigurations->currentForImei('861728087056333')[0]['desired_payload'];
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

        $db->deviceConfigurations->stage(
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
        self::assertTrue($db->deviceConfigurations->updateOperation('op-pills', 'acked'));

        $effective = $db->deviceConfigurations->currentForImei('861728087056333')[0]['effective_payload'];
        self::assertArrayNotHasKey('voiceData', $effective);
        self::assertTrue($effective['voiceDataAvailable']);
    }

    /** Só o desfecho de uma alteração se anuncia: aplicada, recusada, ou entregue sem confirmação possível. */
    public function testOnlyTheOutcomeOfAChangeIsAnnounced(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $settled = [];
        $db->deviceConfigurations->announceOutcomesTo(static function (array $change) use (&$settled): void {
            $settled[] = [$change['configKey'], $change['status'], $change['effective'], $change['error'], $change['desiredRevision']];
        });
        $stage = static fn (string $operationId, bool $enabled, string $mode = 'execution_ack') => $db->deviceConfigurations->stage(
            '861728087056333',
            'whitelist_enabled',
            ['enabled' => $enabled],
            [['nativeKey' => 'rejectUnknownCalls', 'protocol' => 'vivistar-iw', 'command' => 'BP84', 'payload' => ['enabled' => $enabled], 'confirmationMode' => $mode, 'operationId' => $operationId]],
            [['operationId' => $operationId, 'nativeKey' => 'rejectUnknownCalls', 'nativeType' => 'BP84', 'protocol' => 'vivistar-iw', 'confirmationMode' => $mode]],
        );

        $stage('op-one', true);
        $db->deviceConfigurations->updateOperation('op-one', 'waiting');
        $db->deviceConfigurations->updateOperation('op-one', 'acked');
        $db->deviceConfigurations->updateOperation('op-one', 'acked');
        $stage('op-two', false);
        $db->deviceConfigurations->updateOperation('op-two', 'failed', 'device_rejected');
        $stage('op-three', false, 'ack_only');
        $db->deviceConfigurations->updateOperation('op-three', 'acked');
        $db->deviceConfigurations->stage('cfe908067329', 'scale_users', ['items' => []], [], []);

        self::assertSame([
            ['whitelist_enabled', 'confirmed', ['enabled' => true], '', 1],
            ['whitelist_enabled', 'failed', null, 'device_rejected', 2],
            ['whitelist_enabled', 'confirmation_unavailable', null, '', 3],
            ['scale_users', 'confirmed', ['items' => []], '', 1],
        ], $settled);
    }

    /** A falha diz o motivo também depois de uma repetição, que limpa o último erro. */
    public function testAFailureAfterARetryKeepsItsReason(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $store = new DeviceStore(new InMemoryRedisClient());
        $store->setDataAccess($db);
        $settled = [];
        $db->deviceConfigurations->announceOutcomesTo(static function (array $change) use (&$settled): void {
            $settled[] = [$change['status'], $change['error'], $change['confirmedAt'] !== ''];
        });
        $db->deviceConfigurations->stage(
            'cfe908067329',
            'weight_unit',
            ['unit' => 'kg'],
            [['nativeKey' => 'weightUnit', 'protocol' => 'lefu-torre', 'command' => 'weightUnit', 'payload' => ['unit' => 'kg'], 'confirmationMode' => 'execution_ack', 'operationId' => 'op-w']],
            [['operationId' => 'op-w', 'nativeKey' => 'weightUnit', 'nativeType' => 'weightUnit', 'protocol' => 'lefu-torre', 'confirmationMode' => 'execution_ack']],
        );
        $store->recordCommand('cfe908067329', 'op-w', ['status' => 'queued', 'configKey' => 'weightUnit', 'operationId' => 'op-w', 'error' => '', 'lastError' => '']);

        $store->markCommand('cfe908067329', 'op-w', ['status' => 'failed', 'error' => 'write_failed']);

        self::assertSame([['failed', 'write_failed', false]], $settled);
    }

    public function testAConfirmationSaysWhenItHappened(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $confirmedAt = [];
        $db->deviceConfigurations->announceOutcomesTo(static function (array $change) use (&$confirmedAt): void {
            $confirmedAt[] = $change['confirmedAt'];
        });

        $db->deviceConfigurations->stage('cfe908067329', 'scale_users', ['items' => []], [], []);

        self::assertSame(
            $db->deviceConfigurations->currentForImei('cfe908067329')[0]['confirmed_at'],
            $confirmedAt[0],
        );
    }

    /** A lista no formato antigo é operação da alteração, e a confirmação do relógio fecha-a. */
    public function testTheLegacyPhonebookClosesTheChangeItReplaces(): void
    {
        $imei = '637507597567372';
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $store = new DeviceStore(new InMemoryRedisClient());
        $store->setDataAccess($db);
        $store->deviceSeen($imei, ['deviceType' => 'watch', 'online' => '1']);
        $settled = [];
        $db->deviceConfigurations->announceOutcomesTo(static function (array $change) use (&$settled): void {
            $settled[] = $change['status'];
        });
        $contacts = ['items' => [['name' => 'Hugo', 'phone' => '+351938854803']]];
        $operation = ['operationId' => 'op-phbx2', 'nativeKey' => 'phonebook', 'nativeType' => 'PHBX2', 'protocol' => 'four-p-touch', 'confirmationMode' => 'execution_ack'];
        $staged = $db->deviceConfigurations->stage(
            $imei,
            'phonebook',
            $contacts,
            [['nativeKey' => 'phonebook', 'protocol' => 'four-p-touch', 'command' => 'PHBX2', 'payload' => $contacts, 'confirmationMode' => 'execution_ack', 'operationId' => 'op-phbx2']],
            [$operation],
        );
        $store->recordCommand($imei, 'op-phbx2', [
            'status' => 'failed', 'error' => 'response_timeout', 'protocol' => 'four-p-touch', 'nativeKey' => 'phonebook',
            'nativeType' => 'PHBX2', 'configKey' => 'phonebook', 'genericConfigKey' => 'phonebook', 'operationId' => 'op-phbx2',
            'changeId' => $staged['changeId'], 'desiredRevision' => $staged['revision'], 'retryable' => true, 'expectedReplyTypes' => ['PHBX2'],
            'payload' => ['fields' => ['1', '004800750067006F', '+351938854803']], 'bytes' => '[3G*1234567890*0007*PHBX2,1]',
        ]);

        $store->retryWaitingCommands(60, 3600, 3, static fn (): string => 'sent');
        foreach ($store->commands($imei) as $command) {
            if (($command['nativeType'] ?? '') === 'PHB') {
                $store->markCommand($imei, (string)$command['id'], ['status' => 'acked']);
            }
        }

        self::assertSame('confirmed', $db->deviceConfigurations->currentForImei($imei)[0]['sync_status']);
        self::assertSame(['failed', 'confirmed'], $settled);
    }
}
