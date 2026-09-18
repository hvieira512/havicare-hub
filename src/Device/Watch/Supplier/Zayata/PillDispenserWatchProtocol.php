<?php

namespace Hub\Device\Watch\Supplier\Zayata;

use Hub\Device\DeviceSession;
use Hub\Device\Watch\AbstractWatchProtocol;
use Hub\Device\Watch\WatchResponse;

/**
 * O dispensador M228 espera que o servidor confirme cada pacote de subida: registo `0x01`,
 * heartbeat `0x02`, evento `0x03` e notificação `0x04` respondem-se com o mesmo tipo mais o
 * bit alto (`0x81`–`0x84`), ecoando a identidade e o número de série do pacote recebido. O
 * bit 0 do Flag dispensa a resposta.
 */
final class PillDispenserWatchProtocol extends AbstractWatchProtocol
{
    private const ACKNOWLEDGED_TYPES = ['register', 'heartbeat', 'event', 'change'];

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
