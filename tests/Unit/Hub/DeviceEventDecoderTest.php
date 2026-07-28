<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\DeviceEventDecoder;
use Hub\DeviceSession;
use Hub\ConnectionInterface;
use PHPUnit\Framework\TestCase;

final class DeviceEventDecoderTest extends TestCase
{
    public function testDecodesWonlexBatchIntoMultipleEvents(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('wonlex-json'),
            [
                'type' => 'upBatch',
                'data' => [
                    'heartRate' => '100,98,97',
                    'bp' => '120/80/72',
                    'bo' => '98',
                ],
            ]
        );

        self::assertSame(['heart_rate', 'blood_pressure', 'heart_rate', 'blood_oxygen'], array_column($events, 'feature'));
        self::assertSame(100, $events[0]['value']['bpm']);
        self::assertSame(120, $events[1]['value']['systolicMmHg']);
        self::assertSame(72, $events[2]['value']['bpm']);
        self::assertSame(98, $events[3]['value']['spo2Percent']);
    }

    public function testDecodesDocumentedWonlexBatchShapeAndMeasurementTimes(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('wonlex-json'),
            [
                'type' => 'upBatch',
                'data' => [
                    'dataType' => 'upHeartRate',
                    'data' => '100,98,97',
                    'dataTime' => '1648111390075,1648111390073,1648111390074',
                ],
            ]
        );

        self::assertSame([100, 98, 97], array_column(array_column($events, 'value'), 'bpm'));
        self::assertSame(1648111390075, $events[0]['extra']['measuredAt']);
    }

    public function testDecodesWonlexBreathingTemperatureSleepCallsSmsAndDeviceState(): void
    {
        $decoder = new DeviceEventDecoder();
        $session = $this->session('wonlex-json');

        $breathing = $decoder->decode($session, ['type' => 'upBreathe', 'data' => ['date' => '20']]);
        $temperature = $decoder->decode($session, ['type' => 'upBodyTemperature', 'data' => ['date' => '36.8/31.6/28.2']]);
        $sleep = $decoder->decode($session, ['type' => 'upSleep', 'data' => [
            'startTime' => 1653228000000,
            'endTime' => 1653271200000,
            'dateTime' => [
                ['startTime' => 1653228000000, 'endTime' => 1653229800000, 'duration' => 30, 'sleepType' => 'deepSleep'],
                ['startTime' => 1653229800000, 'endTime' => 1653233400000, 'duration' => 60, 'sleeptype' => 'lightSleep'],
            ],
        ]]);
        $call = $decoder->decode($session, ['type' => 'upCallLog', 'data' => [
            'phone' => '+351210000000', 'duration' => 60, 'callType' => 1, 'isSwitchOn' => 1,
        ]]);
        $sms = $decoder->decode($session, ['type' => 'upSMS', 'data' => [
            'sender' => 'operator', 'msgContent' => 'Balance: 10 EUR',
        ]]);
        $analysis = $decoder->decode($session, ['type' => 'upECGAnalysis', 'data' => [
            'devType' => 'ECG', 'mealstatus' => '-1', 'medicationstatus' => '1', 'fileBase64' => 'YWJj',
        ]]);
        $shutdown = $decoder->decode($session, ['type' => 'upShutdown', 'data' => []]);

        self::assertSame(20, $breathing[0]['value']['breathsPerMinute']);
        self::assertSame(31.6, $temperature[0]['value']['surfaceCelsius']);
        self::assertSame(28.2, $temperature[0]['value']['environmentCelsius']);
        self::assertSame('deepSleep', $sleep[0]['value']['segments'][0]['type']);
        self::assertSame('outgoing', $call[0]['value']['direction']);
        self::assertTrue($call[0]['value']['connected']);
        self::assertSame('Balance: 10 EUR', $sms[0]['value']['content']);
        self::assertSame('ecg_analysis', $analysis[0]['feature']);
        self::assertSame(-1, $analysis[0]['value']['mealStatus']);
        self::assertSame('YWJj', $analysis[0]['value']['fileBase64']);
        self::assertSame('shutdown', $shutdown[0]['value']['state']);
    }

    public function testDecodesWonlexLocationWithBaseStationsAndWifi(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('wonlex-json'),
            [
                'type' => 'upLocation',
                'data' => [
                    'gps' => [
                        'GSM' => 90,
                        'lat' => '38.7150',
                        'lon' => '-9.1450',
                        'Type' => 0,
                        'height' => 45,
                        'satelliteNum' => 8,
                    ],
                    'wifi' => [
                        ['mac' => 'AA:BB:CC:DD:EE:FF', 'ssid' => 'HOME', 'signal' => '-58'],
                    ],
                    'baseStation' => [
                        ['ci' => 5679, 'lac' => 1234, 'mcc' => 268, 'mnc' => 1, 'rxlev' => 49],
                    ],
                ],
            ]
        );

        self::assertCount(1, $events);
        self::assertSame('location', $events[0]['feature']);
        self::assertSame('gps', $events[0]['value']['source']);
        self::assertSame(38.715, $events[0]['value']['lat']);
        self::assertSame(-9.145, $events[0]['value']['lon']);
        self::assertSame(8, $events[0]['value']['satelliteCount']);
        self::assertSame(268, (int)$events[0]['value']['mcc']);
        self::assertSame(1, (int)$events[0]['value']['mnc']);
        self::assertSame(1234, (int)$events[0]['value']['lac']);
        self::assertSame(5679, (int)$events[0]['value']['cellId']);
        self::assertTrue($events[0]['value']['hasCoordinates']);
        self::assertCount(1, $events[0]['value']['baseStations']);
        self::assertCount(1, $events[0]['value']['wifiAccessPoints']);
    }

    public function testWonlexGpsCoordinateSystemDoesNotOverrideGpsSourceInference(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('wonlex-json'),
            [
                'type' => 'upLocation',
                'data' => [
                    'gps' => [
                        'GSM' => 66,
                        'lat' => '38.782034',
                        'lon' => '-9.17531',
                        'Type' => 3,
                        'height' => 11,
                        'satelliteNum' => 8,
                    ],
                    'wifi' => [
                        ['mac' => 'AA:BB:CC:DD:EE:FF', 'ssid' => 'HOME', 'signal' => '-58'],
                    ],
                    'baseStation' => [
                        ['ci' => 5679, 'lac' => 1234, 'mcc' => 268, 'mnc' => 1, 'rxlev' => 49],
                    ],
                ],
            ]
        );

        self::assertCount(1, $events);
        self::assertSame('gps', $events[0]['value']['source']);
        self::assertArrayNotHasKey('gpsValid', $events[0]['value']);
    }

    public function testDecodesWonlexPdfUppercaseWifiLocationField(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('wonlex-json'),
            [
                'type' => 'upLocation',
                'data' => [
                    'Wifi' => [
                        ['mac' => 'AA:BB:CC:DD:EE:FF', 'ssid' => 'CLINIC', 'signal' => '-61'],
                    ],
                ],
            ]
        );

        self::assertCount(1, $events);
        self::assertSame('wifi', $events[0]['value']['source']);
        self::assertSame('CLINIC', $events[0]['value']['wifiAccessPoints'][0]['ssid']);
    }

    public function testDecodesWonlexActivityAndDeviceConfigPackets(): void
    {
        $activity = (new DeviceEventDecoder())->decode(
            $this->session('wonlex-json'),
            [
                'type' => 'upTodayActivity',
                'data' => [
                    'step' => 0,
                    'standTime' => 6,
                    'exerciseTime' => 1800,
                ],
            ]
        );

        $config = (new DeviceEventDecoder())->decode(
            $this->session('wonlex-json'),
            [
                'type' => 'upGetDevConfig',
                'data' => [
                    'deviceModel' => 'HW20PRO',
                ],
            ]
        );

        self::assertSame(['activity'], array_column($activity, 'feature'));
        self::assertSame(0, $activity[0]['value']['steps']);
        self::assertSame(6, $activity[0]['value']['standMinutes']);
        self::assertSame(1800, $activity[0]['value']['exerciseSeconds']);
        self::assertSame([], $config);
    }

    public function testDecodesWonlexBloodPressureWithStringData(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('wonlex-json'),
            [
                'type' => 'upBP',
                'data' => [
                    'type' => 'upBP',
                    'data' => '164/89',
                    'imei' => '868705080300697',
                    'deviceModel' => 'HW20PRO',
                    'testType' => 57199,
                ],
            ]
        );

        self::assertCount(1, $events);
        self::assertSame('blood_pressure', $events[0]['feature']);
        self::assertSame(164, $events[0]['value']['systolicMmHg']);
        self::assertSame(89, $events[0]['value']['diastolicMmHg']);
    }

    public function testDecodesWonlexHeartRateDataFieldAndHeartbeatBattery(): void
    {
        $heartRate = (new DeviceEventDecoder())->decode(
            $this->session('wonlex-json'),
            [
                'type' => 'upHeartRate',
                'data' => [
                    'data' => '83',
                    'testType' => 0,
                ],
            ]
        );

        $heartbeat = (new DeviceEventDecoder())->decode(
            $this->session('wonlex-json'),
            [
                'type' => 'heartbeat',
                'data' => [
                    'batteryLevel' => 90,
                    'batteryState' => 0,
                ],
            ]
        );

        self::assertSame(['heart_rate'], array_column($heartRate, 'feature'));
        self::assertSame(83, $heartRate[0]['value']['bpm']);
        self::assertSame(['heartbeat', 'battery'], array_column($heartbeat, 'feature'));
        self::assertSame('ok', $heartbeat[0]['value']['status']);
        self::assertSame(90, $heartbeat[0]['value']['batteryPercent']);
        self::assertSame(0, $heartbeat[0]['value']['chargingState']);
        self::assertSame(90, $heartbeat[1]['value']['percent']);
        self::assertSame(0, $heartbeat[1]['value']['chargingState']);
    }

    public function testDecodesWonlexDocumentDateFields(): void
    {
        $decoder = new DeviceEventDecoder();
        $session = $this->session('wonlex-json');

        $heartRate = $decoder->decode($session, ['type' => 'upHeartRate', 'data' => ['date' => '75']]);
        $bloodPressure = $decoder->decode($session, ['type' => 'upBP', 'data' => ['date' => '118/78/75']]);
        $bloodOxygen = $decoder->decode($session, ['type' => 'upBO', 'data' => ['date' => '97']]);
        $temperature = $decoder->decode($session, ['type' => 'upBodyTemperature', 'data' => ['date' => '36.6/31.0/27.8']]);

        self::assertSame(75, $heartRate[0]['value']['bpm']);
        self::assertSame(118, $bloodPressure[0]['value']['systolicMmHg']);
        self::assertSame(78, $bloodPressure[0]['value']['diastolicMmHg']);
        self::assertSame('heart_rate', $bloodPressure[1]['feature']);
        self::assertSame(75, $bloodPressure[1]['value']['bpm']);
        self::assertSame(97, $bloodOxygen[0]['value']['spo2Percent']);
        self::assertSame(36.6, $temperature[0]['value']['bodyCelsius']);
    }

    public function testDecodesVivistarApHpIntoMultipleEvents(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('vivistar-iw'),
            [
                'type' => 'APHP',
                'data' => [
                    'heartRate' => 72,
                    'systolic' => 120,
                    'diastolic' => 80,
                    'spo2' => 98,
                    'bloodSugar' => 91,
                ],
            ]
        );

        self::assertSame(['heart_rate', 'blood_pressure', 'blood_oxygen', 'blood_sugar'], array_column($events, 'feature'));
        self::assertSame(72, $events[0]['value']['bpm']);
        self::assertSame(80, $events[1]['value']['diastolicMmHg']);
        self::assertSame(98, $events[2]['value']['spo2Percent']);
        self::assertSame(91, $events[3]['value']['glucoseMgDl']);
    }

    public function testDecodesVivistarAp50IntoTemperatureAndBatteryEvents(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('vivistar-iw'),
            [
                'type' => 'AP50',
                'data' => [
                    'temperature' => 36.7,
                    'battery' => 90,
                ],
            ]
        );

        self::assertSame(['temperature', 'battery'], array_column($events, 'feature'));
        self::assertSame(36.7, $events[0]['value']['bodyCelsius']);
        self::assertSame(90, $events[1]['value']['percent']);
    }

    public function testDecodesVivistarConfigAndControlAckTypesAsDeviceConfig(): void
    {
        $ackTypes = ['AP12', 'AP14', 'AP28', 'AP33', 'AP40', 'AP76', 'AP77', 'AP84', 'AP85', 'AP86', 'APJZ'];

        foreach ($ackTypes as $type) {
            $events = (new DeviceEventDecoder())->decode(
                $this->session('vivistar-iw'),
                ['type' => $type, 'data' => ['raw' => '080835', 'fields' => ['080835']]]
            );

            self::assertCount(1, $events, "{$type} should produce exactly one event");
            self::assertSame('device_config', $events[0]['feature'], "{$type} should map to device_config");
            self::assertSame($type, $events[0]['nativeType'], "{$type} nativeType mismatch");
            self::assertSame('ok', $events[0]['value']['status'], "{$type} should have status ok");
        }
    }

    public function testDecodesFourPTouchConfigWithAckAndSettings(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('four-p-touch'),
            [
                'type' => 'CONFIG',
                'data' => [
                    'configAck' => '1',
                    'configs' => [
                        'UPLOAD' => '600',
                        'LANG' => 'pt',
                    ],
                ],
            ]
        );

        self::assertCount(1, $events);
        self::assertSame('device_config', $events[0]['feature']);
        self::assertSame('ok', $events[0]['value']['status']);
        self::assertSame('1', $events[0]['value']['ack']);
        self::assertSame(['UPLOAD' => '600', 'LANG' => 'pt'], $events[0]['value']['settings']);
    }

    public function testDecodesFourPTouchTakePillsReplyAsDeviceConfig(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('four-p-touch'),
            [
                'type' => 'TAKEPILLS',
                'data' => [
                    'configAck' => '1',
                    'fields' => ['1'],
                ],
            ]
        );

        self::assertCount(1, $events);
        self::assertSame('device_config', $events[0]['feature']);
        self::assertSame('TAKEPILLS', $events[0]['nativeType']);
        self::assertSame('ok', $events[0]['value']['status']);
        self::assertSame('1', $events[0]['value']['ack']);
    }

    public function testDecodesWonlexWeatherIntoStructuredPayload(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('wonlex-json'),
            [
                'type' => 'upWeather',
                'data' => [
                    'weather' => 'Cloudy',
                    'weatherType' => 1,
                    'reporttime' => '2026-06-17 14:15:00',
                    'temp' => 22.5,
                    'lowTemp' => 18.0,
                    'highTemp' => 25.0,
                    'humidity' => 61,
                ],
            ]
        );

        self::assertCount(1, $events);
        self::assertSame('weather', $events[0]['feature']);
        self::assertSame('ok', $events[0]['value']['status']);
        self::assertSame('Cloudy', $events[0]['value']['summary']);
        self::assertSame(1, $events[0]['value']['weatherType']);
        self::assertSame('2026-06-17 14:15:00', $events[0]['value']['reportedAt']);
        self::assertSame(22.5, $events[0]['value']['temperatureCelsius']);
        self::assertSame(18.0, $events[0]['value']['lowCelsius']);
        self::assertSame(25.0, $events[0]['value']['highCelsius']);
        self::assertSame(61, $events[0]['value']['humidityPercent']);
    }

    public function testIgnoresVivistarRequestAckTypesAsTelemetry(): void
    {
        $ackTypes = ['AP16', 'AP87', 'APXL', 'APXY', 'APXT', 'APXZ'];

        foreach ($ackTypes as $type) {
            $events = (new DeviceEventDecoder())->decode(
                $this->session('vivistar-iw'),
                ['type' => $type, 'data' => ['raw' => '080835', 'fields' => ['080835']]]
            );

            self::assertSame([], $events, "{$type} should remain a raw request ACK only");
        }
    }

    public function testDecodesVivistarAp02IntoLocationEvent(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('vivistar-iw'),
            [
                'type' => 'AP02',
                'data' => [
                    'raw' => 'zh_cn|1@bt1|08-00-20-00-0a-04|38,0,1,268,6,8820|677900|33,4,a|86-45-58-1d-dd-4f|106&a|7e-45-58-1d-dd-4f|105&a|82-45-58-1d-dd-4f|105&a|78-45-58-1d-dd-4f|104',
                    'fields' => [
                        'zh_cn|1@bt1|08-00-20-00-0a-04|38',
                        '0',
                        '1',
                        '268',
                        '6',
                        '8820|677900|33',
                        '4',
                        'a|86-45-58-1d-dd-4f|106&a|7e-45-58-1d-dd-4f|105&a|82-45-58-1d-dd-4f|105&a|78-45-58-1d-dd-4f|104',
                    ],
                ],
            ]
        );

        self::assertCount(1, $events);
        self::assertSame('location', $events[0]['feature']);
        self::assertSame('AP02', $events[0]['nativeType']);
        self::assertSame('cell_wifi', $events[0]['value']['source']);
        self::assertFalse($events[0]['value']['hasCoordinates']);
        self::assertFalse($events[0]['value']['gpsValid']);
        self::assertSame('268', $events[0]['value']['mcc']);
        self::assertSame('6', $events[0]['value']['mnc']);
        self::assertSame('8820', $events[0]['value']['lac']);
        self::assertSame('677900', $events[0]['value']['cellId']);
        self::assertSame(117, $events[0]['value']['gsmSignal']);
        self::assertCount(1, $events[0]['value']['baseStations']);
        self::assertCount(4, $events[0]['value']['wifiAccessPoints']);
        self::assertSame('vivistar-ap02', $events[0]['extra']['sourceRaw']);
        self::assertSame(0, $events[0]['extra']['replyFlag']);
        self::assertSame(1, $events[0]['extra']['baseCount']);
        self::assertSame(4, $events[0]['extra']['wifiCount']);
    }

    public function testDecodesVivistarAp01IntoLocationEvent(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('vivistar-iw'),
            [
                'type' => 'AP01',
                'data' => [
                    'gpsValid' => true,
                    'lat' => 22.549676666666667,
                    'lon' => 114.08225833333333,
                    'speed' => 0.1,
                    'direction' => 323.87,
                    'gsmSignal' => 60,
                    'satelliteCount' => 9,
                    'mcc' => 460,
                    'mnc' => 0,
                    'lac' => 9520,
                    'cellId' => 3671,
                    'baseStation' => [
                        ['ci' => 3671, 'lac' => 9520, 'mcc' => 460, 'mnc' => 0, 'rxlev' => 60],
                    ],
                    'wifi' => [
                        ['ssid' => 'Home', 'mac' => '74-DE-2B-44-88-8C', 'signal' => 97],
                    ],
                ],
            ]
        );

        self::assertCount(1, $events);
        self::assertSame('location', $events[0]['feature']);
        self::assertSame('AP01', $events[0]['nativeType']);
        self::assertSame('gps', $events[0]['value']['source']);
        self::assertTrue($events[0]['value']['hasCoordinates']);
        self::assertTrue($events[0]['value']['gpsValid']);
        self::assertSame(22.549676666666667, $events[0]['value']['lat']);
        self::assertSame(114.08225833333333, $events[0]['value']['lon']);
        self::assertSame(9, $events[0]['value']['satelliteCount']);
        self::assertSame('460', $events[0]['value']['mcc']);
        self::assertSame('9520', $events[0]['value']['lac']);
        self::assertCount(1, $events[0]['value']['baseStations']);
        self::assertCount(1, $events[0]['value']['wifiAccessPoints']);
    }

    public function testDecodesVivistarAp10IntoAlarmLocationAndBatteryEvents(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('vivistar-iw'),
            [
                'type' => 'AP10',
                'data' => [
                    'alarmCode' => '06',
                    'fall' => true,
                    'lowBattery' => false,
                    'sos' => false,
                    'wearingNotice' => false,
                    'lat' => 22.549676666666667,
                    'lon' => 114.08225833333333,
                    'gpsValid' => true,
                    'speed' => 0.1,
                    'direction' => 323.87,
                    'mcc' => 460,
                    'mnc' => 0,
                    'lac' => 9520,
                    'cellId' => 3671,
                    'battery' => 80,
                ],
            ]
        );

        self::assertSame(['alarm', 'location', 'battery'], array_column($events, 'feature'));
        self::assertSame('fall', $events[0]['value']['code']);
        self::assertTrue($events[0]['value']['fall']);
        self::assertFalse($events[0]['value']['wearingNotice']);
        self::assertSame('06', $events[0]['extra']['rawCode']);
        self::assertTrue($events[1]['value']['hasCoordinates']);
        self::assertSame(22.549676666666667, $events[1]['value']['lat']);
        self::assertSame(114.08225833333333, $events[1]['value']['lon']);
        self::assertArrayNotHasKey('rawCode', $events[1]['extra'] ?? []);
        self::assertSame(80, $events[2]['value']['percent']);
        self::assertArrayNotHasKey('extra', $events[2]);
    }

    public function testDecodesFourPTouchHealthReport(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('four-p-touch'),
            [
                'type' => 'bphrt',
                'data' => [
                    'systolic' => 112,
                    'diastolic' => 73,
                    'heartRate' => 67,
                ],
            ]
        );

        self::assertSame(['blood_pressure', 'heart_rate'], array_column($events, 'feature'));
        self::assertSame(112, $events[0]['value']['systolicMmHg']);
        self::assertSame(67, $events[1]['value']['bpm']);
    }

    public function testDecodesFourPTouchOxygenReport(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('four-p-touch'),
            [
                'type' => 'oxygen',
                'data' => [
                    'measureType' => 0,
                    'spo2' => 97,
                ],
            ]
        );

        self::assertCount(1, $events);
        self::assertSame('blood_oxygen', $events[0]['feature']);
        self::assertSame(97, $events[0]['value']['spo2Percent']);
    }

    public function testDecodesFourPTouchBodyTemperatureReport(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('four-p-touch'),
            [
                'type' => 'btemp2',
                'data' => [
                    'measureType' => 1,
                    'temp' => 36.7,
                ],
            ]
        );

        self::assertCount(1, $events);
        self::assertSame('temperature', $events[0]['feature']);
        self::assertSame(36.7, $events[0]['value']['bodyCelsius']);
        self::assertSame(1, $events[0]['extra']['measureType']);
    }

    public function testDecodesFourPTouchPositionIntoLocationActivityAndBattery(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('four-p-touch'),
            [
                'type' => 'UD2',
                'data' => [
                    'source' => 'gps',
                    'gpsValid' => true,
                    'lat' => 38.7167,
                    'lon' => -9.1399,
                    'speed' => 0.5,
                    'direction' => 152.0,
                    'altitude' => 12.0,
                    'satellites' => 9,
                    'gsmSignal' => 88,
                    'batteryPercent' => 76,
                    'steps' => 1042,
                    'mcc' => '268',
                    'mnc' => '01',
                    'lac' => '1234',
                    'cellId' => '5678',
                    'accuracy' => 15.5,
                    'baseStations' => [
                        ['lac' => '1234', 'cellId' => '5678', 'gsmSignal' => 91],
                    ],
                    'wifi' => [
                        ['label' => 'OfficeNet', 'mac' => 'bc:5f:f6:1e:07:be', 'signal' => -55],
                    ],
                ],
            ]
        );

        self::assertSame(['location', 'activity', 'battery'], array_column($events, 'feature'));
        self::assertSame('gps', $events[0]['value']['source']);
        self::assertTrue($events[0]['value']['hasCoordinates']);
        self::assertSame(38.7167, $events[0]['value']['lat']);
        self::assertSame(-9.1399, $events[0]['value']['lon']);
        self::assertSame(9, $events[0]['value']['satelliteCount']);
        self::assertSame('268', $events[0]['value']['mcc']);
        self::assertSame('1234', $events[0]['value']['lac']);
        self::assertCount(1, $events[0]['value']['baseStations']);
        self::assertCount(1, $events[0]['value']['wifiAccessPoints']);
        self::assertSame(1042, $events[1]['value']['steps']);
        self::assertSame(76, $events[2]['value']['percent']);
    }

    public function testDecodesFourPTouchAlarmIntoLocationAlarmAndBattery(): void
    {
        $events = (new DeviceEventDecoder())->decode(
            $this->session('four-p-touch'),
            [
                'type' => 'AL_LTE',
                'data' => [
                    'source' => 'lbs_wifi',
                    'gpsValid' => false,
                    'lat' => 0.0,
                    'lon' => 0.0,
                    'gsmSignal' => 55,
                    'batteryPercent' => 44,
                    'mcc' => '334',
                    'mnc' => '020',
                    'lac' => '13011',
                    'cellId' => '23152151',
                    'networkType' => 'LTE',
                    'alarmCode' => '00200000',
                    'fall' => true,
                    'sos' => false,
                    'lowBattery' => false,
                ],
            ]
        );

        self::assertSame(['location', 'alarm', 'battery'], array_column($events, 'feature'));
        self::assertSame('cell', $events[0]['value']['source']);
        self::assertFalse($events[0]['value']['hasCoordinates']);
        self::assertArrayNotHasKey('lat', $events[0]['value']);
        self::assertArrayNotHasKey('lon', $events[0]['value']);
        self::assertSame('fall', $events[1]['value']['code']);
        self::assertTrue($events[1]['value']['fall']);
        self::assertSame('00200000', $events[1]['extra']['rawCode']);
        self::assertSame('lbs_wifi', $events[0]['extra']['sourceRaw']);
        self::assertSame('LTE', $events[1]['extra']['networkType']);
        self::assertSame('13011', $events[0]['value']['lac']);
        self::assertSame(44, $events[2]['value']['percent']);
    }

    public function testSkipsUnknownNativePackets(): void
    {
        self::assertSame([], (new DeviceEventDecoder())->decode(
            $this->session('four-p-touch'),
            ['type' => 'FFZDPAYH5', 'data' => ['raw' => 'FFZDPAYH5']]
        ));
    }

    private function session(string $protocol): DeviceSession
    {
        return new DeviceSession(
            new DecoderFakeConnection(),
            'tcp',
            true,
            '8800000015',
            $protocol,
            'Supplier',
            'Model'
        );
    }
}

final class DecoderFakeConnection implements ConnectionInterface
{
    public int $resourceId = 1;

    public function send($data): static
    {
        return $this;
    }

    public function close(): static
    {
        return $this;
    }
}
