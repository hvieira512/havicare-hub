<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Os dois pedidos que só leem pertencem ao ecrã principal, e não ao modal.
 *
 * O que a dashboard mostra como mosaico pedível sai daqui: o `DeviceCapabilityPresenter`
 * percorre os comandos do protocolo, guarda os de `kind: request`, e marca a capacidade de
 * cada um como pedível. O dispensador não declarava comando nenhum -- o
 * `commandsForProtocol('zayata-m228')` devolvia lista vazia --, e por isso o mosaico «Estado
 * do dispositivo» aparecia no ecrã principal sem responder ao clique. Era um botão a fingir.
 *
 * Só entram os dois que perguntam e não mexem: ler o estado e ler a configuração. Reiniciar,
 * repor o prato ou dispensar mudam o aparelho e ficam no modal, atrás de quem foi lá de
 * propósito.
 */
final class PillDispenserRequestCardsTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function requests(): array
    {
        $requests = [];
        foreach (DeviceCommandCatalog::commandsForProtocol('zayata-m228') as $entry) {
            if (($entry['kind'] ?? '') === 'request') {
                $requests[(string)$entry['feature']] = $entry;
            }
        }

        return $requests;
    }

    /**
     * Cada leitura que o `0x07` enche é pedível por si.
     *
     * Havia um `device_status` que não publicava nada e existia só para ser o botão: quem
     * quisesse a temperatura tinha de saber que a ia buscar clicando numa coisa chamada
     * «estado do dispositivo», e o cartão da temperatura ficava a olhar. A mesma trama serve
     * sete leituras, e por isso são sete os pedidos — o mosaico de cada uma responde ao
     * clique, que é onde a pessoa está a olhar quando o quer.
     */
    public function testEveryReadingTheStatusFramePullsIsRequestable(): void
    {
        self::assertSame([
            'battery',
            'cells_remaining',
            'connectivity',
            'humidity',
            'lid_state',
            'medication_alarm_status',
            'temperature',
            'sync_configuration',
        ], array_keys($this->requests()));
    }

    /** E o `device_status`, que só existia para ser botão, deixou de fazer falta. */
    public function testTheEmptyStatusCapabilityIsGone(): void
    {
        self::assertArrayNotHasKey('device_status', $this->requests());
    }

    /** Cada um tem de saber que trama manda e que resposta espera, senão não fecha o ciclo. */
    public function testEachRequestKnowsItsCommandAndItsReply(): void
    {
        $requests = $this->requests();

        foreach (['battery', 'temperature', 'humidity', 'connectivity', 'cells_remaining', 'lid_state', 'medication_alarm_status'] as $feature) {
            self::assertSame('readStatus', $requests[$feature]['command'] ?? null, $feature);
            self::assertSame(['read_status_ack'], $requests[$feature]['expectedReplyTypes'] ?? null, $feature);
        }
        self::assertSame('readConfiguration', $requests['sync_configuration']['command'] ?? null);
        self::assertSame(['read_config_ack'], $requests['sync_configuration']['expectedReplyTypes'] ?? null);
    }

    /**
     * O que muda o aparelho não é pedido.
     *
     * A distinção não é cosmética: um mosaico do ecrã principal dispara ao primeiro clique,
     * sem confirmação e sem contexto. Dispensar consome uma dose e reiniciar corta a ligação.
     */
    public function testNothingThatChangesTheDeviceIsARequest(): void
    {
        foreach (['dispense_now', 'restart_device', 'reset_tray', 'mute_alarm', 'calibrate_clock'] as $feature) {
            self::assertArrayNotHasKey($feature, $this->requests(), $feature);
        }
    }

    /** E a trama sai mesmo: o descritor nomeia um comando que o construtor conhece. */
    public function testTheDeclaredCommandsBuildAFrame(): void
    {
        foreach ($this->requests() as $feature => $entry) {
            $bytes = DeviceCommandCatalog::buildDownlink(
                'zayata-m228',
                '869243062262262',
                (string)$entry['command'],
            );

            self::assertNotSame('', $bytes, $feature);
        }
    }
}
