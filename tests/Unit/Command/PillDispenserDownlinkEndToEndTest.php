<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** As TAGs esperadas são escritas à mão da secção 5 da especificação, para o teste não concordar consigo próprio. */
final class PillDispenserDownlinkEndToEndTest extends TestCase
{
    private const IMEI = '869243062262262';

    /**
     * @return iterable<string, array{string, array<string, mixed>, int, list<int>}>
     */
    public static function everyConfiguration(): iterable
    {
        yield 'screen language' => ['device_language', ['language' => 1], 0x06, [0x1001]];
        yield 'plan period' => [
            'medication_period',
            ['enabled' => true, 'startDate' => '2026-09-02', 'endDate' => '2026-09-04'],
            0x06,
            [0x1004, 0x1005, 0x1006, 0x1007, 0x1008, 0x1009, 0x100A],
        ];
        yield 'child lock' => ['child_lock', ['enabled' => true], 0x06, [0x100C]];
        yield 'early dispense' => ['early_dispense', ['enabled' => true], 0x06, [0x100D]];
        yield 'dispense after missing' => ['missed_dispense', ['enabled' => true], 0x06, [0x1019]];
        yield 'emergency call' => ['emergency_call', ['enabled' => false], 0x06, [0x100E]];
        yield 'date format' => ['date_format', ['format' => 1], 0x06, [0x1002]];
        yield 'time format' => ['time_format', ['format' => 0], 0x06, [0x1003]];
        yield 'key sound' => ['key_tone', ['enabled' => true], 0x06, [0x100B]];
        yield 'automatic clock sync' => ['auto_clock', ['enabled' => true], 0x06, [0x1014]];
        yield 'ringtone' => ['alarm_ringtone', ['ringtone' => 2], 0x06, [0x1012]];
        yield 'volume' => ['alarm_volume', ['volume' => 1], 0x06, [0x1013]];
        yield 'time zone' => ['time_zone', ['timeZone' => 100], 0x06, [0x1015]];
        yield 'late warning' => ['retrieval_warning', ['minutes' => 30], 0x06, [0x1017]];
        yield 'mark as missed' => ['retrieval_timeout', ['minutes' => 60], 0x06, [0x1018]];
        yield 'loaded compartments' => ['loaded_cells', ['cells' => 14], 0x06, [0x101C]];
        yield 'do not disturb' => [
            'do_not_disturb',
            ['enabled' => true, 'startHour' => 18, 'startMinute' => 0, 'endHour' => 9, 'endMinute' => 0],
            0x06,
            [0x1051, 0x1052, 0x1053, 0x1054, 0x1055],
        ];
        yield 'medication plan' => [
            'medication_reminders',
            ['plans' => [['slot' => 1, 'hour' => 9, 'minute' => 35, 'enabled' => true]]],
            0x06,
            [
                0x1021, 0x1031, 0x1041, 0x1022, 0x1032, 0x1042, 0x1023, 0x1033, 0x1043,
                0x1024, 0x1034, 0x1044, 0x1025, 0x1035, 0x1045, 0x1026, 0x1036, 0x1046,
                0x1027, 0x1037, 0x1047, 0x1028, 0x1038, 0x1048, 0x1029, 0x1039, 0x1049,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<int> $tags
     */
    #[DataProvider('everyConfiguration')]
    public function testThePublicKeyReachesTheTagsTheSpecAssignsIt(
        string $key,
        array $payload,
        int $packetType,
        array $tags,
    ): void {
        $decoded = $this->dispatch($key, $payload);

        self::assertSame($packetType, $decoded['packetType'], $key);
        self::assertSame($tags, array_keys($decoded['tlv']), $key);
    }

    /**
     * Uma definição que o validador não conheça sai vazia, e o aparelho aceita o zero por omissão.
     *
     * @return iterable<string, array{string, array<string, mixed>, int, int}>
     */
    public static function chosenValues(): iterable
    {
        yield 'language' => ['device_language', ['language' => 1], 0x1001, 1];
        yield 'lock' => ['child_lock', ['enabled' => true], 0x100C, 1];
        yield 'ringtone' => ['alarm_ringtone', ['ringtone' => 2], 0x1012, 2];
        yield 'volume' => ['alarm_volume', ['volume' => 1], 0x1013, 1];
        yield 'late warning' => ['retrieval_warning', ['minutes' => 30], 0x1017, 1800];
        yield 'mark as missed' => ['retrieval_timeout', ['minutes' => 60], 0x1018, 3600];
        yield 'compartments' => ['loaded_cells', ['cells' => 14], 0x101C, 14];
        yield 'hour of the first alarm' => [
            'medication_reminders',
            ['plans' => [['slot' => 1, 'hour' => 9, 'minute' => 35, 'enabled' => true]]],
            0x1021,
            9,
        ];
        yield 'minute of the first alarm' => [
            'medication_reminders',
            ['plans' => [['slot' => 1, 'hour' => 9, 'minute' => 35, 'enabled' => true]]],
            0x1031,
            35,
        ];
        yield 'start of the quiet period' => [
            'do_not_disturb',
            ['enabled' => true, 'startHour' => 18, 'startMinute' => 0, 'endHour' => 9, 'endMinute' => 0],
            0x1052,
            18,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('chosenValues')]
    public function testTheChosenValueReachesTheFrame(
        string $key,
        array $payload,
        int $tag,
        int $value,
    ): void {
        $raw = (string)($this->dispatch($key, $payload)['tlv'][$tag]['value'] ?? '');

        $read = match (strlen($raw)) {
            1 => ord($raw),
            2 => unpack('v', $raw)[1],
            4 => unpack('V', $raw)[1],
            default => -1,
        };

        self::assertSame($value, $read, sprintf('%s na TAG 0x%04X', $key, $tag));
    }

    /** O provider acima é escrito à mão, e isto exercita uma definição nova do catálogo que ninguém lá pôs. */
    public function testEveryStoredConfigurationIsCovered(): void
    {
        $covered = [];
        foreach (self::everyConfiguration() as [$key]) {
            $covered[] = $key;
        }

        $declared = [];
        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            if (($entry['transient'] ?? false) !== true) {
                $declared[] = (string)$entry['key'];
            }
        }

        sort($covered);
        sort($declared);
        self::assertSame($declared, $covered);
    }

    /** Uma escrita `0x06` confirma-se com `0x86` e uma ordem `0x08` com `0x88`; a resposta errada deixa o pedido pendente. */
    public function testTheExpectedReplyMatchesThePacketThatIsSent(): void
    {
        $expected = [0x06 => 'write_config_ack', 0x05 => 'read_config_ack', 0x08 => 'control_ack'];

        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            $key = (string)$entry['key'];
            $bytes = DeviceCommandCatalog::buildDownlink(
                'zayata-m228',
                self::IMEI,
                (string)$entry['command'],
                self::sampleFor($key),
            );
            $decoded = (new PillDispenserAdapter())->decodeIncoming($bytes);
            self::assertIsArray($decoded, $key);

            $packetType = (int)$decoded['packetType'];
            if (!isset($expected[$packetType])) {
                continue;
            }

            self::assertSame([$expected[$packetType]], $entry['expectedReplyTypes'], $key);
        }
    }

    /** Um valor que o aparelho recusaria pára na validação, onde a API dá 400, e não no construtor da trama. */
    public function testAnIllegalValueStopsAtTheValidationStep(): void
    {
        self::assertSame(
            'volume must be between 0 and 3',
            DeviceConfigurationCatalog::validate('zayata-m228', 'alarm_volume', ['volume' => 9]),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function dispatch(string $key, array $payload): array
    {
        $entry = DeviceConfigurationCatalog::configForProtocol('zayata-m228', $key);
        self::assertIsArray($entry, "o catálogo não conhece {$key}");
        self::assertNull(DeviceConfigurationCatalog::validate('zayata-m228', $key, $payload), $key);

        $built = DeviceConfigurationCatalog::commandPayloads('zayata-m228', $key, $payload);
        self::assertCount(1, $built, $key);

        $decoded = (new PillDispenserAdapter())->decodeIncoming(DeviceCommandCatalog::buildDownlink(
            'zayata-m228',
            self::IMEI,
            $built[0]['command'],
            $built[0]['payload'],
        ));
        self::assertIsArray($decoded, $key);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function sampleFor(string $key): array
    {
        foreach (self::everyConfiguration() as [$candidate, $payload]) {
            if ($candidate === $key) {
                return $payload;
            }
        }

        return [];
    }
}
