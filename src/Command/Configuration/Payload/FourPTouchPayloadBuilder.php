<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Payload;

final class FourPTouchPayloadBuilder extends ConfigurationPayloadBuilder
{
    /** O código do `profile` de cada perfil de som. */
    public const SOUND_PROFILES = ['ring_and_vibrate' => 1, 'ring' => 2, 'vibrate' => 3, 'silent' => 4];

    /**
     * O código do `LZ` de cada idioma, pela etiqueta BCP 47. A ficha dá o 29 como «Polski»,
     * repetido do 17, e fica de fora.
     */
    public const LANGUAGES = [
        'en' => 0, 'zh-Hans' => 1, 'pt' => 3, 'es' => 4, 'de' => 5, 'tr' => 7, 'vi' => 8, 'ru' => 9,
        'fr' => 10, 'el' => 11, 'it' => 12, 'sv' => 13, 'zh-Hant' => 14, 'bg' => 15, 'nl' => 16, 'pl' => 17,
        'fi' => 18, 'th' => 19, 'he' => 22, 'da' => 23, 'hi' => 25, 'ro' => 26, 'cs' => 27, 'ar' => 28,
        'hu' => 34, 'sk' => 36,
    ];

    /**
     * O tecto do áudio aceite, antes de descodificar. É generoso porque a dashboard não limita a
     * gravação, e o `ffmpeg`, que corre síncrono no event loop, já a corta aos 15 segundos.
     */
    private const MAX_VOICE_AUDIO_BYTES = 2 * 1024 * 1024;

    /**
     * O resultado da sonda ao `ffmpeg`, por `PATH`: é o `PATH` que decide onde o binário se
     * procura, e a resposta só vale para aquele.
     *
     * @var array<string, bool>
     */
    private static array $voiceTranscodingSupport = [];

