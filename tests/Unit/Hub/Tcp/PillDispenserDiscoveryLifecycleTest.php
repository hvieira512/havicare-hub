<?php

declare(strict_types=1);

namespace Tests\Unit\Hub\Tcp;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\Tcp\Supplier\Zayata\PillDispenserTcpProtocol;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Uma resposta à descoberta de parâmetros fecha o pedido que a provocou.
 *
 * O `replyAccepted` decide pelo corpo TFLV, e uma resposta à descoberta não tem nenhum: as
 * TAGs vêm numa lista simples, fora do TFLV. Caía na guarda do corpo vazio, que devolve
 * `null` — «não disse» —, e o pedido ficava eternamente «a aguardar resposta do dispositivo»,
 * a ser repetido de minuto a minuto, com o aparelho a responder de cada vez.
 *
 * Apanhou-se na dashboard, com o aparelho ligado: a lista das 54 TAGs de configuração chegou
 * e ficou lá guardada, e o cartão continuava amarelo a dizer que esperava.
 */
final class PillDispenserDiscoveryLifecycleTest extends TestCase
{
    private function protocol(): PillDispenserTcpProtocol
    {
        return new PillDispenserTcpProtocol(new PillDispenserAdapter(), new DeviceEventDecoder());
    }

    public function testADiscoveryReplyClosesTheRequest(): void
    {
        $protocol = $this->protocol();

        foreach (['discover_config_ack', 'discover_status_ack', 'discover_control_ack'] as $type) {
            self::assertTrue(
                $protocol->replyAccepted(['type' => $type, 'tlv' => [], 'supportedTags' => [0x1001, 0x1002]]),
                $type,
            );
        }
    }

    /**
     * O caso do corpo vazio vive no `PillDispenserDiscoveryDecodingTest`, onde a trama é
     * montada e descodificada pelo adaptador.
     *
     * Estava aqui, com o `supportedTags` construído à mão — uma forma que o adaptador nunca
     * produzia, porque um corpo vazio devolvia `null`. O teste passava e o caminho real ficava
     * partido: era o defeito que ele dizia cobrir.
     */

    /** O que o aparelho manda por sua iniciativa continua a não comentar pedido nenhum. */
    public function testASpontaneousPacketStillSaysNothing(): void
    {
        $protocol = $this->protocol();

        self::assertNull($protocol->replyAccepted(['type' => 'heartbeat', 'tlv' => []]));
        self::assertNull($protocol->replyAccepted(['type' => 'event', 'tlv' => []]));
    }
}
