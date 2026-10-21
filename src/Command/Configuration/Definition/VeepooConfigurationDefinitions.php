<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

/**
 * O que se configura numa pulseira Veepoo: só o que muda o que ela mede ou calcula. O
 * `command` é o nome da operação na ponte, porque quem escreve é o gateway com a sessão BLE.
 */
final class VeepooConfigurationDefinitions
{
    /**
     * Chave genérica do hub => secção, pela ordem que faz sentido ler. O `blood_oxygen_alert` não
     * é medição contínua de oxigénio, que a pulseira faz sempre: é o despertar por hipoxia.
     */
    private const SWITCHES = [
        'heart_rate_continuous' => 'health',
        'blood_pressure_trend' => 'health',
        'temperature_continuous' => 'health',
        'hrv_continuous' => 'health',
        'blood_sugar_continuous' => 'health',
        'blood_lipids_continuous' => 'health',
        'stress_continuous' => 'health',
        'sleep_monitoring' => 'health',
        'blood_oxygen_alert' => 'alarms',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $entry = static fn(string $key, string $input, array $fields, string $category, int $order, mixed ...$named): array
            => ConfigurationDefinition::make($key, 'config:' . $key, $input, $fields, ['monitoring'], $category, $order, ...$named);

        $order = 10;
        $configs = [];
        foreach (self::SWITCHES as $key => $section) {
            $configs[] = $entry($key, 'toggle', ['enabled'], $section, $order);
            $order += 10;
        }

        return [
            ...$configs,
            $entry('blood_oxygen_window', 'windowToggle', ['enabled', 'range'], 'health', $order),
            // Os limiares são avaliados pelo aparelho sobre a medição dele, e não pela aplicação.
            $entry('heart_rate_alert', 'heartRateThresholds', ['enabled', 'maxBpm', 'minBpm'], 'alarms', $order + 10),
            // A potência do LED do sensor ótico, de que sai tudo o que a pulseira mede por luz.
            $entry('skin_tone', 'number', ['level'], 'health', $order + 20, options: ['min' => 1, 'max' => 6]),
            // O corpo com que ela calcula calorias e composição corporal, e não quem a usa.
            $entry('personal_info', 'personalInfo', ['heightCm', 'weightKg', 'age', 'sex', 'stepGoal', 'sleepGoalMinutes'], 'health', $order + 30),
            // Vibra quando recebe a ordem e não guarda estado: pede-se por `/requests`.
            ConfigurationDefinition::make(
                'find_device',
                'config:find_device',
                'toggle',
                ['enabled'],
                ['find_device'],
                'settings_system',
                $order + 40,
                transient: true,
                actions: ['on' => 'vibrate', 'off' => 'stop'],
            ),
        ];
    }
}
