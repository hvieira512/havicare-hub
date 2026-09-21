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

    public function testEachDispenseSwitchIsItsOwnConfiguration(): void
    {
        // Dois interruptores independentes, e por isso duas definições: a dashboard agrupa
        // interruptores em linhas compactas, e um bloco com dois lá dentro fugia ao padrão.
        $early = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'earlyRetrieval', ['enabled' => true]),
            0x06,
        );
        self::assertSame("\x01", $early[0x100D]['value']);

        $lock = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'childLock', ['enabled' => false]),
            0x06,
        );
        self::assertSame("\x00", $lock[0x100C]['value']);
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
        $language = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'deviceLanguage', ['language' => 1]),
            0x06,
        );
        self::assertSame("\x01", $language[0x1001]['value']);

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

    public function testQueryingStatusAsksForTheStatusTagsAndNotTheConfigurationOnes(): void
    {
        $tlv = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'readStatus', []),
            0x07,
        );

        self::assertSame("\x00", $tlv[0x8101]['value'], 'nível de medicação, um byte');
        self::assertSame("\x00", $tlv[0x810E]['value'], 'temperatura é INT8S');
        self::assertSame("\x00\x00", $tlv[0x810A]['value'], 'o sinal é INT16S');
        self::assertArrayHasKey(0x8112, $tlv);
        self::assertArrayHasKey(0x811D, $tlv);
        // Uma consulta de estado não pergunta por configuração.
        self::assertArrayNotHasKey(0x1021, $tlv);
    }

    public function testAnUnknownCommandIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'naoExiste', []);
    }

    /**
     * O M228 sai de fábrica a cifrar o corpo dos pacotes que envia, e a especificação nunca
     * diz a chave. Sem isto o hub recebe um heartbeat por minuto que não consegue ler: o
     * cabeçalho lê-se, o corpo é texto cifrado, e não há telemetria nenhuma.
     */
    public function testDisablingEncryptionWritesTheParameterThatTurnsItOff(): void
    {
        $tlv = $this->decode(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, 'disableEncryption', []),
            0x06,
        );

        self::assertSame("\x00", $tlv[0x8005]['value'], '0 é «não cifrar»');
        self::assertSame(PillDispenserAdapter::T_INT8U, $tlv[0x8005]['type']);
    }

    /**
     * Cada TFLV declara o tipo do parâmetro nos bits 0--4 do Flag, e o aparelho recusa com
     * «tipo de parâmetro inválido» tudo o que lhe chegue como `UNKONW`.
     *
     * Isto não se via a construir e descodificar a trama connosco próprios: o `packTlv` e o
     * `parseTlv` concordavam no zero e o round-trip fechava. Foi o M228 real que discordou --
     * devolveu as vinte e sete TAGs de um plano com estado `010`. Por isso os tipos esperados
     * estão aqui escritos à mão, da tabela «TAG Definition - Device Type 02», e não lidos da
     * tabela do adaptador: um teste que se sirva da mesma fonte que o código não prova nada.
     */
    public function testEveryDownlinkTagDeclaresTheParameterTypeTheSpecRequires(): void
    {
        $int8u = 2;
        $excepções = [
            0x1004 => 4, 0x1007 => 4,   // ano de início e de fim do período, INT16U
            0x1015 => 3,                // fuso horário, INT16S
            0x810A => 3, 0x810B => 3,   // sinal WiFi e GSM, INT16S
            0x810E => 1,                // temperatura, INT8S
            0xA101 => 11,               // calibração do relógio, STRING
        ];

        $errados = [];
        foreach (self::everyCommand() as [$comando, $payload]) {
            $frame = DeviceCommandCatalog::buildDownlink('zayata-m228', self::MAC, $comando, $payload);
            $decoded = (new PillDispenserAdapter())->decodeIncoming($frame);
            self::assertIsArray($decoded, $comando);

            foreach ($decoded['tlv'] as $tag => $entry) {
                $esperado = $excepções[$tag] ?? $int8u;
                if ($entry['type'] !== $esperado) {
                    $errados[] = sprintf(
                        '%s/0x%04X: tipo %d, esperado %d',
                        $comando,
                        $tag,
                        $entry['type'],
                        $esperado,
                    );
                }
            }
        }

        self::assertSame([], $errados);
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private static function everyCommand(): array
    {
        return [
            ['medicationPlan', ['plans' => [['hour' => 8, 'minute' => 30, 'enabled' => true]]]],
            ['medicationPeriod', ['enabled' => true, 'start' => '2026-01-01', 'end' => '2026-12-31']],
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
            ['disableEncryption', []],
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
