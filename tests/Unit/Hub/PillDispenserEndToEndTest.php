<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceHubServer;
use Hub\Ingress\Tcp\HubTcpIngress;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\LocalTcpPort;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Do socket ao canal do MQTT, com as tramas na forma em que o aparelho as manda: cifradas. */
final class PillDispenserEndToEndTest extends TestCase
{
    private const IMEI = '869243062262262';

    /** Heartbeat real, cifrado: bateria a 100% e 25 °C. */
    private const HEARTBEAT = 'AA30000001031F000004026222266230246948021EB35A0509D9B171F6D2BB1C'
        . 'EAEA70649DE296A02754B4507058B69C05F08AEF35';

    /** Evento real de toma, cifrado: alarme 3, compartimento 9, tomada a horas. */
    private const INTAKE = 'AA55000001C71E000004026222266230246948031CF05A056FDBF271F6D3B25F'
        . 'E2FC5B54DFE6B4BE413A69CBD368BD964E43F81F121A5C6C6518C2A2B0ECE99A'
        . 'DDC28D49E1BA0E24781E513A88DC37ED79CE63A4F8904AF71B31';

    private Whitelist $whitelist;

    protected function setUp(): void
    {
        $this->whitelist = IngressFixtures::whitelist([
            self::IMEI => IngressFixtures::device('Zayata', 'M228', 'pill_dispenser'),
        ]);
    }

    public function testAnEncryptedIntakeReachesTheEventChannel(): void
    {
        $received = '';
        $mqtt = $this->exchange([$this->frame(0x01, 1), (string)hex2bin(self::INTAKE)], 2, $received);

        self::assertCount(1, $mqtt->statuses);
        self::assertSame('online', $mqtt->statuses[0]['payload']['state']);
        self::assertSame('device.connected', $mqtt->events[0]['payload']['type']);

        // O primeiro ACK mede-se pelo seu campo de comprimento: o 0xAA também aparece dentro da trama.
        $ack = (new PillDispenserAdapter())->decodeIncoming(
            substr($received, 0, 3 + unpack('v', substr($received, 1, 2))[1] + 2)
        );
        self::assertIsArray($ack);
        self::assertSame('register_ack', $ack['type']);
        self::assertSame(self::IMEI, $ack['imei']);

        $intake = $this->ofType($mqtt->events, 'medication_intake');
        self::assertCount(1, $intake, 'a toma cifrada não chegou ao canal de eventos');
        self::assertSame('on_time', $intake[0]['data']['result']);
        self::assertSame(3, $intake[0]['data']['slot']);
        self::assertSame(9, $intake[0]['data']['cellNumber']);
    }

    /** Uma leitura sai por `telemetry`, e não pelo canal com garantia de entrega. */
    public function testAReadingReachesTheTelemetryChannel(): void
    {
        $mqtt = $this->exchange([$this->frame(0x01, 1), (string)hex2bin(self::HEARTBEAT)], 2);

        self::assertCount(1, $this->ofType($mqtt->telemetry, 'battery'));
        self::assertSame(100, $this->ofType($mqtt->telemetry, 'battery')[0]['data']['percent']);
        self::assertCount(1, $this->ofType($mqtt->telemetry, 'ambient_temperature'));

        self::assertSame([], $this->ofType($mqtt->events, 'battery'));
        self::assertSame([], $this->ofType($mqtt->events, 'ambient_temperature'));
    }

    /** O único sinal de uma dose falhada: por `telemetry` sairia a QoS 0. */
    public function testAMissedDoseReachesTheEventChannel(): void
    {
        $mqtt = $this->exchange([
            $this->frame(0x01, 1),
            $this->frame(0x04, 2, [0x8135 => ['value' => "\x06"]]),
        ], 2);

        $change = $this->ofType($mqtt->events, 'medication_alarm_change');
        self::assertCount(1, $change);
        self::assertSame(5, $change[0]['data']['slot']);
        self::assertSame('missed', $change[0]['data']['state']);
        self::assertSame([], $this->ofType($mqtt->telemetry, 'medication_alarm_change'));
    }

