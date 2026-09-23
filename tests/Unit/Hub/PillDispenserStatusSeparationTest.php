<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Cada coisa na sua capacidade, e o que não serve a ninguém não é capacidade nenhuma.
 *
 * O `device_status` tinha virado uma gaveta. Lá dentro iam o sinal — que muda de minuto a
 * minuto e enche o histórico —, o número do cartão SIM, e o estado do bloqueio de criança,
 * que já é uma configuração com valor reportado. Na lista de eventos saíam todos com a mesma
 * etiqueta, «Estado do dispositivo».
 *
 * A separação segue o que cada coisa é: o que muda ao minuto é telemetria, o que se configura
 * tem o seu valor reportado e não uma segunda verdade ao lado, e o CCID não é nem uma nem
 * outra — é um identificador que ninguém vai ler ali.
 */
final class PillDispenserStatusSeparationTest extends TestCase
{
    /**
     * O estado do dispositivo fica com o sinal e mais nada.
     *
     * É a mesma arrumação que os relógios já têm: `device_status` é a ligação à rede, e a
     * leitura fina em dBm vive ao lado da contagem de barras. Tudo o resto que lá estava
     * dentro tinha um sítio melhor, e chegar ao ecrã como «Tampa aberta: Não · Ligado à
     * corrente: Sim · Ambiente fora da gama: Não» era uma linha que ninguém lê.
     */
    public function testDeviceStatusIsTheSignalAndNothingElse(): void
    {
        $status = $this->telemetry([
            0x810B => pack('s', 25),
            0x810D => "\x03",
            0x8107 => "\x01",
            0x8109 => "\x01",
            0x8111 => "\x00",
        ])['device_status'] ?? [];

        self::assertSame(['gsmSignalDbm' => -25, 'signalLevel' => 3], $status);
    }

    /**
     * A corrente entra na bateria, que é onde alguém a procura.
     *
     * «Ligado à corrente» e «a carregar» são a mesma pergunta feita de dois lados, e a
     * dashboard já desenha a bateria com o estado de carga. Numa lista à parte, a corrente
     * ficava a uma linha de distância da percentagem que a explica.
     */
    public function testTheMainsSupplyTravelsWithTheBattery(): void
    {
        $battery = $this->telemetry([
            0x8103 => "\x64",
            0x8104 => "\x03",
            0x8109 => "\x01",
        ])['battery'] ?? [];

        self::assertSame(100, $battery['percent'] ?? null);
        self::assertSame('charging', $battery['chargingState'] ?? null);
        self::assertTrue($battery['mainsPowered'] ?? null);
    }

    /**
     * A tampa é uma capacidade própria: é um estado físico sobre que alguém age.
     *
     * Uma tampa aberta quer dizer que o prato está acessível — alguém está a carregá-lo, ou
     * ficou aberta por esquecimento. Enfiada entre dois números de sinal, não chamava
     * ninguém.
     */
    public function testTheLidIsItsOwnCapability(): void
    {
        self::assertSame(['open' => true], $this->telemetry([0x8107 => "\x01"])['lid_state'] ?? null);
        self::assertSame(['open' => false], $this->telemetry([0x8107 => "\x00"])['lid_state'] ?? null);
    }

    /**
     * O «ambiente fora da gama» é o juízo do aparelho sobre a temperatura e a humidade.
     *
     * O nome não dizia isso a ninguém. É uma capacidade própria, com o nome do que mede: se
     * a medicação está guardada dentro das condições que o fabricante dá como boas. As duas
     * leituras que ele compara já têm cartão, e este é a conclusão delas.
     */
    public function testTheStorageEnvironmentIsItsOwnCapability(): void
    {
        self::assertSame(
            ['outOfRange' => true],
            $this->telemetry([0x8111 => "\x01"])['storage_environment'] ?? null,
        );
        self::assertSame(
            ['outOfRange' => false],
            $this->telemetry([0x8111 => "\x00"])['storage_environment'] ?? null,
        );
    }

    /**
     * O cartão SIM não sai de todo.
     *
     * Primeiro foi separado do estado do dispositivo para capacidade própria, e o argumento
     * estava certo — uma coisa é o sinal, outra é o cartão. A conclusão é que não é nenhuma
     * das duas: o CCID é um identificador que nunca muda, ninguém o consulta na dashboard, e
     * cada leitura de estado deixava mais uma linha na lista de eventos a repetir o mesmo
     * número. O adaptador continua a descodificá-lo; o contrato não o publica.
     */
    public function testTheSimCardIsNotPublished(): void
    {
        $byFeature = $this->telemetry([0x8009 => "8935103211501958977F\x00", 0x810D => "\x03"]);

        self::assertArrayNotHasKey('sim_card', $byFeature);
        self::assertArrayNotHasKey('simCcid', $byFeature['device_status'] ?? []);
    }

    /**
     * O bloqueio de criança é uma configuração, e o que o aparelho reporta é o valor dela.
     *
     * Publicá-lo também como telemetria dava duas verdades sobre a mesma coisa, e nada as
     * obrigava a concordar.
     */
    public function testTheChildLockIsNotTelemetry(): void
    {
        $byFeature = $this->telemetry([0x8102 => "\x01", 0x810D => "\x03"]);

        self::assertArrayNotHasKey('childLockEngaged', $byFeature['device_status'] ?? []);
        self::assertSame(['enabled' => true], $byFeature['device_config']['settings']['child_lock'] ?? null);
    }

    /** Uma trama sem nada disto não publica capacidade nenhuma vazia. */
    public function testNothingIsPublishedWithoutReadings(): void
    {
        $byFeature = $this->telemetry([0x8103 => "\x64"]);

        self::assertArrayNotHasKey('sim_card', $byFeature);
        self::assertArrayNotHasKey('device_status', $byFeature);
    }

    /**
     * Uma TAG que o aparelho recusa não vira valor, que era como se publicava zero.
     *
     * O estado da leitura viaja nos bits 5--7 do Flag, e a trama leva-o tal como o aparelho o
     * manda: uma TAG recusada volta com os bytes que lhe mandámos, zeros. Sem olhar ao
     * estado, uma tampa que o firmware não sabe reportar aparecia no ecrã como fechada.
     */
    public function testARefusedTagIsNotPublished(): void
    {
        $adapter = new PillDispenserAdapter();
        $byFeature = [];
        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x87,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [
                0x8107 => ['value' => "\x00", 'state' => 1],
                0x810D => ['value' => "\x03", 'state' => 0],
            ],
        ]));
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        self::assertArrayNotHasKey('lid_state', $byFeature);
        self::assertSame(3, $byFeature['device_status']['signalLevel'] ?? null);
    }

    /**
     * @param array<int, string> $tlv
     * @return array<string, array<string, mixed>>
     */
    private function telemetry(array $tlv): array
    {
        $adapter = new PillDispenserAdapter();
        $entries = [];
        foreach ($tlv as $tag => $value) {
            $entries[$tag] = ['value' => $value];
        }

        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x87,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => $entries,
        ]));

        $byFeature = [];
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        return $byFeature;
    }

    private function session(): DeviceSession
    {
        return new DeviceSession(
            new PillFakeConnection(),
            'tcp',
            true,
            'AABBCCDDEEFF',
            'zayata-m228',
            'Zayata',
            'M228',
            'Zayata M228',
            'pill_dispenser',
        );
    }
}
