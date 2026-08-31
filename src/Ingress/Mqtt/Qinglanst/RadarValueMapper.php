<?php

namespace Hub\Ingress\Mqtt\Qinglanst;

final class RadarValueMapper
{
    public const UNKNOWN_CODE = 255;

    public const DETECTION_CATEGORY_ALARM = 1;
    public const DETECTION_CATEGORY_EVENT = 2;

    public const DETECTION_TYPE_FALL_CONFIRMED = 1;
    public const DETECTION_TYPE_HEART_RATE_HIGH_CRITICAL = 2;
    public const DETECTION_TYPE_HEART_RATE_HIGH = 3;
    public const DETECTION_TYPE_HEART_RATE_LOW_CRITICAL = 4;
    public const DETECTION_TYPE_HEART_RATE_LOW = 5;
    public const DETECTION_TYPE_APNEA = 6;
    public const DETECTION_TYPE_BREATHING_HIGH = 7;
    public const DETECTION_TYPE_BREATHING_LOW = 8;
    public const DETECTION_TYPE_VITALS_SIGNAL_LOST = 9;
    public const DETECTION_TYPE_ROOM_ENTRY = 10;
    public const DETECTION_TYPE_ROOM_EXIT = 11;
    public const DETECTION_TYPE_AREA_ENTRY = 12;
    public const DETECTION_TYPE_AREA_EXIT = 13;
    public const DETECTION_TYPE_SITTING_CONFIRMED = 14;
    public const DETECTION_TYPE_ON_FLOOR = 15;

    public const DETECTION_LEVEL_INFO = 1;
    public const DETECTION_LEVEL_WARNING = 2;
    public const DETECTION_LEVEL_DANGER = 3;

    public const DETECTION_SOURCE_POSITION = 1;
    public const DETECTION_SOURCE_HEARTBREATH = 2;

    private const POSTURE_CODE_TO_LABEL = [
        0 => 'Initialization',
        1 => 'Walking',
        2 => 'Suspected Fall',
        3 => 'Squatting',
        4 => 'Standing',
        5 => 'Fall Confirmation',
        6 => 'Lying Down',
        7 => 'Suspected Sitting on Ground',
        8 => 'Confirmed Sitting on Ground',
        9 => 'Sitting Up Bed',
        10 => 'Suspected Sitting Up Bed',
        11 => 'Confirmed Sitting Up Bed',
        self::UNKNOWN_CODE => 'Unknown',
    ];

    private const LAST_EVENT_CODE_TO_LABEL = [
        0 => 'No Event',
        1 => 'Enter Room',
        2 => 'Leave Room',
        3 => 'Enter Area',
        4 => 'Leave Area',
        self::UNKNOWN_CODE => 'Unknown',
    ];

    private const SLEEP_STATE_CODE_TO_LABEL = [
        0 => 'Undefined',
        1 => 'Light Sleep',
        2 => 'Deep Sleep',
        3 => 'Awake',
        self::UNKNOWN_CODE => 'Undefined',
    ];

    private const BREATHING_STATUS_CODE_TO_LABEL = [
        0 => 'Normal',
        1 => 'Hypopnea',
        2 => 'Hyperpnea',
        3 => 'Apnea',
        self::UNKNOWN_CODE => 'Normal',
    ];

    private const HEART_STATUS_CODE_TO_LABEL = [
        0 => 'Normal',
        1 => 'Low',
        2 => 'High',
        3 => 'Undefined',
        self::UNKNOWN_CODE => 'Normal',
    ];

    private const VITAL_STATUS_CODE_TO_LABEL = [
        0 => 'Normal',
        1 => 'Undefined',
        2 => 'Undefined',
        3 => 'Weak',
        self::UNKNOWN_CODE => 'Normal',
    ];

    private const DETECTION_CATEGORY_CODE_TO_LABEL = [
        self::DETECTION_CATEGORY_ALARM => 'alarm',
        self::DETECTION_CATEGORY_EVENT => 'event',
        self::UNKNOWN_CODE => 'unknown',
    ];

    private const DETECTION_LEVEL_CODE_TO_LABEL = [
        self::DETECTION_LEVEL_INFO => 'info',
        self::DETECTION_LEVEL_WARNING => 'aviso',
        self::DETECTION_LEVEL_DANGER => 'perigo',
        self::UNKNOWN_CODE => 'unknown',
    ];