    public static function supportsVoiceTranscoding(): bool
    {
        // Sem memória, cada lembrete com voz lança três subprocessos em vez de um: a procura
        // do binário, a lista de codificadores, e só depois a conversão.
        $path = (string)getenv('PATH');
        if (isset(self::$voiceTranscodingSupport[$path])) {
            return self::$voiceTranscodingSupport[$path];
        }

        if (!self::commandExists('ffmpeg')) {
            return self::$voiceTranscodingSupport[$path] = false;
        }

        [$exitCode, $output] = self::runProcess(['ffmpeg', '-hide_banner', '-encoders']);

        return self::$voiceTranscodingSupport[$path]
            = ($exitCode === 0 && str_contains($output, 'libopencore_amrnb'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{fields: list<string>}
     */
    public static function build(string $key, array $payload): array
    {
        if (isset($payload['fields']) && is_array($payload['fields'])) {
            return ['fields' => array_map(static fn(mixed $value): string => trim((string)$value), $payload['fields'])];
        }

        $fields = match ($key) {
            'uploadInterval' => [self::rangeInt($payload['intervalSeconds'] ?? null, 60, 65535, 'intervalSeconds')],
            'sosContacts' => self::sosNumbers($payload['numbers'] ?? []),
            'monitorNumber' => [self::requiredString($payload['phone'] ?? null, 'phone')],
            'whitelistGroup1', 'whitelistGroup2' => self::stringList($payload['numbers'] ?? [], 5, 'numbers'),
            'languageTimezone' => [
                self::LANGUAGES[(string)($payload['language'] ?? '')]
                    ?? throw new \InvalidArgumentException('language must be one of ' . implode(', ', array_keys(self::LANGUAGES))),
                self::utcOffsetHours($payload['utcOffsetHours'] ?? null),
            ],
            'sosSmsAlerts', 'lowBatterySmsAlerts', 'removeWatchAlarm', 'removeWatchSmsAlerts' => [
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
            ],
            'takePills' => self::takePills($payload),
            'healthAutoMeasurement' => self::healthAutoMeasurement($payload),
            'walkTime' => self::timeRanges($payload, 3, 'ranges'),
            'doNotDisturb' => self::timeRanges($payload, 4, 'ranges'),
            'sleepTime' => [self::timeRange($payload['range'] ?? null, 'range')],
            'fallDownAlert' => [
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
                self::boolInt($payload['callCenterOnFall'] ?? false, 'callCenterOnFall'),
            ],
            'fallDownSensitivity' => [self::fallDownSensitivity($payload)],
            'bodyTemperatureInterval' => [
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
                self::rangeInt($payload['intervalHours'] ?? null, 1, 12, 'intervalHours'),
            ],
            'makeCall', 'centerNumber' => [self::requiredString($payload['phone'] ?? null, 'phone')],
            'pushMessage' => [self::utf16Hex(self::requiredString($payload['message'] ?? null, 'message'))],
            'restartCommand', 'powerOffCommand', 'findDeviceCommand' => [],
            'rejectUnknownCalls' => [self::boolInt($payload['enabled'] ?? null, 'enabled')],
            'alarmClock' => self::alarmClock($payload),
            'profile' => [
                self::SOUND_PROFILES[(string)($payload['mode'] ?? '')]
                    ?? throw new \InvalidArgumentException('mode must be one of ' . implode(', ', array_keys(self::SOUND_PROFILES))),
            ],
            default => throw new \InvalidArgumentException("Unsupported 4P Touch configuration {$key}"),
        };

        return ['fields' => array_map(static fn(mixed $value): string => (string)$value, $fields)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function takePills(array $payload): array
    {
        $settings = $payload['reminderSettings'] ?? [];
        $number = 0;
        if (is_array($settings) && array_is_list($settings)) {
            $number = count($settings);
            if ($number > 3) {
                throw new \InvalidArgumentException('reminderSettings accepts at most 3 reminders');
            }
            $parts = [];
            foreach ($settings as $setting) {
                $parts[] = self::takePillsReminderSettings($setting);
            }
            $reminderSettings = implode('-', $parts);
        } elseif (is_array($settings)) {
            $reminderSettings = self::takePillsReminderSettings($settings);
            $number = 1;
        } else {
            throw new \InvalidArgumentException('reminderSettings must be a list or object');
        }

        if ($number === 0) {
            // O TAKEPILLS só aceita 1 a 3 slots. Um plano genérico vazio representa-se com um
            // slot nativo desligado, para os lembretes existentes serem substituídos.
            return ['00:00-0-1', '1', self::utf16Hex('M'), ''];
        }

        $fields = [
            $reminderSettings,
            (string)$number,
            self::utf16Hex(self::requiredString($payload['reminderText'] ?? null, 'reminderText')),
            self::takePillsVoiceData($payload['voiceData'] ?? '', $payload['voiceMimeType'] ?? null),
        ];

        return $fields;
    }

    private static function takePillsReminderSettings(mixed $value): string
    {
        try {
            return self::alarmClockEntry($value);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(
                str_replace('alarm ', 'reminderSettings.', $e->getMessage()),
                previous: $e,
            );
        }
    }

    private static function takePillsVoiceData(mixed $value, mixed $mimeType = null): string
    {
        $voiceData = trim((string)$value);
        if ($voiceData === '') {
            return '';
        }

        $detectedMimeType = trim((string)$mimeType);
        if (str_starts_with($voiceData, 'data:')) {
            $commaPos = strpos($voiceData, ',');
            if ($commaPos !== false) {
                $meta = substr($voiceData, 5, $commaPos - 5);
                $voiceData = substr($voiceData, $commaPos + 1) ?: '';
                $semicolonPos = strpos($meta, ';');
                if ($semicolonPos !== false) {
                    $meta = substr($meta, 0, $semicolonPos);
                }
                if ($meta !== '') {
                    $detectedMimeType = $meta;
                }
            }
        }

        // O tecto testa-se **antes** de descodificar: o objectivo é não materializar em memória,
        // nem escrever em disco, nem entregar ao `ffmpeg`, um corpo que já se sabe grande de mais.
        if ((int)(strlen($voiceData) * 3 / 4) > self::MAX_VOICE_AUDIO_BYTES) {
            $limit = (int)(self::MAX_VOICE_AUDIO_BYTES / 1024);
            throw new \InvalidArgumentException("voiceData must not exceed {$limit} KiB of audio");
        }

        $binary = base64_decode($voiceData, true);
        if ($binary === false) {
            throw new \InvalidArgumentException('voiceData must be base64 audio');
        }

        return self::escapeVoiceBinary(self::transcodeAudioToAmr(
            $binary,
            $detectedMimeType !== '' ? $detectedMimeType : 'audio/webm'
        ));
    }

    /**
     * Os bytes de voz partilham o transporte enquadrado com os delimitadores de comando, e o
     * protocolo do dispositivo escapa-os antes de os pôr num frame.
     */
    private static function escapeVoiceBinary(string $bytes): string
    {
        return strtr($bytes, [
            "\x7D" => "\x7D\x01",
            "\x5B" => "\x7D\x02",
            "\x5D" => "\x7D\x03",
            "\x2C" => "\x7D\x04",
            "\x2A" => "\x7D\x05",
        ]);
    }

    private static function transcodeAudioToAmr(string $audioBytes, string $mimeType): string
    {
        if (!self::supportsVoiceTranscoding()) {
            throw new \RuntimeException('ffmpeg with AMR-NB support is required for TAKEPILLS voice audio');
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'takepills-audio-in-');
        $outputPath = tempnam(sys_get_temp_dir(), 'takepills-audio-out-');
        if ($inputPath === false || $outputPath === false) {
            throw new \RuntimeException('Failed to allocate temporary files for voice conversion');
        }
        @unlink($outputPath);

        try {
            if (file_put_contents($inputPath, $audioBytes) === false) {
                throw new \RuntimeException('Failed to persist voice recording for conversion');
            }
            $command = [
                'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $inputPath, '-vn',
                '-t', '15',
                '-acodec', 'libopencore_amrnb', '-ar', '8000', '-ac', '1', '-b:a', '12.2k',
                '-f', 'amr', $outputPath,
            ];
            [$exitCode, $stderr] = self::runProcess($command);
            if ($exitCode !== 0 || !is_file($outputPath)) {
                $message = trim($stderr);
                throw new \RuntimeException($message !== ''
                    ? "Failed to convert voice recording to AMR: {$message}"
                    : 'Failed to convert voice recording to AMR');
            }
            $amrBytes = file_get_contents($outputPath);
            if ($amrBytes === false || $amrBytes === '') {
                throw new \RuntimeException('Converted AMR audio is empty');
            }

            return $amrBytes;
        } finally {
            if (is_file($inputPath)) {
                @unlink($inputPath);
            }
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    private static function commandExists(string $command): bool
    {
        $output = [];
        $exitCode = 0;
        exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 && trim(implode("\n", $output)) !== '';
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string}
     */
    private static function runProcess(array $command): array
    {
        $quoted = array_map('escapeshellarg', $command);
        $output = [];
        $exitCode = 0;
        exec(implode(' ', $quoted) . ' 2>&1', $output, $exitCode);

        return [$exitCode, trim(implode("\n", $output))];
    }

    /** O desvio do fuso em horas, que o `LZ` aceita com meia hora. */
    private static function utcOffsetHours(mixed $value): string
    {
        if (!is_numeric($value) || (float)$value < -12 || (float)$value > 14 || fmod((float)$value * 2, 1.0) !== 0.0) {
            throw new \InvalidArgumentException('utcOffsetHours must be a half-hour step between -12 and 14');
        }

        return (string)(0 + $value);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function timeRanges(array $payload, int $max, string $field): array
    {
        if (!array_key_exists('ranges', $payload)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }
        $value = $payload['ranges'];
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }
        $ranges = [];
        foreach ($value as $item) {
            if (trim((string)$item) !== '') {
                $ranges[] = self::timeRange($item, $field);
            }
        }
        if (count($ranges) > $max) {
            throw new \InvalidArgumentException("{$field} accepts at most {$max} ranges");
        }

        return $ranges;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<int>
     */
    private static function healthAutoMeasurement(array $payload): array
    {
        $enabled = self::boolInt($payload['enabled'] ?? null, 'enabled');
        $interval = self::nonNegativeInt($payload['intervalMinutes'] ?? null, 'intervalMinutes');
        // Spec HEALTHAUTOSET: mínimo de 5 minutos; desligado, o intervalo não conta.
        if ($enabled === 1 && $interval < 5) {
            throw new \InvalidArgumentException('intervalMinutes must be at least 5');
        }

        return [1, $enabled, $interval];
    }

    private static function timeRange(mixed $value, string $field): string
    {
        $value = trim((string)$value);
        if (!preg_match('/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/', $value)) {
            throw new \InvalidArgumentException("{$field} must use HH:MM-HH:MM");
        }
        [$start, $end] = explode('-', $value, 2);
        self::validateClockTime($start, $field);
        self::validateClockTime($end, $field);

        return $value;
    }

    private static function validateClockTime(string $value, string $field): void
    {
        [$hour, $minute] = array_map('intval', explode(':', $value, 2));
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new \InvalidArgumentException("{$field} must use valid 24h times");
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function alarmClock(array $payload): array
    {
        $alarms = $payload['alarms'] ?? [];
        if (!is_array($alarms) || !array_is_list($alarms)) {
            throw new \InvalidArgumentException('alarms must be a list');
        }
        if (count($alarms) > 3) {
            throw new \InvalidArgumentException('alarms must not contain more than 3 items');
        }

        return array_map([self::class, 'alarmClockEntry'], $alarms);
    }

    private static function alarmClockEntry(mixed $value): string
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                throw new \InvalidArgumentException('alarm entry must not be empty');
            }
            if (preg_match('/^(\d{1,2}:\d{2}|\d{4})-(0|1)-(1|2|3)(?:-([01]{7}))?$/', $value, $matches) === 1) {
                $time = self::alarmClockTime($matches[1]);
                $enabled = $matches[2];
                $frequency = $matches[3];
                $custom = $matches[4] ?? '';

                return $frequency === '3'
                    ? "{$time}-{$enabled}-{$frequency}-" . self::alarmClockDays($custom, 'custom')
                    : "{$time}-{$enabled}-{$frequency}";
            }
            throw new \InvalidArgumentException('alarm entry must use HH:MM-switch-frequency[-days]');
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('each alarm item must be an object or string');
        }
        if (array_key_exists('type', $value) && trim((string)($value['type'] ?? '')) !== '') {
            throw new \InvalidArgumentException('type is not supported for four-p-touch alarm_clock');
        }

        $time = self::alarmClockTime(self::requiredString($value['time'] ?? $value['alarmTime'] ?? null, 'alarm time'));
        $enabled = self::boolInt($value['enabled'] ?? $value['switchState'] ?? null, 'alarm enabled');
        $recurrence = is_array($value['recurrence'] ?? null) ? $value['recurrence'] : [];
        $kind = strtolower(trim((string)($recurrence['kind'] ?? '')));
        $frequency = match ($kind) {
            'daily' => 2,
            'custom' => 3,
            'once' => 1,
            default => self::rangeInt($value['frequency'] ?? $value['mode'] ?? $value['reminderFrequency'] ?? null, 1, 3, 'alarm frequency'),
        };
        if ($frequency === 3) {
            $custom = self::alarmClockDays(
                $recurrence['days'] ?? $value['custom'] ?? $value['days'] ?? $value['reminderCustom'] ?? null,
                'alarm custom days',
            );

            return "{$time}-{$enabled}-{$frequency}-{$custom}";
        }

        return "{$time}-{$enabled}-{$frequency}";
    }

    private static function alarmClockTime(string $value): string
    {
        $value = trim($value);
        if (
            preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches) === 1
            || preg_match('/^(\d{2})(\d{2})$/', $value, $matches) === 1
        ) {
            $hour = (int)$matches[1];
            $minute = (int)$matches[2];
            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                throw new \InvalidArgumentException('alarm time must use valid 24h times');
            }

            return sprintf('%02d:%02d', $hour, $minute);
        }

        throw new \InvalidArgumentException('alarm time must use HH:MM or HHmm');
    }

    private static function alarmClockDays(mixed $value, string $field): string
    {
        if (is_array($value)) {
            $days = self::alarmClockDayMaskFromList($value);
            if ($days !== '') {
                return $days;
            }
        }
        $days = trim((string)$value);
        if (preg_match('/^[01]{7}$/', $days) !== 1) {
            if (preg_match('/^[1-7]+$/', $days) === 1) {
                return self::alarmClockDayMaskFromList(str_split($days));
            }
            throw new \InvalidArgumentException("{$field} must be a 7-digit 0/1 mask");
        }

        return $days;
    }

    /**
     * @param array<array-key, mixed> $days
     */
    private static function alarmClockDayMaskFromList(array $days): string
    {
        $mask = array_fill(0, 7, '0');
        foreach ($days as $day) {
            $value = (int)$day;
            if ($value === 7) {
                $mask[0] = '1';
            } elseif ($value >= 1 && $value <= 6) {
                $mask[$value] = '1';
            }
        }

        return implode('', $mask);
    }

    /**
     * Os contactos desejados, validados e sem o limite de nome do `PHB`.
     *
     * @param array<string, mixed> $payload
     * @return list<array{name: string, phone: string}>
     */
    public static function phonebookContacts(array $payload): array
    {
        if (!array_key_exists('contacts', $payload) || !is_array($payload['contacts'])) {
            throw new \InvalidArgumentException('contacts must be an array');
        }

        $contacts = [];
        foreach ($payload['contacts'] as $contact) {
            if (!is_array($contact)) {
                throw new \InvalidArgumentException('each contact must be an object');
            }
            $contacts[] = [
                'name' => self::requiredString($contact['name'] ?? null, 'name'),
                'phone' => self::phonebookPhone($contact['phone'] ?? null),
            ];
        }

        return $contacts;
    }

    private static function phonebookPhone(mixed $value): string
    {
        $phone = self::requiredString($value, 'phone');
        if (strlen($phone) > 20) {
            throw new \InvalidArgumentException('phone must not exceed 20 ASCII characters');
        }
        if (!preg_match('/^[\x00-\x7F]+$/', $phone)) {
            throw new \InvalidArgumentException('phone must contain ASCII characters only');
        }

        return $phone;
    }

    /**
     * Os três campos vão sempre, e um slot sem número vai vazio: o comando substitui a lista
     * inteira, e o campo que ficasse de fora guardava o número anterior.
     *
     * @return list<string>
     */
    private static function sosNumbers(mixed $value): array
    {
        return array_map(
            static function (string $phone): string {
                if ($phone === '') {
                    return '';
                }
                if (strlen($phone) > 20) {
                    throw new \InvalidArgumentException('numbers must not exceed 20 ASCII characters');
                }
                if (!preg_match('/^[\x00-\x7F]+$/', $phone)) {
                    throw new \InvalidArgumentException('numbers must contain ASCII characters only');
                }

                return $phone;
            },
            self::stringList($value, 3, 'numbers')
        );
    }

    /** @param array<string, mixed> $payload */
    private static function fallDownSensitivity(array $payload): string
    {
        $level = self::positiveInt($payload['sensitivity'] ?? $payload['sensitivityLevel'] ?? null, 'sensitivity');
        $totalLevelsValue = $payload['levels'] ?? $payload['totalLevels'] ?? 8;
        $totalLevelsValue = $totalLevelsValue === '' ? 8 : $totalLevelsValue;
        $totalLevels = self::rangeInt($totalLevelsValue, 6, 8, 'levels');
        if (!in_array($totalLevels, [6, 8], true)) {
            throw new \InvalidArgumentException('levels must be 6 or 8');
        }
        if ($level > $totalLevels) {
            throw new \InvalidArgumentException('sensitivity must not exceed levels');
        }

        return "{$level}+{$totalLevels}";
    }
}
