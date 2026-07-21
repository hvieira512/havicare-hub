<?php

namespace Hub\Command\Configuration\Payload;

final class FourPTouchPayloadBuilder extends ConfigurationPayloadBuilder
{
    public static function build(string $key, array $payload): array
    {
        if (isset($payload['fields']) && is_array($payload['fields'])) {
            return ['fields' => array_map(static fn(mixed $value): string => trim((string)$value), $payload['fields'])];
        }

        $fields = match ($key) {
            'uploadInterval' => [self::rangeInt($payload['intervalSeconds'] ?? null, 60, 65535, 'intervalSeconds')],
            'sosNumber1', 'sosNumber2', 'sosNumber3', 'monitorNumber' => [self::requiredString($payload['phone'] ?? null, 'phone')],
            'whitelistGroup1', 'whitelistGroup2' => self::stringList($payload['numbers'] ?? [], 5, 'numbers'),
            'devicePassword' => [self::requiredString($payload['password'] ?? null, 'password')],
            'languageTimezone' => [
                self::zeroBasedRangeInt($payload['language'] ?? null, 0, 36, 'language'),
                self::requiredString($payload['timeZone'] ?? null, 'timeZone'),
            ],
            'sosSmsAlerts', 'lowBatterySmsAlerts', 'removeWatchAlarm', 'removeWatchSmsAlerts' => [
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
            ],
            'takePills' => self::takePills($payload),
            'healthAutoMeasurement' => [
                1,
                self::boolInt($payload['enabled'] ?? null, 'enabled'),
                self::positiveInt($payload['intervalMinutes'] ?? null, 'intervalMinutes'),
            ],
            'walkTime' => self::timeRanges($payload['ranges'] ?? [], 3, 'ranges'),
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
            'resetCommand', 'powerOffCommand', 'findDeviceCommand', 'firmwareVersion', 'deviceStatus' => [],
            'doNotDisturb', 'callInRestriction' => [self::boolInt($payload['enabled'] ?? null, 'enabled')],
            'alarmClock' => self::alarmClock($payload),
            'phonebook' => self::phonebook($payload),
            'soundProfile' => [self::soundProfileMode($payload['mode'] ?? null)],
            default => throw new \InvalidArgumentException("Unsupported 4P Touch configuration {$key}"),
        };

        return ['fields' => array_map(static fn(mixed $value): string => (string)$value, $fields)];
    }

    private static function takePills(array $payload): array
    {
        $settings = $payload['reminderSettings'] ?? [];
        if (array_is_list($settings)) {
            $parts = [];
            foreach ($settings as $setting) {
                $parts[] = self::takePillsReminderSettings($setting);
            }
            $reminderSettings = implode('-', $parts);
        } else {
            $reminderSettings = self::takePillsReminderSettings($settings);
        }

        $fields = [
            $reminderSettings,
            self::rangeInt($payload['number'] ?? null, 1, 3, 'number'),
            self::utf16Hex(self::requiredString($payload['reminderText'] ?? null, 'reminderText')),
        ];

        if (array_key_exists('voiceData', $payload)) {
            $voiceData = self::takePillsVoiceData($payload['voiceData'], $payload['voiceMimeType'] ?? null);
            if ($voiceData !== '') {
                $fields[] = $voiceData;
            }
        }

        return $fields;
    }

