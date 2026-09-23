<?php

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\FourPTouch\FourPTouchGenericHandler;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

final class CapabilityCatalogTest extends TestCase
{
    public function testDefinitionsRemainStableAfterBeingSplitByDeviceType(): void
    {
        $expected = [
            // Três rótulos de contactos passaram a dizer o que fazem: a «lista branca» é a
            // lista de chamadas autorizadas, e o interruptor dela restringe as recebidas.
            'watch' => [69, '7886530a01fe8b83f158ecfccb597bcb952f63edd2b4ec05441543ac97f76295'],
            'ncs' => [1, '213f35a9295bacacfdaa5570451707a23ee59416ebc3ac1de062f1b6ca7685a4'],
            'radar' => [9, '45dfaa71313e4da275fca1da9536b826bf0fe6a442cf462d3d2534db1499fa65'],
            'gateway' => [3, '044f4b1de47b562638442dc3fc8be22b3ab76043721211a47f478ee68124a91f'],
            'diaper_sensor' => [7, '1aabeb619dd84c1e60cb25bc6d43fe88ea8b3b708365ad38f39a3c13bf5c4fd2'],
            // De 4 para 28 com a Veepoo MF91: as W6/W6B só anunciam bateria, movimento,
            // proximidade e botão, mas uma pulseira com sessão GATT entrega dezasseis
            // grandezas e aceita seis interruptores de medição autónoma. E 36 desde que a
            // pulseira passou a dizer se está ao pulso, a medir composição corporal, a contar
            // os passos de cada bloco à parte do acumulado do dia, e a versão que traz.
            // São 40 desde que se deixou de a configurar só por interruptores: a janela em
            // que mede o oxigénio, os limiares do alerta de frequência cardíaca, e as duas
            // calibrações que entram nas contas do aparelho -- tom de pele e dados do corpo.
            // Os alarmes, os lembretes e as unidades existem na pulseira e ficaram de fora:
            // não alteram nenhuma leitura. E 41 desde que o relatório de sono do firmware
            // deixou de se perder: as pontuações que ele atribui à noite saem à parte do
            // `sleep`, que é o contrato partilhado com os relógios e não pontua nada. O sono
            // passou também a poder ser pedido: é a única grandeza sem outro caminho, e a
            // pulseira responde ao pedido a qualquer momento.
            'bracelet' => [41, 'c24b1c638090a697db262e4159d9b57acc2632480af274cb8c98e1b4f2ef57bd'],
            // O dispensador M228: oito grandezas de telemetria, três eventos (toma, avaria,
            // chamada de ajuda), doze configurações e dez acções. Cada enumeração é uma
            // configuração própria -- volume e toque não são a mesma escolha -- e o
            // `device_status` é pedível desde que o `0x07` passou a perguntar o estado em vez
            // de se esperar pelo heartbeat. A sétima é o estado dos nove alarmes, que
            // é como a toma de medicação se lia antes de a cifra abrir. Três das acções
            // perguntam ao aparelho que parâmetros ele serve, uma por família. Três não estão
            // cá: a reposição de fábrica, que devolvia o aparelho ao servidor do fornecedor,
            // desligar a cifra, que o firmware recusa sempre, e mudar o servidor a que ele se
            // liga, que é a única ordem que nos pode fazer perder o aparelho. Rodar até um
            // compartimento e pausar a medicação também não: estão na especificação da série,
            // mas este firmware recusa-as e a descoberta de parâmetros não as anuncia. O
            // cartão SIM também não: o CCID é um identificador que nunca muda e ninguém o
            // consulta na dashboard, e cada leitura de estado repetia-o na lista de eventos.
            // O `device_status` deixou de ser uma gaveta e já não publica nada -- é só o botão
            // que pede o estado. O sinal saiu para a `connectivity` que os gateways já usam, a
            // corrente juntou-se à bateria, a tampa ganhou cartão próprio, e o ambiente de
            // armazenamento virou alerta, ao lado da avaria, porque só fala quando dispara.
            // E o `medication_level` saiu: era o juízo grosseiro do aparelho a dizer o mesmo
            // que a contagem de células, sem número nenhum — passou a campo dela. A mudança de
            // estado de uma dose entrou como acontecimento próprio, porque é o único sinal de
            // uma dose falhada e viajava dentro de uma leitura, pelo canal sem garantia.
            // E o `device_status` saiu: era uma capacidade que não publicava nada e existia só
            // para ser o botão do `0x07`. A trama enche sete leituras, e são essas sete que
            // passam a pedir-se — o clique fica no mosaico que a pessoa está a olhar.
            'pill_dispenser' => [33, 'ac5d8181f162a1d35532232e1d29fc9561b23bfaffa8a078fb884e65789fadb1'],
        ];

        // Um tipo de dispositivo acrescentado sem hash aqui ficava sem guarda, e foi assim
        // que a pulseira passou despercebida à primeira.
        self::assertSame(CapabilityCatalog::deviceTypes(), array_keys($expected));

        foreach ($expected as $deviceType => [$count, $hash]) {
            $definitions = CapabilityCatalog::definitionsForDeviceType($deviceType);

            self::assertCount($count, $definitions, $deviceType);
            self::assertSame(
                $hash,
                hash('sha256', json_encode($definitions, JSON_UNESCAPED_UNICODE)),
                $deviceType,
            );
        }
    }

