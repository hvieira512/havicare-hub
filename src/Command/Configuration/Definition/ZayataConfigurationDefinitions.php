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
                'alerts',
                10,
                9,
                null,
                false,
                'Enviado em bloco: um slot em branco fica vazio. O número é etiqueta e não'
                . ' ordem — quem manda é a hora.',
            ),
            ConfigurationDefinition::make(
                'medication_period',
                'medicationPeriod',
                'Período do plano',
                'pillDispenserPeriod',
                ['enabled', 'startDate', 'endDate'],
                self::replyTo('medicationPeriod'),
                'alerts',
                11,
                null,
                null,
                false,
                'Entre que datas o plano vale; desligado, tocam sempre. Não há dias da semana.',
            ),
            // Dois interruptores independentes, duas definições: a dashboard agrupa interruptores
            // seguidos em linhas compactas.
            self::toggle('early_dispense', 'earlyRetrieval', 'Toma antecipada', 20, 'Deixa o utente levantar a medicação antes da hora marcada.'),
            self::toggle('child_lock', 'childLock', 'Bloqueio de criança', 21, 'Tranca o prato para não ser aberto por quem não deve.'),
            self::toggle('missed_dispense', 'missedDispense', 'Dispensar depois de falhar', 22, 'Deixa levantar a dose depois de ela já contar como falhada.'),
            self::toggle(
                'emergency_call',
                'emergencyCall',
                'Chamada de emergência',
                30,
                'Decide se o botão do aparelho chega a pedir ajuda. Desligado, ele deixa de'
                . ' produzir a chamada.',
                'alerts',
            ),
            // Os dois tempos decidem se uma dose por tomar chega a alguém como alerta ou fica em silêncio.
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
                'Depois de o alarme tocar, quanto espera antes de marcar a toma como atrasada.',
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
                'Quanto espera antes de dar a toma como falhada. Tem de ser maior do que o aviso de atraso.',
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
                'O último compartimento que encheu, e não quantos encheu: o aparelho não vê lá dentro.',
            ),
            // O volume é uma enumeração: na especificação, 0 é o mais alto e 3 é silêncio. Num grupo de
            // botões, para a ordem mostrar que a escala está invertida.
            self::choice('alarm_volume', 'alarmVolume', 'Volume', 'alerts', 20, 'volume', [
                [0, 'Alto'],
                [1, 'Médio'],
                [2, 'Baixo'],
                [3, 'Silêncio'],
            ], 'Em silêncio não toca de todo, e a dose continua a contar como falhada.', input: 'volumeScale'),
            self::choice('alarm_ringtone', 'alarmRingtone', 'Tipo de toque', 'alerts', 21, 'ringtone', [
                [0, 'Nenhum'],
                [1, 'Toque 1'],
                [2, 'Toque 2'],
                [3, 'Toque 3'],
            ], 'Qual dos quatro toques chama a pessoa à hora da medicação.'),
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
                'Uma janela em que não toca. Os alarmes lá dentro dispensam na mesma.',
            ),
            // Duas opções e mais nada: o aparelho só fala a língua de fábrica ou inglês. O
            // português existe, mas só instalado de origem — não é configurável.
            self::choice('device_language', 'deviceLanguage', 'Idioma do ecrã', 'system', 10, 'language', [
                [0, 'Do aparelho'],
                [1, 'Inglês'],
            ], 'A língua do ecrã do aparelho, não a da dashboard.'),
            self::choice('date_format', 'dateFormat', 'Formato da data', 'system', 11, 'format', [
                [0, 'YYYY-MM-DD'],
                [1, 'DD-MM-YYYY'],
                [2, 'MM-DD-YYYY'],
            ], 'A ordem por que o aparelho escreve a data no ecrã. Não muda a da dashboard.'),
            self::choice('time_format', 'timeFormat', 'Formato da hora', 'system', 12, 'format', [
                [0, '24 horas'],
                [1, '12 horas'],
            ], 'Relógio de 24 ou de 12 horas no ecrã do aparelho.'),
            self::toggle(
                'auto_clock',
                'autoClock',
                'Acertar-se sozinho',
                13,
                'O aparelho corrige a própria hora sem ninguém lhe pedir. Desligado, só muda'
                . ' com o «Acertar o relógio do aparelho», aqui em baixo.',
                'system',
            ),
            self::toggle(
                'key_tone',
                'keyTone',
                'Som das teclas',
                14,
                'O apito que o aparelho dá quando alguém carrega num botão.',
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
                'O relógio do aparelho deriva, e o fuso é o que dá sentido às horas que ele reporta.',
                // Parte de Lisboa no inverno, e não da ponta da lista.
                0,
            ),
            // As leituras. Sem elas o hub sabe o que *pediu* ao aparelho e não o que ele
            // *tem* — e a especificação manda ler os parâmetros no primeiro registo.
            self::action(
                'sync_configuration',
                'readConfiguration',
                'Sincronizar configuração',
                'system',
                5,
                'Confirma que o que está no ecrã é o que o aparelho ficou a ter. Não muda nada.',
            ),
            // «Atualizar estado» pede-se do mosaico dele. Desligar a cifra (`0x8005`) não entra: o
            // fornecedor confirma que o aparelho o recusa.

            // O relógio calibra-se à mão porque o aparelho deriva. Dispensar fica em Saúde e não em
            // Sistema: é um acto sobre a medicação do utente.
            self::action(
                'dispense_now',
                'dispenseNow',
                'Dispensar agora',
                'health',
                40,
                'Empurra já a dose do próximo alarme de hoje e dá-o como tomado. Sem alarme por vir, não faz nada.',
                'Isto gasta a dose do próximo alarme e dá-a como tomada. Confirma?',
            ),
            // Rodar até um compartimento (`0xA124`) e pausar a medicação (`0xA125`) não entram: este
            // firmware recusa-os com «TAG inválida».
            self::action(
                'calibrate_clock',
                'calibrateClock',
                'Acertar o relógio do aparelho',
                'system',
                30,
                'Os alarmes disparam pelo relógio do aparelho, e ele deriva: atrasado, as doses saem à hora errada sem erro nenhum.',
            ),
            // Em Alarmes e não em Sistema: o que isto faz é calar um alarme que está a tocar.
            self::action(
                'mute_alarm',
                'muteAlarm',
                'Silenciar o alarme a tocar',
                'alerts',
                30,
                'Cala o alarme que está a tocar agora. A dose continua por tomar.',
            ),
            self::action(
                'reset_tray',
                'resetTray',
                'Repor o prato',
                'system',
                50,
                'Reassenta o carrossel na origem, para quando o prato ficou desalinhado.',
            ),
            self::action(
                'restart_device',
                'restartDevice',
                'Reiniciar',
                'system',
                60,
                'Não apaga configurações nem o plano. Uma toma agendada para o minuto do arranque não sai.',
                'O dispensador fica sem comunicar enquanto arranca. Uma toma agendada para esse minuto não é dispensada.',
            ),
            // A reposição de fábrica não entra: devolve o aparelho ao servidor do fornecedor, e perde-se
            // o controlo dele.
        ];
    }

    /**
     * A resposta esperada é a do tipo de pacote e não a do comando: o M228 responde a um `0x06`
     * com `write_config_ack` seja qual for a TAG.
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

    /**
     * Uma acção leva sempre uma frase a dizer o que faz: metade destes nomes — «Repor o
     * prato», «Parâmetros de controlo» — não se explica a si própria.
     *
     * @return array<string, mixed>
     */
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
