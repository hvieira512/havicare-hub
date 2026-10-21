<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Definition;

/**
 * O que se configura num dispensador Zayata M228. Os nove alarmes são fixos — preenchem-se e
 * esvaziam-se —, e por isso o plano viaja inteiro de cada vez.
 */
final class ZayataConfigurationDefinitions
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $entry = static fn(string $key, string $command, string $input, array $fields, string $category, int $order, mixed ...$named): array
            => ConfigurationDefinition::make($key, $command, $input, $fields, self::replyTo($command), $category, $order, ...$named);
        $toggle = static fn(string $key, string $command, int $order, string $category = 'health'): array
            => $entry($key, $command, 'toggle', ['enabled'], $category, $order);
        $number = static fn(string $key, string $command, string $field, int $max, int $order): array
            => $entry($key, $command, 'number', [$field], 'health', $order, options: ['min' => 0, 'max' => $max]);
        $choice = static fn(string $key, string $command, string $field, array $values, string $category, int $order, string $input = 'select', mixed $default = null): array
            => $entry($key, $command, $input, [$field], $category, $order, options: array_filter([
                $field => array_map(static fn(int|string $value): array => ['value' => $value], $values),
                'default' => $default,
            ], static fn(mixed $value): bool => $value !== null));
        $action = static fn(string $key, string $command, string $category, int $order, bool $destructive = false): array
            => $entry($key, $command, 'action', [], $category, $order, transient: true, destructive: $destructive);

        return [
            $entry('medication_reminders', 'medicationPlan', 'pillDispenserAlarms', ['plans'], 'reminders', 10, limit: 9),
            $entry('medication_period', 'medicationPeriod', 'pillDispenserPeriod', ['enabled', 'startDate', 'endDate'], 'reminders', 11),
            $toggle('early_dispense', 'earlyRetrieval', 20),
            $toggle('child_lock', 'childLock', 21),
            $toggle('missed_dispense', 'missedDispense', 22),
            $toggle('emergency_call', 'emergencyCall', 30, 'alerts'),
            $number('retrieval_warning', 'retrievalWarning', 'minutes', 1440, 30),
            $number('retrieval_timeout', 'retrievalTimeout', 'minutes', 1440, 31),
            $number('loaded_cells', 'loadedCells', 'cells', 28, 32),
            // Na especificação 0 é o mais alto e 3 é silêncio.
            $choice('alarm_volume', 'alarmVolume', 'volume', [0, 1, 2, 3], 'reminders', 20, 'volumeScale'),
            $choice('alarm_ringtone', 'alarmRingtone', 'ringtone', [0, 1, 2, 3], 'reminders', 21),
            $entry('do_not_disturb', 'doNotDisturb', 'pillDispenserQuietHours', ['enabled', 'startHour', 'startMinute', 'endHour', 'endMinute'], 'system', 20),
            $choice('device_language', 'deviceLanguage', 'language', [0, 1], 'system', 10),
            $choice('date_format', 'dateFormat', 'format', [0, 1, 2], 'system', 11),
            $choice('time_format', 'timeFormat', 'format', [0, 1], 'system', 12),
            $toggle('auto_clock', 'autoClock', 13, 'system'),
            $toggle('key_tone', 'keyTone', 14, 'system'),
            // As zonas da IANA, que o hub converte para o desvio do aparelho e reescreve quando a hora muda.
            $choice('time_zone', 'timeZone', 'timeZone', \DateTimeZone::listIdentifiers(), 'system', 11, default: 'Europe/Lisbon'),
            $action('sync_configuration', 'readConfiguration', 'system', 5),
            // Sem desligar a cifra: o aparelho recusa o `0x8005`.
            $action('dispense_now', 'dispenseNow', 'health', 40, true),
            // Sem `0xA124` nem `0xA125`: este firmware recusa-os com «TAG inválida».
            $action('calibrate_clock', 'calibrateClock', 'system', 30),
            $action('mute_alarm', 'muteAlarm', 'reminders', 30),
            $action('reset_tray', 'resetTray', 'system', 50),
            $action('restart_device', 'restartDevice', 'system', 60, true),
            // Sem reposição de fábrica: devolve o aparelho ao servidor do fornecedor.
        ];
    }

    /**
     * A resposta que um comando espera é a do seu tipo de pacote, e não o nome dele: o M228
     * responde a um `0x06` com `write_config_ack` seja qual for a TAG.
     *
     * @return list<string>
     */
    private static function replyTo(string $command): array
    {
        return [match ($command) {
            'readConfiguration' => 'read_config_ack',
            'dispenseNow', 'calibrateClock', 'muteAlarm',
            'resetTray', 'restartDevice' => 'control_ack',
            default => 'write_config_ack',
        }];
    }
}
