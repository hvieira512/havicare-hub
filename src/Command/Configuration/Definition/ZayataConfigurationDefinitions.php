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
                self::replyTo('medicationPlan'),
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
                self::replyTo('medicationPeriod'),
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
            // Os dois tempos decidem se uma dose por tomar chega a alguém como alerta ou fica
            // em silêncio, e até agora só se mudavam por script.
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
                'Quanto tempo o aparelho espera, depois de o alarme tocar, antes de marcar a toma como atrasada. De fábrica são 30 minutos.',
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
                'Quanto tempo espera antes de desistir e dar a toma como falhada. É esta que faz a dose contar como perdida. De fábrica são 60 minutos, e tem de ser maior do que o aviso de atraso.',
            ),
            self::number(
                'loaded_cells',
                'loadedCells',
                'Compartimentos carregados',
                'health',
                32,
                'cells',
                0,
                28,
                'Compartimentos',
                'Quantos dos 28 compartimentos foram carregados com medicação. É por este número que o aparelho sabe avisar que está a acabar — não se confunde com a capacidade do prato, que é sempre 28.',
            ),
            // O volume é uma enumeração e não uma escala: na especificação, 0 é o mais alto
            // e 3 é silêncio. Um número solto no ecrã dizia exactamente o contrário a quem o
            // lesse.
            // Num grupo de botões e não numa lista fechada: são quatro posições e a ordem é
            // que diz que a escala está invertida. Uma de cada vez escondia isso.
            self::choice('alarm_volume', 'alarmVolume', 'Volume', 'alerts', 10, 'volume', [
                [0, 'Alto'],
                [1, 'Médio'],
                [2, 'Baixo'],
                [3, 'Silêncio'],
            ], input: 'buttonGroup'),
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
                self::replyTo('doNotDisturb'),
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
            // As leituras. Sem elas o hub sabe o que *pediu* ao aparelho e não o que ele
            // *tem* — e a especificação manda ler os parâmetros no primeiro registo.
            self::action('sync_configuration', 'readConfiguration', 'Sincronizar configuração', 'system', 5),
            self::action('device_status', 'readStatus', 'Atualizar estado', 'system', 6),
            // Perguntar ao aparelho que parâmetros ele serve, em vez de adivinhar por recusa.
            // São três porque o aparelho separa configuração, estado e controlo, e cada
            // pergunta é um pacote próprio; um botão só cobria um terço da resposta.
            self::action('supported_configuration', 'discoverParametersConfiguration', 'Parâmetros de configuração', 'system', 7),
            self::action('supported_status', 'discoverParametersStatus', 'Parâmetros de estado', 'system', 8),
            self::action('supported_control', 'discoverParametersControl', 'Parâmetros de controlo', 'system', 9),
            // Desligar a cifra também não entra: o `0x8005` aparece na tabela dos parâmetros
            // escrevíveis, mas o fornecedor respondeu que o aparelho o recusa e que a chave sai
            // da codificação dele — ou cifra tudo o que envia, ou não cifra nada, e a decisão
            // não é deste lado. O botão só prometia uma saída que o firmware não tem.

            // As acções. O relógio calibra-se à mão porque num ensaio um alarme das 12:55
            // ficou registado às 11:45.
            self::action('dispense_now', 'dispenseNow', 'Dispensar agora', 'system', 20),
            // Duas ordens de controlo que levam um valor, e não um interruptor.
            self::number(
                'rotate_to_cell',
                'rotateToCell',
                'Rodar até ao compartimento',
                'system',
                21,
                'cell',
                0,
                28,
                'Compartimento',
                'Roda o prato até ao compartimento indicado, sem dispensar nada. Serve para carregar ou inspecionar um compartimento em particular. Zero não roda.',
            ),
            self::number(
                'medication_pause',
                'medicationPause',
                'Pausar medicação',
                'system',
                22,
                'minutes',
                0,
                255,
                'Minutos',
                'Suspende a dispensa durante os minutos indicados — para uma ida ao hospital ou uma consulta. Zero volta ao normal.',
            ),
            self::action('calibrate_clock', 'calibrateClock', 'Calibrar relógio', 'system', 30),
            self::action('mute_alarm', 'muteAlarm', 'Silenciar', 'system', 40),
            self::action('reset_tray', 'resetTray', 'Repor o prato', 'system', 50),
            self::action('restart_device', 'restartDevice', 'Reiniciar', 'system', 60),
            // A reposição de fábrica não entra. O aparelho só aponta para o hub porque o
            // fornecedor lhe mandou essa configuração, e uma reposição devolve-o ao servidor
            // dele: perde-se o controlo do aparelho e recuperá-lo depende de outra pessoa,
            // noutro fuso horário. Não há nada que ela resolva que justifique o botão.
        ];
    }

    /**
     * A resposta que um comando espera é a do seu tipo de pacote, e não o nome dele.
     *
     * É a diferença entre este protocolo e os outros: o M2 responde a um `0x06` com
     * `write_config_ack` seja qual for a TAG que ele levou, e por isso o valor por omissão --
     * uma resposta com o nome do comando -- nunca casava. O resultado era a dashboard a dar
     * «falhou, tentativas esgotadas» em configurações que o aparelho tinha aceitado, e o hub
     * a reenviá-las de minuto a minuto.
     *
     * @return list<string>
     */
    private static function replyTo(string $command): array
    {
        return [match ($command) {
            'readConfiguration' => 'read_config_ack',
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
     * A ajuda não é decoração: metade destas definições tem um nome que não se explica a si
     * próprio, e sem uma frase quem opera a dashboard fica a adivinhar.
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

    private static function toggle(string $key, string $command, string $label, int $order, string $help): array
    {
        return ConfigurationDefinition::make(
            $key,
            $command,
            $label,
            'toggle',
            ['enabled'],
            self::replyTo($command),
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
            self::replyTo($command),
            $category,
            $order,
            null,
            null,
            true,
        );
    }
}
