<?php

declare(strict_types=1);

namespace Hub\Device\Decoder;

use Hub\Protocol\Adapter\PillDispenserAdapter;
use Hub\Support\Values;

final class PillDispenserEventDecoder
{
    /** As condições que o aparelho mantém acesas enquanto duram, pela TAG que as diz. */
    private const CONDITION_TAGS = [
        0x8111 => 'storage_environment',
        0x8112 => 'help_call',
        0x8121 => 'device_fault:rotation',
        0x8122 => 'device_fault:tray_reset',
        0x8123 => 'device_fault:pusher',
        0x8124 => 'device_fault:cell_door',
        0x8125 => 'device_fault:keys',
    ];

    /** O evento de cada condição: o juízo do ambiente, a chamada de ajuda, e cada avaria. */
    public const CONDITIONS = [
        'storage_environment' => ['feature' => 'storage_environment', 'value' => ['outOfRange' => true]],
        'help_call' => ['feature' => 'help_call', 'value' => []],
        'device_fault:rotation' => ['feature' => 'device_fault', 'value' => ['fault' => 'rotation']],
        'device_fault:tray_reset' => ['feature' => 'device_fault', 'value' => ['fault' => 'tray_reset']],
        'device_fault:pusher' => ['feature' => 'device_fault', 'value' => ['fault' => 'pusher']],
        'device_fault:cell_door' => ['feature' => 'device_fault', 'value' => ['fault' => 'cell_door']],
        'device_fault:keys' => ['feature' => 'device_fault', 'value' => ['fault' => 'keys']],
    ];

    /**
     * As condições que o pacote traz, acesas ou apagadas. Uma TAG que o pacote não traz não diz
     * nada, e fica de fora.
     *
     * @param array<int|string, mixed> $tlv
     * @return array<string, bool>
     */
    public static function conditions(array $tlv): array
    {
        $conditions = [];
        foreach (self::CONDITION_TAGS as $tag => $condition) {
            $state = Tlv::u8($tlv, $tag);
            if ($state !== null) {
                $conditions[$condition] = $state !== 0;
            }
        }

        return $conditions;
    }

    /**
     * O M228 traz o corpo já descodificado em TAGs TFLV: o `0x03` é uma toma e os restantes
     * carregam estado. Cada TAG lê-se com o tipo da especificação, sem o FeatureNormalizer.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public static function decode(string $nativeType, array $payload): array
    {
        $tlv = isset($payload['tlv']) && is_array($payload['tlv']) ? $payload['tlv'] : [];

        if ($nativeType === 'event') {
            $intake = self::medicationIntake($nativeType, $tlv);
            return $intake === null ? [] : [$intake];
        }

        // A resposta a uma leitura ou a uma escrita de configuração traz o corpo pedido já
        // preenchido, e o resultado de cada TAG nos bits de estado do Flag.
        if ($nativeType === 'read_config_ack' || $nativeType === 'write_config_ack') {
            $configuration = self::configuration($nativeType, $tlv);
            return $configuration === null ? [] : [$configuration];
        }

        // Tudo o resto -- heartbeat, registo, notificação e consulta de estado -- traz as
        // mesmas TAGs de estado, e por isso passa pelo mesmo caminho.
        return self::statusEvents($nativeType, $tlv);
    }

    /**
     * @param array<int, array{value?: string, state?: int}> $tlv
     * @return array<string, mixed>|null
     */
    private static function medicationIntake(string $nativeType, array $tlv): ?array
    {
        $slot = Tlv::u8($tlv, 0xC201);
        $value = Values::withoutNulls([
            'alarmSlot' => $slot === null ? null : $slot + 1,
            'scheduledAt' => Tlv::text($tlv, 0xC202),
            'takenAt' => Tlv::text($tlv, 0xC203),
            'cellNumber' => Tlv::u8($tlv, 0xC204),
            'method' => match (Tlv::u8($tlv, 0xC205)) {
                0 => 'on_time',
                1 => 'early',
                2 => 'late',
                default => null,
            },
            'result' => match (Tlv::u8($tlv, 0xC206)) {
                0 => 'on_time',
                1 => 'late',
                2 => 'abnormal',
                3 => 'missed',
                default => null,
            },
        ]);

        return $value === [] ? null : ['feature' => 'medication_intake', 'nativeType' => $nativeType, 'value' => $value];
    }

