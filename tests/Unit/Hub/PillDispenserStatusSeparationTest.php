<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Cada coisa na sua capacidade, e nas capacidades que o hub já tem.
 *
 * O `device_status` tinha virado uma gaveta: o sinal, a tampa, a corrente, o juízo sobre o
 * ambiente, o cartão SIM e o bloqueio de criança, tudo com a mesma etiqueta na lista de
 * eventos. Esvaziou-se, e o que resta dele é só o botão que pede o estado ao aparelho.
 *
 * Quatro regras decidiram para onde foi cada uma. O que o hub já sabe publicar publica-se
 * como ele já publica — o sinal é a `connectivity` dos gateways, e não um formato só deste
 * aparelho. O que é a mesma pergunta vista de dois lados fica junto — a corrente com a
 * bateria. O que é um alerta só fala quando dispara — o ambiente de armazenamento, como a
 * avaria já fazia. E o que não serve a ninguém não se publica — o CCID do cartão SIM.
 */
final class PillDispenserStatusSeparationTest extends TestCase
{
    /**
     * O sinal é a `connectivity` que o hub já tem, e não uma forma só deste aparelho.
     *
     * Os gateways já publicam a ligação à rede assim — que interface, que tecnologia, e a
     * potência em dBm —, e a dashboard já a desenha. Publicar `gsmSignalDbm` dentro de um
     * `device_status` obrigava quem integra a conhecer mais um formato para ler a mesma
     * grandeza, e não havia razão nenhuma para isso.
     */
    public function testTheSignalIsPublishedAsConnectivity(): void
    {
        $byFeature = $this->telemetry([
            0x810B => pack('s', 25),
            0x810D => "\x03",
            0x8107 => "\x01",
            0x8109 => "\x01",
        ]);

        self::assertSame(
            ['interface' => 'cellular', 'signalStrengthDbm' => -25],
            $byFeature['connectivity'] ?? null,
        );
        self::assertArrayNotHasKey('device_status', $byFeature);
    }

    /**
     * Sem rádio móvel a ler, vale o WiFi.
     *
     * Esta unidade é 4G e não tem rádio WiFi nenhum — soube-se pela descoberta de parâmetros
     * —, mas a série tem modelos que o têm, e a interface tem de dizer por onde o aparelho
     * está mesmo a falar.
     */
    public function testAWifiOnlyUnitSaysSo(): void
    {
        self::assertSame(
            ['interface' => 'wifi', 'signalStrengthDbm' => -60],
            $this->telemetry([0x810A => pack('s', 60)])['connectivity'] ?? null,
        );
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
     * O nível de medicação junta-se às células restantes, que é o mesmo facto com número.
     *
     * O `0x8101` é o juízo grosseiro do aparelho — normal, a acabar, sem medicação — e o
     * `0x811D` é a contagem que lhe dá origem. Dois cartões diziam a mesma coisa, um deles sem
     * número nenhum. O juízo fica como campo da contagem, que é onde acrescenta: é ele que diz
     * que 4 de 28 já é pouco, e essa gama é do aparelho e não nossa.
     */
    public function testTheMedicationLevelTravelsWithTheCellCount(): void
    {
        $byFeature = $this->telemetry([0x8101 => "\x02", 0x811D => "\x00", 0x811B => "\x1D"]);

        self::assertArrayNotHasKey('medication_level', $byFeature);
        self::assertSame(
            ['remaining' => 0, 'total' => 28, 'level' => 'empty'],
            $byFeature['cells_remaining'] ?? null,
        );
    }

    /** Sem contagem, o juízo do aparelho continua a valer por si. */
    public function testTheLevelAloneIsStillPublished(): void
    {
        self::assertSame(
            ['level' => 'low'],
            $this->telemetry([0x8101 => "\x01"])['cells_remaining'] ?? null,
        );
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
     * O ambiente de armazenamento é um alerta, e um alerta só se publica quando dispara.
     *
     * Publicado a cada leitura, enchia a lista de eventos com linhas iguais a dizer «Dentro
     * da gama» — um estado que é o normal e que ninguém lê. É o mesmo tratamento que a avaria
     * já tinha ao lado, no mesmo descodificador: só sai quando há alguma coisa a dizer.
     */
    public function testTheStorageEnvironmentOnlySpeaksWhenItIsOutOfRange(): void
    {
        self::assertSame(
            ['outOfRange' => true],
            $this->telemetry([0x8111 => "\x01"])['storage_environment'] ?? null,
        );
        self::assertArrayNotHasKey('storage_environment', $this->telemetry([0x8111 => "\x00"]));
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
        self::assertArrayNotHasKey('simCcid', $byFeature['connectivity'] ?? []);
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

        self::assertArrayNotHasKey('childLockEngaged', $byFeature['connectivity'] ?? []);
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
                0x810B => ['value' => pack('s', 25), 'state' => 0],
            ],
        ]));
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        self::assertArrayNotHasKey('lid_state', $byFeature);
        self::assertSame(-25, $byFeature['connectivity']['signalStrengthDbm'] ?? null);
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
