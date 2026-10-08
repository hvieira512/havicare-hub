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
        return [
            ConfigurationDefinition::make(
                'medication_reminders',
                'medicationPlan',
                'Plano de medicação',
                'pillDispenserAlarms',
                ['plans'],
                self::replyTo('medicationPlan'),
                'reminders',
                10,
                9,
                null,
                false,
                'Cada hora preenchida gasta um compartimento; saem pela ordem da hora, não do número.',
            ),
            ConfigurationDefinition::make(
                'medication_period',
                'medicationPeriod',
                'Período do plano',
                'pillDispenserPeriod',
                ['enabled', 'startDate', 'endDate'],
                self::replyTo('medicationPeriod'),
                'reminders',
                11,
                null,
                null,
                false,
                'Desligado, vale sempre. Não há escolha de dias da semana.',
            ),
            self::toggle('early_dispense', 'earlyRetrieval', 'Toma antecipada', 20, 'Com pressão longa no botão sai já a dose do próximo alarme, dada como tomada.'),
            self::toggle('child_lock', 'childLock', 'Bloqueio de criança', 21, 'Tranca as teclas do aparelho.'),
            self::toggle('missed_dispense', 'missedDispense', 'Dispensar depois de falhar', 22, 'Levantada depois de falhada, a toma fica registada como anormal.'),
            self::toggle(
                'emergency_call',
                'emergencyCall',
                'Chamada de emergência',
                30,
                'O aparelho não telefona: o pedido chega ao hub, que o encaminha.',
                'alerts',
            ),
            self::number(
                'retrieval_warning',
                'retrievalWarning',
                'Avisar de atraso ao fim de',
                'health',
                30,
                'minutes',
                0,
                1440,
                'Minutos',
                '0 usa o valor de fábrica. Levantada depois disto, a toma fica como tardia.',
            ),
            self::number(
                'retrieval_timeout',
                'retrievalTimeout',
                'Dar como falhada ao fim de',
                'health',
                31,
                'minutes',
                0,
                1440,
                'Minutos',
                '0 usa o valor de fábrica.',
            ),
            self::number(
                'loaded_cells',
                'loadedCells',
                'Carregado até ao compartimento',
                'health',
                32,
                'cells',
                0,
                28,
                'de 28',
                'O último compartimento cheio, e não quantos. Levantada a dose desse, o prato volta ao início;'
                . ' se falhar, segue para os vazios.',
            ),
            // Na especificação 0 é o mais alto e 3 é silêncio.
            self::choice('alarm_volume', 'alarmVolume', 'Volume', 'reminders', 20, 'volume', [
                [0, 'Alto'],
                [1, 'Médio'],
                [2, 'Baixo'],
                [3, 'Silêncio'],
            ], input: 'volumeScale'),
            self::choice('alarm_ringtone', 'alarmRingtone', 'Tipo de toque', 'reminders', 21, 'ringtone', [
                [0, 'Nenhum'],
                [1, 'Toque 1'],
                [2, 'Toque 2'],
                [3, 'Toque 3'],
            ]),
            ConfigurationDefinition::make(
                'do_not_disturb',
                'doNotDisturb',
                'Não incomodar',
                'pillDispenserQuietHours',
                ['enabled', 'startHour', 'startMinute', 'endHour', 'endMinute'],
                self::replyTo('doNotDisturb'),
                'system',
                20,
                null,
                null,
                false,
                'Os alarmes dentro da janela dispensam na mesma, sem som.',
            ),
            self::choice('device_language', 'deviceLanguage', 'Idioma do ecrã', 'system', 10, 'language', [
                [0, 'Do aparelho'],
                [1, 'Inglês'],
            ], '«Do aparelho» é a língua instalada de fábrica.'),
            self::choice('date_format', 'dateFormat', 'Formato da data', 'system', 11, 'format', [
                [0, 'AAAA-MM-DD'],
                [1, 'DD-MM-AAAA'],
                [2, 'MM-DD-AAAA'],
            ]),
            self::choice('time_format', 'timeFormat', 'Formato da hora', 'system', 12, 'format', [
                [0, '24 horas'],
                [1, '12 horas'],
            ]),
            self::toggle(
                'auto_clock',
                'autoClock',
                'Acertar-se sozinho',
                13,
                '',
                'system',
            ),
            self::toggle(
                'key_tone',
                'keyTone',
                'Som das teclas',
                14,
                '',
                'system',
            ),
            self::choice(
                'time_zone',
                'timeZone',
                'Fuso horário',
                'system',
                11,
                'timeZone',
                self::timeZones(),
                default: 0,
            ),
            self::action(
                'sync_configuration',
                'readConfiguration',
                'Sincronizar configuração',
                'system',
                5,
                'Relê o que o aparelho tem guardado; não escreve nada.',
            ),
            // Sem desligar a cifra: o aparelho recusa o `0x8005`.
            self::action(
                'dispense_now',
                'dispenseNow',
                'Dispensar agora',
                'health',
                40,
                'Sem alarme por vir hoje, não faz nada.',
                'Isto gasta a dose do próximo alarme e dá-a como tomada. Confirma?',
            ),
            // Sem `0xA124` nem `0xA125`: este firmware recusa-os com «TAG inválida».
            self::action(
                'calibrate_clock',
                'calibrateClock',
                'Acertar o relógio do aparelho',
                'system',
                30,
                'Os alarmes tocam pelo relógio do aparelho, que deriva.',
            ),
            self::action(
                'mute_alarm',
                'muteAlarm',
                'Silenciar o alarme a tocar',
                'reminders',
                30,
                '',
            ),
            self::action(
                'reset_tray',
                'resetTray',
                'Repor o prato',
                'system',
                50,
                'Assenta o prato sem mexer na contagem; não limpa a avaria do prato.',
            ),
            self::action(
                'restart_device',
                'restartDevice',
                'Reiniciar',
                'system',
                60,
                'Sem nada preso no prato, limpa a avaria do prato à distância.',
                'Reiniciar o dispensador?',
            ),
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
            'readConfiguration', 'readConfiguration2' => 'read_config_ack',
            'readStatus' => 'read_status_ack',
            'discoverParametersConfiguration' => 'discover_config_ack',
            'discoverParametersStatus' => 'discover_status_ack',
            'discoverParametersControl' => 'discover_control_ack',
            'dispenseNow', 'calibrateClock', 'muteAlarm',
            'resetTray', 'restartDevice' => 'control_ack',
            default => 'write_config_ack',
        }];
    }

    /**
     * Uma escolha de uma lista, com o significado à vista. O aparelho recebe o número; quem
     * configura vê o que ele quer dizer.
     *
     * @param list<array{0: int, 1: string}> $choices
     * @return array<string, mixed>
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
        string $input = 'select',
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
            $input,
            [$field],
            self::replyTo($command),
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

    /**
     * Um número com gama, e a etiqueta e a ajuda que dizem o que ele significa.
     *
     * @return array<string, mixed>
     */
    private static function number(
        string $key,
        string $command,
        string $label,
        string $category,
        int $order,
        string $field,
        int $min,
        int $max,
        string $fieldLabel,
        string $help,
    ): array {
        return ConfigurationDefinition::make(
            $key,
            $command,
            $label,
            'number',
            [$field],
            self::replyTo($command),
            $category,
            $order,
            null,
            ['min' => $min, 'max' => $max, 'label' => $fieldLabel],
            false,
            $help,
        );
    }

    /** @return array<string, mixed> */
    private static function toggle(
        string $key,
        string $command,
        string $label,
        int $order,
        string $help,
        string $category = 'health',
    ): array {
        return ConfigurationDefinition::make(
            $key,
            $command,
            $label,
            'toggle',
            ['enabled'],
            self::replyTo($command),
            $category,
            $order,
            null,
            null,
            false,
            $help,
        );
    }

    /** @return array<string, mixed> */
    private static function action(
        string $key,
        string $command,
        string $label,
        string $category,
        int $order,
        string $help,
        string $confirm = '',
    ): array {
        return ConfigurationDefinition::make(
            $key,
            $command,
            $label,
            'action',
            [],
            self::replyTo($command),
            $category,
            $order,
            transient: true,
            help: $help,
            confirm: $confirm,
        );
    }
}
