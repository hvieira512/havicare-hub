<?php

namespace Hub\Command;

use Hub\Protocol\Adapter\FourPTouchAdapter;
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

    public static function requestForProtocol(string $protocol, string $requestId): ?array
    {
        foreach (self::commandsForProtocol($protocol) as $entry) {
            if (($entry['id'] ?? $entry['command'] ?? '') === $requestId || ($entry['command'] ?? '') === $requestId) {
                return $entry;
            }
        }

        return null;
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
            'wonlex-json' => self::buildWonlex($imei, $command, $payload),
            'vivistar-iw' => self::buildVivistar($imei, $command, $entry, $payload),
            'four-p-touch' => self::buildFourPTouch($imei, $command, $entry, $payload, $context),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol}"),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function wonlexCommands(): array
    {
        return [
            ['id' => 'dnHeartRate', 'command' => 'dnHeartRate', 'label' => 'Heart rate', 'icon' => 'fa-heart-pulse', 'kind' => 'request', 'feature' => 'heart_rate', 'expectedReplyTypes' => ['upHeartRate', 'upBatch']],
            ['id' => 'dnBP', 'command' => 'dnBP', 'label' => 'Blood pressure', 'icon' => 'fa-stethoscope', 'kind' => 'request', 'feature' => 'blood_pressure', 'expectedReplyTypes' => ['upBP', 'upBatch']],
            ['id' => 'dnBO', 'command' => 'dnBO', 'label' => 'Blood oxygen', 'icon' => 'fa-droplet', 'kind' => 'request', 'feature' => 'blood_oxygen', 'expectedReplyTypes' => ['upBO', 'upBatch']],
            ['id' => 'dnTemperature', 'command' => 'dnTemperature', 'label' => 'Temperature', 'icon' => 'fa-temperature-half', 'kind' => 'request', 'feature' => 'temperature', 'expectedReplyTypes' => ['upBodyTemperature']],
            ['id' => 'dnBreathe', 'command' => 'dnBreathe', 'label' => 'Breath rate', 'icon' => 'fa-lungs', 'kind' => 'request', 'expectedReplyTypes' => ['upBreathe']],
            ['id' => 'dnLocation', 'command' => 'dnLocation', 'label' => 'Location', 'icon' => 'fa-location-dot', 'kind' => 'request', 'feature' => 'location', 'expectedReplyTypes' => ['upLocation']],
            ['id' => 'dnUpSleep', 'command' => 'dnUpSleep', 'label' => 'Sleep data', 'icon' => 'fa-bed', 'kind' => 'request', 'feature' => 'sleep', 'expectedReplyTypes' => ['upSleep']],
            ['id' => 'dnECG', 'command' => 'dnECG', 'label' => 'ECG', 'icon' => 'fa-wave-square', 'kind' => 'request', 'feature' => 'ecg', 'expectedReplyTypes' => ['upECG']],
            ['id' => 'dnHRV', 'command' => 'dnHRV', 'label' => 'HRV', 'icon' => 'fa-chart-line', 'kind' => 'request', 'feature' => 'hrv', 'expectedReplyTypes' => ['upHRV']],
            ['id' => 'dnPPG', 'command' => 'dnPPG', 'label' => 'PPG', 'icon' => 'fa-circle-nodes', 'kind' => 'request', 'expectedReplyTypes' => ['upPPG']],
            ['id' => 'dnRR', 'command' => 'dnRR', 'label' => 'RR interval', 'icon' => 'fa-stopwatch', 'kind' => 'request', 'expectedReplyTypes' => ['upRR']],
            ['id' => 'dnWeather', 'command' => 'dnWeather', 'label' => 'Weather', 'icon' => 'fa-cloud-sun', 'kind' => 'data', 'feature' => 'weather', 'expectedReplyTypes' => ['upWeather']],
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
            ['id' => 'CR', 'command' => 'CR', 'label' => 'Location', 'icon' => 'fa-location-dot', 'kind' => 'request', 'feature' => 'location', 'expectedReplyTypes' => ['CR', 'UD', 'UD2', 'UD_WCDMA', 'UD_LTE', 'AL', 'AL_WCDMA', 'AL_LTE']],
            ['id' => 'fourPHeartRate', 'command' => 'hrtstart', 'label' => 'Heart rate', 'icon' => 'fa-heart-pulse', 'kind' => 'request', 'feature' => 'heart_rate', 'expectedReplyTypes' => ['hrtstart', 'bphrt'], 'data' => ['1']],
            ['id' => 'fourPSystolicPressure', 'command' => 'hrtstart', 'label' => 'Systolic blood pressure', 'icon' => 'fa-heart-circle-bolt', 'kind' => 'request', 'feature' => 'blood_pressure_systolic', 'expectedReplyTypes' => ['hrtstart', 'bphrt'], 'data' => ['1']],
            ['id' => 'fourPDiastolicPressure', 'command' => 'hrtstart', 'label' => 'Diastolic blood pressure', 'icon' => 'fa-stethoscope', 'kind' => 'request', 'feature' => 'blood_pressure_diastolic', 'expectedReplyTypes' => ['hrtstart', 'bphrt'], 'data' => ['1']],
            ['id' => 'fourPBodyTemperature', 'command' => 'bodytemp2', 'label' => 'Temperature', 'icon' => 'fa-temperature-half', 'kind' => 'request', 'feature' => 'temperature', 'expectedReplyTypes' => ['bodytemp2', 'btemp2']],
        ];
    }

    private static function buildWonlex(string $imei, string $command, array $payload = []): string
    {
        $timestamp = (int)round(microtime(true) * 1000);
        $data = array_replace([
            'type' => $command,
            'imei' => $imei,
            'timestamp' => $timestamp,
        ], $payload);
        if (in_array($command, ['dnECG', 'dnHRV', 'dnPPG', 'dnRR'], true)) {
            $data += ['frequency' => '200', 'oneTime' => 300, 'collectionLogo' => (string)random_int(10000000, 99999999)];
        }
        if ($command === 'dnWeather') {
            $data += ['weather' => 'Cloudy', 'weatherType' => 1, 'reporttime' => gmdate('Y-m-d H:i:s')];
        }

        return (new WonlexAdapter())->encodeOutgoing([
            'type' => $command,
            'ident' => random_int(100000, 999999),
            'ref' => 's:down',
            'imei' => $imei,
            'data' => $data,
            'timestamp' => $timestamp,
        ]);
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
