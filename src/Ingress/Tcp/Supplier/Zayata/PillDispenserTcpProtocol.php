<?php

declare(strict_types=1);

namespace Hub\Ingress\Tcp\Supplier\Zayata;

use Hub\Device\Decoder\PillDispenserEventDecoder;
use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Device\Firmware\FirmwareUpgrade;
use Hub\Device\Firmware\FirmwareUpgradeStore;
use Hub\Ingress\Tcp\AbstractTcpProtocol;
use Hub\Ingress\Tcp\TcpMessage;
use Hub\Ingress\Tcp\TcpResponse;
use Hub\Protocol\Adapter\DeviceAdapterInterface;

/**
 * O M228 espera confirmação de cada pacote `0x01`–`0x04`: o mesmo tipo com o bit alto, a ecoar
 * a identidade e o número de série. O bit 1 do Flag dispensa a resposta.
 */
final class PillDispenserTcpProtocol extends AbstractTcpProtocol
{
    private const ACKNOWLEDGED_TYPES = ['register', 'heartbeat', 'event', 'change'];

    // ponytail: em memória, por processo; um reinício volta a anunciar o que estiver aceso.
    /** @var array<string, true> */
    private array $activeConditions = [];

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
     * O resultado vem por TAG, nos bits 5--7 do Flag de cada TFLV (`000` é sucesso). Só numa
     * escrita uma TAG recusada é recusa; um corpo vazio é `null`.
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
     * O heartbeat repete as condições que duram: a avaria, a chamada e o ambiente saem quando
     * acendem, e voltam a poder sair depois de o aparelho as dar por apagadas.
     */
    public function handleIncoming(DeviceSession $session, string $raw): ?TcpMessage
    {
        $message = parent::handleIncoming($session, $raw);
        if ($message === null) {
            return null;
        }

        $tlv = is_array($message->decoded['tlv'] ?? null) ? $message->decoded['tlv'] : [];
        $raised = [];
        foreach (PillDispenserEventDecoder::conditions($tlv) as $condition => $active) {
            $key = $session->imei . '|' . $condition;
            if ($active && !isset($this->activeConditions[$key])) {
                $raised[$condition] = true;
            }
            if ($active) {
                $this->activeConditions[$key] = true;
            } else {
                unset($this->activeConditions[$key]);
            }
        }

        $telemetry = array_values(array_filter(
            $message->telemetry,
            static function (array $event) use ($raised): bool {
                $condition = array_search(
                    ['feature' => $event['type'], 'value' => $event['data']],
                    PillDispenserEventDecoder::CONDITIONS,
                    true,
                );

                return $condition === false || isset($raised[$condition]);
            },
        ));

        return new TcpMessage(decoded: $message->decoded, telemetry: $telemetry, responses: $message->responses);
    }

    /**
     * @return array<int, TcpResponse>
     */
    protected function responsesForDecoded(DeviceSession $session, array $decoded): array
    {
        // A confirmação primeiro, e o pacote de upgrade com ela: sem o `0x82` o aparelho corta.
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

    /** @param array<string, mixed> $decoded */
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
     * O pacote seguinte da actualização de firmware: o arranque sai no primeiro heartbeat,
     * e cada pedaço na confirmação do anterior.
     *
     * @param array<string, mixed> $decoded
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
            // Na secção 4 os subpacotes vêm a zero e cada pacote é um pedido; o resto manda `subtotal` a 1.
            'subserial' => 0,
            'subtotal' => 0,
            'appDataRaw' => $step['body'],
        ]));
    }
}
