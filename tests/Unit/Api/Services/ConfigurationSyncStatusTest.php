<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Services;

use Hub\Api\Services\ConfigurationSyncStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Se um dispositivo aplicou o que o hub pediu: o achatamento em caminhos comparáveis, a
 * igualdade entre pretendido e reportado, e o estado de ciclo de vida que a API serve.
 */
final class ConfigurationSyncStatusTest extends TestCase
{
    public function testFlattensWritableCapabilitiesToSectionDotKeyPaths(): void
    {
        $flat = $this->subject()->flattenWritableCapabilities([
            'telemetry' => ['heart_rate' => ['supported' => true, 'requestable' => true]],
            'alarms' => ['alarm_clock' => ['value' => [['time' => '08:00', 'enabled' => true]], '_meta' => ['limit' => 3]]],
            'settings_system' => ['whitelist_enabled' => ['value' => ['enabled' => true], '_meta' => []]],
        ]);

        self::assertSame(
            ['alarms.alarm_clock', 'settings_system.whitelist_enabled'],
            array_keys($flat),
            'a telemetria não se escreve e não se compara'
        );
        self::assertSame([['time' => '08:00', 'enabled' => true]], $flat['alarms.alarm_clock']);
        self::assertSame(['enabled' => true], $flat['settings_system.whitelist_enabled']);
    }

    public function testFlattenSkipsSupportedOnlyEntriesThatCarryNoValue(): void
    {
        $flat = $this->subject()->flattenWritableCapabilities([
            'settings_system' => [
                'nurse_call' => ['supported' => true],
                'volume' => ['value' => ['level' => 3], '_meta' => []],
            ],
        ]);

        self::assertSame(['settings_system.volume'], array_keys($flat));
    }

    public function testValuesCompareEqualAcrossOrderingAndScalarFormatting(): void
    {
        $subject = $this->subject();

        self::assertTrue($subject->capabilityValuesEqual(['enabled' => true], ['enabled' => true]));
        self::assertTrue($subject->capabilityValuesEqual(
            ['b' => 2, 'a' => 1],
            ['a' => 1, 'b' => 2],
        ));
        self::assertFalse($subject->capabilityValuesEqual(['enabled' => true], ['enabled' => false]));
    }

    #[DataProvider('lifecycleStatuses')]
    public function testPendingStatusReflectsTheLastCommandAndWhetherTheDeviceReported(
        string $lastStatus,
        bool $reportedExists,
        string $expected,
    ): void {
        self::assertSame($expected, $this->subject()->pendingStatus($lastStatus, $reportedExists));
    }

    /** @return array<string, array{0: string, 1: bool, 2: string}> */
    public static function lifecycleStatuses(): array
    {
        return [
            'delivery failure outranks everything' => ['delivery_failed', true, 'failed'],
            'timeout is a failure too' => ['response_timeout', false, 'failed'],
            'acked but never reported is applied' => ['acked', false, 'applied'],
            'still in flight' => ['waiting', false, 'waiting_device'],
            'queued is in flight' => ['queued', false, 'waiting_device'],
            'sent is in flight' => ['sent', false, 'waiting_device'],
            'nothing sent and nothing reported' => ['', false, 'never_reported'],
            'device reported something else' => ['acked', true, 'diverged'],
        ];
    }

    public function testFailureCodeIsTheStatusItselfOnlyForFailureStatuses(): void
    {
        $subject = $this->subject();

        foreach (['failed', 'dropped', 'delivery_failed', 'retry_exhausted', 'response_timeout'] as $status) {
            self::assertSame($status, $subject->pendingFailureCode($status));
        }

        foreach (['acked', 'waiting', 'queued', 'sent', ''] as $status) {
            self::assertSame('', $subject->pendingFailureCode($status));
        }
    }

    public function testRowMetaKeepsTheNewestRowPerGenericKey(): void
    {
        $meta = $this->subject()->genericCapabilityRowMeta([
            [
                'native_key' => 'reminders',
                'config_key' => 'alarm_clock',
                'desired_updated_at' => '2026-01-01 10:00:00',
                'last_status' => 'acked',
                'last_command_id' => 'old',
            ],
            [
                'native_key' => 'reminders',
                'config_key' => 'alarm_clock',
                'desired_updated_at' => '2026-01-02 10:00:00',
                'last_status' => 'waiting',
                'last_command_id' => 'new',
            ],
        ]);

        self::assertSame('new', $meta['alarm_clock']['last_command_id']);
        self::assertSame('waiting', $meta['alarm_clock']['last_status']);
    }

    public function testRowMetaPrefersTheStrongerStatusWhenTimestampsTie(): void
    {
        $rows = [
            [
                'native_key' => 'reminders',
                'config_key' => 'alarm_clock',
                'desired_updated_at' => '2026-01-01 10:00:00',
                'last_status' => 'acked',
                'last_command_id' => 'acked-one',
            ],
            [
                'native_key' => 'reminders',
                'config_key' => 'alarm_clock',
                'desired_updated_at' => '2026-01-01 10:00:00',
                'last_status' => 'delivery_failed',
                'last_command_id' => 'failed-one',
            ],
        ];

        self::assertSame('failed-one', $this->subject()->genericCapabilityRowMeta($rows)['alarm_clock']['last_command_id']);
        self::assertSame(
            'failed-one',
            $this->subject()->genericCapabilityRowMeta(array_reverse($rows))['alarm_clock']['last_command_id'],
            'uma falha ganha seja qual for a ordem das linhas'
        );
    }

    public function testRowMetaIgnoresRowsWithoutAResolvableCapabilityKey(): void
    {
        self::assertSame([], $this->subject()->genericCapabilityRowMeta([
            ['native_key' => '', 'config_key' => '', 'desired_updated_at' => '2026-01-01 10:00:00'],
        ]));
    }

    public function testACapabilityDeliveredInSeveralCommandsReportsHowFarItGot(): void
    {
        $detail = ConfigurationSyncStatus::operationDetail([
            ['deliveryStatus' => 'acked'],
            ['deliveryStatus' => 'acked'],
            ['deliveryStatus' => 'acked'],
            ['deliveryStatus' => 'waiting'],
            ['deliveryStatus' => 'failed'],
        ]);

        self::assertSame(['confirmed' => 3, 'total' => 5], $detail);
    }

    public function testACapabilityThatTravelsInASingleCommandHasNothingToDetail(): void
    {
        self::assertNull(
            ConfigurationSyncStatus::operationDetail([['deliveryStatus' => 'acked']]),
            'com um só comando o estado de topo já diz tudo'
        );
        self::assertNull(ConfigurationSyncStatus::operationDetail([]));
    }

    private function subject(): ConfigurationSyncStatus
    {
        return new ConfigurationSyncStatus();
    }
}
