<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\Decoder\FourPTouchEventDecoder;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use PHPUnit\Framework\TestCase;

/**
 * A resposta ao `TS`, apanhada de um D45 Pro em produção a 2026-09-28.
 *
 * Nem todos os modelos respondem: o Y6M e o Y6L devolvem o comando tal e qual, e a própria
 * especificação avisa que a validade depende do firmware.
 */
final class FourPTouchDeviceStatusTest extends TestCase
{
    private const REPLY = 'ver:A6C_YSC_D45Pro_En_Z_2026.04.21_18.34.39_0627_1005; '
        . "\nID:6006029822; \nimei:868160060298224; \nurl:144.76.186.92; \nport:8080; "
        . "\nupload:14400; \nlk:300; \nbatlevel:100; \nlanguage:pt; \nzone:+01:00; "
        . "\nprofile:1; \nGPS:OK(2); \nwifiOpen:true; \nwifiConnect:false; "
        . "\ngprsOpen:true; \nNET:OK(100)";

    /** @return array<string, mixed> */
    private function decoded(): array
    {
        $frame = '[3G*6006029822*011c*TS,' . self::REPLY . ']';
        $decoded = (new FourPTouchAdapter())->decodeIncoming($frame);

        return $decoded['data'] ?? [];
    }

    /** O bloco é `chave:valor` separado por `;`, e não campos por vírgulas. */
    public function testTheReplyIsReadAsKeysAndValues(): void
    {
        $data = $this->decoded();

        self::assertSame('A6C_YSC_D45Pro_En_Z_2026.04.21_18.34.39_0627_1005', $data['firmware']);
        self::assertSame(100, $data['batteryPercent']);
        self::assertSame(14400, $data['uploadIntervalSeconds']);
        self::assertSame(300, $data['heartbeatIntervalSeconds']);
        self::assertSame('pt', $data['language']);
        self::assertSame('+01:00', $data['timeZone']);
        self::assertSame(1, $data['soundProfile']);
        self::assertTrue($data['cellularEnabled']);
        self::assertTrue($data['wifiEnabled']);
        self::assertFalse($data['wifiConnected']);
    }

    /**
     * O `GPS:OK(2)` e o `NET:OK(100)` ficam de fora: a especificação dá o formato e nunca
     * diz o que os números são, e o `100` é o mesmo no exemplo dela e no aparelho real. Uma
     * grandeza sem unidade conhecida não entra no contrato.
     */
    public function testTheUndocumentedNumbersDoNotBecomeAContract(): void
    {
        $data = $this->decoded();

        foreach (['netQuality', 'gpsSatellites', 'signalQuality', 'signalStrengthDbm'] as $invented) {
            self::assertArrayNotHasKey($invented, $data, $invented);
        }
    }

    /** A ligação de rede é telemetria: muda sozinha. */
    public function testTheRadiosBecomeConnectivity(): void
    {
        $events = FourPTouchEventDecoder::decode('TS', $this->decoded());
        $connectivity = $this->firstOf($events, 'connectivity');

        self::assertNotNull($connectivity);
        self::assertSame('cellular', $connectivity['value']['interface']);
        self::assertTrue($connectivity['value']['cellularEnabled']);
        self::assertFalse($connectivity['value']['wifiConnected']);
    }

    /** O que o hub lá escreveu volta como configuração reportada, e não como leitura. */
    public function testWhatTheHubWroteComesBackAsReportedConfiguration(): void
    {
        $events = FourPTouchEventDecoder::decode('TS', $this->decoded());
        $config = $this->firstOf($events, 'device_config');

        self::assertNotNull($config);
        $settings = $config['value']['settings'];

        self::assertSame(['language' => 'pt', 'timeZone' => '+01:00'], $settings['language_timezone']);
        self::assertSame(['mode' => 1], $settings['sound_profile']);
        self::assertSame(['intervalSeconds' => 14400], $settings['location_reporting_interval']);
        // O `lk` não tem capacidade e não ganha uma: entra só para se ver.
        self::assertSame(['seconds' => 300], $settings['heartbeat_interval']);
    }

    /** E a versão do firmware sai na capacidade que já existe. */
    public function testTheFirmwareVersionUsesTheCapabilityThatExists(): void
    {
        $events = FourPTouchEventDecoder::decode('TS', $this->decoded());

        self::assertNotNull($this->firstOf($events, 'firmware_version'));
    }

    /**
     * O eco do Y6L não é uma resposta: a trama de subida é igual à que desceu, e sem bloco
     * não há nada para publicar.
     */
    public function testAnEchoPublishesNothing(): void
    {
        $decoded = (new FourPTouchAdapter())->decodeIncoming('[3G*2808660424*0002*TS]');

        self::assertSame([], FourPTouchEventDecoder::decode('TS', $decoded['data'] ?? []));
    }

    /** @param list<array<string, mixed>> $events */
    private function firstOf(array $events, string $feature): ?array
    {
        foreach ($events as $event) {
            if (($event['feature'] ?? null) === $feature) {
                return $event;
            }
        }

        return null;
    }
}