    private static function takePillsReminderSettings(mixed $value): string
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
            throw new \InvalidArgumentException('reminderSettings is required');
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('reminderSettings must be a string or object');
        }

        $time = self::requiredString($value['time'] ?? $value['reminderTime'] ?? null, 'reminderSettings.time');
        $enabled = self::boolInt($value['enabled'] ?? $value['switchState'] ?? null, 'reminderSettings.enabled');
        $frequency = self::rangeInt($value['frequency'] ?? $value['reminderFrequency'] ?? null, 0, 999, 'reminderSettings.frequency');
        $custom = array_key_exists('custom', $value)
            ? trim((string)$value['custom'])
            : trim((string)($value['reminderCustom'] ?? ''));
        $parts = [$time, (string)$enabled, (string)$frequency, $custom];
        while ($parts !== [] && end($parts) === '') {
            array_pop($parts);
        }

        return implode('-', $parts);
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

        $binary = base64_decode($voiceData, true);
        if ($binary === false) {
            throw new \InvalidArgumentException('voiceData must be base64 audio');
        }

        return self::transcodeAudioToArmBase64($binary, $detectedMimeType !== '' ? $detectedMimeType : 'audio/webm');
    }

    private static function transcodeAudioToArmBase64(string $audioBytes, string $mimeType): string
    {
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
                '-acodec', 'libopencore_amrnb', '-ar', '8000', '-ac', '1', '-b:a', '12.2k',
                '-f', 'amr', $outputPath,
            ];
            [$exitCode, $stderr] = self::runProcess($command);
            if ($exitCode !== 0 || !is_file($outputPath)) {
                $message = trim($stderr);
                throw new \RuntimeException($message !== ''
                    ? "Failed to convert voice recording to ARM: {$message}"
                    : 'Failed to convert voice recording to ARM');
            }
            $armBytes = file_get_contents($outputPath);
            if ($armBytes === false || $armBytes === '') {
                throw new \RuntimeException('Converted ARM audio is empty');
            }

            return base64_encode($armBytes);
        } finally {
            if (is_file($inputPath)) {
                @unlink($inputPath);
            }
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    private static function runProcess(array $command): array
    {
        $quoted = array_map('escapeshellarg', $command);
        $output = [];
        $exitCode = 0;
        exec(implode(' ', $quoted) . ' 2>&1', $output, $exitCode);

        return [$exitCode, trim(implode("\n", $output))];
    }

    private static function soundProfileMode(mixed $value): int
    {
        if (!is_numeric((string)$value)) {
            throw new \InvalidArgumentException('mode must be an integer');
        }
        $mode = (int)$value;
        if ($mode < 1 || $mode > 4) {
            throw new \InvalidArgumentException('mode must be between 1 and 4');
        }

        return $mode;
    }

    private static function timeRanges(mixed $value, int $max, string $field): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }
        $ranges = [];
        foreach (array_slice($value, 0, $max) as $item) {
            if (trim((string)$item) !== '') {
                $ranges[] = self::timeRange($item, $field);
            }
        }
        if ($ranges === []) {
            throw new \InvalidArgumentException("{$field} must contain at least one time range");
        }

        return $ranges;
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

    private static function alarmClock(array $payload): array
    {
        $alarms = $payload['items'] ?? $payload['alarms'] ?? $payload['alarmClock'] ?? null;
        if (is_string($alarms) && trim($alarms) !== '') {
            $alarms = self::alarmClockListFromString($alarms);
        }
        if (!is_array($alarms)) {
            $alarms = [$payload];
        } elseif (!array_is_list($alarms)) {
            $alarms = [$alarms];
        }
        $alarms = array_values(array_filter($alarms, static fn(mixed $item): bool => $item !== null));
        if ($alarms === []) {
            throw new \InvalidArgumentException('alarms must contain at least one alarm');
        }
        if (count($alarms) > 3) {
            throw new \InvalidArgumentException('alarms must not contain more than 3 items');
        }

        return array_map([self::class, 'alarmClockEntry'], $alarms);
    }

    private static function alarmClockListFromString(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('alarms must contain at least one alarm');
        }
        $parts = explode(',', $value);

        return count($parts) > 1
            ? array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''))
            : [$value];
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
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches) === 1
            || preg_match('/^(\d{2})(\d{2})$/', $value, $matches) === 1) {
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

    private static function phonebook(array $payload): array
    {
        $contacts = $payload['contacts'] ?? [];
        if (!is_array($contacts) || $contacts === []) {
            throw new \InvalidArgumentException('contacts must be a non-empty array');
        }
        $fields = [];
        if (count($contacts) > 5) {
            throw new \InvalidArgumentException('contacts must not contain more than 5 items');
        }
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                throw new \InvalidArgumentException('each contact must be an object');
            }
            $phone = self::phonebookPhone($contact['phone'] ?? null);
            $name = self::phonebookName($contact['name'] ?? null);
            $fields[] = $phone;
            $fields[] = self::utf16Hex($name);
        }

        return $fields;
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

    private static function phonebookName(mixed $value): string
    {
        $name = self::requiredString($value, 'name');
        if (self::unicodeLength($name) > 10) {
            throw new \InvalidArgumentException('name must not exceed 10 Unicode characters');
        }

        return $name;
    }

    private static function unicodeLength(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        $matches = [];
        $count = preg_match_all('/./us', $value, $matches);
        if ($count === false) {
            throw new \InvalidArgumentException('value must be valid UTF-8');
        }

        return $count;
    }

    private static function fallDownSensitivity(array $payload): string
    {
        $level = self::positiveInt($payload['sensitivity'] ?? $payload['sensitivityLevel'] ?? null, 'sensitivity');
        $totalLevels = self::rangeInt($payload['levels'] ?? $payload['totalLevels'] ?? null, 6, 8, 'levels');
        if (!in_array($totalLevels, [6, 8], true)) {
            throw new \InvalidArgumentException('levels must be 6 or 8');
        }
        if ($level > $totalLevels) {
            throw new \InvalidArgumentException('sensitivity must not exceed levels');
        }

        return "{$level}+{$totalLevels}";
    }
}
