<?php

namespace Hub\Command\Configuration\Payload;

/**
 * Valida o que se configura numa pulseira Veepoo.
 *
 * Não monta tramas: quem as monta é o SDK dentro do gateway, e o payload chega lá tal e
 * qual. O que este construtor faz é impedir que lá chegue coisa que o aparelho não sabe
 * ler -- uma janela sem traço, um tom de pele fora da escala --, porque o SDK aceita-os em
 * silêncio e não configura nada.
 */
final class VeepooPayloadBuilder extends ConfigurationPayloadBuilder
{
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
            'skin_tone' => ['level' => self::rangeInt($payload['level'] ?? null, 1, 6, 'level')],
            'personal_info' => self::personalInfo($payload),
            // Os interruptores e o «encontrar dispositivo»: só um booleano.
            default => ['enabled' => (bool)self::boolInt($payload['enabled'] ?? null, 'enabled')],
        };
    }

    /**
     * A pulseira calcula calorias e composição corporal a partir disto. Os limites são de
     * plausibilidade humana e não do protocolo: o que está em causa é telemetria calculada
     * sobre um corpo que não existe.
     */
    private static function personalInfo(array $payload): array
    {
        $sex = is_string($payload['sex'] ?? null) ? $payload['sex'] : '';
        if (!in_array($sex, ['female', 'male'], true)) {
            throw new \InvalidArgumentException('sex must be female or male');
        }

        return [
            'heightCm' => self::rangeInt($payload['heightCm'] ?? null, 50, 250, 'heightCm'),
            'weightKg' => self::rangeInt($payload['weightKg'] ?? null, 10, 300, 'weightKg'),
            'age' => self::rangeInt($payload['age'] ?? null, 1, 120, 'age'),
            'sex' => $sex,
            'stepGoal' => self::rangeInt($payload['stepGoal'] ?? null, 100, 100000, 'stepGoal'),
            'sleepGoalMinutes' => self::rangeInt($payload['sleepGoalMinutes'] ?? null, 60, 900, 'sleepGoalMinutes'),
        ];
    }

    /**
     * Os limiares do alarme de frequência cardíaca.
     *
     * Quem os avalia é o aparelho, sobre a medição dele. Um mínimo acima do máximo passava
     * nas validações de cada campo e deixava o alarme impossível de disparar.
     *
     * @return array<string, mixed>
     */
    private static function heartRateThresholds(array $payload): array
    {
        $max = self::rangeInt($payload['maxBpm'] ?? null, 40, 220, 'maxBpm');
        $min = self::rangeInt($payload['minBpm'] ?? null, 30, 200, 'minBpm');
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
        $range = is_string($value) ? $value : '';
        if (preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $range, $m) !== 1) {
            throw new \InvalidArgumentException('range must be HH:MM-HH:MM');
        }
        if ((int)$m[1] > 23 || (int)$m[3] > 23 || (int)$m[2] > 59 || (int)$m[4] > 59) {
            throw new \InvalidArgumentException('range has an impossible time');
        }

        return $range;
    }
}
