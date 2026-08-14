<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Moko\GatewayNormalizer;
use Hub\Ingress\Mqtt\Moko\Mkgw3MessageDecoder;
use Hub\Ingress\Mqtt\Moko\Mkgw4MessageDecoder;
use Hub\Ingress\Mqtt\Moko\MonitMecsProDecoder;
use Hub\Ingress\Mqtt\Moko\MonitNormalizer;
use Hub\Ingress\Mqtt\Moko\Topic;
use PHPUnit\Framework\TestCase;

final class DecoderTest extends TestCase
{
    private const ADV_DATA = '0201041aff5900021535c80410418015dc8200410418415dc8200202f9c3';
    private const MKGW4_HEARTBEAT = 'ef3004c5e390f30bce00400000046a759cd8010007464444204c54450200011203000210740400060002000200000500010006000f38363130373630383232333235313107000400000998';

    public function testParsesCanonicalGatewayIngressTopic(): void
    {
        $topic = Topic::parse('havicare-hub/null/0/gw/d4:8c:49:f7:90:9c/raw');
        self::assertSame('d48c49f7909c', $topic?->gatewayMac);
        self::assertNull(Topic::parse('havicare-hub/null/0/gateway/d48c49f7909c/raw'));
    }

    public function testDecodesMkgw3HeartbeatAndNormalizesConnectivity(): void
    {
        $decoded = (new Mkgw3MessageDecoder())->decode(json_encode([
            'msg_id' => 3004,
            'device_info' => ['mac' => 'd48c49f7909c'],
            'data' => ['timestamp' => 0, 'net_interface' => 1, 'wifi_rssi' => -54],
        ], JSON_THROW_ON_ERROR));
        self::assertSame(3004, $decoded['messageId'] ?? null);

        $telemetry = (new GatewayNormalizer())->telemetry($decoded, $this->device('gateway'));
        self::assertSame('connectivity', $telemetry[0]['type'] ?? null);
        self::assertSame(['interface' => 'wifi', 'signalStrengthDbm' => -54], $telemetry[0]['data'] ?? null);
        self::assertSame('moko-mkgw3', $telemetry[0]['source']['protocol'] ?? null);
    }

    public function testDecodesLiveMkgw4HeartbeatAndNormalizesCellularTelemetry(): void
    {
        $decoded = (new Mkgw4MessageDecoder())->decode(hex2bin(self::MKGW4_HEARTBEAT));

        self::assertSame(3004, $decoded['messageId'] ?? null);
        self::assertSame('c5e390f30bce', $decoded['gatewayMac'] ?? null);
        self::assertSame('FDD LTE', $decoded['data']['network_type'] ?? null);
        self::assertSame(18, $decoded['data']['csq'] ?? null);
        self::assertSame(4212, $decoded['data']['battery_voltage_mv'] ?? null);
        self::assertSame('861076082232511', $decoded['data']['imei'] ?? null);
        self::assertSame(2456, $decoded['data']['heartbeat_index'] ?? null);

        $telemetry = (new GatewayNormalizer())->telemetry($decoded, [
            'imei' => 'c5e390f30bce', 'supplier' => 'MOKO', 'model' => 'MKGW4',
        ]);
        self::assertSame(['connectivity', 'battery'], array_column($telemetry, 'type'));
        self::assertSame([
            'interface' => 'cellular', 'networkType' => 'FDD LTE',
            'signalQuality' => 18, 'signalStrengthDbm' => -77,
        ], $telemetry[0]['data']);
        self::assertSame(['voltageMv' => 4212], $telemetry[1]['data']);
        self::assertSame('moko-mkgw4', $telemetry[0]['source']['protocol'] ?? null);
    }