    public function testDefinitionsHaveUniqueKeysAndRequiredMetadata(): void
    {
        self::assertSame([
            'telemetry' => 'Telemetria',
            'health' => 'Saúde',
            'contacts' => 'Contactos',
            'alarms' => 'Alarmes',
            'settings_system' => 'Sistema',
        ], CapabilityCatalog::sections());

        foreach (CapabilityCatalog::deviceTypes() as $deviceType) {
            $definitions = CapabilityCatalog::definitionsForDeviceType($deviceType);
            $keys = array_column($definitions, 'key');

            self::assertSame($keys, array_values(array_unique($keys)), $deviceType);

            foreach ($definitions as $definition) {
                self::assertSame($deviceType, $definition['deviceType']);
                self::assertArrayHasKey($definition['section'], CapabilityCatalog::sections());
                self::assertNotSame('', trim($definition['key']));
                self::assertNotSame('', trim($definition['label']));
                self::assertIsBool($definition['isTelemetry']);
                self::assertIsBool($definition['isConfigurable']);
                self::assertIsBool($definition['isRequestable']);
            }
        }
    }

    public function testWonlexControlsAreRequestableActionsAndWeatherIsNotAdvertised(): void
    {
        $definitions = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('watch') as $definition) {
            $definitions[$definition['key']] = $definition;
        }

