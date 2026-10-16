<?php

declare(strict_types=1);

namespace Hub\Ingress\Http\Qinglanst;

/**
 * As configurações do hub de e para a API Cloud2cloud. Cada escrita parte do que o radar tem
 * nesse momento (o `deviceProp`): os pedidos com vários campos levam os que não mudaram como lá
 * estão, porque um campo que falte volta ao valor de fábrica.
 */
final class QinglanstCloudCommands
{
    private const PROP = '/thirdparty/v2/prop';
    private const THRESHOLD = '/thirdparty/v2/threshold';
    private const BORDER = '/thirdparty/v2/border';
    private const COORDS = '/thirdparty/v2/coords';

    /** O `scene` que se escreve, por modo. */
    private const SCENES = [
        'full_function' => '1',
        'fall_detection' => '2',
        'people_tracking' => '3',
        'sleep_monitoring' => '4',
    ];

    /** O `radar_func_ctrl` que se lê é uma máscara de funções, e não o `scene`. */
    private const FUNCTIONS = [
        15 => 'full_function',
        7 => 'fall_detection',
        3 => 'people_tracking',
        11 => 'sleep_monitoring',
    ];

    /** Os vértices de fronteira e área que mandam o radar medir os limites sozinho, e parar. */
    private const MEASURE_START = '-127,-127';
    private const MEASURE_STOP = '127,127';

    public function __construct(private readonly LayoutParser $layouts = new LayoutParser())
    {
    }

    /**
     * O que o radar tem, na forma das configurações do hub. Só entra o que o `deviceProp` traz.
     *
     * @param array<string, mixed> $props
     * @return array<string, array<string, mixed>>
     */
    public function configurations(array $props): array
    {
        $configurations = [];
        $thresholds = self::thresholds($props);
        if ($thresholds !== null) {
            $configurations['vital_sign_sensitivity'] = ['sensitivity' => $thresholds['sensitivity'] ?: 5];
            $configurations['breath_rate_alert'] = [
                'minPerMinute' => $thresholds['lowerBreathe'],
                'maxPerMinute' => $thresholds['upperBreathe'],
            ];
            $configurations['heart_rate_alert'] = [
                'minBpm' => $thresholds['lowerHeartRate'],
                'maxBpm' => $thresholds['upperHeartRate'],
            ];
            if (isset($props['suddenDeathSwitch'])) {
                $configurations['weak_vital_signs_alarm'] = [
                    // No fio, `0` liga e `1` desliga.
                    'enabled' => (string)$props['suddenDeathSwitch'] === '0',
                    'afterMinutes' => $thresholds['suddenDuration'] ?: 10,
                ];
            }
        }

        $posture = self::posture($props);
        if ($posture !== null) {
            $configurations['fall_alarm'] = ['afterSeconds' => self::seconds($posture['fall'], 3)];
            $configurations['sitting_on_floor_alarm'] = [
                'enabled' => ($posture['bits'] & 0b001) !== 0,
                'afterSeconds' => self::seconds($posture['sitting'], 10),
            ];
        }

        $mode = self::FUNCTIONS[(int)($props['radar_func_ctrl'] ?? -1)] ?? null;
        if ($mode !== null) {
            $configurations['working_mode'] = ['mode' => $mode];
        }

        if (isset($props['radar_install_style'], $props['radar_install_height'])) {
            $configurations['radar_installation'] = [
                'mounting' => self::mounting($props),
                'heightCm' => (int)$props['radar_install_height'] * 10,
            ];
        }

        $layout = $this->layouts->parse($props);
        if ($layout !== null) {
            $room = $layout['room'];
            $configurations['detection_boundary'] = [
                'leftM' => self::metres(-$room['x_min_dm']),
                'rightM' => self::metres($room['x_max_dm']),
                'frontM' => self::metres($room['y_max_dm']),
                'backM' => self::metres(-$room['y_min_dm']),
            ];
            $configurations['radar_areas'] = ['items' => array_map(static fn (array $area): array => [
                'key' => (int)$area['key'],
                'type' => (int)$area['type'],
                'name' => (string)$area['name'],
                'xMinDm' => (int)$area['x_min_dm'],
                'yMinDm' => (int)$area['y_min_dm'],
                'xMaxDm' => (int)$area['x_max_dm'],
                'yMaxDm' => (int)$area['y_max_dm'],
            ], $layout['areas'])];
        }

        return $configurations;
    }

