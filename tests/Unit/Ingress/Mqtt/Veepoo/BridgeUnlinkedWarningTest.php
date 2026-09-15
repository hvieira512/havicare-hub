<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Ingress\Mqtt\Gateway\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Veepoo\Bridge;
use Hub\Log\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * Um aparelho que um gateway ouve e não é dele avisa uma vez, e não a cada trama.
 *
 * Um gateway MOKO no terreno anuncia tudo o que o rodeia, e o hub recusa o que não lhe está
 * ligado -- correctamente. Mas escrevia o aviso por mensagem: no diário local, um só par
 * aparelho/gateway dava uma linha por segundo, e o registo do hub deixava de servir para
 * diagnosticar seja o que for. É o mesmo travão que os aparelhos não autorizados já têm, e
 * pela mesma razão.
 */
final class BridgeUnlinkedWarningTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    private string $logFile;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'unlinked-log-');
        if ($path === false) {
            throw new \RuntimeException('could not create the temporary log file');
        }
        $this->logFile = $path;
        putenv('LOG_FILE=' . $path);
        Logger::reset();
    }

    protected function tearDown(): void
    {
        putenv('LOG_FILE');
        Logger::reset();
        @unlink($this->logFile);
    }

    public function testTheSamePairIsWarnedAboutOncePerWindow(): void
    {
        $bridge = $this->bridge();

        for ($i = 0; $i < 20; $i++) {
            $bridge->handleReceivedMessage(self::TOPIC, self::session());
        }

        self::assertSame(1, $this->warnings());
    }

    /** Passada a janela, volta a avisar: o par continua a insistir e isso é para se saber. */
    public function testItWarnsAgainOnceTheWindowHasPassed(): void
    {
        $now = 1000;
        $bridge = $this->bridge(static function () use (&$now): float {
            return (float)$now;
        });

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $now += 30;
        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        self::assertSame(1, $this->warnings(), 'dentro da janela é a mesma queixa');

        $now += 90;
        $bridge->handleReceivedMessage(self::TOPIC, self::session());

        self::assertSame(2, $this->warnings());
    }

    private function warnings(): int
    {
        return substr_count((string)file_get_contents($this->logFile), 'Ignoring unlinked');
    }

    private static function session(): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'session',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['authenticated' => true],
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(?callable $clock = null): Bridge
    {
        return new Bridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                self::BRACELET => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
            ]),
            new RecordingHubMqttBridge(),
            IngressFixtures::links(false),
            null,
            new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
            clock: $clock,
        );
    }
}
