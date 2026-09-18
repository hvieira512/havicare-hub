<?php

namespace Hub\Command\Configuration\Definition;

/**
 * O que se configura num dispensador de comprimidos Zayata M228.
 *
 * O aparelho tem nove alarmes **fixos**: não se criam nem se apagam, ligam-se e desligam-se.
 * É por isso que o plano viaja inteiro de cada vez — escrever só um slot deixava os outros
 * com o que lá estivesse antes, e um alarme esquecido continua a dispensar comprimidos.
 *
 * As acções entram aqui como entradas transientes em vez de um catálogo à parte: o que a
 * `PATCH` recusa é exactamente o que a `/requests` aceita.
 */
final class ZayataConfigurationDefinitions
{
    public static function all(): array
    {
        return [
            ConfigurationDefinition::make(
                'medication_reminders',
                'medicationPlan',
                'Plano de medicação',
                'pillDispenserAlarms',
                ['plans'],
                ['medicationPlan'],
                'health',
                10,
                9,
                null,
                false,
                'Os nove alarmes do aparelho. O plano é enviado inteiro: um slot que fique de'
                . ' fora é desligado, para não sobrar nada de um plano anterior.',
            ),
            ConfigurationDefinition::make(
                'medication_period',
                'medicationPeriod',
                'Período do plano',
                'pillDispenserPeriod',
                ['enabled', 'startDate', 'endDate'],
                ['medicationPeriod'],
                'health',
                15,
                null,
                null,
                false,
                'Entre que datas o plano vale. Desligado, os alarmes tocam sempre. O aparelho'
                . ' não sabe dias da semana — só "todos os dias, neste intervalo".',
            ),
            // Dois interruptores independentes, e por isso duas definições: a dashboard
            // agrupa interruptores seguidos em linhas compactas, com a pastilha e o switch
            // à direita. Um bloco só com os dois lá dentro fugia a esse padrão.
            self::toggle('early_dispense', 'earlyRetrieval', 'Toma antecipada', 20, 'Deixa o utente levantar a medicação antes da hora marcada.'),
            self::toggle('child_lock', 'childLock', 'Bloqueio de criança', 21, 'Tranca o prato para não ser aberto por quem não deve.'),
            ConfigurationDefinition::make(
                'sound_profile',
                'soundProfile',
                'Som',
                'pillDispenserSound',
                ['volume', 'ringtone'],
                ['soundProfile'],
                'alerts',
                10,
            ),
            ConfigurationDefinition::make(
                'do_not_disturb',
                'doNotDisturb',
                'Não incomodar',
                'pillDispenserQuietHours',
                ['enabled', 'startHour', 'startMinute', 'endHour', 'endMinute'],
                ['doNotDisturb'],
                'alerts',
                20,
            ),
            ConfigurationDefinition::make(
                'language_timezone',
                'languageTimezone',
                'Idioma e fuso horário',
                'pillDispenserRegion',
                ['language', 'timezoneMinutes'],
                ['languageTimezone'],
                'system',
                10,
                null,
                null,
                false,
                'O relógio do aparelho deriva, e o fuso é o que dá sentido às horas que ele'
                . ' reporta.',
            ),
            // As acções. O relógio calibra-se à mão porque num ensaio um alarme das 12:55
            // ficou registado às 11:45.
            self::action('dispense_now', 'dispenseNow', 'Dispensar agora', 'system', 20),
            self::action('calibrate_clock', 'calibrateClock', 'Calibrar relógio', 'system', 30),
            self::action('mute_alarm', 'muteAlarm', 'Silenciar', 'system', 40),
            self::action('reset_tray', 'resetTray', 'Repor o prato', 'system', 50),
            self::action('restart_device', 'restartDevice', 'Reiniciar', 'system', 60),
            self::action('reset_device', 'factoryReset', 'Reposição de fábrica', 'system', 70),
        ];
    }

    private static function toggle(string $key, string $command, string $label, int $order, string $help): array
    {
        return ConfigurationDefinition::make(
            $key,
            $command,
            $label,
            'toggle',
            ['enabled'],
            [$command],
            'health',
            $order,
            null,
            null,
            false,
            $help,
        );
    }

    private static function action(string $key, string $command, string $label, string $category, int $order): array
    {
        return ConfigurationDefinition::make(
            $key,
            $command,
            $label,
            'requestAction',
            [],
            [$command],
            $category,
            $order,
            null,
            null,
            true,
        );
    }
}
