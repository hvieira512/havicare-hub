<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\W6rDecoder;
use PHPUnit\Framework\TestCase;

/**
 * Vectors come from the BXP-B Series tab of the MOKO ADV format sheet.
 *
 * Its absolute byte offsets disagree with its own AD length bytes, so the
 * payloads here are assembled with correct lengths and the decoder walks the
 * advertising structures instead of indexing fixed positions.
 */
final class W6rDecoderTest extends TestCase
{
    /** Alarm frame: service data FEE0. */
    private function alarmAdv(int $frameType = 0x20, int $status = 0x02, int $count = 5): string
    {
        $body = sprintf('%02x%02x%04x%s%02x%02x', $frameType, $status, $count, '000000000001', 0x00, 0x00);

        return '020106'                       // Flags
            . sprintf('%02x', 1 + 2 + 12)     // AD length: type + UUID + 12 byte body
            . '16' . 'e0fe' . $body
            . '0a09' . bin2hex('MK Button');  // complete local name
    }

    /** General info frame: service data EA00, values straight from the sheet. */
    private function infoRsp(string $battery = '0ccc'): string
    {
        return sprintf('%02x', 1 + 2 + 21)
            . '16' . '00ea'
            . '00'            // frame type: general device information
            . '00'            // full scale +-2g
            . '0010'          // motion threshold 16mg
            . '0028'          // X  40mg
            . 'ff84'          // Y -124mg
            . '03d8'          // Z  984mg
            . '006c'          // temperature
            . '00'            // ranging data
            . $battery
            . 'd3a45f30321f'  // MAC
            . '020a00';       // Tx power
    }

    public function testDecodesThePressModeAndTriggerCount(): void
    {
        $decoded = (new W6rDecoder())->decode([
            'mac' => 'fbd87c59ba8b',
            'adv_data' => $this->alarmAdv(0x21, 0x02, 7),
            'rsp_data' => '',
        ]);

        self::assertSame('fbd87c59ba8b', $decoded['mac']);
        self::assertSame('double', $decoded['alarm']['pressMode']);
        self::assertSame(7, $decoded['alarm']['triggerCount']);
        self::assertTrue($decoded['alarm']['triggered']);
        self::assertSame('000000000001', $decoded['alarm']['deviceId']);
    }

    public function testEachAlarmFrameTypeMapsToItsPressMode(): void
    {
        $modes = [];
        foreach ([0x20, 0x21, 0x22, 0x23] as $frameType) {
            $decoded = (new W6rDecoder())->decode([
                'mac' => 'fbd87c59ba8b',
                'adv_data' => $this->alarmAdv($frameType),
            ]);
            $modes[] = $decoded['alarm']['pressMode'];
        }

        self::assertSame(['single', 'double', 'long', 'inactivity'], $modes);
    }

    public function testUntriggeredStatusFlagIsReported(): void
    {
        $decoded = (new W6rDecoder())->decode([
            'mac' => 'fbd87c59ba8b',
            'adv_data' => $this->alarmAdv(0x20, 0x01),
        ]);

        // Bit 0 is password verification, not the button.
        self::assertFalse($decoded['alarm']['triggered']);
    }

    public function testDecodesAccelerationFromTheScanResponse(): void
    {
        $decoded = (new W6rDecoder())->decode([
            'mac' => 'fbd87c59ba8b',
            'adv_data' => '',
            'rsp_data' => $this->infoRsp(),
        ]);

        // The sheet documents 40mg and 984mg for X and Z. It prints -144mg for
        // Y, but 0xFF84 is -124: a typo in the sheet, not a parsing difference.
        self::assertSame(['x' => 40, 'y' => -124, 'z' => 984], $decoded['info']['accelerationMg']);
    }

    public function testBatteryAboveOneHundredIsMillivolts(): void
    {
        $decoded = (new W6rDecoder())->decode([
            'mac' => 'fbd87c59ba8b',
            'rsp_data' => $this->infoRsp('0ccc'),
        ]);

        self::assertSame(3276, $decoded['info']['batteryVoltageMv']);
        self::assertArrayNotHasKey('batteryPercent', $decoded['info']);
    }

    public function testBatteryUpToOneHundredIsAPercentage(): void
    {
        $decoded = (new W6rDecoder())->decode([
            'mac' => 'fbd87c59ba8b',
            'rsp_data' => $this->infoRsp('0064'),
        ]);

        self::assertSame(100, $decoded['info']['batteryPercent']);
        self::assertArrayNotHasKey('batteryVoltageMv', $decoded['info']);
    }

    public function testReadsBothFramesFromOneObservation(): void
    {
        $decoded = (new W6rDecoder())->decode([
            'mac' => 'fbd87c59ba8b',
            'adv_data' => $this->alarmAdv(0x22, 0x02, 3),
            'rsp_data' => $this->infoRsp('0050'),
        ]);

        self::assertSame('long', $decoded['alarm']['pressMode']);
        self::assertSame(80, $decoded['info']['batteryPercent']);
    }

    public function testIgnoresPayloadsWithoutAMokoButtonServiceBlock(): void
    {
        $decoder = new W6rDecoder();

        // A real capture of an Apple device from the same gateway.
        self::assertNull($decoder->decode([
            'mac' => '4e9f3ec3cfc6',
            'adv_data' => '02011a020a0c0bff4c001006061988d14808',
        ]));
        // The empty advertisement the W6R sends with no slot configured.
        self::assertNull($decoder->decode(['mac' => 'fbd87c59ba8b', 'adv_data' => '', 'rsp_data' => '']));
        self::assertNull($decoder->decode(['mac' => 'not-a-mac', 'adv_data' => $this->alarmAdv()]));
    }

    public function testIgnoresTruncatedAndUnknownFrames(): void
    {
        $decoder = new W6rDecoder();

        // Alarm block cut short.
        self::assertNull($decoder->decode(['mac' => 'fbd87c59ba8b', 'adv_data' => '0416e0fe20']));
        // Reserved frame type 0x24-0x3F.
        self::assertNull($decoder->decode(['mac' => 'fbd87c59ba8b', 'adv_data' => $this->alarmAdv(0x24)]));
    }
}
