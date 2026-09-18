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
            // O volume é uma enumeração e não uma escala: na especificação, 0 é o mais alto
            // e 3 é silêncio. Um número solto no ecrã dizia exactamente o contrário a quem o
            // lesse.
            self::choice('alarm_volume', 'alarmVolume', 'Volume', 'alerts', 10, 'volume', [
                [0, 'Alto'],
                [1, 'Médio'],
                [2, 'Baixo'],
                [3, 'Silêncio'],
            ]),
            self::choice('alarm_ringtone', 'alarmRingtone', 'Tipo de toque', 'alerts', 11, 'ringtone', [
                [0, 'Nenhum'],
                [1, 'Toque 1'],
                [2, 'Toque 2'],
                [3, 'Toque 3'],
                [4, 'Toque 4'],
            ]),
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
            // Duas opções e mais nada: o aparelho só fala a língua de fábrica ou inglês. O
            // português existe, mas só instalado de origem — não é configurável.
            self::choice('device_language', 'deviceLanguage', 'Idioma', 'system', 10, 'language', [
                [0, 'Do aparelho'],
                [1, 'Inglês'],
            ]),
            self::choice(
                'time_zone',
                'timeZone',
                'Fuso horário',
                'system',
                11,
                'timeZone',
                self::timeZones(),
                'O relógio do aparelho deriva, e o fuso é o que dá sentido às horas que ele reporta.',
                // Parte de Lisboa no inverno, e não da ponta da lista.
                0,
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

    /**
     * Uma escolha de uma lista, com o significado à vista. O aparelho recebe o número; quem
     * configura vê o que ele quer dizer.
     *
     * @param list<array{0: int, 1: string}> $choices
     */
    private static function choice(
        string $key,
        string $command,
        string $label,
        string $category,
        int $order,
        string $field,
        array $choices,
        string $help = '',
        ?int $default = null,
    ): array {
        $options = [$field => array_map(
            static fn(array $choice): array => ['value' => $choice[0], 'label' => $choice[1]],
            $choices,
        )];
        if ($default !== null) {
            $options['default'] = $default;
        }

        return ConfigurationDefinition::make(
            $key,
            $command,
            $label,
            'select',
            [$field],
            [$command],
            $category,
            $order,
            null,
            $options,
            false,
            $help,
        );
    }

    /**
     * Os fusos que existem, no formato do aparelho: HHMM com sinal, de −1200 a +1400. Não são
     * minutos — `+100` é uma hora à frente, e não cem minutos.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function timeZones(): array
    {
        $offsets = [
            -1200, -1100, -1000, -930, -900, -800, -700, -600, -500, -400, -330, -300, -200, -100,
            0,
            100, 200, 300, 330, 400, 430, 500, 530, 545, 600, 630, 700, 800, 845, 900, 930,
            1000, 1030, 1100, 1200, 1245, 1300, 1400,
        ];

        return array_map(static function (int $offset): array {
            $sign = $offset < 0 ? '−' : '+';
            $hours = intdiv(abs($offset), 100);
            $minutes = abs($offset) % 100;
            $label = sprintf('UTC%s%02d:%02d', $sign, $hours, $minutes);

            // Portugal continental tem os dois, e é onde os aparelhos estão.
            return [$offset, match ($offset) {
                0 => $label . ' — Lisboa (inverno)',
                100 => $label . ' — Lisboa (verão)',
                default => $label,
            }];
        }, $offsets);
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
