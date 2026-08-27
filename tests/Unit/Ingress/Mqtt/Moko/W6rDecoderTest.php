<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\W6rDecoder;
use PHPUnit\Framework\TestCase;

/**
 * Os vectores de campos do gateway são observações capturadas tal e qual de um MKGW3 a
 * retransmitir uma W6R real (fb:d8:7c:59:ba:8b) com o botão premido.
 */
final class W6rDecoderTest extends TestCase
{
    /** Toque simples, com os campos da resposta ao scan presentes. */
    private const SINGLE_PRESS = [
        'timestamp' => 1786359514903,
        'timezone' => 0,
        'type_code' => 7,
        'type' => 'bxp-button',
        'rssi' => -82,
        'connectable' => 1,
        'mac' => 'fbd87c59ba8b',
        'frame_type' => 0,
        'passwd_verification' => 1,
        'alarm_status' => 1,
        'trigger_count' => 69,
        'device_id' => '000001',
        'adv_name' => 'MK Button',
        'full_scale' => '2g',
        'motion_threshold' => 80,
        'x_axis_data' => -4,
        'y_axis_data' => -20,
        'z_axis_data' => 1052,
        'temperature' => 27.2,
        'ranging_data' => 0,
        'batt_vol' => 98,
        'txpower' => 0,
    ];

    /** Toque duplo, capturado sem a resposta ao scan. */
    private const DOUBLE_PRESS_ALARM_ONLY = [
        'type_code' => 7,
        'type' => 'bxp-button',
        'rssi' => -87,
        'connectable' => 1,
        'mac' => 'fbd87c59ba8b',
        'frame_type' => 1,
        'passwd_verification' => 1,
        'alarm_status' => 1,
        'trigger_count' => 42,
        'device_id' => '000001',
        'adv_name' => 'MK Button',
    ];

    public function testDecodesASinglePressFromTheGatewayFields(): void
    {
        $decoded = (new W6rDecoder())->decode(self::SINGLE_PRESS);

        self::assertSame('fbd87c59ba8b', $decoded['mac']);
        self::assertSame('single', $decoded['alarm']['pressMode']);
        self::assertSame(69, $decoded['alarm']['triggerCount']);
        self::assertTrue($decoded['alarm']['triggered']);
        self::assertSame('000001', $decoded['alarm']['deviceId']);
    }

    public function testCarriesTheGatewayMeasuredRssiThrough(): void
    {
        // A proximidade precisa disto: só o gateway o consegue medir, e por isso existe na
        // observação e em sítio nenhum do anúncio.
        self::assertSame(-82, (new W6rDecoder())->decode(self::SINGLE_PRESS)['rssiDbm'] ?? null);
    }

    public function testOmitsRssiWhenTheGatewayDidNotReportIt(): void
    {
        $observation = self::SINGLE_PRESS;
        unset($observation['rssi']);

        self::assertArrayNotHasKey('rssiDbm', (new W6rDecoder())->decode($observation));
    }

    public function testFrameTypeIsReportedWithoutTheSpecBaseOffset(): void
    {
        // A folha do fabricante numera os modos 0x20/0x21/0x22 e o gateway 0/1/2. Cada modo
        // leva o seu contador, e foi assim que o mapeamento se confirmou contra um aparelho
        // premido em simples, duplo e longo, um a um.
        $decoder = new W6rDecoder();
        $modes = [];
        foreach ([0, 1, 2, 3] as $frameType) {
            $decoded = $decoder->decode(['mac' => 'fbd87c59ba8b', 'type' => 'bxp-button', 'frame_type' => $frameType, 'trigger_count' => 1]);
            $modes[] = $decoded['alarm']['pressMode'];
        }

        self::assertSame(['single', 'double', 'long', 'inactivity'], $modes);
    }

    public function testEachPressModeCarriesItsOwnCounter(): void
    {
        $decoder = new W6rDecoder();

        self::assertSame(69, $decoder->decode(self::SINGLE_PRESS)['alarm']['triggerCount']);
        self::assertSame(42, $decoder->decode(self::DOUBLE_PRESS_ALARM_ONLY)['alarm']['triggerCount']);
    }

    public function testDecodesAccelerationAndBatteryPercentage(): void
    {
        $decoded = (new W6rDecoder())->decode(self::SINGLE_PRESS);

        self::assertSame(['x' => -4, 'y' => -20, 'z' => 1052], $decoded['info']['accelerationMg']);
        // A app deles reportava 98% para este aparelho na hora da captura.
        self::assertSame(98, $decoded['info']['batteryPercent']);
    }

    public function testAnAlarmWithoutTheScanResponseHasNoInfo(): void
    {
        $decoded = (new W6rDecoder())->decode(self::DOUBLE_PRESS_ALARM_ONLY);

        self::assertSame('double', $decoded['alarm']['pressMode']);
        self::assertArrayNotHasKey('info', $decoded);
    }

    public function testBatteryAboveOneHundredIsMillivolts(): void
    {
        $decoded = (new W6rDecoder())->decode(
            ['batt_vol' => 3009] + self::DOUBLE_PRESS_ALARM_ONLY
        );

        self::assertSame(3009, $decoded['info']['batteryVoltageMv']);
        self::assertArrayNotHasKey('batteryPercent', $decoded['info']);
    }

    public function testAnUntriggeredAlarmStatusIsReported(): void
    {
        $decoded = (new W6rDecoder())->decode(['alarm_status' => 0] + self::DOUBLE_PRESS_ALARM_ONLY);

        self::assertFalse($decoded['alarm']['triggered']);
    }

    public function testIgnoresDevicesThatAreNotMokoButtons(): void
    {
        $decoder = new W6rDecoder();

        // Uma captura real de um aparelho Apple, do mesmo gateway.
        self::assertNull($decoder->decode([
            'mac' => '4e9f3ec3cfc6',
            'type' => 'other',
            'adv_data' => '02011a020a0c0bff4c001006061988d14808',
        ]));
        // A W6R antes de os seus slots de anúncio estarem configurados.
        self::assertNull($decoder->decode([
            'mac' => 'fbd87c59ba8b',
            'type' => 'bxp-button',
            'adv_data' => '',
            'rsp_data' => '',
        ]));
        self::assertNull($decoder->decode(['type' => 'bxp-button', 'mac' => 'not-a-mac', 'frame_type' => 0, 'trigger_count' => 1]));
        // Os tipos de frame reservados não se adivinham.
        self::assertNull($decoder->decode(['mac' => 'fbd87c59ba8b', 'type' => 'bxp-button', 'frame_type' => 9, 'trigger_count' => 1]));
    }

    public function testStillDecodesRawAdvertisingDataFromGatewaysThatSendIt(): void
    {
        // Um MKGW3 pré-descodifica os frames MOKO, mas nada garante que todos os modelos de
        // gateway o façam, e por isso o formato BXP-B continua suportado.
        $adv = '020106'
            . sprintf('%02x', 1 + 2 + 12) . '16' . 'e0fe'
            . '22'            // 0x22 -> long press
            . '02'            // status: main button triggered
            . '0006'          // trigger count 6
            . '000000000001'  // device id
            . '00' . '00'     // firmware type, RFU
            . '0a09' . bin2hex('MK Button');

        $decoded = (new W6rDecoder())->decode(['mac' => 'fbd87c59ba8b', 'adv_data' => $adv]);

        self::assertSame('long', $decoded['alarm']['pressMode']);
        self::assertSame(6, $decoded['alarm']['triggerCount']);
        self::assertTrue($decoded['alarm']['triggered']);
    }
}