    /**
     * A configuração que o aparelho diz ter, como `device_config`, tal como nos relógios.
     *
     * @param array<int, array{value?: string, state?: int}> $tlv
     * @return array<string, mixed>|null
     */
    private static function configuration(string $nativeType, array $tlv): ?array
    {
        // Só os alarmes definidos, com o número de cada um. Decide a hora e não o interruptor: este
        // firmware ignora o `0x1041`.
        $plans = [];
        // Se a trama falou das horas, ela diz o plano inteiro — mesmo que o plano inteiro
        // seja nove slots vazios.
        $planReported = false;
        for ($offset = 0; $offset < PillDispenserAdapter::ALARM_SLOTS; $offset++) {
            $hour = Tlv::u8($tlv, 0x1021 + $offset);
            if ($hour === null) {
                continue;
            }
            $planReported = true;
            $minute = Tlv::u8($tlv, 0x1031 + $offset) ?? 0;
            if ($hour >= PillDispenserAdapter::ALARM_UNSET_HOUR || $minute >= PillDispenserAdapter::ALARM_UNSET_MINUTE) {
                continue;
            }
            $plans[] = [
                'slot' => $offset + 1,
                'hour' => $hour,
                'minute' => $minute,
            ];
        }

        // O estado no Flag: `000` é sucesso, e tudo o resto é a TAG a ser recusada.
        $refused = [];
        foreach ($tlv as $tag => $entry) {
            if ((int)($entry['state'] ?? 0) !== 0) {
                $refused[] = sprintf('0x%04X', $tag);
            }
        }

        // Pela chave do contrato e com a forma com que a configuração é enviada, para que o
        // reportado se desenhe com o mesmo componente que desenha o desejado.
        $settings = Values::withoutNulls([
            'medication_reminders' => $planReported ? ['plans' => $plans] : null,
            'medication_period' => self::period($tlv),
            'do_not_disturb' => self::quietHours($tlv),
            'alarm_volume' => self::field($tlv, 0x1013, 'volume'),
            'alarm_ringtone' => self::field($tlv, 0x1012, 'ringtone'),
            'device_language' => self::field($tlv, 0x1001, 'language'),
            'time_zone' => ($zone = Tlv::i16($tlv, 0x1015)) === null ? null : ['timeZone' => $zone],
            'child_lock' => self::switchSetting($tlv, 0x100C),
            'early_dispense' => self::switchSetting($tlv, 0x100D),
            'missed_dispense' => self::switchSetting($tlv, 0x1019),
            'emergency_call' => self::switchSetting($tlv, 0x100E),
            'key_tone' => self::switchSetting($tlv, 0x100B),
            'auto_clock' => self::switchSetting($tlv, 0x1014),
            'date_format' => self::field($tlv, 0x1002, 'format'),
            'time_format' => self::field($tlv, 0x1003, 'format'),
            // Os dois tempos viajam em segundos e mostram-se em minutos, como são enviados.
            'retrieval_warning' => self::minutes($tlv, 0x1017),
            'retrieval_timeout' => self::minutes($tlv, 0x1018),
            'loaded_cells' => self::field($tlv, 0x101C, 'cells'),
        ]);

        $value = Values::withoutNulls([
            'settings' => $settings !== [] ? $settings : null,
            'refusedTags' => $refused !== [] ? $refused : null,
        ]);

        return $value === [] ? null : ['feature' => 'device_config', 'nativeType' => $nativeType, 'value' => $value];
    }

    /** @param array<int, array{value?: string}> $tlv */
    private static function flag(array $tlv, int $tag): ?bool
    {
        $value = Tlv::u8($tlv, $tag);
        return $value === null ? null : $value === 1;
    }

    /**
     * Um número solto embrulhado no nome com que é enviado.
     *
     * @param array<int, array{value?: string}> $tlv
     * @return array<string, int>|null
     */
    private static function field(array $tlv, int $tag, string $field): ?array
    {
        $value = Tlv::u8($tlv, $tag);

        return $value === null ? null : [$field => $value];
    }

    /**
     * Um tempo que o aparelho conta em segundos, na unidade em que é configurado.
     *
     * @param array<int, array{value?: string, state?: int}> $tlv
     * @return array{minutes: int}|null
     */
    private static function minutes(array $tlv, int $tag): ?array
    {
        $value = Tlv::value($tlv, $tag);
        if ($value === null || strlen($value) < 4) {
            return null;
        }

        return ['minutes' => intdiv(unpack('V', substr($value, 0, 4))[1], 60)];
    }

    /**
     * @param array<int, array{value?: string}> $tlv
     * @return array{enabled: bool}|null
     */
    private static function switchSetting(array $tlv, int $tag): ?array
    {
        $value = self::flag($tlv, $tag);

        return $value === null ? null : ['enabled' => $value];
    }

