<?php

namespace Hub\Device\Tcp\Supplier\Zayata;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Device\Firmware\FirmwareUpgrade;
use Hub\Device\Firmware\FirmwareUpgradeStore;
use Hub\Device\Tcp\AbstractTcpProtocol;
use Hub\Device\Tcp\TcpResponse;
use Hub\Protocol\Adapter\DeviceAdapterInterface;

/**
 * O dispensador M228 espera que o servidor confirme cada pacote de subida: registo `0x01`,
 * heartbeat `0x02`, evento `0x03` e notificação `0x04` respondem-se com o mesmo tipo mais o
 * bit alto (`0x81`–`0x84`), ecoando a identidade e o número de série do pacote recebido. O
 * bit 1 do Flag dispensa a resposta.
 */
final class PillDispenserTcpProtocol extends AbstractTcpProtocol
{
    private const ACKNOWLEDGED_TYPES = ['register', 'heartbeat', 'event', 'change'];

    public function __construct(
        DeviceAdapterInterface $adapter,
        DeviceEventDecoder $eventDecoder,
        private readonly ?FirmwareUpgradeStore $upgrades = null,
    ) {
        parent::__construct($adapter, $eventDecoder);
    }

    /** As respostas a uma escrita, em que o estado de cada TAG diz se ela pegou. */
    private const WRITE_REPLIES = ['write_config_ack', 'control_ack'];

    /** As respostas a uma leitura, que trazem valores em vez de aplicarem algum. */
    private const READ_REPLIES = ['read_config_ack', 'read_status_ack'];

    /**
     * O resultado vem por TAG, nos bits 5--7 do Flag de cada TFLV: `000` é sucesso e o resto
     * é uma recusa com motivo.
     *
     * Numa **escrita**, uma só TAG recusada chega para não ter feito o que se pediu. Numa
     * **leitura** não: uma TAG que o aparelho não suporta é informação sobre esse parâmetro,
     * não uma falha do pedido. Um corpo vazio é `null` e não recusa -- `null` é «não disse».
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
        // A confirmação primeiro: o pacote de upgrade viaja com ela, nunca em vez dela. Sem o
        // `0x82` de um heartbeat o aparelho retransmite e acaba por cortar a ligação.
        $responses = [];
        $type = (string)($decoded['type'] ?? '');
        if (in_array($type, self::ACKNOWLEDGED_TYPES, true) && ($decoded['waivesReply'] ?? false) !== true) {
            $responses[] = new TcpResponse($this->acknowledgement($decoded));
        }

        $upgrade = $this->upgradeResponse($decoded);
        if ($upgrade !== null) {
            $responses[] = $upgrade;
        }

        return $responses;
    }

    private function acknowledgement(array $decoded): string
    {

        return $this->encodeOutgoing([
            'packetType' => ((int)($decoded['packetType'] ?? 0)) | 0x80,
            'deviceNumber' => (int)($decoded['deviceNumber'] ?? 0),
            'serial' => (int)($decoded['ident'] ?? 0),
            'status' => 0,
        ]);
    }

    /**
     * O pacote seguinte da actualização de firmware, se houver uma a correr.
     *
     * O aparelho é que liga ao hub, por isso a transferência só anda quando ele fala: o
     * arranque sai no primeiro heartbeat depois do pedido, e cada pedaço na confirmação do
     * anterior.
     */
    private function upgradeResponse(array $decoded): ?TcpResponse
    {
        if ($this->upgrades === null) {
            return null;
        }

        $imei = (string)($decoded['imei'] ?? '');
        $state = $imei === '' ? null : $this->upgrades->load($imei);
        if ($state === null || in_array((string)($state['status'] ?? ''), ['done', 'failed'], true)) {
            return null;
        }

        $firmware = @file_get_contents((string)($state['path'] ?? ''));
        if ($firmware === false) {
            $this->upgrades->save($imei, ['status' => 'failed', 'error' => 'firmware_unreadable'] + $state);

            return null;
        }

        $step = FirmwareUpgrade::advance(
            $state,
            $firmware,
            (string)($decoded['type'] ?? ''),
            (int)($decoded['status'] ?? 0),
        );

        if ($step['state'] !== $state) {
            $this->upgrades->save($imei, $step['state']);
        }

        if ($step['body'] === null || $step['packetType'] === null) {
            return null;
        }

        return new TcpResponse($this->encodeOutgoing([
            'packetType' => $step['packetType'],
            'deviceNumber' => (int)($decoded['deviceNumber'] ?? 0),
            'serial' => (int)($step['state']['serial'] ?? 0),
            'status' => 0,
            'appDataRaw' => $step['body'],
        ]));
    }
}
