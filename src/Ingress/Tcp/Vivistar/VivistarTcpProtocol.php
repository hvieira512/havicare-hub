<?php

declare(strict_types=1);

namespace Hub\Ingress\Tcp\Vivistar;

use Hub\Ingress\Tcp\DeviceAdapterInterface;
use Hub\Ingress\Tcp\DeviceEventDecoder;
use Hub\Ingress\Tcp\DeviceSession;
use Hub\Ingress\Tcp\TcpProtocol;
use Hub\Ingress\Tcp\TcpResponse;

final class VivistarTcpProtocol extends TcpProtocol
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
    protected function responsesForDecoded(DeviceSession $session, array $decoded): array
    {
        $type = (string)($decoded['type'] ?? '');

        if ($type === 'login') {
            return [new TcpResponse($this->adapter->encodeOutgoing(['type' => 'login_ok']))];
        }

        // A confirmação é o próprio tipo com `IWB` em vez de `A`: `AP01` → `IWBP01#`.
        return in_array($type, ['AP01', 'AP02', 'AP03', 'AP10', 'AP49', 'APHT', 'APHP', 'AP50', 'APHD'], true)
            ? [new TcpResponse('IWB' . substr($type, 1) . '#')]
            : [];
    }

    public function downlinkCorrelation(string $bytes): array
    {
        return preg_match('/^IWBP[A-Z0-9]{2},[^,]*,([^,#]+)/', $bytes, $matches) === 1 ? ['ident' => $matches[1]] : [];
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
