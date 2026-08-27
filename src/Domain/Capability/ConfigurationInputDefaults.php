<?php

namespace Hub\Domain\Capability;

/**
 * O payload de que uma capacidade parte quando um dispositivo nunca guardou nenhum.
 *
 * Indexado pelo tipo de campo da entrada de configuração do protocolo, para a dashboard
 * desenhar um formulário utilizável em vez de um vazio. Num sítio só: o
 * `DeviceCapabilityPresenter` e o `CapabilityRegistry` traziam cada um a sua cópia desta
 * tabela, e as cópias já tinham divergido.
 */
final class ConfigurationInputDefaults
{
    /**
     * @param array<string, mixed> $entry a protocol configuration catalog entry
     * @return array<string, mixed>
     */
    public static function forEntry(array $entry): array
    {
        $input = (string)($entry['input'] ?? 'json');
        $field = static fn(int $index = 0): string => (string)($entry['fields'][$index] ?? '');

        return match ($input) {
            'toggle' => [($field(0) ?: 'enabled') => true],
            'number' => [($field(0) ?: 'value') => 0],
            'phone' => [($field(0) ?: 'phone') => ''],
            'text' => [($field(0) ?: 'value') => ''],
            'pushMessage' => ['message' => ''],
            'makeCall' => ['phone' => ''],
            'resetAction', 'requestAction' => [],
            'intervalToggle' => ['enabled' => true, 'intervalMinutes' => 60],
            'intervalHoursToggle' => ['enabled' => true, 'intervalHours' => 2],
            'workingMode' => ['mode' => 1],
            'bloodPressure' => ['systolic' => 120, 'diastolic' => 80],
            // O `BPEarlyWarning` leva um limiar sistólico e um diastólico, e o construtor de
            // payloads exige os dois.
            'wonlexBloodPressureWarning' => ['switchState' => true, 'hpWarn' => 135, 'LPWarn' => 90],
            'languageTimezone' => ['language' => 0, 'timeZone' => '0'],
            'dualToggle' => ['enabled' => true, 'callCenterOnFall' => false],
            'fallSensitivityLevels' => ['sensitivity' => 5, 'levels' => 8],
            'timeRanges' => ['ranges' => ['08:10-09:30']],
            'timeRange' => ['range' => '21:10-07:30'],
            'wonlexSleepSettings' => [
                'switchState' => true,
                'sleepStartTime' => '220000',
                'sleepEndTime' => '100000',
                'sleepTarget' => 480,
            ],
            'wonlexReminderThreshold' => ['switchState' => true, ($field(1) ?: 'reminderValue') => 90],
            'wonlexHeartRateRange' => [
                'switchState' => true,
                'remindValue' => 120,
                'exerciseSwitchState' => true,
                'exerciseHRMin' => 100,
                'exerciseHRMax' => 140,
                'exerciseRemindValue' => 140,
            ],
            'list' => ['numbers' => array_fill(0, max(1, (int)($entry['limit'] ?? 3)), '')],
            'contacts' => ['contacts' => [['name' => '', 'phone' => '']]],
            'takePills' => [
                'reminderSettings' => [
                    ['time' => '08:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                    ['time' => '09:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                    ['time' => '10:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                ],
                'number' => 1,
                'reminderText' => '',
                'voiceData' => '',
                'voiceMimeType' => 'audio/webm',
            ],
            'soundProfile' => ['mode' => 1],
            default => [],
        };
    }
}
