<?php

namespace Hub\Command;

use Hub\Command\Downlink\FourPTouchDownlink;
use Hub\Command\Downlink\VivistarDownlink;
use Hub\Command\Downlink\WonlexDownlink;
use Hub\Command\Downlink\ZayataDownlink;
use Hub\Protocol\Adapter\FourPTouchAdapter;
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
            'zayata-m228' => self::pillDispenserCommands(),
            default => [],
        };
    }

    /**
     * Só o que o dispensador serve como *pedido*: o `0x07` e o `0x05`. O que muda o aparelho
     * fica no modal, porque um mosaico dispara ao primeiro clique e sem confirmação.
     *
     * @return list<array<string, mixed>>
     */
    private static function pillDispenserCommands(): array
    {
        return [
            // Um pedido só: o `0x07` pede sempre as `STATUS_TAGS` todas, e a resposta enche
            // as sete leituras de uma vez.
            ['id' => 'pillReadStatus', 'command' => 'readStatus', 'label' => 'Refresh telemetry', 'icon' => 'fa-arrows-rotate', 'kind' => 'refresh', 'feature' => 'telemetry_refresh', 'expectedReplyTypes' => ['read_status_ack']],
            ['id' => 'pillReadConfiguration', 'command' => 'readConfiguration', 'label' => 'Stored configuration', 'icon' => 'fa-rotate', 'kind' => 'request', 'feature' => 'sync_configuration', 'expectedReplyTypes' => ['read_config_ack']],
        ];
    }

    /**
     * O comando com que um protocolo relê o estado do aparelho, se souber.
     *
     * Não tem capacidade por trás: actualizar a telemetria é uma função do ecrã, e o que a
     * resposta traz sai nas capacidades que já existem. Por isso não é `request` — um
     * `request` é um mosaico, e um mosaico precisa de uma capacidade que o sustente.
     *
     * @return array<string, mixed>|null
     */
    public static function refreshCommandForProtocol(string $protocol): ?array
    {
        foreach (self::commandsForProtocol($protocol) as $entry) {
            if ((string)($entry['kind'] ?? '') === 'refresh') {
                return $entry;
            }
        }

        return null;
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
    /**
     * O `refresh` entra aqui com o `request`: a diferença entre os dois é onde o botão vive,
     * e não como o comando se envia. Fora daqui, um `request` é um mosaico com capacidade por
     * trás e um `refresh` não é.
     */
    public static function commandsForFeature(string $protocol, string $feature): array
    {
        return array_values(array_filter(
            self::commandsForProtocol($protocol),
            static fn(array $entry): bool => in_array((string)($entry['kind'] ?? ''), ['request', 'refresh'], true)
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
            'wonlex-json' => WonlexDownlink::build($imei, $command, $payload, $context),
            'vivistar-iw' => VivistarDownlink::build($imei, $command, $entry, $payload),
            'four-p-touch' => FourPTouchDownlink::build($imei, $command, $entry, $payload, $context),
            // Não há trama a montar: o destinatário é o gateway, e o que ele precisa é do
            // nome da operação para chamar o SDK. Os bytes em fila são esse nome.
            'veepoo-ble' => $command,
            'zayata-m228' => ZayataDownlink::build($imei, $command, $payload, $context),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol}"),
        };
    }

    public static function deriveFourPTouchDeviceId(string $imei): string
    {
        return FourPTouchDownlink::deriveDeviceId($imei);
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
        if (WonlexDownlink::isRequestedWaveform($nativeType)) {
            $data += WonlexDownlink::waveformDefaults($nativeType);
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
     * O comando não viaja para o aparelho: vai para o gateway que tem a sessão BLE e chama o
     * SDK, e por isso o `command` é o nome da operação na ponte e não uma trama.
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
            // É uma leitura e não uma medição: a pulseira já tem o sono calculado.
            ['id' => 'readSleep', 'command' => 'read.sleep', 'label' => 'Sleep', 'icon' => 'fa-bed', 'kind' => 'request', 'feature' => 'sleep', 'expectedReplyTypes' => ['sleep', 'sleep_quality']],
            // O acumulado do dia responde no instante, como a bateria: é um contador que a
            // pulseira já tem, e não uma medição a fazer.
            ['id' => 'readDailyTotals', 'command' => 'read.dailyTotals', 'label' => 'Daily totals', 'icon' => 'fa-shoe-prints', 'kind' => 'request', 'feature' => 'activity', 'expectedReplyTypes' => ['activity']],
            // O ECG exige que quem a usa encoste o dedo ao elétrodo: sem isso o aparelho
            // envia dezenas de tramas com `wearNotPass` e tudo a zero.
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
            ['id' => 'fourPDeviceStatus', 'command' => 'TS', 'label' => 'Refresh telemetry', 'icon' => 'fa-arrows-rotate', 'kind' => 'refresh', 'feature' => 'telemetry_refresh', 'expectedReplyTypes' => ['TS']],
        ];
    }
}
