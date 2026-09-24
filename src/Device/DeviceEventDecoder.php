<?php

namespace Hub\Device;

use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Protocol\Adapter\PillDispenserAdapter;

final class DeviceEventDecoder
{
    /**
     * @return array<int, array{feature: string, nativeType: string, value: array, extra?: array}>
     */
    public function decode(DeviceSession $session, array $decoded): array
    {
        $nativeType = (string)($decoded['type'] ?? '');
        if ($nativeType === '' || $nativeType === 'login') {
            return [];
        }

        $payload = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
        if ($session->protocol === 'wonlex-json' && $nativeType === 'upSleep') {
            foreach (['isAccumulative', 'IsAccumulative'] as $field) {
                if (array_key_exists($field, $decoded) && !array_key_exists($field, $payload)) {
                    $payload[$field] = $decoded[$field];
                }
            }
        }

        $events = match ($session->protocol) {
            'wonlex-json' => $this->decodeWonlex($nativeType, $payload),
            'vivistar-iw' => $this->decodeVivistar($nativeType, $payload),
            'four-p-touch' => $this->decodeFourPTouch($nativeType, $payload),
            'zayata-m228' => $this->decodePillDispenser($nativeType, $payload),
            default => [],
        };

        return array_values(array_filter($events, 'is_array'));
    }

    private function decodeWonlex(string $nativeType, array $payload): array
    {
        return match ($nativeType) {
            'upHeartRate' => [$this->event('heart_rate', $nativeType, $payload)],
            'upBO' => [$this->event('blood_oxygen', $nativeType, $payload)],
            'upBP' => array_values(array_filter([
                $this->event('blood_pressure', $nativeType, $payload),
                $this->heartRateFromBloodPressure($nativeType, $payload),
            ])),
            'upBS' => [$this->event('blood_sugar', $nativeType, $payload)],
            'upBodyTemperature' => [$this->event('temperature', $nativeType, $payload)],
            'upBreathe' => [$this->event('breath_rate', $nativeType, $payload)],
            'upECG' => [$this->event('ecg', $nativeType, $payload)],
            'upHRV' => [$this->event('hrv', $nativeType, $payload)],
            'upPPG' => [$this->event('ppg', $nativeType, $payload)],
            'upRR' => [$this->event('rr_interval', $nativeType, $payload)],
            'upBattery' => [$this->event('battery', $nativeType, $payload)],
            'heartbeat' => array_values(array_filter([
                $this->event('heartbeat', $nativeType, $payload),
                $this->event('battery', $nativeType, $payload),
            ])),
            'upLocation' => [$this->locationEvent($nativeType, $payload)],
            'upStep', 'upKcal', 'upDistance', 'upTodayActivity', 'upRun', 'upWalk' => [$this->event('activity', $nativeType, $payload)],
            'upSleep' => [$this->event('sleep', $nativeType, $payload)],
            'upDeviceConfig' => [$this->event('device_config', $nativeType, $payload)],
            'upShutdown' => [$this->event('device_state', $nativeType, ['state' => 'shutdown'] + $payload)],
            'upReset' => [$this->event('device_state', $nativeType, ['state' => 'factory_reset'] + $payload)],
            'upBatch' => $this->decodeWonlexBatch($nativeType, $payload),
            default => [],
        };
    }

