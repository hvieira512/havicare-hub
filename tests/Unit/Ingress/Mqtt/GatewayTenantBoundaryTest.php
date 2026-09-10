<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt;

use Hub\Ingress\Mqtt\Gateway\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Moko\Bridge as MokoBridge;
use Hub\Ingress\Mqtt\Veepoo\Bridge as VeepooBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * A fronteira entre clientes nos dois ingressos que retransmitem por um gateway.
 *
 * Um gateway só fala por aparelhos do mesmo cliente e da mesma licença. A ligação estar
 * activa não chega: a tabela de ligações é editável na dashboard, e um engano ali não pode
 * bastar para a telemetria de um cliente sair debaixo de outro.
 *
 * A regra está escrita duas vezes -- em `Moko\Bridge::linkedDevice()` e em linha no
 * `Veepoo\Bridge::handleMessage()` -- e é por isso que este teste exercita os dois: a
 * cobertura que existia só apanhava a cláusula da ligação, e as da empresa e da licença
 * podiam desaparecer de qualquer um dos lados com a suite inteira a verde.
 */
final class GatewayTenantBoundaryTest extends TestCase
{
    private const MOKO_GATEWAY = 'd48c49f7909c';
    private const W6B = 'fbd87c59ba8b';
    private const VEEPOO_GATEWAY = 'bef341903987';
    private const MF91 = '9f69c4866e6c';

    public function testMokoDoesNotRelayABraceletOfAnotherCompany(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->moko($mqtt, IngressFixtures::device('MOKO', 'W6B', 'bracelet', '1001', 'havicare'));

        $bridge->handleReceivedMessage(
            'havicare-hub/null/0/gw/' . self::MOKO_GATEWAY . '/raw',
            $this->scanPayload(),
        );

        self::assertSame([], $this->forDevice($mqtt->telemetry, self::W6B));
        self::assertSame([], $this->forDevice($mqtt->events, self::W6B));
        self::assertSame([], $this->forDevice($mqtt->raw, self::W6B));
    }

    public function testMokoDoesNotRelayABraceletOfAnotherLicence(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->moko($mqtt, IngressFixtures::device('MOKO', 'W6B', 'bracelet', '2002', 'hitcare'));

        $bridge->handleReceivedMessage(
            'havicare-hub/null/0/gw/' . self::MOKO_GATEWAY . '/raw',
            $this->scanPayload(),
        );

        self::assertSame([], $this->forDevice($mqtt->telemetry, self::W6B));
        self::assertSame([], $this->forDevice($mqtt->events, self::W6B));
        self::assertSame([], $this->forDevice($mqtt->raw, self::W6B));
    }

    public function testVeepooDoesNotRelayABraceletOfAnotherCompany(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->veepoo($mqtt, IngressFixtures::device('Wonlex', 'MF91', 'bracelet', '1001', 'havicare'));

        $bridge->handleReceivedMessage(
            'havicare-hub/null/0/gw/' . self::VEEPOO_GATEWAY . '/raw',
            $this->sessionPayload(),
        );

        self::assertSame([], $mqtt->raw);
        self::assertSame([], $mqtt->statuses);
        self::assertSame([], $mqtt->events);
    }

    public function testVeepooDoesNotRelayABraceletOfAnotherLicence(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->veepoo($mqtt, IngressFixtures::device('Wonlex', 'MF91', 'bracelet', '2002', 'hitcare'));

        $bridge->handleReceivedMessage(
            'havicare-hub/null/0/gw/' . self::VEEPOO_GATEWAY . '/raw',
            $this->sessionPayload(),
        );

        self::assertSame([], $mqtt->raw);
        self::assertSame([], $mqtt->statuses);
        self::assertSame([], $mqtt->events);
    }

    /**
     * O caso de controlo: com o mesmo cliente e a mesma licença, a mensagem passa. Sem ele,
     * os quatro testes acima passariam à mesma se o payload deixasse de ser reconhecido.
     */
    public function testTheSameTenantIsStillRelayed(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->moko($mqtt, IngressFixtures::bracelet('W6B'));

        $bridge->handleReceivedMessage(
            'havicare-hub/null/0/gw/' . self::MOKO_GATEWAY . '/raw',
            $this->scanPayload(),
        );

        self::assertNotSame([], $this->forDevice($mqtt->telemetry, self::W6B));
    }

    /** @param array<string, string> $bracelet */
    private function moko(RecordingHubMqttBridge $mqtt, array $bracelet): MokoBridge
    {
        return new MokoBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::MOKO_GATEWAY => IngressFixtures::gateway('MKGW3'),
                self::W6B => $bracelet,
            ]),
            $mqtt,
            // Ligação activa de propósito: o que se está a prender é o que rejeita apesar dela.
            IngressFixtures::links(true),
            new ArrayObservationStateStore(),
        );
    }

    /** @param array<string, string> $bracelet */
    private function veepoo(RecordingHubMqttBridge $mqtt, array $bracelet): VeepooBridge
    {
        return new VeepooBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::VEEPOO_GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                self::MF91 => $bracelet,
            ]),
            $mqtt,
            IngressFixtures::links(true),
            null,
            new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
        );
    }

    private function scanPayload(): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => self::MOKO_GATEWAY],
            'data' => [[
                'type_code' => 7,
                'type' => 'bxp-button',
                'rssi' => -82,
                'connectable' => 1,
                'mac' => self::W6B,
                'frame_type' => 0,
                'passwd_verification' => 1,
                'alarm_status' => 1,
                'trigger_count' => 69,
                'device_id' => '000001',
                'adv_name' => 'MK Button',
                'batt_vol' => 98,
                'x_axis_data' => -4,
                'y_axis_data' => -20,
                'z_axis_data' => 1052,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function sessionPayload(): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'session',
            'device' => ['mac' => self::MF91],
            'payload' => ['authenticated' => true],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $published
     * @return list<array<string, mixed>>
     */
    private function forDevice(array $published, string $deviceKey): array
    {
        return array_values(array_filter(
            $published,
            static fn (array $entry): bool => $entry['imei'] === $deviceKey,
        ));
    }
}