    private const DETECTION_SOURCE_CODE_TO_LABEL = [
        self::DETECTION_SOURCE_POSITION => 'position',
        self::DETECTION_SOURCE_HEARTBREATH => 'heartbreath',
        self::UNKNOWN_CODE => 'unknown',
    ];

    /**
     * As etiquetas do documento do fabricante, em enumerações.
     *
     * O que sai dos mapas acima é o que o documento diz -- `Awake`, `Lying Down`,
     * `Hypopnea` -- e é assim que se verifica o codec contra a especificação. Mas era
     * também assim que chegava à interface: a etiqueta do campo estava traduzida
     * ("Estado do sono") e o valor ao lado dela vinha em inglês.
     *
     * O que viaja passa a ser a enumeração; traduzir é trabalho de quem desenha.
     */
    private const LABEL_TO_ENUM = [
        'Undefined' => 'undefined',
        'Unknown' => 'unknown',
        'Light Sleep' => 'light_sleep',
        'Deep Sleep' => 'deep_sleep',
        'Awake' => 'awake',
        'Initialization' => 'initialization',
        'Walking' => 'walking',
        'Suspected Fall' => 'suspected_fall',
        'Squatting' => 'squatting',
        'Standing' => 'standing',
        'Fall Confirmation' => 'fall_confirmation',
        'Lying Down' => 'lying_down',
        'Suspected Sitting on Ground' => 'suspected_sitting_on_ground',
        'Confirmed Sitting on Ground' => 'confirmed_sitting_on_ground',
        'Sitting Up Bed' => 'sitting_up_bed',
        'Suspected Sitting Up Bed' => 'suspected_sitting_up_bed',
        'Confirmed Sitting Up Bed' => 'confirmed_sitting_up_bed',
        'No Event' => 'no_event',
        'Enter Room' => 'enter_room',
        'Leave Room' => 'leave_room',
        'Enter Area' => 'enter_area',
        'Leave Area' => 'leave_area',
        'Normal' => 'normal',
        'Hypopnea' => 'hypopnea',
        'Hyperpnea' => 'hyperpnea',
        'Apnea' => 'apnea',
        'Low' => 'low',
        'High' => 'high',
        'Weak' => 'weak',
    ];

    private static ?array $detectionCategoryLabelToCode = null;
    private static ?array $detectionLevelLabelToCode = null;
    private static ?array $detectionSourceLabelToCode = null;

    private function __construct()
    {
    }

    public static function decodePostureState(int|string|null $value): string
    {
        return self::decodeWithCodeMap($value, self::POSTURE_CODE_TO_LABEL, 'Unknown');
    }

    public static function decodeLastEvent(int|string|null $value): string
    {
        return self::decodeWithCodeMap($value, self::LAST_EVENT_CODE_TO_LABEL, 'Unknown');
    }

    public static function decodeSleepState(int|string|null $value): string
    {
        return self::decodeWithCodeMap($value, self::SLEEP_STATE_CODE_TO_LABEL, 'Undefined');
    }

    public static function decodeBreathingStatus(int|string|null $value): string
    {
        return self::decodeWithCodeMap($value, self::BREATHING_STATUS_CODE_TO_LABEL, 'Normal');
    }

    public static function decodeHeartStatus(int|string|null $value): string
    {
        return self::decodeWithCodeMap($value, self::HEART_STATUS_CODE_TO_LABEL, 'Normal');
    }

    public static function decodeVitalStatus(int|string|null $value): string
    {
        return self::decodeWithCodeMap($value, self::VITAL_STATUS_CODE_TO_LABEL, 'Normal');
    }

    public static function decodeDetectionCategory(int|string|null $value): string
    {
        if (is_string($value) && !ctype_digit($value)) {
            return self::DETECTION_CATEGORY_CODE_TO_LABEL[self::encodeDetectionCategory($value)] ?? 'unknown';
        }
        return self::decodeWithCodeMap($value, self::DETECTION_CATEGORY_CODE_TO_LABEL, 'unknown');
    }

    public static function encodeDetectionCategory(int|string|null $value): int
    {
        return self::encodeWithLabelMap($value, self::detectionCategoryLabelToCode(), self::UNKNOWN_CODE);
    }

    public static function decodeDetectionLevel(int|string|null $value): string
    {
        if (is_string($value) && !ctype_digit($value)) {
            return self::DETECTION_LEVEL_CODE_TO_LABEL[self::encodeDetectionLevel($value)] ?? 'unknown';
        }
        return self::decodeWithCodeMap($value, self::DETECTION_LEVEL_CODE_TO_LABEL, 'unknown');
    }

