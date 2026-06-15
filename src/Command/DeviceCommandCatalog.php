<?php

namespace Hub\Command;

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

    public static function buildDownlink(string $protocol, string $imei, string $command): string
    {
        $entry = self::commandForProtocol($protocol, $command);
        if ($entry === null) {
            throw new \InvalidArgumentException("Unsupported {$protocol} command {$command}");
        }

        return match ($protocol) {
            'wonlex-json' => self::buildWonlex($imei, $command),
            'vivistar-iw' => self::buildVivistar($imei, $command, $entry),
            default => throw new \InvalidArgumentException("Unsupported protocol {$protocol}"),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function wonlexCommands(): array
    {
        return [
            ['command' => 'dnHeartRate', 'label' => 'Heart rate', 'icon' => 'fa-heart-pulse', 'kind' => 'request', 'expectedReplyTypes' => ['upHeartRate', 'upBatch']],
            ['command' => 'dnBP', 'label' => 'Blood pressure', 'icon' => 'fa-stethoscope', 'kind' => 'request', 'expectedReplyTypes' => ['upBP', 'upBatch']],
            ['command' => 'dnBO', 'label' => 'Blood oxygen', 'icon' => 'fa-droplet', 'kind' => 'request', 'expectedReplyTypes' => ['upBO', 'upBatch']],
            ['command' => 'dnTemperature', 'label' => 'Temperature', 'icon' => 'fa-temperature-half', 'kind' => 'request', 'expectedReplyTypes' => ['upBodyTemperature']],
            ['command' => 'dnBreathe', 'label' => 'Breath rate', 'icon' => 'fa-lungs', 'kind' => 'request', 'expectedReplyTypes' => ['upBreathe']],
            ['command' => 'dnLocation', 'label' => 'Location', 'icon' => 'fa-location-dot', 'kind' => 'request', 'expectedReplyTypes' => ['upLocation']],
            ['command' => 'dnUpSleep', 'label' => 'Sleep data', 'icon' => 'fa-bed', 'kind' => 'request', 'expectedReplyTypes' => ['upSleep']],
            ['command' => 'dnECG', 'label' => 'ECG', 'icon' => 'fa-wave-square', 'kind' => 'request', 'expectedReplyTypes' => ['upECG']],
            ['command' => 'dnHRV', 'label' => 'HRV', 'icon' => 'fa-chart-line', 'kind' => 'request', 'expectedReplyTypes' => ['upHRV']],
            ['command' => 'dnPPG', 'label' => 'PPG', 'icon' => 'fa-circle-nodes', 'kind' => 'request', 'expectedReplyTypes' => ['upPPG']],
            ['command' => 'dnRR', 'label' => 'RR interval', 'icon' => 'fa-stopwatch', 'kind' => 'request', 'expectedReplyTypes' => ['upRR']],
            ['command' => 'dnWeather', 'label' => 'Weather', 'icon' => 'fa-cloud-sun', 'kind' => 'data', 'expectedReplyTypes' => ['upWeather']],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function vivistarCommands(): array
    {
        return [
            ['command' => 'BPXL', 'label' => 'Heart rate', 'icon' => 'fa-heart-pulse', 'kind' => 'request', 'expectedReplyTypes' => ['APXL']],
            ['command' => 'BPXY', 'label' => 'Blood pressure', 'icon' => 'fa-stethoscope', 'kind' => 'request', 'expectedReplyTypes' => ['APXY']],
            ['command' => 'BPXZ', 'label' => 'Blood oxygen', 'icon' => 'fa-droplet', 'kind' => 'request', 'expectedReplyTypes' => ['APXZ']],
            ['command' => 'BPXT', 'label' => 'Temperature', 'icon' => 'fa-temperature-half', 'kind' => 'request', 'expectedReplyTypes' => ['APXT']],
            ['command' => 'BP16', 'label' => 'Location', 'icon' => 'fa-location-dot', 'kind' => 'request', 'expectedReplyTypes' => ['AP16', 'AP01']],
            ['command' => 'BP87', 'label' => 'Temperature variant', 'icon' => 'fa-temperature-half', 'kind' => 'request', 'expectedReplyTypes' => ['AP87']],
        ];
    }

    private static function buildWonlex(string $imei, string $command): string
    {
        $timestamp = (int)round(microtime(true) * 1000);
        $data = [
            'type' => $command,
            'imei' => $imei,
            'timestamp' => $timestamp,
        ];
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

    private static function buildVivistar(string $imei, string $command, array $entry): string
    {
        return (new VivistarAdapter())->encodeOutgoing([
            'type' => $command,
            'imei' => $imei,
            'ident' => (string)random_int(100000, 999999),
            'data' => ['fields' => $entry['data'] ?? []],
        ]);
    }
}
