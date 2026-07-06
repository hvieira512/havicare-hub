<?php

namespace Hub\Watch\Supplier\FourPTouch;

use Hub\DeviceEventDecoder;
use Hub\DeviceSession;
use Hub\Protocol\Adapter\DeviceAdapterInterface;
use Hub\Watch\AbstractWatchProtocol;
use Hub\Watch\WatchResponse;

final class FourPTouchWatchProtocol extends AbstractWatchProtocol
{
    public function __construct(
        DeviceAdapterInterface $adapter,
        DeviceEventDecoder $eventDecoder,
    ) {
        parent::__construct($adapter, $eventDecoder);
    }

    /**
     * @return array<int, WatchResponse>
     */
    protected function responsesForDecoded(DeviceSession $session, array $decoded): array
    {
        $type = (string)($decoded['type'] ?? '');
        $ackFields = $this->ackFields($type);
        if ($ackFields === null) {
            return [];
        }

        return [new WatchResponse($this->encodeOutgoing([
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

        if (in_array($type, ['AL', 'AL_WCDMA', 'AL_LTE'], true)) {
            return [];
        }

        return match ($type) {
            'CONFIG', 'oxygen', 'WIFIINFOUP', 'TK', 'VERNO', 'TS' => ['1'],
            default => null,
        };
    }
}