    private function decodeWonlexBatch(string $nativeType, array $payload): array
    {
        $dataType = trim((string)($payload['dataType'] ?? ''));
        $data = trim((string)($payload['data'] ?? ''));
        if ($dataType === '' && (isset($payload['heartRate']) || isset($payload['bp']) || isset($payload['bo']))) {
            $events = [];
            if (isset($payload['heartRate'])) {
                $events[] = $this->event('heart_rate', $nativeType, $payload);
            }
            if (isset($payload['bp']) && is_string($payload['bp'])) {
                $events[] = $this->event('blood_pressure', $nativeType, ['data' => $payload['bp']]);
                $events[] = $this->heartRateFromBloodPressure($nativeType, ['data' => $payload['bp']]);
            }
            if (isset($payload['bo'])) {
                $events[] = $this->event('blood_oxygen', $nativeType, ['spo2' => $payload['bo']]);
            }
            return array_values(array_filter($events, 'is_array'));
        }
        $times = array_map('trim', explode(',', (string)($payload['dataTime'] ?? '')));
        if ($dataType === '' || $data === '') {
            return [];
        }

        if ($dataType === 'upBP') {
            $measurements = str_contains($data, ';') ? explode(';', $data) : [$data];
        } else {
            $measurements = array_map('trim', explode(',', $data));
        }

        $events = [];
        foreach ($measurements as $index => $measurement) {
            $sample = array_filter([
                'data' => trim((string)$measurement),
                'measuredAt' => isset($times[$index]) && is_numeric($times[$index]) ? (int)$times[$index] : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
            if ($dataType === 'upHeartRate') {
                $events[] = $this->event('heart_rate', $nativeType, $sample);
            } elseif ($dataType === 'upBP') {
                $events[] = $this->event('blood_pressure', $nativeType, $sample);
                $events[] = $this->heartRateFromBloodPressure($nativeType, $sample);
            } elseif ($dataType === 'upBO') {
                $events[] = $this->event('blood_oxygen', $nativeType, $sample);
            } elseif ($dataType === 'upBodyTemperature') {
                $events[] = $this->event('temperature', $nativeType, $sample);
            } elseif ($dataType === 'upBreathe') {
                $events[] = $this->event('breath_rate', $nativeType, $sample);
            }
        }

        return array_values(array_filter($events, 'is_array'));
    }

    private function decodeVivistar(string $nativeType, array $payload): array
    {
        return match ($nativeType) {
            'AP01' => [$this->locationEvent($nativeType, $payload)],
            'AP02' => [$this->decodeVivistarAp02($payload)],
            'AP49' => [$this->event('heart_rate', $nativeType, $payload)],
            'APHT' => [
                $this->event('heart_rate', $nativeType, $payload),
                $this->event('blood_pressure', $nativeType, $payload),
            ],
            'APHP' => array_values(array_filter([
                $this->event('heart_rate', $nativeType, $payload),
                $this->event('blood_pressure', $nativeType, $payload),
                $this->event('blood_oxygen', $nativeType, $payload),
                $this->event('blood_sugar', $nativeType, $payload),
            ])),
            'AP50' => [
                $this->event('temperature', $nativeType, $payload),
                $this->event('battery', $nativeType, ['battery' => $payload['battery'] ?? null]),
            ],
            'AP10' => array_values(array_filter([
                ...$this->alarmEvents($nativeType, $payload),
                $this->locationEvent($nativeType, $payload),
                $this->event('battery', $nativeType, ['battery' => $payload['battery'] ?? null]),
            ])),
            'AP03' => [
                $this->event('heartbeat', $nativeType, $payload),
                $this->event('battery', $nativeType, ['battery' => $payload['battery'] ?? null]),
                $this->event('activity', $nativeType, ['steps' => $payload['steps'] ?? null]),
            ],
            'AP12', 'AP14', 'AP28', 'AP33', 'AP40',
            'AP76', 'AP77', 'AP84', 'AP85', 'AP86',
            'APJZ', 'AP43' => [
                $this->event('device_config', $nativeType, $payload),
            ],
            'AP16', 'AP87', 'APXL', 'APXY', 'APXT', 'APXZ' => [],
            default => [],
        };
    }

    private function decodeVivistarAp02(array $payload): ?array
    {
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];
        $baseStations = $this->parseVivistarBaseStations((string)($fields[5] ?? ''));
        $wifi = $this->parseVivistarWifi((string)($fields[7] ?? ''));
        $firstBase = $baseStations[0] ?? [];

        return $this->locationEvent('AP02', array_filter([
            'source' => 'vivistar-ap02',
            'gpsValid' => false,
            'mcc' => $this->stringField($fields[3] ?? null),
            'mnc' => $this->stringField($fields[4] ?? null),
            'lac' => $firstBase['lac'] ?? null,
            'cellId' => $firstBase['cellId'] ?? null,
            'gsmSignal' => $firstBase['gsmSignal'] ?? null,
            'accuracyMeters' => null,
            'baseStations' => $baseStations,
            'wifi' => $wifi,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    private function decodeFourPTouch(string $nativeType, array $payload): array
    {
        return match (true) {
            $nativeType === 'LK' => array_values(array_filter([
                $this->event('heartbeat', $nativeType, $payload),
                $this->event('activity', $nativeType, ['steps' => $payload['steps'] ?? null]),
                $this->event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
            ])),
            $nativeType === 'bphrt' => [
                $this->event('blood_pressure', $nativeType, $payload),
                $this->event('heart_rate', $nativeType, $payload),
            ],
            $nativeType === 'oxygen' => [
                $this->event('blood_oxygen', $nativeType, $payload),
            ],
            $nativeType === 'btemp2' => [
                $this->event('temperature', $nativeType, $payload),
            ],
            $this->isFourPTouchPosition($nativeType) => array_values(array_filter([
                $this->locationEvent($nativeType, $payload),
                $this->event('activity', $nativeType, ['steps' => $payload['steps'] ?? null]),
                $this->event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
            ])),
            $this->isFourPTouchAlarm($nativeType) => array_values(array_filter([
                $this->locationEvent($nativeType, $payload),
                ...$this->alarmEvents($nativeType, $payload),
                $this->event('battery', $nativeType, ['batteryPercent' => $payload['batteryPercent'] ?? null]),
            ])),
            $nativeType === 'CONFIG', $nativeType === 'TAKEPILLS' => [$this->event('device_config', $nativeType, $payload)],
            $nativeType === 'VERNO' => [$this->event('firmware_version', $nativeType, $payload)],
            $nativeType === 'TS' => [$this->event('device_status', $nativeType, $payload)],
            default => [],
        };
    }

    private function isFourPTouchPosition(string $nativeType): bool
    {
        return in_array($nativeType, FourPTouchAdapter::LOCATION_FRAME_TYPES, true);
    }

    private function isFourPTouchAlarm(string $nativeType): bool
    {
        return in_array($nativeType, FourPTouchAdapter::ALARM_FRAME_TYPES, true);
    }

    /**
     * O dispensador M228 traz o corpo já descodificado num mapa de TAGs TFLV. O evento
     * `0x03` é uma toma; os restantes pacotes carregam estado. Não passa pelo
     * FeatureNormalizer: cada TAG lê-se com o tipo que a especificação lhe dá.
     */
    private function decodePillDispenser(string $nativeType, array $payload): array
    {
        $tlv = isset($payload['tlv']) && is_array($payload['tlv']) ? $payload['tlv'] : [];

        if ($nativeType === 'event') {
            $intake = $this->pillMedicationIntake($nativeType, $tlv);
            return $intake === null ? [] : [$intake];
        }

        // A resposta a uma leitura ou a uma escrita de configuração traz o corpo pedido já
        // preenchido, e o resultado de cada TAG nos bits de estado do Flag.
        if ($nativeType === 'read_config_ack' || $nativeType === 'write_config_ack') {
            $configuration = $this->pillConfiguration($nativeType, $tlv);
            return $configuration === null ? [] : [$configuration];
        }

        // Tudo o resto -- heartbeat, registo, notificação e consulta de estado -- traz as
        // mesmas TAGs de estado, e por isso passa pelo mesmo caminho.
        return $this->pillStatusEvents($nativeType, $tlv);
    }

    private function pillMedicationIntake(string $nativeType, array $tlv): ?array
    {
        $slot = $this->tlvU8($tlv, 0xC201);
        $value = array_filter([
            'alarmSlot' => $slot === null ? null : $slot + 1,
            'scheduledAt' => $this->tlvString($tlv, 0xC202),
            'takenAt' => $this->tlvString($tlv, 0xC203),
            'cellNumber' => $this->tlvU8($tlv, 0xC204),
            'method' => match ($this->tlvU8($tlv, 0xC205)) {
                0 => 'on_time',
                1 => 'early',
                2 => 'late',
                default => null,
            },
            'result' => match ($this->tlvU8($tlv, 0xC206)) {
                0 => 'on_time',
                1 => 'late',
                2 => 'abnormal',
                3 => 'missed',
                default => null,
            },
        ], static fn (mixed $field): bool => $field !== null);

        return $value === [] ? null : ['feature' => 'medication_intake', 'nativeType' => $nativeType, 'value' => $value];
    }

    /**
     * A configuração que o aparelho diz ter.
     *
     * Sai como `device_config`, que é o que os relógios já usam para o mesmo.
     */
    private function pillConfiguration(string $nativeType, array $tlv): ?array
    {
        // Só os alarmes ligados: os outros seriam nove linhas a dizer "00:00 desligado". O
        // número do alarme vai junto, senão o terceiro voltava como se fosse o segundo.
        $plans = [];
        // Se a trama falou dos interruptores, ela diz o plano inteiro — mesmo que o plano
        // inteiro sejam nove alarmes desligados.
        $planReported = false;
        for ($offset = 0; $offset < PillDispenserAdapter::ALARM_SLOTS; $offset++) {
            $enabled = $this->tlvU8($tlv, 0x1041 + $offset);
            if ($enabled === null) {
                continue;
            }
            $planReported = true;
            if ($enabled !== 1) {
                continue;
            }
            $plans[] = [
                'slot' => $offset + 1,
                'hour' => $this->tlvU8($tlv, 0x1021 + $offset) ?? 0,
                'minute' => $this->tlvU8($tlv, 0x1031 + $offset) ?? 0,
                'enabled' => true,
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
        $settings = array_filter([
            'medication_reminders' => $planReported ? ['plans' => $plans] : null,
            'medication_period' => $this->pillPeriod($tlv),
            'do_not_disturb' => $this->pillQuietHours($tlv),
            'alarm_volume' => $this->pillField($tlv, 0x1013, 'volume'),
            'alarm_ringtone' => $this->pillField($tlv, 0x1012, 'ringtone'),
            'device_language' => $this->pillField($tlv, 0x1001, 'language'),
            'time_zone' => ($zone = $this->tlvI16($tlv, 0x1015)) === null ? null : ['timeZone' => $zone],
            'child_lock' => $this->pillSwitch($tlv, 0x100C),
            'early_dispense' => $this->pillSwitch($tlv, 0x100D),
            // Os dois tempos viajam em segundos e mostram-se em minutos, como são enviados.
            'retrieval_warning' => $this->pillMinutes($tlv, 0x1017),
            'retrieval_timeout' => $this->pillMinutes($tlv, 0x1018),
            'loaded_cells' => $this->pillField($tlv, 0x101C, 'cells'),
        ], static fn (mixed $field): bool => $field !== null);

        $value = array_filter([
            'settings' => $settings !== [] ? $settings : null,
            'refusedTags' => $refused !== [] ? $refused : null,
        ], static fn (mixed $field): bool => $field !== null);

        return $value === [] ? null : ['feature' => 'device_config', 'nativeType' => $nativeType, 'value' => $value];
    }

    /** @param array<int, array{value?: string}> $tlv */
    private function pillFlag(array $tlv, int $tag): ?bool
    {
        $value = $this->tlvU8($tlv, $tag);
        return $value === null ? null : $value === 1;
    }

    /**
     * Um número solto embrulhado no nome com que é enviado.
     *
     * @param array<int, array{value?: string}> $tlv
     * @return array<string, int>|null
     */
    private function pillField(array $tlv, int $tag, string $field): ?array
    {
        $value = $this->tlvU8($tlv, $tag);

        return $value === null ? null : [$field => $value];
    }

    /**
     * Um tempo que o aparelho conta em segundos, na unidade em que é configurado.
     *
     * @param array<int, array{value?: string, state?: int}> $tlv
     * @return array{minutes: int}|null
     */
    private function pillMinutes(array $tlv, int $tag): ?array
    {
        $value = $this->tlvValue($tlv, $tag);
        if ($value === null || strlen($value) < 4) {
            return null;
        }

        return ['minutes' => intdiv(unpack('V', substr($value, 0, 4))[1], 60)];
    }

    /**
     * @param array<int, array{value?: string}> $tlv
     * @return array{enabled: bool}|null
     */
    private function pillSwitch(array $tlv, int $tag): ?array
    {
        $value = $this->pillFlag($tlv, $tag);

        return $value === null ? null : ['enabled' => $value];
    }

    /**
     * A janela de «não incomodar», das quatro TAGs de hora mais o interruptor.
     *
     * @param array<int, array{value?: string}> $tlv
     * @return array{enabled: bool, startHour: int, startMinute: int, endHour: int, endMinute: int}|null
     */
    private function pillQuietHours(array $tlv): ?array
    {
        $enabled = $this->pillFlag($tlv, 0x1051);
        if ($enabled === null) {
            return null;
        }

        return [
            'enabled' => $enabled,
            'startHour' => $this->tlvU8($tlv, 0x1052) ?? 0,
            'startMinute' => $this->tlvU8($tlv, 0x1053) ?? 0,
            'endHour' => $this->tlvU8($tlv, 0x1054) ?? 0,
            'endMinute' => $this->tlvU8($tlv, 0x1055) ?? 0,
        ];
    }

    /**
     * O período em que o plano vale, das seis TAGs de data mais o interruptor.
     *
     * @param array<int, array{value?: string}> $tlv
     * @return array{enabled: bool, startDate?: string, endDate?: string}|null
     */
    private function pillPeriod(array $tlv): ?array
    {
        $enabled = $this->tlvU8($tlv, 0x100A);
        if ($enabled === null) {
            return null;
        }

        $date = function (int $yearTag, int $monthTag, int $dayTag) use ($tlv): ?string {
            $year = $this->tlvI16($tlv, $yearTag);
            $month = $this->tlvU8($tlv, $monthTag);
            $day = $this->tlvU8($tlv, $dayTag);
            if ($year === null || $month === null || $day === null || !checkdate($month, $day, $year)) {
                return null;
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        };

        return array_filter([
            'enabled' => $enabled === 1,
            'startDate' => $date(0x1004, 0x1005, 0x1006),
            'endDate' => $date(0x1007, 0x1008, 0x1009),
        ], static fn (mixed $field): bool => $field !== null);
    }

    /**
     * @return list<array{feature: string, nativeType: string, value: array}>
     */
    private function pillStatusEvents(string $nativeType, array $tlv): array
    {
        $events = [];

        // Em hexadecimal, como a especificação nomeia tudo neste protocolo: ela não diz como
        // se lê o número, e o decimal perdia a única estrutura visível nele.
        $firmware = $this->tlvU16($tlv, 0x8002);
        if ($firmware !== null) {
            $events[] = [
                'feature' => 'firmware_version',
                'nativeType' => $nativeType,
                'value' => ['version' => sprintf('0x%04X', $firmware)],
            ];
        }

        // A corrente viaja com a bateria: é a mesma pergunta feita de dois lados.
        $battery = array_filter([
            'percent' => $this->tlvU8($tlv, 0x8103),
            'chargingState' => match ($this->tlvU8($tlv, 0x8104)) {
                0 => 'normal',
                1 => 'full',
                2 => 'low',
                3 => 'charging',
                4 => 'absent',
                default => null,
            },
            'mainsPowered' => $this->pillFlag($tlv, 0x8109),
        ], static fn (mixed $field): bool => $field !== null);
        if ($battery !== []) {
            $events[] = ['feature' => 'battery', 'nativeType' => $nativeType, 'value' => $battery];
        }

        // A temperatura é INT8S e a humidade INT8U -- um byte cada, e não dois como o sinal.
        $temperature = $this->tlvI8($tlv, 0x810E);
        if ($temperature !== null) {
            $events[] = ['feature' => 'temperature', 'nativeType' => $nativeType, 'value' => ['environmentCelsius' => $temperature]];
        }

        $humidity = $this->tlvU8($tlv, 0x810F);
        if ($humidity !== null) {
            $events[] = ['feature' => 'humidity', 'nativeType' => $nativeType, 'value' => ['humidityPercent' => $humidity]];
        }

        // O `0x8101` é o juízo do aparelho sobre a contagem do `0x811D`: viaja como campo dela.
        $level = match ($this->tlvU8($tlv, 0x8101)) {
            0 => 'ok',
            1 => 'low',
            2 => 'empty',
            default => null,
        };

        // O `0x811B` conta posições: a zero é a de repouso e não leva medicação. O aparelho
        // responde 29, e o contrato publica os 28 compartimentos.
        $capacity = $this->tlvU8($tlv, 0x811B);
        $cells = array_filter([
            'remaining' => $this->tlvU8($tlv, 0x811D),
            'total' => $capacity === null ? null : max(0, $capacity - 1),
            'current' => $this->tlvU8($tlv, 0x811A),
            'level' => $level,
        ], static fn (mixed $field): bool => $field !== null);
        if ($cells !== []) {
            $events[] = ['feature' => 'cells_remaining', 'nativeType' => $nativeType, 'value' => $cells];
        }

        // A tampa aberta quer dizer que o prato está acessível: é um estado sobre que se age.
        $lidOpen = $this->pillFlag($tlv, 0x8107);
        if ($lidOpen !== null) {
            $events[] = ['feature' => 'lid_state', 'nativeType' => $nativeType, 'value' => ['open' => $lidOpen]];
        }

        // O juízo do aparelho sobre a temperatura e a humidade que ele mede. Só sai quando
        // dispara, como a avaria aqui ao lado.
        if ($this->pillFlag($tlv, 0x8111) === true) {
            $events[] = [
                'feature' => 'storage_environment',
                'nativeType' => $nativeType,
                'value' => ['outOfRange' => true],
            ];
        }

        // Sai como a `connectivity` dos gateways, em dBm. Não vai a contagem de barras do
        // `0x810D`: o `signalQuality` do contrato é o CSQ de 0 a 31, e as barras vão de 0 a 3.
        $cellular = self::pillNegativeSignal($this->tlvI16($tlv, 0x810B));
        $wifi = self::pillNegativeSignal($this->tlvI16($tlv, 0x810A));
        if ($cellular !== null || $wifi !== null) {
            $events[] = ['feature' => 'connectivity', 'nativeType' => $nativeType, 'value' => [
                'interface' => $cellular !== null ? 'cellular' : 'wifi',
                'signalStrengthDbm' => $cellular ?? $wifi,
            ]];
        }

        // O bloqueio de criança é o valor reportado da configuração, e não uma leitura ao
        // lado dela.
        $childLock = $this->pillSwitch($tlv, 0x8102);
        if ($childLock !== null) {
            $events[] = [
                'feature' => 'device_config',
                'nativeType' => $nativeType,
                'value' => ['settings' => ['child_lock' => $childLock]],
            ];
        }

        foreach ([0x8121 => 'rotation', 0x8122 => 'tray_reset', 0x8123 => 'pusher', 0x8124 => 'cell_door', 0x8125 => 'keys'] as $tag => $fault) {
            $state = $this->tlvU8($tlv, $tag);
            if ($state !== null && $state !== 0) {
                $events[] = ['feature' => 'device_fault', 'nativeType' => $nativeType, 'value' => ['fault' => $fault]];
            }
        }

        $emergency = $this->tlvU8($tlv, 0x8112);
        if ($emergency !== null && $emergency !== 0) {
            $events[] = ['feature' => 'help_call', 'nativeType' => $nativeType, 'value' => ['state' => 'in_progress']];
        }

        // A resposta ao `0x07` traz os nove e é leitura; tudo o resto traz o alarme que mudou
        // e é acontecimento, que sai pelo canal com garantia de entrega.
        $doses = $this->pillDoseStates($tlv);
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
    private static function pillNegativeSignal(?int $value): ?int
    {
        return $value === null ? null : -abs($value);
    }

    /**
     * O estado de toma de cada alarme que a trama reporta, na ordem dos alarmes. Quantos vêm
     * é que distingue a leitura dos nove da notificação de um, e quem chama é que decide.
     *
     * @param array<int, array{value?: string, state?: int}> $tlv
     * @return list<array{alarm: int, state: string}>
     */
    private function pillDoseStates(array $tlv): array
    {
        $doses = [];
        foreach (range(1, PillDispenserAdapter::ALARM_SLOTS) as $alarm) {
            $state = match ($this->tlvU8($tlv, 0x8130 + $alarm)) {
                0 => 'idle',
                1 => 'preparing',
                2 => 'waiting',
                4 => 'timed_out',
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

    /**
     * O valor de uma TAG, ou `null` quando não há valor nenhum a ler.
     *
     * O estado nos bits 5--7 do Flag distingue uma leitura de um eco: uma TAG recusada volta
     * com os bytes que lhe mandámos -- zeros -- e um estado diferente de `000`.
     *
     * @param array<int, array{value?: string, state?: int}> $tlv
     */
    private function tlvValue(array $tlv, int $tag): ?string
    {
        $entry = $tlv[$tag] ?? null;
        if (!is_array($entry) || (int)($entry['state'] ?? 0) !== 0) {
            return null;
        }

        $value = $entry['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<int, array{value?: string, state?: int}> $tlv */
    private function tlvU8(array $tlv, int $tag): ?int
    {
        $value = $this->tlvValue($tlv, $tag);
        return $value === null ? null : ord($value[0]);
    }

    /** @param array<int, array{value?: string, state?: int}> $tlv */
    private function tlvI8(array $tlv, int $tag): ?int
    {
        $value = $this->tlvValue($tlv, $tag);
        return $value === null ? null : unpack('c', $value[0])[1];
    }

    /** @param array<int, array{value?: string, state?: int}> $tlv */
    private function tlvI16(array $tlv, int $tag): ?int
    {
        $value = $this->tlvValue($tlv, $tag);
        return $value === null || strlen($value) < 2 ? null : unpack('s', substr($value, 0, 2))[1];
    }

    /** @param array<int, array{value?: string, state?: int}> $tlv */
    private function tlvU16(array $tlv, int $tag): ?int
    {
        $value = $this->tlvValue($tlv, $tag);
        return $value === null || strlen($value) < 2 ? null : unpack('v', substr($value, 0, 2))[1];
    }

    /** @param array<int, array{value?: string, state?: int}> $tlv */
    private function tlvString(array $tlv, int $tag): ?string
    {
        $value = $this->tlvValue($tlv, $tag);
        return $value === null ? null : rtrim($value, "\0");
    }

    private function event(string $feature, string $nativeType, array $payload): ?array
    {
        $value = FeatureNormalizer::normalize($feature, $payload);
        if ($value === []) {
            return null;
        }

        return array_filter([
            'feature' => $feature,
            'nativeType' => $nativeType,
            'value' => $value,
        ], static fn (mixed $field): bool => $field !== []);
    }

    /**
     * Um evento `alarm` por motivo ativo — vários bits da máscara do 4P Touch
     * dão vários eventos; máscara a zero não dá nenhum.
     *
     * @return list<array{feature: string, nativeType: string, value: array{reason: string}}>
     */
    private function alarmEvents(string $nativeType, array $payload): array
    {
        return array_map(
            static fn (string $reason): array => [
                'feature' => 'alarm',
                'nativeType' => $nativeType,
                'value' => ['reason' => $reason],
            ],
            FeatureNormalizer::alarmReasons($payload)
        );
    }

    private function locationEvent(string $nativeType, array $payload): ?array
    {
        $payload['radioType'] = $payload['radioType'] ?? $payload['networkType'] ?? match ($nativeType) {
            'UD', 'UD2', 'AL' => 'gsm',
            'UD_WCDMA', 'AL_WCDMA' => 'wcdma',
            'UD_LTE', 'AL_LTE' => 'lte',
            default => null,
        };
        $payload['reportKind'] = $payload['reportKind'] ?? match ($nativeType) {
            'UD2' => 'replay',
            'AL', 'AL_WCDMA', 'AL_LTE', 'AP10' => 'alarm',
            'UD', 'UD_WCDMA', 'UD_LTE', 'AP01' => 'periodic',
            'upLocation' => match ((string)($payload['positionDataType'] ?? $payload['dataType'] ?? $payload['DataType'] ?? '')) {
                '0' => 'periodic',
                '1' => 'requested',
                default => null,
            },
            default => null,
        };

        return $this->event('location', $nativeType, $payload);
    }

    private function heartRateFromBloodPressure(string $nativeType, array $payload): ?array
    {
        $pulse = $payload['pulse'] ?? $payload['pulseBpm'] ?? $payload['heartRate'] ?? $payload['hr'] ?? null;
        if ($pulse === null) {
            $rawData = $payload['data'] ?? $payload['date'] ?? null;
            if (is_string($rawData) && str_contains($rawData, '/')) {
                $parts = preg_split('/[\/,\-]+/', $rawData) ?: [];
                $pulse = $parts[2] ?? null;
            }
        }

        return $this->event('heart_rate', $nativeType, [
            'pulse' => $pulse,
        ]);
    }

    /**
     * @return array<int, array{lac?: string, cellId?: string, gsmSignal?: int}>
     */
    private function parseVivistarBaseStations(string $field): array
    {
        $stations = [];
        foreach (explode(',', $field) as $entry) {
            $parts = array_map('trim', explode('|', $entry));
            if (count($parts) < 3) {
                continue;
            }

            $rawSignal = $this->intField($parts[2] ?? null);
            $stations[] = array_filter([
                'lac' => $parts[0] !== '' ? $parts[0] : null,
                'cellId' => $parts[1] !== '' ? $parts[1] : null,
                'gsmSignal' => $this->legacySignalStrength($rawSignal),
                'signalStrengthDbm' => $this->vivistarSignalDbm($rawSignal),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $stations;
    }

    /**
     * @return array<int, array{label?: string, mac?: string, gsmSignal?: int}>
     */
    private function parseVivistarWifi(string $field): array
    {
        $wifi = [];
        foreach (explode('&', $field) as $entry) {
            $parts = array_map('trim', explode('|', $entry));
            if (count($parts) < 3) {
                continue;
            }

            $rawSignal = $this->intField($parts[2] ?? null);
            $wifi[] = array_filter([
                'label' => $parts[0] !== '' ? $parts[0] : null,
                'mac' => $parts[1] !== '' ? $parts[1] : null,
                'gsmSignal' => $this->legacySignalStrength($rawSignal),
                'signalStrengthDbm' => $this->vivistarSignalDbm($rawSignal),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $wifi;
    }

    private function legacySignalStrength(?int $value): ?int
    {
        return $value === null ? null : max(0, 150 - abs($value));
    }

    private function vivistarSignalDbm(?int $value): ?int
    {
        return $value === null ? null : $value - 150;
    }

    private function intField(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric((string)$value) ? null : (int)$value;
    }

    private function stringField(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string)$value;
    }
}
