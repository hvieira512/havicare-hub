<?php

declare(strict_types=1);

namespace Hub\Domain\Capability;

/**
 * O payload de que uma configuração parte quando o dispositivo nunca guardou nenhum. É a única
 * fonte: a dashboard desenha o que a API lhe serve, e não tem valores de partida seus.
 */
final class ConfigurationInputDefaults
{
    /**
     * @param array<string, mixed> $entry uma entrada do catálogo de configuração do protocolo
     * @return array<string, mixed>
     */
    public static function forEntry(array $entry): array
    {
        $fields = array_map('strval', (array)($entry['fields'] ?? []));
        $field = $fields[0] ?? 'value';
        $options = is_array($entry['options'] ?? null) ? $entry['options'] : [];

        // A definição que declara de onde parte ganha a tudo; com um campo só, o valor é desse campo.
        if (array_key_exists('default', $options)) {
            return is_array($options['default']) ? $options['default'] : [$field => $options['default']];
        }

        $firstOption = is_array($options[$field] ?? null) && array_is_list($options[$field])
            ? ($options[$field][0]['value'] ?? null)
            : null;

        return match ((string)($entry['input'] ?? 'json')) {
            'toggle' => [$field => true],
            // Do mínimo: um tom de pele vai de 1 a 6, e zero seria recusado pelo aparelho.
            'number', 'sensitivitySlider' => [$field => $options['min'] ?? $entry['bounds'][$field]['min'] ?? 0],
            'select', 'optionCards', 'volumeScale', 'soundProfile', 'fallSensitivity' => [$field => $firstOption],
            'workingMode' => ['mode' => $firstOption],
            'phone', 'makeCall', 'voiceMonitor' => [$field => ''],
            'pushMessage' => ['message' => ''],
            'intervalToggle' => ['enabled' => true, 'intervalMinutes' => 60],
            'intervalHoursToggle' => ['enabled' => true, 'intervalHours' => 2],
            'windowToggle' => ['enabled' => true, 'range' => '22:00-08:00'],
            'heartRateThresholds' => ['enabled' => true, 'maxBpm' => 150, 'minBpm' => 50],
            'personalInfo' => [
                'heightCm' => 170,
                'weightKg' => 70,
                'age' => 40,
                'sex' => 'female',
                'stepGoal' => 8000,
                'sleepGoalMinutes' => 480,
            ],
            // Vazio quer dizer os nove alarmes desligados, que é um estado legítimo.
            'pillDispenserAlarms' => ['plans' => []],
            // Sem período: o plano vale sempre até alguém dizer o contrário.
            'pillDispenserPeriod' => ['enabled' => false, 'startDate' => '', 'endDate' => ''],
            'pillDispenserQuietHours' => ['enabled' => false, 'startHour' => 22, 'startMinute' => 0, 'endHour' => 7, 'endMinute' => 0],
            'wonlexBloodPressureWarning' => ['enabled' => true, 'systolicMmHg' => 135, 'diastolicMmHg' => 90],
            'dualToggle' => ['enabled' => true, 'callCenterOnFall' => false],
            'fallSensitivityLevels' => ['sensitivity' => 5, 'levels' => 8],
            // Um horário de silêncio pré-preenchido recusava chamadas a quem nunca o pediu.
            'timeRanges' => ['ranges' => []],
            'timeRange' => ['range' => '21:10-07:30'],
            'wonlexSleepSettings' => ['enabled' => true, 'startTime' => '22:00', 'endTime' => '10:00', 'targetMinutes' => 480],
            'wonlexReminderThreshold' => $fields[1] === 'thresholdCelsius'
                ? ['enabled' => true, 'thresholdCelsius' => 38.5]
                : ['enabled' => true, 'thresholdPercent' => 90],
            'wonlexHeartRateRange' => [
                'enabled' => true,
                'thresholdBpm' => 120,
                'exerciseEnabled' => true,
                'exerciseMinBpm' => 100,
                'exerciseMaxBpm' => 140,
                'exerciseThresholdBpm' => 140,
            ],
            // A forma vem do campo nativo: números soltos no 4P Touch, contactos com nome na Vivistar.
            'sos_contacts', 'phonebook', 'call_whitelist' => $field === 'numbers' ? ['numbers' => []] : ['contacts' => []],
            'takePills' => ['plans' => [], 'reminderText' => ''],
            'radarAreas', 'scaleUsers' => ['items' => []],
            default => [],
        };
    }
}
