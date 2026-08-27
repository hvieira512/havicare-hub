<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Services;

use Hub\Api\Services\ConfigurationSyncStatus;
use PHPUnit\Framework\TestCase;

/**
 * Prende o comportamento que decide se um dispositivo aplicou o que o hub lhe pediu: achatar
 * a árvore de capacidades em caminhos comparáveis, decidir quando é que um valor pretendido e
 * um reportado contam como iguais, e transformar o estado do último comando no estado de
 * ciclo de vida que a API serve.
 */
final class ConfigurationSyncStatusTest extends TestCase
{
    public function testFlattensWritableCapabilitiesToSectionDotKeyPaths(): void
    {
        $flat = $this->subject()->flattenWritableCapabilities('wonlex-json', [
            'telemetry' => ['heart_rate' => ['supported' => true, 'requestable' => true]],
            'alarms' => ['alarm_clock' => ['value' => [['time' => '08:00', 'enabled' => true]], '_meta' => ['limit' => 3]]],
            'settings_system' => ['whitelist_enabled' => ['value' => ['enabled' => true], '_meta' => []]],
        ]);

        self::assertSame(
            ['alarms.alarm_clock', 'settings_system.whitelist_enabled'],
            array_keys($flat),
            'telemetry is not writable and must not be compared'
        );
        self::assertSame([['time' => '08:00', 'enabled' => true]], $flat['alarms.alarm_clock']);
        self::assertSame(['enabled' => true], $flat['settings_system.whitelist_enabled']);
    }

    public function testFlattenSkipsSupportedOnlyEntriesThatCarryNoValue(): void
    {
        $flat = $this->subject()->flattenWritableCapabilities('voerka-ncs', [
            'settings_system' => [
                'nurse_call' => ['supported' => true],
                'volume' => ['value' => ['level' => 3], '_meta' => []],
            ],
        ]);

        self::assertSame(['settings_system.volume'], array_keys($flat));
    }

    public function testSosContactsFlattenToPlainPhoneStrings(): void
    {
        $fromNumbers = $this->subject()->flattenWritableCapabilities('wonlex-json', [
            'contacts' => [
                'sos_contacts' => ['value' => ['numbers' => ['+351911111111', '']], '_meta' => []],
            ],
        ]);
        $fromList = $this->subject()->flattenWritableCapabilities('wonlex-json', [
            'contacts' => [
                'sos_contacts' => ['value' => ['+351911111111'], '_meta' => []],
            ],
        ]);

        self::assertSame(['+351911111111'], $fromNumbers['contacts.sos_contacts'], 'blank slots are dropped');
        self::assertSame(['+351911111111'], $fromList['contacts.sos_contacts']);
    }

    public function testCallWhitelistKeepsContactObjectsForVivistarAndPhonesElsewhere(): void
    {
        $capabilities = [
            'contacts' => [
                'call_whitelist' => [
                    'value' => ['contacts' => [['name' => 'Suporte', 'phone' => '+351278710140']]],
                    '_meta' => [],
                ],
            ],
        ];

        self::assertSame(
            [['name' => 'Suporte', 'phone' => '+351278710140']],
            $this->subject()->flattenWritableCapabilities('vivistar-iw', $capabilities)['contacts.call_whitelist']
        );
        self::assertSame(
            ['+351278710140'],
            $this->subject()->flattenWritableCapabilities('four-p-touch', $capabilities)['contacts.call_whitelist']
        );
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

    /**
     * @dataProvider lifecycleStatuses
     */
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
            'a failure must win regardless of the order the rows arrive in'
        );
    }

    public function testRowMetaIgnoresRowsWithoutAResolvableCapabilityKey(): void
    {
        self::assertSame([], $this->subject()->genericCapabilityRowMeta([
            ['native_key' => '', 'config_key' => '', 'desired_updated_at' => '2026-01-01 10:00:00'],
        ]));
    }

    private function subject(): ConfigurationSyncStatus
    {
        return new ConfigurationSyncStatus();
    }
}
