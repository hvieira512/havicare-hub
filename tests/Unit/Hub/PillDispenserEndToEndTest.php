<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceHubServer;
use Hub\Device\HubTcpIngress;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\LocalTcpPort;

/**
 * Do socket ao canal do MQTT, com as tramas na forma em que o aparelho as manda.
 *
 * O que estava preso era cada degrau em separado: a decifra, a descodificação, a bandeira
 * `isEvent`. Nenhum teste os punha em fila, e por isso três coisas podiam falhar em silêncio
 * -- o hub publicaria nada, ou publicaria pelo canal errado, com identidade correcta e CRC
 * válido.
 *
 * As três que aqui se prendem: uma trama **cifrada**, que é a única forma em que o aparelho
 * envia por iniciativa própria; uma leitura a sair por `telemetry`; e a mudança de estado de
 * uma dose a sair por `events`, que é o único sinal que existe de uma dose falhada.
 */
final class PillDispenserEndToEndTest extends TestCase
{
    private const DEVICE_NUMBER = 0x0000AABBCCDDEEFF;

    private Whitelist $whitelist;

    protected function setUp(): void
    {
        $this->whitelist = IngressFixtures::whitelist([
            'AABBCCDDEEFF' => IngressFixtures::device('Zayata', 'M228', 'pill_dispenser'),
        ]);
    }

    /**
     * O evento de toma chega cifrado e sai por `events`.
     *
     * A chave e o IV são o Device Number em hexadecimal maiúsculo de dezasseis caracteres.
     */
    public function testAnEncryptedIntakeReachesTheEventChannel(): void
    {
        $mqtt = $this->exchange([
            $this->frame(0x01, 1),
            $this->encrypt($this->frame(0x03, 2, [
                0xC201 => "\x02",
                0xC203 => '2026-09-18T20:05:04',
                0xC204 => "\x0C",
                0xC206 => "\x03",
            ])),
        ], 2);

        $intake = $this->ofType($mqtt->events, 'medication_intake');
        self::assertCount(1, $intake, 'a toma cifrada não chegou ao canal de eventos');
        self::assertSame('missed', $intake[0]['data']['result']);
        self::assertSame(3, $intake[0]['data']['alarmSlot']);
        self::assertSame(12, $intake[0]['data']['cellNumber']);
    }

    /** Uma leitura sai por `telemetry`, e não pelo canal com garantia de entrega. */
    public function testAReadingReachesTheTelemetryChannel(): void
    {
        $mqtt = $this->exchange([
            $this->frame(0x01, 1),
            $this->encrypt($this->frame(0x02, 2, [0x8103 => "\x50", 0x810E => "\x19"])),
        ], 2);

        self::assertCount(1, $this->ofType($mqtt->telemetry, 'battery'));
        self::assertSame(80, $this->ofType($mqtt->telemetry, 'battery')[0]['data']['percent']);
        self::assertCount(1, $this->ofType($mqtt->telemetry, 'temperature'));

        self::assertSame([], $this->ofType($mqtt->events, 'battery'));
        self::assertSame([], $this->ofType($mqtt->events, 'temperature'));
    }

    /**
     * Uma dose falhada sai por `events`.
     *
     * Não há `medication_intake` quando ninguém toma a medicação, e por isso esta mudança de
     * estado é o único sinal dela. Sair por `telemetry` era sair a QoS 0.
     */
    public function testAMissedDoseReachesTheEventChannel(): void
    {
        $mqtt = $this->exchange([
            $this->frame(0x01, 1),
            $this->encrypt($this->frame(0x04, 2, [0x8135 => "\x06"])),
        ], 2);

        $change = $this->ofType($mqtt->events, 'medication_alarm_change');
        self::assertCount(1, $change);
        self::assertSame(5, $change[0]['data']['alarm']);
        self::assertSame('missed', $change[0]['data']['state']);
        self::assertSame([], $this->ofType($mqtt->telemetry, 'medication_alarm_change'));
    }

    /**
     * Um corpo que não abre não publica nada, e não publica lixo.
     *
     * Sem a guarda do TFLV, ruído passava por TAGs inventadas e o hub publicava telemetria
     * fabricada -- com identidade correcta e CRC válido, que é a falha calada que este
     * protocolo torna fácil.
     */
    public function testABodyThatDoesNotDecryptPublishesNothing(): void
    {
        $frame = $this->frame(0x02, 2, [0x8103 => "\x50"]);
        $body = substr($frame, 20, -2);
        $broken = substr($frame, 0, 9)
            . chr(ord($frame[9]) | 0x04)
            . substr($frame, 10, 10)
            . strrev($body)
            . substr($frame, -2);

        $mqtt = $this->exchange([$this->frame(0x01, 1), $this->withCrc($broken)], 2);

        self::assertSame([], $this->ofType($mqtt->telemetry, 'battery'));
    }

    /**
     * @param array<int, string> $tlv
     */
    private function frame(int $packetType, int $serial, array $tlv = []): string
    {
        $entries = [];
        foreach ($tlv as $tag => $value) {
            $entries[$tag] = ['value' => $value];
        }

        return (new PillDispenserAdapter())->encodeOutgoing([
            'packetType' => $packetType,
            'serial' => $serial,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => $entries,
        ]);
    }

    /** Liga a bandeira da cifra e cifra o corpo, como o aparelho faz. */
    private function encrypt(string $frame): string
    {
        $key = sprintf('%016X', self::DEVICE_NUMBER);
        $cipher = openssl_encrypt(
            substr($frame, 20, -2),
            'aes-128-cfb',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $key,
        );
        self::assertIsString($cipher);

        return $this->withCrc(
            substr($frame, 0, 9) . chr(ord($frame[9]) | 0x04) . substr($frame, 10, 10) . $cipher . '  '
        );
    }

    /** Reescreve o comprimento e o CRC depois de o corpo mudar de tamanho. */
    private function withCrc(string $frame): string
    {
        $body = substr($frame, 3, -2);
        $withLength = "\xAA" . pack('v', strlen($body)) . $body;

        return $withLength . pack('v', PillDispenserAdapter::crc16Modbus(substr($withLength, 1)));
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
     * Escreve as tramas no socket e devolve o que o hub publicou.
     *
     * @param list<string> $frames
     */
    private function exchange(array $frames, int $expectedAcks): RecordingHubMqttBridge
    {
        $loop = new StreamSelectLoop();
        $port = LocalTcpPort::free();
        if ($port === null) {
            self::markTestSkipped('Local TCP sockets are not available in this environment');
        }

        $mqtt = new RecordingHubMqttBridge();
        new HubTcpIngress(new DeviceHubServer($this->whitelist, $mqtt), $loop, '127.0.0.1', $port);

        $error = null;
        $connector = new Connector($loop);
        $loop->addTimer(0.01, static function () use ($connector, $port, $frames, $expectedAcks, &$error, $loop): void {
            $connector->connect("tcp://127.0.0.1:$port")->then(
                static function (ConnectionInterface $connection) use ($frames, $expectedAcks, $loop): void {
                    $received = '';
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