    /**
     * O sinal, a inclinação e o firmware: mudam sozinhos ou identificam o aparelho, e por isso não
     * são configuração.
     *
     * @param array<string, mixed> $props
     * @return array<string, array<string, mixed>>
     */
    public function telemetry(array $props): array
    {
        $telemetry = [];
        $signal = $props['signal_intensity'] ?? null;
        if (is_numeric($signal)) {
            // O Wi-Fi vem em dBm, negativo; o 4G vem em CSQ, de 0 a 31.
            $telemetry['connectivity'] = (int)$signal < 0
                ? ['interface' => 'wifi', 'signalStrengthDbm' => (int)$signal]
                : ['interface' => '4g', 'signalQuality' => (int)$signal];
        }

        $tilt = explode(':', (string)($props['accelera'] ?? ''));
        if (count($tilt) >= 4 && is_numeric($tilt[0]) && is_numeric($tilt[1]) && is_numeric($tilt[2])) {
            $telemetry['tilt'] = [
                'xDegrees' => (float)$tilt[0],
                'yDegrees' => (float)$tilt[1],
                'zDegrees' => (float)$tilt[2],
                'factoryCalibrated' => trim($tilt[3]) === '1',
            ];
        }

        $version = trim((string)($props['radar_compile_time'] ?? ''));
        $communication = trim((string)($props['app_compile_time'] ?? ''));
        if ($version !== '' || $communication !== '') {
            $telemetry['firmware_version'] = array_filter(
                ['version' => $version, 'communicationVersion' => $communication],
                static fn (string $value): bool => $value !== '',
            );
        }

        return $telemetry;
    }

    /**
     * Os pedidos de escrita de uma configuração, sobre o que o radar tem agora.
     *
     * @param array<string, mixed> $payload já validado pelo `QinglanstPayloadBuilder`
     * @param array<string, mixed> $props
     * @return list<array{path: string, body: array<string, mixed>}>
     */
    public function requests(string $key, array $payload, array $props, string $uid): array
    {
        return match ($key) {
            'vital_sign_sensitivity' => [self::threshold($uid, $props, ['sensitivity' => (int)$payload['sensitivity']])],
            'breath_rate_alert' => [self::threshold($uid, $props, [
                'lowerBreathe' => (int)$payload['minPerMinute'],
                'upperBreathe' => (int)$payload['maxPerMinute'],
            ])],
            'heart_rate_alert' => [self::threshold($uid, $props, [
                'lowerHeartRate' => (int)$payload['minBpm'],
                'upperHeartRate' => (int)$payload['maxBpm'],
            ])],
            'weak_vital_signs_alarm' => [
                self::prop($uid, [
                    'scene' => self::scene($props),
                    'suddenDeathSwitch' => ($payload['enabled'] ?? false) === true ? '0' : '1',
                ]),
                self::threshold($uid, $props, ['suddenDuration' => (int)$payload['afterMinutes']]),
            ],
            'fall_alarm' => [self::prop($uid, ['postureParams' => self::postureWith($props, fall: intdiv((int)$payload['afterSeconds'], 10))])],
            'sitting_on_floor_alarm' => [self::prop($uid, ['postureParams' => self::postureWith(
                $props,
                sitting: intdiv((int)$payload['afterSeconds'], 10),
                sittingEnabled: ($payload['enabled'] ?? false) === true,
            )])],
            'working_mode' => [self::prop($uid, ['scene' => self::SCENES[(string)$payload['mode']]])],
            'radar_installation' => [self::prop($uid, [
                'installType' => $payload['mounting'] === 'wall' ? '1' : '0',
                'height' => intdiv((int)$payload['heightCm'], 10),
            ])],
            'detection_boundary' => [self::border($uid, $props, $payload)],
            'measure_boundary' => [self::measure($uid, ($payload['enabled'] ?? false) === true)],
            'radar_areas' => $this->areaRequests($uid, $props, (array)($payload['items'] ?? [])),
            default => throw new \InvalidArgumentException("Unsupported qinglanst-radar configuration {$key}"),
        };
    }

