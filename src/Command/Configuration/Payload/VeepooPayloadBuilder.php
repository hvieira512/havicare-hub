<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Payload;

/**
 * Valida o que se configura numa pulseira Veepoo. As tramas monta-as o SDK no gateway, que
 * aceita em silêncio o que o aparelho não sabe ler.
 */
final class VeepooPayloadBuilder extends ConfigurationPayloadBuilder
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function build(string $key, array $payload): array
    {
        return match ($key) {
            'blood_oxygen_window' => [
                'enabled' => (bool)self::boolInt($payload['enabled'] ?? null, 'enabled'),
                'range' => self::window($payload['range'] ?? null),
            ],
            'heart_rate_alert' => self::heartRateThresholds($payload),
            // Seis posições nesta pulseira, da mais clara para a mais escura. Qual é a régua
            // vem do resumo de funções do aparelho -- `skinColorType` 0 dá duas posições.
            'skin_tone' => ['level' => self::intInRange($payload['level'] ?? null, 'level', 1, 6)],
            'personal_info' => self::personalInfo($payload),
            // Os interruptores e o «encontrar dispositivo»: só um booleano.
            default => ['enabled' => (bool)self::boolInt($payload['enabled'] ?? null, 'enabled')],
        };
    }

    /**
     * Limites de plausibilidade humana e não do protocolo: a pulseira calcula calorias e
     * composição corporal a partir disto.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function personalInfo(array $payload): array
    {
        $sex = is_string($payload['sex'] ?? null) ? $payload['sex'] : '';
        if (!in_array($sex, ['female', 'male'], true)) {
            throw new \InvalidArgumentException('sex must be female or male');
        }

        return [
            'heightCm' => self::intInRange($payload['heightCm'] ?? null, 'heightCm', 50, 250),
            'weightKg' => self::intInRange($payload['weightKg'] ?? null, 'weightKg', 10, 300),
            'age' => self::intInRange($payload['age'] ?? null, 'age', 1, 120),
            'sex' => $sex,
            'stepGoal' => self::intInRange($payload['stepGoal'] ?? null, 'stepGoal', 100, 100000),
            'sleepGoalMinutes' => self::intInRange($payload['sleepGoalMinutes'] ?? null, 'sleepGoalMinutes', 60, 900),
        ];
    }

    /**
     * Os limiares do alarme de frequência cardíaca, avaliados pelo aparelho. Um mínimo acima do
     * máximo deixava o alarme impossível de disparar.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function heartRateThresholds(array $payload): array
    {
        $max = self::intInRange($payload['maxBpm'] ?? null, 'maxBpm', 40, 220);
        $min = self::intInRange($payload['minBpm'] ?? null, 'minBpm', 30, 200);
        if ($min >= $max) {
            throw new \InvalidArgumentException('minBpm must be below maxBpm');
        }

        return [
            'enabled' => (bool)self::boolInt($payload['enabled'] ?? null, 'enabled'),
            'maxBpm' => $max,
            'minBpm' => $min,
        ];
    }

    /** `HH:MM-HH:MM`, o mesmo formato do `timeRange` dos relógios. */
    private static function window(mixed $value): string
    {
        $range = explode('-', is_string($value) ? $value : '');
        if (count($range) !== 2) {
            throw new \InvalidArgumentException('range must be HH:MM-HH:MM');
        }

        return self::hhmm($range[0], 'range') . '-' . self::hhmm($range[1], 'range');
    }
}
