<?php

namespace Hub\Command\Downlink;

use Hub\Protocol\Adapter\PillDispenserAdapter;

/**
 * A descida do dispensador M228.
 *
 * A configuração vai num pacote `0x06` e o controlo num `0x08`, ambos em TFLV. O que os
 * distingue é o tipo de pacote e não o conteúdo.
 */
final class ZayataDownlink
{
    /**
     * O número de série de cada trama de descida, que o aparelho ecoa na resposta.
     *
     * É por ele que se sabe a qual dos pedidos pendentes uma resposta pertence. Começa em 1
     * porque o zero é o que a trama tem quando ninguém lhe mexeu.
     */
    private static int $pillSerial = 0;

    public static function build(string $imei, string $command, array $payload = [], array $context = []): string
    {

        // A calibração leva a hora a que o aparelho se deve pôr, e não um interruptor: é a
        // única TAG de controlo que é STRING.
        if ($command === 'calibrateClock') {
            return self::pillFrame($imei, 0x08, [
                0xA101 => ['value' => self::pillLocalTime($context['timeZone'] ?? null)],
            ]);
        }

        // O `0xA002`, reposição de fábrica, não está aqui de propósito: devolveria o aparelho
        // ao servidor do fornecedor, e daqui não há como o trazer de volta.
        $control = [
            'restartDevice' => 0xA001,
            'muteAlarm' => 0xA102,
            'resetTray' => 0xA103,
            'dispenseNow' => 0xA123,
        ][$command] ?? null;

        if ($control !== null) {
            return self::pillFrame($imei, 0x08, [$control => ['value' => "\x01"]]);
        }

        // As leituras. O aparelho devolve o mesmo corpo preenchido, com o resultado de cada
        // TAG no estado do Flag.
        if ($command === 'readConfiguration') {
            return self::pillFrame($imei, 0x05, PillDispenserAdapter::readRequestTlv(PillDispenserAdapter::CONFIGURATION_TAGS));
        }
        if ($command === 'readStatus') {
            return self::pillFrame($imei, 0x07, PillDispenserAdapter::readRequestTlv(PillDispenserAdapter::STATUS_TAGS));
        }

        $tlv = match ($command) {
            'medicationPlan' => self::pillMedicationPlan($payload),
            'medicationPeriod' => self::pillMedicationPeriod($payload),
            'childLock' => [0x100C => ['value' => self::pillBool($payload['enabled'] ?? false)]],
            'earlyRetrieval' => [0x100D => ['value' => self::pillBool($payload['enabled'] ?? false)]],
            'missedDispense' => [0x1019 => ['value' => self::pillBool($payload['enabled'] ?? false)]],
            // A ficha declara gama 0 a 3 e descreve só o 0 e o 1; os outros dois não se
            // mandam a um mecanismo de emergência sem saber o que são.
            'emergencyCall' => [0x100E => ['value' => self::pillBool($payload['enabled'] ?? false)]],
            'alarmRingtone' => [0x1012 => ['value' => self::pillByte($payload['ringtone'] ?? 0, 3)]],
            // 0 é o mais alto e 3 é silêncio, ao contrário do que o nome faz esperar.
            'alarmVolume' => [0x1013 => ['value' => self::pillByte($payload['volume'] ?? 0, 3)]],
            'doNotDisturb' => [
                0x1051 => ['value' => self::pillBool($payload['enabled'] ?? false)],
                0x1052 => ['value' => self::pillByte($payload['startHour'] ?? 0, 23)],
                0x1053 => ['value' => self::pillByte($payload['startMinute'] ?? 0, 59)],
                0x1054 => ['value' => self::pillByte($payload['endHour'] ?? 0, 23)],
                0x1055 => ['value' => self::pillByte($payload['endMinute'] ?? 0, 59)],
            ],
            'deviceLanguage' => [0x1001 => ['value' => self::pillByte($payload['language'] ?? 0, 1)]],
            // A ficha de cada um declara um máximo acima do que descreve — 2 para a data, e
            // o terceiro valor não está documentado. Vai só o que se sabe ler.
            'dateFormat' => [0x1002 => ['value' => self::pillByte($payload['format'] ?? 0, 1)]],
            'timeFormat' => [0x1003 => ['value' => self::pillByte($payload['format'] ?? 0, 1)]],
            'keyTone' => [0x100B => ['value' => self::pillBool($payload['enabled'] ?? false)]],
            'autoClock' => [0x1014 => ['value' => self::pillBool($payload['enabled'] ?? false)]],
            // Os dois tempos da toma viajam em segundos e expõem-se em minutos.
            'retrievalWarning' => [0x1017 => ['value' => self::pillSeconds($payload['minutes'] ?? 0)]],
            'retrievalTimeout' => [0x1018 => ['value' => self::pillSeconds($payload['minutes'] ?? 0)]],
            // Quantos vão carregados, que não é a capacidade do prato.
            'loadedCells' => [0x101C => ['value' => self::pillByte($payload['cells'] ?? 0, 28)]],
            // INT16S em HHMM: `+100` é uma hora à frente, e a oeste o sinal é negativo.
            'timeZone' => [0x1015 => ['value' => self::pillTimeZone($payload['timeZone'] ?? 0)]],
            default => throw new \InvalidArgumentException("Unsupported zayata-m228 command {$command}"),
        };

        return self::pillFrame($imei, 0x06, $tlv);
    }