    /**
     * A janela de «não incomodar», das quatro TAGs de hora mais o interruptor.
     *
     * @param array<int, array{value?: string}> $tlv
     * @return array{enabled: bool, startHour: int, startMinute: int, endHour: int, endMinute: int}|null
     */
    private static function quietHours(array $tlv): ?array
    {
        $enabled = self::flag($tlv, 0x1051);
        if ($enabled === null) {
            return null;
        }

        return [
            'enabled' => $enabled,
            'startHour' => Tlv::u8($tlv, 0x1052) ?? 0,
            'startMinute' => Tlv::u8($tlv, 0x1053) ?? 0,
            'endHour' => Tlv::u8($tlv, 0x1054) ?? 0,
            'endMinute' => Tlv::u8($tlv, 0x1055) ?? 0,
        ];
    }

    /**
     * O período em que o plano vale, das seis TAGs de data mais o interruptor.
     *
     * @param array<int, array{value?: string}> $tlv
     * @return array{enabled: bool, startDate?: string, endDate?: string}|null
     */
    private static function period(array $tlv): ?array
    {
        $enabled = Tlv::u8($tlv, 0x100A);
        if ($enabled === null) {
            return null;
        }

        $date = static function (int $yearTag, int $monthTag, int $dayTag) use ($tlv): ?string {
            $year = Tlv::i16($tlv, $yearTag);
            $month = Tlv::u8($tlv, $monthTag);
            $day = Tlv::u8($tlv, $dayTag);
            if ($year === null || $month === null || $day === null || !checkdate($month, $day, $year)) {
                return null;
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        };

        return Values::withoutNulls([
            'enabled' => $enabled === 1,
            'startDate' => $date(0x1004, 0x1005, 0x1006),
            'endDate' => $date(0x1007, 0x1008, 0x1009),
        ]);
    }

    /**
     * @param array<int, array{value?: string, state?: int}> $tlv
     * @return list<array{feature: string, nativeType: string, value: array<string, mixed>}>
     */
    private static function statusEvents(string $nativeType, array $tlv): array
    {
        $events = [];

        // Em hexadecimal, como a especificação nomeia tudo neste protocolo: ela não diz como
        // se lê o número, e o decimal perdia a única estrutura visível nele.
        $firmware = Tlv::u16($tlv, 0x8002);
        if ($firmware !== null) {
            $events[] = [
                'feature' => 'firmware_version',
                'nativeType' => $nativeType,
                'value' => ['version' => sprintf('0x%04X', $firmware)],
            ];
        }

        // A corrente viaja com a bateria: é a mesma pergunta feita de dois lados.
        $chargingState = Tlv::u8($tlv, 0x8104);
        $battery = Values::withoutNulls([
            'percent' => Tlv::u8($tlv, 0x8103),
            'chargingState' => match ($chargingState) {
                0 => 'normal',
                1 => 'full',
                2 => 'low',
                3 => 'charging',
                4 => 'absent',
                default => null,
            },
            'mainsPowered' => self::flag($tlv, 0x8109),
            // Sem bateria (4) não é bateria fraca, e sem a TAG não se sabe.
            'lowBattery' => $chargingState === null || $chargingState === 4 ? null : $chargingState === 2,
        ]);
        if ($battery !== []) {
            $events[] = ['feature' => 'battery', 'nativeType' => $nativeType, 'value' => $battery];
        }

        // A temperatura é INT8S e a humidade INT8U -- um byte cada, e não dois como o sinal.
        $temperature = Tlv::i8($tlv, 0x810E);
        if ($temperature !== null) {
            $events[] = ['feature' => 'ambient_temperature', 'nativeType' => $nativeType, 'value' => ['environmentCelsius' => $temperature]];
        }

        $humidity = Tlv::u8($tlv, 0x810F);
        if ($humidity !== null) {
            $events[] = ['feature' => 'ambient_humidity', 'nativeType' => $nativeType, 'value' => ['humidityPercent' => $humidity]];
        }

        // O `0x8101` é o juízo do aparelho sobre a contagem do `0x811D`: viaja como campo dela.
        $level = match (Tlv::u8($tlv, 0x8101)) {
            0 => 'ok',
            1 => 'low',
            2 => 'empty',
            default => null,
        };

        // O `0x811B` conta posições e não compartimentos: a zero é a de repouso.
        $capacity = Tlv::u8($tlv, 0x811B);
        $cells = Values::withoutNulls([
            'remaining' => Tlv::u8($tlv, 0x811D),
            'total' => $capacity === null ? null : max(0, $capacity - 1),
            'current' => Tlv::u8($tlv, 0x811A),
            'level' => $level,
        ]);
        if ($cells !== []) {
            $events[] = ['feature' => 'cells_remaining', 'nativeType' => $nativeType, 'value' => $cells];
        }

        // Os sensores do prato (`0x8107`) e do copo (`0x8106`) não entram: neste firmware respondem
        // sempre o mesmo, com a peça no sítio ou fora.


        // Sai como a `connectivity` dos gateways, em dBm: o WiFi dá dBm e o 4G o CSQ do módulo. As
        // barras do `0x810D` não vão: o `signalQuality` do contrato é o CSQ de 0 a 31.
        $cellular = self::cellularSignal(Tlv::i16($tlv, 0x810B));
        $wifi = self::negativeSignal(Tlv::i16($tlv, 0x810A));
        if ($cellular !== null || $wifi !== null) {
            $events[] = ['feature' => 'connectivity', 'nativeType' => $nativeType, 'value' => [
                'interface' => $cellular !== null ? 'cellular' : 'wifi',
                'signalStrengthDbm' => $cellular ?? $wifi,
            ]];
        }

        // Os interruptores reportados são configuração e não leitura. O `0x8105` fica de fora: só
        // traz o interruptor do «não incomodar», e escrevê-lo sozinho apagava a janela.
        $reported = Values::withoutNulls([
            'child_lock' => self::switchSetting($tlv, 0x8102),
        ]);

        if ($reported !== []) {
            $events[] = [
                'feature' => 'device_config',
                'nativeType' => $nativeType,
                'value' => ['settings' => $reported],
            ];
        }

        foreach (self::conditions($tlv) as $condition => $active) {
            if ($active) {
                $events[] = ['nativeType' => $nativeType] + self::CONDITIONS[$condition];
            }
        }

        // A resposta ao `0x07` traz os nove e é leitura; tudo o resto traz o alarme que mudou
        // e é acontecimento, que sai pelo canal com garantia de entrega.
        $doses = self::doseStates($tlv);
        if ($doses === []) {
            return $events;
        }

        if ($nativeType === 'read_status_ack') {
            $events[] = [
                'feature' => 'medication_alarm_status',
                'nativeType' => $nativeType,
                'value' => [
                    'takenCount' => count(array_filter($doses, static fn(array $d): bool => $d['state'] === 'taken')),
                    'missedCount' => count(array_filter($doses, static fn(array $d): bool => $d['state'] === 'missed')),
                    'alarms' => $doses,
                ],
            ];

            return $events;
        }

        // Um evento por alarme: dois acontecimentos numa mensagem obrigavam quem consome a
        // desempacotar uma lista para ler um facto.
        foreach ($doses as $dose) {
            $events[] = ['feature' => 'medication_alarm_change', 'nativeType' => $nativeType, 'value' => $dose];
        }

        return $events;
    }

    /**
     * A força de sinal em dBm, que é sempre negativa: o aparelho manda a magnitude sem sinal,
     * e um valor que já venha negativo fica como está.
     */
    private static function negativeSignal(?int $value): ?int
    {
        return $value === null ? null : -abs($value);
    }

    /**
     * O sinal móvel em dBm, venha como dBm (negativo) ou como CSQ (0 a 31, escala do 3GPP). O
     * `99` é o «não sei» do CSQ, e não é leitura.
     */
    private static function cellularSignal(?int $value): ?int
    {
        if ($value === null || $value > 31) {
            return null;
        }

        return $value < 0 ? $value : -113 + 2 * $value;
    }

    /**
     * O estado de toma de cada alarme que a trama reporta, na ordem dos alarmes. Quantos vêm
     * é que distingue a leitura dos nove da notificação de um, e quem chama é que decide.
     *
     * @param array<int, array{value?: string, state?: int}> $tlv
     * @return list<array{alarm: int, state: string}>
     */
    private static function doseStates(array $tlv): array
    {
        $doses = [];
        foreach (range(1, PillDispenserAdapter::ALARM_SLOTS) as $alarm) {
            // São oito, e são duas fases: o aparelho primeiro empurra a dose para fora, e só
            // depois espera que alguém a levante. Cada fase tem o seu tempo esgotado.
            $state = match (Tlv::u8($tlv, 0x8130 + $alarm)) {
                0 => 'idle',
                1 => 'preparing',
                2 => 'waiting',
                3 => 'awaiting_retrieval',
                4 => 'timed_out',
                5 => 'retrieval_timed_out',
                6 => 'missed',
                7 => 'taken',
                default => null,
            };
            if ($state !== null) {
                $doses[] = ['alarm' => $alarm, 'state' => $state];
            }
        }

        return $doses;
    }
}
