<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Ingress\Tcp\Zayata\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/** Sem o aparelho à mão, o descodificador do próprio hub ler a trama de volta é a única prova de que ela está certa. */
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

    public function testTheMedicationPlanRefusesMoreSlotsThanTheDeviceHas(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'medicationPlan', [
            'plans' => array_fill(0, 10, ['hour' => 8, 'minute' => 0, 'enabled' => true]),
        ]);
    }

    public function testSoundAndDoNotDisturbAreWrittenAsConfiguration(): void
    {
        // Volume e toque são enumerações independentes, cada uma com o seu comando.
        $volume = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'alarmVolume', ['volume' => 3]),
            0x06,
        );
        self::assertSame("\x03", $volume[0x1013]['value'], '3 é silêncio, e não o volume mais alto');

        $ringtone = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'alarmRingtone', ['ringtone' => 2]),
            0x06,
        );
        self::assertSame("\x02", $ringtone[0x1012]['value']);

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

    public function testThePlanPeriodIsWrittenAsSixIntegersAndASwitch(): void
    {
        // A intenção é uma data; o nativo são ano, mês e dia em TAGs separadas. O ano é
        // INT16U e não cabe num byte.
        $tlv = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'medicationPeriod', [
                'enabled' => true,
                'startDate' => '2026-09-18',
                'endDate' => '2026-12-31',
            ]),
            0x06,
        );

        self::assertSame(2026, unpack('v', $tlv[0x1004]['value'])[1]);
        self::assertSame("\x09", $tlv[0x1005]['value']);
        self::assertSame("\x12", $tlv[0x1006]['value']);
        self::assertSame(2026, unpack('v', $tlv[0x1007]['value'])[1]);
        self::assertSame("\x0C", $tlv[0x1008]['value']);
        self::assertSame("\x1F", $tlv[0x1009]['value']);
        self::assertSame("\x01", $tlv[0x100A]['value']);
    }

    public function testAPlanWithoutAPeriodTurnsTheSwitchOff(): void
    {
        // Sem período, o plano vale sempre — e é o interruptor que o diz, não datas a zero.
        $tlv = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'medicationPeriod', [
                'enabled' => false,
            ]),
            0x06,
        );

        self::assertSame("\x00", $tlv[0x100A]['value']);
    }

    public function testTheTimeZoneIsSignedAndInHoursAndMinutes(): void
    {
        // HHMM e não minutos: -100 é uma hora atrás, e não cem minutos.
        $zone = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'timeZone', ['timeZone' => -100]),
            0x06,
        );
        self::assertSame(-100, unpack('s', $zone[0x1015]['value'])[1]);

        $lisbonSummer = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'timeZone', ['timeZone' => 100]),
            0x06,
        );
        self::assertSame(100, unpack('s', $lisbonSummer[0x1015]['value'])[1]);
    }

    public function testControlsTravelAsControlPacketsAndNotAsConfiguration(): void
    {
        // Sem a reposição de fábrica (`0xA002`): não é um comando que o hub monte.
        $controls = [
            'restartDevice' => 0xA001,
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

    public function testReadingConfigurationAsksForTheTagsWithRoomForTheAnswer(): void
    {
        // A especificação é explícita: no pedido de leitura, o valor vai a zeros **com o
        // comprimento da TAG**, e não vazio. O aparelho devolve o mesmo corpo preenchido.
        $tlv = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'readConfiguration', []),
            0x05,
        );

        // Um byte para as horas dos alarmes...
        self::assertSame("\x00", $tlv[0x1021]['value']);
        self::assertSame("\x00", $tlv[0x1041]['value']);
        // ...e dois para o que é INT16: o ano do período e o fuso.
        self::assertSame("\x00\x00", $tlv[0x1004]['value']);
        self::assertSame("\x00\x00", $tlv[0x1015]['value']);
        // Os nove alarmes inteiros, e não só o primeiro.
        self::assertArrayHasKey(0x1029, $tlv);
        self::assertArrayHasKey(0x1049, $tlv);
    }

    /** O aparelho conta o atraso em segundos, num INT32U; a intenção vem em minutos. */
    public function testTheWarningTimeTravelsInSeconds(): void
    {
        $tlv = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'retrievalWarning', ['minutes' => 45]),
            0x06,
        );

        self::assertSame(45 * 60, unpack('V', $tlv[0x1017]['value'])[1]);
        self::assertSame(PillDispenserAdapter::T_INT32U, $tlv[0x1017]['type']);
    }

    public function testAnUnknownCommandIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'naoExiste', []);
    }

    /** Os tipos vêm à mão da tabela «TAG Definition - Device Type 02», porque `packTlv` e `parseTlv` concordam entre si. */
    public function testEveryDownlinkTagDeclaresTheParameterTypeTheSpecRequires(): void
    {
        $int8u = 2;
        $exceptions = [
            0x1004 => 4, 0x1007 => 4,   // ano de início e de fim do período, INT16U
            0x1015 => 3,                // fuso horário, INT16S
            0x1017 => 6, 0x1018 => 6,   // aviso de atraso e tempo até falhar, INT32U em segundos
            0x810A => 3, 0x810B => 3,   // sinal WiFi e GSM, INT16S
            0x810E => 1,                // temperatura, INT8S
            0x8009 => 11,               // CCID do cartão SIM, STRING de 20
            0xA101 => 11,               // calibração do relógio, STRING
        ];

        $wrong = [];
        foreach (self::everyCommand() as [$command, $payload]) {
            $frame = DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, $command, $payload);
            $decoded = (new PillDispenserAdapter())->decodeIncoming($frame);
            self::assertIsArray($decoded, $command);

            foreach ($decoded['tlv'] as $tag => $entry) {
                $expected = $exceptions[$tag] ?? $int8u;
                if ($entry['type'] !== $expected) {
                    $wrong[] = sprintf(
                        '%s/0x%04X: tipo %d, esperado %d',
                        $command,
                        $tag,
                        $entry['type'],
                        $expected,
                    );
                }
            }
        }

        self::assertSame([], $wrong);
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private static function everyCommand(): array
    {
        return [
            ['medicationPlan', ['plans' => [['hour' => 8, 'minute' => 30, 'enabled' => true]]]],
            ['medicationPeriod', ['enabled' => true, 'startDate' => '2026-01-01', 'endDate' => '2026-12-31']],
            ['childLock', ['enabled' => true]],
            ['earlyRetrieval', ['enabled' => false]],
            ['alarmRingtone', ['ringtone' => 2]],
            ['alarmVolume', ['volume' => 1]],
            ['deviceLanguage', ['language' => 1]],
            ['timeZone', ['timeZone' => 100]],
            ['doNotDisturb', [
                'enabled' => true,
                'startHour' => 22,
                'startMinute' => 0,
                'endHour' => 7,
                'endMinute' => 0,
            ]],
            ['restartDevice', []],
            ['calibrateClock', []],
            ['muteAlarm', []],
            ['resetTray', []],
            ['dispenseNow', []],
            ['readConfiguration', []],
            ['readStatus', []],
        ];
    }
}