    /**
     * Os nove alarmes, sempre os nove. O aparelho não os cria nem apaga, e um slot que o
     * plano não use tem de ser desligado explicitamente.
     *
     * @return array<int, array{value: string}>
     */
    private static function pillMedicationPlan(array $payload): array
    {
        $plans = array_values(array_filter($payload['plans'] ?? [], 'is_array'));
        if (count($plans) > PillDispenserAdapter::ALARM_SLOTS) {
            throw new \InvalidArgumentException('o M228 tem nove alarmes, e o plano traz ' . count($plans));
        }

        // Cada plano diz em que alarme fica. Um plano guardado antes disto não traz slot e
        // continua a valer por posição.
        $bySlot = [];
        foreach ($plans as $position => $plan) {
            $slot = isset($plan['slot']) ? (int)$plan['slot'] : $position + 1;
            if ($slot < 1 || $slot > 9) {
                throw new \InvalidArgumentException("o M228 tem nove alarmes, e o plano pede o {$slot}");
            }
            if (isset($bySlot[$slot])) {
                throw new \InvalidArgumentException("dois planos para o alarme {$slot}");
            }
            $bySlot[$slot] = $plan;
        }

        $tlv = [];
        for ($offset = 0; $offset < PillDispenserAdapter::ALARM_SLOTS; $offset++) {
            $plan = $bySlot[$offset + 1] ?? null;
            // Um plano guardado antes de o interruptor sair do cartão pode trazê-lo
            // desligado. Ele nunca calou nada, e é o vazio que finalmente o faz.
            $set = $plan !== null && ($plan['enabled'] ?? true) !== false;
            $tlv[0x1021 + $offset] = ['value' => $set
                ? self::pillByte($plan['hour'] ?? 0, 23)
                : chr(PillDispenserAdapter::ALARM_UNSET_HOUR)];
            $tlv[0x1031 + $offset] = ['value' => $set
                ? self::pillByte($plan['minute'] ?? 0, 59)
                : chr(PillDispenserAdapter::ALARM_UNSET_MINUTE)];
            $tlv[0x1041 + $offset] = ['value' => self::pillBool($set)];
        }

        return $tlv;
    }

    /**
     * O período em que o plano vale. O aparelho só sabe "todos os dias entre duas datas".
     * Sem período fica o interruptor a zero, e não as datas: essas ele leria como intervalo.
     *
     * @return array<int, array{value: string}>
     */
    private static function pillMedicationPeriod(array $payload): array
    {
        $enabled = ($payload['enabled'] ?? false) === true;
        $start = self::pillDateParts($payload['startDate'] ?? null);
        $end = self::pillDateParts($payload['endDate'] ?? null);

        return [
            // O ano é INT16U: não cabe num byte.
            0x1004 => ['value' => pack('v', $start['year'])],
            0x1005 => ['value' => self::pillByte($start['month'], 12)],
            0x1006 => ['value' => self::pillByte($start['day'], 31)],
            0x1007 => ['value' => pack('v', $end['year'])],
            0x1008 => ['value' => self::pillByte($end['month'], 12)],
            0x1009 => ['value' => self::pillByte($end['day'], 31)],
            0x100A => ['value' => self::pillBool($enabled)],
        ];
    }

    /** @return array{year: int, month: int, day: int} */
    private static function pillDateParts(mixed $date): array
    {
        if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($date), $parts) !== 1) {
            return ['year' => 0, 'month' => 0, 'day' => 0];
        }

        return ['year' => (int)$parts[1], 'month' => (int)$parts[2], 'day' => (int)$parts[3]];
    }

    /** @param array<int, array{value: string}> $tlv */
    private static function pillFrame(string $imei, int $packetType, array $tlv): string
    {
        $adapter = new PillDispenserAdapter();
        self::$pillSerial = self::$pillSerial % 65535 + 1;

        return $adapter->encodeOutgoing([
            'packetType' => $packetType,
            'serial' => self::$pillSerial,
            'deviceNumber' => PillDispenserAdapter::deviceNumberFor($imei),
            'tlv' => $tlv,
        ]);
    }

    /**
     * A hora a que o aparelho se deve pôr, no fuso dele.
     *
     * A TAG `0xA101` leva uma string sem marca de fuso e o M228 toma-a à letra. O fuso vem na
     * mesma unidade da TAG `0x1015`: INT16S em HHMM, `+100` é uma hora à frente.
     */
    private static function pillLocalTime(mixed $timeZone): string
    {
        $hhmm = (int)$timeZone;
        $minutes = intdiv($hhmm, 100) * 60 + $hhmm % 100;

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('%+d minutes', $minutes))
            ->format('Y-m-d\TH:i:s');
    }

    /**
     * Minutos para os segundos que o aparelho quer, em INT32U.
     *
     * O tecto é o da especificação: 86400 segundos, que são as vinte e quatro horas de um dia.
     */
    private static function pillSeconds(mixed $minutes): string
    {
        $number = (int)$minutes;
        if ($number < 0 || $number * 60 > 86400) {
            throw new \InvalidArgumentException("{$number} minutos fora da gama 0-1440");
        }

        return pack('V', $number * 60);
    }

    /** HHMM com sinal, na gama da especificação. */
    private static function pillTimeZone(mixed $value): string
    {
        $number = (int)$value;
        if ($number < -1200 || $number > 1400) {
            throw new \InvalidArgumentException("fuso {$number} fora da gama -1200 a 1400");
        }

        return pack('s', $number);
    }

    private static function pillByte(mixed $value, int $max = 255): string
    {
        $number = (int)$value;
        if ($number < 0 || $number > $max) {
            throw new \InvalidArgumentException("valor {$number} fora da gama 0-{$max}");
        }

        return chr($number);
    }

    private static function pillBool(mixed $value): string
    {
        return chr($value ? 1 : 0);
    }
}
