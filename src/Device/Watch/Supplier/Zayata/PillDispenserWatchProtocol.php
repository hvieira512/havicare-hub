<?php

namespace Hub\Device\Watch\Supplier\Zayata;

use Hub\Device\DeviceSession;
use Hub\Device\Watch\AbstractWatchProtocol;
use Hub\Device\Watch\WatchResponse;

/**
 * O dispensador M228 espera que o servidor confirme cada pacote de subida: registo `0x01`,
 * heartbeat `0x02`, evento `0x03` e notificação `0x04` respondem-se com o mesmo tipo mais o
 * bit alto (`0x81`–`0x84`), ecoando a identidade e o número de série do pacote recebido. O
 * bit 1 do Flag dispensa a resposta.
 */
final class PillDispenserWatchProtocol extends AbstractWatchProtocol
{
    private const ACKNOWLEDGED_TYPES = ['register', 'heartbeat', 'event', 'change'];

    /** As respostas que comentam alguma coisa que o hub pediu. */
    private const REPLY_TYPES = ['write_config_ack', 'read_config_ack', 'read_status_ack', 'control_ack'];

    /**
     * O resultado de uma escrita vem por TAG, nos bits 5--7 do Flag de cada TFLV: `000` é
     * sucesso e o resto é uma recusa com motivo. Uma só TAG recusada chega para a escrita
     * inteira não ter feito o que se pediu.
     *
     * Um corpo vazio é `null` e não recusa: `null` é «não disse». É o que devolvem os pacotes
     * que o aparelho envia por sua iniciativa, que não comentam configuração nenhuma.
     */
    public function replyAccepted(array $decoded): ?bool
    {
        if (!in_array((string)($decoded['type'] ?? ''), self::REPLY_TYPES, true)) {
            return null;
        }

        $tlv = $decoded['tlv'] ?? [];
        if (!is_array($tlv) || $tlv === []) {
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
     * @return array<int, WatchResponse>
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

        return [new WatchResponse($ack)];
    }
}
