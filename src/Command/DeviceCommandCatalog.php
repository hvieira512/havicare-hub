<?php

namespace Hub\Command;

use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use Hub\Protocol\Adapter\VivistarAdapter;
use Hub\Protocol\Adapter\WonlexAdapter;

final class DeviceCommandCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function models(): array
    {
        return [
            ['supplier' => 'Wonlex', 'model' => 'HW20PRO', 'image' => '', 'protocol' => 'wonlex-json'],
            ['supplier' => 'Wonlex', 'model' => 'L08 Pro', 'image' => '', 'protocol' => 'wonlex-json'],
            ['supplier' => 'Vivistar', 'model' => 'VIVISTAR-CARE', 'image' => '', 'protocol' => 'vivistar-iw'],
            ['supplier' => 'Vivistar', 'model' => 'VIVISTAR-LITE', 'image' => '', 'protocol' => 'vivistar-iw'],
            ['supplier' => '4P Touch', 'model' => '4P-TOUCH', 'image' => '', 'protocol' => 'four-p-touch'],
            ['supplier' => '4P Touch', 'model' => 'D46', 'image' => '', 'protocol' => 'four-p-touch'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function commandsForProtocol(string $protocol): array
    {
        return match ($protocol) {
            'wonlex-json' => self::wonlexCommands(),
            'vivistar-iw' => self::vivistarCommands(),
            'four-p-touch' => self::fourPTouchCommands(),
            'veepoo-ble' => self::veepooCommands(),
            default => [],
        };
    }

    public static function commandForProtocol(string $protocol, string $command): ?array
    {
        foreach (self::commandsForProtocol($protocol) as $entry) {
            if (($entry['command'] ?? '') === $command) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function featuresForProtocol(string $protocol): array
    {
        $features = [];
        foreach (self::commandsForProtocol($protocol) as $entry) {
            if ((string)($entry['kind'] ?? '') !== 'request') {
                continue;
            }
            $feature = trim((string)($entry['feature'] ?? ''));
            if ($feature !== '') {
                $features[$feature] = true;
            }
        }

        return array_keys($features);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function commandsForFeature(string $protocol, string $feature): array
    {
        return array_values(array_filter(
            self::commandsForProtocol($protocol),
            static fn(array $entry): bool => (string)($entry['kind'] ?? '') === 'request'
                && trim((string)($entry['feature'] ?? '')) === $feature
        ));
    }

    public static function buildDownlink(string $protocol, string $imei, string $command, array $payload = [], array $context = []): string
    {
        $entry = self::commandForProtocol($protocol, $command);
        $configEntry = null;
        if ($entry === null) {
            $configEntry = DeviceConfigurationCatalog::configForCommand($protocol, $command);
            if ($configEntry === null) {
                throw new \InvalidArgumentException("Unsupported {$protocol} command {$command}");
            }
            $entry = $configEntry;
        }

        return match ($protocol) {
            'wonlex-json' => self::buildWonlex($imei, $command, $payload, $context),
            'vivistar-iw' => self::buildVivistar($imei, $command, $entry, $payload),
            'four-p-touch' => self::buildFourPTouch($imei, $command, $entry, $payload, $context),
            // Não há trama a montar: o destinatário é o gateway, e o que ele precisa é do
            // nome da operação para chamar o SDK. Os bytes em fila são esse nome.
            'veepoo-ble' => $command,
            'zayata-m228' => self::buildPillDispenser($imei, $command, $payload),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol}"),
        };
    }

    public static function normalizeQueuedDownlink(string $protocol, string $bytes): string
    {
        if ($protocol !== 'wonlex-json') {
            return $bytes;
        }

        $adapter = new WonlexAdapter();
        $decoded = $adapter->decodeIncoming($bytes);
        if (!is_array($decoded)) {
            return $bytes;
        }

        $nativeType = (string)($decoded['type'] ?? '');
        if (
            !in_array($nativeType, [
            'dnHeartRate',
            'dnBP',
            'dnBO',
            'dnTemperature',
            'dnBreathe',
            'dnECG',
            'dnHRV',
            'dnPPG',
            'dnRR',
            ], true)
        ) {
            return $bytes;
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        if (($data['fields'] ?? null) !== []) {
            return $bytes;
        }

        unset($data['fields']);
        if (self::isWonlexRequestedWaveform($nativeType)) {
            $data += self::wonlexWaveformDefaults($nativeType);
        }
        $decoded['data'] = $data;

        return $adapter->encodeOutgoing($decoded);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * Medições a pedido de uma pulseira Veepoo.
     *
     * Ao contrário dos relógios, aqui o comando não viaja para o aparelho: vai para o gateway
     * que tem a sessão BLE, e é ele que chama o SDK. Por isso o `command` é o nome da operação
     * na ponte, e não uma trama.
     *
     * Não há `location`: a MF91 não tem GPS. Também não há pedido de HRV, PPG nem intervalos
     * R-R -- o firmware calcula-os nos blocos que acumula sozinho e não os mede a pedido.
     *
     * Cada uma destas foi confirmada contra a pulseira: o pedido sai, o aparelho mede e o
     * valor volta. A tensão é a mais lenta -- cerca de meio minuto -- e é servida pela
     * «tensão universal» do SDK, e não pelo comando de tensão personalizada, que apesar do
     * nome só define valores e responde sempre com zeros.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function veepooCommands(): array
    {
        return [
            ['id' => 'measureHeartRate', 'command' => 'measure.heartRate.start', 'label' => 'Heart rate', 'icon' => 'fa-heart-pulse', 'kind' => 'request', 'feature' => 'heart_rate', 'expectedReplyTypes' => ['heart_rate']],
            ['id' => 'measureBloodPressure', 'command' => 'measure.bloodPressure.start', 'label' => 'Blood pressure', 'icon' => 'fa-stethoscope', 'kind' => 'request', 'feature' => 'blood_pressure', 'expectedReplyTypes' => ['blood_pressure']],
            ['id' => 'measureOxygen', 'command' => 'measure.oxygen.start', 'label' => 'Blood oxygen', 'icon' => 'fa-droplet', 'kind' => 'request', 'feature' => 'blood_oxygen', 'expectedReplyTypes' => ['blood_oxygen']],
            ['id' => 'measureBloodGlucose', 'command' => 'measure.bloodGlucose.start', 'label' => 'Blood glucose', 'icon' => 'fa-vial', 'kind' => 'request', 'feature' => 'blood_sugar', 'expectedReplyTypes' => ['blood_sugar']],
            ['id' => 'measureTemperature', 'command' => 'measure.temperature.start', 'label' => 'Temperature', 'icon' => 'fa-temperature-half', 'kind' => 'request', 'feature' => 'temperature', 'expectedReplyTypes' => ['temperature']],
            // Mede-se pelos elétrodos do ECG e exige o dedo encostado, como ele. Leva cerca
            // de meio minuto e devolve catorze grandezas de uma vez.
            ['id' => 'measureBodyComposition', 'command' => 'measure.bodyComposition.start', 'label' => 'Body composition', 'icon' => 'fa-weight-scale', 'kind' => 'request', 'feature' => 'body_composition', 'expectedReplyTypes' => ['body_composition']],
            ['id' => 'measureStress', 'command' => 'measure.stress.start', 'label' => 'Stress', 'icon' => 'fa-gauge-high', 'kind' => 'request', 'feature' => 'stress', 'expectedReplyTypes' => ['stress']],
            // A bateria é o único pedido que não depende do sensor ótico: responde sempre,
            // e em menos de um segundo.
            ['id' => 'readBattery', 'command' => 'read.battery', 'label' => 'Battery', 'icon' => 'fa-battery-half', 'kind' => 'request', 'feature' => 'battery', 'expectedReplyTypes' => ['battery']],
            // O registo de sono é a única grandeza sem outro caminho: os blocos de cinco
            // minutos são relidos sozinhos, ele entrava uma vez só, no arranque do gateway.
            // É uma leitura e não uma medição -- a pulseira já o tem calculado, e não ter
            // dormido não é falha dela.
            ['id' => 'readSleep', 'command' => 'read.sleep', 'label' => 'Sleep', 'icon' => 'fa-bed', 'kind' => 'request', 'feature' => 'sleep', 'expectedReplyTypes' => ['sleep', 'sleep_quality']],
            // O acumulado do dia responde no instante, como a bateria: é um contador que a
            // pulseira já tem, e não uma medição a fazer.
            ['id' => 'readDailyTotals', 'command' => 'read.dailyTotals', 'label' => 'Daily totals', 'icon' => 'fa-shoe-prints', 'kind' => 'request', 'feature' => 'activity', 'expectedReplyTypes' => ['activity']],
            // O ECG arranca e transmite, mas exige que quem a usa encoste o dedo ao elétrodo:
            // sem isso o aparelho envia dezenas de tramas com `wearNotPass` e tudo a zero.
            // Fica no catálogo porque é assim em qualquer pulseira com ECG, e a falha de
            // contacto é reportada em vez de o pedido ficar pendurado.
            ['id' => 'measureEcg', 'command' => 'measure.ecg.start', 'label' => 'ECG', 'icon' => 'fa-wave-square', 'kind' => 'request', 'feature' => 'ecg', 'expectedReplyTypes' => ['ecg']],
        ];
    }

    private static function wonlexCommands(): array
    {
        return [
            ['id' => 'dnHeartRate', 'command' => 'dnHeartRate', 'label' => 'Heart rate', 'icon' => 'fa-heart-pulse', 'kind' => 'request', 'feature' => 'heart_rate', 'expectedReplyTypes' => ['upHeartRate', 'upBatch']],
            ['id' => 'dnBP', 'command' => 'dnBP', 'label' => 'Blood pressure', 'icon' => 'fa-stethoscope', 'kind' => 'request', 'feature' => 'blood_pressure', 'expectedReplyTypes' => ['upBP', 'upBatch']],
            ['id' => 'dnBO', 'command' => 'dnBO', 'label' => 'Blood oxygen', 'icon' => 'fa-droplet', 'kind' => 'request', 'feature' => 'blood_oxygen', 'expectedReplyTypes' => ['upBO', 'upBatch']],
            ['id' => 'dnTemperature', 'command' => 'dnTemperature', 'label' => 'Temperature', 'icon' => 'fa-temperature-half', 'kind' => 'request', 'feature' => 'temperature', 'expectedReplyTypes' => ['upBodyTemperature', 'upBatch']],
            ['id' => 'dnBreathe', 'command' => 'dnBreathe', 'label' => 'Breath rate', 'icon' => 'fa-lungs', 'kind' => 'request', 'feature' => 'breath_rate', 'expectedReplyTypes' => ['upBreathe', 'upBatch']],
            ['id' => 'dnLocation', 'command' => 'dnLocation', 'label' => 'Location', 'icon' => 'fa-location-dot', 'kind' => 'request', 'feature' => 'location', 'expectedReplyTypes' => ['upLocation']],
            ['id' => 'dnUpSleep', 'command' => 'dnUpSleep', 'label' => 'Sleep data', 'icon' => 'fa-bed', 'kind' => 'data', 'feature' => 'sleep', 'expectedReplyTypes' => ['dnUpSleep']],
            ['id' => 'dnECG', 'command' => 'dnECG', 'label' => 'ECG', 'icon' => 'fa-wave-square', 'kind' => 'request', 'feature' => 'ecg', 'expectedReplyTypes' => ['upECG']],
            ['id' => 'dnHRV', 'command' => 'dnHRV', 'label' => 'HRV', 'icon' => 'fa-chart-line', 'kind' => 'request', 'feature' => 'hrv', 'expectedReplyTypes' => ['upHRV']],
            ['id' => 'dnPPG', 'command' => 'dnPPG', 'label' => 'PPG', 'icon' => 'fa-circle-nodes', 'kind' => 'request', 'feature' => 'ppg', 'expectedReplyTypes' => ['upPPG']],
            ['id' => 'dnRR', 'command' => 'dnRR', 'label' => 'RR interval', 'icon' => 'fa-stopwatch', 'kind' => 'request', 'feature' => 'rr_interval', 'expectedReplyTypes' => ['upRR']],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function vivistarCommands(): array
    {
        return [
            ['id' => 'BPXL', 'command' => 'BPXL', 'label' => 'Heart rate', 'icon' => 'fa-heart-pulse', 'kind' => 'request', 'feature' => 'heart_rate', 'expectedReplyTypes' => ['APXL']],
            ['id' => 'BPXY', 'command' => 'BPXY', 'label' => 'Blood pressure', 'icon' => 'fa-stethoscope', 'kind' => 'request', 'feature' => 'blood_pressure', 'expectedReplyTypes' => ['APXY']],
            ['id' => 'BPXZ', 'command' => 'BPXZ', 'label' => 'Blood oxygen', 'icon' => 'fa-droplet', 'kind' => 'request', 'feature' => 'blood_oxygen', 'expectedReplyTypes' => ['APXZ']],
            ['id' => 'BPXT', 'command' => 'BPXT', 'label' => 'Temperature', 'icon' => 'fa-temperature-half', 'kind' => 'request', 'feature' => 'temperature', 'expectedReplyTypes' => ['APXT']],
            ['id' => 'BP16', 'command' => 'BP16', 'label' => 'Location', 'icon' => 'fa-location-dot', 'kind' => 'request', 'feature' => 'location', 'expectedReplyTypes' => ['AP16', 'AP01']],
            ['id' => 'BP87', 'command' => 'BP87', 'label' => 'Temperature variant', 'icon' => 'fa-temperature-half', 'kind' => 'request', 'feature' => 'temperature', 'expectedReplyTypes' => ['AP87']],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fourPTouchCommands(): array
    {
        return [
            ['id' => 'CR', 'command' => 'CR', 'label' => 'Location', 'icon' => 'fa-location-dot', 'kind' => 'request', 'feature' => 'location', 'expectedReplyTypes' => ['CR', ...FourPTouchAdapter::LOCATION_FRAME_TYPES, ...FourPTouchAdapter::ALARM_FRAME_TYPES]],
            ['id' => 'fourPHeartRate', 'command' => 'hrtstart', 'label' => 'Heart rate', 'icon' => 'fa-heart-pulse', 'kind' => 'request', 'feature' => 'heart_rate', 'expectedReplyTypes' => ['hrtstart', 'bphrt'], 'data' => ['1']],
            ['id' => 'fourPBloodPressure', 'command' => 'hrtstart', 'label' => 'Blood pressure', 'icon' => 'fa-stethoscope', 'kind' => 'request', 'feature' => 'blood_pressure', 'expectedReplyTypes' => ['hrtstart', 'bphrt'], 'data' => ['1']],
            ['id' => 'fourPBodyTemperature', 'command' => 'bodytemp2', 'label' => 'Temperature', 'icon' => 'fa-temperature-half', 'kind' => 'request', 'feature' => 'temperature', 'expectedReplyTypes' => ['bodytemp2', 'btemp2']],
            ['id' => 'fourPFirmwareVersion', 'command' => 'VERNO', 'label' => 'Firmware version', 'icon' => 'fa-microchip', 'kind' => 'request', 'feature' => 'firmware_version', 'expectedReplyTypes' => ['VERNO']],
            ['id' => 'fourPDeviceStatus', 'command' => 'TS', 'label' => 'Device status', 'icon' => 'fa-clock', 'kind' => 'request', 'feature' => 'device_status', 'expectedReplyTypes' => ['TS']],
        ];
    }

    private static function buildWonlex(string $imei, string $command, array $payload = [], array $context = []): string
    {
        $timestamp = (int)round(microtime(true) * 1000);
        $data = array_replace([
            'type' => $command,
            'imei' => $imei,
            'timestamp' => $timestamp,
        ], $payload);
        if (self::isWonlexRequestedWaveform($command)) {
            $data += self::wonlexWaveformDefaults($command);
        }
        if ($command === 'dnUpSleep' && (!isset($data['upDayStr']) || !isset($data['value']))) {
            throw new \InvalidArgumentException('dnUpSleep requires upDayStr and value');
        }
        $ident = $context['ident'] ?? random_int(100000, 999999);
        if (!is_int($ident) && !(is_string($ident) && ctype_digit($ident))) {
            throw new \InvalidArgumentException('Wonlex ident must be numeric');
        }

        return (new WonlexAdapter())->encodeOutgoing([
            'type' => $command,
            'ident' => (int)$ident,
            'ref' => 's:down',
            'imei' => $imei,
            'data' => $data,
            'timestamp' => $timestamp,
        ]);
    }

    private static function isWonlexRequestedWaveform(string $command): bool
    {
        return in_array($command, ['dnECG', 'dnHRV', 'dnPPG'], true);
    }

    /**
     * Estes valores são opcionais no contrato genérico da Wonlex, mas alguns firmwares não
     * começam a recolher a forma de onda sem eles explícitos.
     *
     * @return array{frequency:string,oneTime:int,collectionLogo:string}
     */
    private static function wonlexWaveformDefaults(string $command): array
    {
        return [
            'frequency' => match ($command) {
                'dnECG' => '500',
                'dnHRV' => '100',
                default => '200',
            },
            'oneTime' => 30,
            'collectionLogo' => (string)random_int(10000000, 99999999),
        ];
    }

    /**
     * A descida do dispensador M228.
     *
     * A configuração vai num pacote `0x06` e o controlo num `0x08`, ambos com o corpo em
     * TFLV. O que distingue os dois não é o conteúdo mas o tipo de pacote: escrever uma TAG
     * de controlo num pacote de configuração não faz nada.
     */
    private static function buildPillDispenser(string $imei, string $command, array $payload = []): string
    {
        // A calibração leva a hora a que o aparelho se deve pôr, e não um interruptor: é a
        // única TAG de controlo que é STRING.
        if ($command === 'calibrateClock') {
            return self::pillFrame($imei, 0x08, [
                0xA101 => ['value' => gmdate('Y-m-d\TH:i:s')],
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
        // TAG no estado do Flag -- é assim que se sabe o que ele tem, em vez de se assumir.
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
            'alarmRingtone' => [0x1012 => ['value' => self::pillByte($payload['ringtone'] ?? 0, 4)]],
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
            // O aparelho sai de fábrica a cifrar o que envia, e a especificação não dá a
            // chave: sem isto o hub recebe heartbeats que não consegue ler.
            'disableEncryption' => [0x8005 => ['value' => "\x00"]],
            // INT16S em HHMM: `+100` é uma hora à frente, e a oeste o sinal é negativo.
            'timeZone' => [0x1015 => ['value' => pack('s', (int)($payload['timeZone'] ?? 0))]],
            default => throw new \InvalidArgumentException("Unsupported zayata-m228 command {$command}"),
        };

        return self::pillFrame($imei, 0x06, $tlv);
    }

    /**
     * Os nove alarmes, sempre os nove. O aparelho não os cria nem apaga, e um slot que o
     * plano não use tem de ser desligado explicitamente: senão ficava a tocar o que lá
     * estivesse de um plano anterior.
     *
     * @return array<int, array{value: string}>
     */
    private static function pillMedicationPlan(array $payload): array
    {
        $plans = array_values(array_filter($payload['plans'] ?? [], 'is_array'));
        if (count($plans) > 9) {
            throw new \InvalidArgumentException('o M228 tem nove alarmes, e o plano traz ' . count($plans));
        }

        $tlv = [];
        for ($slot = 0; $slot < 9; $slot++) {
            $plan = $plans[$slot] ?? null;
            $tlv[0x1021 + $slot] = ['value' => self::pillByte($plan['hour'] ?? 0, 23)];
            $tlv[0x1031 + $slot] = ['value' => self::pillByte($plan['minute'] ?? 0, 59)];
            $tlv[0x1041 + $slot] = ['value' => self::pillBool($plan !== null && ($plan['enabled'] ?? true))];
        }

        return $tlv;
    }

    /**
     * O período em que o plano vale. O aparelho só sabe "todos os dias entre duas datas":
     * não tem dias da semana nem repetição. Sem período, é o interruptor que fica a zero —
     * e não datas a zero, que o aparelho leria como um intervalo real.
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
    /**
     * O número de série de cada trama de descida, que o aparelho ecoa na resposta.
     *
     * É por ele que se sabe a qual dos pedidos pendentes uma resposta pertence. Todas a zero,
     * duas escritas ao mesmo tempo ficavam indistinguíveis e a primeira resposta fechava a
     * errada. Começa em 1 porque o zero é o que a trama tem quando ninguém lhe mexeu.
     */
    private static int $pillSerial = 0;

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

    private static function buildVivistar(string $imei, string $command, array $entry, array $payload = []): string
    {
        return (new VivistarAdapter())->encodeOutgoing([
            'type' => $command,
            'imei' => $imei,
            'ident' => (string)random_int(100000, 999999),
            'data' => ['fields' => $payload['fields'] ?? ($entry['data'] ?? [])],
        ]);
    }

    private static function buildFourPTouch(string $imei, string $command, array $entry, array $payload = [], array $context = []): string
    {
        $deviceId = trim((string)($context['deviceId'] ?? ''));
        if ($deviceId === '') {
            $deviceId = trim((string)($payload['deviceId'] ?? ''));
        }
        if ($deviceId === '') {
            $deviceId = self::deriveFourPTouchDeviceId($imei);
        }

        return (new FourPTouchAdapter())->encodeOutgoing([
            'type' => $command,
            'imei' => $deviceId,
            'manufacturer' => (string)($payload['manufacturer'] ?? '3G'),
            'data' => ['fields' => $payload['fields'] ?? ($entry['data'] ?? [])],
        ]);
    }

    public static function deriveFourPTouchDeviceId(string $imei): string
    {
        $digits = preg_replace('/\D+/', '', $imei) ?? '';
        if (strlen($digits) === 15) {
            return substr($digits, 4, 10);
        }

        if (strlen($digits) === 10) {
            return $digits;
        }

        if (strlen($digits) > 10) {
            return substr($digits, -10);
        }

        return $digits;
    }
}
