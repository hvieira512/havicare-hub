<?php

namespace Hub\Device\Watch\Supplier\Vivistar;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\DeviceAdapterInterface;
use Hub\Device\Watch\AbstractWatchProtocol;
use Hub\Device\Watch\WatchResponse;

final class VivistarWatchProtocol extends AbstractWatchProtocol
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

        if ($type === 'login') {
            return [new WatchResponse($this->encodeOutgoing(['type' => 'login_ok']))];
        }

        $ack = match ($type) {
            'AP01' => 'IWBP01#',
            'AP02' => 'IWBP02#',
            'AP03' => 'IWBP03#',
            'AP10' => 'IWBP10#',
            'AP49' => 'IWBP49#',
            'APHT' => 'IWBPHT#',
            'APHP' => 'IWBPHP#',
            'AP50' => 'IWBP50#',
            'APHD' => 'IWBPHD#',
            default => null,
        };

        return $ack !== null ? [new WatchResponse($ack)] : [];
    }

    public function commandMetadata(string $bytes): ?array
    {
        $metadata = parent::commandMetadata($bytes);
        if ($metadata !== null) {
            return $metadata;
        }

        $message = trim($bytes);
        if (preg_match('/^IW(BP[A-Z0-9]{2}),([^,#]+),([^,#]+)/', $message, $matches) !== 1) {
            return null;
        }

        return [
            'nativeType' => $matches[1],
            'protocol' => $this->protocol(),
            'ident' => $matches[3],
        ];
    }
}
