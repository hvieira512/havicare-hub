<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * A descida do dispensador: o que o hub monta tem de ser lido de volta pelo próprio
 * descodificador, que é a única prova de que a trama está certa sem ter o aparelho à mão.
 */
final class PillDispenserDownlinkTest extends TestCase
{
    private const MAC = 'AABBCCDDEE01';

    /** @return array<int, array{type: int, state: int, value: string}> */
    private function decode(string $frame, int $expectedPacketType): array
    {
        $adapter = new PillDispenserAdapter();
        $decoded = $adapter->decodeIncoming($frame);

        self::assertIsArray($decoded, 'a trama de descida tem de ser válida à luz do protocolo');
        self::assertSame($expectedPacketType, $decoded['packetType']);
        self::assertSame(self::MAC, $decoded['imei'], 'a identidade tem de voltar intacta');

        return $decoded['tlv'];
    }

    public function testTheMedicationPlanWritesHourMinuteAndSwitchForEachSlot(): void
    {
        $frame = DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'medicationPlan', [
            'plans' => [
                ['hour' => 8, 'minute' => 30, 'enabled' => true],
                ['hour' => 20, 'minute' => 5, 'enabled' => false],
            ],
        ]);

        $tlv = $this->decode($frame, 0x06);

        // Alarme 1: 08:30 ligado.
        self::assertSame("\x08", $tlv[0x1021]['value']);
        self::assertSame("\x1E", $tlv[0x1031]['value']);
        self::assertSame("\x01", $tlv[0x1041]['value']);
        // Alarme 2: 20:05 desligado.
        self::assertSame("\x14", $tlv[0x1022]['value']);
        self::assertSame("\x05", $tlv[0x1032]['value']);
        self::assertSame("\x00", $tlv[0x1042]['value']);
        // Os slots que o plano não usa são desligados de propósito: o aparelho tem nove
        // fixos, e um que sobrasse de um plano anterior continuava a tocar.
        self::assertSame("\x00", $tlv[0x1049]['value']);
    }

    public function testTheMedicationPlanRefusesMoreSlotsThanTheDeviceHas(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'medicationPlan', [
            'plans' => array_fill(0, 10, ['hour' => 8, 'minute' => 0, 'enabled' => true]),
        ]);
    }

    public function testSoundAndDoNotDisturbAreWrittenAsConfiguration(): void
    {
        $sound = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'soundProfile', [
                'volume' => 3,
                'ringtone' => 2,
            ]),
            0x06,
        );
        self::assertSame("\x02", $sound[0x1012]['value']);
        self::assertSame("\x03", $sound[0x1013]['value']);

        $quiet = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'doNotDisturb', [
                'enabled' => true,
                'startHour' => 22,
                'startMinute' => 30,
                'endHour' => 7,
                'endMinute' => 0,
            ]),
            0x06,
        );
        self::assertSame("\x01", $quiet[0x1051]['value']);
        self::assertSame("\x16", $quiet[0x1052]['value']);
        self::assertSame("\x1E", $quiet[0x1053]['value']);
        self::assertSame("\x07", $quiet[0x1054]['value']);
        self::assertSame("\x00", $quiet[0x1055]['value']);
    }

    public function testTheTimeZoneIsSignedBecauseItGoesWest(): void
    {
        $tlv = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'languageTimezone', [
                'language' => 1,
                'timezoneMinutes' => -60,
            ]),
            0x06,
        );

        self::assertSame("\x01", $tlv[0x1001]['value']);
        self::assertSame(-60, unpack('s', $tlv[0x1015]['value'])[1]);
    }

    public function testControlsTravelAsControlPacketsAndNotAsConfiguration(): void
    {
        $controls = [
            'restartDevice' => 0xA001,
            'factoryReset' => 0xA002,
            'calibrateClock' => 0xA101,
            'muteAlarm' => 0xA102,
            'resetTray' => 0xA103,
            'dispenseNow' => 0xA123,
        ];

        foreach ($controls as $command => $tag) {
            $tlv = $this->decode(
                DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, $command, []),
                0x08,
            );

            self::assertArrayHasKey($tag, $tlv, "o comando {$command} tem de escrever a TAG " . dechex($tag));
        }
    }

    public function testAnImeiIdentityIsEncodedAsImeiAndNotAsMac(): void
    {
        $adapter = new PillDispenserAdapter();
        $frame = DeviceCommandCatalog::buildDownlink('zayata-m228', '860123456789012', 'muteAlarm', []);
        $decoded = $adapter->decodeIncoming($frame);

        self::assertIsArray($decoded);
        self::assertSame('imei', $decoded['idKind']);
        self::assertSame('860123456789012', $decoded['imei']);
    }

    public function testAnUnknownCommandIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'naoExiste', []);
    }
}
