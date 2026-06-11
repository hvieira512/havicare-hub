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