    public function testDecodesMkgw4AsciiHexAndBleScanForExistingMonitDecoder(): void
    {
        $scanPayload = $this->mkgw4Frame('30a0',
            $this->tlv(0, chr(10))
            . $this->tlv(1, hex2bin('eec5000202f9'))
            . $this->tlv(2, chr(1))
            . $this->tlv(4, chr(0xad))
            . $this->tlv(5, hex2bin(self::ADV_DATA))
        );
        $decoded = (new Mkgw4MessageDecoder())->decode(bin2hex($scanPayload));

        self::assertSame('30a0', $decoded['messageId'] ?? null);
        self::assertSame(self::ADV_DATA, $decoded['data'][0]['adv_data'] ?? null);
        self::assertSame(-83, $decoded['data'][0]['rssi'] ?? null);
        self::assertSame(87, (new MonitMecsProDecoder())->decode($decoded['data'][0])['batteryPercent'] ?? null);
    }

    public function testDecodesVerifiedMecsProBitstream(): void
    {
        $decoded = (new MonitMecsProDecoder())->decode([
            'mac' => 'eec5000202f9', 'adv_data' => self::ADV_DATA, 'rssi' => -83,
        ]);

        self::assertSame(87, $decoded['batteryPercent'] ?? null);
        self::assertSame([1, 1, 1, 1, 32, 1, 23, 28, 32, 32], $decoded['baseline'] ?? null);
        self::assertSame([1, 1, 1, 1, 33, 1, 23, 28, 32, 32], $decoded['raw'] ?? null);
        self::assertSame([0, 0, 0, 0, 1, 0, 0, 0, 0, 0], $decoded['normalized'] ?? null);
        self::assertSame(-83, $decoded['rssiDbm'] ?? null);
    }

    public function testRejectsEmbeddedMacMismatch(): void
    {
        self::assertNull((new MonitMecsProDecoder())->decode([
            'mac' => 'eec5000202f8', 'adv_data' => self::ADV_DATA,
        ]));
    }

    public function testNormalizesIndependentMonitCapabilitiesWithSimpleCondition(): void
    {
        $decoded = (new MonitMecsProDecoder())->decode(['mac' => 'eec5000202f9', 'adv_data' => self::ADV_DATA]);
        $result = (new MonitNormalizer())->normalize($decoded, $this->device('diaper_sensor'), 'd48c49f7909c');

        self::assertSame(['battery', 'diaper_moisture', 'diaper_moisture_level', 'diaper_condition'], array_keys($result['telemetry']));
        self::assertSame(['state' => 'clean'], $result['telemetry']['diaper_condition']['data']);
        self::assertSame(1, $result['telemetry']['diaper_moisture']['data']['maximumDelta']);
        self::assertSame(['percent' => 87], $result['telemetry']['battery']['data']);
    }

    public function testDerivesChangeRequiredFromFourAffectedChannels(): void
    {
        $decoded = [
            'batteryPercent' => 80,
            'baseline' => array_fill(0, 10, 1),
            'raw' => [13, 13, 13, 13, 1, 1, 1, 1, 1, 1],
            'normalized' => [12, 12, 12, 12, 0, 0, 0, 0, 0, 0],
            'rssiDbm' => -70,
        ];
        $result = (new MonitNormalizer())->normalize($decoded, $this->device('diaper_sensor'), 'd48c49f7909c');
        self::assertSame('change_required', $result['condition']);
        self::assertSame(['state' => 'change_required'], $result['telemetry']['diaper_condition']['data']);
    }

    private function device(string $type): array
    {
        return ['imei' => $type === 'gateway' ? 'd48c49f7909c' : 'eec5000202f9', 'supplier' => $type === 'gateway' ? 'MOKO' : 'MONIT', 'model' => $type === 'gateway' ? 'MKGW3' : 'MECS-PRO', 'deviceType' => $type, 'licenseId' => '1001', 'company' => 'hitcare'];
    }

    private function mkgw4Frame(string $messageId, string $data): string
    {
        return hex2bin('ef' . $messageId . 'c5e390f30bce' . sprintf('%04x', strlen($data))) . $data;
    }

    private function tlv(int $tag, string $value): string
    {
        return chr($tag) . pack('n', strlen($value)) . $value;
    }
}
