<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\JoyTech;

use Hub\Ingress\Mqtt\JoyTech\JoyTechBpBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * O ECG do DBP-6679B, com as tramas e os tempos de chegada de um exame real a 2026-10-16
 * (`ecg-dbp6679b.hex`: milissegundos e trama por linha). O ecrã mostrou 73 bpm.
 */
final class JoyTechEcgTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const MONITOR = '748b3483cda4';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    private float $now;

    protected function setUp(): void
    {
        $this->now = (float)strtotime('2026-10-16T16:31:00Z');
    }

    /** O traçado sai inteiro num `ecg`, com a FC e a hora do fim do exame; o pulso não sai à parte. */
    public function testAFullExamLeavesAsOneEcg(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->replay($this->bridge($mqtt), self::capture());

        $ecg = array_values(array_filter(array_column($mqtt->telemetry, 'payload'), static fn (array $t): bool => $t['type'] === 'ecg'));
        self::assertCount(1, $ecg);
        self::assertCount(7504, $ecg[0]['data']['samples']);
        self::assertSame([210, 156, 101, 52, 1, -45], array_slice($ecg[0]['data']['samples'], 0, 6));
        self::assertSame(250, $ecg[0]['data']['frequencyHz']);
        self::assertSame(73, $ecg[0]['data']['heartRateBpm']);
        self::assertSame('2026-10-16T16:32:00Z', $ecg[0]['occurredAt']);
        self::assertSame('joytech-bp-ecg', $ecg[0]['source']['protocol']);
        self::assertSame(['ecg'], array_values(array_unique(array_column(array_column($mqtt->telemetry, 'payload'), 'type'))));
    }

    /**
     * Sem o `0x05` na contagem o aparelho não manda nada, e o fim só pára com o `0x06`: a contagem
     * repete-se de segundo a segundo e o fim de 2 em 2 s, e cada um tem uma resposta.
     */
    public function testTheCountdownAndTheEndAreAnsweredOnce(): void
    {
        $capture = self::capture();
        $countdown = array_values(array_filter($capture, static fn (array $frame): bool => str_starts_with($frame[1], 'faaaaaaf000a85')))[0];
        $end = $capture[count($capture) - 1];
        $repeated = [];
        foreach ($capture as $frame) {
            if ($frame === $countdown) {
                foreach ([-3000, -2000, -1000] as $before) {
                    $repeated[] = [$frame[0] + $before, $frame[1]];
                }
            }
            $repeated[] = $frame;
        }
        foreach ([2000, 4000] as $after) {
            $repeated[] = [$end[0] + $after, $end[1]];
        }
        $mqtt = new RecordingHubMqttBridge();
        $this->replay($this->bridge($mqtt), $repeated);

        self::assertSame(
            [['startEcg', 'faaaaaaf000a050ff55f'], ['acknowledgeEcg', 'faaaaaaf000a0610f55f']],
            array_map(
                static fn (array $command): array => [$command['payload']['operation'], $command['payload']['payload']['writes'][0]['hex']],
                $mqtt->gatewayCommands,
            ),
        );
    }

    /** Um exame cancelado e começado logo a seguir: a contagem nova tem resposta. */
    public function testASecondExamSoonAfterIsStarted(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $countdown = 'faaaaaaf000a858ff55f';

        $this->replay($bridge, [[0, $countdown], [6000, $countdown]]);

        self::assertCount(2, $mqtt->gatewayCommands);
    }

    /** Um exame interrompido não é um traçado: não sai. */
    public function testAnInterruptedExamIsNotPublished(): void
    {
        $capture = self::capture();
        $gap = $capture;
        array_splice($gap, 700, 100);
        $mqtt = new RecordingHubMqttBridge();
        $this->replay($this->bridge($mqtt), $gap);

        $dropped = new RecordingHubMqttBridge();
        $bridge = $this->bridge($dropped);
        $this->replay($bridge, array_slice($capture, 0, 900));
        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => false]));
        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true]));
        $this->replay($bridge, array_slice($capture, -1));

        self::assertSame([], array_filter(array_column($mqtt->telemetry, 'payload'), static fn (array $t): bool => $t['type'] === 'ecg'));
        self::assertSame([], array_filter(array_column($dropped->telemetry, 'payload'), static fn (array $t): bool => $t['type'] === 'ecg'));
    }

    /** @param list<array{0: int, 1: string}> $frames */
    private function replay(JoyTechBpBridge $bridge, array $frames): void
    {
        $start = $this->now;
        foreach ($frames as [$ms, $hex]) {
            $this->now = max($this->now, $start + $ms / 1000);
            $bridge->handleReceivedMessage(self::TOPIC, self::message('frame', ['char' => 'fff1', 'hex' => $hex]));
        }
    }

    /** @return list<array{0: int, 1: string}> */
    private static function capture(): array
    {
        $lines = file(__DIR__ . '/ecg-dbp6679b.hex', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $first = (int)explode(' ', $lines[0])[0];

        return array_map(static function (string $line) use ($first): array {
            [$ms, $hex] = explode(' ', $line);

            return [(int)$ms - $first, $hex];
        }, $lines);
    }

    /** @param array<string, mixed> $payload */
    private static function message(string $kind, array $payload): string
    {
        return json_encode([
            'gatewayId' => self::GATEWAY,
            'source' => 'joytech-node',
            'kind' => $kind,
            'device' => ['mac' => self::MONITOR, 'model' => 'DBP-6679B'],
            'payload' => $payload,
            'tzOffsetMinutes' => 60,
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(RecordingHubMqttBridge $mqtt): JoyTechBpBridge
    {
        return new JoyTechBpBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
                self::MONITOR => IngressFixtures::device('JoyTech', 'HC-6679B', 'blood_pressure_monitor'),
            ]),
            $mqtt,
            IngressFixtures::links(),
            null,
            'havicare-hub/null/0/gw/+/raw',
            null,
            null,
            fn (): float => $this->now,
        );
    }
}
