<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use App\Hub\DeviceEventDecoder;
use App\Hub\DeviceSession;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

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

        self::assertSame(['heart_rate', 'blood_pressure', 'blood_oxygen'], array_column($events, 'feature'));
        self::assertSame(100, $events[0]['value']['bpm']);
        self::assertSame(120, $events[1]['value']['systolicMmHg']);
        self::assertSame(98, $events[2]['value']['spo2Percent']);
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
        self::assertSame(38.715, $events[0]['value']['lat']);
        self::assertSame(-9.145, $events[0]['value']['lon']);
        self::assertSame(8, $events[0]['value']['satelliteCount']);
        self::assertSame(268, (int)$events[0]['value']['mcc']);
        self::assertSame(1, (int)$events[0]['value']['mnc']);
        self::assertSame(1234, (int)$events[0]['value']['lac']);
        self::assertSame(5679, (int)$events[0]['value']['cellId']);
        self::assertCount(1, $events[0]['extra']['baseStation']);
        self::assertCount(1, $events[0]['extra']['wifi']);
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
        self::assertSame(['device_config'], array_column($config, 'feature'));
        self::assertSame('ok', $config[0]['value']['status']);
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
        self::assertSame(90, $heartbeat[1]['value']['percent']);
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
        self::assertSame(91, $events[3]['value']['value']);
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
        self::assertSame('vivistar-ap02', $events[0]['value']['source']);
        self::assertFalse($events[0]['value']['gpsValid']);
        self::assertSame('268', $events[0]['value']['mcc']);
        self::assertSame('6', $events[0]['value']['mnc']);
        self::assertSame('8820', $events[0]['value']['lac']);
        self::assertSame('677900', $events[0]['value']['cellId']);
        self::assertSame(117, $events[0]['value']['gsmSignal']);
        self::assertSame(0, $events[0]['extra']['replyFlag']);
        self::assertSame(1, $events[0]['extra']['baseCount']);
        self::assertSame(4, $events[0]['extra']['wifiCount']);
        self::assertCount(1, $events[0]['extra']['baseStations']);
        self::assertCount(4, $events[0]['extra']['wifi']);
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

    public function send($data)
    {
        return $this;
    }

    public function close()
    {
        return $this;
    }
}