        foreach (['reset_device', 'restart_device', 'power_off', 'find_device', 'push_message', 'make_call'] as $key) {
            self::assertFalse($definitions[$key]['isConfigurable']);
            self::assertTrue($definitions[$key]['isRequestable']);
        }
        self::assertArrayNotHasKey('weather_data', $definitions);
    }

    public function testMonitorNumberIsARequestableActionAndNotAContactSetting(): void
    {
        $definitions = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('watch') as $definition) {
            $definitions[$definition['key']] = $definition;
        }

        // O relógio liga para o número no instante em que recebe o MONITOR, tal como no CALL.
        self::assertFalse($definitions['monitor_number']['isConfigurable']);
        self::assertTrue($definitions['monitor_number']['isRequestable']);
        self::assertSame('settings_system', $definitions['monitor_number']['section']);
    }

    public function testWonlexSystemReportsAreNotAdvertisedAsTelemetry(): void
    {
        $definitions = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('watch') as $definition) {
            $definitions[$definition['key']] = $definition;
        }

        foreach (['call_log', 'sms', 'ecg_analysis'] as $key) {
            self::assertArrayNotHasKey($key, $definitions);
        }

        self::assertSame('settings_system', $definitions['device_state']['section']);
        self::assertFalse($definitions['device_state']['isTelemetry']);
        self::assertTrue($definitions['device_state']['isEvent']);
        self::assertContains('device_state', CapabilityCatalog::keysForProtocol('wonlex-json'));
        self::assertNotContains('device_state', CapabilityCatalog::telemetryKeysForProtocol('wonlex-json'));
    }

    public function testWatchTaxonomyExcludesInternalSynchronizationAndGroupsCallRulesWithContacts(): void
    {
        $definitions = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('watch') as $definition) {
            $definitions[$definition['key']] = $definition;
        }

        self::assertArrayNotHasKey('device_binding', $definitions);
        self::assertArrayNotHasKey('device_settings_sync', $definitions);
        self::assertArrayNotHasKey('call_in_restriction', $definitions);
        self::assertSame('contacts', $definitions['whitelist_enabled']['section']);
        self::assertSame('Alerta de remoção do relógio', $definitions['remove_watch_alarm']['label']);
        self::assertSame('SMS de remoção do relógio', $definitions['remove_watch_sms_alert']['label']);

        self::assertNull(CapabilityCatalog::mapConfigurationKey('deviceConfig'));
    }

    public function testFourPTouchAliasesAreResolvedByTheDedicatedHelper(): void
    {
        self::assertSame('sos_contacts', FourPTouchGenericHandler::nativeKeyToGenericKey('sosContacts'));
        self::assertSame('call_whitelist', FourPTouchGenericHandler::nativeKeyToGenericKey('whitelistGroup1'));
        self::assertSame('whitelist_enabled', FourPTouchGenericHandler::nativeKeyToGenericKey('rejectUnknownCalls'));
        self::assertSame('whitelist_enabled', FourPTouchGenericHandler::nativeKeyToGenericKey('whitelistSwitch'));
        self::assertSame('alarm_clock', FourPTouchGenericHandler::nativeKeyToGenericKey('alarmClock'));
        self::assertSame('alarmClock', FourPTouchGenericHandler::publicKeyToNativeKey('alarm_clock'));
        self::assertSame('uploadInterval', FourPTouchGenericHandler::publicKeyToNativeKey('location_reporting_interval'));
    }

    public function testFourPTouchFallbackCanRehydrateFallSensitivity(): void
    {
        $handler = new FourPTouchGenericHandler();

        self::assertSame(
            ['sensitivity' => 6, 'levels' => 8],
            $handler->fromNative('fall_sensitivity', 'fallDownSensitivity', [
                'sensitivityLevel' => 6,
                'totalLevels' => 8,
            ]),
        );
    }

    public function testFourPTouchFallbackDoesNotInventFirmwareScale(): void
    {
        $handler = new FourPTouchGenericHandler();

        self::assertSame(
            ['sensitivity' => 6],
            $handler->fromNative('fall_sensitivity', 'fallDownSensitivity', [
                'sensitivityLevel' => 6,
            ]),
        );
    }

    /**
     * O `isTelemetry` e a secção dizem a mesma coisa, e têm de continuar a dizê-la.
     *
     * Cada definição declara as duas ao lado uma da outra, e nas 93 que existem coincidem
     * sempre -- o `is_telemetry` da base de dados é, na prática, `section = 'telemetry'`. Uma
     * definição que as separasse não daria erro em sítio nenhum: o `ModelCapabilityRepository`
     * filtra por `is_telemetry`, o ecrã das capacidades lê `isTelemetry`, e a capacidade
     * aparecia numa secção a dizer que era telemetria ou o contrário.
     *
     * Vale mais prender a coincidência aqui do que remover a repetição de 93 declarações: a
     * redundância é legível, e é a divergência que faz mal.
     */
    public function testTelemetryFlagAndSectionCannotDisagree(): void
    {
        $divergentes = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            $naSecção = $definition['section'] === 'telemetry';
            if ($naSecção !== $definition['isTelemetry']) {
                $divergentes[] = sprintf(
                    '%s:%s (section=%s, isTelemetry=%s)',
                    $definition['deviceType'],
                    $definition['key'],
                    $definition['section'],
                    $definition['isTelemetry'] ? 'true' : 'false',
                );
            }
        }

        self::assertSame([], $divergentes, 'isTelemetry tem de ser verdadeiro exactamente na secção telemetry');
    }

    /** Uma capacidade de telemetria não é configurável: são os dois lados do mesmo aparelho. */
    public function testTelemetryIsNeverConfigurable(): void
    {
        $configuráveis = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            if ($definition['isTelemetry'] && $definition['isConfigurable']) {
                $configuráveis[] = $definition['deviceType'] . ':' . $definition['key'];
            }
        }

        self::assertSame([], $configuráveis);
    }
}