    /**
     * @param array<string, mixed> $body
     * @return array{path: string, body: array<string, mixed>}
     */
    private static function prop(string $uid, array $body): array
    {
        return ['path' => self::PROP, 'body' => ['uid' => $uid] + $body];
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, int> $changes
     * @return array{path: string, body: array<string, mixed>}
     */
    private static function threshold(string $uid, array $props, array $changes): array
    {
        $current = self::thresholds($props) ?? throw new \InvalidArgumentException('The radar returned no heart_breath_param');

        return ['path' => self::THRESHOLD, 'body' => ['uid' => $uid] + array_replace($current, $changes)];
    }

    /**
     * Os sete primeiros números do `heart_breath_param`, cada um num byte sem sinal.
     *
     * @param array<string, mixed> $props
     * @return array{upperBreathe: int, upperHeartRate: int, lowerBreathe: int, lowerHeartRate: int, intensiveCare: int, suddenDuration: int, sensitivity: int}|null
     */
    private static function thresholds(array $props): ?array
    {
        preg_match_all('/-?\d+/', (string)($props['heart_breath_param'] ?? ''), $matches);
        $values = array_map(static fn (string $value): int => (int)$value & 0xFF, $matches[0]);
        if (count($values) < 7) {
            return null;
        }

        return [
            'upperBreathe' => $values[0],
            'upperHeartRate' => $values[1],
            'lowerBreathe' => $values[2],
            'lowerHeartRate' => $values[3],
            'intensiveCare' => $values[4],
            'suddenDuration' => $values[5],
            'sensitivity' => $values[6],
        ];
    }

    /**
     * A cadeia `queda,bits,sentado`, os tempos em blocos de 10 s. O bit 0 é o alarme de sentado no
     * chão; os outros ficam como estão.
     *
     * @param array<string, mixed> $props
     * @return array{fall: int, bits: int, sitting: int}|null
     */
    private static function posture(array $props): ?array
    {
        $parts = array_map('trim', explode(',', (string)($props['postureParams'] ?? '')));
        if (count($parts) < 3 || !is_numeric($parts[0]) || !is_numeric($parts[1]) || !is_numeric($parts[2])) {
            return null;
        }

        return ['fall' => (int)$parts[0], 'bits' => (int)$parts[1], 'sitting' => (int)$parts[2]];
    }

    /** @param array<string, mixed> $props */
    private static function postureWith(array $props, ?int $fall = null, ?int $sitting = null, ?bool $sittingEnabled = null): string
    {
        $current = self::posture($props) ?? ['fall' => 6, 'bits' => 0, 'sitting' => 10];
        $bits = $current['bits'];
        if ($sittingEnabled !== null) {
            $bits = $sittingEnabled ? $bits | 0b001 : $bits & ~0b001;
        }

        // Um tempo a zero é o valor de fábrica do radar; o hub escreve sempre um explícito.
        return implode(',', [
            $fall ?? ($current['fall'] ?: 3),
            $bits,
            $sitting ?? ($current['sitting'] ?: 10),
        ]);
    }

    /** Zero é o valor de fábrica, em blocos de 10 s. */
    private static function seconds(int $tens, int $factory): int
    {
        return ($tens > 0 ? $tens : $factory) * 10;
    }

    /**
     * Os quatro vértices em decímetros, `x,y`. Na parede o eixo Y começa no radar.
     *
     * @param array<string, mixed> $props
     * @param array<string, mixed> $payload
     * @return array{path: string, body: array<string, mixed>}
     */
    private static function border(string $uid, array $props, array $payload): array
    {
        $left = -self::decimetres($payload['leftM']);
        $right = self::decimetres($payload['rightM']);
        $front = self::decimetres($payload['frontM']);
        $back = self::mounting($props) === 'wall' ? 0 : -self::decimetres($payload['backM']);

        return ['path' => self::BORDER, 'body' => [
            'uid' => $uid,
            'lowLeft' => "{$left},{$back}",
            'lowRight' => "{$right},{$back}",
            'upLeft' => "{$left},{$front}",
            'upRight' => "{$right},{$front}",
        ]];
    }

    /** @return array{path: string, body: array<string, mixed>} */
    private static function measure(string $uid, bool $start): array
    {
        $vertex = $start ? self::MEASURE_START : self::MEASURE_STOP;

        return ['path' => self::BORDER, 'body' => [
            'uid' => $uid,
            'lowLeft' => $vertex,
            'lowRight' => $vertex,
            'upLeft' => $vertex,
            'upRight' => $vertex,
        ]];
    }

    /**
     * Um pedido por área que mudou ou é nova, e um com o tipo 0 por cada que o radar tem e o hub
     * já não quer. As que ficam iguais não saem.
     *
     * @param array<string, mixed> $props
     * @param array<int|string, mixed> $items
     * @return list<array{path: string, body: array<string, mixed>}>
     */
    private function areaRequests(string $uid, array $props, array $items): array
    {
        $current = [];
        foreach ($this->layouts->parse($props)['areas'] ?? [] as $area) {
            $current[(int)$area['key']] = [
                (int)$area['type'],
                (int)$area['x_min_dm'],
                (int)$area['y_min_dm'],
                (int)$area['x_max_dm'],
                (int)$area['y_max_dm'],
            ];
        }

        $requests = [];
        $wanted = [];
        foreach ($items as $item) {
            $key = (int)$item['key'];
            $wanted[$key] = true;
            $box = [(int)$item['type'], (int)$item['xMinDm'], (int)$item['yMinDm'], (int)$item['xMaxDm'], (int)$item['yMaxDm']];
            if (($current[$key] ?? null) === $box) {
                continue;
            }
            [$type, $xMin, $yMin, $xMax, $yMax] = $box;
            $requests[] = ['path' => self::COORDS, 'body' => [
                'uid' => $uid,
                'coordKey' => (string)$key,
                'type' => $type,
                'lowLeft' => "{$xMin},{$yMin}",
                'lowRight' => "{$xMax},{$yMin}",
                'upLeft' => "{$xMin},{$yMax}",
                'upRight' => "{$xMax},{$yMax}",
            ]];
        }

        foreach (array_keys($current) as $key) {
            if (!isset($wanted[$key])) {
                $requests[] = ['path' => self::COORDS, 'body' => ['uid' => $uid, 'coordKey' => (string)$key, 'type' => 0]];
            }
        }

        return $requests;
    }

    /** @param array<string, mixed> $props */
    private static function scene(array $props): string
    {
        return self::SCENES[self::FUNCTIONS[(int)($props['radar_func_ctrl'] ?? -1)] ?? 'full_function'];
    }

    /** @param array<string, mixed> $props */
    private static function mounting(array $props): string
    {
        return (string)($props['radar_install_style'] ?? '0') === '1' ? 'wall' : 'ceiling';
    }

    private static function metres(int $decimetres): float
    {
        return round(max(0, $decimetres) / 10, 1);
    }

    private static function decimetres(mixed $metres): int
    {
        return (int)round((float)$metres * 10);
    }
}
