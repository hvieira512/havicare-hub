<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Device\CommercialModelResolver;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * O `commercialName` vem do catálogo de modelos, e os campos que não se sabem omitem-se em vez de
 * saírem vazios.
 */
final class BridgeCommercialNameTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    public function testTelemetryCarriesTheCommercialNameFromTheCatalogue(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::battery());

        self::assertSame('Havicare MF91', $mqtt->telemetry[0]['payload']['device']['commercialName'] ?? null);
    }

    /** O registo de sono tem o seu próprio caminho e sai com o mesmo descritor. */
    public function testTheSleepRecordCarriesItToo(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::sleep());

        self::assertNotSame([], $mqtt->telemetry);
        foreach ($mqtt->telemetry as $entry) {
            self::assertSame('Havicare MF91', $entry['payload']['device']['commercialName'] ?? null);
        }
    }

    /** Um modelo que o catálogo não conhece não leva a chave, em vez de a levar vazia. */
    public function testAnUnknownModelOmitsTheCommercialName(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt, new CommercialModelResolver())
            ->handleReceivedMessage(self::TOPIC, self::battery());

        self::assertArrayNotHasKey('commercialName', $mqtt->telemetry[0]['payload']['device']);
    }

    private static function battery(): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'battery',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 64],
        ], JSON_THROW_ON_ERROR);
    }

    private static function sleep(): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'sleep',
            'device' => ['mac' => self::BRACELET],
            'payload' => [
                'fallAsleepTime' => '09-14-23-10',
                'exitSleepTime' => '09-15-07-30',
                'nightScore' => 88,
                'sleepTotalTime' => 480,
                'deepSleepTime' => 120,
                'lightSleepTime' => 330,
                'otherSleepTime' => 30,
                'sleepCurve' => '00112234',
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(
        RecordingHubMqttBridge $mqtt,
        ?CommercialModelResolver $resolver = null,
    ): VeepooBridge {
        return new VeepooBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                self::BRACELET => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
            ]),
            $mqtt,
            IngressFixtures::links(true),
            null,
            new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
            commercialModelResolver: $resolver ?? self::resolver(),
        );
    }

    private static function resolver(): CommercialModelResolver
    {
        return new class extends CommercialModelResolver {
            public function __construct()
            {
            }

            public function resolveCommercialName(string $supplier, string $model): string
            {
                return $supplier === 'Wonlex' && $model === 'MF91' ? 'Havicare MF91' : '';
            }
        };
    }
}