    /** Quem integra não repete o plano para saber quando é preciso recarregar o prato. */
    public function testCellsRemainingCarriesTheRunoutDate(): void
    {
        $plan = ['medication_reminders' => ['plans' => [['hour' => 8, 'minute' => 0]]], 'time_zone' => ['timeZone' => 100]];
        $mqtt = $this->exchange(
            [$this->frame(0x01, 1), $this->frame(0x02, 2, [0x811D => ['value' => "\x02"]])],
            2,
            effectiveConfiguration: static fn (string $imei): array => $imei === self::IMEI ? $plan : [],
        );

        $cells = $this->ofType($mqtt->telemetry, 'cells_remaining');
        self::assertCount(1, $cells);
        self::assertSame(2, $cells[0]['data']['remaining']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T08:00:00\+01:00$/', $cells[0]['data']['runoutAt']);
    }

    /** A toma e a mudança do alarme saem com as horas em UTC, pelo fuso e pelo plano que o hub confirmou. */
    public function testDoseEventsCarryUtcTimesFromTheConfirmedZone(): void
    {
        $configurations = [
            'medication_reminders' => ['plans' => [['slot' => 3, 'hour' => 8, 'minute' => 0]]],
            'time_zone' => ['timeZone' => 100],
        ];
        $mqtt = $this->exchange(
            [
                $this->frame(0x01, 1),
                $this->frame(0x03, 2, [
                    0xC201 => ['value' => "\x02"],
                    0xC202 => ['value' => '2026-10-19T08:00:00'],
                    0xC203 => ['value' => '2026-10-19T08:04:10'],
                    0xC206 => ['value' => "\x00"],
                ]),
                $this->frame(0x04, 3, [0x8133 => ['value' => "\x07"]]),
            ],
            3,
            effectiveConfiguration: static fn (string $imei): array => $imei === self::IMEI ? $configurations : [],
        );

        $intake = $this->ofType($mqtt->events, 'medication_intake');
        self::assertSame('2026-10-19T07:00:00Z', $intake[0]['data']['scheduledAt']);
        self::assertSame('2026-10-19T07:04:10Z', $intake[0]['data']['takenAt']);
        self::assertSame('2026-10-19', $intake[0]['data']['doseDate']);

        $change = $this->ofType($mqtt->events, 'medication_alarm_change');
        self::assertSame(3, $change[0]['data']['slot']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T07:00:00Z$/', $change[0]['data']['scheduledAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $change[0]['data']['doseDate']);
    }

    /** Uma dose dada por falhada é um alerta só, mesmo que o alarme passe por vários estados de falta. */
    public function testAMissedDoseRaisesOneDoseMissed(): void
    {
        $configurations = [
            'medication_reminders' => ['plans' => [['slot' => 5, 'hour' => 8, 'minute' => 0]]],
            'time_zone' => ['timeZone' => 100],
        ];
        $mqtt = $this->exchange(
            [
                $this->frame(0x01, 1),
                $this->frame(0x04, 2, [0x8135 => ['value' => "\x04"]]),
                $this->frame(0x04, 3, [0x8135 => ['value' => "\x06"]]),
            ],
            3,
            effectiveConfiguration: static fn (string $imei): array => $imei === self::IMEI ? $configurations : [],
        );

        $missed = $this->ofType($mqtt->events, 'dose_missed');
        $change = $this->ofType($mqtt->events, 'medication_alarm_change');
        self::assertCount(1, $missed);
        self::assertSame(
            ['slot' => 5, 'scheduledAt' => $change[0]['data']['scheduledAt'], 'doseDate' => $change[0]['data']['doseDate']],
            $missed[0]['data'],
        );
    }

    /** A toma registada como falhada também dá a dose por falhada, com o dia local do aparelho. */
    public function testAMissedIntakeRaisesDoseMissed(): void
    {
        $configurations = [
            'medication_reminders' => ['plans' => [['slot' => 2, 'hour' => 8, 'minute' => 0]]],
            'time_zone' => ['timeZone' => 100],
        ];
        $mqtt = $this->exchange(
            [
                $this->frame(0x01, 1),
                $this->frame(0x03, 2, [
                    0xC201 => ['value' => "\x01"],
                    0xC202 => ['value' => '2026-10-19T08:00:00'],
                    0xC206 => ['value' => "\x03"],
                ]),
            ],
            2,
            effectiveConfiguration: static fn (string $imei): array => $imei === self::IMEI ? $configurations : [],
        );

        self::assertSame(
            [['slot' => 2, 'scheduledAt' => '2026-10-19T07:00:00Z', 'doseDate' => '2026-10-19']],
            array_column($this->ofType($mqtt->events, 'dose_missed'), 'data'),
        );
    }

    /** Sem fuso confirmado a dose não tem dia nem hora, mas a falta sai na mesma, uma vez. */
    public function testWithoutAConfirmedZoneTheDoseMissedCarriesOnlyTheSlot(): void
    {
        $mqtt = $this->exchange([
            $this->frame(0x01, 1),
            $this->frame(0x04, 2, [0x8135 => ['value' => "\x05"]]),
            $this->frame(0x04, 3, [0x8135 => ['value' => "\x06"]]),
        ], 3);

        self::assertSame([['slot' => 5]], array_column($this->ofType($mqtt->events, 'dose_missed'), 'data'));
    }

    /** Sem a guarda do TFLV, ruído passaria por TAGs inventadas e viraria telemetria fabricada. */
    public function testABodyThatDoesNotDecryptPublishesNothing(): void
    {
        $noise = strrev(substr((string)hex2bin(self::HEARTBEAT), 20, -2));
        $mqtt = $this->exchange([$this->frame(0x01, 1), $this->frame(0x02, 2, flag: 0x04, appDataRaw: $noise)], 2);

        self::assertSame([], $this->ofType($mqtt->telemetry, 'battery'));
    }

    /**
     * @param array<int, array{value: string}> $tlv
     */
    private function frame(int $packetType, int $serial, array $tlv = [], int $flag = 0, ?string $appDataRaw = null): string
    {
        $payload = ['packetType' => $packetType, 'serial' => $serial, 'imei' => self::IMEI, 'tlv' => $tlv];
        if ($appDataRaw !== null) {
            $payload += ['flag' => $flag, 'appDataRaw' => $appDataRaw];
        }

        return (new PillDispenserAdapter())->encodeOutgoing($payload);
    }

    /**
     * @param list<array<string, mixed>> $published
     * @return list<array<string, mixed>>
     */
    private function ofType(array $published, string $type): array
    {
        return array_values(array_map(
            static fn (array $entry): array => $entry['payload'],
            array_filter($published, static fn (array $entry): bool => ($entry['type'] ?? null) === $type)
        ));
    }

    /**
     * Escreve as tramas no socket e devolve o que o hub publicou; os bytes de volta ficam em `$received`.
     *
     * @param list<string> $frames
     */
    private function exchange(
        array $frames,
        int $expectedAcks,
        string &$received = '',
        ?\Closure $effectiveConfiguration = null,
    ): RecordingHubMqttBridge {
        $loop = new StreamSelectLoop();
        $port = LocalTcpPort::free();
        if ($port === null) {
            self::markTestSkipped('Local TCP sockets are not available in this environment');
        }

        $mqtt = new RecordingHubMqttBridge();
        new HubTcpIngress(new DeviceHubServer($this->whitelist, $mqtt, effectiveConfiguration: $effectiveConfiguration), $loop, '127.0.0.1', $port);

        $error = null;
        $connector = new Connector($loop);
        $loop->addTimer(0.01, static function () use ($connector, $port, $frames, $expectedAcks, &$received, &$error, $loop): void {
            $connector->connect("tcp://127.0.0.1:$port")->then(
                static function (ConnectionInterface $connection) use ($frames, $expectedAcks, &$received, $loop): void {
                    $connection->on('data', static function (string $data) use (&$received, $connection, $loop, $expectedAcks): void {
                        $received .= $data;
                        if (substr_count($received, "\xAA") >= $expectedAcks) {
                            $connection->end();
                            $loop->stop();
                        }
                    });
                    $connection->write(implode('', $frames));
                },
                static function (\Throwable $thrown) use (&$error, $loop): void {
                    $error = $thrown;
                    $loop->stop();
                }
            );
        });
        $loop->addTimer(2.0, static function () use (&$error, $loop): void {
            $error = $error ?? new \RuntimeException('Timed out waiting for the dispenser ACKs');
            $loop->stop();
        });

        $loop->run();
        self::assertNull($error, $error?->getMessage() ?? '');

        return $mqtt;
    }
}
