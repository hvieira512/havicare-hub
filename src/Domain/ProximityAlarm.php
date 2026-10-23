<?php

declare(strict_types=1);

namespace Hub\Domain;

/**
 * O alarme de proximidade de um gateway: dispara quando um dispositivo que ele retransmite chega
 * perto. O hub não sabe onde o gateway está, e por isso o alarme não diz «porta».
 *
 * Dispara pelo máximo da janela do `ProximityTracker` e não pela mediana: uma passagem rápida dá
 * uma ou duas leituras fortes, e o ruído quase só atenua (`docs/05-gateways-ble.md` §5).
 */
final class ProximityAlarm
{
    public const MODES = ['off', 'calibrating', 'active'];

    /** @var array{int, int} */
    public const TRIGGER_DBM_BOUNDS = [-100, -30];

    /** O teto é o `maxSamples` da janela: mais do que isso nunca se juntava. */
    public const MIN_SAMPLES_BOUNDS = [1, 10];

    /** @var array{int, int} */
    public const COOLDOWN_SECONDS_BOUNDS = [0, 3600];

    /** @return array{mode: string, devices: 'all'|list<string>, triggerDbm: int, minSamples: int, cooldownSeconds: int} */
    public static function defaults(): array
    {
        return ['mode' => 'off', 'devices' => 'all', 'triggerDbm' => -58, 'minSamples' => 3, 'cooldownSeconds' => 60];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function watches(array $settings, string $deviceKey): bool
    {
        if (($settings['mode'] ?? 'off') === 'off') {
            return false;
        }
        $devices = $settings['devices'] ?? 'all';

        return $devices === 'all'
            || (is_array($devices) && in_array(strtolower($deviceKey), $devices, true));
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $window os dados da `proximity`
     */
    public static function fires(array $settings, array $window): bool
    {
        $max = $window['rssiMaxDbm'] ?? null;

        return ($window['state'] ?? '') === 'measured'
            && (int)($window['samples'] ?? 0) >= (int)($settings['minSamples'] ?? self::defaults()['minSamples'])
            && is_numeric($max)
            && (int)$max >= (int)($settings['triggerDbm'] ?? self::defaults()['triggerDbm']);
    }

    /**
     * Completa com os valores por omissão e recusa o que as regras não sabem usar.
     *
     * @return array{mode: string, devices: 'all'|list<string>, triggerDbm: int, minSamples: int, cooldownSeconds: int}
     */
    public static function sanitize(mixed $value): array
    {
        $input = (is_array($value) ? $value : []) + self::defaults();

        $mode = (string)$input['mode'];
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('mode must be one of: ' . implode(', ', self::MODES));
        }

        $devices = $input['devices'];
        if (is_array($devices)) {
            $devices = array_values(array_unique(array_filter(array_map(
                static fn(mixed $key): string => strtolower(trim((string)$key)),
                $devices,
            ))));
        } elseif ($devices !== 'all') {
            throw new \InvalidArgumentException('devices must be "all" or a list of device ids');
        }

        return [
            'mode' => $mode,
            'devices' => $devices,
            'triggerDbm' => self::bounded($input, 'triggerDbm', self::TRIGGER_DBM_BOUNDS),
            'minSamples' => self::bounded($input, 'minSamples', self::MIN_SAMPLES_BOUNDS),
            'cooldownSeconds' => self::bounded($input, 'cooldownSeconds', self::COOLDOWN_SECONDS_BOUNDS),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array{int, int} $bounds
     */
    private static function bounded(array $input, string $field, array $bounds): int
    {
        $value = $input[$field];
        if (!is_numeric($value) || (int)$value < $bounds[0] || (int)$value > $bounds[1]) {
            throw new \InvalidArgumentException(sprintf('%s must be between %d and %d', $field, $bounds[0], $bounds[1]));
        }

        return (int)$value;
    }
}
