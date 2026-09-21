<?php

namespace Hub\Device\Tcp\Supplier\FourPTouch;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\DeviceAdapterInterface;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Device\Tcp\AbstractTcpProtocol;
use Hub\Device\Tcp\TcpResponse;

final class FourPTouchTcpProtocol extends AbstractTcpProtocol
{
    public function __construct(
        DeviceAdapterInterface $adapter,
        DeviceEventDecoder $eventDecoder,
    ) {
        parent::__construct($adapter, $eventDecoder);
    }

    /**
     * @return array<int, TcpResponse>
     */
    /**
     * O `TAKEPILLS` é a única trama do 4P Touch que confirma uma configuração, e di-lo num
     * campo próprio: `1` aceitou, `0` recusou, e o resto é o aparelho a não se pronunciar.
     */
    public function replyAccepted(array $decoded): ?bool
    {
        if (($decoded['type'] ?? null) !== 'TAKEPILLS') {
            return null;
        }

        return match ((string)($decoded['data']['configAck'] ?? '')) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    protected function responsesForDecoded(DeviceSession $session, array $decoded): array
    {
        $type = (string)($decoded['type'] ?? '');
        $ackFields = $this->ackFields($type);
        if ($ackFields === null) {
            return [];
        }

        return [new TcpResponse($this->encodeOutgoing([
            'type' => $type,
            'imei' => $decoded['ident'] ?? $session->imei,
            'deviceId' => $decoded['ident'] ?? $session->imei,
            'manufacturer' => $decoded['data']['manufacturer'] ?? '3G',
            'data' => ['fields' => $ackFields],
        ]))];
    }

    /**
     * @return array<int, string>|null
     */
    private function ackFields(string $type): ?array
    {
        if ($type === 'LK' || $type === 'bphrt' || $type === 'btemp2' || $type === 'TKQ' || $type === 'TKQ2') {
            return [];
        }

        if (in_array($type, FourPTouchAdapter::ALARM_FRAME_TYPES, true)) {
            return [];
        }

        return match ($type) {
            'CONFIG', 'oxygen', 'WIFIINFOUP', 'TK' => ['1'],
            default => null,
        };
    }
}
