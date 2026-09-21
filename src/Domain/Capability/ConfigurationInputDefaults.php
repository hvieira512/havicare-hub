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
            // Zero só serve quando está dentro da escala: um tom de pele vai de 1 a 6, e o
            // formulário partia de um valor que o aparelho recusa.
            'number' => [($field(0) ?: 'value') => (int)($entry['options']['min'] ?? 0)],
            // A primeira opção que a definição declara: é a que o `select` mostra escolhida
            // antes de alguém mexer, e um número inventado aqui podia nem existir na lista.
            'select' => (static function () use ($entry, $field): array {
                $name = $field(0) ?: 'value';
                $options = $entry['options'][$name] ?? [];
                // A definição pode dizer de onde parte; senão é a primeira da lista, que numa
                // lista ordenada por valor é a ponta e não o meio.
                $default = $entry['options']['default']
                    ?? (is_array($options) && $options !== [] ? ($options[0]['value'] ?? 0) : 0);

                return [$name => $default];
            })(),
            'phone' => [($field(0) ?: 'phone') => ''],
            'text' => [($field(0) ?: 'value') => ''],
            'pushMessage' => ['message' => ''],
            'makeCall' => ['phone' => ''],
            'action' => [],
            'intervalToggle' => ['enabled' => true, 'intervalMinutes' => 60],
            // A janela é sempre acompanhada de um número quando a definição o declara: o
            // intervalo de um lembrete, o brilho do ecrã. O nome do campo vem da definição, e
            // o ponto de partida também -- um lembrete para beber água a partir das 22:00 de
            // cinco em cinco minutos é um formulário que ninguém quer gravar como está.
            'windowToggle' => (static function () use ($entry): array {
                $default = $entry['options']['default'] ?? [];
                $payload = [
                    'enabled' => (bool)($default['enabled'] ?? true),
                    'range' => (string)($default['range'] ?? '22:00-08:00'),
                ];
                $number = $entry['options']['number']['field'] ?? null;
                if ($number !== null) {
                    $payload[(string)$number] = (int)($default[(string)$number]
                        ?? $entry['options']['number']['min'] ?? 1);
                }

                return $payload;
            })(),
            'heartRateThresholds' => ['enabled' => true, 'maxBpm' => 150, 'minBpm' => 50],
            'personalInfo' => [
                'heightCm' => 170,
                'weightKg' => 70,
                'age' => 40,
                'sex' => 'female',
                'stepGoal' => 8000,
                'sleepGoalMinutes' => 480,
            ],
            'intervalHoursToggle' => ['enabled' => true, 'intervalHours' => 2],
            // O dispensador. O plano parte vazio de propósito: vazio quer dizer os nove
            // alarmes desligados, que é um estado legítimo e não um formulário por preencher.
            'pillDispenserAlarms' => ['plans' => []],
            // Sem período por omissão: o plano vale sempre até alguém dizer o contrário.
            'pillDispenserPeriod' => ['enabled' => false, 'startDate' => '', 'endDate' => ''],
            'pillDispenserQuietHours' => [
                'enabled' => false,
                'startHour' => 22,
                'startMinute' => 0,
                'endHour' => 7,
                'endMinute' => 0,
            ],
            'pillDispenserDispenseMode' => ['earlyRetrieval' => false, 'childLock' => false],
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
