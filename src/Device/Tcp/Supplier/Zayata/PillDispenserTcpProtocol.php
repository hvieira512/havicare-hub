<?php

namespace Hub\Device\Tcp\Supplier\Zayata;

use Hub\Device\DeviceSession;
use Hub\Device\Tcp\AbstractTcpProtocol;
use Hub\Device\Tcp\TcpResponse;

/**
 * O dispensador M228 espera que o servidor confirme cada pacote de subida: registo `0x01`,
 * heartbeat `0x02`, evento `0x03` e notificação `0x04` respondem-se com o mesmo tipo mais o
 * bit alto (`0x81`–`0x84`), ecoando a identidade e o número de série do pacote recebido. O
 * bit 1 do Flag dispensa a resposta.
 */
final class PillDispenserTcpProtocol extends AbstractTcpProtocol
{
    private const ACKNOWLEDGED_TYPES = ['register', 'heartbeat', 'event', 'change'];

    /** As respostas a uma escrita, em que o estado de cada TAG diz se ela pegou. */
    private const WRITE_REPLIES = ['write_config_ack', 'control_ack'];

    /** As respostas a uma leitura, que trazem valores em vez de aplicarem algum. */
    private const READ_REPLIES = ['read_config_ack', 'read_status_ack'];

    /**
     * O resultado vem por TAG, nos bits 5--7 do Flag de cada TFLV: `000` é sucesso e o resto
     * é uma recusa com motivo.
     *
     * Numa **escrita**, uma só TAG recusada chega para não ter feito o que se pediu. Numa
     * **leitura** não: o aparelho responde com tudo o que tem, e uma TAG que ele não suporta
     * é informação sobre esse parâmetro, não uma falha do pedido. O M228 de produção é a
     * variante 4G e não tem WiFi -- recusa o `0x810A` em todas as consultas de estado, e
     * tratar isso como recusa punha «o aparelho recusou» numa leitura que trouxe a bateria, a
     * temperatura, a humidade e as células todas.
     *
     * Um corpo vazio é `null` e não recusa: `null` é «não disse». É o que devolvem os pacotes
     * que o aparelho envia por sua iniciativa, que não comentam nada que lhe tenha sido pedido.
     */
    public function replyAccepted(array $decoded): ?bool
    {
        $type = (string)($decoded['type'] ?? '');
        $tlv = $decoded['tlv'] ?? [];
        if (!is_array($tlv) || $tlv === []) {
            return null;
        }

        if (in_array($type, self::READ_REPLIES, true)) {
            return true;
        }

        if (!in_array($type, self::WRITE_REPLIES, true)) {
            return null;
        }

        foreach ($tlv as $entry) {
            if ((int)($entry['state'] ?? 0) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, TcpResponse>
     */
    protected function responsesForDecoded(DeviceSession $session, array $decoded): array
    {
        $type = (string)($decoded['type'] ?? '');
        if (!in_array($type, self::ACKNOWLEDGED_TYPES, true) || ($decoded['waivesReply'] ?? false) === true) {
            return [];
        }

        $ack = $this->encodeOutgoing([
            'packetType' => ((int)($decoded['packetType'] ?? 0)) | 0x80,
            'deviceNumber' => (int)($decoded['deviceNumber'] ?? 0),
            'serial' => (int)($decoded['ident'] ?? 0),
            'status' => 0,
        ]);

        return [new TcpResponse($ack)];
    }
}