    public static function encodeDetectionLevel(int|string|null $value): int
    {
        return self::encodeWithLabelMap($value, self::detectionLevelLabelToCode(), self::UNKNOWN_CODE);
    }

    public static function decodeDetectionSource(int|string|null $value): string
    {
        if (is_string($value) && !ctype_digit($value)) {
            return self::DETECTION_SOURCE_CODE_TO_LABEL[self::encodeDetectionSource($value)] ?? 'unknown';
        }
        return self::decodeWithCodeMap($value, self::DETECTION_SOURCE_CODE_TO_LABEL, 'unknown');
    }

    public static function encodeDetectionSource(int|string|null $value): int
    {
        return self::encodeWithLabelMap($value, self::detectionSourceLabelToCode(), self::UNKNOWN_CODE);
    }

    public static function detectionAlarmTypeCodes(): array
    {
        return [
            self::DETECTION_TYPE_FALL_CONFIRMED,
            self::DETECTION_TYPE_HEART_RATE_HIGH_CRITICAL,
            self::DETECTION_TYPE_HEART_RATE_HIGH,
            self::DETECTION_TYPE_HEART_RATE_LOW_CRITICAL,
            self::DETECTION_TYPE_HEART_RATE_LOW,
            self::DETECTION_TYPE_APNEA,
            self::DETECTION_TYPE_BREATHING_HIGH,
            self::DETECTION_TYPE_BREATHING_LOW,
            self::DETECTION_TYPE_VITALS_SIGNAL_LOST,
            self::DETECTION_TYPE_SITTING_CONFIRMED,
            self::DETECTION_TYPE_ON_FLOOR,
        ];
    }

    /**
     * A enumeração correspondente a uma etiqueta do fabricante.
     *
     * Uma etiqueta que o mapa não conheça devolve-se em minúsculas com underscores, o que
     * mantém a forma mesmo que o fabricante acrescente estados numa versão nova do
     * firmware -- é preferível a `unknown`, que deitava fora o que ele nos disse.
     */
    public static function toEnum(string $label): string
    {
        return self::LABEL_TO_ENUM[$label]
            ?? strtolower(str_replace(' ', '_', trim($label)));
    }

    private static function encodeWithLabelMap(int|string|null $value, array $map, int $fallback): int
    {
        if ($value === null) {
            return $fallback;
        }

        if (is_int($value)) {
            return $value >= 0 && $value <= self::UNKNOWN_CODE ? $value : $fallback;
        }

        if (is_string($value) && ctype_digit($value)) {
            $code = (int)$value;
            return $code >= 0 && $code <= self::UNKNOWN_CODE ? $code : $fallback;
        }

        $label = trim($value);
        if ($label === '') {
            return $fallback;
        }

        return $map[$label] ?? $fallback;
    }

    private static function decodeWithCodeMap(int|string|null $value, array $map, string $fallback): string
    {
        if ($value === null) {
            return $fallback;
        }

        if (is_string($value) && !ctype_digit($value)) {
            return $value;
        }

        $code = (int)$value;
        return $map[$code] ?? $fallback;
    }

    private static function detectionCategoryLabelToCode(): array
    {
        if (self::$detectionCategoryLabelToCode === null) {
            self::$detectionCategoryLabelToCode = array_flip(self::DETECTION_CATEGORY_CODE_TO_LABEL);
            self::$detectionCategoryLabelToCode['alarme'] = self::DETECTION_CATEGORY_ALARM;
            self::$detectionCategoryLabelToCode['evento'] = self::DETECTION_CATEGORY_EVENT;
        }
        return self::$detectionCategoryLabelToCode;
    }

    private static function detectionLevelLabelToCode(): array
    {
        if (self::$detectionLevelLabelToCode === null) {
            self::$detectionLevelLabelToCode = array_flip(self::DETECTION_LEVEL_CODE_TO_LABEL);
            self::$detectionLevelLabelToCode['warning'] = self::DETECTION_LEVEL_WARNING;
            self::$detectionLevelLabelToCode['danger'] = self::DETECTION_LEVEL_DANGER;
        }
        return self::$detectionLevelLabelToCode;
    }

    private static function detectionSourceLabelToCode(): array
    {
        if (self::$detectionSourceLabelToCode === null) {
            self::$detectionSourceLabelToCode = array_flip(self::DETECTION_SOURCE_CODE_TO_LABEL);
        }
        return self::$detectionSourceLabelToCode;
    }
}
